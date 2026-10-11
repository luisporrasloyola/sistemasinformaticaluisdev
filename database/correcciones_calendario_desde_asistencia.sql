-- Auditoría de cambios individuales de calendario desde la corrección administrativa.
CREATE TABLE IF NOT EXISTS attendance_calendar_corrections (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    worker_id INT UNSIGNED NOT NULL,
    calendar_date DATE NOT NULL,
    previous_event_type VARCHAR(30) DEFAULT NULL,
    previous_name VARCHAR(180) DEFAULT NULL,
    event_type VARCHAR(30) NOT NULL,
    event_name VARCHAR(180) NOT NULL,
    reason VARCHAR(500) NOT NULL,
    adjusted_by_user_id INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_acc_worker_date (worker_id, calendar_date),
    CONSTRAINT fk_acc_worker FOREIGN KEY (worker_id) REFERENCES workers(id) ON DELETE CASCADE,
    CONSTRAINT fk_acc_user FOREIGN KEY (adjusted_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
