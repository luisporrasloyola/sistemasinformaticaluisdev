<?php
declare(strict_types=1);

require_once __DIR__ . '/security.php';

function upload_error_message(int $error): string
{
    return match ($error) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'El archivo supera el limite permitido por el servidor. Reduzca el PDF o aumente upload_max_filesize y post_max_size en cPanel.',
        UPLOAD_ERR_PARTIAL => 'El archivo se subio incompleto. Intente nuevamente.',
        UPLOAD_ERR_NO_TMP_DIR => 'El servidor no tiene carpeta temporal configurada para subidas.',
        UPLOAD_ERR_CANT_WRITE => 'El servidor no pudo escribir el archivo temporal. Revise permisos o espacio disponible.',
        UPLOAD_ERR_EXTENSION => 'Una extension de PHP bloqueo la subida del archivo.',
        default => 'No se pudo subir el archivo.',
    };
}

function upload_file(array $file, string $folder, array $allowedMime): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['path' => null, 'name' => null];
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException(upload_error_message((int) $file['error']));
    }

    if ($file['size'] > MAX_UPLOAD_SIZE) {
        throw new RuntimeException('El archivo supera el tamaño máximo permitido.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);

    if (!in_array($mime, $allowedMime, true)) {
        throw new RuntimeException('Tipo de archivo no permitido.');
    }

    $extension = match ($mime) {
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
        default => 'bin',
    };

    $dir = UPLOAD_PATH . DIRECTORY_SEPARATOR . $folder;
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $safeName = bin2hex(random_bytes(16)) . '.' . $extension;
    $target = $dir . DIRECTORY_SEPARATOR . $safeName;

    if (!move_uploaded_file($file['tmp_name'], $target)) {
        throw new RuntimeException('No se pudo guardar el archivo.');
    }

    return [
        'path' => 'archivos/' . $folder . '/' . $safeName,
        'name' => preg_replace('/[^A-Za-z0-9._ -]/', '_', (string) $file['name']),
    ];
}

function delete_uploaded_file(?string $relativePath): void
{
    if (!$relativePath) {
        return;
    }

    $fullPath = realpath(dirname(__DIR__) . DIRECTORY_SEPARATOR . $relativePath);
    $archivosRoot = realpath(UPLOAD_PATH);

    if ($fullPath && $archivosRoot && str_starts_with($fullPath, $archivosRoot) && is_file($fullPath)) {
        unlink($fullPath);
    }
}


