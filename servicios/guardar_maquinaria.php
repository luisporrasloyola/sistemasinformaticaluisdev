<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/upload.php';
require_once __DIR__ . '/../config/database.php';
require_module_access('maquinaria.datos_generales');

verify_csrf($_POST['csrf_token'] ?? null);

$id = (int) ($_POST['id'] ?? 0);
$companyInput = (string) ($_POST['company_id'] ?? '');
$equipo = trim((string) ($_POST['equipo'] ?? ''));
$seriePlaca = trim((string) ($_POST['serie_placa'] ?? ''));
$anioEquipo = (int) ($_POST['anio_equipo'] ?? 0);
$currentYear = (int) date('Y') + 1;
$contactNames = $_POST['contact_name'] ?? [];
$contactPhones = $_POST['contact_phone'] ?? [];

if ($companyInput === '' || $equipo === '' || $seriePlaca === '' || $anioEquipo < 1950 || $anioEquipo > $currentYear) {
    json_response(['ok' => false, 'message' => 'Complete todos los campos obligatorios.'], 400);
}

if (!is_array($contactNames) || !is_array($contactPhones)
    || count($contactNames) > 100 || count($contactNames) !== count($contactPhones)) {
    json_response(['ok' => false, 'message' => 'Revise los datos de los contactos.'], 400);
}
$contacts = [];
foreach ($contactNames as $index => $name) {
    $contact = [
        'name' => trim((string) $name),
        'phone' => trim((string) ($contactPhones[$index] ?? '')),
        'position' => '',
        'email' => '',
    ];
    if ($contact['name'] === '' || mb_strlen($contact['name']) > 150
        || $contact['phone'] === '' || mb_strlen($contact['phone']) > 40) {
        json_response(['ok' => false, 'message' => 'Complete nombre y celular en cada contacto.'], 400);
    }
    $contacts[] = $contact;
}

function resolve_machine_company_id(string $input): int
{
    if (ctype_digit($input)) {
        $stmt = db()->prepare('SELECT id FROM companies WHERE id = :id AND status = 1');
        $stmt->execute(['id' => (int) $input]);
        if ($stmt->fetch()) {
            return (int) $input;
        }
        throw new RuntimeException('Seleccione una empresa valida.');
    }

    $name = trim(preg_replace('/\s+/', ' ', $input) ?? '');
    if ($name === '') {
        throw new RuntimeException('Seleccione una empresa valida.');
    }
    if (mb_strlen($name, 'UTF-8') > 160) {
        throw new RuntimeException('El nombre de la empresa es demasiado largo.');
    }

    $stmt = db()->prepare('INSERT INTO companies (name, status) VALUES (:name, 1) ON DUPLICATE KEY UPDATE status = 1, id = LAST_INSERT_ID(id)');
    $stmt->execute(['name' => $name]);
    return (int) db()->lastInsertId();
}

try {
    $companyId = resolve_machine_company_id($companyInput);
    if ($id > 0) {
        $stmt = db()->prepare('SELECT foto_path FROM maquinarias WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $current = $stmt->fetch();
        if (!$current) {
            json_response(['ok' => false, 'message' => 'No se encontró la maquinaria.'], 404);
        }
    }
    $foto = upload_file($_FILES['foto'] ?? [], 'maquinarias', ['image/jpeg', 'image/png', 'image/webp']);
    db()->beginTransaction();

    if ($id > 0) {
        $sql = 'UPDATE maquinarias SET company_id = :company_id, equipo = :equipo, serie_placa = :serie_placa, anio_equipo = :anio_equipo';
        $params = [
            'company_id' => $companyId,
            'equipo' => $equipo,
            'serie_placa' => $seriePlaca,
            'anio_equipo' => $anioEquipo,
            'id' => $id,
        ];
        if ($foto['path']) {
            $sql .= ', foto_path = :foto_path';
            $params['foto_path'] = $foto['path'];
        }
        $sql .= ' WHERE id = :id';
        db()->prepare($sql)->execute($params);
    } else {
        $stmt = db()->prepare('INSERT INTO maquinarias (company_id, equipo, serie_placa, anio_equipo, foto_path) VALUES (:company_id, :equipo, :serie_placa, :anio_equipo, :foto_path)');
        $stmt->execute([
            'company_id' => $companyId,
            'equipo' => $equipo,
            'serie_placa' => $seriePlaca,
            'anio_equipo' => $anioEquipo,
            'foto_path' => $foto['path'],
        ]);
        $id = (int) db()->lastInsertId();
    }

    db()->prepare('DELETE FROM maquinaria_contactos WHERE maquinaria_id = :id')->execute(['id' => $id]);
    $insertContact = db()->prepare('INSERT INTO maquinaria_contactos (maquinaria_id, name, position, phone, email, sort_order) VALUES (:maquinaria_id, :name, :position, :phone, :email, :sort_order)');
    foreach ($contacts as $index => $contact) {
        $insertContact->execute($contact + ['maquinaria_id' => $id, 'sort_order' => $index]);
    }
    db()->commit();
    if (!empty($foto['path']) && !empty($current['foto_path'])) {
        delete_uploaded_file($current['foto_path']);
    }

    json_response(['ok' => true]);
} catch (PDOException $e) {
    if (db()->inTransaction()) db()->rollBack();
    if (!empty($foto['path'])) delete_uploaded_file($foto['path']);
    if ($e->getCode() === '23000') {
        json_response(['ok' => false, 'message' => 'La serie o placa ya existe.'], 409);
    }
    json_response(['ok' => false, 'message' => 'No se pudo guardar la maquinaria.'], 400);
} catch (Throwable $e) {
    if (db()->inTransaction()) db()->rollBack();
    if (!empty($foto['path'])) delete_uploaded_file($foto['path']);
    json_response(['ok' => false, 'message' => $e->getMessage()], 400);
}
