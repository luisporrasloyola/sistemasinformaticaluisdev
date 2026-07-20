CREATE TABLE IF NOT EXISTS attendance_holidays (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    holiday_date DATE NOT NULL,
    name VARCHAR(180) NOT NULL,
    source VARCHAR(40) NOT NULL DEFAULT 'manual',
    status TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_attendance_holiday_date (holiday_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS attendance_calendar_days (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    calendar_date DATE NOT NULL,
    end_date DATE NULL,
    event_type VARCHAR(30) NOT NULL,
    name VARCHAR(180) NOT NULL,
    scope_type VARCHAR(20) NOT NULL DEFAULT 'all',
    company_id INT UNSIGNED NULL,
    worker_id INT UNSIGNED NULL,
    status TINYINT(1) NOT NULL DEFAULT 1,
    created_by_user_id INT UNSIGNED NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_calendar_days_date (calendar_date),
    KEY idx_calendar_days_scope (scope_type, company_id, worker_id),
    KEY idx_calendar_days_status (status),
    CONSTRAINT fk_calendar_days_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    CONSTRAINT fk_calendar_days_worker FOREIGN KEY (worker_id) REFERENCES workers(id) ON DELETE CASCADE,
    CONSTRAINT fk_calendar_days_user FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE attendance_calendar_days
    ADD COLUMN IF NOT EXISTS end_date DATE NULL AFTER calendar_date;

UPDATE attendance_calendar_days
SET end_date = calendar_date
WHERE end_date IS NULL;

UPDATE attendance_calendar_days
SET status = 0
WHERE event_type = 'working_exception';

INSERT INTO attendance_calendar_days
    (calendar_date, end_date, event_type, name, scope_type, status, created_at)
SELECT ah.holiday_date, ah.holiday_date, 'holiday', ah.name, 'all', ah.status, ah.created_at
FROM attendance_holidays ah
WHERE NOT EXISTS (
    SELECT 1
    FROM attendance_calendar_days acd
    WHERE acd.calendar_date = ah.holiday_date
      AND acd.event_type = 'holiday'
      AND acd.scope_type = 'all'
      AND acd.name = ah.name
);
