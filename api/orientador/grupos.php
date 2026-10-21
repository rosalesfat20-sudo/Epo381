<?php
require_once __DIR__ . '/../../includes/auth.php';
$u = requireLogin('orientador');
$pdo = getPDO();

$stmt = $pdo->prepare('
    SELECT g.id AS grupo_id, g.nombre, g.grado, g.turno, g.ciclo_escolar
    FROM orientador_grupos og
    JOIN grupos g ON g.id = og.grupo_id
    WHERE og.orientador_id = :id
    ORDER BY g.nombre
');
$stmt->execute(['id' => $u['id']]);
jsonOk(['grupos' => $stmt->fetchAll()]);
