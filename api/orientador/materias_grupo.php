<?php
require_once __DIR__ . '/../../includes/auth.php';
$u = requireLogin('orientador');
$pdo = getPDO();

$grupoId = isset($_GET['grupo_id']) ? (int)$_GET['grupo_id'] : null;
if (!$grupoId) jsonError('Falta grupo_id.');
if (!orientadorTieneGrupo($pdo, $u['id'], $grupoId)) jsonError('No tienes asignado ese grupo.', 403);

$stmt = $pdo->prepare('
    SELECT DISTINCT m.id, m.nombre
    FROM docente_asignaciones da
    JOIN materias m ON m.id = da.materia_id
    JOIN grupos g ON g.id = da.grupo_id
    WHERE da.grupo_id = :g
      -- Solo materias que realmente correspondan al semestre ACTUAL del grupo:
      -- protege contra asignaciones que hayan quedado de un semestre anterior
      -- (por ejemplo, si el grupo ya avanzó de semestre pero la asignación del
      -- docente para la materia vieja no se quitó).
      AND m.semestre = (g.grado - 1) * 2 + g.semestre
    ORDER BY m.nombre
');
$stmt->execute(['g' => $grupoId]);
jsonOk(['materias' => $stmt->fetchAll()]);
