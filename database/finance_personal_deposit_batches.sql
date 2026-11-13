-- Ejecutar una sola vez después de finance_personal_deposits_registration_source.sql.
CREATE TABLE IF NOT EXISTS finance_personal_deposit_batches (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    image_path VARCHAR(255) NOT NULL,
    image_sha256 CHAR(64) NOT NULL,
    image_original_name VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_personal_deposit_batch_image (image_sha256)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE finance_personal_deposits
    ADD COLUMN batch_id BIGINT UNSIGNED NULL AFTER image_sha256,
    ADD COLUMN worker_id INT UNSIGNED NULL AFTER batch_id,
    ADD COLUMN image_region_x1 SMALLINT UNSIGNED NULL AFTER worker_id,
    ADD COLUMN image_region_y1 SMALLINT UNSIGNED NULL AFTER image_region_x1,
    ADD COLUMN image_region_x2 SMALLINT UNSIGNED NULL AFTER image_region_y1,
    ADD COLUMN image_region_y2 SMALLINT UNSIGNED NULL AFTER image_region_x2,
    ADD KEY idx_personal_deposits_batch (batch_id),
    ADD KEY idx_personal_deposits_worker (worker_id);
