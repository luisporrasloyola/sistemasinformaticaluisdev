-- Ejecutar una sola vez antes de publicar Cliente y Régimen laboral en Asignaciones.
ALTER TABLE attendance_assignments
    ADD COLUMN project_id INT UNSIGNED NULL AFTER schedule_id,
    ADD COLUMN labor_regime ENUM('regular', '21x7') NULL AFTER project_id,
    ADD KEY idx_attendance_assignments_project (project_id),
    ADD CONSTRAINT fk_attendance_assignments_project
        FOREIGN KEY (project_id) REFERENCES attendance_projects (id) ON DELETE SET NULL;

-- NULL conserva el comportamiento de las asignaciones existentes.
-- Las nuevas selecciones de régimen también se registran, con auditoría, en attendance_labor_regimes.
