ALTER TABLE attendance_locations
    ADD COLUMN created_by_user_id INT UNSIGNED NULL AFTER status,
    ADD KEY idx_attendance_locations_created_by (created_by_user_id),
    ADD CONSTRAINT fk_attendance_locations_created_by
        FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL;
