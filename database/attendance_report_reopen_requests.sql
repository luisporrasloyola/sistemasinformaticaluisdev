CREATE TABLE IF NOT EXISTS attendance_report_reopen_requests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    worker_id INT UNSIGNED NOT NULL,
    report_month CHAR(7) NOT NULL,
    status VARCHAR(12) NOT NULL DEFAULT 'pending',
    reason VARCHAR(500) NOT NULL,
    requested_by_user_id INT UNSIGNED NOT NULL,
    requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    approved_by_user_id INT UNSIGNED NULL,
    approved_at DATETIME NULL,
    previous_admin_user_id INT UNSIGNED NULL,
    previous_admin_closed_at DATETIME NULL,
    previous_staff_user_id INT UNSIGNED NULL,
    previous_staff_closed_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_attendance_reopen_worker_month (worker_id, report_month, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
