-- Ejecutar una sola vez. Justificantes PDF o imagen de descansos del reporte de asistencia.
CREATE TABLE IF NOT EXISTS attendance_rest_justifications (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    worker_id INT UNSIGNED NOT NULL,
    rest_date DATE NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    original_file_name VARCHAR(255) NOT NULL,
    uploaded_by_user_id INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_attendance_rest_justification_worker_date (worker_id, rest_date),
    KEY idx_attendance_rest_justifications_date (rest_date),
    CONSTRAINT fk_attendance_rest_justifications_worker FOREIGN KEY (worker_id) REFERENCES workers (id) ON DELETE CASCADE,
    CONSTRAINT fk_attendance_rest_justifications_user FOREIGN KEY (uploaded_by_user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
