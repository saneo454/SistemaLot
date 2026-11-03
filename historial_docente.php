<?php
// =========================================================================
// HISTORIAL DEL DOCENTE
// =========================================================================
// Se incluye desde historial.php cuando el usuario en sesión es docente.
// Dos pestañas:
//   - Asistencia:     matriz alumno x sesión (asistió / no asistió)
//   - Calificaciones: captura de parcial 1, 2 y 3 con promedio
// Los grupos se eligen en las pestañas del costado derecho.
// =========================================================================

require_once __DIR__ . '/includes/docente_grupos.php';

const MAX_SESIONES_MATRIZ = 40;   // columnas máximas en la matriz
const NOTA_EXCELENCIA = 90;
const NOTA_REPROBATORIA = 70;     // por debajo de esto se marca en riesgo
const UMBRAL_ASISTENCIA_DOC = 80;

$error = '';
$success = '';
$grupos = [];
$calificacionesDisponibles = true;

// ---------------------------------------------------------------------
// Parámetros de navegación
// ---------------------------------------------------------------------
$tab = (($_GET['tab'] ?? '') === 'calificaciones') ? 'calificaciones' : 'asistencia';

$fechaDesde = $_GET['desde'] ?? '';
$fechaHasta = $_GET['hasta'] ?? '';
$fechaDesde = preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaDesde) ? $fechaDesde : '';
$fechaHasta = preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaHasta) ? $fechaHasta : '';

try {
    $grupos = cargarGruposDocente($pdo, $matricula);
} catch (PDOException $e) {
    $error = mensajeErrorBD($e, 'cargar tus grupos', $rol, 'historial_docente.php');
}

$gSel = $_GET['g'] ?? '';
if (!isset($grupos[$gSel])) {
    reset($grupos);
    $gSel = empty($grupos) ? '' : (string)key($grupos);
}
$grupoSel = $gSel !== '' ? $grupos[$gSel] : null;

$mSel = $_GET['m'] ?? '';
if ($grupoSel && !isset($grupoSel['materias'][$mSel])) {
    reset($grupoSel['materias']);
    $mSel = (string)key($grupoSel['materias']);
}
$materiaSel = ($grupoSel && $mSel !== '') ? $grupoSel['materias'][$mSel] : null;

/**
 * URL del historial conservando la navegación actual.
 */
$url = function (array $cambios = []) use (&$tab, &$gSel, &$mSel, &$fechaDesde, &$fechaHasta) {
    $p = ['tab' => $tab, 'g' => $gSel, 'm' => $mSel];
    if ($fechaDesde !== '') { $p['desde'] = $fechaDesde; }
    if ($fechaHasta !== '') { $p['hasta'] = $fechaHasta; }
    foreach ($cambios as $k => $v) {
        if ($v === null) { unset($p[$k]); } else { $p[$k] = $v; }
    }
    return 'historial.php?' . http_build_query($p);
};

// ---------------------------------------------------------------------
// Funciones de datos
// ---------------------------------------------------------------------

/**
 * Alumnos inscritos en cualquier clase de la materia.
 * matricula => ['matricula','nombre','estado','clases'=>[claseId=>true]]
 */
function hdAlumnosDeMateria(PDO $pdo, array $idsClases) {
    if (empty($idsClases)) {
        return [];
    }
    $in = implode(',', array_fill(0, count($idsClases), '?'));
    $stmt = $pdo->prepare("
        SELECT i.alumno_id, i.clase_id, u.nombre, u.estado
        FROM inscripciones i
        JOIN usuarios u ON u.matricula = i.alumno_id
        WHERE i.clase_id IN ($in)
        ORDER BY u.nombre ASC
    ");
    $stmt->execute($idsClases);

    $alumnos = [];
    foreach ($stmt->fetchAll() as $r) {
        $m = $r['alumno_id'];
        if (!isset($alumnos[$m])) {
            $alumnos[$m] = ['matricula' => $m, 'nombre' => $r['nombre'], 'estado' => $r['estado'], 'clases' => []];
        }
        $alumnos[$m]['clases'][(int)$r['clase_id']] = true;
    }
    return $alumnos;
}

/**
 * Matriz de asistencia alumno x sesión de la materia.
 *
 * Una sesión es un día (en una clase) con al menos un registro de asistencia.
 * Si un alumno inscrito no tiene registro ese día, cuenta como falta, igual
 * que en "Mis Clases".
 */
function hdMatrizAsistencia(PDO $pdo, array $alumnos, array $idsClases, $desde, $hasta) {
    $resultado = ['sesiones' => [], 'celdas' => [], 'resumen' => []];
    if (empty($idsClases)) {
        return $resultado;
    }
    $in = implode(',', array_fill(0, count($idsClases), '?'));

    $sql = "SELECT DISTINCT clase_id, fecha FROM asistencias WHERE clase_id IN ($in)";
    $params = $idsClases;
    if ($desde !== '') { $sql .= " AND fecha >= ?"; $params[] = $desde; }
    if ($hasta !== '') { $sql .= " AND fecha <= ?"; $params[] = $hasta; }
    $sql .= " ORDER BY fecha DESC, clase_id DESC LIMIT " . MAX_SESIONES_MATRIZ;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $sesiones = array_reverse($stmt->fetchAll());   // de la más antigua a la más reciente
    $resultado['sesiones'] = $sesiones;

    if (empty($sesiones)) {
        return $resultado;
    }

    // Registros de esas sesiones
    $fechas = array_column($sesiones, 'fecha');
    $stmt = $pdo->prepare("
        SELECT usuario_id, clase_id, fecha, estado
        FROM asistencias
        WHERE clase_id IN ($in) AND fecha BETWEEN ? AND ?
    ");
    $stmt->execute(array_merge($idsClases, [min($fechas), max($fechas)]));
    $registros = [];
    foreach ($stmt->fetchAll() as $r) {
        $registros[(int)$r['clase_id'] . '|' . $r['fecha'] . '|' . $r['usuario_id']] = $r['estado'];
    }

    foreach ($alumnos as $m => $al) {
        $aplicables = 0; $asistidas = 0; $retardos = 0; $justificadas = 0;
        foreach ($sesiones as $i => $s) {
            $cid = (int)$s['clase_id'];
            if (!isset($al['clases'][$cid])) {
                $resultado['celdas'][$m][$i] = 'na';   // no está inscrito en esa clase
                continue;
            }
            $estado = $registros[$cid . '|' . $s['fecha'] . '|' . $m] ?? 'falta';
            $resultado['celdas'][$m][$i] = $estado;
            $aplicables++;
            if (in_array($estado, ['presente', 'retardo', 'justificado'], true)) { $asistidas++; }
            if ($estado === 'retardo') { $retardos++; }
            if ($estado === 'justificado') { $justificadas++; }
        }
        $resultado['resumen'][$m] = [
            'sesiones' => $aplicables, 'asistidas' => $asistidas, 'retardos' => $retardos,
            'justificadas' => $justificadas, 'faltas' => max(0, $aplicables - $asistidas),
            'pct' => $aplicables > 0 ? round(($asistidas / $aplicables) * 100) : null,
        ];
    }
    return $resultado;
}

/**
 * Calificaciones guardadas: matricula => [1 => nota, 2 => nota, 3 => nota]
 */
function hdCargarCalificaciones(PDO $pdo, $docente, $materia) {
    $stmt = $pdo->prepare("SELECT alumno_id, parcial, calificacion FROM calificaciones WHERE docente_id = :d AND materia = :m");
    $stmt->execute(['d' => $docente, 'm' => $materia]);
    $notas = [];
    foreach ($stmt->fetchAll() as $r) {
        $notas[$r['alumno_id']][(int)$r['parcial']] = (float)$r['calificacion'];
    }
    return $notas;
}

/** Promedio de las calificaciones capturadas (null si no hay ninguna). */
function hdPromedio(array $parciales) {
    $valores = array_filter($parciales, function ($v) { return $v !== null && $v !== ''; });
    return count($valores) > 0 ? array_sum($valores) / count($valores) : null;
}

/** Formato de número sin ceros sobrantes: 85 -> "85", 86.5 -> "86.5". */
function hdNum($n) {
    if ($n === null || $n === '') { return ''; }
    $r = round((float)$n, 1);
    return (floor($r) == $r) ? (string)(int)$r : number_format($r, 1, '.', '');
}

/** Clase CSS según el valor de la calificación. */
function hdClaseNota($valor) {
    if ($valor === null || $valor === '') { return 'nota-vacia'; }
    if ($valor >= NOTA_EXCELENCIA) { return 'nota-alta'; }
    if ($valor >= NOTA_REPROBATORIA) { return 'nota-ok'; }
    return 'nota-baja';
}

$nombresDiaCorto = ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'];

// ---------------------------------------------------------------------
// Alumnos de la materia seleccionada
// ---------------------------------------------------------------------
$alumnos = [];
if ($materiaSel && !$error) {
    try {
        $alumnos = hdAlumnosDeMateria($pdo, $materiaSel['clases']);
    } catch (PDOException $e) {
        $error = mensajeErrorBD($e, 'cargar los alumnos', $rol, 'historial_docente.php');
    }
}

// ---------------------------------------------------------------------
// POST: guardar calificaciones
// ---------------------------------------------------------------------
$notasForm = null;   // valores enviados, para volver a mostrarlos si hay errores

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'guardar_calificaciones') {
    $tab = 'calificaciones';

    if (!$materiaSel || $error) {
        $error = $error ?: 'No se encontró el grupo o la materia que intentas calificar.';
    } else {
        $enviadas = (isset($_POST['notas']) && is_array($_POST['notas'])) ? $_POST['notas'] : [];
        $aGuardar = [];
        $aBorrar = [];
        $invalidas = [];
        $notasForm = [];

        foreach ($alumnos as $m => $al) {
            for ($p = 1; $p <= 3; $p++) {
                $raw = $enviadas[$m][$p] ?? '';
                $raw = is_string($raw) ? trim(str_replace(',', '.', $raw)) : '';
                $notasForm[$m][$p] = $raw;

                if ($raw === '') {
                    $aBorrar[] = [$m, $p];
                } elseif (!is_numeric($raw) || (float)$raw < 0 || (float)$raw > 100) {
                    $invalidas[] = $al['nombre'] . ' (parcial ' . $p . ')';
                } else {
                    $aGuardar[] = [$m, $p, round((float)$raw, 2)];
                }
            }
        }

        if (!empty($invalidas)) {
            $error = 'Las calificaciones deben estar entre 0 y 100. Revisa: ' . implode(', ', array_slice($invalidas, 0, 5))
                   . (count($invalidas) > 5 ? '…' : '') . '. No se guardó nada.';
        } else {
            try {
                $pdo->beginTransaction();

                $up = $pdo->prepare("
                    INSERT INTO calificaciones (docente_id, materia, alumno_id, parcial, calificacion)
                    VALUES (:d, :mat, :a, :p, :c)
                    ON DUPLICATE KEY UPDATE calificacion = VALUES(calificacion)
                ");
                foreach ($aGuardar as $g) {
                    $up->execute(['d' => $matricula, 'mat' => $materiaSel['nombre'], 'a' => $g[0], 'p' => $g[1], 'c' => $g[2]]);
                }

                $del = $pdo->prepare("DELETE FROM calificaciones WHERE docente_id = :d AND materia = :mat AND alumno_id = :a AND parcial = :p");
                foreach ($aBorrar as $b) {
                    $del->execute(['d' => $matricula, 'mat' => $materiaSel['nombre'], 'a' => $b[0], 'p' => $b[1]]);
                }

                $pdo->commit();

                header('Location: ' . $url(['tab' => 'calificaciones', 'guardado' => 1]));
                exit;
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if ($e->getCode() === '42S02') {
                    $calificacionesDisponibles = false;
                    error_log('[historial_docente] ' . $e->getMessage());
                } else {
                    $error = mensajeErrorBD($e, 'guardar las calificaciones', $rol, 'historial_docente.php');
                }
            }
        }
    }
}

if (isset($_GET['guardado'])) {
    $success = 'Calificaciones guardadas correctamente.';
}

// ---------------------------------------------------------------------
// Datos según la pestaña (o la exportación solicitada)
// ---------------------------------------------------------------------
$export = $_GET['export'] ?? '';
$matriz = ['sesiones' => [], 'celdas' => [], 'resumen' => []];
$notas = [];

if ($materiaSel && !$error) {
    try {
        if ($tab === 'asistencia' || $export === 'asistencia') {
            $matriz = hdMatrizAsistencia($pdo, $alumnos, $materiaSel['clases'], $fechaDesde, $fechaHasta);
        }
        if ($tab === 'calificaciones' || $export === 'calificaciones') {
            try {
                $notas = hdCargarCalificaciones($pdo, $matricula, $materiaSel['nombre']);
            } catch (PDOException $e) {
                if ($e->getCode() === '42S02') {
                    $calificacionesDisponibles = false;
                    error_log('[historial_docente] ' . $e->getMessage());
                } else {
                    throw $e;
                }
            }
        }
    } catch (PDOException $e) {
        $error = mensajeErrorBD($e, 'cargar el historial', $rol, 'historial_docente.php');
    }
}

// Si hubo errores al guardar, se muestran los valores que escribió el docente
$valoresCalif = [];
foreach ($alumnos as $m => $al) {
    for ($p = 1; $p <= 3; $p++) {
        if ($notasForm !== null) {
            $valoresCalif[$m][$p] = $notasForm[$m][$p] ?? '';
        } else {
            $valoresCalif[$m][$p] = isset($notas[$m][$p]) ? hdNum($notas[$m][$p]) : '';
        }
    }
}

// ---------------------------------------------------------------------
// Exportaciones CSV
// ---------------------------------------------------------------------
if ($materiaSel && !$error && in_array($export, ['asistencia', 'calificaciones'], true)) {
    if (ob_get_level()) {
        ob_end_clean();
    }
    $nombreArchivo = ($export === 'asistencia' ? 'asistencia_' : 'calificaciones_')
                   . preg_replace('/[^A-Za-z0-9]+/', '_', $materiaSel['nombre']) . '_' . date('Ymd_His') . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=' . $nombreArchivo);
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

    if ($export === 'asistencia') {
        $cab = ['Matrícula', 'Alumno'];
        foreach ($matriz['sesiones'] as $s) {
            $cab[] = date('d/m/Y', strtotime($s['fecha']));
        }
        array_push($cab, 'Asistencias', 'Retardos', 'Faltas', '% Asistencia');
        fputcsv($out, $cab);

        $textos = ['presente' => 'Asistió', 'retardo' => 'Retardo', 'falta' => 'Faltó', 'justificado' => 'Justificado', 'na' => 'N/A'];
        foreach ($alumnos as $m => $al) {
            $fila = [$m, $al['nombre']];
            foreach ($matriz['sesiones'] as $i => $s) {
                $fila[] = $textos[$matriz['celdas'][$m][$i] ?? 'na'];
            }
            $r = $matriz['resumen'][$m] ?? ['asistidas' => 0, 'retardos' => 0, 'faltas' => 0, 'pct' => null];
            array_push($fila, $r['asistidas'], $r['retardos'], $r['faltas'], $r['pct'] === null ? '' : $r['pct'] . '%');
            fputcsv($out, $fila);
        }
    } else {
        fputcsv($out, ['Materia', $materiaSel['nombre']]);
        fputcsv($out, ['Grupo', etiquetaGrupo($grupoSel) . ' - ' . ($grupoSel['carrera'] ?: 'Sin carrera')]);
        fputcsv($out, []);
        fputcsv($out, ['Matrícula', 'Alumno', 'Parcial 1', 'Parcial 2', 'Parcial 3', 'Promedio']);
        foreach ($alumnos as $m => $al) {
            $p = [$notas[$m][1] ?? null, $notas[$m][2] ?? null, $notas[$m][3] ?? null];
            fputcsv($out, [$m, $al['nombre'], hdNum($p[0]), hdNum($p[1]), hdNum($p[2]), hdNum(hdPromedio($p))]);
        }
    }
    fclose($out);
    exit;
}

// ---------------------------------------------------------------------
// Conteo de alumnos por grupo (para las pestañas del costado)
// ---------------------------------------------------------------------
$alumnosPorGrupo = [];
if (!empty($grupos)) {
    try {
        $claseAGrupo = [];
        foreach ($grupos as $gid => $g) {
            foreach ($g['materias'] as $mat) {
                foreach ($mat['clases'] as $cid) {
                    $claseAGrupo[$cid] = $gid;
                }
            }
        }
        $ids = array_keys($claseAGrupo);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT clase_id, alumno_id FROM inscripciones WHERE clase_id IN ($in)");
        $stmt->execute($ids);
        foreach ($stmt->fetchAll() as $r) {
            $alumnosPorGrupo[$claseAGrupo[(int)$r['clase_id']]][$r['alumno_id']] = true;
        }
    } catch (PDOException $e) {
        error_log('[historial_docente] ' . $e->getMessage());
    }
}

// ---------------------------------------------------------------------
// Totales para las tarjetas
// ---------------------------------------------------------------------
// Asistencia
$totSesiones = count($matriz['sesiones']);
$sumaPct = 0; $conPct = 0; $bajoUmbral = 0;
foreach ($matriz['resumen'] as $r) {
    if ($r['pct'] !== null) {
        $sumaPct += $r['pct']; $conPct++;
        if ($r['pct'] < UMBRAL_ASISTENCIA_DOC) { $bajoUmbral++; }
    }
}
$promAsistencia = $conPct > 0 ? round($sumaPct / $conPct) : null;

// Calificaciones
$promediosAlumnos = [];
foreach ($alumnos as $m => $al) {
    $pr = [];
    for ($p = 1; $p <= 3; $p++) {
        $v = $valoresCalif[$m][$p] ?? '';
        $pr[$p] = ($v !== '' && is_numeric($v)) ? (float)$v : null;
    }
    $promediosAlumnos[$m] = hdPromedio($pr);
}
$conPromedio = array_filter($promediosAlumnos, function ($v) { return $v !== null; });
$promGrupal = count($conPromedio) > 0 ? array_sum($conPromedio) / count($conPromedio) : null;
$cuentaAltos = count(array_filter($conPromedio, function ($v) { return $v >= NOTA_EXCELENCIA; }));
$cuentaBajos = count(array_filter($conPromedio, function ($v) { return $v < NOTA_REPROBATORIA; }));

require_once __DIR__ . '/header.php';
?>

<div class="top-bar">
    <div class="page-title">
        <span class="page-eyebrow">Docencia</span>
        <h2>Historial de Mis Alumnos</h2>
        <p>Consulta quién asistió a cada sesión y captura las calificaciones de tus grupos.</p>
    </div>
</div>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger">
        <i class="fa-solid fa-circle-exclamation"></i>
        <span><?php echo htmlspecialchars($error); ?></span>
    </div>
<?php endif; ?>

<?php if (!empty($success)): ?>
    <div class="alert alert-success">
        <i class="fa-solid fa-circle-check"></i>
        <span><?php echo htmlspecialchars($success); ?></span>
    </div>
<?php endif; ?>

<?php if (empty($grupos)): ?>

    <div class="panel-card">
        <div style="text-align: center; color: var(--text-muted); padding: 40px 20px;">
            <i class="fa-solid fa-users-slash" style="font-size: 2rem; margin-bottom: 12px; display: block;"></i>
            Todavía no tienes grupos asignados.<br>
            Pídele al administrador que te asigne tus clases en Horarios / Clases.
        </div>
    </div>

<?php else: ?>

<div class="hd-layout">

    <!-- ===================== CONTENIDO PRINCIPAL ===================== -->
    <div class="hd-main">
        <div class="panel-card">

            <div class="panel-header" style="flex-wrap: wrap; gap: 0.75rem;">
                <div>
                    <div class="panel-title">
                        <i class="fa-solid fa-book-open icon-purple" style="margin-right: 8px;"></i>
                        <?php echo htmlspecialchars($materiaSel['nombre']); ?>
                    </div>
                    <div style="font-size: 0.82rem; color: var(--text-muted); margin-top: 4px;">
                        <?php echo htmlspecialchars(etiquetaGrupo($grupoSel)); ?> ·
                        <?php echo htmlspecialchars($grupoSel['carrera'] ?: 'Sin carrera definida'); ?>
                    </div>
                </div>
            </div>

            <?php if (count($grupoSel['materias']) > 1): ?>
                <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                    <span style="font-size: 0.8rem; color: var(--text-muted);">Materia:</span>
                    <?php foreach ($grupoSel['materias'] as $mat): ?>
                        <a href="<?php echo htmlspecialchars($url(['m' => $mat['id'], 'guardado' => null])); ?>"
                           class="tab-btn <?php echo $mat['id'] === $mSel ? 'active' : ''; ?>">
                            <?php echo htmlspecialchars($mat['nombre']); ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- PESTAÑAS PRINCIPALES -->
            <div class="tabs" role="tablist">
                <a href="<?php echo htmlspecialchars($url(['tab' => 'asistencia', 'guardado' => null])); ?>"
                   class="tab-btn <?php echo $tab === 'asistencia' ? 'active' : ''; ?>">
                    <span><i class="fa-solid fa-clipboard-user"></i> Asistencia</span>
                </a>
                <a href="<?php echo htmlspecialchars($url(['tab' => 'calificaciones', 'guardado' => null])); ?>"
                   class="tab-btn <?php echo $tab === 'calificaciones' ? 'active' : ''; ?>">
                    <span><i class="fa-solid fa-pen-to-square"></i> Calificaciones</span>
                </a>
            </div>

            <?php if (empty($alumnos)): ?>

                <div style="text-align: center; color: var(--text-muted); padding: 30px 10px;">
                    <i class="fa-solid fa-user-plus" style="font-size: 1.6rem; margin-bottom: 10px; display: block;"></i>
                    Esta materia aún no tiene alumnos inscritos.<br>
                    Pídele al administrador que los inscriba desde Inscripciones.
                </div>

            <?php elseif ($tab === 'asistencia'): ?>

                <!-- ============ PESTAÑA: ASISTENCIA ============ -->
                <form method="GET" action="historial.php" class="hd-filtros">
                    <input type="hidden" name="tab" value="asistencia">
                    <input type="hidden" name="g" value="<?php echo htmlspecialchars($gSel); ?>">
                    <input type="hidden" name="m" value="<?php echo htmlspecialchars($mSel); ?>">
                    <div class="form-group" style="margin-bottom: 0;">
                        <label for="desde">DESDE</label>
                        <input type="date" id="desde" name="desde" class="form-date" value="<?php echo htmlspecialchars($fechaDesde); ?>">
                    </div>
                    <div class="form-group" style="margin-bottom: 0;">
                        <label for="hasta">HASTA</label>
                        <input type="date" id="hasta" name="hasta" class="form-date" value="<?php echo htmlspecialchars($fechaHasta); ?>">
                    </div>
                    <button type="submit" class="btn-filter"><i class="fa-solid fa-filter"></i> Filtrar</button>
                    <a href="<?php echo htmlspecialchars($url(['desde' => null, 'hasta' => null])); ?>" class="btn-reset" style="display: inline-flex; align-items: center; text-decoration: none;">Limpiar</a>
                    <a href="<?php echo htmlspecialchars($url(['export' => 'asistencia'])); ?>" class="btn-filter" style="text-decoration: none; margin-left: auto;">
                        <i class="fa-solid fa-file-csv"></i> Exportar CSV
                    </a>
                </form>

                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-info">
                            <span class="stat-value"><?php echo count($alumnos); ?></span>
                            <span class="stat-label">Alumnos</span>
                        </div>
                        <div class="stat-icon stat-blue"><i class="fa-solid fa-user-graduate"></i></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-info">
                            <span class="stat-value"><?php echo $totSesiones; ?></span>
                            <span class="stat-label">Sesiones registradas</span>
                        </div>
                        <div class="stat-icon stat-purple"><i class="fa-solid fa-calendar-days"></i></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-info">
                            <span class="stat-value"><?php echo $promAsistencia === null ? '—' : $promAsistencia . '%'; ?></span>
                            <span class="stat-label">Asistencia promedio</span>
                        </div>
                        <div class="stat-icon stat-green"><i class="fa-solid fa-percent"></i></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-info">
                            <span class="stat-value"><?php echo $bajoUmbral; ?></span>
                            <span class="stat-label">Bajo <?php echo UMBRAL_ASISTENCIA_DOC; ?>% de asistencia</span>
                        </div>
                        <div class="stat-icon stat-danger"><i class="fa-solid fa-triangle-exclamation"></i></div>
                    </div>
                </div>

                <?php if ($totSesiones === 0): ?>
                    <div style="text-align: center; color: var(--text-muted); padding: 30px 10px;">
                        <i class="fa-solid fa-calendar-xmark" style="font-size: 1.6rem; margin-bottom: 10px; display: block;"></i>
                        Todavía no hay sesiones registradas<?php echo ($fechaDesde || $fechaHasta) ? ' con esas fechas' : ''; ?>.
                    </div>
                <?php else: ?>

                    <div class="hd-leyenda">
                        <span><span class="cel cel-presente"><i class="fa-solid fa-check"></i></span> Asistió</span>
                        <span><span class="cel cel-retardo"><i class="fa-solid fa-clock"></i></span> Retardo</span>
                        <span><span class="cel cel-justificado"><i class="fa-solid fa-file-circle-check"></i></span> Justificado</span>
                        <span><span class="cel cel-falta"><i class="fa-solid fa-xmark"></i></span> No asistió</span>
                        <span><span class="cel cel-na">·</span> No inscrito en esa sesión</span>
                    </div>

                    <div class="table-responsive">
                        <table class="custom-table matriz-asistencia">
                            <thead>
                                <tr>
                                    <th>Alumno</th>
                                    <?php foreach ($matriz['sesiones'] as $s): ?>
                                        <?php $ts = strtotime($s['fecha']); ?>
                                        <th class="col-fecha" title="<?php echo date('d/m/Y', $ts); ?>">
                                            <?php echo date('d/m', $ts); ?>
                                            <small><?php echo $nombresDiaCorto[(int)date('N', $ts) - 1]; ?></small>
                                        </th>
                                    <?php endforeach; ?>
                                    <th class="col-total">Asist.</th>
                                    <th class="col-total">Faltas</th>
                                    <th class="col-total">%</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($alumnos as $m => $al): ?>
                                    <?php $r = $matriz['resumen'][$m]; ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($al['nombre']); ?></strong>
                                            <div style="font-size: 0.72rem; color: var(--text-muted);"><?php echo htmlspecialchars($m); ?></div>
                                        </td>
                                        <?php foreach ($matriz['sesiones'] as $i => $s): ?>
                                            <?php
                                                $estado = $matriz['celdas'][$m][$i] ?? 'na';
                                                $iconos = [
                                                    'presente' => ['fa-check', 'Asistió'],
                                                    'retardo' => ['fa-clock', 'Retardo'],
                                                    'justificado' => ['fa-file-circle-check', 'Falta justificada'],
                                                    'falta' => ['fa-xmark', 'No asistió'],
                                                ];
                                            ?>
                                            <td class="col-fecha">
                                                <?php if ($estado === 'na'): ?>
                                                    <span class="cel cel-na" title="No inscrito en esta sesión">·</span>
                                                <?php else: ?>
                                                    <span class="cel cel-<?php echo $estado; ?>" title="<?php echo $iconos[$estado][1] . ' · ' . date('d/m/Y', strtotime($s['fecha'])); ?>">
                                                        <i class="fa-solid <?php echo $iconos[$estado][0]; ?>"></i>
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                        <?php endforeach; ?>
                                        <td class="col-total"><?php echo $r['asistidas']; ?>/<?php echo $r['sesiones']; ?></td>
                                        <td class="col-total">
                                            <strong style="color: <?php echo $r['faltas'] > 0 ? 'var(--danger)' : 'inherit'; ?>;"><?php echo $r['faltas']; ?></strong>
                                        </td>
                                        <td class="col-total">
                                            <?php
                                                $pct = $r['pct'];
                                                $b = $pct === null ? 'neutro' : ($pct >= UMBRAL_ASISTENCIA_DOC ? 'presente' : ($pct >= 60 ? 'retardo' : 'falta'));
                                            ?>
                                            <span class="badge badge-<?php echo $b; ?>"><?php echo $pct === null ? '—' : $pct . '%'; ?></span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <p style="font-size: 0.78rem; color: var(--text-muted);">
                        Una sesión cuenta cuando se registró asistencia en esa clase ese día; si un alumno inscrito no aparece, se marca como no asistió.
                        <?php if ($totSesiones >= MAX_SESIONES_MATRIZ): ?>
                            Se muestran las últimas <?php echo MAX_SESIONES_MATRIZ; ?> sesiones; usa las fechas para ver otras.
                        <?php endif; ?>
                    </p>
                <?php endif; ?>

            <?php else: ?>

                <!-- ============ PESTAÑA: CALIFICACIONES ============ -->
                <?php if (!$calificacionesDisponibles): ?>

                    <div class="alert alert-warning">
                        <i class="fa-solid fa-triangle-exclamation"></i>
                        <span>Las calificaciones aún no están habilitadas en el sistema. Avísale al administrador que ejecute
                        <strong>database/migracion_v4_calificaciones.sql</strong>.</span>
                    </div>

                <?php else: ?>

                    <form method="POST" action="<?php echo htmlspecialchars($url(['tab' => 'calificaciones', 'guardado' => null])); ?>" id="form-calif">
                        <input type="hidden" name="accion" value="guardar_calificaciones">

                        <div class="table-responsive">
                            <table class="custom-table tabla-calif">
                                <thead>
                                    <tr>
                                        <th>Matrícula</th>
                                        <th>Alumno</th>
                                        <th style="text-align: center;">Parcial 1</th>
                                        <th style="text-align: center;">Parcial 2</th>
                                        <th style="text-align: center;">Parcial 3</th>
                                        <th style="text-align: center;">Promedio</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($alumnos as $m => $al): ?>
                                        <?php $prom = $promediosAlumnos[$m]; ?>
                                        <tr class="js-fila">
                                            <td style="font-size: 0.85rem;"><?php echo htmlspecialchars($m); ?></td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($al['nombre']); ?></strong>
                                                <div style="font-size: 0.72rem; color: var(--text-muted);">
                                                    <?php echo htmlspecialchars(etiquetaGrupo($grupoSel)); ?>
                                                </div>
                                            </td>
                                            <?php for ($p = 1; $p <= 3; $p++): ?>
                                                <?php $v = $valoresCalif[$m][$p]; ?>
                                                <td style="text-align: center;">
                                                    <label class="nota-cell">
                                                        <input type="number" step="any" min="0" max="100" inputmode="decimal"
                                                               class="nota-input js-nota <?php echo hdClaseNota($v === '' ? null : (float)$v); ?>"
                                                               name="notas[<?php echo htmlspecialchars($m); ?>][<?php echo $p; ?>]"
                                                               value="<?php echo htmlspecialchars($v); ?>" placeholder="--"
                                                               aria-label="Parcial <?php echo $p; ?> de <?php echo htmlspecialchars($al['nombre']); ?>">
                                                        <i class="fa-solid fa-pen nota-edit"></i>
                                                    </label>
                                                </td>
                                            <?php endfor; ?>
                                            <td style="text-align: center;">
                                                <span class="nota-prom js-prom <?php echo hdClaseNota($prom); ?>"><?php echo $prom === null ? '--' : hdNum($prom); ?></span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="stats-grid" style="margin-top: 1.25rem;">
                            <div class="stat-card">
                                <div class="stat-info">
                                    <span class="stat-value" id="st-total"><?php echo count($alumnos); ?></span>
                                    <span class="stat-label">Total alumnos</span>
                                </div>
                                <div class="stat-icon stat-blue"><i class="fa-solid fa-user-graduate"></i></div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-info">
                                    <span class="stat-value" id="st-prom"><?php echo $promGrupal === null ? '--' : hdNum($promGrupal); ?></span>
                                    <span class="stat-label">Promedio grupal</span>
                                </div>
                                <div class="stat-icon stat-green"><i class="fa-solid fa-chart-line"></i></div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-info">
                                    <span class="stat-value" id="st-altos"><?php echo $cuentaAltos; ?></span>
                                    <span class="stat-label">Promedio ≥ <?php echo NOTA_EXCELENCIA; ?></span>
                                </div>
                                <div class="stat-icon stat-purple"><i class="fa-solid fa-star"></i></div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-info">
                                    <span class="stat-value" id="st-bajos"><?php echo $cuentaBajos; ?></span>
                                    <span class="stat-label">Promedio &lt; <?php echo NOTA_REPROBATORIA; ?></span>
                                </div>
                                <div class="stat-icon stat-danger"><i class="fa-solid fa-triangle-exclamation"></i></div>
                            </div>
                        </div>

                        <div style="display: flex; gap: 0.75rem; flex-wrap: wrap; margin-top: 1rem;">
                            <button type="submit" class="btn-primary" style="width: auto; padding: 0.7rem 1.4rem;">
                                <i class="fa-solid fa-floppy-disk"></i>
                                <span>Guardar calificaciones</span>
                            </button>
                            <a href="<?php echo htmlspecialchars($url(['export' => 'calificaciones', 'guardado' => null])); ?>"
                               class="btn-filter" style="text-decoration: none; height: auto; padding: 0.7rem 1.2rem;">
                                <i class="fa-solid fa-file-lines"></i> Generar Reporte
                            </a>
                        </div>

                        <p style="font-size: 0.78rem; color: var(--text-muted); margin-top: 0.75rem;">
                            Escala de 0 a 100. Deja una casilla vacía si aún no hay calificación. El promedio se calcula con los parciales capturados.
                        </p>
                    </form>

                    <script>
                    (function () {
                        var ALTA = <?php echo NOTA_EXCELENCIA; ?>, BAJA = <?php echo NOTA_REPROBATORIA; ?>;
                        var clases = ['nota-alta', 'nota-ok', 'nota-baja', 'nota-vacia'];

                        function claseDe(v) {
                            if (v === null) { return 'nota-vacia'; }
                            return v >= ALTA ? 'nota-alta' : (v >= BAJA ? 'nota-ok' : 'nota-baja');
                        }
                        function pon(el, v) {
                            clases.forEach(function (c) { el.classList.remove(c); });
                            el.classList.add(claseDe(v));
                        }
                        function fmt(n) { var r = Math.round(n * 10) / 10; return (r % 1 === 0) ? String(r) : r.toFixed(1); }

                        function recalcular() {
                            var suma = 0, con = 0, altos = 0, bajos = 0;
                            document.querySelectorAll('.js-fila').forEach(function (fila) {
                                var vals = [];
                                fila.querySelectorAll('.js-nota').forEach(function (inp) {
                                    var n = parseFloat(String(inp.value).replace(',', '.'));
                                    var v = isNaN(n) ? null : n;
                                    pon(inp, v);
                                    if (v !== null) { vals.push(v); }
                                });
                                var prom = null;
                                if (vals.length) { prom = vals.reduce(function (a, b) { return a + b; }, 0) / vals.length; }
                                var celda = fila.querySelector('.js-prom');
                                celda.textContent = prom === null ? '--' : fmt(prom);
                                pon(celda, prom);
                                if (prom !== null) {
                                    suma += prom; con++;
                                    if (prom >= ALTA) { altos++; }
                                    if (prom < BAJA) { bajos++; }
                                }
                            });
                            document.getElementById('st-prom').textContent = con ? fmt(suma / con) : '--';
                            document.getElementById('st-altos').textContent = altos;
                            document.getElementById('st-bajos').textContent = bajos;
                        }

                        document.querySelectorAll('.js-nota').forEach(function (inp) {
                            inp.addEventListener('input', recalcular);
                        });
                        // El lápiz enfoca la casilla
                        document.querySelectorAll('.nota-edit').forEach(function (ic) {
                            ic.addEventListener('click', function () { ic.parentNode.querySelector('input').focus(); });
                        });
                    })();
                    </script>

                <?php endif; ?>

            <?php endif; ?>

        </div>
    </div>

    <!-- ===================== GRUPOS (COSTADO DERECHO) ===================== -->
    <aside class="hd-groups">
        <div class="panel-card">
            <div class="hd-groups-title">
                <i class="fa-solid fa-graduation-cap"></i> Selecciona el grupo
            </div>
            <div class="hd-groups-list">
                <?php foreach ($grupos as $gid => $g): ?>
                    <a href="<?php echo htmlspecialchars($url(['g' => $gid, 'm' => null, 'guardado' => null, 'export' => null])); ?>"
                       class="group-tab <?php echo $gid === $gSel ? 'active' : ''; ?>">
                        <span class="group-tab-main">
                            <strong><?php echo htmlspecialchars(etiquetaGrupo($g)); ?></strong>
                            <small><?php echo htmlspecialchars(carreraCorta($g['carrera'])); ?></small>
                        </span>
                        <span class="tab-count"><?php echo isset($alumnosPorGrupo[$gid]) ? count($alumnosPorGrupo[$gid]) : 0; ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </aside>

</div>

<?php endif; ?>

<?php
require_once __DIR__ . '/footer.php';
?>
