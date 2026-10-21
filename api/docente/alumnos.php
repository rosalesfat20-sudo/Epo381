<?php
require_once __DIR__ . '/../../includes/auth.php';
$u = requireLogin('docente');
$pdo = getPDO();

$grupoId = isset($_GET['grupo_id']) ? (int)$_GET['grupo_id'] : null;
if (!$grupoId) jsonError('Falta grupo_id.');

$chk = $pdo->prepare('SELECT 1 FROM docente_asignaciones WHERE docente_id=:d AND grupo_id=:g');
$chk->execute(['d' => $u['id'], 'g' => $grupoId]);
if (!$chk->fetch()) jsonError('No impartes materia en ese grupo.', 403);

$stmt = $pdo->prepare('
    SELECT us.id AS alumno_id, al.matricula,
           (us.nombre || \' \' || us.apellido_paterno || \' \' || us.apellido_materno) AS nombre
    FROM alumnos al JOIN usuarios us ON us.id = al.usuario_id
    WHERE al.grupo_id = :g AND al.estatus = \'activo\'
    ORDER BY us.apellido_paterno, us.apellido_materno, us.nombre
');
$stmt->execute(['g' => $grupoId]);
jsonOk(['alumnos' => $stmt->fetchAll()]);
