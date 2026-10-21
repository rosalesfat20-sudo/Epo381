<?php
require_once __DIR__ . '/../../includes/auth.php';
$u = requireLogin('alumno');
$pdo = getPDO();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') jsonError('Método no permitido.', 405);

$stmt = $pdo->prepare('
    SELECT rc.id, rc.fecha, rc.motivo, rc.descripcion, rc.medida, rc.autor_rol,
           COALESCE((au.nombre || \' \' || au.apellido_paterno), rc.autor_nombre_hist, \'Usuario eliminado\') AS autor_nombre,
           m.nombre AS materia_nombre
    FROM reportes_conducta rc
    LEFT JOIN usuarios au ON au.id = rc.autor_id
    LEFT JOIN materias m ON m.id = rc.materia_id
    WHERE rc.alumno_id = :id
    ORDER BY rc.fecha DESC, rc.created_at DESC
');
$stmt->execute(['id' => $u['id']]);
jsonOk(['reportes' => $stmt->fetchAll()]);
