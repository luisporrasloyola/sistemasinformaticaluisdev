-- Ejecutar una sola vez antes de usar importes por cada registro.
ALTER TABLE empresa_maquirenta_valorizacion_equipos
    ADD COLUMN movilizacion_desmovilizacion DECIMAL(12,2) NULL DEFAULT NULL AFTER tarifa_equipo;
