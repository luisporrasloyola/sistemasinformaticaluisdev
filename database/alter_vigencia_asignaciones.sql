-- Vigencia real de las asignaciones de asistencia.
-- Las asignaciones existentes comienzan en su fecha de creacion y quedan sin fecha final.
ALTER TABLE attendance_assignments
    ADD COLUMN IF NOT EXISTS valid_from DATE NULL AFTER activity,
    ADD COLUMN IF NOT EXISTS valid_until DATE NULL AFTER valid_from;

UPDATE attendance_assignments
SET valid_from = COALESCE(valid_from, DATE(created_at), CURDATE())
WHERE valid_from IS NULL;

ALTER TABLE attendance_assignments
    MODIFY COLUMN valid_from DATE NOT NULL;
