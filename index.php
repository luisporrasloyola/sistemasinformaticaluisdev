<?php
require_once __DIR__ . '/includes/security.php';
redirect(current_user() ? 'panel.php' : 'ingreso.php');

