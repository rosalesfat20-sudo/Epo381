<?php
require_once __DIR__ . '/../../includes/auth.php';
$u = requireLogin('docente');
$pdo = getPDO();

$grupoId   = isset($_GET['grupo_id'])   ? (int)$_GET['grupo_id']   : null;
$materiaId = isset($_GET['materia_id']) ? (int)$_GET['materia_id'] : null;

if (!$grupoId || !$materiaId) jsonError('Faltan parámetros grupo_id y materia_id.');

// Verificar que el docente sí tiene asignada esa materia en ese grupo
$chk = $pdo->prepare('SELECT 1 FROM docente_asignaciones WHERE docente_id=:d AND grupo_id=:g AND materia_id=:m');
$chk->execute(['d' => $u['id'], 'g' => $grupoId, 'm' => $materiaId]);
if (!$chk->fetch()) jsonError('No tienes esa materia/grupo asignada.', 403);

$stmt = $pdo->prepare('
    SELECT us.id AS alumno_id, us.nombre, us.apellido_paterno, us.apellido_materno, al.matricula,
           p.orden, p.nombre AS periodo, c.calificacion
    FROM alumnos al
    JOIN usuarios us ON us.id = al.usuario_id
    LEFT JOIN calificaciones c ON c.alumno_id = us.id AND c.materia_id = :m
    LEFT JOIN periodos p ON p.id = c.periodo_id
    WHERE al.grupo_id = :g AND al.estatus = \'activo\'
    ORDER BY us.apellido_paterno, us.apellido_materno, us.nombre
');
$stmt->execute(['m' => $materiaId, 'g' => $grupoId]);
$rows = $stmt->fetchAll();

$alumnos = [];
foreach ($rows as $r) {
    $id = $r['alumno_id'];
    if (!isset($alumnos[$id])) {
        $alumnos[$id] = [
            'alumno_id' => $id,
            'matricula' => $r['matricula'],
            'nombre'    => trim($r['nombre'] . ' ' . $r['apellido_paterno'] . ' ' . $r['apellido_materno']),
            'parciales' => [],
        ];
    }
    if ($r['orden']) $alumnos[$id]['parciales'][$r['orden']] = (float)$r['calificacion'];
}
foreach ($alumnos as &$a) {
    $vals = array_values($a['parciales']);
    $a['promedio'] = $vals ? round(array_sum($vals) / count($vals), 1) : null;
}
unset($a);

jsonOk(['alumnos' => array_values($alumnos)]);
