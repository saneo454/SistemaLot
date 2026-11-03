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
$rol = $user['rol'];

// Solo docentes y administrativos
if (!in_array($rol, ['docente', 'administrativo'], true)) {
    header("Location: index.php");
    exit;
}

$error = '';
$success = '';

$esDocente = ($rol === 'docente');

/**
 * Comprueba que la clase le pertenezca al docente en sesión.
 */
function claseDelDocente($pdo, $claseId, $matricula, $esDocente) {
    $sql = "SELECT c.*, u.nombre AS docente_nombre FROM clases c
            JOIN usuarios u ON u.matricula = c.docente_id
            WHERE c.id = :id";
    $params = ['id' => $claseId];

    if ($esDocente) {
        $sql .= " AND c.docente_id = :docente";
        $params['docente'] = $matricula;
    }

    $stmt = $pdo->prepare($sql . " LIMIT 1");
    $stmt->execute($params);
    return $stmt->fetch() ?: null;
}

// -------------------------------------------------------------------------
// ACCIONES: marcar asistencia manualmente
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $claseId  = (int)($_POST['clase_id'] ?? 0);
    $fecha    = $_POST['fecha'] ?? fechaHoy();
    $alumnoId = trim($_POST['alumno_id'] ?? '');
    $estado   = $_POST['estado'] ?? '';
    $accion   = $_POST['accion'] ?? '';

    $clase = claseDelDocente($pdo, $claseId, $user['matricula'], $esDocente);

    if (!$clase) {
        $error = 'No tienes permiso para pasar lista en esa clase.';
    } elseif (!in_array($estado, ['presente', 'retardo', 'falta', 'justificado'], true)) {
        $error = 'El estado seleccionado no es válido.';
    } else {
        try {
            // ¿Ya hay un registro de ese alumno en esa clase y fecha?
            $stmt = $pdo->prepare("
                SELECT id FROM asistencias
                WHERE usuario_id = :alumno AND clase_id = :clase AND fecha = :fecha
                LIMIT 1
            ");
            $stmt->execute(['alumno' => $alumnoId, 'clase' => $claseId, 'fecha' => $fecha]);
            $existente = $stmt->fetchColumn();

            if ($accion === 'quitar') {
                if ($existente) {
                    $pdo->prepare("DELETE FROM asistencias WHERE id = :id")->execute(['id' => $existente]);
                    $success = 'Registro eliminado.';
                } else {
                    $error = 'Ese alumno no tenía registro en esta fecha.';
                }
            } elseif ($existente) {
                $stmt = $pdo->prepare("
                    UPDATE asistencias
                    SET estado = :estado, tipo = 'AUTORIZADO_DOCENTE', docente_autorizo_id = :docente
                    WHERE id = :id
                ");
                $stmt->execute(['estado' => $estado, 'docente' => $user['matricula'], 'id' => $existente]);
                $success = 'Asistencia actualizada a "' . $estado . '".';
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO asistencias (usuario_id, clase_id, fecha, hora, tipo, estado, docente_autorizo_id)
                    VALUES (:alumno, :clase, :fecha, :hora, 'AUTORIZADO_DOCENTE', :estado, :docente)
                ");
                $stmt->execute([
                    'alumno' => $alumnoId,
                    'clase' => $claseId,
                    'fecha' => $fecha,
                    'hora' => ($fecha === fechaHoy()) ? horaAhora() : $clase['hora_inicio'],
                    'estado' => $estado,
                    'docente' => $user['matricula'],
                ]);
                $success = 'Asistencia registrada como "' . $estado . '".';
            }
        } catch (PDOException $e) {
            $error = mensajeErrorBD($e, 'guardar el pase de lista', $user['rol'] ?? null, 'pase_lista.php');
        }
    }

    // Mantener la vista donde estaba
    $_GET['clase_id'] = $claseId;
    $_GET['fecha'] = $fecha;
}

// -------------------------------------------------------------------------
// DATOS
// -------------------------------------------------------------------------
$claseSeleccionada = null;
$listaAlumnos = [];
$resumen = ['presente' => 0, 'retardo' => 0, 'falta' => 0, 'justificado' => 0, 'sin_registro' => 0];

$fechaConsulta = $_GET['fecha'] ?? fechaHoy();

try {
    // Clases del docente (o todas, si es administrativo)
    if ($esDocente) {
        $stmt = $pdo->prepare("
            SELECT c.*, (SELECT COUNT(*) FROM inscripciones i WHERE i.clase_id = c.id) AS inscritos
            FROM clases c
            WHERE c.docente_id = :docente
            ORDER BY FIELD(c.dia_semana,'lunes','martes','miercoles','jueves','viernes','sabado'), c.hora_inicio
        ");
        $stmt->execute(['docente' => $user['matricula']]);
        $misClases = $stmt->fetchAll();
    } else {
        $misClases = $pdo->query("
            SELECT c.*, (SELECT COUNT(*) FROM inscripciones i WHERE i.clase_id = c.id) AS inscritos
            FROM clases c
            ORDER BY FIELD(c.dia_semana,'lunes','martes','miercoles','jueves','viernes','sabado'), c.hora_inicio
        ")->fetchAll();
    }

    $claseId = isset($_GET['clase_id']) ? (int)$_GET['clase_id'] : 0;

    if ($claseId > 0) {
        $claseSeleccionada = claseDelDocente($pdo, $claseId, $user['matricula'], $esDocente);

        if (!$claseSeleccionada) {
            $error = $error ?: 'Esa clase no está entre las tuyas.';
        } else {
            generarFaltasParaClaseYFecha($pdo, $claseId, $fechaConsulta);
            $stmt = $pdo->prepare("
                SELECT u.matricula, u.nombre, u.grupo, u.semestre, u.tarjeta_rfid, u.id_telegram,
                       a.estado, a.hora, a.tipo, a.dispositivo_id,
                       d.nombre AS autorizo_nombre
                FROM inscripciones i
                JOIN usuarios u ON u.matricula = i.alumno_id
                LEFT JOIN asistencias a
                       ON a.usuario_id = u.matricula AND a.clase_id = i.clase_id AND a.fecha = :fecha
                LEFT JOIN usuarios d ON d.matricula = a.docente_autorizo_id
                WHERE i.clase_id = :clase_id
                ORDER BY u.nombre ASC
            ");
            $stmt->execute(['fecha' => $fechaConsulta, 'clase_id' => $claseId]);
            $listaAlumnos = $stmt->fetchAll();

            foreach ($listaAlumnos as $al) {
                $clave = $al['estado'] ?: 'sin_registro';
                if (isset($resumen[$clave])) {
                    $resumen[$clave]++;
                }
            }
        }
    }
} catch (PDOException $e) {
    $error = mensajeErrorBD($e, 'cargar el pase de lista', $user['rol'] ?? null, 'pase_lista.php');
    $misClases = [];
}

require_once __DIR__ . '/header.php';
?>

<div class="top-bar">
    <div class="page-title">
        <span class="page-eyebrow">Docencia</span>
        <h2>Pase de Lista</h2>
        <p>Consulta quién registró asistencia en cada sesión y corrige manualmente lo que haga falta.</p>
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

<!-- SELECTOR -->
<div class="filter-card">
    <form action="pase_lista.php" method="GET">
        <div class="filter-grid">
            <div class="form-group" style="margin-bottom: 0; grid-column: span 2;">
                <label for="clase_id">CLASE</label>
                <select id="clase_id" name="clase_id" class="form-select" onchange="this.form.submit()">
                    <option value="">Selecciona una clase...</option>
                    <?php foreach ($misClases as $c): ?>
                        <option value="<?php echo $c['id']; ?>" <?php echo ($claseSeleccionada && $claseSeleccionada['id'] == $c['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($c['nombre_materia']); ?>
                            — <?php echo nombreDia($c['dia_semana']); ?>
                            <?php echo date('h:i A', strtotime($c['hora_inicio'])); ?>
                            (<?php echo (int)$c['inscritos']; ?> alumnos)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group" style="margin-bottom: 0;">
                <label for="fecha">FECHA DE LA SESIÓN</label>
                <input type="date" id="fecha" name="fecha" class="form-date"
                       value="<?php echo htmlspecialchars($fechaConsulta); ?>" max="<?php echo fechaHoy(); ?>">
            </div>

            <div style="display: flex; align-items: flex-end;">
                <button type="submit" class="btn-filter" style="width: 100%;">
                    <i class="fa-solid fa-magnifying-glass"></i> Consultar
                </button>
            </div>
        </div>
    </form>
</div>

<?php if (!$claseSeleccionada): ?>

    <!-- SIN CLASE SELECCIONADA: mostrar el horario del docente -->
    <div class="panel-card">
        <div class="panel-header">
            <div class="panel-title">
                <i class="fa-solid fa-calendar-days icon-blue" style="margin-right: 8px;"></i>
                <?php echo $esDocente ? 'Mis clases' : 'Todas las clases'; ?>
            </div>
        </div>

        <div class="table-responsive">
            <table class="custom-table">
                <thead>
                    <tr>
                        <th>Día</th>
                        <th>Horario</th>
                        <th>Materia</th>
                        <th>Aula</th>
                        <th>Alumnos</th>
                        <th>Acción</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($misClases)): ?>
                        <tr>
                            <td colspan="6" style="text-align: center; color: var(--text-muted); padding: 30px 10px;">
                                No tienes clases asignadas. Pide al administrador que te las registre.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($misClases as $c): ?>
                            <?php $esHoy = (obtenerDiaSemanaEspanol() === $c['dia_semana']); ?>
                            <tr <?php echo $esHoy ? 'style="background: var(--wine-50);"' : ''; ?>>
                                <td>
                                    <span class="badge badge-role-alumno">
                                        <?php echo nombreDia($c['dia_semana']); ?>
                                    </span>
                                    <?php if ($esHoy): ?>
                                        <div style="font-size: 0.7rem; color: var(--wine-700); margin-top: 3px;">HOY</div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div><strong><?php echo date('h:i A', strtotime($c['hora_inicio'])); ?></strong></div>
                                    <div style="font-size: 0.8rem; color: var(--text-muted);">a <?php echo date('h:i A', strtotime($c['hora_fin'])); ?></div>
                                </td>
                                <td><strong><?php echo htmlspecialchars($c['nombre_materia']); ?></strong></td>
                                <td><?php echo htmlspecialchars($c['aula'] ?: 'Sin asignar'); ?></td>
                                <td><?php echo (int)$c['inscritos']; ?></td>
                                <td>
                                    <a href="pase_lista.php?clase_id=<?php echo $c['id']; ?>" class="btn-filter"
                                       style="padding: 6px 12px; height: auto; font-size: 0.8rem;">
                                        <i class="fa-solid fa-clipboard-user"></i> Pasar lista
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

<?php else: ?>

    <!-- RESUMEN DE LA SESIÓN -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-info">
                <span class="stat-value"><?php echo $resumen['presente']; ?></span>
                <span class="stat-label">Presentes</span>
            </div>
            <div class="stat-icon stat-green"><i class="fa-solid fa-circle-check"></i></div>
        </div>
        <div class="stat-card">
            <div class="stat-info">
                <span class="stat-value"><?php echo $resumen['retardo']; ?></span>
                <span class="stat-label">Retardos</span>
            </div>
            <div class="stat-icon stat-orange"><i class="fa-solid fa-clock"></i></div>
        </div>
        <div class="stat-card">
            <div class="stat-info">
                <span class="stat-value"><?php echo $resumen['falta'] + $resumen['sin_registro']; ?></span>
                <span class="stat-label">Faltas / Sin registro</span>
            </div>
            <div class="stat-icon stat-danger"><i class="fa-solid fa-circle-xmark"></i></div>
        </div>
        <div class="stat-card">
            <div class="stat-info">
                <span class="stat-value"><?php echo count($listaAlumnos); ?></span>
                <span class="stat-label">Alumnos inscritos</span>
            </div>
            <div class="stat-icon stat-blue"><i class="fa-solid fa-users"></i></div>
        </div>
    </div>

    <div class="panel-card">
        <div class="panel-header">
            <div class="panel-title">
                <i class="fa-solid fa-clipboard-user icon-purple" style="margin-right: 8px;"></i>
                <?php echo htmlspecialchars($claseSeleccionada['nombre_materia']); ?>
                — <?php echo date('d/m/Y', strtotime($fechaConsulta)); ?>
            </div>
            <span style="font-size: 0.8rem; color: var(--text-muted);">
                <?php echo ucfirst($claseSeleccionada['dia_semana']); ?>
                <?php echo date('h:i A', strtotime($claseSeleccionada['hora_inicio'])); ?>
                · Aula <?php echo htmlspecialchars($claseSeleccionada['aula'] ?: 'S/A'); ?>
            </span>
        </div>

        <?php
            $numerosDia = ['lunes' => 1, 'martes' => 2, 'miercoles' => 3, 'jueves' => 4, 'viernes' => 5, 'sabado' => 6];
            $diaDeLaFecha = (int)date('N', strtotime($fechaConsulta));
        ?>
        <?php if ($diaDeLaFecha !== ($numerosDia[$claseSeleccionada['dia_semana']] ?? 0)): ?>
            <div class="alert alert-danger" style="margin: 0 0 16px 0;">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <span>Ojo: el <?php echo date('d/m/Y', strtotime($fechaConsulta)); ?> no cae en <?php echo ucfirst($claseSeleccionada['dia_semana']); ?>, que es el día de esta clase.</span>
            </div>
        <?php endif; ?>

        <div class="table-responsive">
            <table class="custom-table">
                <thead>
                    <tr>
                        <th>Alumno</th>
                        <th>Estado</th>
                        <th>Hora de registro</th>
                        <th>Método</th>
                        <th>Marcar manualmente</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($listaAlumnos)): ?>
                        <tr>
                            <td colspan="5" style="text-align: center; color: var(--text-muted); padding: 30px 10px;">
                                No hay alumnos inscritos en esta clase.
                                <a href="inscripciones.php?clase_id=<?php echo (int)$claseSeleccionada['id']; ?>">Inscribirlos ahora</a>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($listaAlumnos as $al): ?>
                            <tr>
                                <td>
                                    <div><strong><?php echo htmlspecialchars($al['nombre']); ?></strong></div>
                                    <div style="font-size: 0.8rem; color: var(--text-muted);">
                                        <?php echo htmlspecialchars($al['matricula']); ?>
                                        <?php if (empty($al['tarjeta_rfid'])): ?>
                                            <i class="fa-solid fa-id-card" style="color: var(--danger);" title="Sin tarjeta RFID asignada"></i>
                                        <?php endif; ?>
                                        <?php if (empty($al['id_telegram'])): ?>
                                            <i class="fa-brands fa-telegram" style="color: var(--danger);" title="Sin Telegram: necesita tu firma"></i>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($al['estado']): ?>
                                        <span class="badge badge-<?php echo $al['estado']; ?>"><?php echo $al['estado']; ?></span>
                                    <?php else: ?>
                                        <span class="badge badge-inactive">sin registro</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo $al['hora'] ? date('h:i A', strtotime($al['hora'])) : '—'; ?>
                                </td>
                                <td style="font-size: 0.8rem;">
                                    <?php if ($al['tipo'] === 'RFID_VERIFICADO'): ?>
                                        <i class="fa-solid fa-id-card icon-blue"></i> Tarjeta
                                    <?php elseif ($al['tipo'] === 'MATRICULA_VERIFICADA'): ?>
                                        <i class="fa-solid fa-keyboard icon-purple"></i> Matrícula
                                    <?php elseif ($al['tipo'] === 'AUTORIZADO_DOCENTE'): ?>
                                        <i class="fa-solid fa-signature icon-green"></i>
                                        <?php echo htmlspecialchars($al['autorizo_nombre'] ?: 'Docente'); ?>
                                    <?php else: ?>
                                        <span style="color: var(--text-muted);">—</span>
                                    <?php endif; ?>
                                    <?php if ($al['dispositivo_id']): ?>
                                        <div style="font-size: 0.7rem; color: var(--text-muted);">
                                            <i class="fa-solid fa-microchip"></i> <?php echo htmlspecialchars($al['dispositivo_id']); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div style="display: flex; gap: 4px; flex-wrap: wrap;">
                                        <form action="pase_lista.php" method="POST" style="display: flex; gap: 4px;">
                                            <input type="hidden" name="clase_id" value="<?php echo (int)$claseSeleccionada['id']; ?>">
                                            <input type="hidden" name="fecha" value="<?php echo htmlspecialchars($fechaConsulta); ?>">
                                            <input type="hidden" name="alumno_id" value="<?php echo htmlspecialchars($al['matricula']); ?>">

                                            <button type="submit" name="estado" value="presente" class="btn-action" title="Marcar presente" style="color: var(--success);">
                                                <i class="fa-solid fa-check"></i>
                                            </button>
                                            <button type="submit" name="estado" value="retardo" class="btn-action" title="Marcar retardo" style="color: var(--warning);">
                                                <i class="fa-solid fa-clock"></i>
                                            </button>
                                            <button type="submit" name="estado" value="falta" class="btn-action" title="Marcar falta" style="color: var(--danger);">
                                                <i class="fa-solid fa-xmark"></i>
                                            </button>
                                            <button type="submit" name="estado" value="justificado" class="btn-action" title="Justificar" style="color: var(--info);">
                                                <i class="fa-solid fa-file-medical"></i>
                                            </button>
                                        </form>

                                        <?php if ($al['estado']): ?>
                                            <form action="pase_lista.php" method="POST" style="display: inline;"
                                                  onsubmit="return confirm('¿Borrar el registro de <?php echo htmlspecialchars($al['nombre'], ENT_QUOTES); ?> en esta fecha?')">
                                                <input type="hidden" name="clase_id" value="<?php echo (int)$claseSeleccionada['id']; ?>">
                                                <input type="hidden" name="fecha" value="<?php echo htmlspecialchars($fechaConsulta); ?>">
                                                <input type="hidden" name="alumno_id" value="<?php echo htmlspecialchars($al['matricula']); ?>">
                                                <input type="hidden" name="accion" value="quitar">
                                                <input type="hidden" name="estado" value="presente">
                                                <button type="submit" class="btn-action btn-delete" title="Borrar el registro">
                                                    <i class="fa-solid fa-eraser"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

<?php endif; ?>

<?php
require_once __DIR__ . '/footer.php';
?>
