-- Recorridos de trabajo con múltiples lugares.
-- Ejecutar una sola vez en la base de datos lifemaquinarias.

ALTER TABLE attendance_program_stops
    ADD COLUMN location_id INT UNSIGNED NULL AFTER program_id,
    ADD COLUMN estimated_time TIME NULL AFTER activity,
    ADD KEY idx_attendance_program_stop_location (location_id),
    ADD CONSTRAINT fk_attendance_program_stop_location
        FOREIGN KEY (location_id) REFERENCES attendance_locations(id);

ALTER TABLE attendance_trips
    ADD COLUMN first_destination_location_id INT UNSIGNED NULL AFTER first_destination,
    ADD COLUMN last_location_id INT UNSIGNED NULL AFTER first_destination_location_id,
    ADD KEY idx_attendance_trip_first_location (first_destination_location_id),
    ADD KEY idx_attendance_trip_last_location (last_location_id),
    ADD CONSTRAINT fk_attendance_trip_first_location
        FOREIGN KEY (first_destination_location_id) REFERENCES attendance_locations(id),
    ADD CONSTRAINT fk_attendance_trip_last_location
        FOREIGN KEY (last_location_id) REFERENCES attendance_locations(id);

ALTER TABLE attendance_trip_stops
    ADD COLUMN location_id INT UNSIGNED NULL AFTER trip_id,
    ADD KEY idx_attendance_trip_stop_location (location_id),
    ADD CONSTRAINT fk_attendance_trip_stop_location
        FOREIGN KEY (location_id) REFERENCES attendance_locations(id);
