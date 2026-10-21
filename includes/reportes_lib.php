<?php
/**
 * Funciones reutilizables para generar los reportes académicos
 * a partir de las calificaciones capturadas por el orientador.
 * Usadas tanto por api/orientador/reportes.php como por api/export/excel.php
 *
 * IMPORTANTE sobre ciclos escolares: los 9 grupos (salones "1 I".."3 III")
 * son un catálogo FIJO que se reutiliza cada ciclo escolar para la
 * generación nueva de alumnos. Por eso cada calificación guarda su propia
 * "fotografía" de ciclo_escolar/grado/semestre en el momento de la captura
 * (columnas ciclo_escolar, grado_captura, semestre_captura de la tabla
 * calificaciones) — estas funciones siempre filtran por esa fotografía en
 * vez de leer los datos (mutables) de la fila de "grupos", para que el
 * historial de un alumno no se vea afectado si su salón se reutiliza después.
 */

/** Nombre ordinal del semestre (1-6) tal como se usa en el encabezado oficial. */
function nombreSemestre(int $semestreGlobal): string {
    $nombres = [1 => 'PRIMER', 2 => 'SEGUNDO', 3 => 'TERCER', 4 => 'CUARTO', 5 => 'QUINTO', 6 => 'SEXTO'];
    return ($nombres[$semestreGlobal] ?? $semestreGlobal) . ' SEMESTRE';
}

/** Ciclo escolar ACTUAL de un grupo (tal como está hoy en la tabla grupos). */
function cicloActualDeGrupo(PDO $pdo, int $grupoId): ?string {
    $g = $pdo->prepare('SELECT ciclo_escolar FROM grupos WHERE id = :g');
    $g->execute(['g' => $grupoId]);
    $v = $g->fetchColumn();
    return $v !== false ? $v : null;
}

/**
 * Informe de Calificaciones (antes "boleta") de un alumno.
 * Si no se especifica $cicloEscolar, se usa el ciclo ACTUAL del grupo (vista
 * "en curso"). Si se especifica (por ejemplo desde el historial del alumno),
 * se usa exactamente ese ciclo, tal como quedó fotografiado en cada calificación.
 */
function construirBoleta(PDO $pdo, int $alumnoId, int $grupoId, ?string $cicloEscolar = null): array {
    $alu = $pdo->prepare('
        SELECT us.nombre, us.apellido_paterno, us.apellido_materno, al.matricula, g.nombre AS grupo_nombre
        FROM usuarios us JOIN alumnos al ON al.usuario_id = us.id
        LEFT JOIN grupos g ON g.id = al.grupo_id
        WHERE us.id = :id
    ');
    $alu->execute(['id' => $alumnoId]);
    $datosAlumno = $alu->fetch();

    $gradoMostrado = null; $semestreMostrado = null;
    if ($cicloEscolar === null) {
        $g = $pdo->prepare('SELECT ciclo_escolar, grado, semestre FROM grupos WHERE id = :g');
        $g->execute(['g' => $grupoId]);
        $grupoInfo = $g->fetch();
        $cicloEscolar    = $grupoInfo['ciclo_escolar'] ?? null;
        $gradoMostrado   = $grupoInfo['grado'] ?? null;
        $semestreMostrado= $grupoInfo['semestre'] ?? null;
    }

    $stmt = $pdo->prepare('
        SELECT m.nombre AS materia, p.orden, c.calificacion, c.faltas, c.grado_captura, c.semestre_captura
        FROM calificaciones c
        JOIN materias m ON m.id = c.materia_id
        JOIN periodos p ON p.id = c.periodo_id
        WHERE c.alumno_id = :a AND c.grupo_id = :g AND c.ciclo_escolar = :ciclo
        ORDER BY m.nombre, p.orden
    ');
    $stmt->execute(['a' => $alumnoId, 'g' => $grupoId, 'ciclo' => $cicloEscolar]);

    $materias = [];
    $totalFaltas = 0;
    foreach ($stmt->fetchAll() as $r) {
        $materias[$r['materia']]['parciales'][$r['orden']] = (float)$r['calificacion'];
        $materias[$r['materia']]['faltas'] = ($materias[$r['materia']]['faltas'] ?? 0) + (int)$r['faltas'];
        $totalFaltas += (int)$r['faltas'];
        if ($gradoMostrado === null && $r['grado_captura']) {
            $gradoMostrado = $r['grado_captura'];
            $semestreMostrado = $r['semestre_captura'];
        }
    }
    $sumaPromedios = 0; $n = 0;
    foreach ($materias as $mat => &$d) {
        $vals = array_values($d['parciales']);
        $d['promedio'] = $vals ? round(array_sum($vals) / count($vals), 1) : null;
        $d['estatus']  = $d['promedio'] !== null ? ($d['promedio'] >= 6 ? 'Aprobado' : 'Reprobado') : 'Pendiente';
        if ($d['promedio'] !== null) { $sumaPromedios += $d['promedio']; $n++; }
    }
    unset($d);

    if ($datosAlumno) {
        $datosAlumno['grado'] = $gradoMostrado;
        $datosAlumno['semestre'] = $semestreMostrado;
        $datosAlumno['ciclo_escolar'] = $cicloEscolar;
        $datosAlumno['semestre_global'] = $gradoMostrado ? ((int)$gradoMostrado - 1) * 2 + (int)$semestreMostrado : null;
    }

    return [
        'alumno'   => $datosAlumno,
        'materias' => $materias,
        'total_faltas' => $totalFaltas,
        'promedio_general' => $n ? round($sumaPromedios / $n, 1) : null,
    ];
}

/**
 * Concentrado de calificaciones del grupo, EN SU CICLO ESCOLAR ACTUAL (la
 * generación de alumnos que hoy está inscrita en ese salón).
 */
function construirConcentrado(PDO $pdo, int $grupoId): array {
    $ciclo = cicloActualDeGrupo($pdo, $grupoId);
    $stmt = $pdo->prepare('
        SELECT us.id AS alumno_id, al.matricula,
               (us.nombre || \' \' || us.apellido_paterno || \' \' || us.apellido_materno) AS nombre,
               m.id AS materia_id, m.nombre AS materia, p.orden, c.calificacion, c.faltas
        FROM alumnos al
        JOIN usuarios us ON us.id = al.usuario_id
        LEFT JOIN calificaciones c ON c.alumno_id = us.id AND c.grupo_id = al.grupo_id AND c.ciclo_escolar = :ciclo
        LEFT JOIN materias m ON m.id = c.materia_id
        LEFT JOIN periodos p ON p.id = c.periodo_id
        WHERE al.grupo_id = :g AND al.estatus = \'activo\'
        ORDER BY us.apellido_paterno, us.apellido_materno, us.nombre, m.nombre
    ');
    $stmt->execute(['g' => $grupoId, 'ciclo' => $ciclo]);
    $rows = $stmt->fetchAll();

    $alumnos = [];
    $materiasSet = [];
    foreach ($rows as $r) {
        $id = $r['alumno_id'];
        if (!isset($alumnos[$id])) {
            $alumnos[$id] = ['alumno_id' => $id, 'matricula' => $r['matricula'], 'nombre' => $r['nombre'], 'materias' => []];
        }
        if ($r['materia']) {
            $materiasSet[$r['materia']] = true;
            $alumnos[$id]['materias'][$r['materia']]['materia_id'] = $r['materia_id'];
            $alumnos[$id]['materias'][$r['materia']]['parciales'][$r['orden']] = (float)$r['calificacion'];
            $alumnos[$id]['materias'][$r['materia']]['faltas'] = ($alumnos[$id]['materias'][$r['materia']]['faltas'] ?? 0) + (int)$r['faltas'];
        }
    }
    foreach ($alumnos as &$a) {
        $sum = 0; $n = 0; $totalFaltas = 0;
        foreach ($a['materias'] as $mat => &$d) {
            $vals = array_values($d['parciales']);
            $d['promedio'] = $vals ? round(array_sum($vals) / count($vals), 1) : null;
            if ($d['promedio'] !== null) { $sum += $d['promedio']; $n++; }
            $totalFaltas += $d['faltas'] ?? 0;
        }
        unset($d);
        $a['promedio_general'] = $n ? round($sum / $n, 1) : null;
        $a['total_faltas'] = $totalFaltas;
    }
    unset($a);

    return [
        'ciclo_escolar' => $ciclo,
        'materias' => array_keys($materiasSet),
        'alumnos'  => array_values($alumnos),
    ];
}

function construirReprobados(PDO $pdo, int $grupoId): array {
    $concentrado = construirConcentrado($pdo, $grupoId);
    $reprobados = [];
    foreach ($concentrado['alumnos'] as $a) {
        $materiasReprobadas = [];
        foreach ($a['materias'] as $mat => $d) {
            if ($d['promedio'] !== null && $d['promedio'] < 6) $materiasReprobadas[] = ['materia' => $mat, 'materia_id' => $d['materia_id'], 'promedio' => $d['promedio']];
        }
        if (!empty($materiasReprobadas)) {
            $reprobados[] = [
                'alumno_id' => $a['alumno_id'],
                'matricula' => $a['matricula'],
                'nombre'    => $a['nombre'],
                'promedio_general' => $a['promedio_general'],
                'materias_reprobadas' => $materiasReprobadas,
                'total_reprobadas' => count($materiasReprobadas),
            ];
        }
    }
    usort($reprobados, fn($x, $y) => $y['total_reprobadas'] <=> $x['total_reprobadas']);
    return $reprobados;
}

/**
 * Cuadro de Honor del grupo: ordena a los alumnos por promedio general (desc)
 * y asigna "Primer/Segundo/Tercer Lugar" (compartido si hay empate) y
 * "Mención Especial" al resto de los que aparecen en el listado (top $topN).
 * Solo se consideran alumnos con al menos una calificación capturada.
 */
function construirHonor(PDO $pdo, int $grupoId, int $topN = 10): array {
    $concentrado = construirConcentrado($pdo, $grupoId);
    $conPromedio = array_values(array_filter($concentrado['alumnos'], fn($a) => $a['promedio_general'] !== null));
    usort($conPromedio, fn($x, $y) => $y['promedio_general'] <=> $x['promedio_general']);

    $resultado = [];
    $lugar = 0;        // lugar real (1, 2, 3...)
    $anterior = null;  // promedio anterior, para detectar empates
    foreach ($conPromedio as $i => $a) {
        if ($a['promedio_general'] !== $anterior) $lugar = $i + 1; // empate = mismo lugar
        $anterior = $a['promedio_general'];
        if ($lugar > $topN) break;

        $etiqueta = match (true) {
            $lugar === 1 => 'Primer Lugar',
            $lugar === 2 => 'Segundo Lugar',
            $lugar === 3 => 'Tercer Lugar',
            default      => 'Mención Especial',
        };
        $resultado[] = [
            'lugar'    => $lugar,
            'etiqueta' => $etiqueta,
            'alumno_id'=> $a['alumno_id'],
            'matricula'=> $a['matricula'],
            'nombre'   => $a['nombre'],
            'promedio_general' => $a['promedio_general'],
        ];
    }
    return $resultado;
}

function construirIndicadores(PDO $pdo, int $grupoId): array {
    $concentrado = construirConcentrado($pdo, $grupoId);
    $totalAlumnos = count($concentrado['alumnos']);
    $promedios = array_filter(array_map(fn($a) => $a['promedio_general'], $concentrado['alumnos']), fn($v) => $v !== null);
    $promedioGrupal = $promedios ? round(array_sum($promedios) / count($promedios), 1) : null;

    $reprobados = construirReprobados($pdo, $grupoId);
    $totalReprobados = count($reprobados);

    // Indicador por materia: promedio y % de aprobación
    $porMateria = [];
    foreach ($concentrado['materias'] as $mat) {
        $vals = []; $aprobados = 0; $totalConCalif = 0;
        foreach ($concentrado['alumnos'] as $a) {
            $prom = $a['materias'][$mat]['promedio'] ?? null;
            if ($prom !== null) {
                $vals[] = $prom; $totalConCalif++;
                if ($prom >= 6) $aprobados++;
            }
        }
        $porMateria[$mat] = [
            'promedio' => $vals ? round(array_sum($vals) / count($vals), 1) : null,
            'porcentaje_aprobacion' => $totalConCalif ? round(($aprobados / $totalConCalif) * 100, 1) : null,
        ];
    }

    return [
        'total_alumnos'          => $totalAlumnos,
        'promedio_grupal'        => $promedioGrupal,
        'total_reprobados'       => $totalReprobados,
        'porcentaje_reprobacion' => $totalAlumnos ? round(($totalReprobados / $totalAlumnos) * 100, 1) : null,
        'porcentaje_aprobacion'  => $totalAlumnos ? round((($totalAlumnos - $totalReprobados) / $totalAlumnos) * 100, 1) : null,
        'por_materia'            => $porMateria,
    ];
}

/**
 * Seguimiento de Irregulares: alumnos reprobados del ciclo actual del grupo,
 * junto con los intentos de regularización (extraordinarios) que ya tengan
 * registrados en ese mismo ciclo, indicando si ya se regularizaron o siguen
 * pendientes.
 */
function construirIrregulares(PDO $pdo, int $grupoId): array {
    $ciclo = cicloActualDeGrupo($pdo, $grupoId);
    $reprobados = construirReprobados($pdo, $grupoId);

    $stmtExtra = $pdo->prepare('
        SELECT e.materia_id, m.nombre AS materia, e.oportunidad, e.calificacion, e.fecha_regularizacion
        FROM extraordinarios e JOIN materias m ON m.id = e.materia_id
        WHERE e.alumno_id = :a AND e.grupo_id = :g AND e.ciclo_escolar = :ciclo
        ORDER BY m.nombre, e.oportunidad
    ');

    $resultado = [];
    foreach ($reprobados as $r) {
        $stmtExtra->execute(['a' => $r['alumno_id'], 'g' => $grupoId, 'ciclo' => $ciclo]);
        $intentos = $stmtExtra->fetchAll();

        $porMateria = [];
        foreach ($r['materias_reprobadas'] as $mr) $porMateria[$mr['materia']] = ['materia_id' => $mr['materia_id'], 'promedio_original' => $mr['promedio'], 'intentos' => []];
        foreach ($intentos as $i) {
            if (isset($porMateria[$i['materia']])) {
                $porMateria[$i['materia']]['intentos'][] = [
                    'oportunidad' => (int)$i['oportunidad'],
                    'calificacion' => $i['calificacion'] !== null ? (float)$i['calificacion'] : null,
                    'fecha' => $i['fecha_regularizacion'],
                ];
            }
        }
        foreach ($porMateria as $mat => &$d) {
            $ultimaAprobada = false;
            foreach ($d['intentos'] as $intento) {
                if ($intento['calificacion'] !== null && $intento['calificacion'] >= 6) $ultimaAprobada = true;
            }
            $d['regularizada'] = $ultimaAprobada;
        }
        unset($d);

        $resultado[] = [
            'alumno_id' => $r['alumno_id'],
            'matricula' => $r['matricula'],
            'nombre'    => $r['nombre'],
            'materias'  => $porMateria,
            'pendientes'=> count(array_filter($porMateria, fn($d) => !$d['regularizada'])),
        ];
    }
    return $resultado;
}
