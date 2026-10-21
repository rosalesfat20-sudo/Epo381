<?php
require_once __DIR__ . '/../../includes/auth.php';
$u = requireLogin('admin');
$pdo = getPDO();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $tipo = $_GET['tipo'] ?? '';
    if ($tipo === 'docente') {
        $rows = $pdo->query('
            SELECT da.id, da.docente_id, (us.nombre || \' \' || us.apellido_paterno || \' \' || us.apellido_materno) AS docente_nombre,
                   da.materia_id, m.nombre AS materia_nombre, da.grupo_id, g.nombre AS grupo_nombre
            FROM docente_asignaciones da
            JOIN usuarios us ON us.id = da.docente_id
            JOIN materias m ON m.id = da.materia_id
            JOIN grupos g ON g.id = da.grupo_id
            ORDER BY us.apellido_paterno, g.nombre
        ')->fetchAll();
        jsonOk(['asignaciones' => $rows]);
    } elseif ($tipo === 'orientador') {
        $rows = $pdo->query('
            SELECT og.id, og.orientador_id, (us.nombre || \' \' || us.apellido_paterno || \' \' || us.apellido_materno) AS orientador_nombre,
                   og.grupo_id, g.nombre AS grupo_nombre
            FROM orientador_grupos og
            JOIN usuarios us ON us.id = og.orientador_id
            JOIN grupos g ON g.id = og.grupo_id
            ORDER BY us.apellido_paterno, g.nombre
        ')->fetchAll();
        jsonOk(['asignaciones' => $rows]);
    } else {
        jsonError('Parámetro tipo inválido (docente|orientador).');
    }
}

if ($method === 'POST') {
    $in = jsonInput();
    $tipo = $in['tipo'] ?? '';

    if ($tipo === 'docente') {
        $docenteId = (int)($in['docente_id'] ?? 0);
        $materiaId = (int)($in['materia_id'] ?? 0);
        $grupoId   = (int)($in['grupo_id']   ?? 0);
        if (!$docenteId || !$materiaId || !$grupoId) jsonError('docente_id, materia_id y grupo_id son obligatorios.');

        $chk = $pdo->prepare('SELECT m.semestre, g.grado, g.semestre AS sem_grupo FROM materias m, grupos g WHERE m.id = :m AND g.id = :g');
        $chk->execute(['m' => $materiaId, 'g' => $grupoId]);
        $info = $chk->fetch();
        if (!$info) jsonError('Materia o grupo no encontrados.', 404);
        $semestreGlobalGrupo = ((int)$info['grado'] - 1) * 2 + (int)$info['sem_grupo'];
        if ((int)$info['semestre'] !== $semestreGlobalGrupo) {
            jsonError('Esa materia pertenece a otro semestre (' . ($info['semestre'] ?? 'sin asignar') . '°) y el grupo está en su ' . $semestreGlobalGrupo . '° semestre. Revisa Administrador → Materias.');
        }

        try {
            $pdo->prepare('INSERT INTO docente_asignaciones (docente_id, materia_id, grupo_id) VALUES (:d,:m,:g)')
                ->execute(['d' => $docenteId, 'm' => $materiaId, 'g' => $grupoId]);
        } catch (Exception $e) {
            jsonError('Esa asignación ya existe o hay un error: ' . $e->getMessage());
        }
        jsonOk(['mensaje' => 'Materia asignada al docente correctamente.'], 201);
    } elseif ($tipo === 'orientador') {
        $orientadorId = (int)($in['orientador_id'] ?? 0);
        $grupoId      = (int)($in['grupo_id']      ?? 0);
        if (!$orientadorId || !$grupoId) jsonError('orientador_id y grupo_id son obligatorios.');
        try {
            $pdo->prepare('INSERT INTO orientador_grupos (orientador_id, grupo_id) VALUES (:o,:g)')
                ->execute(['o' => $orientadorId, 'g' => $grupoId]);
        } catch (Exception $e) {
            jsonError('Esa asignación ya existe o hay un error: ' . $e->getMessage());
        }
        jsonOk(['mensaje' => 'Grupo asignado al orientador correctamente.'], 201);
    } else {
        jsonError('Parámetro tipo inválido.');
    }
}

if ($method === 'DELETE') {
    $in = jsonInput();
    $tipo = $in['tipo'] ?? '';
    $id   = (int)($in['id'] ?? 0);
    if (!$id || !in_array($tipo, ['docente','orientador'], true)) jsonError('Parámetros inválidos.');
    $tabla = $tipo === 'docente' ? 'docente_asignaciones' : 'orientador_grupos';
    $pdo->prepare("DELETE FROM $tabla WHERE id = :id")->execute(['id' => $id]);
    jsonOk(['mensaje' => 'Asignación eliminada.']);
}

jsonError('Método no permitido.', 405);
