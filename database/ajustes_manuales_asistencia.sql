-- Auditoría de correcciones manuales realizadas desde la matriz de asistencia.
CREATE TABLE IF NOT EXISTS attendance_manual_adjustments (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    attendance_mark_id INT UNSIGNED NOT NULL,
    worker_id INT UNSIGNED NOT NULL,
    mark_date DATE NOT NULL,
    mark_type ENUM('entrada', 'salida') NOT NULL,
    previous_time TIME DEFAULT NULL,
    new_time TIME NOT NULL,
    previous_location_id INT UNSIGNED DEFAULT NULL,
    new_location_id INT UNSIGNED NOT NULL,
    previous_status VARCHAR(40) DEFAULT NULL,
    new_status VARCHAR(40) NOT NULL,
    reason VARCHAR(500) NOT NULL,
    adjusted_by_user_id INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ama_worker_date (worker_id, mark_date),
    KEY idx_ama_mark (attendance_mark_id),
    KEY idx_ama_user (adjusted_by_user_id),
    KEY idx_ama_location (new_location_id),
    CONSTRAINT fk_ama_mark FOREIGN KEY (attendance_mark_id) REFERENCES attendance_marks (id) ON DELETE CASCADE,
    CONSTRAINT fk_ama_worker FOREIGN KEY (worker_id) REFERENCES workers (id) ON DELETE CASCADE,
    CONSTRAINT fk_ama_user FOREIGN KEY (adjusted_by_user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Compatibilidad si la tabla de auditoría ya fue instalada.
ALTER TABLE attendance_manual_adjustments
    ADD COLUMN IF NOT EXISTS previous_location_id INT UNSIGNED DEFAULT NULL AFTER new_time,
    ADD COLUMN IF NOT EXISTS new_location_id INT UNSIGNED DEFAULT NULL AFTER previous_location_id;
ALTER TABLE attendance_manual_adjustments
    ADD COLUMN IF NOT EXISTS previous_status VARCHAR(40) DEFAULT NULL AFTER new_location_id,
    ADD COLUMN IF NOT EXISTS new_status VARCHAR(40) DEFAULT NULL AFTER previous_status;
-- Anulación administrativa de una jornada como falta, sin eliminar marcaciones ni rutas originales.
CREATE TABLE IF NOT EXISTS attendance_manual_day_overrides (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    worker_id INT UNSIGNED NOT NULL,
    mark_date DATE NOT NULL,
    attendance_status ENUM('falta') NOT NULL DEFAULT 'falta',
    reason VARCHAR(500) NOT NULL,
    adjusted_by_user_id INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_amdo_worker_date (worker_id, mark_date),
    KEY idx_amdo_user (adjusted_by_user_id),
    CONSTRAINT fk_amdo_worker FOREIGN KEY (worker_id) REFERENCES workers (id) ON DELETE CASCADE,
    CONSTRAINT fk_amdo_user FOREIGN KEY (adjusted_by_user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;