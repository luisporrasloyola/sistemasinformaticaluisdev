<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/permissions.php';
require_role('Administrador');

verify_csrf($_POST['csrf_token'] ?? null);

$id = (int) ($_POST['id'] ?? 0);
$name = trim((string) ($_POST['name'] ?? ''));
$email = trim((string) ($_POST['email'] ?? ''));
$role = trim((string) ($_POST['role'] ?? ''));
$workerId = (int) ($_POST['worker_id'] ?? 0);
$password = (string) ($_POST['password'] ?? '');
$allowedRoles = ['Administrador', 'Gestor', 'Personal'];

if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !in_array($role, $allowedRoles, true)) {
    json_response(['ok' => false, 'message' => 'Complete los campos obligatorios.'], 400);
}

if ($role === 'Personal') {
    if ($workerId <= 0) {
        json_response(['ok' => false, 'message' => 'Seleccione el trabajador vinculado para el rol Personal.'], 400);
    }

    $workerStmt = db()->prepare('SELECT id FROM workers WHERE id = :id LIMIT 1');
    $workerStmt->execute(['id' => $workerId]);
    if (!$workerStmt->fetch()) {
        json_response(['ok' => false, 'message' => 'El trabajador seleccionado no existe.'], 400);
    }

    $duplicateWorkerStmt = db()->prepare('SELECT id FROM users WHERE worker_id = :worker_id AND id <> :id LIMIT 1');
    $duplicateWorkerStmt->execute(['worker_id' => $workerId, 'id' => $id]);
    if ($duplicateWorkerStmt->fetch()) {
        json_response(['ok' => false, 'message' => 'Este trabajador ya tiene un usuario vinculado.'], 409);
    }
} else {
    $workerId = 0;
}

if ($id === 0 && strlen($password) < 8) {
    json_response(['ok' => false, 'message' => 'La contrasena debe tener al menos 8 caracteres.'], 400);
}

if ($id > 0 && $password !== '' && strlen($password) < 8) {
    json_response(['ok' => false, 'message' => 'La contrasena debe tener al menos 8 caracteres.'], 400);
}

try {
    db()->beginTransaction();
    if ($id > 0) {
        $sql = 'UPDATE users SET name = :name, email = :email, role = :role, worker_id = :worker_id';
        $params = [
            'name' => $name,
            'email' => $email,
            'role' => $role,
            'worker_id' => $workerId ?: null,
            'id' => $id,
        ];
        if ($password !== '') {
            $sql .= ', password = :password';
            $params['password'] = password_hash($password, PASSWORD_DEFAULT);
        }
        $sql .= ' WHERE id = :id';
        db()->prepare($sql)->execute($params);
        $userId = $id;
    } else {
        $stmt = db()->prepare('INSERT INTO users (name, email, role, worker_id, password, status)
            VALUES (:name, :email, :role, :worker_id, :password, 1)');
        $stmt->execute([
            'name' => $name,
            'email' => $email,
            'role' => $role,
            'worker_id' => $workerId ?: null,
            'password' => password_hash($password, PASSWORD_DEFAULT),
        ]);
        $userId = (int) db()->lastInsertId();
    }

    if ($role === 'Personal' && $workerId > 0) {
        $syncWorkerEmail = db()->prepare('UPDATE workers SET email = :email WHERE id = :worker_id');
        $syncWorkerEmail->execute([
            'email' => $email,
            'worker_id' => $workerId,
        ]);
    }

    save_user_permissions($userId, $role, $_POST);
    db()->commit();
    json_response(['ok' => true]);
} catch (PDOException $e) {
    if (db()->inTransaction()) {
        db()->rollBack();
    }
    if ($e->getCode() === '23000') {
        json_response(['ok' => false, 'message' => 'El correo electronico o trabajador vinculado ya existe.'], 409);
    }
    json_response(['ok' => false, 'message' => 'No se pudo guardar el usuario.'], 400);
} catch (Throwable $e) {
    if (db()->inTransaction()) {
        db()->rollBack();
    }
    json_response(['ok' => false, 'message' => 'No se pudo guardar los permisos del usuario.'], 400);
}
