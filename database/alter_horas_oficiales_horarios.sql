ALTER TABLE attendance_schedule_days
    ADD COLUMN entry_time TIME NULL AFTER day_of_week,
    ADD COLUMN exit_time TIME NULL AFTER break_end;

UPDATE attendance_schedule_days
SET entry_time = COALESCE(
        entry_time,
        SUBTIME(entry_end, SEC_TO_TIME(tolerance_minutes * 60)),
        entry_start
    ),
    exit_time = COALESCE(exit_time, exit_start);
