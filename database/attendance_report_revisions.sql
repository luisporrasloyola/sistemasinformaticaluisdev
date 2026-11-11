CREATE TABLE IF NOT EXISTS attendance_report_revisions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    worker_id INT UNSIGNED NOT NULL,
    report_month CHAR(7) NOT NULL,
    previous_admin_user_id INT UNSIGNED NULL,
    previous_admin_closed_at DATETIME NULL,
    previous_staff_user_id INT UNSIGNED NULL,
    previous_staff_closed_at DATETIME NULL,
    correction_date DATE NOT NULL,
    correction_reason VARCHAR(500) NOT NULL,
    corrected_by_user_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_attendance_report_revisions_worker_month (worker_id, report_month, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
