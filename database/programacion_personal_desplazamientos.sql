CREATE TABLE IF NOT EXISTS attendance_programs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    assignment_id INT UNSIGNED NOT NULL,
    worker_id INT UNSIGNED NOT NULL,
    location_id INT UNSIGNED NULL,
    schedule_id INT UNSIGNED NULL,
    program_date DATE NOT NULL,
    entry_time TIME NOT NULL,
    entry_start TIME NOT NULL,
    entry_end TIME NOT NULL,
    exit_time TIME NOT NULL,
    tolerance_minutes INT UNSIGNED NOT NULL DEFAULT 0,
    activity VARCHAR(180) NULL,
    notes VARCHAR(500) NULL,
    status ENUM('programada','cancelada') NOT NULL DEFAULT 'programada',
    created_by_user_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_attendance_program_assignment_date (assignment_id, program_date),
    KEY idx_attendance_program_worker_date (worker_id, program_date),
    KEY idx_attendance_program_location (location_id),
    KEY idx_attendance_program_schedule (schedule_id),
    CONSTRAINT fk_attendance_program_assignment FOREIGN KEY (assignment_id) REFERENCES attendance_assignments(id),
    CONSTRAINT fk_attendance_program_worker FOREIGN KEY (worker_id) REFERENCES workers(id) ON DELETE CASCADE,
    CONSTRAINT fk_attendance_program_location FOREIGN KEY (location_id) REFERENCES attendance_locations(id),
    CONSTRAINT fk_attendance_program_schedule FOREIGN KEY (schedule_id) REFERENCES attendance_schedules(id),
    CONSTRAINT fk_attendance_program_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS attendance_program_stops (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    program_id INT UNSIGNED NOT NULL,
    stop_order INT UNSIGNED NOT NULL,
    destination VARCHAR(180) NOT NULL,
    activity VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_attendance_program_stop_order (program_id, stop_order),
    CONSTRAINT fk_attendance_program_stop FOREIGN KEY (program_id) REFERENCES attendance_programs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS attendance_trips (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    worker_id INT UNSIGNED NOT NULL,
    assignment_id INT UNSIGNED NOT NULL,
    program_id INT UNSIGNED NULL,
    trip_date DATE NOT NULL,
    reason VARCHAR(255) NOT NULL,
    first_destination VARCHAR(180) NOT NULL,
    status ENUM('en_ruta','finalizado') NOT NULL DEFAULT 'en_ruta',
    completion_type ENUM('arrival_confirmed','temporary_return_confirmed','returned_without_arrival') NOT NULL DEFAULT 'arrival_confirmed',
    exception_reason VARCHAR(500) NULL,
    started_at DATETIME NOT NULL,
    ended_at DATETIME NULL,
    start_latitude DECIMAL(10,8) NOT NULL,
    start_longitude DECIMAL(11,8) NOT NULL,
    end_latitude DECIMAL(10,8) NULL,
    end_longitude DECIMAL(11,8) NULL,
    created_by_user_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_attendance_trip_worker_date (worker_id, trip_date),
    CONSTRAINT fk_attendance_trip_worker FOREIGN KEY (worker_id) REFERENCES workers(id) ON DELETE CASCADE,
    CONSTRAINT fk_attendance_trip_assignment FOREIGN KEY (assignment_id) REFERENCES attendance_assignments(id),
    CONSTRAINT fk_attendance_trip_program FOREIGN KEY (program_id) REFERENCES attendance_programs(id) ON DELETE SET NULL,
    CONSTRAINT fk_attendance_trip_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS attendance_trip_stops (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    trip_id INT UNSIGNED NOT NULL,
    stop_order INT UNSIGNED NOT NULL,
    destination VARCHAR(180) NOT NULL,
    activity VARCHAR(255) NULL,
    registered_at DATETIME NOT NULL,
    latitude DECIMAL(10,8) NOT NULL,
    longitude DECIMAL(11,8) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_attendance_trip_stop_order (trip_id, stop_order),
    CONSTRAINT fk_attendance_trip_stop FOREIGN KEY (trip_id) REFERENCES attendance_trips(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE attendance_marks
    ADD COLUMN program_id INT UNSIGNED NULL AFTER assignment_id,
    ADD KEY idx_attendance_marks_program (program_id),
    ADD CONSTRAINT fk_attendance_mark_program FOREIGN KEY (program_id) REFERENCES attendance_programs(id) ON DELETE SET NULL;
