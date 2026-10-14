-- Archivos y fechas propios de cada formato en una habilitación de Personal.
CREATE TABLE IF NOT EXISTS client_pmi_habilitation_formats (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    habilitation_id INT UNSIGNED NOT NULL,
    catalog_id INT UNSIGNED NOT NULL,
    registration_date DATE NOT NULL,
    start_date DATE DEFAULT NULL,
    end_date DATE DEFAULT NULL,
    file_path VARCHAR(255) DEFAULT NULL,
    registered_by_user_id INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_habilitation_format (habilitation_id, catalog_id),
    KEY idx_habilitation_format_catalog (catalog_id),
    CONSTRAINT fk_habilitation_format_hab FOREIGN KEY (habilitation_id) REFERENCES client_pmi_habilitations(id) ON DELETE CASCADE,
    CONSTRAINT fk_habilitation_format_catalog FOREIGN KEY (catalog_id) REFERENCES client_pmi_format_catalog(id) ON DELETE RESTRICT,
    CONSTRAINT fk_habilitation_format_user FOREIGN KEY (registered_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
