-- Ejecutar una sola vez en bases existentes antes de habilitar Nuevo Manual.
ALTER TABLE finance_personal_deposits
    MODIFY COLUMN image_path VARCHAR(255) NULL DEFAULT NULL,
    MODIFY COLUMN image_original_name VARCHAR(255) NULL DEFAULT NULL,
    MODIFY COLUMN image_sha256 CHAR(64) NULL DEFAULT NULL;
