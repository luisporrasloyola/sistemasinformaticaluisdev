-- Ejecutar después de attendance_extra_days.sql.
ALTER TABLE attendance_assignments
    ADD COLUMN day_type ENUM('regular', 'extra') NOT NULL DEFAULT 'regular' AFTER labor_regime;

-- Las filas existentes en attendance_extra_days siguen siendo Día Extra.
-- El valor regular permite exceptuar una fecha de una asignación de Día Extra.
ALTER TABLE attendance_extra_days
    ADD COLUMN day_type ENUM('regular', 'extra') NOT NULL DEFAULT 'extra' AFTER work_date;
