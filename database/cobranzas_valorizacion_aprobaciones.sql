-- Ejecutar una vez para registrar las valorizaciones aprobadas por período.
CREATE TABLE IF NOT EXISTS cobranzas_valorizacion_aprobaciones (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    valorizacion_id INT UNSIGNED NOT NULL,
    periodo_tipo ENUM('mes', 'fechas') NOT NULL,
    fecha_inicio DATE NOT NULL,
    fecha_fin DATE NOT NULL,
    approved_by_user_id INT UNSIGNED DEFAULT NULL,
    approved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cv_aprobacion_periodo (valorizacion_id, periodo_tipo, fecha_inicio, fecha_fin),
    KEY idx_cv_aprobacion_valorizacion_fecha (valorizacion_id, approved_at),
    CONSTRAINT fk_cv_aprobacion_valorizacion FOREIGN KEY (valorizacion_id)
        REFERENCES cobranzas_valorizaciones (id) ON DELETE CASCADE,
    CONSTRAINT fk_cv_aprobacion_usuario FOREIGN KEY (approved_by_user_id)
        REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
