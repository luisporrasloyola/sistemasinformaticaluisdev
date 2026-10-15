<?php
declare(strict_types=1);

require_once __DIR__ . '/security.php';

function upload_error_message(int $error): string
{
    return match ($error) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'El archivo supera el límite permitido por el servidor. Reduzca su tamaño o aumente upload_max_filesize y post_max_size en cPanel.',
        UPLOAD_ERR_PARTIAL => 'El archivo se subio incompleto. Intente nuevamente.',
        UPLOAD_ERR_NO_TMP_DIR => 'El servidor no tiene carpeta temporal configurada para subidas.',
        UPLOAD_ERR_CANT_WRITE => 'El servidor no pudo escribir el archivo temporal. Revise permisos o espacio disponible.',
        UPLOAD_ERR_EXTENSION => 'Una extension de PHP bloqueo la subida del archivo.',
        default => 'No se pudo subir el archivo.',
    };
}

function upload_file(array $file, string $folder, array $allowedMime, ?array $allowedExtensions = null): array
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

    if ($allowedExtensions !== null) {
        $originalExtension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (!isset($allowedExtensions[$originalExtension]) || !in_array($mime, $allowedExtensions[$originalExtension], true)) {
            throw new RuntimeException('La extensión del archivo no corresponde a su tipo.');
        }
        if (in_array($originalExtension, ['docx', 'xlsx', 'pptx'], true) && in_array($mime, ['application/zip', 'application/x-zip-compressed'], true)) {
            if (!class_exists('ZipArchive')) throw new RuntimeException('El servidor no puede verificar este archivo de Office.');
            $zip = new ZipArchive();
            if ($zip->open($file['tmp_name']) !== true) throw new RuntimeException('El archivo de Office no es válido.');
            $officeFolder = ['docx' => 'word/', 'xlsx' => 'xl/', 'pptx' => 'ppt/'][$originalExtension];
            $validOfficeFile = $zip->locateName('[Content_Types].xml') !== false;
            for ($i = 0; $validOfficeFile && $i < $zip->numFiles; $i++) {
                if (str_starts_with((string) $zip->getNameIndex($i), $officeFolder)) break;
            }
            $validOfficeFile = $validOfficeFile && $i < $zip->numFiles;
            $zip->close();
            if (!$validOfficeFile) throw new RuntimeException('El archivo de Office no corresponde a su extensión.');
        }
    }

    $extension = $allowedExtensions !== null ? $originalExtension : match ($mime) {
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
        'audio/mpeg' => 'mp3',
        'audio/mp4' => 'm4a',
        'audio/ogg' => 'ogg',
        'audio/wav', 'audio/x-wav', 'audio/wave' => 'wav',
        'audio/webm' => 'webm',
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

function document_attachment_mimes(): array
{
    return ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];
}

function format_attachment_extensions(): array
{
    $zip = ['application/zip', 'application/x-zip-compressed'];
    $ole = ['application/x-ole-storage', 'application/vnd.ms-office', 'application/CDFV2'];
    return [
        'pdf' => ['application/pdf'],
        'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'],
        'png' => ['image/png'], 'webp' => ['image/webp'],
        'doc' => array_merge(['application/msword'], $ole),
        'xls' => array_merge(['application/vnd.ms-excel'], $ole),
        'ppt' => array_merge(['application/vnd.ms-powerpoint'], $ole),
        'docx' => array_merge(['application/vnd.openxmlformats-officedocument.wordprocessingml.document'], $zip),
        'xlsx' => array_merge(['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'], $zip),
        'pptx' => array_merge(['application/vnd.openxmlformats-officedocument.presentationml.presentation'], $zip),
    ];
}

function format_attachment_mimes(): array
{
    return array_values(array_unique(array_merge(...array_values(format_attachment_extensions()))));
}

function audio_attachment_mimes(): array
{
    return ['audio/mpeg', 'audio/mp4', 'audio/ogg', 'audio/wav', 'audio/x-wav', 'audio/wave', 'audio/webm'];
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


