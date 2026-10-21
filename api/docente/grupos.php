<?php
require_once __DIR__ . '/../../includes/auth.php';
$u = requireLogin('docente');
$pdo = getPDO();

$stmt = $pdo->prepare('
    SELECT da.id AS asignacion_id, g.id AS grupo_id, g.nombre AS grupo_nombre, g.grado, g.turno,
           m.id AS materia_id, m.nombre AS materia_nombre
    FROM docente_asignaciones da
    JOIN grupos g ON g.id = da.grupo_id
    JOIN materias m ON m.id = da.materia_id
    WHERE da.docente_id = :id
    ORDER BY g.nombre, m.nombre
');
$stmt->execute(['id' => $u['id']]);
jsonOk(['asignaciones' => $stmt->fetchAll()]);
