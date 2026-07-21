CREATE TABLE attendance_schedules (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(160) NOT NULL,
    status TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_attendance_schedules_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE attendance_schedule_days (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    schedule_id INT UNSIGNED NOT NULL,
    day_of_week TINYINT UNSIGNED NOT NULL,
    entry_time TIME NULL,
    entry_start TIME NULL,
    entry_end TIME NULL,
    break_start TIME NULL,
    break_end TIME NULL,
    exit_time TIME NULL,
    exit_start TIME NULL,
    exit_end TIME NULL,
    tolerance_minutes INT UNSIGNED NOT NULL DEFAULT 0,
    status TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_schedule_day (schedule_id, day_of_week),
    CONSTRAINT fk_schedule_days_schedule FOREIGN KEY (schedule_id) REFERENCES attendance_schedules(id) ON DELETE CASCADE,
    CONSTRAINT chk_schedule_day_of_week CHECK (day_of_week BETWEEN 1 AND 7)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE attendance_locations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(160) NOT NULL,
    latitude DECIMAL(10,8) NOT NULL,
    longitude DECIMAL(11,8) NOT NULL,
    address VARCHAR(255) NULL,
    radius_meters INT UNSIGNED NOT NULL DEFAULT 100,
    status TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_attendance_locations_name (name),
    CONSTRAINT chk_attendance_location_radius CHECK (radius_meters BETWEEN 50 AND 1000)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE attendance_assignments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    worker_id INT UNSIGNED NOT NULL,
    location_id INT UNSIGNED NOT NULL,
    schedule_id INT UNSIGNED NOT NULL,
    activity VARCHAR(180) NULL,
    status TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_attendance_assignments_worker (worker_id),
    KEY idx_attendance_assignments_location (location_id),
    KEY idx_attendance_assignments_schedule (schedule_id),
    CONSTRAINT fk_attendance_assignment_worker FOREIGN KEY (worker_id) REFERENCES workers(id) ON DELETE CASCADE,
    CONSTRAINT fk_attendance_assignment_location FOREIGN KEY (location_id) REFERENCES attendance_locations(id),
    CONSTRAINT fk_attendance_assignment_schedule FOREIGN KEY (schedule_id) REFERENCES attendance_schedules(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE attendance_holidays (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    holiday_date DATE NOT NULL,
    name VARCHAR(180) NOT NULL,
    source VARCHAR(40) NOT NULL DEFAULT 'manual',
    status TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_attendance_holiday_date (holiday_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
