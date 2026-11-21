-- Ejecutar una sola vez en la base de datos de Cobranzas > Valorización.
ALTER TABLE cobranzas_valorizaciones
    ADD COLUMN movilizacion_activa TINYINT(1) NOT NULL DEFAULT 0 AFTER periodo;

ALTER TABLE cobranzas_valorizacion_registros
    ADD COLUMN movilizacion DECIMAL(12,2) NULL AFTER fecha_servicio,
    ADD COLUMN desmovilizacion DECIMAL(12,2) NULL AFTER movilizacion;
