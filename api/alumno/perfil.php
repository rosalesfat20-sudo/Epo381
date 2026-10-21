<?php
require_once __DIR__ . '/../../includes/auth.php';
$u = requireLogin('alumno');
$pdo = getPDO();

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $stmt = $pdo->prepare('
        SELECT u.id, u.usuario, u.nombre, u.apellido_paterno, u.apellido_materno, u.primer_ingreso,
               a.*, g.nombre AS grupo_nombre, g.grado, g.turno
        FROM usuarios u
        JOIN alumnos a ON a.usuario_id = u.id
        LEFT JOIN grupos g ON g.id = a.grupo_id
        WHERE u.id = :id
    ');
    $stmt->execute(['id' => $u['id']]);
    $perfil = $stmt->fetch();
    if (!$perfil) jsonError('No se encontró el perfil del alumno.', 404);
    $perfil = decryptFields($perfil, camposPersonalesAlumno());
    jsonOk(['perfil' => $perfil]);
}

if ($method === 'POST' || $method === 'PUT') {
    $in = jsonInput();

    $campos = [
        'curp', 'fecha_nacimiento', 'sexo', 'telefono', 'email_personal',
        'tutor_nombre', 'tutor_telefono',
        'calle', 'numero', 'colonia', 'municipio', 'estado', 'cp',
        'escuela_procedencia', 'municipio_procedencia', 'promedio_secundaria',
    ];

    // ── Validación de formato de los datos personales ──
    $errores = [];
    $curp = isset($in['curp']) ? strtoupper(trim($in['curp'])) : null;
    if ($curp !== null && $curp !== '' && !preg_match('/^[A-Z]{4}\d{6}[HM][A-Z]{5}[A-Z0-9]\d$/', $curp)) {
        $errores[] = 'La CURP debe tener exactamente 18 caracteres con el formato oficial (ej. AAAA000101HAAAAA00).';
    }
    if ($curp !== null) $in['curp'] = $curp; // se guarda normalizada en mayúsculas

    $telefono = isset($in['telefono']) ? preg_replace('/\D/', '', $in['telefono']) : null;
    if ($telefono !== null && $telefono !== '' && strlen($telefono) !== 10) {
        $errores[] = 'El teléfono debe tener exactamente 10 dígitos.';
    }
    if ($telefono !== null) $in['telefono'] = $telefono;

    $tutorTel = isset($in['tutor_telefono']) ? preg_replace('/\D/', '', $in['tutor_telefono']) : null;
    if ($tutorTel !== null && $tutorTel !== '' && strlen($tutorTel) !== 10) {
        $errores[] = 'El teléfono del tutor debe tener exactamente 10 dígitos.';
    }
    if ($tutorTel !== null) $in['tutor_telefono'] = $tutorTel;

    $cp = isset($in['cp']) ? preg_replace('/\D/', '', $in['cp']) : null;
    if ($cp !== null && $cp !== '' && strlen($cp) !== 5) {
        $errores[] = 'El código postal debe tener exactamente 5 dígitos.';
    }
    if ($cp !== null) $in['cp'] = $cp;

    if (!empty($in['email_personal']) && !filter_var($in['email_personal'], FILTER_VALIDATE_EMAIL)) {
        $errores[] = 'El correo personal no tiene un formato válido.';
    }

    if ($errores) jsonError(implode(' ', $errores));

    $set = [];
    $params = ['id' => $u['id']];
    $personales = camposPersonalesAlumno();
    foreach ($campos as $c) {
        if (array_key_exists($c, $in)) {
            $valor = ($in[$c] === '' ? null : $in[$c]);
            // Los datos personales sensibles se cifran antes de guardarse.
            if (in_array($c, $personales, true)) {
                $valor = encryptField($valor !== null ? (string)$valor : null);
            }
            $set[] = "$c = :$c";
            $params[$c] = $valor;
        }
    }
    if (empty($set)) jsonError('No se recibieron datos para actualizar.');

    // Validación mínima de campos obligatorios para considerar el perfil completo
    $obligatorios = ['curp','fecha_nacimiento','sexo','telefono','calle','colonia','municipio','estado','cp','escuela_procedencia'];
    $todosPresentes = true;
    foreach ($obligatorios as $ob) {
        $valor = $in[$ob] ?? null;
        if ($valor === null || $valor === '') { $todosPresentes = false; break; }
    }

    $set[] = 'updated_at = now()';
    if ($todosPresentes) $set[] = 'perfil_completo = TRUE';

    $sql = 'UPDATE alumnos SET ' . implode(', ', $set) . ' WHERE usuario_id = :id';
    $pdo->prepare($sql)->execute($params);

    if ($todosPresentes) {
        $pdo->prepare('UPDATE usuarios SET primer_ingreso = FALSE WHERE id = :id')->execute(['id' => $u['id']]);
        $_SESSION['user']['primer_ingreso'] = false;
    }

    jsonOk(['mensaje' => 'Perfil actualizado correctamente.', 'perfil_completo' => $todosPresentes]);
}

jsonError('Método no permitido.', 405);
