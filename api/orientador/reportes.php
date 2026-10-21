<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/reportes_lib.php';
$u = requireLogin('orientador');
$pdo = getPDO();

$tipo    = $_GET['tipo']     ?? '';
$grupoId = isset($_GET['grupo_id'])  ? (int)$_GET['grupo_id']  : null;
$alumnoId= isset($_GET['alumno_id']) ? (int)$_GET['alumno_id'] : null;

if (!$grupoId) jsonError('Falta grupo_id.');
if (!orientadorTieneGrupo($pdo, $u['id'], $grupoId)) jsonError('No tienes asignado ese grupo.', 403);

switch ($tipo) {
    case 'boleta':
        if (!$alumnoId) jsonError('Falta alumno_id.');
        jsonOk(['boleta' => construirBoleta($pdo, $alumnoId, $grupoId)]);
        break;
    case 'concentrado':
        jsonOk(['concentrado' => construirConcentrado($pdo, $grupoId)]);
        break;
    case 'reprobados':
        jsonOk(['reprobados' => construirReprobados($pdo, $grupoId)]);
        break;
    case 'indicadores':
        jsonOk(['indicadores' => construirIndicadores($pdo, $grupoId)]);
        break;
    case 'honor':
        jsonOk(['honor' => construirHonor($pdo, $grupoId)]);
        break;
    case 'irregulares':
        jsonOk(['irregulares' => construirIrregulares($pdo, $grupoId)]);
        break;
    default:
        jsonError('Tipo de reporte inválido. Usa: boleta, concentrado, reprobados, indicadores, honor o irregulares.');
}
