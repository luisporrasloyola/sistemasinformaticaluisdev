-- Ejecutar una sola vez después de cobranzas_valorizaciones_multiples.sql.
ALTER TABLE cobranzas_valorizaciones
    ADD COLUMN registered_by_user_id INT UNSIGNED DEFAULT NULL AFTER periodo,
    ADD KEY idx_cv_valorizaciones_registrado_por (registered_by_user_id),
    ADD CONSTRAINT fk_cv_valorizaciones_registrado_por FOREIGN KEY (registered_by_user_id)
        REFERENCES users(id) ON DELETE SET NULL;

-- En valorizaciones anteriores se toma el usuario del primer parte diario que lo tenga.
-- Si no existe ese dato, el campo permanece vacío para no atribuirlo incorrectamente.
UPDATE cobranzas_valorizaciones v
SET registered_by_user_id = (
    SELECT r.registered_by_user_id
    FROM cobranzas_valorizacion_registros r
    WHERE r.valorizacion_id = v.id AND r.registered_by_user_id IS NOT NULL
    ORDER BY r.id
    LIMIT 1
)
WHERE v.registered_by_user_id IS NULL;
