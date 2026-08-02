ALTER TABLE attendance_programs
    ADD COLUMN IF NOT EXISTS schedule_source ENUM('template', 'extraordinary') NOT NULL DEFAULT 'template'
    AFTER tolerance_minutes;
