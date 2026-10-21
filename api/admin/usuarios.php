<?php
require_once __DIR__ . '/../../includes/auth.php';
$u = requireLogin('admin');
$pdo = getPDO();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $rol = $_GET['rol'] ?? '';
    if (!in_array($rol, ['alumno','docente','orientador'], true)) jsonError('Parámetro rol inválido.');

    if ($rol === 'alumno') {
        $stmt = $pdo->query('
            SELECT us.id, us.usuario, us.nombre, us.apellido_paterno, us.apellido_materno, us.activo,
                   al.matricula, al.estatus, al.perfil_completo, g.nombre AS grupo_nombre, al.grupo_id
            FROM usuarios us JOIN alumnos al ON al.usuario_id = us.id
            LEFT JOIN grupos g ON g.id = al.grupo_id
            WHERE us.rol = \'alumno\'
            ORDER BY al.estatus, us.apellido_paterno, us.apellido_materno, us.nombre
        ');
    } else {
        $stmt = $pdo->prepare('SELECT id, usuario, nombre, apellido_paterno, apellido_materno, activo FROM usuarios WHERE rol = :r ORDER BY apellido_paterno, nombre');
        $stmt->execute(['r' => $rol]);
    }
    jsonOk(['usuarios' => $stmt->fetchAll()]);
}

if ($method === 'POST') {
    $in = jsonInput();
    $rol = $in['rol'] ?? '';
    $usuario = trim($in['usuario'] ?? '');
    $password = trim($in['password'] ?? '');
    $nombre = trim($in['nombre'] ?? '');
    $apPat  = trim($in['apellido_paterno'] ?? '');
    $apMat  = trim($in['apellido_materno'] ?? '');

    if (!in_array($rol, ['alumno','docente','orientador','admin'], true)) jsonError('Rol inválido.');
    if ($usuario === '' || $password === '' || $nombre === '' || $apPat === '') {
        jsonError('Usuario, contraseña, nombre y apellido paterno son obligatorios.');
    }
    if ($rol === 'alumno' && empty($in['grupo_id'])) {
        jsonError('Debes asignar un grupo al alumno.');
    }

    $chk = $pdo->prepare('SELECT 1 FROM usuarios WHERE usuario = :u');
    $chk->execute(['u' => $usuario]);
    if ($chk->fetch()) jsonError('Ese usuario/matrícula ya existe.');

    $pdo->beginTransaction();
    try {
        $ins = $pdo->prepare('
            INSERT INTO usuarios (usuario, password_hash, rol, nombre, apellido_paterno, apellido_materno, primer_ingreso, creado_por)
            VALUES (:usuario, :hash, :rol, :nombre, :apPat, :apMat, :primer, :creado_por) RETURNING id
        ');
        $ins->execute([
            'usuario' => $usuario,
            'hash' => password_hash($password, PASSWORD_DEFAULT),
            'rol' => $rol, 'nombre' => $nombre, 'apPat' => $apPat, 'apMat' => $apMat,
            'primer' => $rol === 'alumno' ? 't' : 'f',
            'creado_por' => $u['id'],
        ]);
        $newId = (int)$ins->fetchColumn();

        if ($rol === 'alumno') {
            $pdo->prepare('INSERT INTO alumnos (usuario_id, matricula, grupo_id) VALUES (:id, :mat, :g)')
                ->execute(['id' => $newId, 'mat' => $usuario, 'g' => (int)$in['grupo_id']]);
        }
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        jsonError('Error al crear el usuario: ' . $e->getMessage());
    }

    jsonOk(['mensaje' => ucfirst($rol) . ' creado correctamente.', 'id' => $newId], 201);
}

if ($method === 'PATCH') {
    // Dar de baja / reactivar un alumno
    $in = jsonInput();
    $alumnoId = (int)($in['alumno_id'] ?? 0);
    $accion   = $in['accion'] ?? '';
    if (!$alumnoId || !in_array($accion, ['baja','activar'], true)) jsonError('Parámetros inválidos.');

    if ($accion === 'baja') {
        $motivo = trim($in['motivo'] ?? '');
        $pdo->prepare('UPDATE alumnos SET estatus = \'baja\', fecha_baja = now(), motivo_baja = :m WHERE usuario_id = :id')
            ->execute(['m' => $motivo, 'id' => $alumnoId]);
        $pdo->prepare('UPDATE usuarios SET activo = FALSE WHERE id = :id')->execute(['id' => $alumnoId]);
        jsonOk(['mensaje' => 'Alumno dado de baja.']);
    } else {
        $pdo->prepare('UPDATE alumnos SET estatus = \'activo\', fecha_baja = NULL, motivo_baja = NULL WHERE usuario_id = :id')
            ->execute(['id' => $alumnoId]);
        $pdo->prepare('UPDATE usuarios SET activo = TRUE WHERE id = :id')->execute(['id' => $alumnoId]);
        jsonOk(['mensaje' => 'Alumno reactivado.']);
    }
}

if ($method === 'PUT') {
    // Editar nombre de un docente u orientador (no aplica a alumnos, cuyo nombre define el perfil)
    $in = jsonInput();
    $id = (int)($in['id'] ?? 0);
    $nombre = trim($in['nombre'] ?? '');
    $apPat  = trim($in['apellido_paterno'] ?? '');
    $apMat  = trim($in['apellido_materno'] ?? '');

    if (!$id || $nombre === '' || $apPat === '') jsonError('Nombre y apellido paterno son obligatorios.');

    $chk = $pdo->prepare("SELECT rol FROM usuarios WHERE id = :id");
    $chk->execute(['id' => $id]);
    $rolActual = $chk->fetchColumn();
    if (!in_array($rolActual, ['docente','orientador'], true)) {
        jsonError('Solo se pueden editar cuentas de docente u orientador.', 403);
    }

    $pdo->prepare('UPDATE usuarios SET nombre=:n, apellido_paterno=:ap, apellido_materno=:am WHERE id=:id')
        ->execute(['n' => $nombre, 'ap' => $apPat, 'am' => $apMat, 'id' => $id]);
    jsonOk(['mensaje' => 'Datos actualizados correctamente.']);
}

if ($method === 'DELETE') {
    // Eliminar (o desactivar si tiene historial ligado) a un docente u orientador
    $in = jsonInput();
    $id = (int)($in['id'] ?? 0);
    if (!$id) jsonError('Falta id.');

    $chk = $pdo->prepare("SELECT rol FROM usuarios WHERE id = :id");
    $chk->execute(['id' => $id]);
    $rolActual = $chk->fetchColumn();
    if (!in_array($rolActual, ['docente','orientador'], true)) {
        jsonError('Solo se pueden eliminar cuentas de docente u orientador.', 403);
    }

    try {
        $pdo->prepare('DELETE FROM usuarios WHERE id = :id')->execute(['id' => $id]);
        jsonOk(['mensaje' => ucfirst($rolActual) . ' eliminado permanentemente.']);
    } catch (Exception $e) {
        // Si tiene referencias que no permiten borrado físico, se desactiva en su lugar
        $pdo->prepare('UPDATE usuarios SET activo = FALSE WHERE id = :id')->execute(['id' => $id]);
        jsonOk(['mensaje' => 'No se pudo eliminar por completo porque tiene historial ligado (calificaciones o reportes), así que la cuenta fue desactivada en su lugar.']);
    }
}

jsonError('Método no permitido.', 405);
