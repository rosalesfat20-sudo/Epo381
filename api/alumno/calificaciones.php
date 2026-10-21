<?php
require_once __DIR__ . '/../../includes/auth.php';
$u = requireLogin('alumno');
$pdo = getPDO();

$stmt = $pdo->prepare('
    SELECT m.nombre AS materia, p.nombre AS periodo, p.orden, c.calificacion
    FROM calificaciones c
    JOIN materias m ON m.id = c.materia_id
    JOIN periodos p ON p.id = c.periodo_id
    WHERE c.alumno_id = :id
    ORDER BY m.nombre, p.orden
');
$stmt->execute(['id' => $u['id']]);
$filas = $stmt->fetchAll();

$materias = [];
foreach ($filas as $f) {
    $materias[$f['materia']]['parciales'][$f['orden']] = (float)$f['calificacion'];
}
foreach ($materias as $mat => &$d) {
    $vals = array_values($d['parciales']);
    $d['promedio'] = $vals ? round(array_sum($vals) / count($vals), 1) : null;
    $d['estatus']  = $d['promedio'] !== null ? ($d['promedio'] >= 6 ? 'Aprobado' : 'Reprobado') : 'Sin calificar';
}
unset($d);

jsonOk(['materias' => $materias]);
