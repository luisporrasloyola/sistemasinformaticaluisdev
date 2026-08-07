-- Regreso al lugar habitual cuando el trabajador no confirmó su llegada al destino temporal.
-- Ejecutar una sola vez en la base de datos lifemaquinarias.

ALTER TABLE attendance_trips
    ADD COLUMN completion_type ENUM('arrival_confirmed','temporary_return_confirmed','returned_without_arrival')
        NOT NULL DEFAULT 'arrival_confirmed' AFTER status,
    ADD COLUMN exception_reason VARCHAR(500) NULL AFTER completion_type;
