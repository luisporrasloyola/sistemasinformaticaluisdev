-- Ejecutar una sola vez en la base de datos existente antes de publicar el código.
ALTER TABLE cobranzas_valorizacion_datos_cliente
    ADD COLUMN equipo VARCHAR(150) NOT NULL DEFAULT '' AFTER lugar_servicio,
    ADD COLUMN periodo VARCHAR(120) NOT NULL DEFAULT '' AFTER equipo;
