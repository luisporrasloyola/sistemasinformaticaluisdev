-- Ejecutar una sola vez en bases de datos donde ya existe la tabla de valorización.
ALTER TABLE cobranzas_valorizacion_datos_cliente
    ADD COLUMN capacidad VARCHAR(100) NOT NULL DEFAULT '' AFTER equipo;
