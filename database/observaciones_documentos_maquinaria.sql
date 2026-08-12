-- Auditoría de observaciones para Maquinaria - Documentos.
-- Ejecutar una vez en phpMyAdmin de producción.

ALTER TABLE maquinaria_documentos
    ADD COLUMN IF NOT EXISTS review_observation TEXT DEFAULT NULL AFTER observaciones,
    ADD COLUMN IF NOT EXISTS observation_status VARCHAR(20) NOT NULL DEFAULT 'none' AFTER review_observation,
    ADD COLUMN IF NOT EXISTS observation_by_user_id INT UNSIGNED DEFAULT NULL AFTER observation_status,
    ADD COLUMN IF NOT EXISTS observation_at DATETIME DEFAULT NULL AFTER observation_by_user_id,
    ADD COLUMN IF NOT EXISTS observation_resolved_by_user_id INT UNSIGNED DEFAULT NULL AFTER observation_at,
    ADD COLUMN IF NOT EXISTS observation_resolved_at DATETIME DEFAULT NULL AFTER observation_resolved_by_user_id;

CREATE TABLE IF NOT EXISTS maquinaria_documento_activity_log (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    maquinaria_documento_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED DEFAULT NULL,
    action_type VARCHAR(50) NOT NULL,
    description TEXT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_mdal_documento_fecha (maquinaria_documento_id, created_at),
    KEY idx_mdal_usuario (user_id),
    CONSTRAINT fk_mdal_documento FOREIGN KEY (maquinaria_documento_id)
        REFERENCES maquinaria_documentos (id) ON DELETE CASCADE,
    CONSTRAINT fk_mdal_usuario FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Integra observaciones históricas escritas al registrar documentos.
UPDATE maquinaria_documentos
SET review_observation = observaciones,
    observation_status = CASE WHEN observation_status = 'none' THEN 'observed' ELSE observation_status END,
    observation_by_user_id = COALESCE(observation_by_user_id, registered_by_user_id),
    observation_at = COALESCE(observation_at, updated_at, created_at, NOW())
WHERE TRIM(COALESCE(observaciones, '')) <> ''
  AND TRIM(COALESCE(review_observation, '')) = '';

INSERT INTO maquinaria_documento_activity_log
    (maquinaria_documento_id, user_id, action_type, description, created_at)
SELECT md.id, md.observation_by_user_id, 'observacion_registrada', md.review_observation,
       COALESCE(md.observation_at, md.updated_at, md.created_at, NOW())
FROM maquinaria_documentos md
WHERE TRIM(COALESCE(md.review_observation, '')) <> ''
  AND NOT EXISTS (
      SELECT 1 FROM maquinaria_documento_activity_log log
      WHERE log.maquinaria_documento_id = md.id
        AND log.action_type = 'observacion_registrada'
  );