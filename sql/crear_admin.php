<?php

require_once __DIR__ . '/../config/db.php';

if ($argc < 5) {
    echo "Uso: php crear_admin.php <usuario> <password> <nombre> <apellido_paterno> [apellido_materno]\n";
    exit(1);
}

[$_, $usuario, $password, $nombre, $apPat] = $argv;
$apMat = $argv[5] ?? '';

$pdo = getPDO();
$chk = $pdo->prepare('SELECT 1 FROM usuarios WHERE usuario = :u');
$chk->execute(['u' => $usuario]);
if ($chk->fetch()) {
    echo "Ya existe un usuario con esa clave de acceso.\n";
    exit(1);
}

$stmt = $pdo->prepare('
    INSERT INTO usuarios (usuario, password_hash, rol, nombre, apellido_paterno, apellido_materno, primer_ingreso)
    VALUES (:usuario, :hash, \'admin\', :nombre, :apPat, :apMat, FALSE)
');
$stmt->execute([
    'usuario' => $usuario,
    'hash' => password_hash($password, PASSWORD_DEFAULT),
    'nombre' => $nombre, 'apPat' => $apPat, 'apMat' => $apMat,
]);

echo "Cuenta de administrador creada correctamente. Ya puedes iniciar sesión en el Portal Administrador.\n";
