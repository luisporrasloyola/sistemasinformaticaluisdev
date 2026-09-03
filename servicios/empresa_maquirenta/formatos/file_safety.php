<?php
declare(strict_types=1);

/**
 * Elimina un archivo del módulo Formatos solamente cuando el módulo original
 * de Requisitos ya no lo utiliza. Así una copia migrada nunca rompe el origen.
 */
function delete_empresa_maquirenta_formato_file(?string $path): void
{
    $path = trim((string) $path);
    if ($path === '') {
        return;
    }

    $stmt = db()->prepare(
        "SELECT
            (SELECT COUNT(*) FROM workers WHERE photo_path = :photo_path OR signature_path = :signature_path) +
            (SELECT COUNT(*) FROM worker_requirements WHERE file_path = :requirement_path)"
    );
    $stmt->execute([
        'photo_path' => $path,
        'signature_path' => $path,
        'requirement_path' => $path,
    ]);

    if ((int) $stmt->fetchColumn() === 0) {
        delete_uploaded_file($path);
    }
}