<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/upload.php';
require_once __DIR__ . '/../config/database.php';
require_login();

verify_csrf($_POST['csrf_token'] ?? null);

$id = (int) ($_POST['id'] ?? 0);
$companyInput = (string) ($_POST['company_id'] ?? '');
$equipo = trim((string) ($_POST['equipo'] ?? ''));
$seriePlaca = trim((string) ($_POST['serie_placa'] ?? ''));
$anioEquipo = (int) ($_POST['anio_equipo'] ?? 0);
$currentYear = (int) date('Y') + 1;

if ($companyInput === '' || $equipo === '' || $seriePlaca === '' || $anioEquipo < 1950 || $anioEquipo > $currentYear) {
    json_response(['ok' => false, 'message' => 'Complete todos los campos obligatorios.'], 400);
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
    $foto = upload_file($_FILES['foto'] ?? [], 'maquinarias', ['image/jpeg', 'image/png', 'image/webp']);

    if ($id > 0) {
        $stmt = db()->prepare('SELECT foto_path FROM maquinarias WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $current = $stmt->fetch();
        if (!$current) {
            json_response(['ok' => false, 'message' => 'No se encontro la maquinaria.'], 404);
        }

        $sql = 'UPDATE maquinarias SET company_id = :company_id, equipo = :equipo, serie_placa = :serie_placa, anio_equipo = :anio_equipo';
        $params = [
            'company_id' => $companyId,
            'equipo' => $equipo,
            'serie_placa' => $seriePlaca,
            'anio_equipo' => $anioEquipo,
            'id' => $id,
        ];
        if ($foto['path']) {
            delete_uploaded_file($current['foto_path'] ?? null);
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
    }

    json_response(['ok' => true]);
} catch (PDOException $e) {
    if ($e->getCode() === '23000') {
        json_response(['ok' => false, 'message' => 'La serie o placa ya existe.'], 409);
    }
    json_response(['ok' => false, 'message' => 'No se pudo guardar la maquinaria.'], 400);
} catch (Throwable $e) {
    json_response(['ok' => false, 'message' => $e->getMessage()], 400);
}
