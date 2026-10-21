<?php
require_once __DIR__ . '/../../includes/auth.php';
$u = requireLogin('orientador');
$pdo = getPDO();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $grupoId = isset($_GET['grupo_id']) ? (int)$_GET['grupo_id'] : null;
    if (!$grupoId) jsonError('Falta grupo_id.');
    if (!orientadorTieneGrupo($pdo, $u['id'], $grupoId)) jsonError('No tienes asignado ese grupo.', 403);

    $g = $pdo->prepare('SELECT ciclo_escolar FROM grupos WHERE id = :g');
    $g->execute(['g' => $grupoId]);
    $cicloActual = $g->fetchColumn();

    $stmt = $pdo->prepare('
        SELECT us.id AS alumno_id, al.matricula,
               (us.nombre || \' \' || us.apellido_paterno || \' \' || us.apellido_materno) AS nombre,
               c.materia_id, c.periodo_id, c.calificacion, c.faltas
        FROM alumnos al
        JOIN usuarios us ON us.id = al.usuario_id
        LEFT JOIN calificaciones c ON c.alumno_id = us.id AND c.grupo_id = al.grupo_id AND c.ciclo_escolar = :ciclo
        WHERE al.grupo_id = :g AND al.estatus = \'activo\'
        ORDER BY us.apellido_paterno, us.apellido_materno, us.nombre
    ');
    $stmt->execute(['g' => $grupoId, 'ciclo' => $cicloActual]);
    $rows = $stmt->fetchAll();

    $alumnos = [];
    foreach ($rows as $r) {
        $id = $r['alumno_id'];
        if (!isset($alumnos[$id])) {
            $alumnos[$id] = ['alumno_id' => $id, 'matricula' => $r['matricula'], 'nombre' => $r['nombre'], 'calificaciones' => []];
        }
        if ($r['materia_id']) {
            $alumnos[$id]['calificaciones'][] = [
                'materia_id' => $r['materia_id'], 'periodo_id' => $r['periodo_id'],
                'calificacion' => (float)$r['calificacion'], 'faltas' => (int)$r['faltas'],
            ];
        }
    }
    jsonOk(['alumnos' => array_values($alumnos)]);
}

if ($method === 'POST') {
    $in = jsonInput();
    $grupoId = (int)($in['grupo_id'] ?? 0);
    $capturas = $in['capturas'] ?? []; // [{alumno_id, materia_id, periodo_id, calificacion, faltas}]

    if (!$grupoId || !is_array($capturas) || empty($capturas)) {
        jsonError('Faltan grupo_id y/o capturas.');
    }
    if (!orientadorTieneGrupo($pdo, $u['id'], $grupoId)) jsonError('No tienes asignado ese grupo.', 403);

    // Fotografía del ciclo/grado/semestre del grupo EN ESTE MOMENTO: se guarda
    // junto con cada calificación para que no cambie si el grupo se reutiliza
    // en un ciclo futuro (ver includes/reportes_lib.php y migracion_v4.sql).
    $g = $pdo->prepare('SELECT ciclo_escolar, grado, semestre FROM grupos WHERE id = :g');
    $g->execute(['g' => $grupoId]);
    $grupoInfo = $g->fetch();
    if (!$grupoInfo) jsonError('Grupo no encontrado.', 404);

    $upsert = $pdo->prepare('
        INSERT INTO calificaciones (alumno_id, materia_id, grupo_id, periodo_id, calificacion, faltas, ciclo_escolar, grado_captura, semestre_captura, orientador_id)
        VALUES (:alumno_id, :materia_id, :grupo_id, :periodo_id, :calificacion, :faltas, :ciclo, :grado, :semestre, :orientador_id)
        ON CONFLICT (alumno_id, materia_id, periodo_id, ciclo_escolar)
        DO UPDATE SET calificacion = EXCLUDED.calificacion, faltas = EXCLUDED.faltas,
                      orientador_id = EXCLUDED.orientador_id, fecha_registro = now()
    ');

    $pdo->beginTransaction();
    try {
        foreach ($capturas as $c) {
            $cal = $c['calificacion'] ?? null;
            $faltas = (int)($c['faltas'] ?? 0);
            if (($cal === '' || $cal === null) && $faltas === 0) continue; // nada que guardar en esta celda
            if ($cal === '' || $cal === null) {
                // Se registran solo faltas sin calificación todavía: no se puede,
                // la tabla exige una calificación numérica; se ignora la celda.
                continue;
            }
            $cal = (float)$cal;
            if ($cal != 10 && ($cal < 5.1 || $cal > 9.9)) {
                throw new Exception('La calificación debe estar entre 5.1 y 9.9, o ser 10.');
            }
            if ($faltas < 0 || $faltas > 99) throw new Exception('Las faltas deben estar entre 0 y 99.');
            $upsert->execute([
                'alumno_id'   => (int)$c['alumno_id'],
                'materia_id'  => (int)$c['materia_id'],
                'grupo_id'    => $grupoId,
                'periodo_id'  => (int)$c['periodo_id'],
                'calificacion'=> $cal,
                'faltas'      => $faltas,
                'ciclo'       => $grupoInfo['ciclo_escolar'],
                'grado'       => $grupoInfo['grado'],
                'semestre'    => $grupoInfo['semestre'],
                'orientador_id'=> $u['id'],
            ]);
        }
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        jsonError('Error al guardar calificaciones: ' . $e->getMessage());
    }

    jsonOk(['mensaje' => 'Calificaciones guardadas correctamente.']);
}

jsonError('Método no permitido.', 405);
