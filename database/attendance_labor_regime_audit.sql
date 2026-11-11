CREATE TABLE IF NOT EXISTS attendance_labor_regime_audit (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    worker_id INT UNSIGNED NOT NULL,
    work_date DATE NOT NULL,
    previous_regime VARCHAR(12) NOT NULL,
    new_regime VARCHAR(12) NOT NULL,
    reason VARCHAR(500) NOT NULL,
    adjusted_by_user_id INT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_labor_regime_audit_worker_date (worker_id, work_date),
    CONSTRAINT fk_labor_regime_audit_worker FOREIGN KEY (worker_id)
        REFERENCES workers (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
