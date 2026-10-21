<?php
require_once __DIR__ . '/../includes/auth.php';

$in = jsonInput();
$usuario  = trim($in['usuario']  ?? '');
$password = trim($in['password'] ?? '');
$rol      = trim($in['rol']      ?? '');

if ($usuario === '' || $password === '') {
    jsonError('Usuario y contraseña son obligatorios.');
}

$pdo = getPDO();
// El rol es opcional: 'usuario' es único, así que el rol sale de la propia cuenta.
// Si viene un rol (desde el menú Comunidad), se exige que coincida.
if ($rol !== '') {
    $stmt = $pdo->prepare('SELECT * FROM usuarios WHERE usuario = :usuario AND rol = :rol LIMIT 1');
    $stmt->execute(['usuario' => $usuario, 'rol' => $rol]);
} else {
    $stmt = $pdo->prepare('SELECT * FROM usuarios WHERE usuario = :usuario LIMIT 1');
    $stmt->execute(['usuario' => $usuario]);
}
$user = $stmt->fetch();

if (!$user || !password_verify($password, $user['password_hash'])) {
    jsonError('Usuario o contraseña incorrectos.', 401);
}
if (!$user['activo']) {
    jsonError('Esta cuenta está dada de baja / inactiva. Contacta al administrador.', 403);
}

session_regenerate_id(true);
$_SESSION['user'] = [
    'id'               => $user['id'],
    'usuario'          => $user['usuario'],
    'rol'              => $user['rol'],
    'nombre'           => $user['nombre'],
    'apellido_paterno' => $user['apellido_paterno'],
    'apellido_materno' => $user['apellido_materno'],
    'primer_ingreso'   => $user['primer_ingreso'],
];

$portal = [
    'alumno'      => 'portal-alumno.html',
    'docente'     => 'portal-docente.html',
    'orientador'  => 'portal-orientador.html',
    'admin'       => 'portal-admin.html',
][$user['rol']] ?? 'index.html';

jsonOk([
    'user'   => $_SESSION['user'],
    'portal' => $portal,
]);
