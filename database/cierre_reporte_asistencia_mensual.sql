-- Ejecutar una vez antes de habilitar el cierre mensual en el hosting.
-- Un registro por trabajador y mes. El cierre de cada parte es irreversible.
CREATE TABLE IF NOT EXISTS attendance_report_month_closures (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    worker_id INT UNSIGNED NOT NULL,
    report_month CHAR(7) NOT NULL,
    admin_user_id INT UNSIGNED DEFAULT NULL,
    admin_closed_at DATETIME DEFAULT NULL,
    staff_user_id INT UNSIGNED DEFAULT NULL,
    staff_closed_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_report_month_worker (worker_id, report_month),
    KEY idx_report_month (report_month),
    CONSTRAINT fk_report_month_worker FOREIGN KEY (worker_id) REFERENCES workers(id) ON DELETE CASCADE,
    CONSTRAINT fk_report_month_admin FOREIGN KEY (admin_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_report_month_staff FOREIGN KEY (staff_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
