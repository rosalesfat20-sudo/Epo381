<?php
require_once __DIR__ . '/../../includes/auth.php';
$u = requireLogin('orientador');
$pdo = getPDO();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $grupoId  = isset($_GET['grupo_id'])  ? (int)$_GET['grupo_id']  : null;
    $alumnoId = isset($_GET['alumno_id']) ? (int)$_GET['alumno_id'] : null;
    if (!$grupoId) jsonError('Falta grupo_id.');
    if (!orientadorTieneGrupo($pdo, $u['id'], $grupoId)) jsonError('No tienes asignado ese grupo.', 403);

    $sql = '
        SELECT rc.*, COALESCE((au.nombre || \' \' || au.apellido_paterno), rc.autor_nombre_hist, \'Usuario eliminado\') AS autor_nombre,
               (us.nombre || \' \' || us.apellido_paterno || \' \' || us.apellido_materno) AS alumno_nombre
        FROM reportes_conducta rc
        JOIN usuarios us ON us.id = rc.alumno_id
        LEFT JOIN usuarios au ON au.id = rc.autor_id
        JOIN alumnos al ON al.usuario_id = us.id
        WHERE al.grupo_id = :g' . ($alumnoId ? ' AND rc.alumno_id = :a' : '') . '
        ORDER BY rc.created_at DESC
    ';
    $stmt = $pdo->prepare($sql);
    $params = ['g' => $grupoId];
    if ($alumnoId) $params['a'] = $alumnoId;
    $stmt->execute($params);
    jsonOk(['reportes' => $stmt->fetchAll()]);
}

if ($method === 'POST') {
    $in = jsonInput();
    $alumnoId   = (int)($in['alumno_id']   ?? 0);
    $motivo     = trim($in['motivo']       ?? '');
    $descripcion= trim($in['descripcion']  ?? '');
    $medida     = trim($in['medida']       ?? '');
    $fecha      = trim($in['fecha']        ?? date('Y-m-d'));

    if (!$alumnoId || $motivo === '' || $descripcion === '') {
        jsonError('Alumno, motivo y descripción son obligatorios.');
    }

    $chk = $pdo->prepare('SELECT grupo_id FROM alumnos WHERE usuario_id = :a');
    $chk->execute(['a' => $alumnoId]);
    $grupoAlumno = $chk->fetchColumn();
    if (!$grupoAlumno || !orientadorTieneGrupo($pdo, $u['id'], (int)$grupoAlumno)) {
        jsonError('El alumno no pertenece a un grupo que tengas asignado.', 403);
    }

    $ins = $pdo->prepare('
        INSERT INTO reportes_conducta (alumno_id, autor_id, autor_rol, autor_nombre_hist, fecha, motivo, descripcion, medida)
        VALUES (:alumno_id, :autor_id, \'orientador\', :autor_nombre, :fecha, :motivo, :descripcion, :medida)
    ');
    $ins->execute([
        'alumno_id' => $alumnoId, 'autor_id' => $u['id'], 'autor_nombre' => nombreCompleto($u),
        'fecha' => $fecha, 'motivo' => $motivo, 'descripcion' => $descripcion, 'medida' => $medida,
    ]);
    jsonOk(['mensaje' => 'Reporte de conducta creado correctamente.'], 201);
}

if ($method === 'PUT') {
    $in = jsonInput();
    $id = (int)($in['id'] ?? 0);
    $motivo     = trim($in['motivo']       ?? '');
    $descripcion= trim($in['descripcion']  ?? '');
    $medida     = trim($in['medida']       ?? '');
    $fecha      = trim($in['fecha']        ?? '');

    if (!$id || $motivo === '' || $descripcion === '') jsonError('Motivo y descripción son obligatorios.');

    $chk = $pdo->prepare('
        SELECT al.grupo_id FROM reportes_conducta rc
        JOIN alumnos al ON al.usuario_id = rc.alumno_id
        WHERE rc.id = :id
    ');
    $chk->execute(['id' => $id]);
    $grupoAlumno = $chk->fetchColumn();
    if (!$grupoAlumno || !orientadorTieneGrupo($pdo, $u['id'], (int)$grupoAlumno)) {
        jsonError('No tienes permiso para editar este reporte.', 403);
    }

    $pdo->prepare('UPDATE reportes_conducta SET motivo=:motivo, descripcion=:descripcion, medida=:medida, fecha=COALESCE(NULLIF(:fecha,\'\'), fecha) WHERE id=:id')
        ->execute(['motivo' => $motivo, 'descripcion' => $descripcion, 'medida' => $medida, 'fecha' => $fecha, 'id' => $id]);
    jsonOk(['mensaje' => 'Reporte de conducta actualizado correctamente.']);
}

if ($method === 'DELETE') {
    $in = jsonInput();
    $id = (int)($in['id'] ?? 0);
    if (!$id) jsonError('Falta id.');

    $chk = $pdo->prepare('
        SELECT al.grupo_id FROM reportes_conducta rc
        JOIN alumnos al ON al.usuario_id = rc.alumno_id
        WHERE rc.id = :id
    ');
    $chk->execute(['id' => $id]);
    $grupoAlumno = $chk->fetchColumn();
    if (!$grupoAlumno || !orientadorTieneGrupo($pdo, $u['id'], (int)$grupoAlumno)) {
        jsonError('No tienes permiso para eliminar este reporte.', 403);
    }

    $pdo->prepare('DELETE FROM reportes_conducta WHERE id = :id')->execute(['id' => $id]);
    jsonOk(['mensaje' => 'Reporte de conducta eliminado.']);
}

jsonError('Método no permitido.', 405);
