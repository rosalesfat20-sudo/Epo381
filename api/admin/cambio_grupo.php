<?php
require_once __DIR__ . '/../../includes/auth.php';
$u = requireLogin('admin');
$pdo = getPDO();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Método no permitido.', 405);

$in = jsonInput();
$alumnoId    = (int)($in['alumno_id']      ?? 0);
$grupoNuevo  = (int)($in['grupo_nuevo_id'] ?? 0);
$motivo      = trim($in['motivo'] ?? '');

if (!$alumnoId || !$grupoNuevo) jsonError('alumno_id y grupo_nuevo_id son obligatorios.');

$actual = $pdo->prepare('SELECT grupo_id FROM alumnos WHERE usuario_id = :id');
$actual->execute(['id' => $alumnoId]);
$grupoActual = $actual->fetchColumn();
if ($grupoActual === false) jsonError('Alumno no encontrado.', 404);

$pdo->beginTransaction();
try {
    $pdo->prepare('UPDATE alumnos SET grupo_id = :g WHERE usuario_id = :id')
        ->execute(['g' => $grupoNuevo, 'id' => $alumnoId]);
    $pdo->prepare('
        INSERT INTO historial_grupos (alumno_id, grupo_anterior_id, grupo_nuevo_id, motivo, admin_id)
        VALUES (:a, :ga, :gn, :m, :adm)
    ')->execute(['a' => $alumnoId, 'ga' => $grupoActual ?: null, 'gn' => $grupoNuevo, 'm' => $motivo, 'adm' => $u['id']]);
    $pdo->commit();
} catch (Exception $e) {
    $pdo->rollBack();
    jsonError('Error al cambiar de grupo: ' . $e->getMessage());
}

jsonOk(['mensaje' => 'Alumno movido de grupo correctamente.']);
