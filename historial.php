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
$matricula = $user['matricula'];

// Valores por defecto para que la vista nunca quede sin datos
$asistencias = [];
$clasesFiltro = [];
$carrerasFiltro = [];
$gruposFiltro = [];
$error = '';
$success = '';

// -------------------------------------------------------------------------
// 0. EDICIÓN DE REGISTROS (solo administrativo)
//
// El docente corrige los suyos desde Pase de Lista; el administrativo puede
// corregir cualquier registro desde aquí.
// -------------------------------------------------------------------------
$estadosValidos = ['presente', 'retardo', 'falta', 'justificado'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $rol === 'administrativo') {
    $accion = $_POST['accion'] ?? '';
    $asistenciaId = (int)($_POST['asistencia_id'] ?? 0);

    try {
        if ($asistenciaId <= 0) {
            $error = 'No se indicó qué registro modificar.';
        } elseif ($accion === 'actualizar') {
            $nuevoEstado = $_POST['estado'] ?? '';
            $nuevaClase  = ($_POST['clase_id_nuevo'] ?? '') === '' ? null : (int)$_POST['clase_id_nuevo'];

            if (!in_array($nuevoEstado, $estadosValidos, true)) {
                $error = 'El estado seleccionado no es válido.';
            } else {
                $stmt = $pdo->prepare("
                    UPDATE asistencias SET estado = :estado, clase_id = :clase_id WHERE id = :id
                ");
                $stmt->execute([
                    'estado' => $nuevoEstado,
                    'clase_id' => $nuevaClase,
                    'id' => $asistenciaId,
                ]);
                $success = 'Registro actualizado a "' . $nuevoEstado . '".';
            }
        } elseif ($accion === 'eliminar') {
            $stmt = $pdo->prepare("DELETE FROM asistencias WHERE id = :id");
            $stmt->execute(['id' => $asistenciaId]);

            $success = $stmt->rowCount() > 0
                ? 'Registro de asistencia eliminado.'
                : 'Ese registro ya no existe.';
        }
    } catch (PDOException $e) {
        $error = mensajeErrorBD($e, 'modificar el registro de asistencia', $user['rol'] ?? null, 'historial.php');
    }
}

// Registro que se está editando (se conserva junto con los filtros activos)
$editarId = isset($_GET['editar_id']) ? (int)$_GET['editar_id'] : 0;

// Query string sin los parámetros internos, para no perder los filtros
$filtrosActivos = $_GET;
unset($filtrosActivos['editar_id'], $filtrosActivos['export']);
$filtrosQuery = http_build_query($filtrosActivos);

generarFaltasPendientes($pdo);

// Los docentes tienen su propia vista: asistencia de sus alumnos y calificaciones.
if ($rol === 'docente') {
    require __DIR__ . '/historial_docente.php';
    exit;
}

// 1. CONSTRUCCIÓN DE LA CONSULTA CON FILTROS DINÁMICOS
$where = [];
$params = [];

// Restricciones de Rol (Seguridad de datos)
if ($rol === 'alumno') {
    $where[] = "a.usuario_id = :user_matricula";
    $params['user_matricula'] = $matricula;
} elseif ($rol === 'docente') {
    // Los docentes solo ven alumnos que asistieron a las clases de sus materias
    $where[] = "c.docente_id = :docente_id";
    $params['docente_id'] = $matricula;
}

// Filtros desde GET
if (!empty($_GET['fecha_inicio'])) {
    $where[] = "a.fecha >= :fecha_inicio";
    $params['fecha_inicio'] = $_GET['fecha_inicio'];
}
if (!empty($_GET['fecha_fin'])) {
    $where[] = "a.fecha <= :fecha_fin";
    $params['fecha_fin'] = $_GET['fecha_fin'];
}
if (!empty($_GET['clase_id'])) {
    $where[] = "a.clase_id = :clase_id";
    $params['clase_id'] = $_GET['clase_id'];
}
if ($rol === 'administrativo') {
    if (!empty($_GET['rol_filtro'])) {
        $where[] = "u.rol = :rol_filtro";
        $params['rol_filtro'] = $_GET['rol_filtro'];
    }
}
if ($rol !== 'alumno') {
    if (!empty($_GET['carrera'])) {
        $where[] = "u.carrera = :carrera";
        $params['carrera'] = $_GET['carrera'];
    }
    if (!empty($_GET['grupo'])) {
        $where[] = "u.grupo = :grupo";
        $params['grupo'] = $_GET['grupo'];
    }
    if (!empty($_GET['busqueda'])) {
        $where[] = "(u.nombre LIKE :busqueda OR u.matricula LIKE :busqueda)";
        $params['busqueda'] = '%' . $_GET['busqueda'] . '%';
    }
}

$whereSQL = "";
if (count($where) > 0) {
    $whereSQL = "WHERE " . implode(" AND ", $where);
}

// Consulta final de asistencias
$query = "
    SELECT 
        a.*, 
        u.nombre as usuario_nombre, 
        u.rol as usuario_rol, 
        u.carrera, 
        u.grupo, 
        u.semestre,
        c.nombre_materia,
        d.nombre as docente_autorizo_nombre,
        dev.nombre as dispositivo_nombre,
        dev.aula as dispositivo_aula
    FROM asistencias a
    JOIN usuarios u ON a.usuario_id = u.matricula
    LEFT JOIN clases c ON a.clase_id = c.id
    LEFT JOIN usuarios d ON a.docente_autorizo_id = d.matricula
    LEFT JOIN dispositivos dev ON a.dispositivo_id = dev.device_id
    $whereSQL
    ORDER BY a.fecha DESC, a.hora DESC
";

try {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $asistencias = $stmt->fetchAll();

    // -------------------------------------------------------------
    // EXPORTACIÓN A CSV (Si se solicita)
    // -------------------------------------------------------------
    if (isset($_GET['export']) && $_GET['export'] === 'csv') {
        // Limpiar cualquier buffer previo
        if (ob_get_level()) {
            ob_end_clean();
        }
        
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=reporte_asistencia_' . date('Ymd_His') . '.csv');
        
        $output = fopen('php://output', 'w');
        
        // Agregar UTF-8 BOM para Excel
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        // Encabezados
        fputcsv($output, ['Matrícula', 'Nombre', 'Rol', 'Carrera', 'Grupo', 'Semestre', 'Materia/Clase', 'Fecha', 'Hora', 'Método', 'Estado', 'Autorizado por (Docente)', 'Caja ESP32']);

        foreach ($asistencias as $a) {
            $metodo = '';
            if ($a['tipo'] === 'RFID_VERIFICADO') $metodo = 'Tarjeta + Telegram';
            elseif ($a['tipo'] === 'MATRICULA_VERIFICADA') $metodo = 'Matrícula + Telegram';
            else $metodo = 'Firma del docente';

            fputcsv($output, [
                $a['usuario_id'],
                $a['usuario_nombre'],
                ucfirst($a['usuario_rol']),
                $a['carrera'] ?: 'N/A',
                $a['grupo'] ?: 'N/A',
                $a['semestre'] ?: 'N/A',
                $a['nombre_materia'] ?: 'General',
                $a['fecha'],
                $a['hora'],
                $metodo,
                ucfirst($a['estado']),
                $a['docente_autorizo_nombre'] ?: 'N/A',
                $a['dispositivo_id'] ?: 'N/A'
            ]);
        }
        fclose($output);
        exit;
    }

    // 2. OBTENER LISTAS AUXILIARES PARA LOS DROPDOWNS DE FILTROS
    // Lista de clases
    if ($rol === 'administrativo') {
        $clasesFiltro = $pdo->query("SELECT id, nombre_materia, grupo, carrera FROM clases ORDER BY nombre_materia ASC")->fetchAll();
        $carrerasFiltro = array_map(function ($c) { return ['carrera' => $c]; }, CARRERAS);
        $gruposFiltro = $pdo->query("SELECT DISTINCT grupo FROM usuarios WHERE grupo IS NOT NULL AND grupo != '' ORDER BY grupo ASC")->fetchAll();
    } elseif ($rol === 'docente') {
        $stmtClasesFiltro = $pdo->prepare("SELECT id, nombre_materia, grupo, carrera FROM clases WHERE docente_id = :docente_id ORDER BY nombre_materia ASC");
        $stmtClasesFiltro->execute(['docente_id' => $matricula]);
        $clasesFiltro = $stmtClasesFiltro->fetchAll();
        
        $stmtCarrerasFiltro = $pdo->prepare("
            SELECT DISTINCT u.carrera 
            FROM usuarios u 
            JOIN clases c ON u.carrera = c.carrera AND u.grupo = c.grupo AND u.semestre = c.semestre
            WHERE c.docente_id = :docente_id AND u.carrera != ''
        ");
        $stmtCarrerasFiltro->execute(['docente_id' => $matricula]);
        $carrerasFiltro = $stmtCarrerasFiltro->fetchAll();

        $stmtGruposFiltro = $pdo->prepare("
            SELECT DISTINCT u.grupo 
            FROM usuarios u 
            JOIN clases c ON u.carrera = c.carrera AND u.grupo = c.grupo AND u.semestre = c.semestre
            WHERE c.docente_id = :docente_id AND u.grupo != ''
        ");
        $stmtGruposFiltro->execute(['docente_id' => $matricula]);
        $gruposFiltro = $stmtGruposFiltro->fetchAll();
    } else {
        // Alumno: sus materias inscritas
        $stmtClasesFiltro = $pdo->prepare("
            SELECT DISTINCT c.id, c.nombre_materia
            FROM inscripciones i
            JOIN clases c ON c.id = i.clase_id
            WHERE i.alumno_id = :matricula
            ORDER BY c.nombre_materia ASC
        ");
        $stmtClasesFiltro->execute(['matricula' => $matricula]);
        $clasesFiltro = $stmtClasesFiltro->fetchAll();
    }

} catch (PDOException $e) {
    $error = mensajeErrorBD($e, 'cargar el historial', $user['rol'] ?? null, 'historial.php');
}

// Cargar el header después de verificar la sesión y procesar posibles redirecciones/exportaciones
require_once __DIR__ . '/header.php';
?>

<div class="top-bar">
    <div class="page-title">
        <span class="page-eyebrow">Consulta</span>
        <h2>Historial de Asistencias</h2>
        <p>Visualiza, filtra y descarga el registro de asistencia del sistema.</p>
    </div>
    
    <div class="action-bar">
        <!-- Mantener filtros actuales al exportar -->
        <?php 
        $queryString = $_SERVER['QUERY_STRING'] ?? '';
        $exportUrl = 'historial.php?' . (empty($queryString) ? 'export=csv' : $queryString . '&export=csv');
        ?>
        <a href="<?php echo htmlspecialchars($exportUrl); ?>" class="btn-primary" style="background: var(--success); box-shadow: 0 4px 10px var(--success-glow);">
            <i class="fa-solid fa-file-csv"></i>
            <span>Exportar CSV</span>
        </a>
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

<!-- PANEL DE FILTROS AVANZADOS -->
<div class="filter-card">
    <form action="historial.php" method="GET">
        <div class="filter-grid">
            
            <?php if ($rol !== 'alumno'): ?>
                <div class="form-group" style="margin-bottom: 0;">
                    <label for="busqueda">BUSCAR NOMBRE / ID</label>
                    <div class="input-container">
                        <input type="text" id="busqueda" name="busqueda" class="form-select" placeholder="Ej: Juan Pérez" value="<?php echo htmlspecialchars($_GET['busqueda'] ?? ''); ?>">
                    </div>
                </div>
            <?php endif; ?>

            <div class="form-group" style="margin-bottom: 0;">
                <label for="fecha_inicio">DESDE LA FECHA</label>
                <input type="date" id="fecha_inicio" name="fecha_inicio" class="form-date" value="<?php echo htmlspecialchars($_GET['fecha_inicio'] ?? ''); ?>">
            </div>

            <div class="form-group" style="margin-bottom: 0;">
                <label for="fecha_fin">HASTA LA FECHA</label>
                <input type="date" id="fecha_fin" name="fecha_fin" class="form-date" value="<?php echo htmlspecialchars($_GET['fecha_fin'] ?? ''); ?>">
            </div>

            <div class="form-group" style="margin-bottom: 0;">
                <label for="clase_id">CLASE / MATERIA</label>
                <select id="clase_id" name="clase_id" class="form-select">
                    <option value="">Todas las clases</option>
                    <?php foreach ($clasesFiltro as $c): ?>
                        <option value="<?php echo $c['id']; ?>" <?php echo (isset($_GET['clase_id']) && $_GET['clase_id'] == $c['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($c['nombre_materia']); ?> 
                            <?php if ($rol !== 'alumno' && isset($c['grupo'])): ?>
                                (<?php echo htmlspecialchars($c['carrera'] . ' - ' . $c['grupo']); ?>)
                            <?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <?php if ($rol === 'administrativo'): ?>
                <div class="form-group" style="margin-bottom: 0;">
                    <label for="rol_filtro">ROL</label>
                    <select id="rol_filtro" name="rol_filtro" class="form-select">
                        <option value="">Todos los roles</option>
                        <option value="alumno" <?php echo (isset($_GET['rol_filtro']) && $_GET['rol_filtro'] === 'alumno') ? 'selected' : ''; ?>>Alumno</option>
                        <option value="docente" <?php echo (isset($_GET['rol_filtro']) && $_GET['rol_filtro'] === 'docente') ? 'selected' : ''; ?>>Docente</option>
                        <option value="administrativo" <?php echo (isset($_GET['rol_filtro']) && $_GET['rol_filtro'] === 'administrativo') ? 'selected' : ''; ?>>Administrativo</option>
                    </select>
                </div>
            <?php endif; ?>

            <?php if ($rol !== 'alumno'): ?>
                <div class="form-group" style="margin-bottom: 0;">
                    <label for="carrera">CARRERA</label>
                    <select id="carrera" name="carrera" class="form-select">
                        <option value="">Todas las carreras</option>
                        <?php foreach ($carrerasFiltro as $cf): ?>
                            <option value="<?php echo htmlspecialchars($cf['carrera']); ?>" <?php echo (isset($_GET['carrera']) && $_GET['carrera'] === $cf['carrera']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cf['carrera']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group" style="margin-bottom: 0;">
                    <label for="grupo">GRUPO</label>
                    <select id="grupo" name="grupo" class="form-select">
                        <option value="">Todos los grupos</option>
                        <?php foreach ($gruposFiltro as $gf): ?>
                            <option value="<?php echo htmlspecialchars($gf['grupo']); ?>" <?php echo (isset($_GET['grupo']) && $_GET['grupo'] === $gf['grupo']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($gf['grupo']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

            <div style="display: flex; gap: 8px;">
                <button type="submit" class="btn-filter" style="flex-grow: 1;">
                    <i class="fa-solid fa-magnifying-glass"></i> Filtrar
                </button>
                <a href="historial.php" class="btn-reset" style="display: flex; align-items: center; justify-content: center;">
                    <i class="fa-solid fa-rotate-left"></i>
                </a>
            </div>
        </div>
    </form>
</div>

<!-- TABLA DE RESULTADOS -->
<div class="panel-card">
    <div class="table-responsive">
        <table class="custom-table">
            <thead>
                <tr>
                    <?php if ($rol !== 'alumno'): ?>
                        <th>Matrícula / ID</th>
                        <th>Nombre</th>
                        <th>Detalles Académicos</th>
                    <?php endif; ?>
                    <th>Clase / Materia</th>
                    <th>Fecha</th>
                    <th>Hora</th>
                    <th>Método</th>
                    <th>Estado</th>
                    <th>Autorización</th>
                    <?php if ($rol === 'administrativo'): ?>
                        <th>Acciones</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($asistencias)): ?>
                    <tr>
                        <td colspan="10" style="text-align: center; color: var(--text-muted); padding: 30px 10px;">
                            No se encontraron registros que coincidan con los filtros seleccionados.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($asistencias as $a): ?>

                        <?php // ------- FILA EN MODO EDICIÓN (solo administrativo) ------- ?>
                        <?php if ($rol === 'administrativo' && $editarId === (int)$a['id']): ?>
                            <tr style="background: var(--wine-50);">
                                <td colspan="10">
                                    <form action="historial.php?<?php echo htmlspecialchars($filtrosQuery); ?>" method="POST"
                                          style="display: flex; gap: 12px; align-items: flex-end; flex-wrap: wrap; padding: 6px 0;">
                                        <input type="hidden" name="accion" value="actualizar">
                                        <input type="hidden" name="asistencia_id" value="<?php echo (int)$a['id']; ?>">

                                        <div style="flex: 1 1 220px;">
                                            <div style="font-size: 0.8rem; color: var(--text-muted); margin-bottom: 4px;">
                                                Editando el registro de
                                                <strong><?php echo htmlspecialchars($a['usuario_nombre']); ?></strong>
                                                del <?php echo date('d/m/Y', strtotime($a['fecha'])); ?>
                                                a las <?php echo date('h:i A', strtotime($a['hora'])); ?>
                                            </div>
                                        </div>

                                        <div class="form-group" style="margin-bottom: 0; flex: 0 1 160px;">
                                            <label for="estado_<?php echo (int)$a['id']; ?>">ESTADO</label>
                                            <select id="estado_<?php echo (int)$a['id']; ?>" name="estado" class="form-select">
                                                <?php foreach ($estadosValidos as $est): ?>
                                                    <option value="<?php echo $est; ?>" <?php echo $a['estado'] === $est ? 'selected' : ''; ?>>
                                                        <?php echo ucfirst($est); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <div class="form-group" style="margin-bottom: 0; flex: 1 1 240px;">
                                            <label for="clase_nuevo_<?php echo (int)$a['id']; ?>">MATERIA</label>
                                            <select id="clase_nuevo_<?php echo (int)$a['id']; ?>" name="clase_id_nuevo" class="form-select">
                                                <option value="">General / Sin horario</option>
                                                <?php foreach ($clasesFiltro as $cf): ?>
                                                    <option value="<?php echo $cf['id']; ?>" <?php echo ((int)$a['clase_id'] === (int)$cf['id']) ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($cf['nombre_materia']); ?>
                                                        <?php if (!empty($cf['grupo'])): ?>
                                                            (<?php echo htmlspecialchars($cf['grupo']); ?>)
                                                        <?php endif; ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <div style="display: flex; gap: 8px;">
                                            <button type="submit" class="btn-primary" style="width: auto; padding: 10px 16px;">
                                                <i class="fa-solid fa-floppy-disk"></i> <span>Guardar</span>
                                            </button>
                                            <a href="historial.php?<?php echo htmlspecialchars($filtrosQuery); ?>" class="btn-reset"
                                               style="display: flex; align-items: center; padding: 0 14px;">Cancelar</a>
                                        </div>
                                    </form>
                                </td>
                            </tr>
                            <?php continue; ?>
                        <?php endif; ?>

                        <tr>
                            <?php if ($rol !== 'alumno'): ?>
                                <td><strong><?php echo htmlspecialchars($a['usuario_id']); ?></strong></td>
                                <td>
                                    <div><strong><?php echo htmlspecialchars($a['usuario_nombre']); ?></strong></div>
                                    <div><span class="badge badge-role-<?php echo $a['usuario_rol']; ?>" style="font-size: 0.65rem; padding: 1px 6px;"><?php echo htmlspecialchars($a['usuario_rol']); ?></span></div>
                                </td>
                                <td>
                                    <?php if ($a['usuario_rol'] === 'alumno'): ?>
                                        <div style="font-size: 0.8rem; color: var(--text-muted);">
                                            <?php echo htmlspecialchars($a['carrera'] ?: 'S/C'); ?>
                                            (<?php echo htmlspecialchars($a['semestre'] ?: '0'); ?>° - "<?php echo htmlspecialchars($a['grupo'] ?: 'S/G'); ?>")
                                        </div>
                                    <?php else: ?>
                                        <div style="font-size: 0.8rem; color: var(--text-muted);">Personal</div>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                            <td><?php echo htmlspecialchars($a['nombre_materia'] ?? 'General / Sin Horario'); ?></td>
                            <td><?php echo date('d/m/Y', strtotime($a['fecha'])); ?></td>
                            <td><?php echo date('h:i A', strtotime($a['hora'])); ?></td>
                            <td>
                                <div style="font-size: 0.82rem;">
                                    <?php if ($a['tipo'] === 'RFID_VERIFICADO'): ?>
                                        <i class="fa-solid fa-id-card icon-blue"></i> Tarjeta + Telegram
                                    <?php elseif ($a['tipo'] === 'MATRICULA_VERIFICADA'): ?>
                                        <i class="fa-solid fa-keyboard icon-purple"></i> Matrícula + Telegram
                                    <?php else: ?>
                                        <i class="fa-solid fa-user-check icon-green"></i> Firma Docente
                                    <?php endif; ?>
                                    <?php if (!empty($a['dispositivo_id'])): ?>
                                        <div style="font-size: 0.7rem; color: var(--text-muted);"
                                             title="<?php echo htmlspecialchars($a['dispositivo_nombre'] ?: $a['dispositivo_id']); ?>">
                                            <i class="fa-solid fa-microchip"></i>
                                            <?php echo htmlspecialchars($a['dispositivo_aula'] ?: $a['dispositivo_id']); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <span class="badge badge-<?php echo $a['estado']; ?>">
                                    <?php echo htmlspecialchars($a['estado']); ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($a['tipo'] === 'AUTORIZADO_DOCENTE'): ?>
                                    <div style="font-size: 0.8rem; color: var(--text-muted);" title="Docente: <?php echo htmlspecialchars($a['docente_autorizo_nombre']); ?>">
                                        <i class="fa-solid fa-signature icon-green"></i> <?php echo htmlspecialchars($a['docente_autorizo_nombre']); ?>
                                    </div>
                                <?php else: ?>
                                    <div style="font-size: 0.8rem; color: var(--text-muted);">Autoverificado</div>
                                <?php endif; ?>
                            </td>

                            <?php if ($rol === 'administrativo'): ?>
                                <td>
                                    <div style="display: flex; gap: 6px;">
                                        <a href="historial.php?<?php echo htmlspecialchars($filtrosQuery ? $filtrosQuery . '&' : ''); ?>editar_id=<?php echo (int)$a['id']; ?>"
                                           class="btn-action" title="Corregir este registro">
                                            <i class="fa-solid fa-pen"></i>
                                        </a>
                                        <form action="historial.php?<?php echo htmlspecialchars($filtrosQuery); ?>" method="POST" style="display: inline;"
                                              onsubmit="return confirm('¿Eliminar el registro de <?php echo htmlspecialchars($a['usuario_nombre'], ENT_QUOTES); ?> del <?php echo date('d/m/Y', strtotime($a['fecha'])); ?>?')">
                                            <input type="hidden" name="accion" value="eliminar">
                                            <input type="hidden" name="asistencia_id" value="<?php echo (int)$a['id']; ?>">
                                            <button type="submit" class="btn-action btn-delete" title="Eliminar registro">
                                                <i class="fa-solid fa-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
require_once __DIR__ . '/footer.php';
?>
