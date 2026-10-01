-- Fase 1: autorización de horas extra anteriores a la entrada programada.
-- Una autorización corresponde a un trabajador y una fecha concreta.
-- La tolerancia de 15 minutos para la salida se aplicará en el cálculo del reporte;
-- no modifica las plantillas de horarios.
CREATE TABLE IF NOT EXISTS attendance_early_overtime_authorizations (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    worker_id INT UNSIGNED NOT NULL,
    work_date DATE NOT NULL,
    is_authorized TINYINT(1) NOT NULL DEFAULT 1,
    authorized_by_user_id INT UNSIGNED DEFAULT NULL,
    authorized_by_name VARCHAR(180) NOT NULL,
    authorized_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revoked_by_user_id INT UNSIGNED DEFAULT NULL,
    revoked_by_name VARCHAR(180) DEFAULT NULL,
    revoked_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_aeoa_worker_date (worker_id, work_date),
    KEY idx_aeoa_work_date (work_date),
    KEY idx_aeoa_authorized_by (authorized_by_user_id),
    KEY idx_aeoa_revoked_by (revoked_by_user_id),
    CONSTRAINT fk_aeoa_worker FOREIGN KEY (worker_id) REFERENCES workers (id) ON DELETE CASCADE,
    CONSTRAINT fk_aeoa_authorized_by FOREIGN KEY (authorized_by_user_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_aeoa_revoked_by FOREIGN KEY (revoked_by_user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
