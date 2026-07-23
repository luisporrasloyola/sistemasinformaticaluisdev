ALTER TABLE attendance_assignments
    ADD COLUMN created_by_user_id INT UNSIGNED NULL AFTER status,
    ADD COLUMN deactivated_at DATETIME NULL AFTER created_by_user_id,
    ADD COLUMN deactivated_by_user_id INT UNSIGNED NULL AFTER deactivated_at,
    ADD KEY idx_attendance_assignments_deactivated_at (deactivated_at),
    ADD CONSTRAINT fk_attendance_assignment_created_by
        FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_attendance_assignment_deactivated_by
        FOREIGN KEY (deactivated_by_user_id) REFERENCES users(id) ON DELETE SET NULL;

UPDATE attendance_assignments
SET deactivated_at = updated_at
WHERE status = 0 AND deactivated_at IS NULL;
