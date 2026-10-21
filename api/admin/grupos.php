<?php
require_once __DIR__ . '/../../includes/auth.php';
$u = requireLogin('admin');
$pdo = getPDO();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    jsonOk(['grupos' => $pdo->query('SELECT * FROM grupos ORDER BY grado, nombre')->fetchAll()]);
}

if ($method === 'POST') {
    $in = jsonInput();
    $nombre = trim($in['nombre'] ?? '');
    $grado  = (int)($in['grado'] ?? 0);
    $semestre = (int)($in['semestre'] ?? 1);
    $turno  = 'Matutino'; // único turno que maneja la escuela
    $ciclo  = trim($in['ciclo_escolar'] ?? '2026-2027');

    $salonesValidos = ['1 I','1 II','1 III','2 I','2 II','2 III','3 I','3 II','3 III'];
    if (!in_array($nombre, $salonesValidos, true)) jsonError('Selecciona un salón válido (Ej. 1 I, 2 II, 3 III).');
    if ($nombre === '' || $grado < 1 || $grado > 3) jsonError('Nombre y grado (1-3) son obligatorios.');
    if (!in_array($semestre, [1,2], true)) jsonError('El semestre debe ser 1 o 2.');

    try {
        $pdo->prepare('INSERT INTO grupos (nombre, grado, semestre, turno, ciclo_escolar) VALUES (:n, :g, :s, :t, :c)')
            ->execute(['n' => $nombre, 'g' => $grado, 's' => $semestre, 't' => $turno, 'c' => $ciclo]);
    } catch (Exception $e) {
        jsonError('No se pudo crear el grupo (¿nombre repetido?): ' . $e->getMessage());
    }
    jsonOk(['mensaje' => 'Grupo creado correctamente.'], 201);
}

if ($method === 'PUT') {
    $in = jsonInput();
    $id = (int)($in['id'] ?? 0);
    $accion = $in['accion'] ?? '';
    if (!$id) jsonError('Falta id.');

    if ($accion === 'avanzar_semestre') {
        // Pasa de 1er a 2do semestre dentro del MISMO grado y ciclo escolar
        // (el salón sigue siendo el mismo, solo cambia qué semestre están cursando).
        $g = $pdo->prepare('SELECT semestre FROM grupos WHERE id = :id');
        $g->execute(['id' => $id]);
        $actual = $g->fetchColumn();
        if ($actual === false) jsonError('Grupo no encontrado.', 404);
        if ((int)$actual !== 1) jsonError('Este grupo ya está en su segundo semestre. Para continuar, usa "Promoción masiva" para pasarlo al siguiente grado.');
        $pdo->prepare('UPDATE grupos SET semestre = 2 WHERE id = :id')->execute(['id' => $id]);
        jsonOk(['mensaje' => 'Grupo avanzado al 2º semestre correctamente.']);
    }

    if ($accion === 'nuevo_ciclo') {

        $cicloNuevo = trim($in['ciclo_escolar'] ?? '');
        if ($cicloNuevo === '') jsonError('Indica el nuevo ciclo escolar (ej. 2026-2027).');
        $activos = $pdo->prepare("SELECT COUNT(*) FROM alumnos WHERE grupo_id = :id AND estatus = 'activo'");
        $activos->execute(['id' => $id]);
        if ((int)$activos->fetchColumn() > 0 && empty($in['confirmar'])) {
            jsonError('Este grupo todavía tiene alumnos activos asignados. Primero muévelos con "Promoción masiva", o marca "confirmar" si de verdad quieres reiniciar el salón con ellos dentro.');
        }
        $pdo->prepare('UPDATE grupos SET ciclo_escolar = :c, semestre = 1 WHERE id = :id')
            ->execute(['c' => $cicloNuevo, 'id' => $id]);
        jsonOk(['mensaje' => 'Grupo listo para el nuevo ciclo escolar ' . $cicloNuevo . '.']);
    }

    jsonError('Acción inválida. Usa: avanzar_semestre o nuevo_ciclo.');
}

jsonError('Método no permitido.', 405);
