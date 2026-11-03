<?php
session_start();
require_once __DIR__ . '/includes/conexion.php';
require_once __DIR__ . '/api/attendance_helper.php';
require_once __DIR__ . '/includes/docente_grupos.php';

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
$grupos = [];   // clave => ['carrera','grupo','semestre','clases'=>[], 'alumnos'=>[]]

// Porcentaje por debajo del cual se marca al alumno "en riesgo"
const UMBRAL_ASISTENCIA = 80;

try {
    // 1. Clases del docente
    $stmt = $pdo->prepare("
        SELECT id, nombre_materia, dia_semana, hora_inicio, aula, carrera, grupo, semestre
        FROM clases
        WHERE docente_id = :d
        ORDER BY nombre_materia ASC, FIELD(dia_semana,'lunes','martes','miercoles','jueves','viernes','sabado'), hora_inicio
    ");
    $stmt->execute(['d' => $matricula]);
    $clases = $stmt->fetchAll();

    $claseAGrupo = [];
    foreach ($clases as $c) {
        $k = claveGrupo($c['carrera'], $c['grupo'], $c['semestre']);
        if (!isset($grupos[$k])) {
            $grupos[$k] = [
                'carrera' => $c['carrera'], 'grupo' => $c['grupo'], 'semestre' => $c['semestre'],
                'clases' => [], 'alumnos' => [],
            ];
        }
        // Una materia suele repetirse en varios días: se lista una sola vez
        $grupos[$k]['clases'][$c['id']] = $c['nombre_materia'];
        $claseAGrupo[$c['id']] = $k;
    }

    if (!empty($clases)) {
        $ids = array_map('intval', array_keys($claseAGrupo));
        $in = implode(',', array_fill(0, count($ids), '?'));

        // 2. Alumnos inscritos en esas clases
        $stmt = $pdo->prepare("
            SELECT i.clase_id, u.matricula, u.nombre, u.correo, u.estado,
                   u.carrera, u.grupo, u.semestre,
                   (u.id_telegram IS NOT NULL AND u.id_telegram <> '') AS tiene_telegram,
                   (u.tarjeta_rfid IS NOT NULL AND u.tarjeta_rfid <> '') AS tiene_tarjeta
            FROM inscripciones i
            JOIN usuarios u ON u.matricula = i.alumno_id
            WHERE i.clase_id IN ($in)
            ORDER BY u.nombre ASC
        ");
        $stmt->execute($ids);
        $inscritos = $stmt->fetchAll();

        // 3. Sesiones impartidas por clase (días con registros de asistencia)
        $stmt = $pdo->prepare("SELECT clase_id, COUNT(DISTINCT fecha) AS sesiones FROM asistencias WHERE clase_id IN ($in) GROUP BY clase_id");
        $stmt->execute($ids);
        $sesionesClase = [];
        foreach ($stmt->fetchAll() as $r) {
            $sesionesClase[(int)$r['clase_id']] = (int)$r['sesiones'];
        }

        // 4. Asistencia de cada alumno por clase
        $stmt = $pdo->prepare("
            SELECT clase_id, usuario_id,
                   COUNT(DISTINCT CASE WHEN estado IN ('presente','retardo','justificado') THEN fecha END) AS asistidas,
                   COUNT(DISTINCT CASE WHEN estado = 'retardo' THEN fecha END) AS retardos
            FROM asistencias
            WHERE clase_id IN ($in)
            GROUP BY clase_id, usuario_id
        ");
        $stmt->execute($ids);
        $asistPorAlumnoClase = [];
        foreach ($stmt->fetchAll() as $r) {
            $asistPorAlumnoClase[(int)$r['clase_id'] . '|' . $r['usuario_id']] = $r;
        }

        // 5. Armar cada grupo
        foreach ($inscritos as $al) {
            $cid = (int)$al['clase_id'];
            $k = $claseAGrupo[$cid];
            $m = $al['matricula'];

            if (!isset($grupos[$k]['alumnos'][$m])) {
                $grupos[$k]['alumnos'][$m] = [
                    'matricula' => $m, 'nombre' => $al['nombre'], 'correo' => $al['correo'],
                    'estado' => $al['estado'], 'carrera' => $al['carrera'],
                    'grupo' => $al['grupo'], 'semestre' => $al['semestre'],
                    'tiene_telegram' => (bool)$al['tiene_telegram'],
                    'tiene_tarjeta' => (bool)$al['tiene_tarjeta'],
                    'materias' => [],      // nombre => ['sesiones','asistidas']
                    'sesiones' => 0, 'asistidas' => 0, 'retardos' => 0,
                ];
            }

            $sesiones = $sesionesClase[$cid] ?? 0;
            $reg = $asistPorAlumnoClase[$cid . '|' . $m] ?? null;
            $asistidas = $reg ? min((int)$reg['asistidas'], $sesiones) : 0;
            $nombreMateria = $grupos[$k]['clases'][$cid];

            if (!isset($grupos[$k]['alumnos'][$m]['materias'][$nombreMateria])) {
                $grupos[$k]['alumnos'][$m]['materias'][$nombreMateria] = ['sesiones' => 0, 'asistidas' => 0];
            }
            $grupos[$k]['alumnos'][$m]['materias'][$nombreMateria]['sesiones']  += $sesiones;
            $grupos[$k]['alumnos'][$m]['materias'][$nombreMateria]['asistidas'] += $asistidas;
            $grupos[$k]['alumnos'][$m]['sesiones']  += $sesiones;
            $grupos[$k]['alumnos'][$m]['asistidas'] += $asistidas;
            $grupos[$k]['alumnos'][$m]['retardos']  += $reg ? (int)$reg['retardos'] : 0;
        }
    }
} catch (PDOException $e) {
    $error = mensajeErrorBD($e, 'cargar tus alumnos', $user['rol'] ?? null, 'mis_alumnos.php');
    $grupos = [];
}

// Orden de las pestañas: carrera, semestre, grupo
uasort($grupos, function ($a, $b) {
    return [(string)$a['carrera'], (int)$a['semestre'], (string)$a['grupo']]
       <=> [(string)$b['carrera'], (int)$b['semestre'], (string)$b['grupo']];
});

// Totales por grupo
foreach ($grupos as $k => $g) {
    $totalAl = count($g['alumnos']);
    $sumaPct = 0;
    $conDatos = 0;
    $enRiesgo = 0;
    foreach ($g['alumnos'] as $m => $al) {
        $pct = $al['sesiones'] > 0 ? round(($al['asistidas'] / $al['sesiones']) * 100) : null;
        $grupos[$k]['alumnos'][$m]['pct'] = $pct;
        $grupos[$k]['alumnos'][$m]['faltas'] = max(0, $al['sesiones'] - $al['asistidas']);
        if ($pct !== null) {
            $sumaPct += $pct;
            $conDatos++;
            if ($pct < UMBRAL_ASISTENCIA) {
                $enRiesgo++;
            }
        }
    }
    $grupos[$k]['total'] = $totalAl;
    $grupos[$k]['promedio'] = $conDatos > 0 ? round($sumaPct / $conDatos) : null;
    $grupos[$k]['en_riesgo'] = $enRiesgo;
}

$totalAlumnosUnicos = [];
foreach ($grupos as $g) {
    foreach ($g['alumnos'] as $m => $_) {
        $totalAlumnosUnicos[$m] = true;
    }
}

require_once __DIR__ . '/header.php';
?>

<div class="top-bar">
    <div class="page-title">
        <span class="page-eyebrow">Docencia</span>
        <h2>Mis Alumnos</h2>
        <p>Información de los alumnos de cada grupo que tienes asignado.</p>
    </div>
</div>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger">
        <i class="fa-solid fa-circle-exclamation"></i>
        <span><?php echo htmlspecialchars($error); ?></span>
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

    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-info">
                <span class="stat-value"><?php echo count($grupos); ?></span>
                <span class="stat-label">Grupos asignados</span>
            </div>
            <div class="stat-icon stat-purple"><i class="fa-solid fa-people-group"></i></div>
        </div>
        <div class="stat-card">
            <div class="stat-info">
                <span class="stat-value"><?php echo count($totalAlumnosUnicos); ?></span>
                <span class="stat-label">Alumnos en total</span>
            </div>
            <div class="stat-icon stat-blue"><i class="fa-solid fa-user-graduate"></i></div>
        </div>
    </div>

    <div class="panel-card">
        <!-- PESTAÑAS: UNA POR GRUPO -->
        <div class="tabs" role="tablist">
            <?php $n = 0; foreach ($grupos as $k => $g): ?>
                <button type="button" class="tab-btn <?php echo $n === 0 ? 'active' : ''; ?>" data-tab="g<?php echo $n; ?>" role="tab">
                    <span>
                        <?php echo htmlspecialchars(etiquetaGrupo($g)); ?>
                        <span class="tab-count"><?php echo $g['total']; ?></span>
                    </span>
                    <small><?php echo htmlspecialchars(carreraCorta($g['carrera'])); ?></small>
                </button>
            <?php $n++; endforeach; ?>
        </div>

        <!-- UN PANEL POR GRUPO -->
        <?php $n = 0; foreach ($grupos as $k => $g): ?>
            <div class="tab-panel <?php echo $n === 0 ? 'active' : ''; ?>" data-panel="g<?php echo $n; ?>">

                <div style="display: flex; justify-content: space-between; gap: 16px; flex-wrap: wrap; align-items: flex-start;">
                    <div>
                        <div style="font-size: 1rem; font-weight: 600;">
                            <?php echo htmlspecialchars($g['carrera'] ?: 'Sin carrera definida'); ?>
                        </div>
                        <div style="font-size: 0.85rem; color: var(--text-muted); margin-top: 4px;">
                            <i class="fa-solid fa-book"></i>
                            <?php echo htmlspecialchars(implode(' · ', array_unique($g['clases']))); ?>
                        </div>
                    </div>
                    <div style="display: flex; gap: 20px; font-size: 0.85rem;">
                        <div><strong style="font-size: 1.2rem;"><?php echo $g['total']; ?></strong><br><span style="color: var(--text-muted);">alumnos</span></div>
                        <div>
                            <strong style="font-size: 1.2rem;"><?php echo $g['promedio'] === null ? '—' : $g['promedio'] . '%'; ?></strong><br>
                            <span style="color: var(--text-muted);">asistencia prom.</span>
                        </div>
                        <div>
                            <strong style="font-size: 1.2rem;"><?php echo $g['en_riesgo']; ?></strong><br>
                            <span style="color: var(--text-muted);">bajo <?php echo UMBRAL_ASISTENCIA; ?>%</span>
                        </div>
                    </div>
                </div>

                <?php if (empty($g['alumnos'])): ?>
                    <div style="text-align: center; color: var(--text-muted); padding: 30px 10px;">
                        <i class="fa-solid fa-user-plus" style="font-size: 1.6rem; margin-bottom: 10px; display: block;"></i>
                        Este grupo aún no tiene alumnos inscritos.<br>
                        Pídele al administrador que los inscriba desde Inscripciones.
                    </div>
                <?php else: ?>
                    <input type="search" class="form-select search-input js-buscar" data-panel="g<?php echo $n; ?>"
                           placeholder="Buscar por nombre o matrícula…" autocomplete="off">

                    <div class="table-responsive">
                        <table class="custom-table">
                            <thead>
                                <tr>
                                    <th>Alumno</th>
                                    <th>Carrera / Grupo</th>
                                    <th>Materias contigo</th>
                                    <th>Asistencia</th>
                                    <th>Faltas</th>
                                    <th>Contacto</th>
                                    <th>Vinculación</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($g['alumnos'] as $al): ?>
                                    <?php
                                        $pct = $al['pct'];
                                        $claseBadge = $pct === null ? 'neutro' : ($pct >= UMBRAL_ASISTENCIA ? 'presente' : ($pct >= 60 ? 'retardo' : 'falta'));
                                    ?>
                                    <tr data-buscar="<?php echo htmlspecialchars(function_exists('mb_strtolower') ? mb_strtolower($al['nombre'] . ' ' . $al['matricula']) : strtolower($al['nombre'] . ' ' . $al['matricula'])); ?>">
                                        <td>
                                            <strong><?php echo htmlspecialchars($al['nombre']); ?></strong>
                                            <div style="font-size: 0.75rem; color: var(--text-muted);">
                                                <?php echo htmlspecialchars($al['matricula']); ?>
                                                <?php if ($al['estado'] !== 'activo'): ?>
                                                    · <span class="badge badge-inactive" style="padding: 0 6px;">inactivo</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td style="font-size: 0.82rem;">
                                            <?php echo htmlspecialchars(carreraCorta($al['carrera'])); ?>
                                            <div style="color: var(--text-muted);">
                                                <?php echo htmlspecialchars(trim(($al['grupo'] ? 'Grupo ' . $al['grupo'] : '') . ($al['semestre'] ? ' · Sem ' . $al['semestre'] : ''), ' ·') ?: '—'); ?>
                                            </div>
                                        </td>
                                        <td style="font-size: 0.8rem;">
                                            <?php foreach ($al['materias'] as $nomMat => $mm): ?>
                                                <?php $pm = $mm['sesiones'] > 0 ? round(($mm['asistidas'] / $mm['sesiones']) * 100) . '%' : '—'; ?>
                                                <div><?php echo htmlspecialchars($nomMat); ?> <span style="color: var(--text-muted);">(<?php echo $pm; ?>)</span></div>
                                            <?php endforeach; ?>
                                        </td>
                                        <td style="min-width: 110px;">
                                            <span class="badge badge-<?php echo $claseBadge; ?>"><?php echo $pct === null ? '—' : $pct . '%'; ?></span>
                                            <?php if ($pct !== null): ?>
                                                <div class="progress-bar"><span style="width: <?php echo (int)$pct; ?>%;"></span></div>
                                                <div style="font-size: 0.72rem; color: var(--text-muted); margin-top: 3px;">
                                                    <?php echo $al['asistidas']; ?>/<?php echo $al['sesiones']; ?> sesiones
                                                </div>
                                            <?php else: ?>
                                                <div style="font-size: 0.72rem; color: var(--text-muted);">aún no se pasa lista</div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <strong style="color: <?php echo $al['faltas'] > 0 ? 'var(--danger)' : 'inherit'; ?>;"><?php echo $al['faltas']; ?></strong>
                                            <?php if ($al['retardos'] > 0): ?>
                                                <div style="font-size: 0.72rem; color: var(--text-muted);"><?php echo $al['retardos']; ?> retardos</div>
                                            <?php endif; ?>
                                        </td>
                                        <td style="font-size: 0.82rem;">
                                            <?php if ($al['correo']): ?>
                                                <a href="mailto:<?php echo htmlspecialchars($al['correo']); ?>" style="color: inherit;">
                                                    <i class="fa-solid fa-envelope"></i> <?php echo htmlspecialchars($al['correo']); ?>
                                                </a>
                                            <?php else: ?>
                                                <span style="color: var(--text-muted);">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="font-size: 0.8rem; white-space: nowrap;">
                                            <span title="<?php echo $al['tiene_tarjeta'] ? 'Tarjeta RFID vinculada' : 'Sin tarjeta RFID'; ?>" style="<?php echo $al['tiene_tarjeta'] ? '' : 'opacity: 0.35;'; ?>">
                                                <i class="fa-solid fa-id-card"></i>
                                            </span>
                                            &nbsp;
                                            <span title="<?php echo $al['tiene_telegram'] ? 'Telegram vinculado' : 'Sin Telegram'; ?>" style="<?php echo $al['tiene_telegram'] ? '' : 'opacity: 0.35;'; ?>">
                                                <i class="fa-brands fa-telegram"></i>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <tr class="js-sin-resultados" style="display: none;">
                                    <td colspan="7" style="text-align: center; color: var(--text-muted); padding: 24px 10px;">
                                        Ningún alumno coincide con la búsqueda.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        <?php $n++; endforeach; ?>

        <p style="font-size: 0.78rem; color: var(--text-muted);">
            Los grupos se forman con la carrera, grupo y semestre de tus clases. La asistencia se calcula
            sobre las sesiones en las que se pasó lista; marcamos con un porcentaje bajo <?php echo UMBRAL_ASISTENCIA; ?>% a quien necesita seguimiento.
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

        // Buscador por pestaña
        document.querySelectorAll('.js-buscar').forEach(function (input) {
            input.addEventListener('input', function () {
                var panel = document.querySelector('.tab-panel[data-panel="' + input.dataset.panel + '"]');
                var q = input.value.trim().toLowerCase();
                var visibles = 0;
                panel.querySelectorAll('tr[data-buscar]').forEach(function (tr) {
                    var ok = tr.dataset.buscar.indexOf(q) !== -1;
                    tr.style.display = ok ? '' : 'none';
                    if (ok) { visibles++; }
                });
                panel.querySelector('.js-sin-resultados').style.display = visibles === 0 ? '' : 'none';
            });
        });
    })();
    </script>

<?php endif; ?>

<?php
require_once __DIR__ . '/footer.php';
?>
