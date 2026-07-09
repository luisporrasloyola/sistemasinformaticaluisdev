<?php

declare(strict_types=1);

function ensure_attendance_schema(): void
{
    db()->exec("CREATE TABLE IF NOT EXISTS attendance_control (
        id INT AUTO_INCREMENT PRIMARY KEY,
        fecha DATE NOT NULL,
        nombre_apellido VARCHAR(180) NOT NULL,
        lugar_actividad TEXT NOT NULL,
        empresa_proyecto VARCHAR(180) NULL,
        puesto VARCHAR(160) NULL,
        record_hash CHAR(64) NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_attendance_record (record_hash),
        INDEX idx_attendance_fecha (fecha),
        INDEX idx_attendance_nombre (nombre_apellido),
        INDEX idx_attendance_empresa (empresa_proyecto),
        INDEX idx_attendance_puesto (puesto)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function attendance_hash(string $fecha, string $nombre, string $actividad, string $empresa, string $puesto): string
{
    return hash('sha256', implode('|', [
        $fecha,
        normalize_attendance_text($nombre),
        normalize_attendance_text($actividad),
        normalize_attendance_text($empresa),
        normalize_attendance_text($puesto),
    ]));
}

function attendance_rating(string $actividad): array
{
    $text = normalize_attendance_text($actividad);

    if (str_contains($text, 'FALTA INJUSTIFICADA')) {
        return ['label' => 'FALTÓ', 'class' => 'text-bg-danger'];
    }

    $restPatterns = [
        'LIMA STAND BY DESCANSO',
        'STAND BY DESCANSO',
        'DESCANSO',
        'CUENTA DE VACACIONES',
        'VACACIONES',
        'A CUENTA DE HORAS',
    ];

    foreach ($restPatterns as $pattern) {
        if (str_contains($text, $pattern)) {
            return ['label' => 'DESCANSO', 'class' => 'text-bg-warning'];
        }
    }

    return ['label' => 'ASISTIÓ', 'class' => 'text-bg-success'];
}

function normalize_attendance_text(string $value): string
{
    $value = trim($value);
    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    $value = $ascii !== false ? $ascii : $value;
    $value = strtoupper($value);
    $value = preg_replace('/\s+/', ' ', $value) ?: $value;
    return trim($value);
}

function excel_serial_to_date(int|float|string $value): ?string
{
    if ($value === '' || $value === null) {
        return null;
    }

    if (is_string($value)) {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $value = preg_replace('/\s+/', ' ', $value) ?: $value;
        $value = preg_replace('/\s+\d{1,2}:\d{2}(:\d{2})?$/', '', $value) ?: $value;

        if (preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})$/', $value, $m)) {
            return valid_date_or_null((int) $m[1], (int) $m[2], (int) $m[3]);
        }

        if (preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{4})$/', $value, $m)) {
            $first = (int) $m[1];
            $second = (int) $m[2];
            $year = (int) $m[3];

            if ($first > 12 && $second <= 12) {
                return valid_date_or_null($year, $second, $first);
            }

            if ($second > 12 && $first <= 12) {
                return valid_date_or_null($year, $first, $second);
            }

            return valid_date_or_null($year, $second, $first)
                ?? valid_date_or_null($year, $first, $second);
        }
    }

    if (!is_numeric($value)) {
        return null;
    }

    $serial = (int) floor((float) $value);
    if ($serial <= 0) {
        return null;
    }

    $timestamp = ($serial - 25569) * 86400;
    return gmdate('Y-m-d', $timestamp);
}

function valid_date_or_null(int $year, int $month, int $day): ?string
{
    if (!checkdate($month, $day, $year)) {
        return null;
    }

    return sprintf('%04d-%02d-%02d', $year, $month, $day);
}
