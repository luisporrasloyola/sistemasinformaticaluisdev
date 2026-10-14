-- Formatos editables de PMI Clientes. Datos independientes de Documentos base.
CREATE TABLE IF NOT EXISTS client_pmi_format_catalog (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    client_id INT UNSIGNED NOT NULL,
    name VARCHAR(180) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_client_pmi_format_catalog (client_id, name),
    CONSTRAINT fk_client_pmi_format_catalog_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS client_pmi_format_documents (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    client_id INT UNSIGNED NOT NULL,
    catalog_id INT UNSIGNED NOT NULL,
    registration_date DATE NOT NULL,
    start_date DATE DEFAULT NULL,
    end_date DATE DEFAULT NULL,
    file_path VARCHAR(255) DEFAULT NULL,
    registered_by_user_id INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_client_pmi_format_client (client_id, catalog_id),
    CONSTRAINT fk_client_pmi_format_doc_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    CONSTRAINT fk_client_pmi_format_doc_catalog FOREIGN KEY (catalog_id) REFERENCES client_pmi_format_catalog(id) ON DELETE RESTRICT,
    CONSTRAINT fk_client_pmi_format_doc_user FOREIGN KEY (registered_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS client_pmi_format_folders (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    client_id INT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_client_pmi_format_folder (client_id, name),
    CONSTRAINT fk_client_pmi_format_folder_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS client_pmi_format_folder_items (
    document_id INT UNSIGNED NOT NULL,
    folder_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (document_id),
    KEY idx_client_pmi_format_folder_items (folder_id),
    CONSTRAINT fk_client_pmi_format_item_doc FOREIGN KEY (document_id) REFERENCES client_pmi_format_documents(id) ON DELETE CASCADE,
    CONSTRAINT fk_client_pmi_format_item_folder FOREIGN KEY (folder_id) REFERENCES client_pmi_format_folders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS client_pmi_format_observations (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    document_id INT UNSIGNED NOT NULL,
    note TEXT NOT NULL,
    created_by_user_id INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_client_pmi_format_observation (document_id, created_at),
    CONSTRAINT fk_client_pmi_format_obs_doc FOREIGN KEY (document_id) REFERENCES client_pmi_format_documents(id) ON DELETE CASCADE,
    CONSTRAINT fk_client_pmi_format_obs_user FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
