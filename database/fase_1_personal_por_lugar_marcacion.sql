-- Fase 1: configuración del personal autorizado por lugar de marcación.
-- Los lugares existentes mantienen el comportamiento actual hasta que sean configurados.

ALTER TABLE attendance_locations
    ADD COLUMN personnel_access_mode ENUM('all', 'selected') NOT NULL DEFAULT 'all' AFTER status,
    ADD COLUMN personnel_access_configured TINYINT(1) NOT NULL DEFAULT 0 AFTER personnel_access_mode,
    ADD COLUMN personnel_access_updated_by_user_id INT UNSIGNED NULL AFTER personnel_access_configured,
    ADD COLUMN personnel_access_updated_at DATETIME NULL AFTER personnel_access_updated_by_user_id,
    ADD KEY idx_attendance_locations_personnel_access (status, personnel_access_mode, personnel_access_configured),
    ADD KEY idx_attendance_locations_access_updated_by (personnel_access_updated_by_user_id),
    ADD CONSTRAINT chk_attendance_locations_access_configured
        CHECK (personnel_access_configured IN (0, 1)),
    ADD CONSTRAINT fk_attendance_locations_access_updated_by
        FOREIGN KEY (personnel_access_updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS attendance_location_workers (
    location_id INT UNSIGNED NOT NULL,
    worker_id INT UNSIGNED NOT NULL,
    selected_by_user_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (location_id, worker_id),
    KEY idx_attendance_location_workers_worker (worker_id, location_id),
    KEY idx_attendance_location_workers_selected_by (selected_by_user_id),
    CONSTRAINT fk_attendance_location_workers_location
        FOREIGN KEY (location_id) REFERENCES attendance_locations(id) ON DELETE CASCADE,
    CONSTRAINT fk_attendance_location_workers_worker
        FOREIGN KEY (worker_id) REFERENCES workers(id) ON DELETE CASCADE,
    CONSTRAINT fk_attendance_location_workers_selected_by
        FOREIGN KEY (selected_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Los valores DEFAULT inicializan los lugares existentes. No ejecutar un UPDATE global:
-- hacerlo eliminaría las selecciones que el administrador ya haya guardado.
