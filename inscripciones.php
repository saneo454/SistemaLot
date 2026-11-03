<?php
session_start();
require_once __DIR__ . '/includes/conexion.php';

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

// -------------------------------------------------------------------------
// ACCIONES
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';
    $claseId = (int)($_POST['clase_id'] ?? 0);

    try {
        // Inscribir alumnos seleccionados
        if ($accion === 'inscribir') {
            $alumnos = $_POST['alumnos'] ?? [];

            if ($claseId === 0 || empty($alumnos)) {
                $error = 'Selecciona al menos un alumno para inscribir.';
            } else {
                $stmtClase = $pdo->prepare("SELECT * FROM clases WHERE id = :id LIMIT 1");
                $stmtClase->execute(['id' => $claseId]);
                $claseDestino = $stmtClase->fetch();

                if (!$claseDestino) {
                    $error = 'La clase seleccionada no existe.';
                } else {
                    $stmtConflict = $pdo->prepare("
                        SELECT c.nombre_materia, c.hora_inicio, c.hora_fin
                        FROM inscripciones i
                        JOIN clases c ON c.id = i.clase_id
                        WHERE i.alumno_id = :alumno_id
                          AND c.dia_semana = :dia
                          AND c.hora_inicio < :hora_fin
                          AND c.hora_fin > :hora_inicio
                          AND c.id != :clase_id
                        LIMIT 1
                    ");

                    $stmtInsert = $pdo->prepare("INSERT IGNORE INTO inscripciones (clase_id, alumno_id) VALUES (:clase_id, :alumno_id)");
                    $nuevos = 0;
                    $rechazados = [];

                    foreach ($alumnos as $alumnoId) {
                        $mat = trim($alumnoId);
                        $stmtConflict->execute([
                            'alumno_id'   => $mat,
                            'dia'         => $claseDestino['dia_semana'],
                            'hora_inicio' => $claseDestino['hora_inicio'],
                            'hora_fin'    => $claseDestino['hora_fin'],
                            'clase_id'    => $claseId,
                        ]);
                        $conflicto = $stmtConflict->fetch();

                        if ($conflicto) {
                            $stmtUsr = $pdo->prepare("SELECT nombre FROM usuarios WHERE matricula = :m LIMIT 1");
                            $stmtUsr->execute(['m' => $mat]);
                            $u = $stmtUsr->fetch();
                            $nombreUsr = $u ? $u['nombre'] : $mat;
                            $rechazados[] = "$nombreUsr (empalme con '{$conflicto['nombre_materia']}')";
                        } else {
                            $stmtInsert->execute(['clase_id' => $claseId, 'alumno_id' => $mat]);
                            $nuevos += $stmtInsert->rowCount();
                        }
                    }

                    if (!empty($rechazados)) {
                        $error = 'No se pudo inscribir a: ' . implode(', ', $rechazados) . '.';
                    }
                    if ($nuevos > 0) {
                        $success = "Se inscribió $nuevos alumno" . ($nuevos === 1 ? '' : 's') . " en la clase.";
                    } elseif (empty($rechazados)) {
                        $success = 'Esos alumnos ya estaban inscritos en la clase.';
                    }
                }
            }
        }

        // Inscribir en bloque a todos los del grupo que coincide con la clase
        if ($accion === 'inscribir_grupo') {
            if ($claseId === 0) {
                $error = 'Selecciona primero una clase.';
            } else {
                $stmt = $pdo->prepare("
                    INSERT IGNORE INTO inscripciones (clase_id, alumno_id)
                    SELECT c.id, u.matricula
                    FROM clases c
                    JOIN usuarios u
                      ON u.carrera = c.carrera AND u.grupo = c.grupo AND u.semestre = c.semestre
                    WHERE c.id = :clase_id AND u.rol = 'alumno' AND u.estado = 'activo'
                      AND u.matricula NOT IN (
                          SELECT i2.alumno_id
                          FROM inscripciones i2
                          JOIN clases c2 ON c2.id = i2.clase_id
                          WHERE c2.dia_semana = c.dia_semana
                            AND c2.hora_inicio < c.hora_fin
                            AND c2.hora_fin > c.hora_inicio
                            AND c2.id != c.id
                      )
                ");
                $stmt->execute(['clase_id' => $claseId]);
                $n = $stmt->rowCount();

                $success = $n > 0
                    ? "Se inscribió $n alumno" . ($n === 1 ? '' : 's') . " del grupo configurado en la clase."
                    : 'No se encontraron alumnos nuevos (o sin empalme de horario) con la carrera, grupo y semestre de esa clase.';
            }
        }

        // Dar de baja a un alumno de la clase
        if ($accion === 'dar_baja') {
            $alumnoId = trim($_POST['alumno_id'] ?? '');
            $stmt = $pdo->prepare("DELETE FROM inscripciones WHERE clase_id = :clase_id AND alumno_id = :alumno_id");
            $stmt->execute(['clase_id' => $claseId, 'alumno_id' => $alumnoId]);

            $success = $stmt->rowCount() > 0
                ? 'Alumno dado de baja de la clase. Su historial de asistencias se conserva.'
                : 'Ese alumno ya no estaba inscrito en la clase.';
        }
    } catch (PDOException $e) {
        $error = mensajeErrorBD($e, 'actualizar la inscripción', $user['rol'] ?? null, 'inscripciones.php');
    }

    // Conservar la clase seleccionada tras la acción
    if ($claseId > 0 && !isset($_GET['clase_id'])) {
        $_GET['clase_id'] = $claseId;
    }
}

// -------------------------------------------------------------------------
// DATOS
// -------------------------------------------------------------------------
$claseSeleccionada = null;
$inscritos = [];
$disponibles = [];

try {
    $clases = $pdo->query("
        SELECT c.*, u.nombre AS docente_nombre,
               (SELECT COUNT(*) FROM inscripciones i WHERE i.clase_id = c.id) AS inscritos
        FROM clases c
        JOIN usuarios u ON c.docente_id = u.matricula
        ORDER BY c.nombre_materia ASC,
                 FIELD(c.dia_semana, 'lunes','martes','miercoles','jueves','viernes','sabado')
    ")->fetchAll();

    $claseId = isset($_GET['clase_id']) ? (int)$_GET['clase_id'] : 0;

    if ($claseId > 0) {
        $stmt = $pdo->prepare("
            SELECT c.*, u.nombre AS docente_nombre
            FROM clases c JOIN usuarios u ON c.docente_id = u.matricula
            WHERE c.id = :id LIMIT 1
        ");
        $stmt->execute(['id' => $claseId]);
        $claseSeleccionada = $stmt->fetch() ?: null;

        if (!$claseSeleccionada) {
            $error = 'No se encontró la clase seleccionada.';
        } else {
            // Alumnos inscritos, con su porcentaje de asistencia en esa clase
            $stmt = $pdo->prepare("
                SELECT u.matricula, u.nombre, u.carrera, u.grupo, u.semestre, u.estado,
                       i.fecha_inscripcion,
                       (SELECT COUNT(*) FROM asistencias a
                         WHERE a.usuario_id = u.matricula AND a.clase_id = :clase_id) AS registros,
                       (SELECT COUNT(*) FROM asistencias a
                         WHERE a.usuario_id = u.matricula AND a.clase_id = :clase_id2
                           AND a.estado IN ('presente','retardo','justificado')) AS asistencias
                FROM inscripciones i
                JOIN usuarios u ON u.matricula = i.alumno_id
                WHERE i.clase_id = :clase_id3
                ORDER BY u.nombre ASC
            ");
            $stmt->execute(['clase_id' => $claseId, 'clase_id2' => $claseId, 'clase_id3' => $claseId]);
            $inscritos = $stmt->fetchAll();

            // Alumnos activos que aún no están en la clase y no tienen empalmes de horario
            $stmt = $pdo->prepare("
                SELECT matricula, nombre, carrera, grupo, semestre
                FROM usuarios
                WHERE rol = 'alumno' AND estado = 'activo'
                  AND matricula NOT IN (SELECT alumno_id FROM inscripciones WHERE clase_id = :clase_id)
                  AND matricula NOT IN (
                      SELECT i.alumno_id
                      FROM inscripciones i
                      JOIN clases c ON c.id = i.clase_id
                      WHERE c.dia_semana = :dia_semana
                        AND c.hora_inicio < :hora_fin
                        AND c.hora_fin > :hora_inicio
                        AND c.id != :clase_id2
                  )
                ORDER BY nombre ASC
            ");
            $stmt->execute([
                'clase_id'    => $claseId,
                'dia_semana'  => $claseSeleccionada['dia_semana'],
                'hora_fin'    => $claseSeleccionada['hora_fin'],
                'hora_inicio' => $claseSeleccionada['hora_inicio'],
                'clase_id2'   => $claseId,
            ]);
            $disponibles = $stmt->fetchAll();
        }
    }
} catch (PDOException $e) {
    $error = mensajeErrorBD($e, 'cargar las inscripciones', $user['rol'] ?? null, 'inscripciones.php');
    $clases = [];
}

require_once __DIR__ . '/header.php';
?>

<div class="top-bar">
    <div class="page-title">
        <span class="page-eyebrow">Módulo de gestión</span>
        <h2>Inscripciones</h2>
        <p>Asigna qué alumnos pertenecen a cada clase. La caja usa estas inscripciones para saber a qué materia corresponde cada pase de lista.</p>
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

<!-- SELECTOR DE CLASE -->
<div class="filter-card">
    <form action="inscripciones.php" method="GET">
        <div class="filter-grid">
            <div class="form-group" style="margin-bottom: 0; grid-column: span 2;">
                <label for="clase_id">CLASE A ADMINISTRAR</label>
                <select id="clase_id" name="clase_id" class="form-select" onchange="this.form.submit()">
                    <option value="">Selecciona una clase...</option>
                    <?php foreach ($clases as $c): ?>
                        <option value="<?php echo $c['id']; ?>" <?php echo ($claseSeleccionada && $claseSeleccionada['id'] == $c['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($c['nombre_materia']); ?>
                            — <?php echo nombreDia($c['dia_semana']); ?>
                            <?php echo date('h:i A', strtotime($c['hora_inicio'])); ?>
                            (<?php echo (int)$c['inscritos']; ?> inscritos)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="display: flex; align-items: flex-end;">
                <button type="submit" class="btn-filter" style="width: 100%;">
                    <i class="fa-solid fa-magnifying-glass"></i> Ver
                </button>
            </div>
        </div>
    </form>
</div>

<?php if (!$claseSeleccionada): ?>
    <div class="panel-card">
        <div style="text-align: center; color: var(--text-muted); padding: 40px 20px;">
            <i class="fa-solid fa-hand-pointer" style="font-size: 2rem; margin-bottom: 12px; display: block;"></i>
            Selecciona una clase arriba para ver e inscribir a sus alumnos.
        </div>
    </div>
<?php else: ?>

    <div class="content-layout content-layout-split">
        <!-- IZQUIERDA: INSCRIBIR -->
        <div class="panel-card">
            <div class="panel-header">
                <div class="panel-title">
                    <i class="fa-solid fa-user-plus icon-purple" style="margin-right: 8px;"></i>
                    Inscribir alumnos
                </div>
            </div>

            <div style="background: var(--wine-50); border-radius: 10px; padding: 12px; margin-bottom: 16px; font-size: 0.85rem;">
                <strong><?php echo htmlspecialchars($claseSeleccionada['nombre_materia']); ?></strong><br>
                <span style="color: var(--text-muted);">
                    <?php echo ucfirst($claseSeleccionada['dia_semana']); ?>
                    de <?php echo date('h:i A', strtotime($claseSeleccionada['hora_inicio'])); ?>
                    a <?php echo date('h:i A', strtotime($claseSeleccionada['hora_fin'])); ?><br>
                    Docente: <?php echo htmlspecialchars($claseSeleccionada['docente_nombre']); ?>
                    <?php if ($claseSeleccionada['aula']): ?> · Aula <?php echo htmlspecialchars($claseSeleccionada['aula']); ?><?php endif; ?>
                </span>
            </div>

            <!-- Inscripción en bloque por grupo -->
            <?php if ($claseSeleccionada['carrera'] || $claseSeleccionada['grupo'] || $claseSeleccionada['semestre']): ?>
                <form action="inscripciones.php" method="POST" style="margin-bottom: 18px;">
                    <input type="hidden" name="accion" value="inscribir_grupo">
                    <input type="hidden" name="clase_id" value="<?php echo (int)$claseSeleccionada['id']; ?>">
                    <button type="submit" class="btn-filter" style="width: 100%;">
                        <i class="fa-solid fa-users-rectangle"></i>
                        Inscribir a todo el grupo configurado
                    </button>
                    <p style="font-size: 0.75rem; color: var(--text-muted); margin-top: 6px;">
                        Inscribe automáticamente a los alumnos activos de
                        <?php echo htmlspecialchars($claseSeleccionada['carrera'] ?: 'cualquier carrera'); ?>,
                        <?php echo $claseSeleccionada['semestre'] ? $claseSeleccionada['semestre'] . '° semestre' : 'cualquier semestre'; ?>,
                        grupo <?php echo htmlspecialchars($claseSeleccionada['grupo'] ?: 'cualquiera'); ?>.
                    </p>
                </form>
            <?php endif; ?>

            <!-- Inscripción manual -->
            <form action="inscripciones.php" method="POST">
                <input type="hidden" name="accion" value="inscribir">
                <input type="hidden" name="clase_id" value="<?php echo (int)$claseSeleccionada['id']; ?>">

                <div class="form-group">
                    <label for="buscarAlumno">BUSCAR ALUMNO</label>
                    <input type="text" id="buscarAlumno" class="form-select" placeholder="Escribe un nombre o matrícula..."
                           onkeyup="filtrarAlumnos(this.value)" autocomplete="off">
                </div>

                <div id="listaDisponibles" style="max-height: 320px; overflow-y: auto; border: 1px solid var(--line); border-radius: 10px; padding: 8px;">
                    <?php if (empty($disponibles)): ?>
                        <p style="color: var(--text-muted); font-size: 0.85rem; text-align: center; padding: 20px;">
                            Todos los alumnos activos ya están inscritos en esta clase.
                        </p>
                    <?php else: ?>
                        <?php foreach ($disponibles as $al): ?>
                            <label class="fila-alumno"
                                   data-buscar="<?php echo htmlspecialchars(strtolower($al['nombre'] . ' ' . $al['matricula'])); ?>">
                                <input type="checkbox" name="alumnos[]" value="<?php echo htmlspecialchars($al['matricula']); ?>">
                                <span>
                                    <strong><?php echo htmlspecialchars($al['nombre']); ?></strong>
                                    <small style="color: var(--text-muted); display: block;">
                                        <?php echo htmlspecialchars($al['matricula']); ?>
                                        <?php if ($al['grupo'] || $al['semestre']): ?>
                                            · <?php echo $al['semestre'] ? $al['semestre'] . '° Sem' : ''; ?>
                                            <?php echo $al['grupo'] ? 'Grupo ' . htmlspecialchars($al['grupo']) : ''; ?>
                                        <?php endif; ?>
                                    </small>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <?php if (!empty($disponibles)): ?>
                    <button type="submit" class="btn-primary" style="margin-top: 15px;">
                        <i class="fa-solid fa-check"></i>
                        <span>Inscribir seleccionados</span>
                    </button>
                <?php endif; ?>
            </form>
        </div>

        <!-- DERECHA: INSCRITOS -->
        <div class="panel-card">
            <div class="panel-header">
                <div class="panel-title">
                    <i class="fa-solid fa-list-check icon-blue" style="margin-right: 8px;"></i>
                    Alumnos inscritos (<?php echo count($inscritos); ?>)
                </div>
            </div>

            <div class="table-responsive">
                <table class="custom-table">
                    <thead>
                        <tr>
                            <th>Alumno</th>
                            <th>Detalles</th>
                            <th>Asistencia</th>
                            <th>Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($inscritos)): ?>
                            <tr>
                                <td colspan="4" style="text-align: center; color: var(--text-muted); padding: 30px 10px;">
                                    Todavía no hay alumnos inscritos en esta clase.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($inscritos as $al): ?>
                                <?php
                                    $registros = (int)$al['registros'];
                                    $asistio = (int)$al['asistencias'];
                                    $pct = $registros > 0 ? round(($asistio / $registros) * 100) : null;
                                ?>
                                <tr>
                                    <td>
                                        <div><strong><?php echo htmlspecialchars($al['nombre']); ?></strong></div>
                                        <div style="font-size: 0.8rem; color: var(--text-muted);"><?php echo htmlspecialchars($al['matricula']); ?></div>
                                    </td>
                                    <td style="font-size: 0.8rem; color: var(--text-muted);">
                                        <?php echo htmlspecialchars($al['carrera'] ?: 'Sin carrera'); ?><br>
                                        <?php echo $al['semestre'] ? $al['semestre'] . '° Sem' : ''; ?>
                                        <?php echo $al['grupo'] ? ' · Grupo ' . htmlspecialchars($al['grupo']) : ''; ?>
                                        <?php if ($al['estado'] !== 'activo'): ?>
                                            <span class="badge badge-inactive">inactivo</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($pct === null): ?>
                                            <span style="color: var(--text-muted); font-size: 0.8rem;">Sin registros</span>
                                        <?php else: ?>
                                            <span class="badge badge-<?php echo $pct >= 80 ? 'presente' : ($pct >= 60 ? 'retardo' : 'falta'); ?>">
                                                <?php echo $pct; ?>%
                                            </span>
                                            <div style="font-size: 0.75rem; color: var(--text-muted);"><?php echo $asistio; ?>/<?php echo $registros; ?> sesiones</div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <form action="inscripciones.php" method="POST" style="display: inline;"
                                              onsubmit="return confirm('¿Dar de baja a <?php echo htmlspecialchars($al['nombre'], ENT_QUOTES); ?> de esta clase?')">
                                            <input type="hidden" name="accion" value="dar_baja">
                                            <input type="hidden" name="clase_id" value="<?php echo (int)$claseSeleccionada['id']; ?>">
                                            <input type="hidden" name="alumno_id" value="<?php echo htmlspecialchars($al['matricula']); ?>">
                                            <button type="submit" class="btn-action btn-delete" title="Dar de baja de la clase">
                                                <i class="fa-solid fa-user-minus"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

<?php endif; ?>

<style>
.fila-alumno {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px 10px;
    border-radius: 8px;
    cursor: pointer;
    font-size: 0.85rem;
}
.fila-alumno:hover { background: var(--wine-50); }
.fila-alumno input { width: 16px; height: 16px; cursor: pointer; }
</style>

<script>
function filtrarAlumnos(texto) {
    const busqueda = texto.toLowerCase().trim();
    document.querySelectorAll('#listaDisponibles .fila-alumno').forEach(fila => {
        fila.style.display = fila.dataset.buscar.includes(busqueda) ? 'flex' : 'none';
    });
}
</script>

<?php
require_once __DIR__ . '/footer.php';
?>
