<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/reportes_lib.php';
$u = requireLogin('alumno');
$pdo = getPDO();

$stmt = $pdo->prepare('
    SELECT DISTINCT c.grupo_id, c.ciclo_escolar, c.grado_captura AS grado, c.semestre_captura AS semestre
    FROM calificaciones c
    WHERE c.alumno_id = :id
');
$stmt->execute(['id' => $u['id']]);
$semestres = $stmt->fetchAll();

// Si el alumno está inscrito actualmente pero aún no tiene ninguna calificación
// capturada en su grupo actual, se agrega también ese grupo con el ciclo/grado
// actuales para que aparezca como "en curso" aunque esté vacío.
$actual = $pdo->prepare('SELECT grupo_id FROM alumnos WHERE usuario_id = :id');
$actual->execute(['id' => $u['id']]);
$grupoActualId = $actual->fetchColumn();
if ($grupoActualId && !in_array($grupoActualId, array_column($semestres, 'grupo_id'))) {
    $g = $pdo->prepare('SELECT ciclo_escolar, grado, semestre FROM grupos WHERE id = :g');
    $g->execute(['g' => $grupoActualId]);
    $gi = $g->fetch();
    if ($gi) $semestres[] = ['grupo_id' => $grupoActualId, 'ciclo_escolar' => $gi['ciclo_escolar'], 'grado' => $gi['grado'], 'semestre' => $gi['semestre']];
}

usort($semestres, fn($a, $b) =>
    ((int)($a['grado'] ?? 0) * 2 + (int)($a['semestre'] ?? 0)) <=> ((int)($b['grado'] ?? 0) * 2 + (int)($b['semestre'] ?? 0))
);

$historial = [];
foreach ($semestres as $s) {
    $boleta = construirBoleta($pdo, $u['id'], (int)$s['grupo_id'], $s['ciclo_escolar']);
    $gn = $pdo->prepare('SELECT nombre FROM grupos WHERE id = :g');
    $gn->execute(['g' => $s['grupo_id']]);
    $historial[] = [
        'grupo_id'       => $s['grupo_id'],
        'grupo_nombre'   => $gn->fetchColumn() ?: '—', // el nombre del salón (ej. "1 I") no cambia, es un catálogo fijo
        'grado'          => $s['grado'],
        'semestre'       => $s['semestre'],
        'semestre_global'=> $s['grado'] ? ((int)$s['grado'] - 1) * 2 + (int)$s['semestre'] : null,
        'ciclo_escolar'  => $s['ciclo_escolar'],
        'materias'       => $boleta['materias'],
        'total_faltas'   => $boleta['total_faltas'],
        'promedio_general' => $boleta['promedio_general'],
    ];
}

jsonOk(['historial' => $historial]);
