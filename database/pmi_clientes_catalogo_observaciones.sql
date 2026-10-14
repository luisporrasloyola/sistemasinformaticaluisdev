-- Ejecutar una vez después de pmi_clientes_documentos_base.sql.
CREATE TABLE IF NOT EXISTS client_pmi_base_catalog (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    client_id INT UNSIGNED NOT NULL,
    name VARCHAR(180) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_client_pmi_catalog_name (client_id, name),
    CONSTRAINT fk_client_pmi_catalog_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO client_pmi_base_catalog (client_id, name)
SELECT DISTINCT client_id, name FROM client_pmi_base_documents;

CREATE TABLE IF NOT EXISTS client_pmi_base_observations (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    document_id INT UNSIGNED NOT NULL,
    note TEXT NOT NULL,
    created_by_user_id INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_client_pmi_observation_document (document_id, created_at),
    CONSTRAINT fk_client_pmi_observation_document FOREIGN KEY (document_id) REFERENCES client_pmi_base_documents(id) ON DELETE CASCADE,
    CONSTRAINT fk_client_pmi_observation_user FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
