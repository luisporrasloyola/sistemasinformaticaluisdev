<?php
declare(strict_types=1);
require_once __DIR__ . '/upload.php';

function pmi_requirement_file_columns(): array
{
    return [
        'primary' => ['file_path', 'original_file_name'],
        'word' => ['word_file_path', 'word_original_file_name'],
        'image' => ['image_file_path', 'image_original_file_name'],
    ];
}

function pmi_requirement_upload(string $key, string $kind, array &$newFiles): array
{
    $extensions = match ($kind) {
        'legacy' => array_intersect_key(format_attachment_extensions(), array_flip(['pdf', 'jpg', 'jpeg', 'png', 'webp'])),
        'pdf' => ['pdf' => ['application/pdf']],
        'word' => array_intersect_key(format_attachment_extensions(), array_flip(['doc', 'docx'])),
        'image' => array_intersect_key(format_attachment_extensions(), array_flip(['jpg', 'jpeg', 'png', 'webp'])),
        default => throw new InvalidArgumentException('Tipo de archivo inválido.'),
    };
    $file = upload_file($_FILES[$key] ?? [], 'requisitos', array_values(array_unique(array_merge(...array_values($extensions)))), $extensions);
    if ($file['path']) $newFiles[] = $file['path'];
    return $file;
}

function pmi_requirement_copy_file(?string $path, array &$newFiles): ?string
{
    if (!$path) return null;
    $root = realpath(UPLOAD_PATH);
    $source = realpath(dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path));
    if (!$root || !$source || !str_starts_with($source, $root . DIRECTORY_SEPARATOR) || !is_file($source)) {
        throw new RuntimeException('No se encontró el archivo original para replicar.');
    }
    $extension = pathinfo($source, PATHINFO_EXTENSION);
    $newPath = 'archivos/requisitos/' . bin2hex(random_bytes(16)) . ($extension !== '' ? '.' . $extension : '');
    $destination = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $newPath);
    if (!copy($source, $destination)) throw new RuntimeException('No se pudo copiar un archivo adjunto.');
    $newFiles[] = $newPath;
    return $newPath;
}

function pmi_requirement_delete_unreferenced(PDO $pdo, array $paths): void
{
    foreach (array_unique(array_filter($paths)) as $path) {
        try {
            $check = $pdo->prepare('SELECT 1 FROM worker_requirements WHERE file_path = ? OR word_file_path = ? OR image_file_path = ? LIMIT 1');
            $check->execute([$path, $path, $path]);
            if (!$check->fetchColumn()) delete_uploaded_file($path);
        } catch (Throwable $error) {
            error_log('No se pudo limpiar un adjunto PMI: ' . $error->getMessage());
        }
    }
}
