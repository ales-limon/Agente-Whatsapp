<?php
require_once __DIR__ . '/src/auth.php';
cerrar_sesion();
header('Location: login.php');
exit;
