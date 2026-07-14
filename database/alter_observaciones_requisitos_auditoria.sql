DROP PROCEDURE IF EXISTS add_requirement_observation_audit;

DELIMITER $$

CREATE PROCEDURE add_requirement_observation_audit()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'worker_requirements'
          AND COLUMN_NAME = 'observation_status'
    ) THEN
        ALTER TABLE worker_requirements
            ADD COLUMN observation_status VARCHAR(20) NOT NULL DEFAULT 'none' AFTER observations,
            ADD COLUMN observation_by_user_id INT UNSIGNED NULL AFTER observation_status,
            ADD COLUMN observation_at DATETIME NULL AFTER observation_by_user_id,
            ADD COLUMN observation_resolved_by_user_id INT UNSIGNED NULL AFTER observation_at,
            ADD COLUMN observation_resolved_at DATETIME NULL AFTER observation_resolved_by_user_id,
            ADD KEY idx_worker_requirements_observation_status (observation_status),
            ADD KEY idx_worker_requirements_observation_by (observation_by_user_id),
            ADD KEY idx_worker_requirements_observation_resolved_by (observation_resolved_by_user_id),
            ADD CONSTRAINT fk_worker_requirements_observation_by FOREIGN KEY (observation_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
            ADD CONSTRAINT fk_worker_requirements_observation_resolved_by FOREIGN KEY (observation_resolved_by_user_id) REFERENCES users(id) ON DELETE SET NULL;
    END IF;

    CREATE TABLE IF NOT EXISTS worker_requirement_activity_log (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        worker_requirement_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NULL,
        action_type VARCHAR(40) NOT NULL,
        description TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_wral_requirement (worker_requirement_id),
        INDEX idx_wral_user (user_id),
        CONSTRAINT fk_wral_requirement FOREIGN KEY (worker_requirement_id) REFERENCES worker_requirements(id) ON DELETE CASCADE,
        CONSTRAINT fk_wral_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
END$$

DELIMITER ;

CALL add_requirement_observation_audit();

DROP PROCEDURE IF EXISTS add_requirement_observation_audit;
