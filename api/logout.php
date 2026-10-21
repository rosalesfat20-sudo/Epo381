<?php
require_once __DIR__ . '/../includes/auth.php';
$_SESSION = [];
session_destroy();
jsonOk(['mensaje' => 'Sesión cerrada.']);
