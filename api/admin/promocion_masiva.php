<?php
require_once __DIR__ . '/../../includes/auth.php';
$u = requireLogin('admin');
$pdo = getPDO();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Método no permitido.', 405);

$in = jsonInput();
$grupoOrigenId  = (int)($in['grupo_origen_id']  ?? 0);
$grupoDestinoId = (int)($in['grupo_destino_id'] ?? 0);
$motivo         = trim($in['motivo'] ?? 'Promoción de fin de semestre');

if (!$grupoOrigenId || !$grupoDestinoId) jsonError('Selecciona grupo de origen y grupo destino.');
if ($grupoOrigenId === $grupoDestinoId) jsonError('El grupo destino debe ser diferente al de origen.');

$stmt = $pdo->prepare("SELECT usuario_id FROM alumnos WHERE grupo_id = :g AND estatus = 'activo'");
$stmt->execute(['g' => $grupoOrigenId]);
$alumnos = $stmt->fetchAll(PDO::FETCH_COLUMN);

if (empty($alumnos)) jsonError('No hay alumnos activos en el grupo de origen.');

$pdo->beginTransaction();
try {
    $upd = $pdo->prepare('UPDATE alumnos SET grupo_id = :g WHERE usuario_id = :id');
    $hist = $pdo->prepare('
        INSERT INTO historial_grupos (alumno_id, grupo_anterior_id, grupo_nuevo_id, motivo, admin_id)
        VALUES (:a, :ga, :gn, :m, :adm)
    ');
    foreach ($alumnos as $alumnoId) {
        $upd->execute(['g' => $grupoDestinoId, 'id' => $alumnoId]);
        $hist->execute(['a' => $alumnoId, 'ga' => $grupoOrigenId, 'gn' => $grupoDestinoId, 'm' => $motivo, 'adm' => $u['id']]);
    }
    $pdo->commit();
} catch (Exception $e) {
    $pdo->rollBack();
    jsonError('Error en la promoción masiva: ' . $e->getMessage());
}

jsonOk(['mensaje' => count($alumnos) . ' alumno(s) movidos correctamente al nuevo grupo.']);
