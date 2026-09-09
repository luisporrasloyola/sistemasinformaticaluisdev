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
function ensure_quick_attendance_marking_schema(): void
{
    ensure_attendance_projects_schema();
    $pdo = db();
    $database = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    $column = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=:db AND TABLE_NAME='attendance_marks' AND COLUMN_NAME='project_id'");
    $column->execute(['db'=>$database]);
    if (!(int)$column->fetchColumn()) {
        $pdo->exec('ALTER TABLE attendance_marks ADD COLUMN project_id INT UNSIGNED NULL AFTER schedule_id, ADD KEY idx_attendance_marks_project (project_id), ADD CONSTRAINT fk_attendance_mark_project FOREIGN KEY (project_id) REFERENCES attendance_projects(id) ON DELETE SET NULL');
    }
    $index = $pdo->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=:db AND TABLE_NAME='attendance_marks' AND INDEX_NAME='uq_attendance_mark_once'");
    $index->execute(['db'=>$database]);
    if ((int)$index->fetchColumn()) {
        $pdo->exec('ALTER TABLE attendance_marks DROP INDEX uq_attendance_mark_once');
    }
}