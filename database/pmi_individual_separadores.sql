-- Ejecutar una sola vez después de carpetas_pmi_individual.sql.
CREATE TABLE IF NOT EXISTS pmi_requirement_separators (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    worker_id INT UNSIGNED NOT NULL,
    position_id INT UNSIGNED NOT NULL,
    scope_type ENUM('folders','folder','ungrouped') NOT NULL,
    scope_id INT UNSIGNED NOT NULL DEFAULT 0,
    after_item_id INT UNSIGNED NOT NULL DEFAULT 0,
    created_by_user_id INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_pmi_separator_position (worker_id, position_id, scope_type, scope_id, after_item_id),
    KEY idx_pmi_separator_scope (worker_id, position_id, scope_type, scope_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
