-- Requisitos generales seleccionados y documentos base, independientes por cliente.
CREATE TABLE IF NOT EXISTS client_pmi_general_items (
    client_id INT UNSIGNED NOT NULL,
    category ENUM('personal','equipos') NOT NULL,
    source_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (client_id, category, source_id),
    CONSTRAINT fk_client_pmi_general_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS client_pmi_general_positions (
    client_id INT UNSIGNED NOT NULL,
    position_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (client_id, position_id),
    CONSTRAINT fk_client_pmi_general_position_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    CONSTRAINT fk_client_pmi_general_position_position FOREIGN KEY (position_id) REFERENCES positions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS client_pmi_general_position_requirements (
    client_id INT UNSIGNED NOT NULL,
    position_id INT UNSIGNED NOT NULL,
    requirement_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (client_id, position_id, requirement_id),
    CONSTRAINT fk_client_pmi_position_requirements_position FOREIGN KEY (client_id, position_id)
        REFERENCES client_pmi_general_positions(client_id, position_id) ON DELETE CASCADE,
    CONSTRAINT fk_client_pmi_position_requirements_requirement FOREIGN KEY (requirement_id)
        REFERENCES requirements_catalog(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS client_pmi_base_documents (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    client_id INT UNSIGNED NOT NULL,
    name VARCHAR(180) NOT NULL,
    start_date DATE DEFAULT NULL,
    end_date DATE DEFAULT NULL,
    file_path VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_client_pmi_base_client (client_id, name),
    CONSTRAINT fk_client_pmi_base_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
