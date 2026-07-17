CREATE TABLE IF NOT EXISTS status_alert_settings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    scope_key VARCHAR(80) NOT NULL,
    catalog_id INT UNSIGNED NOT NULL,
    warning_days INT UNSIGNED NOT NULL DEFAULT 30,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_status_alert_scope_catalog (scope_key, catalog_id),
    KEY idx_status_alert_scope (scope_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
