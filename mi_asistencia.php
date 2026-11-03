<?php
session_start();
require_once __DIR__ . '/includes/conexion.php';
require_once __DIR__ . '/api/attendance_helper.php';

// Verificar sesión
if (!isset($_SESSION['usuario'])) {
    header("Location: login.php");
    exit;
}

$user = $_SESSION['usuario'];

// Esta vista es solo para docentes
if ($user['rol'] !== 'docente') {
    header("Location: index.php");
    exit;
}

$matricula = $user['matricula'];
$error = '';
$misClases = [];
$sesiones = [];
$resumenClases = [];

// Filtros (GET)
$claseFiltro = isset($_GET['clase_id']) ? (int)$_GET['clase_id'] : 0;
$desde = $_GET['desde'] ?? '';
$hasta = $_GET['hasta'] ?? '';
$desde = preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde) ? $desde : '';
$hasta = preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta) ? $hasta : '';

const MAX_FILAS_ASISTENCIA = 500;

try {
    // Clases del docente
    $stmt = $pdo->prepare("
        SELECT id, nombre_materia, dia_semana, hora_inicio, hora_fin, aula, carrera, grupo, semestre
        FROM clases
        WHERE docente_id = :d
        ORDER BY FIELD(dia_semana,'lunes','martes','miercoles','jueves','viernes','sabado'), hora_inicio
    ");
    $stmt->execute(['d' => $matricula]);
    $misClases = $stmt->fetchAll();

    $idsPermitidos = array_map('intval', array_column($misClases, 'id'));
    if ($claseFiltro > 0 && !in_array($claseFiltro, $idsPermitidos, true)) {
        $error = 'Esa clase no está entre las tuyas.';
        $claseFiltro = 0;
    }

    if (!empty($idsPermitidos)) {
        // -----------------------------------------------------------------
        // Sesiones impartidas = días distintos en los que hubo registros de
        // asistencia en la clase (de cualquier persona) o del propio docente.
        // Si ese día el docente no tiene registro, cuenta como inasistencia.
        // (Mismo criterio que usa "Mis Clases" para los alumnos.)
        // -----------------------------------------------------------------
        $idsFiltrados = $claseFiltro > 0 ? [$claseFiltro] : $idsPermitidos;
        $marcadores = implode(',', array_fill(0, count($idsFiltrados), '?'));

        $sql = "
            SELECT s.clase_id, s.fecha,
                   c.nombre_materia, c.hora_inicio, c.hora_fin, c.aula,
                   a.estado, a.hora, a.tipo, a.dispositivo_id
            FROM (SELECT DISTINCT clase_id, fecha FROM asistencias
                  WHERE clase_id IN ($marcadores)) s
            JOIN clases c ON c.id = s.clase_id
            LEFT JOIN asistencias a
                   ON a.clase_id = s.clase_id AND a.fecha = s.fecha AND a.usuario_id = ?
            WHERE 1 = 1
        ";
        $params = $idsFiltrados;
        $params[] = $matricula;

        if ($desde !== '') {
            $sql .= " AND s.fecha >= ?";
            $params[] = $desde;
        }
        if ($hasta !== '') {
            $sql .= " AND s.fecha <= ?";
            $params[] = $hasta;
        }

        $sql .= " ORDER BY s.fecha DESC, c.hora_inicio DESC LIMIT " . MAX_FILAS_ASISTENCIA;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $sesiones = $stmt->fetchAll();
    }
} catch (PDOException $e) {
    $error = mensajeErrorBD($e, 'cargar tu asistencia', $user['rol'] ?? null, 'mi_asistencia.php');
    $sesiones = [];
}

// -------------------------------------------------------------------------
// Clasificación y totales
// -------------------------------------------------------------------------
$totales = ['sesiones' => 0, 'asistencias' => 0, 'presente' => 0, 'retardo' => 0, 'justificado' => 0, 'falta' => 0];

foreach ($sesiones as $i => $s) {
    $estado = $s['estado'] ?: 'falta';
    $sesiones[$i]['estado_final'] = $estado;
    $sesiones[$i]['sin_registro'] = empty($s['estado']);
    $sesiones[$i]['es_asistencia'] = in_array($estado, ['presente', 'retardo', 'justificado'], true);

    $totales['sesiones']++;
    $totales[$estado]++;
    if ($sesiones[$i]['es_asistencia']) {
        $totales['asistencias']++;
    }

    $cid = $s['clase_id'];
    if (!isset($resumenClases[$cid])) {
        $resumenClases[$cid] = [
            'nombre' => $s['nombre_materia'], 'sesiones' => 0, 'asistencias' => 0,
            'retardo' => 0, 'falta' => 0, 'justificado' => 0,
        ];
    }
    $resumenClases[$cid]['sesiones']++;
    if ($sesiones[$i]['es_asistencia']) {
        $resumenClases[$cid]['asistencias']++;
    }
    if (in_array($estado, ['retardo', 'falta', 'justificado'], true)) {
        $resumenClases[$cid][$estado]++;
    }
}

$porcentaje = $totales['sesiones'] > 0 ? round(($totales['asistencias'] / $totales['sesiones']) * 100) : null;
$truncado = count($sesiones) >= MAX_FILAS_ASISTENCIA;

/**
 * Etiqueta legible del método de registro.
 */
function etiquetaMetodo($tipo) {
    switch ($tipo) {
        case 'RFID_VERIFICADO':
            return '<i class="fa-solid fa-id-card icon-blue"></i> Tarjeta + Telegram';
        case 'MATRICULA_VERIFICADA':
            return '<i class="fa-solid fa-keyboard icon-purple"></i> Matrícula + Telegram';
        case 'AUTORIZADO_DOCENTE':
            return '<i class="fa-solid fa-signature icon-green"></i> Registro manual';
        default:
            return '<span style="color: var(--text-muted);">Sin registro</span>';
    }
}

/**
 * Día de la semana (clave en español, minúsculas) de una fecha YYYY-MM-DD.
 */
function obtenerDiaDeFecha($fecha) {
    $dias = [1 => 'lunes', 2 => 'martes', 3 => 'miercoles', 4 => 'jueves',
             5 => 'viernes', 6 => 'sabado', 7 => 'domingo'];
    return $dias[(int)date('N', strtotime($fecha))];
}

$nombresDias = ['lunes' => 'Lunes', 'martes' => 'Martes', 'miercoles' => 'Miércoles',
                'jueves' => 'Jueves', 'viernes' => 'Viernes', 'sabado' => 'Sábado', 'domingo' => 'Domingo'];

require_once __DIR__ . '/header.php';
?>

<div class="top-bar">
    <div class="page-title">
        <span class="page-eyebrow">Docencia</span>
        <h2>Mi Asistencia</h2>
        <p>Tus asistencias, retardos e inasistencias en cada una de las clases que impartes.</p>
    </div>
</div>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger">
        <i class="fa-solid fa-circle-exclamation"></i>
        <span><?php echo htmlspecialchars($error); ?></span>
    </div>
<?php endif; ?>

<?php if (empty($misClases)): ?>

    <div class="panel-card">
        <div style="text-align: center; color: var(--text-muted); padding: 40px 20px;">
            <i class="fa-solid fa-calendar-xmark" style="font-size: 2rem; margin-bottom: 12px; display: block;"></i>
            Todavía no tienes clases asignadas.<br>
            Pídele al administrador que te asigne tus clases en Horarios / Clases.
        </div>
    </div>

<?php else: ?>

    <!-- FILTROS -->
    <div class="filter-card">
        <form method="GET" action="mi_asistencia.php">
            <div class="filter-grid">
                <div class="form-group" style="margin-bottom: 0;">
                    <label for="clase_id">CLASE</label>
                    <select id="clase_id" name="clase_id" class="form-select">
                        <option value="0">Todas mis clases</option>
                        <?php foreach ($misClases as $c): ?>
                            <option value="<?php echo (int)$c['id']; ?>" <?php echo $claseFiltro === (int)$c['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($c['nombre_materia'] . ' — ' . ($nombresDias[$c['dia_semana']] ?? $c['dia_semana']) . ' ' . date('h:i A', strtotime($c['hora_inicio']))); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group" style="margin-bottom: 0;">
                    <label for="desde">DESDE</label>
                    <input type="date" id="desde" name="desde" class="form-date" value="<?php echo htmlspecialchars($desde); ?>">
                </div>

                <div class="form-group" style="margin-bottom: 0;">
                    <label for="hasta">HASTA</label>
                    <input type="date" id="hasta" name="hasta" class="form-date" value="<?php echo htmlspecialchars($hasta); ?>">
                </div>

                <div style="display: flex; align-items: flex-end; gap: 8px;">
                    <button type="submit" class="btn-filter" style="flex: 1;">
                        <i class="fa-solid fa-magnifying-glass"></i> Filtrar
                    </button>
                    <a href="mi_asistencia.php" class="btn-reset" style="display: inline-flex; align-items: center; text-decoration: none;">Limpiar</a>
                </div>
            </div>
        </form>
    </div>

    <!-- RESUMEN -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-info">
                <span class="stat-value"><?php echo $porcentaje === null ? '—' : $porcentaje . '%'; ?></span>
                <span class="stat-label"><?php echo $porcentaje === null ? 'Sin sesiones registradas' : 'Mi asistencia'; ?></span>
            </div>
            <div class="stat-icon stat-purple"><i class="fa-solid fa-percent"></i></div>
        </div>
        <div class="stat-card">
            <div class="stat-info">
                <span class="stat-value"><?php echo $totales['asistencias']; ?>/<?php echo $totales['sesiones']; ?></span>
                <span class="stat-label">Sesiones asistidas</span>
            </div>
            <div class="stat-icon stat-green"><i class="fa-solid fa-calendar-check"></i></div>
        </div>
        <div class="stat-card">
            <div class="stat-info">
                <span class="stat-value"><?php echo $totales['retardo']; ?></span>
                <span class="stat-label">Retardos</span>
            </div>
            <div class="stat-icon stat-orange"><i class="fa-solid fa-clock"></i></div>
        </div>
        <div class="stat-card">
            <div class="stat-info">
                <span class="stat-value"><?php echo $totales['falta']; ?></span>
                <span class="stat-label">Inasistencias</span>
            </div>
            <div class="stat-icon stat-danger"><i class="fa-solid fa-circle-xmark"></i></div>
        </div>
    </div>

    <!-- PESTAÑAS -->
    <div class="panel-card">
        <div class="tabs" role="tablist">
            <button type="button" class="tab-btn active" data-tab="todas" role="tab">
                Todas <span class="tab-count"><?php echo $totales['sesiones']; ?></span>
            </button>
            <button type="button" class="tab-btn" data-tab="asistencias" role="tab">
                Asistencias <span class="tab-count"><?php echo $totales['asistencias']; ?></span>
            </button>
            <button type="button" class="tab-btn" data-tab="inasistencias" role="tab">
                Inasistencias <span class="tab-count"><?php echo $totales['falta']; ?></span>
            </button>
            <button type="button" class="tab-btn" data-tab="resumen" role="tab">
                Resumen por clase
            </button>
        </div>

        <?php
        // Los tres primeros paneles comparten la misma tabla, filtrada por JS.
        $paneles = [
            'todas'         => 'Todavía no hay sesiones registradas con estos filtros.',
            'asistencias'   => 'No hay asistencias registradas con estos filtros.',
            'inasistencias' => '¡Sin inasistencias con estos filtros!',
        ];
        foreach ($paneles as $clavePanel => $textoVacio):
            $filas = array_filter($sesiones, function ($s) use ($clavePanel) {
                if ($clavePanel === 'asistencias') {
                    return $s['es_asistencia'];
                }
                if ($clavePanel === 'inasistencias') {
                    return !$s['es_asistencia'];
                }
                return true;
            });
        ?>
            <div class="tab-panel <?php echo $clavePanel === 'todas' ? 'active' : ''; ?>" data-panel="<?php echo $clavePanel; ?>">
                <div class="table-responsive">
                    <table class="custom-table">
                        <thead>
                            <tr>
                                <th>Fecha</th>
                                <th>Materia</th>
                                <th>Horario</th>
                                <th>Estado</th>
                                <th>Hora de registro</th>
                                <th>Método</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($filas)): ?>
                                <tr>
                                    <td colspan="6" style="text-align: center; color: var(--text-muted); padding: 30px 10px;">
                                        <?php echo $textoVacio; ?>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($filas as $s): ?>
                                    <tr>
                                        <td>
                                            <?php echo date('d/m/Y', strtotime($s['fecha'])); ?>
                                            <div style="font-size: 0.75rem; color: var(--text-muted);">
                                                <?php echo $nombresDias[obtenerDiaDeFecha($s['fecha'])] ?? ''; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($s['nombre_materia']); ?></strong>
                                            <?php if ($s['aula']): ?>
                                                <div style="font-size: 0.75rem; color: var(--text-muted);">Aula <?php echo htmlspecialchars($s['aula']); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td style="font-size: 0.85rem;">
                                            <?php echo date('h:i A', strtotime($s['hora_inicio'])); ?> - <?php echo date('h:i A', strtotime($s['hora_fin'])); ?>
                                        </td>
                                        <td>
                                            <span class="badge badge-<?php echo $s['estado_final']; ?>">
                                                <?php echo $s['sin_registro'] ? 'falta (sin registro)' : htmlspecialchars($s['estado_final']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo $s['hora'] ? date('h:i A', strtotime($s['hora'])) : '—'; ?></td>
                                        <td style="font-size: 0.82rem;"><?php echo etiquetaMetodo($s['tipo']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endforeach; ?>

        <!-- RESUMEN POR CLASE -->
        <div class="tab-panel" data-panel="resumen">
            <div class="table-responsive">
                <table class="custom-table">
                    <thead>
                        <tr>
                            <th>Materia</th>
                            <th>Asistencia</th>
                            <th>Retardos</th>
                            <th>Inasistencias</th>
                            <th>Justificadas</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($resumenClases)): ?>
                            <tr>
                                <td colspan="5" style="text-align: center; color: var(--text-muted); padding: 30px 10px;">
                                    Todavía no hay sesiones registradas con estos filtros.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($resumenClases as $r): ?>
                                <?php
                                    $pct = $r['sesiones'] > 0 ? round(($r['asistencias'] / $r['sesiones']) * 100) : 0;
                                    $claseBadge = $pct >= 80 ? 'presente' : ($pct >= 60 ? 'retardo' : 'falta');
                                ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($r['nombre']); ?></strong></td>
                                    <td>
                                        <span class="badge badge-<?php echo $claseBadge; ?>"><?php echo $pct; ?>%</span>
                                        <div style="font-size: 0.75rem; color: var(--text-muted);">
                                            <?php echo $r['asistencias']; ?>/<?php echo $r['sesiones']; ?> sesiones
                                        </div>
                                    </td>
                                    <td><?php echo $r['retardo']; ?></td>
                                    <td>
                                        <strong style="color: <?php echo $r['falta'] > 0 ? 'var(--danger)' : 'inherit'; ?>;"><?php echo $r['falta']; ?></strong>
                                    </td>
                                    <td><?php echo $r['justificado']; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if ($truncado): ?>
            <p style="font-size: 0.8rem; color: var(--text-muted);">
                Se muestran las <?php echo MAX_FILAS_ASISTENCIA; ?> sesiones más recientes. Usa los filtros de fecha o de clase para ver otras.
            </p>
        <?php endif; ?>

        <p style="font-size: 0.78rem; color: var(--text-muted);">
            Una sesión cuenta como impartida cuando hay registros de asistencia en esa clase ese día.
            Si en esa fecha no registraste tu asistencia, se muestra como inasistencia.
        </p>
    </div>

    <script>
    (function () {
        var botones = document.querySelectorAll('.tab-btn');
        var paneles = document.querySelectorAll('.tab-panel');

        function activar(nombre) {
            botones.forEach(function (b) { b.classList.toggle('active', b.dataset.tab === nombre); });
            paneles.forEach(function (p) { p.classList.toggle('active', p.dataset.panel === nombre); });
        }

        botones.forEach(function (b) {
            b.addEventListener('click', function () {
                activar(b.dataset.tab);
                history.replaceState(null, '', '#' + b.dataset.tab);
            });
        });

        var inicial = location.hash.replace('#', '');
        if (inicial && document.querySelector('.tab-panel[data-panel="' + inicial + '"]')) {
            activar(inicial);
        }
    })();
    </script>

<?php endif; ?>

<?php
require_once __DIR__ . '/footer.php';
?>
