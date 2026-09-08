<?php
declare(strict_types=1);

function ensure_attendance_projects_schema(): void
{
    db()->exec("CREATE TABLE IF NOT EXISTS attendance_projects (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(180) NOT NULL,
        status TINYINT(1) NOT NULL DEFAULT 1,
        created_by_user_id INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_attendance_projects_name (name),
        KEY idx_attendance_projects_status_name (status, name),
        CONSTRAINT fk_attendance_projects_user FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}