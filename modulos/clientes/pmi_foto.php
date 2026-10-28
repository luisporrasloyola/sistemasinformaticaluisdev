<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../includes/upload.php';
require_once __DIR__ . '/../../config/database.php';
require_module_access('clientes.pmi');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['ok' => false, 'message' => 'Método no permitido.'], 405);
verify_csrf($_POST['csrf_token'] ?? null);
$clientId = filter_var($_POST['client_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$clientId) json_response(['ok' => false, 'message' => 'Seleccione un cliente válido.'], 422);

$newPhoto = null;
$pdo = db();
try {
    $uploaded = upload_file($_FILES['photo'] ?? [], 'clientes', ['image/jpeg', 'image/png', 'image/webp']);
    $newPhoto = $uploaded['path'];
    if (!$newPhoto) throw new DomainException('Seleccione una imagen JPG, PNG o WebP.');
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('SELECT photo_path FROM clients WHERE id = ? FOR UPDATE');
    $stmt->execute([$clientId]);
    $client = $stmt->fetch();
    if (!$client) throw new DomainException('El cliente ya no existe.');
    $pdo->prepare('UPDATE clients SET photo_path = ? WHERE id = ?')->execute([$newPhoto, $clientId]);
    $pdo->commit();
    if (!empty($client['photo_path'])) {
        try { delete_uploaded_file((string) $client['photo_path']); } catch (Throwable $ignored) { /* La foto nueva ya está guardada. */ }
    }
    json_response(['ok' => true, 'path' => $newPhoto]);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($newPhoto) delete_uploaded_file($newPhoto);
    json_response(['ok' => false, 'message' => $error instanceof DomainException || $error instanceof RuntimeException ? $error->getMessage() : 'No se pudo cambiar la foto.'], 400);
}
