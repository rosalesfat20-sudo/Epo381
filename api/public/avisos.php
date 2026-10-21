<?php
require_once __DIR__ . '/../../includes/auth.php';
$pdo = getPDO();
$stmt = $pdo->query('SELECT id, titulo, contenido, imagen, created_at FROM avisos WHERE activo = TRUE ORDER BY orden, created_at DESC');
jsonOk(['avisos' => $stmt->fetchAll()]);
