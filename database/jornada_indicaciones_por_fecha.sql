-- Personalizacion de actividad e indicaciones para una jornada concreta.
CREATE TABLE IF NOT EXISTS attendance_journey_overrides (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    assignment_id INT UNSIGNED NOT NULL,
    journey_date DATE NOT NULL,
    activity VARCHAR(180) NOT NULL DEFAULT '',
    instructions VARCHAR(500) NOT NULL DEFAULT '',
    updated_by_user_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_attendance_journey_override (assignment_id, journey_date),
    KEY idx_attendance_journey_override_date (journey_date),
    CONSTRAINT fk_attendance_journey_override_assignment
        FOREIGN KEY (assignment_id) REFERENCES attendance_assignments(id),
    CONSTRAINT fk_attendance_journey_override_user
        FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
