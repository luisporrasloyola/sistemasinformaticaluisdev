-- Ejecutar una vez después de pmi_clientes.sql.
ALTER TABLE client_pmi_base_documents
    ADD COLUMN registration_date DATE DEFAULT NULL,
    ADD COLUMN registered_by_user_id INT UNSIGNED DEFAULT NULL,
    ADD KEY idx_client_pmi_base_registered_by (registered_by_user_id),
    ADD CONSTRAINT fk_client_pmi_base_user FOREIGN KEY (registered_by_user_id) REFERENCES users(id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS client_pmi_base_folders (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    client_id INT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_client_pmi_folder_name (client_id, name),
    CONSTRAINT fk_client_pmi_folder_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS client_pmi_base_folder_items (
    document_id INT UNSIGNED NOT NULL,
    folder_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (document_id),
    KEY idx_client_pmi_folder_items_folder (folder_id),
    CONSTRAINT fk_client_pmi_folder_item_document FOREIGN KEY (document_id) REFERENCES client_pmi_base_documents(id) ON DELETE CASCADE,
    CONSTRAINT fk_client_pmi_folder_item_folder FOREIGN KEY (folder_id) REFERENCES client_pmi_base_folders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
