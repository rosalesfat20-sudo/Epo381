<?php
require_once __DIR__ . '/../../includes/auth.php';
$u = requireLogin('admin');
$pdo = getPDO();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Método no permitido.', 405);

$in = jsonInput();
$usuarioId = (int)($in['usuario_id'] ?? 0);
$nueva     = trim($in['nueva_password'] ?? '');

if (!$usuarioId || $nueva === '') jsonError('Faltan usuario_id y/o nueva_password.');
if (strlen($nueva) < 4) jsonError('La contraseña debe tener al menos 4 caracteres.');

$chk = $pdo->prepare('SELECT usuario, rol FROM usuarios WHERE id = :id');
$chk->execute(['id' => $usuarioId]);
$target = $chk->fetch();
if (!$target) jsonError('Usuario no encontrado.', 404);

$pdo->prepare('UPDATE usuarios SET password_hash = :h WHERE id = :id')
    ->execute(['h' => password_hash($nueva, PASSWORD_DEFAULT), 'id' => $usuarioId]);

jsonOk(['mensaje' => "Contraseña restablecida para {$target['usuario']}."]);
