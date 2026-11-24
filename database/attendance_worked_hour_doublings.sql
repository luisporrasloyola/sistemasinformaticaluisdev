-- Ejecutar una sola vez en la base de datos antes de publicar la nueva opción del reporte.
CREATE TABLE IF NOT EXISTS attendance_worked_hour_doublings (
    worker_id INT UNSIGNED NOT NULL,
    work_date DATE NOT NULL,
    enabled_by_user_id INT UNSIGNED DEFAULT NULL,
    enabled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (worker_id, work_date),
    CONSTRAINT fk_attendance_worked_double_worker FOREIGN KEY (worker_id) REFERENCES workers (id) ON DELETE CASCADE,
    CONSTRAINT fk_attendance_worked_double_user FOREIGN KEY (enabled_by_user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
