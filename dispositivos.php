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

// Restringir acceso solo a administrativos
if ($user['rol'] !== 'administrativo') {
    header("Location: index.php");
    exit;
}

$error = '';
$success = '';
$editando = null;

// -------------------------------------------------------------------------
// ACCIONES
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion   = $_POST['accion'] ?? '';
    $deviceId = trim($_POST['device_id'] ?? '');
    $nombre   = trim($_POST['nombre'] ?? '');
    $aula     = trim($_POST['aula'] ?? '');
    $estado   = ($_POST['estado'] ?? 'activo') === 'inactivo' ? 'inactivo' : 'activo';

    try {
        if ($accion === 'guardar') {
            if ($deviceId === '') {
                $error = 'Escribe el ID único que grabaste en el firmware de la caja.';
            } elseif (!preg_match('/^[A-Za-z0-9_\-]{3,50}$/', $deviceId)) {
                $error = 'El ID solo puede tener letras, números, guiones y guiones bajos (3 a 50 caracteres).';
            } else {
                $check = $pdo->prepare("SELECT COUNT(*) FROM dispositivos WHERE device_id = :id");
                $check->execute(['id' => $deviceId]);

                if ($check->fetchColumn() > 0) {
                    $error = "Ya existe una caja registrada con el ID \"$deviceId\".";
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO dispositivos (device_id, nombre, aula, estado)
                        VALUES (:id, :nombre, :aula, :estado)
                    ");
                    $stmt->execute([
                        'id' => $deviceId,
                        'nombre' => $nombre ?: ('Caja ' . $deviceId),
                        'aula' => $aula ?: null,
                        'estado' => $estado,
                    ]);
                    $success = "Caja \"$deviceId\" registrada. Graba ese mismo ID en la constante DEVICE_ID del ESP32.";
                }
            }
        }

        if ($accion === 'actualizar') {
            $idOriginal = trim($_POST['device_id_original'] ?? '');

            $stmt = $pdo->prepare("
                UPDATE dispositivos SET nombre = :nombre, aula = :aula, estado = :estado
                WHERE device_id = :id
            ");
            $stmt->execute([
                'nombre' => $nombre ?: ('Caja ' . $idOriginal),
                'aula' => $aula ?: null,
                'estado' => $estado,
                'id' => $idOriginal,
            ]);

            $success = 'Datos de la caja actualizados.';
        }
    } catch (PDOException $e) {
        $error = mensajeErrorBD($e, 'guardar la caja', $user['rol'] ?? null, 'dispositivos.php');
    }
}

// Eliminar caja
if (isset($_GET['eliminar'])) {
    try {
        $stmt = $pdo->prepare("DELETE FROM dispositivos WHERE device_id = :id");
        $stmt->execute(['id' => trim($_GET['eliminar'])]);

        $success = $stmt->rowCount() > 0
            ? 'Caja eliminada. Las asistencias que registró se conservan sin dispositivo.'
            : 'Esa caja ya no está registrada.';
    } catch (PDOException $e) {
        $error = mensajeErrorBD($e, 'eliminar la caja', $user['rol'] ?? null, 'dispositivos.php');
    }
}

// Cargar caja a editar
if (isset($_GET['editar'])) {
    $stmt = $pdo->prepare("SELECT * FROM dispositivos WHERE device_id = :id LIMIT 1");
    $stmt->execute(['id' => trim($_GET['editar'])]);
    $editando = $stmt->fetch() ?: null;

    if (!$editando) {
        $error = 'No se encontró esa caja.';
    }
}

// -------------------------------------------------------------------------
// LISTADO
// -------------------------------------------------------------------------
try {
    $dispositivos = $pdo->query("
        SELECT d.*,
               (SELECT COUNT(*) FROM asistencias a WHERE a.dispositivo_id = d.device_id) AS asistencias,
               (SELECT COUNT(*) FROM asistencias a WHERE a.dispositivo_id = d.device_id AND a.fecha = CURDATE()) AS asistencias_hoy
        FROM dispositivos d
        ORDER BY d.ultima_conexion DESC, d.nombre ASC
    ")->fetchAll();
} catch (PDOException $e) {
    $error = mensajeErrorBD($e, 'cargar la lista de cajas', $user['rol'] ?? null, 'dispositivos.php');
    $dispositivos = [];
}

$v = function ($campo, $defecto = '') use ($editando) {
    return htmlspecialchars($editando[$campo] ?? $defecto);
};

require_once __DIR__ . '/header.php';
?>

<div class="top-bar">
    <div class="page-title">
        <span class="page-eyebrow">Módulo de gestión</span>
        <h2>Cajas ESP32</h2>
        <p>Cada caja tiene un ID único grabado en su firmware. Así sabes desde qué aula se registró cada asistencia.</p>
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

<div class="content-layout content-layout-split">
    <!-- IZQUIERDA: FORMULARIO -->
    <div class="panel-card">
        <div class="panel-header">
            <div class="panel-title">
                <?php if ($editando): ?>
                    <i class="fa-solid fa-pen-to-square icon-orange" style="margin-right: 8px;"></i>
                    Editar Caja
                <?php else: ?>
                    <i class="fa-solid fa-microchip icon-purple" style="margin-right: 8px;"></i>
                    Registrar Caja
                <?php endif; ?>
            </div>
            <?php if ($editando): ?>
                <a href="dispositivos.php" class="btn-filter" style="padding: 6px 12px; height: auto; font-size: 0.8rem;">
                    <i class="fa-solid fa-xmark"></i> Cancelar
                </a>
            <?php endif; ?>
        </div>

        <form action="dispositivos.php" method="POST">
            <input type="hidden" name="accion" value="<?php echo $editando ? 'actualizar' : 'guardar'; ?>">
            <?php if ($editando): ?>
                <input type="hidden" name="device_id_original" value="<?php echo $v('device_id'); ?>">
            <?php endif; ?>

            <div class="form-group">
                <label for="device_id">ID ÚNICO DE LA CAJA *</label>
                <input type="text" id="device_id" name="device_id" class="form-select"
                       placeholder="Ej: ESP32-A214-01" required autocomplete="off"
                       value="<?php echo $v('device_id'); ?>"
                       <?php echo $editando ? 'readonly style="opacity:.6; cursor:not-allowed;"' : ''; ?>>
                <p style="font-size: 0.75rem; color: var(--text-muted); margin-top: 4px;">
                    Debe coincidir exactamente con la constante <code>DEVICE_ID</code> del archivo
                    <code>esp32_code.ino</code> de esa caja.
                </p>
            </div>

            <div class="form-group">
                <label for="nombre">NOMBRE DESCRIPTIVO</label>
                <input type="text" id="nombre" name="nombre" class="form-select"
                       placeholder="Ej: Caja del laboratorio" value="<?php echo $v('nombre'); ?>">
            </div>

            <div class="form-group">
                <label for="aula">AULA / UBICACIÓN</label>
                <input type="text" id="aula" name="aula" class="form-select" placeholder="Ej: A214" value="<?php echo $v('aula'); ?>">
            </div>

            <div class="form-group">
                <label for="estado">ESTADO</label>
                <select id="estado" name="estado" class="form-select">
                    <option value="activo" <?php echo ($v('estado', 'activo') === 'activo') ? 'selected' : ''; ?>>Activa</option>
                    <option value="inactivo" <?php echo ($v('estado') === 'inactivo') ? 'selected' : ''; ?>>Inactiva (rechaza lecturas)</option>
                </select>
            </div>

            <button type="submit" class="btn-primary" style="margin-top: 10px;">
                <i class="fa-solid fa-floppy-disk"></i>
                <span><?php echo $editando ? 'Guardar Cambios' : 'Registrar Caja'; ?></span>
            </button>
        </form>

        <div style="margin-top: 20px; padding: 12px; border-radius: 10px; background: var(--wine-50); font-size: 0.8rem; color: var(--text-muted);">
            <i class="fa-solid fa-circle-info icon-blue"></i>
            No es obligatorio darlas de alta a mano: cuando una caja nueva envía su primera lectura,
            aparece sola en esta lista y solo tienes que ponerle nombre y aula.
        </div>
    </div>

    <!-- DERECHA: LISTADO -->
    <div class="panel-card">
        <div class="panel-header">
            <div class="panel-title">
                <i class="fa-solid fa-network-wired icon-blue" style="margin-right: 8px;"></i>
                Cajas registradas (<?php echo count($dispositivos); ?>)
            </div>
        </div>

        <div class="table-responsive">
            <table class="custom-table">
                <thead>
                    <tr>
                        <th>Caja</th>
                        <th>Ubicación</th>
                        <th>Estado</th>
                        <th>Última conexión</th>
                        <th>Registros</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($dispositivos)): ?>
                        <tr>
                            <td colspan="6" style="text-align: center; color: var(--text-muted); padding: 30px 10px;">
                                Ninguna caja se ha conectado todavía.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($dispositivos as $d): ?>
                            <?php
                                $enLinea = $d['ultima_conexion']
                                    && (strtotime($d['ultima_conexion']) > strtotime('-2 minutes'));
                            ?>
                            <tr>
                                <td>
                                    <div><strong><?php echo htmlspecialchars($d['nombre'] ?: $d['device_id']); ?></strong></div>
                                    <div style="font-size: 0.8rem; color: var(--text-muted);">
                                        <code><?php echo htmlspecialchars($d['device_id']); ?></code>
                                    </div>
                                </td>
                                <td><?php echo htmlspecialchars($d['aula'] ?: 'Sin ubicación'); ?></td>
                                <td>
                                    <span class="badge badge-<?php echo $d['estado'] === 'activo' ? 'active' : 'inactive'; ?>">
                                        <?php echo $d['estado'] === 'activo' ? 'activa' : 'inactiva'; ?>
                                    </span>
                                    <?php if ($enLinea): ?>
                                        <div style="font-size: 0.7rem; color: var(--wine-700); margin-top: 3px;">
                                            <i class="fa-solid fa-circle" style="font-size: 0.5rem;"></i> En línea
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td style="font-size: 0.82rem;">
                                    <?php if ($d['ultima_conexion']): ?>
                                        <?php echo date('d/m/Y h:i A', strtotime($d['ultima_conexion'])); ?>
                                        <?php if ($d['ultima_ip']): ?>
                                            <div style="color: var(--text-muted); font-size: 0.75rem;"><?php echo htmlspecialchars($d['ultima_ip']); ?></div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span style="color: var(--text-muted);">Nunca</span>
                                    <?php endif; ?>
                                </td>
                                <td style="font-size: 0.82rem;">
                                    <div><strong><?php echo (int)$d['asistencias']; ?></strong> asistencias</div>
                                    <div style="color: var(--text-muted);"><?php echo (int)$d['asistencias_hoy']; ?> hoy</div>
                                    <div style="color: var(--text-muted); font-size: 0.75rem;"><?php echo (int)$d['total_lecturas']; ?> lecturas</div>
                                </td>
                                <td>
                                    <div style="display: flex; gap: 6px;">
                                        <a href="dispositivos.php?editar=<?php echo urlencode($d['device_id']); ?>" class="btn-action" title="Editar caja">
                                            <i class="fa-solid fa-pen"></i>
                                        </a>
                                        <a href="dispositivos.php?eliminar=<?php echo urlencode($d['device_id']); ?>" class="btn-action btn-delete"
                                           onclick="return confirm('¿Eliminar la caja <?php echo htmlspecialchars($d['device_id'], ENT_QUOTES); ?> del sistema?')"
                                           title="Eliminar caja">
                                            <i class="fa-solid fa-trash"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
require_once __DIR__ . '/footer.php';
?>
