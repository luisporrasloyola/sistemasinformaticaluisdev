CREATE TABLE IF NOT EXISTS attendance_labor_regimes (
    worker_id INT UNSIGNED NOT NULL,
    work_date DATE NOT NULL,
    regime VARCHAR(12) NOT NULL DEFAULT 'regular',
    allow_entry_overtime TINYINT(1) NOT NULL DEFAULT 0,
    allow_exit_overtime TINYINT(1) NOT NULL DEFAULT 0,
    selected_by_user_id INT UNSIGNED DEFAULT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (worker_id, work_date),
    KEY idx_attendance_labor_regime_date (work_date, worker_id),
    CONSTRAINT fk_attendance_labor_regime_worker FOREIGN KEY (worker_id)
        REFERENCES workers (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
