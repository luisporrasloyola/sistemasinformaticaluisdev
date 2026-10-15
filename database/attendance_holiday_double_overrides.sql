-- Ejecutar una vez. Sin registro, el feriado conserva el cálculo doble habitual.
CREATE TABLE IF NOT EXISTS attendance_holiday_double_overrides (
    worker_id INT UNSIGNED NOT NULL,
    work_date DATE NOT NULL,
    double_enabled TINYINT(1) NOT NULL DEFAULT 1,
    changed_by_user_id INT UNSIGNED DEFAULT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (worker_id, work_date),
    KEY idx_holiday_double_date (work_date, worker_id),
    CONSTRAINT fk_holiday_double_worker FOREIGN KEY (worker_id) REFERENCES workers(id) ON DELETE CASCADE,
    CONSTRAINT fk_holiday_double_user FOREIGN KEY (changed_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
