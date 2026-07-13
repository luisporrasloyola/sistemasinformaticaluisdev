<?php
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_role('Administrador');

verify_csrf($_POST['csrf_token'] ?? null);

$id = (int) ($_POST['id'] ?? 0);
$name = trim((string) ($_POST['name'] ?? ''));
$latitude = (float) ($_POST['latitude'] ?? 0);
$longitude = (float) ($_POST['longitude'] ?? 0);
$address = trim((string) ($_POST['address'] ?? ''));
$radius = (int) ($_POST['radius_meters'] ?? 100);

if ($name === '' || $latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180 || $radius < 50 || $radius > 1000) {
    json_response(['ok' => false, 'message' => 'Complete los datos del punto de marcacion.'], 400);
}

try {
    if ($id > 0) {
        $stmt = db()->prepare('UPDATE attendance_locations
            SET name = :name, latitude = :latitude, longitude = :longitude, address = :address, radius_meters = :radius_meters
            WHERE id = :id');
        $stmt->execute([
            'name' => $name,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'address' => $address ?: null,
            'radius_meters' => $radius,
            'id' => $id,
        ]);
    } else {
        $stmt = db()->prepare('INSERT INTO attendance_locations (name, latitude, longitude, address, radius_meters, status)
            VALUES (:name, :latitude, :longitude, :address, :radius_meters, 1)
            ON DUPLICATE KEY UPDATE status = 1, id = LAST_INSERT_ID(id), latitude = VALUES(latitude), longitude = VALUES(longitude), address = VALUES(address), radius_meters = VALUES(radius_meters)');
        $stmt->execute([
            'name' => $name,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'address' => $address ?: null,
            'radius_meters' => $radius,
        ]);
        $id = (int) db()->lastInsertId();
    }

    json_response(['ok' => true, 'id' => $id]);
} catch (PDOException $e) {
    if ($e->getCode() === '23000') {
        json_response(['ok' => false, 'message' => 'Ya existe un punto de marcacion con ese nombre.'], 409);
    }
    json_response(['ok' => false, 'message' => 'No se pudo guardar el punto de marcacion.'], 400);
}
