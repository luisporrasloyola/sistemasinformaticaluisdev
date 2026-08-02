-- Lugar y plantilla propios para cada jornada extraordinaria.
ALTER TABLE attendance_programs
    ADD COLUMN IF NOT EXISTS location_id INT UNSIGNED NULL AFTER worker_id,
    ADD COLUMN IF NOT EXISTS schedule_id INT UNSIGNED NULL AFTER location_id;

UPDATE attendance_programs ap
JOIN attendance_assignments aa ON aa.id = ap.assignment_id
SET ap.location_id = COALESCE(ap.location_id, aa.location_id),
    ap.schedule_id = COALESCE(ap.schedule_id, aa.schedule_id)
WHERE ap.location_id IS NULL OR ap.schedule_id IS NULL;

-- Las columnas se mantienen opcionales para conservar compatibilidad con
-- programaciones antiguas; la aplicación usa la asignación como respaldo.
