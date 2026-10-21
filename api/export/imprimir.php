<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/reportes_lib.php';
$u = requireLogin('orientador');
$pdo = getPDO();

$tipo    = $_GET['tipo']     ?? '';
$grupoId = isset($_GET['grupo_id'])  ? (int)$_GET['grupo_id']  : null;
$alumnoId= isset($_GET['alumno_id']) ? (int)$_GET['alumno_id'] : null;

header('Content-Type: text/html; charset=utf-8');

if (!$grupoId || !orientadorTieneGrupo($pdo, $u['id'], $grupoId)) {
    http_response_code(403);
    exit('No autorizado.');
}

$grupoNombre = $pdo->query('SELECT nombre FROM grupos WHERE id = ' . (int)$grupoId)->fetchColumn();
$titulo = ['boleta' => 'Informe de Calificaciones', 'concentrado' => 'Concentrado de Calificaciones',
           'reprobados' => 'Seguimiento de Alumnos Reprobados', 'indicadores' => 'Indicadores Académicos',
           'honor' => 'Cuadro de Honor', 'irregulares' => 'Seguimiento de Irregulares'][$tipo] ?? 'Reporte';

function esc($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title><?= esc($titulo) ?> - <?= esc($grupoNombre) ?></title>
<style>
    body{font-family:Arial,Helvetica,sans-serif;color:#1e293b;margin:30px;}
    h1{color:#8e1b22;font-size:1.5rem;margin-bottom:0;}
    h2{color:#1e293b;font-size:1rem;font-weight:400;margin-top:4px;}
    table{border-collapse:collapse;width:100%;margin-top:20px;}
    th,td{border:1px solid #cbd5e1;padding:8px 10px;font-size:0.85rem;text-align:left;}
    th{background:#1e293b;color:#fff;}
    tr:nth-child(even){background:#f1f5f9;}
    .footer{margin-top:40px;font-size:0.75rem;color:#64748b;}
    .promedio-bajo{color:#b91c1c;font-weight:bold;}
    .informe-header{text-align:center;margin-bottom:20px;}
    .informe-header p{margin:4px 0;font-size:0.85rem;}
    .informe-header .disclaimer{font-size:0.8rem;border:1px solid #8e1b22;border-radius:8px;padding:8px 12px;margin-top:10px;}
    @media print { .no-print{display:none;} body{margin:10px;} }
    .no-print{margin-bottom:20px;}
    .no-print button{background:#8e1b22;color:#fff;border:none;padding:10px 18px;border-radius:8px;cursor:pointer;font-weight:700;}
</style>
</head>
<body onload="window.print()">
<div class="no-print"><button onclick="window.print()">🖨️ Imprimir / Guardar como PDF</button></div>
<h1>EPO 381 · <?= esc($titulo) ?></h1>
<h2>Grupo: <?= esc($grupoNombre) ?> — Generado: <?= date('d/m/Y H:i') ?></h2>

<?php if ($tipo === 'boleta'):
    if (!$alumnoId) { echo '<p>Falta alumno.</p>'; exit; }
    $b = construirBoleta($pdo, $alumnoId, $grupoId);
    $al = $b['alumno'];
?>
    <div class="informe-header">
        <p><b>ESCUELA PREPARATORIA OFICIAL NÚM. 381 DE TENANCINGO / TURNO MATUTINO</b><br>15EBP0025F</p>
        <p><b><?= esc($al['semestre_global'] ? nombreSemestre($al['semestre_global']) : '') ?> CICLO ESCOLAR <?= esc($al['ciclo_escolar'] ?? '') ?></b></p>
        <p class="disclaimer"><b>INFORME DE CALIFICACIONES (PARA USO EXCLUSIVO DE ORIENTACIÓN). NO ES UNA BOLETA NI COMPROBANTE DE INSCRIPCIÓN.
        Para trámites oficiales, favor de dirigirse con Control Escolar.</b></p>
    </div>
    <p><b>Alumno:</b> <?= esc(trim($al['nombre'].' '.$al['apellido_paterno'].' '.$al['apellido_materno'])) ?> &nbsp;
       <b>Matrícula:</b> <?= esc($al['matricula']) ?> &nbsp; <b>Grupo:</b> <?= esc($al['grupo_nombre'] ?? '—') ?></p>
    <table>
        <tr><th>Asignatura</th><th>Primer Parcial</th><th>Segundo Parcial</th><th>Tercer Parcial</th><th>Calificación Final</th><th>Faltas</th><th>Estatus</th></tr>
        <?php foreach ($b['materias'] as $mat => $d): ?>
        <tr>
            <td><?= esc($mat) ?></td>
            <td><?= esc($d['parciales'][1] ?? '—') ?></td>
            <td><?= esc($d['parciales'][2] ?? '—') ?></td>
            <td><?= esc($d['parciales'][3] ?? '—') ?></td>
            <td class="<?= $d['promedio'] !== null && $d['promedio'] < 6 ? 'promedio-bajo' : '' ?>"><?= esc($d['promedio'] ?? '—') ?></td>
            <td><?= esc($d['faltas'] ?? 0) ?></td>
            <td><?= esc($d['estatus']) ?></td>
        </tr>
        <?php endforeach; ?>
        <tr><td colspan="4"><b>Promedio General</b></td><td colspan="3"><b><?= esc($b['promedio_general'] ?? '—') ?></b> — Faltas totales: <b><?= esc($b['total_faltas'] ?? 0) ?></b></td></tr>
    </table>

<?php elseif ($tipo === 'irregulares'):
    $irr = construirIrregulares($pdo, $grupoId);
?>
    <table>
        <tr><th>Matrícula</th><th>Alumno</th><th>Materia</th><th>Promedio Original</th><th>Intentos de Regularización</th><th>Estatus</th></tr>
        <?php foreach ($irr as $al): foreach ($al['materias'] as $mat => $d): ?>
        <tr>
            <td><?= esc($al['matricula']) ?></td><td><?= esc($al['nombre']) ?></td><td><?= esc($mat) ?></td>
            <td class="promedio-bajo"><?= esc($d['promedio_original']) ?></td>
            <td><?= $d['intentos'] ? esc(implode(' · ', array_map(fn($i) => $i['oportunidad'].'ª: '.($i['calificacion'] ?? '—').($i['fecha'] ? ' ('.$i['fecha'].')' : ''), $d['intentos']))) : 'Sin intentos registrados' ?></td>
            <td><?= $d['regularizada'] ? 'Regularizada' : 'Pendiente' ?></td>
        </tr>
        <?php endforeach; endforeach; ?>
        <?php if (empty($irr)): ?><tr><td colspan="6">No hay alumnos irregulares en este grupo. 🎉</td></tr><?php endif; ?>
    </table>

<?php elseif ($tipo === 'honor'):
    $h = construirHonor($pdo, $grupoId);
?>
    <table>
        <tr><th>Lugar</th><th>Matrícula</th><th>Alumno</th><th>Promedio General</th></tr>
        <?php foreach ($h as $r): ?>
        <tr>
            <td><?= esc($r['lugar']) ?>°</td><td><?= esc($r['matricula']) ?></td><td><?= esc($r['nombre']) ?></td>
            <td><b><?= esc($r['etiqueta']) ?></b> — <?= esc($r['promedio_general']) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($h)): ?><tr><td colspan="4">Aún no hay calificaciones capturadas para este grupo.</td></tr><?php endif; ?>
    </table>

<?php elseif ($tipo === 'concentrado'):
    $c = construirConcentrado($pdo, $grupoId);
?>
    <table>
        <tr><th>Matrícula</th><th>Alumno</th><?php foreach ($c['materias'] as $m): ?><th><?= esc($m) ?></th><?php endforeach; ?><th>Promedio General</th><th>Faltas</th></tr>
        <?php foreach ($c['alumnos'] as $a): ?>
        <tr>
            <td><?= esc($a['matricula']) ?></td><td><?= esc($a['nombre']) ?></td>
            <?php foreach ($c['materias'] as $m): $prom = $a['materias'][$m]['promedio'] ?? null; ?>
                <td class="<?= $prom !== null && $prom < 6 ? 'promedio-bajo' : '' ?>"><?= esc($prom ?? '—') ?></td>
            <?php endforeach; ?>
            <td><b><?= esc($a['promedio_general'] ?? '—') ?></b></td>
            <td><?= esc($a['total_faltas'] ?? 0) ?></td>
        </tr>
        <?php endforeach; ?>
    </table>

<?php elseif ($tipo === 'reprobados'):
    $r = construirReprobados($pdo, $grupoId);
?>
    <table>
        <tr><th>Matrícula</th><th>Alumno</th><th>Materias Reprobadas</th><th>Total</th><th>Promedio General</th></tr>
        <?php foreach ($r as $a): ?>
        <tr>
            <td><?= esc($a['matricula']) ?></td><td><?= esc($a['nombre']) ?></td>
            <td><?= esc(implode(', ', array_map(fn($m) => $m['materia'].' ('.$m['promedio'].')', $a['materias_reprobadas']))) ?></td>
            <td><?= esc($a['total_reprobadas']) ?></td>
            <td class="promedio-bajo"><?= esc($a['promedio_general']) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($r)): ?><tr><td colspan="5">No hay alumnos reprobados en este grupo. 🎉</td></tr><?php endif; ?>
    </table>

<?php elseif ($tipo === 'indicadores'):
    $i = construirIndicadores($pdo, $grupoId);
?>
    <table>
        <tr><th>Indicador</th><th>Valor</th></tr>
        <tr><td>Total de alumnos</td><td><?= esc($i['total_alumnos']) ?></td></tr>
        <tr><td>Promedio grupal</td><td><?= esc($i['promedio_grupal'] ?? '—') ?></td></tr>
        <tr><td>Alumnos reprobados</td><td><?= esc($i['total_reprobados']) ?></td></tr>
        <tr><td>% de aprobación</td><td><?= esc($i['porcentaje_aprobacion'] ?? '—') ?>%</td></tr>
        <tr><td>% de reprobación</td><td><?= esc($i['porcentaje_reprobacion'] ?? '—') ?>%</td></tr>
    </table>
    <table>
        <tr><th>Materia</th><th>Promedio</th><th>% Aprobación</th></tr>
        <?php foreach ($i['por_materia'] as $mat => $d): ?>
        <tr><td><?= esc($mat) ?></td><td><?= esc($d['promedio'] ?? '—') ?></td><td><?= esc($d['porcentaje_aprobacion'] ?? '—') ?>%</td></tr>
        <?php endforeach; ?>
    </table>
<?php else: echo '<p>Tipo de reporte inválido.</p>'; endif; ?>

<div class="footer">EPO 381 — Documento generado automáticamente por el Sistema de Control Escolar.</div>
</body>
</html>
