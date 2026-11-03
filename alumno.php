<?php
session_start();
require_once __DIR__ . '/includes/conexion.php';

// Verificar sesión: esta pantalla es solo para alumnos
if (!isset($_SESSION['usuario'])) {
    header("Location: login.php");
    exit;
}

$user = $_SESSION['usuario'];
if ($user['rol'] !== 'alumno') {
    header("Location: index.php");
    exit;
}

$matricula = $user['matricula'];
$error = '';
$historial = [];
$conteo = ['presente' => 0, 'retardo' => 0, 'falta' => 0, 'justificado' => 0];

const MAX_REGISTROS_INICIO = 30;

try {
    // Totales por estado
    $stmt = $pdo->prepare("SELECT estado, COUNT(*) AS total FROM asistencias WHERE usuario_id = :m GROUP BY estado");
    $stmt->execute(['m' => $matricula]);
    foreach ($stmt->fetchAll() as $r) {
        if (isset($conteo[$r['estado']])) {
            $conteo[$r['estado']] = (int)$r['total'];
        }
    }

    // Últimos registros
    $stmt = $pdo->prepare("
        SELECT a.fecha, a.hora, a.estado, c.nombre_materia, c.aula
        FROM asistencias a
        LEFT JOIN clases c ON c.id = a.clase_id
        WHERE a.usuario_id = :m
        ORDER BY a.fecha DESC, a.hora DESC
        LIMIT " . MAX_REGISTROS_INICIO
    );
    $stmt->execute(['m' => $matricula]);
    $historial = $stmt->fetchAll();
} catch (PDOException $e) {
    $error = mensajeErrorBD($e, 'cargar tu asistencia', $user['rol'] ?? null, 'alumno.php');
}

// Mismo criterio que el resto del sistema: presente, retardo y justificado cuentan como asistencia
$totalRegistros = array_sum($conteo);
$asistidas = $conteo['presente'] + $conteo['retardo'] + $conteo['justificado'];
$porcentaje = $totalRegistros > 0 ? round(($asistidas / $totalRegistros) * 100) : null;

require_once __DIR__ . '/header.php';
?>

<div class="top-bar">
    <div class="page-title">
        <span class="page-eyebrow">Mi espacio</span>
        <h2>¡Hola, <?php echo htmlspecialchars(explode(' ', trim($user['nombre']))[0]); ?>!</h2>
        <p>Matrícula <strong class="mono"><?php echo htmlspecialchars($matricula); ?></strong> · Resumen de tu asistencia.</p>
    </div>
</div>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger">
        <i class="fa-solid fa-circle-exclamation"></i>
        <span><?php echo htmlspecialchars($error); ?></span>
    </div>
<?php endif; ?>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-info">
            <span class="stat-value"><?php echo $porcentaje === null ? '—' : $porcentaje . '%'; ?></span>
            <span class="stat-label"><?php echo $porcentaje === null ? 'Aún sin registros' : 'Efectividad'; ?></span>
        </div>
        <div class="stat-icon stat-purple"><i class="fa-solid fa-percent"></i></div>
    </div>
    <div class="stat-card">
        <div class="stat-info">
            <span class="stat-value"><?php echo $conteo['presente']; ?></span>
            <span class="stat-label">Asistencias</span>
        </div>
        <div class="stat-icon stat-green"><i class="fa-solid fa-circle-check"></i></div>
    </div>
    <div class="stat-card">
        <div class="stat-info">
            <span class="stat-value"><?php echo $conteo['retardo']; ?></span>
            <span class="stat-label">Retardos</span>
        </div>
        <div class="stat-icon stat-orange"><i class="fa-solid fa-clock"></i></div>
    </div>
    <div class="stat-card">
        <div class="stat-info">
            <span class="stat-value"><?php echo $conteo['falta']; ?></span>
            <span class="stat-label">Inasistencias</span>
        </div>
        <div class="stat-icon stat-danger"><i class="fa-solid fa-circle-xmark"></i></div>
    </div>
</div>

<div class="panel-card">
    <div class="panel-header">
        <div>
            <div class="panel-title"><i class="fa-solid fa-calendar-days icon-purple" style="margin-right: 8px;"></i> Mis últimos registros</div>
            <div class="panel-sub">Se muestran los <?php echo MAX_REGISTROS_INICIO; ?> más recientes. El historial completo está en «Historial».</div>
        </div>
        <span class="badge badge-neutro"><?php echo count($historial); ?> registros</span>
    </div>

    <div class="table-responsive">
        <table class="custom-table">
            <thead>
                <tr>
                    <th>Fecha</th>
                    <th>Hora</th>
                    <th>Materia</th>
                    <th>Estado</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($historial)): ?>
                    <tr>
                        <td colspan="4" style="text-align: center; color: var(--text-muted); padding: 32px 10px;">
                            <i class="fa-solid fa-folder-open" style="font-size: 1.6rem; display: block; margin-bottom: 8px;"></i>
                            Aún no tienes asistencias ni inasistencias registradas.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($historial as $row): ?>
                        <tr>
                            <td><strong><?php echo date('d/m/Y', strtotime($row['fecha'])); ?></strong></td>
                            <td style="color: var(--text-muted);"><?php echo $row['hora'] ? date('h:i A', strtotime($row['hora'])) : '—'; ?></td>
                            <td>
                                <?php echo htmlspecialchars($row['nombre_materia'] ?? 'Clase general'); ?>
                                <?php if (!empty($row['aula'])): ?>
                                    <div class="celda-sub">Aula <?php echo htmlspecialchars($row['aula']); ?></div>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge badge-<?php echo htmlspecialchars($row['estado']); ?>"><?php echo htmlspecialchars($row['estado']); ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
require_once __DIR__ . '/footer.php';
