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
