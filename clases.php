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
$editando = null;

$diasSemana = ['lunes', 'martes', 'miercoles', 'jueves', 'viernes', 'sabado', 'domingo'];

/**
 * Lee y normaliza los campos del formulario de clases.
 */
function leerFormularioClase() {
    $limpiar = function ($valor) {
        $valor = trim((string)$valor);
        return $valor === '' ? null : $valor;
    };

    return [
        'nombre_materia' => trim($_POST['nombre_materia'] ?? ''),
        'docente_id'     => trim($_POST['docente_id'] ?? ''),
        'dia_semana'     => trim($_POST['dia_semana'] ?? ''),
        'hora_inicio'    => trim($_POST['hora_inicio'] ?? ''),
        'hora_fin'       => trim($_POST['hora_fin'] ?? ''),
        'aula'           => $limpiar($_POST['aula'] ?? ''),
        'carrera'        => $limpiar($_POST['carrera'] ?? ''),
        'grupo'          => $limpiar($_POST['grupo'] ?? ''),
        'semestre'       => empty($_POST['semestre']) ? null : (int)$_POST['semestre'],
    ];
}

/**
 * Valida los datos de una clase. Devuelve el mensaje de error o ''.
 */
function validarClase($pdo, $d, $diasSemana) {
    if ($d['nombre_materia'] === '' || $d['docente_id'] === '' || $d['dia_semana'] === ''
        || $d['hora_inicio'] === '' || $d['hora_fin'] === '') {
        return 'Faltan datos: materia, docente, día y horario son obligatorios.';
    }

    if (($errCarrera = validarCarrera($d['carrera'])) !== '') {
        return $errCarrera;
    }

    if (!in_array($d['dia_semana'], $diasSemana, true)) {
        return 'El día de la semana seleccionado no es válido.';
    }

    if ($d['hora_fin'] <= $d['hora_inicio']) {
        return 'La hora de fin debe ser posterior a la hora de inicio.';
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE matricula = :id AND rol = 'docente' AND estado = 'activo'");
    $stmt->execute(['id' => $d['docente_id']]);
    if ($stmt->fetchColumn() == 0) {
        return 'El docente seleccionado no existe o está dado de baja.';
    }

    return '';
}

/**
 * Busca un empalme de horario del mismo docente. Devuelve el mensaje o ''.
 */
function buscarEmpalme($pdo, $d, $idExcluir = null) {
    $sql = "
        SELECT nombre_materia, hora_inicio, hora_fin FROM clases
        WHERE docente_id = :docente_id
          AND dia_semana = :dia
          AND hora_inicio < :hora_fin
          AND hora_fin > :hora_inicio
    ";
    $params = [
        'docente_id' => $d['docente_id'],
        'dia' => $d['dia_semana'],
        'hora_inicio' => $d['hora_inicio'],
        'hora_fin' => $d['hora_fin'],
    ];

    if ($idExcluir !== null) {
        $sql .= " AND id != :id";
        $params['id'] = $idExcluir;
    }

    $stmt = $pdo->prepare($sql . " LIMIT 1");
    $stmt->execute($params);
    $choque = $stmt->fetch();

    if ($choque) {
        return 'Ese docente ya tiene "' . $choque['nombre_materia'] . '" el mismo día de '
            . substr($choque['hora_inicio'], 0, 5) . ' a ' . substr($choque['hora_fin'], 0, 5) . '.';
    }

    if (!empty($d['aula'])) {
        $sqlAula = "
            SELECT nombre_materia, hora_inicio, hora_fin FROM clases
            WHERE aula = :aula
              AND dia_semana = :dia
              AND hora_inicio < :hora_fin
              AND hora_fin > :hora_inicio
        ";
        $paramsAula = [
            'aula' => $d['aula'],
            'dia' => $d['dia_semana'],
            'hora_inicio' => $d['hora_inicio'],
            'hora_fin' => $d['hora_fin'],
        ];

        if ($idExcluir !== null) {
            $sqlAula .= " AND id != :id";
            $paramsAula['id'] = $idExcluir;
        }

        $stmtAula = $pdo->prepare($sqlAula . " LIMIT 1");
        $stmtAula->execute($paramsAula);
        $choqueAula = $stmtAula->fetch();

        if ($choqueAula) {
            return 'El aula "' . $d['aula'] . '" ya está ocupada por "' . $choqueAula['nombre_materia'] . '" el mismo día de '
                . substr($choqueAula['hora_inicio'], 0, 5) . ' a ' . substr($choqueAula['hora_fin'], 0, 5) . '.';
        }
    }

    return '';
}

// -------------------------------------------------------------------------
// 1. PROCESAR ACCIONES
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';
    $d = leerFormularioClase();

    if ($accion === 'guardar' || $accion === 'actualizar') {
        $claseId = ($accion === 'actualizar') ? (int)($_POST['clase_id'] ?? 0) : null;

        $error = validarClase($pdo, $d, $diasSemana);
        if ($error === '') {
            $error = buscarEmpalme($pdo, $d, $claseId);
        }

        if ($error === '') {
            try {
                if ($accion === 'guardar') {
                    $stmt = $pdo->prepare("
                        INSERT INTO clases (nombre_materia, docente_id, dia_semana, hora_inicio, hora_fin, aula, carrera, grupo, semestre)
                        VALUES (:nombre_materia, :docente_id, :dia_semana, :hora_inicio, :hora_fin, :aula, :carrera, :grupo, :semestre)
                    ");
                    $stmt->execute($d);
                    $success = 'Clase "' . $d['nombre_materia'] . '" agregada al horario.';
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE clases SET
                            nombre_materia = :nombre_materia,
                            docente_id = :docente_id,
                            dia_semana = :dia_semana,
                            hora_inicio = :hora_inicio,
                            hora_fin = :hora_fin,
                            aula = :aula,
                            carrera = :carrera,
                            grupo = :grupo,
                            semestre = :semestre
                        WHERE id = :id
                    ");
                    $stmt->execute(array_merge($d, ['id' => $claseId]));
                    $success = 'Clase "' . $d['nombre_materia'] . '" actualizada correctamente.';
                }
            } catch (PDOException $e) {
                $error = mensajeErrorBD($e, 'guardar la clase', $user['rol'] ?? null, 'clases.php');
            }
        }
    }
}

// -------------------------------------------------------------------------
// ELIMINAR CLASE
// -------------------------------------------------------------------------
if (isset($_GET['eliminar'])) {
    $idEliminar = (int)$_GET['eliminar'];
    try {
        $stmt = $pdo->prepare("DELETE FROM clases WHERE id = :id");
        $stmt->execute(['id' => $idEliminar]);
        $success = $stmt->rowCount() > 0
            ? 'Clase eliminada del horario. Las asistencias previas se conservan sin materia.'
            : 'Esa clase ya no existe en el horario.';
    } catch (PDOException $e) {
        $error = mensajeErrorBD($e, 'eliminar la clase', $user['rol'] ?? null, 'clases.php');
    }
}

// -------------------------------------------------------------------------
// CARGAR CLASE A EDITAR
// -------------------------------------------------------------------------
if (isset($_GET['editar'])) {
    $stmt = $pdo->prepare("SELECT * FROM clases WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => (int)$_GET['editar']]);
    $editando = $stmt->fetch() ?: null;

    if (!$editando) {
        $error = 'No se encontró la clase que quieres editar.';
    }
}

// Si el guardado falló, se vuelve a mostrar el formulario con lo que escribió
$formDatos = $editando ?: [];
if (!$editando && $error !== '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $formDatos = $_POST;
    if (($_POST['accion'] ?? '') === 'actualizar') {
        $editando = $_POST;
        $editando['id'] = (int)($_POST['clase_id'] ?? 0);
        $formDatos = $editando;
    }
}

// ¿Formulario o listado?
$modoForm = $editando !== null
    || isset($_GET['nuevo'])
    || ($error !== '' && $_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['accion'] ?? '', ['guardar', 'actualizar'], true));

// -------------------------------------------------------------------------
// LISTADOS
// -------------------------------------------------------------------------
try {
    $docentes = $pdo->query("
        SELECT matricula, nombre FROM usuarios
        WHERE rol = 'docente' AND estado = 'activo' ORDER BY nombre ASC
    ")->fetchAll();

    $clases = $pdo->query("
        SELECT c.*, u.nombre AS docente_nombre,
               (SELECT COUNT(*) FROM inscripciones i WHERE i.clase_id = c.id) AS inscritos
        FROM clases c
        JOIN usuarios u ON c.docente_id = u.matricula
        ORDER BY FIELD(c.dia_semana, 'lunes','martes','miercoles','jueves','viernes','sabado','domingo'), c.hora_inicio ASC
    ")->fetchAll();
} catch (PDOException $e) {
    $error = mensajeErrorBD($e, 'cargar el horario', $user['rol'] ?? null, 'clases.php');
    $docentes = [];
    $clases = [];
}

$v = function ($campo, $defecto = '') use ($formDatos) {
    return htmlspecialchars($formDatos[$campo] ?? $defecto);
};

require_once __DIR__ . '/header.php';
?>

<div class="top-bar">
    <div class="page-title">
        <span class="page-eyebrow">Módulo de gestión</span>
        <h2><?php echo $modoForm ? ($editando ? 'Editar materia' : 'Nueva materia') : 'Materias'; ?></h2>
        <?php if (!$modoForm): ?>
            <p>Cada fila es una materia en un día y hora; el horario lo usa la caja para calcular retardos.</p>
        <?php endif; ?>
    </div>
    <?php if (!$modoForm): ?>
        <a href="clases.php?nuevo=1" class="btn-primary">
            <i class="fa-solid fa-plus"></i> <span>Registrar materia</span>
        </a>
    <?php endif; ?>
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

<?php if ($modoForm): ?>

<!-- ===================== FORMULARIO (ALTA O EDICIÓN) ===================== -->
<form action="clases.php" method="POST" class="form-card">
    <input type="hidden" name="accion" value="<?php echo $editando ? 'actualizar' : 'guardar'; ?>">
    <?php if ($editando): ?>
        <input type="hidden" name="clase_id" value="<?php echo (int)$editando['id']; ?>">
    <?php endif; ?>

    <div class="form-card-head">
        <h3>Datos de la materia</h3>
        <p>Configura la materia, el docente, el horario que usa la caja para calcular retardos y el grupo al que va dirigida.</p>
    </div>

    <div class="form-card-body">
        <div class="form-grid">

            <div class="form-group span-2">
                <label for="nombre_materia"><i class="fa-solid fa-book-open"></i> Materia / asignatura *</label>
                <input type="text" id="nombre_materia" name="nombre_materia" class="form-select"
                       placeholder="Ej: Sistemas Inteligentes" required value="<?php echo $v('nombre_materia'); ?>">
            </div>

            <div class="form-group">
                <label for="docente_id"><i class="fa-solid fa-chalkboard-user"></i> Docente *</label>
                <select id="docente_id" name="docente_id" class="form-select" required>
                    <option value="">Selecciona un docente</option>
                    <?php foreach ($docentes as $doc): ?>
                        <option value="<?php echo htmlspecialchars($doc['matricula']); ?>"
                            <?php echo ($v('docente_id') === htmlspecialchars($doc['matricula'])) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($doc['nombre']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (empty($docentes)): ?>
                    <p class="form-ayuda" style="color: var(--danger);">
                        No hay docentes activos. Primero registra uno en <a href="usuarios.php?rol=docente" style="text-decoration: underline;">Profesores</a>.
                    </p>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label for="aula"><i class="fa-solid fa-door-open"></i> Aula / salón</label>
                <input type="text" id="aula" name="aula" class="form-select" placeholder="Ej: A214" value="<?php echo $v('aula'); ?>">
            </div>

            <div class="form-group">
                <label for="dia_semana"><i class="fa-regular fa-calendar"></i> Día de la semana *</label>
                <select id="dia_semana" name="dia_semana" class="form-select" required>
                    <option value="">Selecciona el día</option>
                    <?php foreach ($diasSemana as $dia): ?>
                        <option value="<?php echo $dia; ?>" <?php echo ($v('dia_semana') === $dia) ? 'selected' : ''; ?>>
                            <?php echo ucfirst($dia === 'miercoles' ? 'miércoles' : ($dia === 'sabado' ? 'sábado' : $dia)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-grid" style="grid-template-columns: 1fr 1fr; gap: 0 1rem;">
                <div class="form-group">
                    <label for="hora_inicio"><i class="fa-regular fa-clock"></i> Hora inicio *</label>
                    <input type="time" id="hora_inicio" name="hora_inicio" class="form-select" required
                           value="<?php echo htmlspecialchars(substr((string)($formDatos['hora_inicio'] ?? ''), 0, 5)); ?>">
                </div>
                <div class="form-group">
                    <label for="hora_fin"><i class="fa-regular fa-clock"></i> Hora fin *</label>
                    <input type="time" id="hora_fin" name="hora_fin" class="form-select" required
                           value="<?php echo htmlspecialchars(substr((string)($formDatos['hora_fin'] ?? ''), 0, 5)); ?>">
                </div>
            </div>

            <div class="form-seccion">Grupo objetivo (alumnos)</div>

            <div class="form-group span-2">
                <label for="carrera"><i class="fa-solid fa-graduation-cap"></i> Carrera</label>
                <select id="carrera" name="carrera" class="form-select">
                    <?php echo opcionesCarrera($formDatos['carrera'] ?? '', 'Todas las carreras'); ?>
                </select>
            </div>

            <div class="form-group">
                <label for="grupo"><i class="fa-solid fa-people-group"></i> Grupo</label>
                <input type="text" id="grupo" name="grupo" class="form-select" placeholder="Ej: 1" value="<?php echo $v('grupo'); ?>">
            </div>
            <div class="form-group">
                <label for="semestre"><i class="fa-solid fa-layer-group"></i> Semestre</label>
                <input type="number" id="semestre" name="semestre" class="form-select" min="1" max="12"
                       placeholder="Ej: 9" value="<?php echo $v('semestre'); ?>">
            </div>

            <p class="form-ayuda span-2" style="margin: -0.4rem 0 1rem;">
                Estos datos sirven para inscribir alumnos en bloque desde
                <a href="inscripciones.php" style="text-decoration: underline; color: var(--wine-700);">Inscripciones</a>.
            </p>

        </div>
    </div>

    <div class="form-card-foot">
        <div class="grupo-izq">
            <a href="clases.php" class="btn-tabla" style="padding: 0.6rem 1rem;"><i class="fa-solid fa-xmark"></i> Cancelar</a>
            <?php if ($editando): ?>
                <a href="inscripciones.php?clase_id=<?php echo (int)$editando['id']; ?>" class="btn-tabla" style="padding: 0.6rem 1rem;">
                    <i class="fa-solid fa-users"></i> Ver inscripciones
                </a>
                <a href="clases.php?eliminar=<?php echo (int)$editando['id']; ?>" class="btn-tabla btn-tabla-danger" style="padding: 0.6rem 1rem;"
                   onclick="return confirm('Se eliminará esta clase del horario y sus inscripciones. ¿Continuar?')">
                    <i class="fa-solid fa-trash"></i> Eliminar
                </a>
            <?php endif; ?>
        </div>
        <div class="grupo-der">
            <button type="submit" class="btn-primary btn-auto">
                <i class="fa-solid fa-floppy-disk"></i>
                <span><?php echo $editando ? 'Guardar cambios' : 'Guardar materia'; ?></span>
            </button>
        </div>
    </div>
</form>

<?php else: ?>

<!-- ===================== LISTADO ===================== -->
<div class="buscador">
    <i class="fa-solid fa-magnifying-glass"></i>
    <input type="search" id="buscar" placeholder="Buscar por materia, docente o aula..." autocomplete="off">
</div>

<div class="table-card">
    <div class="table-responsive">
        <table class="custom-table">
            <thead>
                <tr>
                    <th>Materia</th>
                    <th>Docente</th>
                    <th>Día</th>
                    <th>Horario</th>
                    <th>Aula</th>
                    <th>Inscritos</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody id="tablaClases">
                <?php if (empty($clases)): ?>
                    <tr>
                        <td colspan="7" style="text-align: center; color: var(--text-muted); padding: 36px 10px;">
                            Todavía no hay materias en el horario. Usa «Registrar materia».
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($clases as $c): ?>
                        <tr data-buscar="<?php echo htmlspecialchars(strtolower($c['nombre_materia'] . ' ' . $c['docente_nombre'] . ' ' . ($c['aula'] ?? ''))); ?>">
                            <td>
                                <strong><?php echo htmlspecialchars($c['nombre_materia']); ?></strong>
                                <?php if ($c['carrera'] || $c['grupo'] || $c['semestre']): ?>
                                    <div class="celda-sub">
                                        <?php echo htmlspecialchars($c['carrera'] ?: 'Todas las carreras'); ?>
                                        <?php echo $c['semestre'] ? ' · ' . (int)$c['semestre'] . '° sem' : ''; ?>
                                        <?php echo $c['grupo'] ? ' · Grupo ' . htmlspecialchars($c['grupo']) : ''; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($c['docente_nombre']); ?></td>
                            <td><span class="badge badge-role-alumno"><?php echo nombreDia($c['dia_semana']); ?></span></td>
                            <td style="white-space: nowrap;">
                                <strong><?php echo date('h:i A', strtotime($c['hora_inicio'])); ?></strong>
                                <div class="celda-sub">a <?php echo date('h:i A', strtotime($c['hora_fin'])); ?></div>
                            </td>
                            <td><?php echo htmlspecialchars($c['aula'] ?: 'Sin asignar'); ?></td>
                            <td>
                                <a href="inscripciones.php?clase_id=<?php echo $c['id']; ?>"
                                   class="badge badge-<?php echo $c['inscritos'] > 0 ? 'active' : 'neutro'; ?>"
                                   title="Administrar inscripciones">
                                    <?php echo (int)$c['inscritos']; ?> alumnos
                                </a>
                            </td>
                            <td>
                                <div style="display: flex; gap: 6px;">
                                    <a href="clases.php?editar=<?php echo $c['id']; ?>" class="btn-tabla">Editar</a>
                                    <a href="clases.php?eliminar=<?php echo $c['id']; ?>" class="btn-tabla btn-tabla-danger"
                                       onclick="return confirm('Se eliminará &quot;<?php echo htmlspecialchars($c['nombre_materia'], ENT_QUOTES); ?>&quot; del horario y sus inscripciones. ¿Continuar?')">Eliminar</a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <tr id="sinResultados" style="display: none;">
                        <td colspan="7" style="text-align: center; color: var(--text-muted); padding: 30px 10px;">
                            Ningún resultado coincide con la búsqueda.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
(function () {
    var caja = document.getElementById('buscar');
    if (!caja) { return; }
    caja.addEventListener('input', function () {
        var q = caja.value.trim().toLowerCase();
        var visibles = 0;
        document.querySelectorAll('#tablaClases tr[data-buscar]').forEach(function (tr) {
            var ok = tr.dataset.buscar.indexOf(q) !== -1;
            tr.style.display = ok ? '' : 'none';
            if (ok) { visibles++; }
        });
        var vacio = document.getElementById('sinResultados');
        if (vacio) { vacio.style.display = visibles === 0 ? '' : 'none'; }
    });
})();
</script>

<?php endif; ?>

<?php
require_once __DIR__ . '/footer.php';
?>
