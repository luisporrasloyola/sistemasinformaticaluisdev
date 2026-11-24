-- Ejecutar una sola vez antes de publicar la opción «Día Extra» del dashboard.
CREATE TABLE IF NOT EXISTS attendance_extra_days (
    worker_id INT UNSIGNED NOT NULL,
    work_date DATE NOT NULL,
    changed_by_user_id INT UNSIGNED DEFAULT NULL,
    changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (worker_id, work_date),
    CONSTRAINT fk_attendance_extra_day_worker FOREIGN KEY (worker_id) REFERENCES workers (id) ON DELETE CASCADE,
    CONSTRAINT fk_attendance_extra_day_user FOREIGN KEY (changed_by_user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
