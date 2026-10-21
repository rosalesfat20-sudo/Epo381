<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/reportes_lib.php';
$u = requireLogin('orientador');
$pdo = getPDO();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    $in = jsonInput();
    $alumnoId = (int)($in['alumno_id'] ?? 0);
    $materiaId = (int)($in['materia_id'] ?? 0);
    $grupoId = (int)($in['grupo_id'] ?? 0);
    $oportunidad = (int)($in['oportunidad'] ?? 0);
    $calificacion = $in['calificacion'] ?? null;
    $fecha = trim($in['fecha_regularizacion'] ?? '') ?: null;

    if (!$alumnoId || !$materiaId || !$grupoId) jsonError('Faltan datos del alumno, materia o grupo.');
    if (!in_array($oportunidad, [1, 2, 3], true)) jsonError('La oportunidad debe ser 1, 2 o 3.');
    if (!orientadorTieneGrupo($pdo, $u['id'], $grupoId)) jsonError('No tienes asignado ese grupo.', 403);

    if ($calificacion !== null && $calificacion !== '') {
        $calificacion = (float)$calificacion;
        if ($calificacion < 0 || $calificacion > 10) jsonError('La calificación debe estar entre 0 y 10.');
    } else {
        $calificacion = null;
    }

    $ciclo = cicloActualDeGrupo($pdo, $grupoId);
    $pdo->prepare('
        INSERT INTO extraordinarios (alumno_id, materia_id, grupo_id, ciclo_escolar, oportunidad, calificacion, fecha_regularizacion, orientador_id)
        VALUES (:a, :m, :g, :ciclo, :op, :cal, :fecha, :ori)
        ON CONFLICT (alumno_id, materia_id, ciclo_escolar, oportunidad)
        DO UPDATE SET calificacion = EXCLUDED.calificacion, fecha_regularizacion = EXCLUDED.fecha_regularizacion, orientador_id = EXCLUDED.orientador_id
    ')->execute([
        'a' => $alumnoId, 'm' => $materiaId, 'g' => $grupoId, 'ciclo' => $ciclo,
        'op' => $oportunidad, 'cal' => $calificacion, 'fecha' => $fecha, 'ori' => $u['id'],
    ]);

    jsonOk(['mensaje' => 'Regularización registrada correctamente.']);
}

if ($method === 'DELETE') {
    $in = jsonInput();
    $alumnoId = (int)($in['alumno_id'] ?? 0);
    $materiaId = (int)($in['materia_id'] ?? 0);
    $grupoId = (int)($in['grupo_id'] ?? 0);
    $oportunidad = (int)($in['oportunidad'] ?? 0);
    if (!$alumnoId || !$materiaId || !$grupoId || !$oportunidad) jsonError('Faltan datos.');
    if (!orientadorTieneGrupo($pdo, $u['id'], $grupoId)) jsonError('No tienes asignado ese grupo.', 403);

    $ciclo = cicloActualDeGrupo($pdo, $grupoId);
    $pdo->prepare('DELETE FROM extraordinarios WHERE alumno_id = :a AND materia_id = :m AND ciclo_escolar = :ciclo AND oportunidad = :op')
        ->execute(['a' => $alumnoId, 'm' => $materiaId, 'ciclo' => $ciclo, 'op' => $oportunidad]);

    jsonOk(['mensaje' => 'Registro de regularización eliminado.']);
}

jsonError('Método no permitido.', 405);
