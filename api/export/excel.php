<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/reportes_lib.php';
$u = requireLogin('orientador');
$pdo = getPDO();

$tipo    = $_GET['tipo']     ?? '';
$grupoId = isset($_GET['grupo_id'])  ? (int)$_GET['grupo_id']  : null;
$alumnoId= isset($_GET['alumno_id']) ? (int)$_GET['alumno_id'] : null;

if (!$grupoId || !orientadorTieneGrupo($pdo, $u['id'], $grupoId)) {
    http_response_code(403);
    exit('No autorizado.');
}

$grupoNombre = $pdo->query('SELECT nombre FROM grupos WHERE id = ' . (int)$grupoId)->fetchColumn();
$archivo = 'reporte_' . $tipo . '_' . preg_replace('/[^A-Za-z0-9]/', '', $grupoNombre) . '.xls';

header('Content-Type: application/vnd.ms-excel; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $archivo . '"');

echo "\xEF\xBB\xBF"; // BOM para acentos correctos en Excel
echo '<table border="1">';

switch ($tipo) {
    case 'concentrado':
        $c = construirConcentrado($pdo, $grupoId);
        echo '<tr><th>Matrícula</th><th>Alumno</th>';
        foreach ($c['materias'] as $m) echo '<th>' . htmlspecialchars($m) . '</th>';
        echo '<th>Promedio General</th><th>Faltas</th></tr>';
        foreach ($c['alumnos'] as $a) {
            echo '<tr><td>' . htmlspecialchars($a['matricula']) . '</td><td>' . htmlspecialchars($a['nombre']) . '</td>';
            foreach ($c['materias'] as $m) {
                $prom = $a['materias'][$m]['promedio'] ?? '';
                echo '<td>' . $prom . '</td>';
            }
            echo '<td>' . $a['promedio_general'] . '</td><td>' . ($a['total_faltas'] ?? 0) . '</td></tr>';
        }
        break;

    case 'reprobados':
        $r = construirReprobados($pdo, $grupoId);
        echo '<tr><th>Matrícula</th><th>Alumno</th><th>Materias Reprobadas</th><th>Total</th><th>Promedio General</th></tr>';
        foreach ($r as $a) {
            $mats = implode(', ', array_map(fn($m) => $m['materia'] . ' (' . $m['promedio'] . ')', $a['materias_reprobadas']));
            echo '<tr><td>' . htmlspecialchars($a['matricula']) . '</td><td>' . htmlspecialchars($a['nombre']) . '</td><td>' . htmlspecialchars($mats) . '</td><td>' . $a['total_reprobadas'] . '</td><td>' . $a['promedio_general'] . '</td></tr>';
        }
        break;

    case 'indicadores':
        $i = construirIndicadores($pdo, $grupoId);
        echo '<tr><th>Indicador</th><th>Valor</th></tr>';
        echo '<tr><td>Total de alumnos</td><td>' . $i['total_alumnos'] . '</td></tr>';
        echo '<tr><td>Promedio grupal</td><td>' . $i['promedio_grupal'] . '</td></tr>';
        echo '<tr><td>Alumnos reprobados</td><td>' . $i['total_reprobados'] . '</td></tr>';
        echo '<tr><td>% Aprobación</td><td>' . $i['porcentaje_aprobacion'] . '</td></tr>';
        echo '<tr><td>% Reprobación</td><td>' . $i['porcentaje_reprobacion'] . '</td></tr>';
        echo '<tr><td colspan="2"></td></tr>';
        echo '<tr><th>Materia</th><th>Promedio</th><th>% Aprobación</th></tr>';
        foreach ($i['por_materia'] as $mat => $d) {
            echo '<tr><td>' . htmlspecialchars($mat) . '</td><td>' . $d['promedio'] . '</td><td>' . $d['porcentaje_aprobacion'] . '</td></tr>';
        }
        break;

    case 'boleta':
        if (!$alumnoId) { exit('Falta alumno_id.'); }
        $b = construirBoleta($pdo, $alumnoId, $grupoId);
        $al = $b['alumno'];
        echo '<tr><th colspan="2">ESCUELA PREPARATORIA OFICIAL NÚM. 381 DE TENANCINGO / TURNO MATUTINO — 15EBP0025F</th></tr>';
        echo '<tr><th colspan="2">' . htmlspecialchars(($al['semestre_global'] ? nombreSemestre($al['semestre_global']) : '') . ' CICLO ESCOLAR ' . ($al['ciclo_escolar'] ?? '')) . '</th></tr>';
        echo '<tr><td colspan="2"><b>INFORME DE CALIFICACIONES (PARA USO EXCLUSIVO DE ORIENTACIÓN). NO ES UNA BOLETA NI COMPROBANTE DE INSCRIPCIÓN. Para trámites oficiales, favor de dirigirse con Control Escolar.</b></td></tr>';
        echo '<tr><td colspan="2"></td></tr>';
        echo '<tr><td>Alumno</td><td>' . htmlspecialchars(trim($al['nombre'] . ' ' . $al['apellido_paterno'] . ' ' . $al['apellido_materno'])) . '</td></tr>';
        echo '<tr><td>Matrícula</td><td>' . htmlspecialchars($al['matricula']) . '</td></tr>';
        echo '<tr><td>Grupo</td><td>' . htmlspecialchars($al['grupo_nombre']) . '</td></tr>';
        echo '<tr><td colspan="2"></td></tr>';
        echo '<tr><th>Asignatura</th><th>P1</th><th>P2</th><th>P3</th><th>Calif. Final</th><th>Faltas</th><th>Estatus</th></tr>';
        foreach ($b['materias'] as $mat => $d) {
            echo '<tr><td>' . htmlspecialchars($mat) . '</td><td>' . ($d['parciales'][1] ?? '') . '</td><td>' . ($d['parciales'][2] ?? '') . '</td><td>' . ($d['parciales'][3] ?? '') . '</td><td>' . $d['promedio'] . '</td><td>' . ($d['faltas'] ?? 0) . '</td><td>' . $d['estatus'] . '</td></tr>';
        }
        echo '<tr><td colspan="4"><b>Promedio General</b></td><td colspan="3">' . $b['promedio_general'] . ' — Faltas totales: ' . ($b['total_faltas'] ?? 0) . '</td></tr>';
        break;

    case 'honor':
        $h = construirHonor($pdo, $grupoId);
        echo '<tr><th colspan="4">CUADRO DE HONOR</th></tr>';
        echo '<tr><th>Lugar</th><th>Matrícula</th><th>Alumno</th><th>Promedio General</th></tr>';
        foreach ($h as $r) {
            echo '<tr><td>' . $r['lugar'] . '° — ' . htmlspecialchars($r['etiqueta']) . '</td><td>' . htmlspecialchars($r['matricula']) . '</td><td>' . htmlspecialchars($r['nombre']) . '</td><td>' . $r['promedio_general'] . '</td></tr>';
        }
        break;

    case 'irregulares':
        $irr = construirIrregulares($pdo, $grupoId);
        echo '<tr><th colspan="6">SEGUIMIENTO DE IRREGULARES</th></tr>';
        echo '<tr><th>Matrícula</th><th>Alumno</th><th>Materia</th><th>Promedio Original</th><th>Intentos</th><th>Estatus</th></tr>';
        foreach ($irr as $al) {
            foreach ($al['materias'] as $mat => $d) {
                $intentos = $d['intentos'] ? implode(' · ', array_map(fn($i) => $i['oportunidad'] . 'ª: ' . ($i['calificacion'] ?? '—') . ($i['fecha'] ? ' (' . $i['fecha'] . ')' : ''), $d['intentos'])) : 'Sin intentos registrados';
                echo '<tr><td>' . htmlspecialchars($al['matricula']) . '</td><td>' . htmlspecialchars($al['nombre']) . '</td><td>' . htmlspecialchars($mat) . '</td><td>' . $d['promedio_original'] . '</td><td>' . htmlspecialchars($intentos) . '</td><td>' . ($d['regularizada'] ? 'Regularizada' : 'Pendiente') . '</td></tr>';
            }
        }
        break;

    default:
        exit('Tipo de reporte inválido.');
}

echo '</table>';
