-- Ajuste excepcional del saldo HEMP de octubre de 2026 por trabajador.
-- El historial de cambios se conserva por separado; septiembre no se modifica.
CREATE TABLE IF NOT EXISTS attendance_hemp_opening_adjustments (
    worker_id INT UNSIGNED NOT NULL,
    report_month CHAR(7) NOT NULL,
    opening_minutes INT NOT NULL,
    reason VARCHAR(500) NOT NULL,
    adjusted_by_user_id INT UNSIGNED DEFAULT NULL,
    adjusted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (worker_id, report_month),
    CONSTRAINT fk_hemp_adjustment_worker FOREIGN KEY (worker_id) REFERENCES workers(id) ON DELETE CASCADE,
    CONSTRAINT fk_hemp_adjustment_user FOREIGN KEY (adjusted_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS attendance_hemp_opening_adjustment_history (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    worker_id INT UNSIGNED NOT NULL,
    report_month CHAR(7) NOT NULL,
    previous_minutes INT NOT NULL,
    new_minutes INT NOT NULL,
    reason VARCHAR(500) NOT NULL,
    adjusted_by_user_id INT UNSIGNED DEFAULT NULL,
    adjusted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_hemp_history_worker_month (worker_id, report_month),
    CONSTRAINT fk_hemp_history_worker FOREIGN KEY (worker_id) REFERENCES workers(id) ON DELETE CASCADE,
    CONSTRAINT fk_hemp_history_user FOREIGN KEY (adjusted_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
