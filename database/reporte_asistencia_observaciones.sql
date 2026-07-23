CREATE TABLE IF NOT EXISTS attendance_report_notes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    worker_id INT UNSIGNED NOT NULL,
    date_from DATE NOT NULL,
    date_to DATE NOT NULL,
    observation TEXT NULL,
    responsible_user_id INT UNSIGNED NULL,
    responsible_name VARCHAR(180) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_attendance_report_note (worker_id, date_from, date_to),
    CONSTRAINT fk_attendance_report_note_worker FOREIGN KEY (worker_id) REFERENCES workers(id) ON DELETE CASCADE,
    CONSTRAINT fk_attendance_report_note_user FOREIGN KEY (responsible_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
