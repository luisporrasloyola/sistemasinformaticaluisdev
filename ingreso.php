<?php
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/config/database.php';

if (current_user()) {
    redirect('panel.php');
}

$error = '';
$alertClass = 'alert-danger';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_is_valid($_POST['csrf_token'] ?? null)) {
        refresh_csrf_token();
        $alertClass = 'alert-warning';
        $error = "La sesi\u{00F3}n del formulario venci\u{00F3} por seguridad. Vuelva a ingresar sus credenciales e int\u{00E9}ntelo nuevamente.";
    } else {
        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
            $error = "Ingrese credenciales v\u{00E1}lidas.";
        } else {
            $stmt = db()->prepare('SELECT * FROM users WHERE email = :email AND status = 1 LIMIT 1');
            $stmt->execute(['email' => $email]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                session_regenerate_id(true);
                refresh_csrf_token();
                $_SESSION['user'] = [
                    'id' => (int) $user['id'],
                    'name' => $user['name'],
                    'email' => $user['email'],
                    'role' => $user['role'] ?? 'Administrador',
                    'worker_id' => !empty($user['worker_id']) ? (int) $user['worker_id'] : null,
                ];
                redirect(($user['role'] ?? 'Administrador') === 'Personal' ? 'modulos/control_personal/control_asistencia.php' : 'panel.php');
            }

            $error = "Correo o contrase\u{00F1}a incorrectos.";
        }
    }
}

require __DIR__ . '/includes/header.php';
?>
<div class="login-panel login-panel-centered">
    <form class="login-card needs-validation" method="post" novalidate>
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <div class="login-logo-wrap">
            <span class="login-logo"><i class="fa-solid fa-truck-fast"></i></span>
        </div>
        <div class="login-heading">
            <h1>Life Maquinarias</h1>
            <p>Gesti&oacute;n de Personal y Requisitos</p>
        </div>
        <?php if ($error): ?>
            <div class="alert <?= e($alertClass) ?>"><?= e($error) ?></div>
        <?php endif; ?>
        <div class="mb-3">
            <label class="form-label">Correo Electr&oacute;nico</label>
            <div class="input-group login-input-group">
                <span class="input-group-text"><i class="fa-regular fa-envelope"></i></span>
                <input class="form-control" type="email" name="email" required autofocus>
            </div>
            <div class="invalid-feedback">Ingrese un correo v&aacute;lido.</div>
        </div>
        <div class="mb-4">
            <label class="form-label">Contrase&ntilde;a</label>
            <div class="input-group login-input-group">
                <span class="input-group-text"><i class="fa-solid fa-lock"></i></span>
                <input class="form-control" type="password" name="password" required>
            </div>
            <div class="invalid-feedback">Ingrese su contrase&ntilde;a.</div>
        </div>
        <button class="btn btn-primary w-100" type="submit"><i class="fa-solid fa-right-to-bracket"></i>Ingresar al Panel</button>
        <div class="login-divider"></div>
    </form>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
