ALTER TABLE attendance_locations
    DROP CONSTRAINT chk_attendance_location_radius;

ALTER TABLE attendance_locations
    ADD CONSTRAINT chk_attendance_location_radius
    CHECK (radius_meters BETWEEN 50 AND 30000);

UPDATE attendance_locations
SET radius_meters = 30000
WHERE name = 'Central ventanillaaa';