-- Fase 1 de Registro de compras. Ejecutar una sola vez en cada base de datos.
-- Estas tablas son independientes de finance_personal_deposits y sus lotes.
CREATE TABLE IF NOT EXISTS purchase_record_batches (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    image_path VARCHAR(255) NOT NULL,
    image_sha256 CHAR(64) NOT NULL,
    image_original_name VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_purchase_record_batch_image (image_sha256)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS purchase_records (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    purchase_date DATE NOT NULL,
    registration_source VARCHAR(10) NOT NULL DEFAULT 'gemini',
    creditor VARCHAR(150) NOT NULL,
    depositor VARCHAR(150) NULL DEFAULT NULL,
    amount DECIMAL(12,2) NOT NULL,
    payment_method VARCHAR(100) NOT NULL,
    image_path VARCHAR(255) NULL DEFAULT NULL,
    image_original_name VARCHAR(255) NULL DEFAULT NULL,
    image_sha256 CHAR(64) NULL DEFAULT NULL,
    batch_id BIGINT UNSIGNED NULL DEFAULT NULL,
    worker_id INT UNSIGNED NULL DEFAULT NULL,
    image_region_x1 SMALLINT UNSIGNED NULL DEFAULT NULL,
    image_region_y1 SMALLINT UNSIGNED NULL DEFAULT NULL,
    image_region_x2 SMALLINT UNSIGNED NULL DEFAULT NULL,
    image_region_y2 SMALLINT UNSIGNED NULL DEFAULT NULL,
    registered_by_user_id INT UNSIGNED NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_purchase_record_image (image_sha256),
    KEY idx_purchase_record_date (purchase_date, id),
    KEY idx_purchase_record_batch (batch_id),
    KEY idx_purchase_record_worker (worker_id),
    KEY fk_purchase_record_user (registered_by_user_id),
    CONSTRAINT fk_purchase_record_user FOREIGN KEY (registered_by_user_id)
        REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
