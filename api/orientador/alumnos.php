<?php
require_once __DIR__ . '/../../includes/auth.php';
$u = requireLogin('orientador');
$pdo = getPDO();

$grupoId  = isset($_GET['grupo_id'])  ? (int)$_GET['grupo_id']  : null;
$alumnoId = isset($_GET['alumno_id']) ? (int)$_GET['alumno_id'] : null;

// Ficha individual de un alumno (datos personales completos)
if ($alumnoId) {
    $stmt = $pdo->prepare('
        SELECT u.usuario, u.nombre, u.apellido_paterno, u.apellido_materno,
               a.*, g.nombre AS grupo_nombre, g.grado, g.turno
        FROM usuarios u
        JOIN alumnos a ON a.usuario_id = u.id
        LEFT JOIN grupos g ON g.id = a.grupo_id
        WHERE u.id = :id
    ');
    $stmt->execute(['id' => $alumnoId]);
    $ficha = $stmt->fetch();
    if (!$ficha) jsonError('Alumno no encontrado.', 404);
    if (!$ficha['grupo_id'] || !orientadorTieneGrupo($pdo, $u['id'], (int)$ficha['grupo_id'])) {
        jsonError('No tienes asignado el grupo de este alumno.', 403);
    }
    $ficha = decryptFields($ficha, camposPersonalesAlumno());
    jsonOk(['ficha' => $ficha]);
}

if (!$grupoId) jsonError('Falta grupo_id.');
if (!orientadorTieneGrupo($pdo, $u['id'], $grupoId)) jsonError('No tienes asignado ese grupo.', 403);

$stmt = $pdo->prepare('
    SELECT us.id AS alumno_id, al.matricula, al.estatus, al.perfil_completo,
           (us.nombre || \' \' || us.apellido_paterno || \' \' || us.apellido_materno) AS nombre
    FROM alumnos al JOIN usuarios us ON us.id = al.usuario_id
    WHERE al.grupo_id = :g
    ORDER BY al.estatus, us.apellido_paterno, us.apellido_materno, us.nombre
');
$stmt->execute(['g' => $grupoId]);
jsonOk(['alumnos' => $stmt->fetchAll()]);
