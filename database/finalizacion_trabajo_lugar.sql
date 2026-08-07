-- Registro de actividades finalizadas durante una jornada con recorrido.
-- Ejecutar una sola vez en la base de datos lifemaquinarias.

CREATE TABLE attendance_work_completions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    worker_id INT UNSIGNED NOT NULL,
    assignment_id INT UNSIGNED NOT NULL,
    program_id INT UNSIGNED NULL,
    location_id INT UNSIGNED NOT NULL,
    work_date DATE NOT NULL,
    activity VARCHAR(255) NOT NULL,
    observations VARCHAR(500) NULL,
    completed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    latitude DECIMAL(10,8) NOT NULL,
    longitude DECIMAL(11,8) NOT NULL,
    created_by_user_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_work_completion_worker_date (worker_id, work_date, completed_at),
    KEY idx_work_completion_assignment (assignment_id),
    KEY idx_work_completion_program (program_id),
    KEY idx_work_completion_location (location_id),
    CONSTRAINT fk_work_completion_worker FOREIGN KEY (worker_id) REFERENCES workers(id),
    CONSTRAINT fk_work_completion_assignment FOREIGN KEY (assignment_id) REFERENCES attendance_assignments(id),
    CONSTRAINT fk_work_completion_program FOREIGN KEY (program_id) REFERENCES attendance_programs(id) ON DELETE SET NULL,
    CONSTRAINT fk_work_completion_location FOREIGN KEY (location_id) REFERENCES attendance_locations(id),
    CONSTRAINT fk_work_completion_user FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
