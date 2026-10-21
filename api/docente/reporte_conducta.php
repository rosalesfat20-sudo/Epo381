<?php
require_once __DIR__ . '/../../includes/auth.php';
$u = requireLogin('docente');
$pdo = getPDO();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $stmt = $pdo->prepare('
        SELECT rc.*, (us.nombre || \' \' || us.apellido_paterno || \' \' || us.apellido_materno) AS alumno_nombre,
               g.nombre AS grupo_nombre
        FROM reportes_conducta rc
        JOIN usuarios us ON us.id = rc.alumno_id
        LEFT JOIN alumnos al ON al.usuario_id = us.id
        LEFT JOIN grupos g ON g.id = al.grupo_id
        WHERE rc.autor_id = :id
        ORDER BY rc.created_at DESC
    ');
    $stmt->execute(['id' => $u['id']]);
    jsonOk(['reportes' => $stmt->fetchAll()]);
}

if ($method === 'POST') {
    $in = jsonInput();
    $alumnoId   = (int)($in['alumno_id']   ?? 0);
    $motivo     = trim($in['motivo']       ?? '');
    $descripcion= trim($in['descripcion']  ?? '');
    $medida     = trim($in['medida']       ?? '');
    $fecha      = trim($in['fecha']        ?? date('Y-m-d'));
    $materiaId  = isset($in['materia_id']) && $in['materia_id'] !== '' ? (int)$in['materia_id'] : null;

    if (!$alumnoId || $motivo === '' || $descripcion === '') {
        jsonError('Alumno, motivo y descripción son obligatorios.');
    }

    // Verificar que el alumno pertenece a un grupo donde el docente imparte
    $chk = $pdo->prepare('
        SELECT 1 FROM alumnos al
        JOIN docente_asignaciones da ON da.grupo_id = al.grupo_id
        WHERE al.usuario_id = :a AND da.docente_id = :d
    ');
    $chk->execute(['a' => $alumnoId, 'd' => $u['id']]);
    if (!$chk->fetch()) jsonError('El alumno no pertenece a un grupo que impartas.', 403);

    $ins = $pdo->prepare('
        INSERT INTO reportes_conducta (alumno_id, autor_id, autor_rol, autor_nombre_hist, materia_id, fecha, motivo, descripcion, medida)
        VALUES (:alumno_id, :autor_id, \'docente\', :autor_nombre, :materia_id, :fecha, :motivo, :descripcion, :medida)
    ');
    $ins->execute([
        'alumno_id' => $alumnoId, 'autor_id' => $u['id'], 'autor_nombre' => nombreCompleto($u), 'materia_id' => $materiaId,
        'fecha' => $fecha, 'motivo' => $motivo, 'descripcion' => $descripcion, 'medida' => $medida,
    ]);
    jsonOk(['mensaje' => 'Reporte de conducta creado correctamente.'], 201);
}

jsonError('Método no permitido.', 405);
