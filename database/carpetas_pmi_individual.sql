-- Carpetas por trabajador y puesto. Los requisitos se conservan al borrar una carpeta.
CREATE TABLE IF NOT EXISTS pmi_requirement_folders (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    worker_id INT UNSIGNED NOT NULL,
    position_id INT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    created_by_user_id INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_pmi_folder_scope_name (worker_id, position_id, name),
    KEY idx_pmi_folder_position (position_id),
    CONSTRAINT fk_pmi_folder_worker FOREIGN KEY (worker_id) REFERENCES workers(id) ON DELETE CASCADE,
    CONSTRAINT fk_pmi_folder_position FOREIGN KEY (position_id) REFERENCES positions(id) ON DELETE CASCADE,
    CONSTRAINT fk_pmi_folder_user FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pmi_requirement_folder_items (
    requirement_record_id INT UNSIGNED NOT NULL,
    folder_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (requirement_record_id),
    KEY idx_pmi_folder_items_folder (folder_id),
    CONSTRAINT fk_pmi_folder_item_record FOREIGN KEY (requirement_record_id) REFERENCES worker_requirements(id) ON DELETE CASCADE,
    CONSTRAINT fk_pmi_folder_item_folder FOREIGN KEY (folder_id) REFERENCES pmi_requirement_folders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
