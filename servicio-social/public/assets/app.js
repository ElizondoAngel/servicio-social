// Aviso de cierre por inactividad: un minuto antes del límite pregunta si
// se quiere seguir trabajando; si no hay respuesta, va al inicio de sesión.
(function () {
  'use strict';
  var minutos = parseInt(document.body.dataset.inactividad || '0', 10);
  var csrf = document.body.dataset.csrf || '';
  var aviso = document.getElementById('aviso-inactividad');
  if (!minutos || !aviso) { return; }

  var limiteMs = minutos * 60 * 1000;
  var temporizadorAviso, temporizadorCierre;

  function programar() {
    clearTimeout(temporizadorAviso);
    clearTimeout(temporizadorCierre);
    aviso.hidden = true;
    temporizadorAviso = setTimeout(function () { aviso.hidden = false; }, Math.max(0, limiteMs - 60 * 1000));
    temporizadorCierre = setTimeout(function () { window.location.href = '/login?m=expirada'; }, limiteMs);
  }

  document.getElementById('seguir-trabajando').addEventListener('click', function () {
    fetch('/sesion/renovar', {
      method: 'POST',
      headers: { 'X-CSRF-Token': csrf, 'Accept': 'application/json' },
      credentials: 'same-origin'
    }).then(function (r) {
      if (r.ok) { programar(); } else { window.location.href = '/login?m=expirada'; }
    }).catch(function () { window.location.href = '/login?m=expirada'; });
  });

  programar();
})();

// Calendario del expediente: cada día se edita en un diálogo sin salir de
// la página. Sin JavaScript, los mismos enlaces y formularios funcionan
// como páginas normales.
(function () {
  'use strict';
  var zona = document.getElementById('zona-calendario');
  var dialogo = document.getElementById('dialogo-dia');
  if (!zona || !dialogo || typeof dialogo.showModal !== 'function' || !window.fetch) { return; }
  var contenido = document.getElementById('dialogo-contenido');
  var base = '/expedientes/' + zona.dataset.expediente;
  var ultimoDia = null;

  var aviso = document.createElement('div');
  aviso.className = 'aviso-js';
  aviso.setAttribute('role', 'status');
  aviso.setAttribute('aria-live', 'polite');
  aviso.hidden = true;
  document.body.appendChild(aviso);
  var temporizador;
  function avisar(texto, esError) {
    aviso.textContent = texto;
    aviso.className = 'aviso-js ' + (esError ? 'aviso-error' : 'aviso-exito');
    aviso.hidden = false;
    clearTimeout(temporizador);
    temporizador = setTimeout(function () { aviso.hidden = true; }, esError ? 7000 : 3500);
  }

  function sesionVencida(r) {
    if (r.status === 401) { window.location.href = '/login?m=expirada'; return true; }
    return false;
  }

  // Hojas desplegables: se recuerda cuáles están abiertas al recargar la zona.
  function hojasAbiertas() {
    return Array.prototype.map.call(zona.querySelectorAll('details.hoja[open]'), function (d) { return d.id; });
  }
  function prepararHojas(abiertas) {
    if (abiertas) {
      zona.querySelectorAll('details.hoja').forEach(function (d) { d.open = abiertas.indexOf(d.id) !== -1; });
    }
    zona.querySelectorAll('.js-hojas').forEach(function (b) { b.hidden = false; });
  }
  function abrirDesdeAncla() {
    if (!location.hash) { return; }
    var destino = document.getElementById(location.hash.slice(1));
    var hoja = destino && destino.closest('details.hoja');
    if (hoja && !hoja.open) { hoja.open = true; destino.scrollIntoView(); }
  }

  function recargarZona() {
    var abiertas = hojasAbiertas();
    return fetch(base + '?parcial=zona', { credentials: 'same-origin', headers: { 'Accept': 'text/html' } })
      .then(function (r) { if (sesionVencida(r)) { throw new Error('sesion'); } return r.text(); })
      .then(function (html) { zona.innerHTML = html; prepararHojas(abiertas); });
  }

  prepararHojas(null);
  abrirDesdeAncla();
  window.addEventListener('hashchange', abrirDesdeAncla);
  zona.addEventListener('click', function (e) {
    var boton = e.target.closest('.js-hojas');
    if (!boton) { return; }
    var abrir = boton.dataset.abrir === '1';
    zona.querySelectorAll('details.hoja').forEach(function (d) { d.open = abrir; });
  });

  function ajustarCampos() {
    var form = contenido.querySelector('form.js-form-dia');
    if (!form) { return; }
    var marcado = form.querySelector('input[name="estado"]:checked');
    var estado = marcado ? marcado.value : '';
    form.querySelectorAll('.solo-asistencia').forEach(function (el) { el.hidden = estado !== 'asistencia'; });
    form.querySelectorAll('.con-horas').forEach(function (el) { el.hidden = estado !== 'asistencia' && estado !== 'feriado'; });
  }

  function mostrar(html) {
    contenido.innerHTML = html;
    ajustarCampos();
    if (!dialogo.open) { dialogo.showModal(); }
    var foco = contenido.querySelector('[aria-invalid="true"]') || contenido.querySelector('input[name="estado"]:checked')
      || contenido.querySelector('input, select, textarea, button');
    if (foco) { foco.focus(); }
  }

  function enviar(form) {
    return fetch(form.action, {
      method: 'POST',
      body: new FormData(form),
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' }
    }).then(function (r) {
      if (sesionVencida(r)) { throw new Error('sesion'); }
      return r.json().then(function (d) { return { estado: r.status, datos: d }; });
    });
  }

  zona.addEventListener('click', function (e) {
    var enlace = e.target.closest('a.dia');
    if (!enlace || e.ctrlKey || e.metaKey || e.shiftKey || e.button !== 0) { return; }
    e.preventDefault();
    ultimoDia = enlace.getAttribute('href');
    fetch(ultimoDia + '&parcial=1', { credentials: 'same-origin', headers: { 'Accept': 'text/html' } })
      .then(function (r) { if (sesionVencida(r)) { throw new Error('sesion'); } return r.text(); })
      .then(mostrar)
      .catch(function (err) { if (err.message !== 'sesion') { window.location.href = ultimoDia; } });
  });

  contenido.addEventListener('click', function (e) {
    if (e.target.closest('.js-cerrar')) { e.preventDefault(); dialogo.close(); }
  });
  contenido.addEventListener('change', function (e) {
    if (e.target.name === 'estado') { ajustarCampos(); }
  });

  contenido.addEventListener('submit', function (e) {
    var form = e.target.closest('form.js-form-dia');
    if (!form) { return; }
    e.preventDefault();
    var boton = form.querySelector('button[type="submit"]');
    if (boton) { boton.disabled = true; }
    enviar(form).then(function (res) {
      if (res.datos.ok) {
        dialogo.close();
        avisar(res.datos.aviso || 'Guardado.');
        return recargarZona();
      }
      if (res.datos.html) { mostrar(res.datos.html); } else { avisar(res.datos.error || 'No se pudo guardar.', true); }
    }).catch(function (err) {
      if (err.message !== 'sesion') { avisar('No se pudo guardar. Revisa tu conexión e inténtalo de nuevo.', true); }
    }).finally(function () { if (boton) { boton.disabled = false; } });
  });

  // Firmas y confirmación de feriados: sin recargar la página.
  zona.addEventListener('submit', function (e) {
    var form = e.target.closest('form.js-accion');
    if (!form) { return; }
    e.preventDefault();
    enviar(form).then(function (res) {
      avisar(res.datos.ok ? res.datos.aviso : (res.datos.error || 'No se pudo guardar.'), !res.datos.ok);
      return recargarZona();
    }).catch(function (err) {
      if (err.message !== 'sesion') { avisar('No se pudo guardar. Revisa tu conexión.', true); }
    });
  });

  dialogo.addEventListener('close', function () {
    contenido.innerHTML = '';
    if (ultimoDia) {
      var dia = zona.querySelector('a.dia[href="' + ultimoDia.replace(/"/g, '') + '"]');
      if (dia) { dia.focus(); }
    }
  });
})();

// Tablero: al cambiar "Mostrar" u "Ordenar por" se aplica solo (sin JS, con el botón Aplicar).
(function () {
  'use strict';
  var form = document.querySelector('form.js-auto');
  if (!form) { return; }
  form.querySelectorAll('select').forEach(function (s) {
    s.addEventListener('change', function () { form.submit(); });
  });
})();
