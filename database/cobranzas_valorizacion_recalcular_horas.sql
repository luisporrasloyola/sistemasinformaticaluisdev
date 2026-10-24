-- Recalcula registros anteriores con el descuento fijo de 60 minutos.
-- Es idempotente: puede ejecutarse nuevamente sin aplicar otro descuento.
-- Los turnos de una hora o menos quedan sin cambios y se señalan para revisión en la interfaz.
UPDATE cobranzas_valorizacion_registros
SET horas_total_minutos = MOD(TIME_TO_SEC(hora_final) - TIME_TO_SEC(hora_inicio) + 86400, 86400) DIV 60 - 60
WHERE MOD(TIME_TO_SEC(hora_final) - TIME_TO_SEC(hora_inicio) + 86400, 86400) > 3600
  AND horas_total_minutos <> MOD(TIME_TO_SEC(hora_final) - TIME_TO_SEC(hora_inicio) + 86400, 86400) DIV 60 - 60;
