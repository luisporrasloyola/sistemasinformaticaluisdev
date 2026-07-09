<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../config/database.php';
require_login();

verify_csrf($_POST['csrf_token'] ?? null);

$id = (int) ($_POST['id'] ?? 0);
$name = trim((string) ($_POST['name'] ?? ''));
$email = trim((string) ($_POST['email'] ?? ''));
$role = trim((string) ($_POST['role'] ?? ''));
$password = (string) ($_POST['password'] ?? '');
$allowedRoles = ['Administrador'];

if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !in_array($role, $allowedRoles, true)) {
    json_response(['ok' => false, 'message' => 'Complete los campos obligatorios.'], 400);
}

if ($id === 0 && strlen($password) < 8) {
    json_response(['ok' => false, 'message' => 'La contraseña debe tener al menos 8 caracteres.'], 400);
}

if ($id > 0 && $password !== '' && strlen($password) < 8) {
    json_response(['ok' => false, 'message' => 'La contraseña debe tener al menos 8 caracteres.'], 400);
}

try {
    if ($id > 0) {
        $sql = 'UPDATE users SET name = :name, email = :email, role = :role';
        $params = ['name' => $name, 'email' => $email, 'role' => $role, 'id' => $id];
        if ($password !== '') {
            $sql .= ', password = :password';
            $params['password'] = password_hash($password, PASSWORD_DEFAULT);
        }
        $sql .= ' WHERE id = :id';
        db()->prepare($sql)->execute($params);
    } else {
        $stmt = db()->prepare('INSERT INTO users (name, email, role, password, status) VALUES (:name, :email, :role, :password, 1)');
        $stmt->execute([
            'name' => $name,
            'email' => $email,
            'role' => $role,
            'password' => password_hash($password, PASSWORD_DEFAULT),
        ]);
    }

    json_response(['ok' => true]);
} catch (PDOException $e) {
    if ($e->getCode() === '23000') {
        json_response(['ok' => false, 'message' => 'El correo electrónico ya existe.'], 409);
    }
    json_response(['ok' => false, 'message' => 'No se pudo guardar el usuario.'], 400);
}
