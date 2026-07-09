<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../config/database.php';
require_login();

try {
    // Prevent any browser or proxy caching
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    header("Cache-Control: post-check=0, pre-check=0", false);
    header("Pragma: no-cache");
    header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");

    // Get incomplete workers (status = 0) with their positions count
    $stmt = db()->query('SELECT w.*,
        (SELECT COUNT(*) FROM worker_positions wp WHERE wp.worker_id = w.id) AS positions_count
        FROM workers w
        WHERE w.status = 0
        ORDER BY w.created_at DESC');
    $workers = $stmt->fetchAll();

    $notifications = [];
    foreach ($workers as $worker) {
        $missingFields = [];
        if (empty($worker['blood_type'])) {
            $missingFields[] = 'Grupo sanguíneo';
        }
        if (empty($worker['address'])) {
            $missingFields[] = 'Dirección';
        }
        if (empty($worker['phone'])) {
            $missingFields[] = 'Celular';
        }
        if (empty($worker['email'])) {
            $missingFields[] = 'Correo';
        }
        if (empty($worker['birth_date'])) {
            $missingFields[] = 'Fecha de nacimiento';
        }
        if (empty($worker['photo_path'])) {
            $missingFields[] = 'Foto';
        }
        if (empty($worker['signature_path'])) {
            $missingFields[] = 'Firma digital';
        }
        if ((int)$worker['positions_count'] === 0) {
            $missingFields[] = 'Puestos de trabajo';
        }
        if (empty($worker['company_id'])) {
            $missingFields[] = 'Empresa';
        }
        if (empty($worker['full_name'])) {
            $missingFields[] = 'Apellidos y Nombres';
        }
        if (empty($worker['document_type'])) {
            $missingFields[] = 'Tipo de documento';
        }
        if (empty($worker['document_number'])) {
            $missingFields[] = 'Nro. Documento';
        }

        // Even though status=0 implies incomplete, we ensure we only add if there are missing fields
        if (!empty($missingFields)) {
            $bodyText = "Nombre del personal: " . $worker['full_name'] . " (Falta: " . implode(', ', $missingFields) . ")";
            
            $notifications[] = [
                'id' => (int)$worker['id'],
                'full_name' => $worker['full_name'],
                'missing_fields' => implode(', ', $missingFields),
                'body' => $bodyText,
                'is_read' => 0,
                'created_at' => $worker['created_at'],
            ];
        }
    }

    json_response([
        'ok' => true,
        'unread_count' => count($notifications),
        'notifications' => $notifications,
    ]);
} catch (Exception $e) {
    json_response([
        'ok' => false,
        'message' => $e->getMessage(),
    ], 500);
}
