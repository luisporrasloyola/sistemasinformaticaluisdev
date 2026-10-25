<?php
declare(strict_types=1);

function valorizacion_nombre_tarifa(string $equipo): string
{
    $equipo = trim($equipo);
    // La placa o serie identifica la unidad, pero no forma parte del tipo de equipo tarifado.
    return trim((string) preg_replace('/\s+[A-Z0-9]{1,8}[-\s][A-Z0-9-]{2,}\s*$/iu', '', $equipo));
}

function valorizacion_clave_tarifa(string $equipo): string
{
    $nombre = mb_strtolower(valorizacion_nombre_tarifa($equipo), 'UTF-8');
    return strtr($nombre, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u']);
}
