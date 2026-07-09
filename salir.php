<?php
require_once __DIR__ . '/includes/security.php';
$_SESSION = [];
session_destroy();
redirect('ingreso.php');

