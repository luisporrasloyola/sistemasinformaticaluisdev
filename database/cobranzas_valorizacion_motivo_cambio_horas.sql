-- Ejecutar una vez en la base de datos que usa Cobranzas > Valorización.
ALTER TABLE cobranzas_valorizacion_registros
    ADD COLUMN motivo_cambio_horas VARCHAR(1000) NULL AFTER horas_total_minutos;
