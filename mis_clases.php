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

// Esta vista es para alumnos
if ($user['rol'] !== 'alumno') {
    header("Location: index.php");
    exit;
}

$matricula = $user['matricula'];
$error = '';
$misClases = [];
$detalle = [];
$claseDetalle = null;

try {
    // ---------------------------------------------------------------------
    // Resumen por materia
    //
    // "Sesiones" = días distintos en los que se registró asistencia de esa
    // clase (es decir, las veces que realmente se pasó lista).
    // Las faltas incluyen los días en los que el alumno no apareció.
    // ---------------------------------------------------------------------
    $stmt = $pdo->prepare("
        SELECT
            c.id, c.nombre_materia, c.dia_semana, c.hora_inicio, c.hora_fin, c.aula,
            doc.nombre AS docente_nombre,
            (SELECT COUNT(DISTINCT a.fecha) FROM asistencias a
              WHERE a.clase_id = c.id) AS sesiones,
            (SELECT COUNT(DISTINCT a.fecha) FROM asistencias a
              WHERE a.clase_id = c.id AND a.usuario_id = :m1
                AND a.estado IN ('presente','retardo','justificado')) AS asistidas,
            (SELECT COUNT(DISTINCT a.fecha) FROM asistencias a
              WHERE a.clase_id = c.id AND a.usuario_id = :m2 AND a.estado = 'presente') AS presentes,
            (SELECT COUNT(DISTINCT a.fecha) FROM asistencias a
              WHERE a.clase_id = c.id AND a.usuario_id = :m3 AND a.estado = 'retardo') AS retardos,
            (SELECT COUNT(DISTINCT a.fecha) FROM asistencias a
              WHERE a.clase_id = c.id AND a.usuario_id = :m4 AND a.estado = 'justificado') AS justificados
        FROM inscripciones i
        JOIN clases c ON c.id = i.clase_id
        JOIN usuarios doc ON doc.matricula = c.docente_id
        WHERE i.alumno_id = :m5
        ORDER BY FIELD(c.dia_semana,'lunes','martes','miercoles','jueves','viernes','sabado'), c.hora_inicio
    ");
    $stmt->execute([
        'm1' => $matricula, 'm2' => $matricula, 'm3' => $matricula,
        'm4' => $matricula, 'm5' => $matricula
    ]);
    $misClases = $stmt->fetchAll();

    // ---------------------------------------------------------------------
    // Detalle de una materia (historial sesión por sesión)
    // ---------------------------------------------------------------------
    if (isset($_GET['clase_id'])) {
        $claseId = (int)$_GET['clase_id'];

        // Verificar que el alumno esté inscrito en esa clase
        $stmtCheck = $pdo->prepare("
            SELECT c.*, doc.nombre AS docente_nombre
            FROM inscripciones i
            JOIN clases c ON c.id = i.clase_id
            JOIN usuarios doc ON doc.matricula = c.docente_id
            WHERE i.alumno_id = :m AND i.clase_id = :c LIMIT 1
        ");
        $stmtCheck->execute(['m' => $matricula, 'c' => $claseId]);
        $claseDetalle = $stmtCheck->fetch() ?: null;

        if (!$claseDetalle) {
            $error = 'No estás inscrito en esa materia.';
        } else {
            // Todas las fechas en que se pasó lista, con el registro del alumno
            $stmtDetalle = $pdo->prepare("
                SELECT f.fecha, a.estado, a.hora, a.tipo, d.nombre AS autorizo_nombre
                FROM (SELECT DISTINCT fecha FROM asistencias WHERE clase_id = :c1) f
                LEFT JOIN asistencias a
                       ON a.fecha = f.fecha AND a.clase_id = :c2 AND a.usuario_id = :m
                LEFT JOIN usuarios d ON d.matricula = a.docente_autorizo_id
                ORDER BY f.fecha DESC
            ");
            $stmtDetalle->execute(['c1' => $claseId, 'c2' => $claseId, 'm' => $matricula]);
            $detalle = $stmtDetalle->fetchAll();
        }
    }
} catch (PDOException $e) {
    $error = mensajeErrorBD($e, 'cargar tus materias', $user['rol'] ?? null, 'mis_clases.php');
}

// Totales generales
$totalSesiones = 0;
$totalAsistidas = 0;
foreach ($misClases as $c) {
    $totalSesiones  += (int)$c['sesiones'];
    $totalAsistidas += min((int)$c['asistidas'], (int)$c['sesiones']);
}
// null = todavía no se ha pasado lista en ninguna de sus materias.
$porcentajeGeneral = $totalSesiones > 0 ? round(($totalAsistidas / $totalSesiones) * 100) : null;

require_once __DIR__ . '/header.php';
?>

<div class="top-bar">
    <div class="page-title">
        <span class="page-eyebrow">Mi espacio</span>
        <h2>Mis Clases</h2>
        <p>Tu asistencia materia por materia: puntualidades, retardos y faltas acumuladas.</p>
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
            <i class="fa-solid fa-book-open" style="font-size: 2rem; margin-bottom: 12px; display: block;"></i>
            Todavía no estás inscrito en ninguna materia.<br>
            Pídele al administrador que te inscriba en tus clases.
        </div>
    </div>

<?php else: ?>

    <!-- RESUMEN GENERAL -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-info">
                <span class="stat-value"><?php echo $porcentajeGeneral === null ? '—' : $porcentajeGeneral . '%'; ?></span>
                <span class="stat-label">
                    <?php echo $porcentajeGeneral === null ? 'Sin lista pasada aún' : 'Asistencia general'; ?>
                </span>
            </div>
            <div class="stat-icon stat-purple"><i class="fa-solid fa-percent"></i></div>
        </div>
        <div class="stat-card">
            <div class="stat-info">
                <span class="stat-value"><?php echo count($misClases); ?></span>
                <span class="stat-label">Materias inscritas</span>
            </div>
            <div class="stat-icon stat-blue"><i class="fa-solid fa-book"></i></div>
        </div>
        <div class="stat-card">
            <div class="stat-info">
                <span class="stat-value"><?php echo $totalAsistidas; ?>/<?php echo $totalSesiones; ?></span>
                <span class="stat-label">Sesiones asistidas</span>
            </div>
            <div class="stat-icon stat-green"><i class="fa-solid fa-calendar-check"></i></div>
        </div>
        <div class="stat-card">
            <div class="stat-info">
                <span class="stat-value"><?php echo max(0, $totalSesiones - $totalAsistidas); ?></span>
                <span class="stat-label">Faltas acumuladas</span>
            </div>
            <div class="stat-icon stat-danger"><i class="fa-solid fa-circle-xmark"></i></div>
        </div>
    </div>

    <!-- TARJETAS POR MATERIA -->
    <div class="panel-card">
        <div class="panel-header">
            <div class="panel-title">
                <i class="fa-solid fa-graduation-cap icon-purple" style="margin-right: 8px;"></i>
                Detalle por materia
            </div>
        </div>

        <div class="table-responsive">
            <table class="custom-table">
                <thead>
                    <tr>
                        <th>Materia</th>
                        <th>Horario</th>
                        <th>Docente</th>
                        <th>Asistencia</th>
                        <th>Puntual</th>
                        <th>Retardos</th>
                        <th>Faltas</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($misClases as $c): ?>
                        <?php
                            $sesiones = (int)$c['sesiones'];
                            $asistidas = min((int)$c['asistidas'], $sesiones);
                            $faltas = max(0, $sesiones - $asistidas);
                            // Sin sesiones impartidas no hay porcentaje: mostrar
                            // 100% ahí hacía creer que la materia iba perfecta.
                            $pct = $sesiones > 0 ? round(($asistidas / $sesiones) * 100) : null;
                            $claseBadge = $pct === null ? 'neutro' : ($pct >= 80 ? 'presente' : ($pct >= 60 ? 'retardo' : 'falta'));
                            $esHoy = (obtenerDiaSemanaEspanol() === $c['dia_semana']);
                        ?>
                        <tr <?php echo $esHoy ? 'style="background: var(--wine-50);"' : ''; ?>>
                            <td>
                                <strong><?php echo htmlspecialchars($c['nombre_materia']); ?></strong>
                                <?php if ($c['aula']): ?>
                                    <div style="font-size: 0.75rem; color: var(--text-muted);">Aula <?php echo htmlspecialchars($c['aula']); ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div><?php echo nombreDia($c['dia_semana']); ?><?php echo $esHoy ? ' <span style="color: var(--wine-700); font-size:0.7rem;">(HOY)</span>' : ''; ?></div>
                                <div style="font-size: 0.8rem; color: var(--text-muted);">
                                    <?php echo date('h:i A', strtotime($c['hora_inicio'])); ?> - <?php echo date('h:i A', strtotime($c['hora_fin'])); ?>
                                </div>
                            </td>
                            <td style="font-size: 0.85rem;"><?php echo htmlspecialchars($c['docente_nombre']); ?></td>
                            <td>
                                <span class="badge badge-<?php echo $claseBadge; ?>"><?php echo $pct === null ? '—' : $pct . '%'; ?></span>
                                <div style="font-size: 0.75rem; color: var(--text-muted);">
                                    <?php echo $sesiones > 0
                                        ? $asistidas . '/' . $sesiones . ' sesiones'
                                        : 'aún no se pasa lista'; ?>
                                </div>
                            </td>
                            <td><?php echo (int)$c['presentes']; ?></td>
                            <td><?php echo (int)$c['retardos']; ?></td>
                            <td>
                                <strong style="color: <?php echo $faltas > 0 ? 'var(--danger)' : 'inherit'; ?>;"><?php echo $faltas; ?></strong>
                                <?php if ((int)$c['justificados'] > 0): ?>
                                    <div style="font-size: 0.7rem; color: var(--text-muted);"><?php echo (int)$c['justificados']; ?> justificadas</div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="mis_clases.php?clase_id=<?php echo $c['id']; ?>" class="btn-action" title="Ver sesión por sesión">
                                    <i class="fa-solid fa-eye"></i>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- DETALLE SESIÓN POR SESIÓN -->
    <?php if ($claseDetalle): ?>
        <div class="panel-card" style="margin-top: 20px;">
            <div class="panel-header">
                <div class="panel-title">
                    <i class="fa-solid fa-clock-rotate-left icon-blue" style="margin-right: 8px;"></i>
                    <?php echo htmlspecialchars($claseDetalle['nombre_materia']); ?> — sesión por sesión
                </div>
                <a href="mis_clases.php" class="btn-filter" style="padding: 6px 12px; height: auto; font-size: 0.8rem;">
                    <i class="fa-solid fa-xmark"></i> Cerrar
                </a>
            </div>

            <div class="table-responsive">
                <table class="custom-table">
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Estado</th>
                            <th>Hora de registro</th>
                            <th>Método</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($detalle)): ?>
                            <tr>
                                <td colspan="4" style="text-align: center; color: var(--text-muted); padding: 30px 10px;">
                                    Todavía no se ha pasado lista en esta materia.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($detalle as $d): ?>
                                <tr>
                                    <td><?php echo date('d/m/Y', strtotime($d['fecha'])); ?></td>
                                    <td>
                                        <?php if ($d['estado']): ?>
                                            <span class="badge badge-<?php echo $d['estado']; ?>"><?php echo $d['estado']; ?></span>
                                        <?php else: ?>
                                            <span class="badge badge-falta">falta</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo $d['hora'] ? date('h:i A', strtotime($d['hora'])) : '—'; ?></td>
                                    <td style="font-size: 0.82rem;">
                                        <?php if ($d['tipo'] === 'RFID_VERIFICADO'): ?>
                                            <i class="fa-solid fa-id-card icon-blue"></i> Tarjeta + Telegram
                                        <?php elseif ($d['tipo'] === 'MATRICULA_VERIFICADA'): ?>
                                            <i class="fa-solid fa-keyboard icon-purple"></i> Matrícula + Telegram
                                        <?php elseif ($d['tipo'] === 'AUTORIZADO_DOCENTE'): ?>
                                            <i class="fa-solid fa-signature icon-green"></i>
                                            <?php echo htmlspecialchars($d['autorizo_nombre'] ?: 'Firma del docente'); ?>
                                        <?php else: ?>
                                            <span style="color: var(--text-muted);">No registraste asistencia</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

<?php endif; ?>

<?php
require_once __DIR__ . '/footer.php';
?>
