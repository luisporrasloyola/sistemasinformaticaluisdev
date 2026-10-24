-- Ejecutar una sola vez en la base de datos existente antes de publicar el código.
ALTER TABLE cobranzas_valorizacion_registros
    ADD COLUMN registered_by_user_id INT UNSIGNED DEFAULT NULL AFTER observaciones,
    ADD KEY idx_cv_registrado_por (registered_by_user_id),
    ADD CONSTRAINT fk_cv_registrado_por FOREIGN KEY (registered_by_user_id)
        REFERENCES users(id) ON DELETE SET NULL;
