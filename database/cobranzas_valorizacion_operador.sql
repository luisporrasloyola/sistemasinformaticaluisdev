-- Ejecutar una sola vez en la base de datos existente antes de publicar el código.
ALTER TABLE cobranzas_valorizacion_registros
    ADD COLUMN operador VARCHAR(150) DEFAULT NULL AFTER placa_serie;
