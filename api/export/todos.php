<?php

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/reportes_lib.php';
$u = requireLogin('orientador');
$pdo = getPDO();

$grupoId = isset($_GET['grupo_id']) ? (int)$_GET['grupo_id'] : null;
if (!$grupoId || !orientadorTieneGrupo($pdo, $u['id'], $grupoId)) {
    http_response_code(403);
    exit('No autorizado.');
}

if (!class_exists('ZipArchive')) {
    http_response_code(500);
    exit('La extensión ZipArchive de PHP no está habilitada en este servidor. Actívala (php-zip) para poder descargar todos los reportes juntos, o descarga cada reporte por separado.');
}

$grupo = $pdo->prepare('SELECT nombre, ciclo_escolar FROM grupos WHERE id = :id');
$grupo->execute(['id' => $grupoId]);
$grupoRow = $grupo->fetch();
$grupoNombreLimpio = preg_replace('/[^A-Za-z0-9]/', '_', $grupoRow['nombre'] ?? 'grupo');

function esc2($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function tablaHtmlXls(string $titulo, string $tablaHtml): string {
    return "\xEF\xBB\xBF<table border=\"1\"><tr><th colspan=\"20\">" . htmlspecialchars($titulo) . "</th></tr>" . $tablaHtml . '</table>';
}

$tmpZip = tempnam(sys_get_temp_dir(), 'epo381_reportes_');
$zip = new ZipArchive();
$zip->open($tmpZip, ZipArchive::OVERWRITE);

// 1) Concentrado
$c = construirConcentrado($pdo, $grupoId);
$filas = '<tr><th>Matrícula</th><th>Alumno</th>' . implode('', array_map(fn($m) => '<th>' . htmlspecialchars($m) . '</th>', $c['materias'])) . '<th>Promedio General</th><th>Faltas</th></tr>';
foreach ($c['alumnos'] as $a) {
    $filas .= '<tr><td>' . htmlspecialchars($a['matricula']) . '</td><td>' . htmlspecialchars($a['nombre']) . '</td>';
    foreach ($c['materias'] as $m) $filas .= '<td>' . ($a['materias'][$m]['promedio'] ?? '') . '</td>';
    $filas .= '<td>' . $a['promedio_general'] . '</td><td>' . ($a['total_faltas'] ?? 0) . '</td></tr>';
}
$zip->addFromString('concentrado.xls', tablaHtmlXls('Concentrado de Calificaciones - ' . $grupoRow['nombre'], $filas));

// 2) Reprobados
$r = construirReprobados($pdo, $grupoId);
$filas = '<tr><th>Matrícula</th><th>Alumno</th><th>Materias Reprobadas</th><th>Total</th><th>Promedio General</th></tr>';
foreach ($r as $a) {
    $mats = implode(', ', array_map(fn($m) => $m['materia'] . ' (' . $m['promedio'] . ')', $a['materias_reprobadas']));
    $filas .= '<tr><td>' . htmlspecialchars($a['matricula']) . '</td><td>' . htmlspecialchars($a['nombre']) . '</td><td>' . htmlspecialchars($mats) . '</td><td>' . $a['total_reprobadas'] . '</td><td>' . $a['promedio_general'] . '</td></tr>';
}
$zip->addFromString('reprobados.xls', tablaHtmlXls('Seguimiento de Alumnos Reprobados - ' . $grupoRow['nombre'], $filas));

// 3) Indicadores
$i = construirIndicadores($pdo, $grupoId);
$filas = '<tr><th>Indicador</th><th>Valor</th></tr>'
    . '<tr><td>Total de alumnos</td><td>' . $i['total_alumnos'] . '</td></tr>'
    . '<tr><td>Promedio grupal</td><td>' . $i['promedio_grupal'] . '</td></tr>'
    . '<tr><td>Alumnos reprobados</td><td>' . $i['total_reprobados'] . '</td></tr>'
    . '<tr><td>% Aprobación</td><td>' . $i['porcentaje_aprobacion'] . '</td></tr>'
    . '<tr><td>% Reprobación</td><td>' . $i['porcentaje_reprobacion'] . '</td></tr>'
    . '<tr><td colspan="2"></td></tr><tr><th>Materia</th><th>Promedio</th><th>% Aprobación</th></tr>';
foreach ($i['por_materia'] as $mat => $d) {
    $filas .= '<tr><td>' . htmlspecialchars($mat) . '</td><td>' . $d['promedio'] . '</td><td>' . $d['porcentaje_aprobacion'] . '</td></tr>';
}
$zip->addFromString('indicadores.xls', tablaHtmlXls('Indicadores Académicos - ' . $grupoRow['nombre'], $filas));

// 4) Cuadro de Honor
$h = construirHonor($pdo, $grupoId);
$filas = '<tr><th>Lugar</th><th>Matrícula</th><th>Alumno</th><th>Promedio General</th></tr>';
foreach ($h as $row) {
    $filas .= '<tr><td>' . $row['lugar'] . '° — ' . htmlspecialchars($row['etiqueta']) . '</td><td>' . htmlspecialchars($row['matricula']) . '</td><td>' . htmlspecialchars($row['nombre']) . '</td><td>' . $row['promedio_general'] . '</td></tr>';
}
$zip->addFromString('honor.xls', tablaHtmlXls('Cuadro de Honor - ' . $grupoRow['nombre'], $filas));

// 5) Seguimiento de Irregulares
$irr = construirIrregulares($pdo, $grupoId);
$filas = '<tr><th>Matrícula</th><th>Alumno</th><th>Materia</th><th>Promedio Original</th><th>Intentos</th><th>Estatus</th></tr>';
foreach ($irr as $al) {
    foreach ($al['materias'] as $mat => $d) {
        $intentos = $d['intentos'] ? implode(' · ', array_map(fn($i) => $i['oportunidad'] . 'ª: ' . ($i['calificacion'] ?? '—') . ($i['fecha'] ? ' (' . $i['fecha'] . ')' : ''), $d['intentos'])) : 'Sin intentos registrados';
        $filas .= '<tr><td>' . htmlspecialchars($al['matricula']) . '</td><td>' . htmlspecialchars($al['nombre']) . '</td><td>' . htmlspecialchars($mat) . '</td><td>' . $d['promedio_original'] . '</td><td>' . htmlspecialchars($intentos) . '</td><td>' . ($d['regularizada'] ? 'Regularizada' : 'Pendiente') . '</td></tr>';
    }
}
$zip->addFromString('irregulares.xls', tablaHtmlXls('Seguimiento de Irregulares - ' . $grupoRow['nombre'], $filas));

// 6) Informe de Calificaciones (uno por alumno del grupo)
$alumnosStmt = $pdo->prepare("
    SELECT us.id FROM alumnos al JOIN usuarios us ON us.id = al.usuario_id
    WHERE al.grupo_id = :g AND al.estatus = 'activo' ORDER BY us.apellido_paterno, us.apellido_materno, us.nombre
");
$alumnosStmt->execute(['g' => $grupoId]);
foreach ($alumnosStmt->fetchAll() as $row) {
    $b = construirBoleta($pdo, (int)$row['id'], $grupoId);
    $al = $b['alumno'];
    if (!$al) continue;
    $filasInforme = '';
    foreach ($b['materias'] as $mat => $d) {
        $filasInforme .= '<tr><td>' . esc2($mat) . '</td><td>' . esc2($d['parciales'][1] ?? '—') . '</td><td>' . esc2($d['parciales'][2] ?? '—') . '</td><td>' . esc2($d['parciales'][3] ?? '—') . '</td>'
            . '<td class="' . ($d['promedio'] !== null && $d['promedio'] < 6 ? 'promedio-bajo' : '') . '">' . esc2($d['promedio'] ?? '—') . '</td><td>' . esc2($d['estatus']) . '</td></tr>';
    }
    $html = '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Informe de Calificaciones</title><style>
        body{font-family:Arial,Helvetica,sans-serif;color:#1e293b;margin:30px;}
        table{border-collapse:collapse;width:100%;margin-top:14px;}
        th,td{border:1px solid #cbd5e1;padding:8px 10px;font-size:0.85rem;text-align:left;}
        th{background:#1e293b;color:#fff;} tr:nth-child(even){background:#f1f5f9;}
        .promedio-bajo{color:#b91c1c;font-weight:bold;}
        .informe-header{text-align:center;margin-bottom:20px;}
        .informe-header .disclaimer{font-size:0.8rem;border:1px solid #8e1b22;border-radius:8px;padding:8px 12px;margin-top:10px;}
        .no-print button{background:#8e1b22;color:#fff;border:none;padding:10px 18px;border-radius:8px;cursor:pointer;font-weight:700;}
        @media print{.no-print{display:none;}}
        </style></head><body>
        <div class="no-print"><button onclick="window.print()">🖨️ Imprimir / Guardar como PDF</button></div>
        <div class="informe-header">
            <p><b>ESCUELA PREPARATORIA OFICIAL NÚM. 381 DE TENANCINGO / TURNO MATUTINO</b><br>15EBP0025F</p>
            <p><b>' . esc2($al['semestre_global'] ? nombreSemestre($al['semestre_global']) : '') . ' CICLO ESCOLAR ' . esc2($al['ciclo_escolar'] ?? '') . '</b></p>
            <p class="disclaimer"><b>INFORME DE CALIFICACIONES (PARA USO EXCLUSIVO DE ORIENTACIÓN). NO ES UNA BOLETA NI COMPROBANTE DE INSCRIPCIÓN.
            Para trámites oficiales, favor de dirigirse con Control Escolar.</b></p>
        </div>
        <p><b>Alumno:</b> ' . esc2(trim($al['nombre'] . ' ' . $al['apellido_paterno'] . ' ' . $al['apellido_materno'])) . ' &nbsp;
           <b>Matrícula:</b> ' . esc2($al['matricula']) . ' &nbsp; <b>Grupo:</b> ' . esc2($al['grupo_nombre'] ?? '—') . '</p>
        <table><tr><th>Asignatura</th><th>Primer Parcial</th><th>Segundo Parcial</th><th>Tercer Parcial</th><th>Calificación Final</th><th>Estatus</th></tr>'
        . $filasInforme .
        '<tr><td colspan="4"><b>Promedio General</b></td><td colspan="2"><b>' . esc2($b['promedio_general'] ?? '—') . '</b></td></tr></table>
        </body></html>';

    $nombreArchivo = 'informe_' . preg_replace('/[^A-Za-z0-9]/', '_', $al['matricula'] ?? ('alumno_' . $row['id'])) . '.html';
    $zip->addFromString('informes_individuales/' . $nombreArchivo, $html);
}

$zip->close();

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="reportes_' . $grupoNombreLimpio . '.zip"');
header('Content-Length: ' . filesize($tmpZip));
readfile($tmpZip);
unlink($tmpZip);
