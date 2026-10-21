<?php
require_once __DIR__ . '/../../includes/auth.php';
$u = requireLogin('admin');
$pdo = getPDO();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    jsonOk(['materias' => $pdo->query('SELECT * FROM materias ORDER BY semestre NULLS FIRST, nombre')->fetchAll()]);
}

if ($method === 'POST') {
    $in = jsonInput();
    $nombre   = trim($in['nombre'] ?? '');
    $clave    = trim($in['clave'] ?? '');
    $semestre = isset($in['semestre']) && $in['semestre'] !== '' ? (int)$in['semestre'] : null;

    if ($nombre === '') jsonError('El nombre de la materia es obligatorio.');
    if ($semestre === null || $semestre < 1 || $semestre > 6) {
        jsonError('Indica a qué semestre (1° a 6°) pertenece la materia. Cada semestre tiene sus propias asignaturas.');
    }

    try {
        $pdo->prepare('INSERT INTO materias (nombre, clave, semestre) VALUES (:n, :c, :s)')
            ->execute(['n' => $nombre, 'c' => $clave, 's' => $semestre]);
    } catch (Exception $e) {
        jsonError('No se pudo crear la materia (¿nombre repetido?): ' . $e->getMessage());
    }
    jsonOk(['mensaje' => 'Materia creada correctamente.'], 201);
}

if ($method === 'PUT') {

    $in = jsonInput();
    $id       = (int)($in['id'] ?? 0);
    $nombre   = trim($in['nombre'] ?? '');
    $clave    = trim($in['clave'] ?? '');
    $semestre = isset($in['semestre']) && $in['semestre'] !== '' ? (int)$in['semestre'] : null;

    if (!$id) jsonError('Falta id.');
    if ($nombre === '') jsonError('El nombre de la materia es obligatorio.');
    if ($semestre === null || $semestre < 1 || $semestre > 6) {
        jsonError('Indica a qué semestre (1° a 6°) pertenece la materia.');
    }

    try {
        $pdo->prepare('UPDATE materias SET nombre = :n, clave = :c, semestre = :s WHERE id = :id')
            ->execute(['n' => $nombre, 'c' => $clave, 's' => $semestre, 'id' => $id]);
    } catch (Exception $e) {
        jsonError('No se pudo actualizar la materia (¿nombre repetido?): ' . $e->getMessage());
    }
    jsonOk(['mensaje' => 'Materia actualizada correctamente.']);
}

jsonError('Método no permitido.', 405);
