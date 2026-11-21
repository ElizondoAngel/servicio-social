# Genera 002_triggers.sql a partir de la lista de columnas de cada tabla,
# para que los JSON de historial no se desincronicen del esquema.
COLS = {
 'asistencias': ['expediente_id','fecha','estado','horas_trabajadas','horas_acreditadas','dia_inhabil_id','justificacion','hora_entrada','hora_salida','observaciones','autorizado_por','motivo_autorizacion','registrado_por','actualizado_por','version'],
 'expedientes': ['alumno_id','unidad_administrativa','responsable','hora_entrada','hora_salida','fecha_inicio','fecha_fin_autorizada','horas_requeridas','meses_requeridos','jornada_prevista','feriados_acreditan','horas_por_feriado','estado','fecha_conclusion','resumen_conclusion','observaciones','creado_por','version'],
 'alumnos': ['matricula','nombre','apellido_paterno','apellido_materno','carrera','institucion','correo','telefono','archivado'],
 'firmas_semana': ['expediente_id','semana_inicio','registrado_por','firmado_en'],
 'dias_inhabiles': ['fecha','tipo','descripcion'],
 'configuracion': ['clave','valor','descripcion','actualizado_por'],
 'usuarios': ['nombre','correo','rol','activo'],
}
EXP = {'asistencias':'{r}.expediente_id','expedientes':'{r}.id','firmas_semana':'{r}.expediente_id'}
ID = {'configuracion':'NULL'}
def jo(t,r):
    parts=[]
    for c in COLS[t]:
        v=f"{r}.{c}"
        if c=='resumen_conclusion': v=f"JSON_EXTRACT({r}.resumen_conclusion, '$')"
        parts.append(f"'{c}', {v}")
    if t=='usuarios': pass
    return "JSON_OBJECT(" + ", ".join(parts) + ")"
def ins(t,accion,antes,despues,r):
    exp = EXP.get(t,'NULL').format(r=r)
    eid = ID.get(t, f"{r}.id")
    if t=='configuracion': eid='NULL'
    return (f"  INSERT INTO historial (origen, usuario_id, usuario_bd, accion, entidad, entidad_id, expediente_id, antes, despues, motivo, ip, agente)\n"
            f"  VALUES ('trigger', @usuario_app, USER(), {accion}, '{t}', {eid}, {exp}, {antes}, {despues}, NULLIF(TRIM(@motivo_app), ''), @ip_app, @agente_app);")
out=[]
for t in COLS:
    for ev,r in (('INSERT','NEW'),('UPDATE','NEW'),('DELETE','OLD')):
        if t=='firmas_semana' and ev=='UPDATE': continue
        name=f"trg_{t}_a{ev[0].lower()}"
        if ev=='INSERT': antes,despues,acc='NULL',jo(t,'NEW'),"'crear'"
        elif ev=='DELETE': antes,despues,acc=jo(t,'OLD'),'NULL',"'quitar'"
        else:
            antes,despues=jo(t,'OLD'),jo(t,'NEW'); acc="'actualizar'"
            if t=='expedientes':
                acc=("CASE WHEN NEW.estado = 'concluido' AND OLD.estado <> 'concluido' THEN 'concluir' "
                     "WHEN OLD.estado = 'concluido' AND NEW.estado <> 'concluido' THEN 'reabrir' "
                     "WHEN NEW.estado <> OLD.estado THEN 'cambiar_estado' ELSE 'actualizar' END")
        body = ins(t,acc,antes,despues,r)
        if t=='usuarios':
            extra_new = "JSON_INSERT({j}, '$.password_cambiada', {pc})"
            if ev=='UPDATE':
                pc="IF(NEW.password_hash <=> OLD.password_hash, FALSE, TRUE)"
                body = ins(t,acc,jo(t,'OLD'),extra_new.format(j=jo(t,'NEW'),pc=pc),r)
                # no registrar cambios que solo tocan ultimo_acceso
                body = ("  IF NOT (NEW.nombre <=> OLD.nombre AND NEW.correo <=> OLD.correo AND NEW.rol <=> OLD.rol\n"
                        "          AND NEW.activo <=> OLD.activo AND NEW.password_hash <=> OLD.password_hash) THEN\n"
                        + body.replace('\n','\n  ').replace('  INSERT','    INSERT',1) + "\n  END IF;")
        out.append(f"CREATE TRIGGER {name} AFTER {ev} ON {t} FOR EACH ROW\nBEGIN\n{body}\nEND$$\n")
open(__import__('sys').argv[1] if len(__import__('sys').argv)>1 else '_audit.sql','w').write("\n".join(out))
