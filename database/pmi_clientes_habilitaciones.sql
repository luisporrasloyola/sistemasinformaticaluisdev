CREATE TABLE IF NOT EXISTS client_pmi_habilitations (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    client_id INT UNSIGNED NOT NULL,
    area ENUM('personal','equipo') NOT NULL,
    subject_id INT UNSIGNED NOT NULL,
    habilitation_type ENUM('individual','grupal') NOT NULL DEFAULT 'individual',
    has_pass TINYINT(1) DEFAULT NULL,
    pass_file_path VARCHAR(255) DEFAULT NULL,
    has_validity TINYINT(1) DEFAULT NULL,
    registration_date DATE DEFAULT NULL,
    start_date DATE DEFAULT NULL,
    end_date DATE DEFAULT NULL,
    registered_by_user_id INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_client_pmi_habilitation (client_id, area, subject_id),
    CONSTRAINT fk_client_pmi_habilitation_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
