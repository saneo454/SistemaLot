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

// Vista actual: Estudiantes (alumno), Profesores (docente) o Administrativos
$rolesVista = [
    'alumno'         => ['plural' => 'Estudiantes',     'singular' => 'estudiante',     'nuevo' => 'Registrar estudiante'],
    'docente'        => ['plural' => 'Profesores',      'singular' => 'profesor',       'nuevo' => 'Registrar profesor'],
    'administrativo' => ['plural' => 'Administrativos', 'singular' => 'administrativo', 'nuevo' => 'Registrar administrativo'],
];
$rolVista = $_GET['rol'] ?? 'alumno';
if (!isset($rolesVista[$rolVista])) {
    $rolVista = 'alumno';
}

/**
 * Verifica que un campo único no lo esté usando otro usuario.
 * Devuelve el mensaje de error, o '' si está libre.
 */
function campoOcupado($pdo, $campo, $valor, $matriculaActual, $etiqueta) {
    if (empty($valor)) {
        return '';
    }
    $stmt = $pdo->prepare("SELECT nombre FROM usuarios WHERE $campo = :valor AND matricula != :matricula LIMIT 1");
    $stmt->execute(['valor' => $valor, 'matricula' => $matriculaActual]);
    $duenio = $stmt->fetchColumn();

    return $duenio ? "El $etiqueta \"$valor\" ya lo está usando $duenio." : '';
}

/**
 * Lee y normaliza los campos del formulario.
 */
function leerFormularioUsuario() {
    $limpiar = function ($valor) {
        $valor = trim((string)$valor);
        return $valor === '' ? null : $valor;
    };

    return [
        'matricula'    => strtoupper(trim($_POST['matricula'] ?? '')),
        'nombre'       => trim($_POST['nombre'] ?? ''),
        'rol'          => $_POST['rol'] ?? 'alumno',
        'estado'       => $_POST['estado'] ?? 'activo',
        'id_telegram'  => $limpiar($_POST['id_telegram'] ?? ''),
        'tarjeta_rfid' => $limpiar(strtoupper($_POST['tarjeta_rfid'] ?? '')),
        'contrasena'   => trim($_POST['contrasena'] ?? ''),
        'correo'       => $limpiar($_POST['correo'] ?? ''),
        'carrera'      => $limpiar($_POST['carrera'] ?? ''),
        'grupo'        => $limpiar($_POST['grupo'] ?? ''),
        'semestre'     => empty($_POST['semestre']) ? null : (int)$_POST['semestre'],
    ];
}

// -------------------------------------------------------------------------
// 1. PROCESAR ACCIONES
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';
    $d = leerFormularioUsuario();

    // ---------------------------------------------------------------------
    // ALTA DE USUARIO
    // ---------------------------------------------------------------------
    if ($accion === 'guardar') {
        if ($d['matricula'] === '' || $d['nombre'] === '' || $d['contrasena'] === '') {
            $error = 'Faltan datos: la matrícula, el nombre y la contraseña son obligatorios.';
        } elseif (strlen($d['contrasena']) < 6) {
            $error = 'La contraseña debe tener al menos 6 caracteres.';
        } elseif (($errCarrera = validarCarrera($d['carrera'])) !== '') {
            $error = $errCarrera;
        } else {
            try {
                $check = $pdo->prepare("SELECT nombre FROM usuarios WHERE matricula = :m");
                $check->execute(['m' => $d['matricula']]);
                $existente = $check->fetchColumn();

                $conflicto = '';
                if ($existente) {
                    $conflicto = "La matrícula {$d['matricula']} ya está registrada a nombre de $existente.";
                }
                foreach ([['correo', 'correo'], ['tarjeta_rfid', 'UID de tarjeta'], ['id_telegram', 'chat ID de Telegram']] as [$campo, $etiqueta]) {
                    if ($conflicto === '') {
                        $conflicto = campoOcupado($pdo, $campo, $d[$campo], $d['matricula'], $etiqueta);
                    }
                }

                if ($conflicto !== '') {
                    $error = $conflicto;
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO usuarios (matricula, nombre, rol, estado, id_telegram, tarjeta_rfid, contrasena, correo, carrera, grupo, semestre)
                        VALUES (:matricula, :nombre, :rol, :estado, :id_telegram, :tarjeta_rfid, :contrasena, :correo, :carrera, :grupo, :semestre)
                    ");
                    $stmt->execute([
                        'matricula' => $d['matricula'],
                        'nombre' => $d['nombre'],
                        'rol' => $d['rol'],
                        'estado' => $d['estado'],
                        'id_telegram' => $d['id_telegram'],
                        'tarjeta_rfid' => $d['tarjeta_rfid'],
                        'contrasena' => password_hash($d['contrasena'], PASSWORD_DEFAULT),
                        'correo' => $d['correo'],
                        'carrera' => $d['carrera'],
                        'grupo' => $d['grupo'],
                        'semestre' => $d['semestre'],
                    ]);
                    $success = "Usuario {$d['nombre']} registrado correctamente.";
                }
            } catch (PDOException $e) {
                $error = mensajeErrorBD($e, 'registrar el usuario', $user['rol'] ?? null, 'usuarios.php');
            }
        }
    }

    // ---------------------------------------------------------------------
    // EDICIÓN DE USUARIO
    // ---------------------------------------------------------------------
    if ($accion === 'actualizar') {
        $matriculaOriginal = strtoupper(trim($_POST['matricula_original'] ?? ''));

        if ($matriculaOriginal === '' || $d['nombre'] === '') {
            $error = 'Faltan datos para actualizar: el nombre es obligatorio.';
        } elseif ($d['contrasena'] !== '' && strlen($d['contrasena']) < 6) {
            $error = 'La contraseña nueva debe tener al menos 6 caracteres.';
        } elseif (($errCarrera = validarCarrera($d['carrera'])) !== '') {
            $error = $errCarrera;
        } else {
            try {
                $conflicto = '';
                foreach ([['correo', 'correo'], ['tarjeta_rfid', 'UID de tarjeta'], ['id_telegram', 'chat ID de Telegram']] as [$campo, $etiqueta]) {
                    if ($conflicto === '') {
                        $conflicto = campoOcupado($pdo, $campo, $d[$campo], $matriculaOriginal, $etiqueta);
                    }
                }

                // No permitir que el admin en sesión se quite a sí mismo el acceso
                if ($conflicto === '' && $matriculaOriginal === $user['matricula']
                    && ($d['rol'] !== 'administrativo' || $d['estado'] !== 'activo')) {
                    $conflicto = 'No puedes quitarte a ti mismo el rol de administrativo ni desactivarte.';
                }

                if ($conflicto !== '') {
                    $error = $conflicto;
                } else {
                    $campos = "nombre = :nombre, rol = :rol, estado = :estado, id_telegram = :id_telegram,
                               tarjeta_rfid = :tarjeta_rfid, correo = :correo, carrera = :carrera,
                               grupo = :grupo, semestre = :semestre";
                    $params = [
                        'nombre' => $d['nombre'],
                        'rol' => $d['rol'],
                        'estado' => $d['estado'],
                        'id_telegram' => $d['id_telegram'],
                        'tarjeta_rfid' => $d['tarjeta_rfid'],
                        'correo' => $d['correo'],
                        'carrera' => $d['carrera'],
                        'grupo' => $d['grupo'],
                        'semestre' => $d['semestre'],
                        'matricula' => $matriculaOriginal,
                    ];

                    if ($d['contrasena'] !== '') {
                        $campos .= ", contrasena = :contrasena";
                        $params['contrasena'] = password_hash($d['contrasena'], PASSWORD_DEFAULT);
                    }

                    $stmt = $pdo->prepare("UPDATE usuarios SET $campos WHERE matricula = :matricula");
                    $stmt->execute($params);

                    // Refrescar la sesión si el admin se editó a sí mismo
                    if ($matriculaOriginal === $user['matricula']) {
                        $_SESSION['usuario']['nombre'] = $d['nombre'];
                        $user = $_SESSION['usuario'];
                    }

                    $success = "Datos de {$d['nombre']} actualizados correctamente.";
                }
            } catch (PDOException $e) {
                $error = mensajeErrorBD($e, 'guardar los cambios', $user['rol'] ?? null, 'usuarios.php');
            }
        }
    }
}

// Si se acaba de guardar a alguien, la lista que se ve es la de su rol
if ($success !== '' && isset($_POST['rol']) && isset($rolesVista[$_POST['rol']])) {
    $rolVista = $_POST['rol'];
}

// -------------------------------------------------------------------------
// ELIMINAR USUARIO
// -------------------------------------------------------------------------
if (isset($_GET['eliminar'])) {
    $matriculaEliminar = trim($_GET['eliminar']);

    if ($matriculaEliminar === $user['matricula']) {
        $error = 'No puedes eliminar tu propio usuario mientras tienes la sesión abierta.';
    } else {
        try {
            $stmt = $pdo->prepare("DELETE FROM usuarios WHERE matricula = :m");
            $stmt->execute(['m' => $matriculaEliminar]);
            $success = $stmt->rowCount() > 0
                ? 'Usuario eliminado junto con su historial de asistencias.'
                : 'Ese usuario ya no existe en el sistema.';
        } catch (PDOException $e) {
            $error = mensajeErrorBD($e, 'eliminar el usuario', $user['rol'] ?? null, 'usuarios.php');
        }
    }
}

// -------------------------------------------------------------------------
// CARGAR USUARIO A EDITAR
// -------------------------------------------------------------------------
if (isset($_GET['editar'])) {
    $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE matricula = :m LIMIT 1");
    $stmt->execute(['m' => trim($_GET['editar'])]);
    $editando = $stmt->fetch() ?: null;

    if (!$editando) {
        $error = 'No se encontró el usuario que quieres editar.';
    }
}

// Si el guardado falló, se vuelve a mostrar el formulario con lo que escribió
$formDatos = ['rol' => $rolVista];
if ($editando) {
    $formDatos = $editando;
} elseif ($error !== '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $formDatos = $_POST;
    if (($_POST['accion'] ?? '') === 'actualizar') {
        $editando = $_POST;
        $editando['matricula'] = $_POST['matricula_original'] ?? ($_POST['matricula'] ?? '');
        $formDatos = $editando;
    }
}

// ¿Formulario o listado?
$modoForm = $editando !== null
    || isset($_GET['nuevo'])
    || ($error !== '' && $_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['accion'] ?? '', ['guardar', 'actualizar'], true));

// -------------------------------------------------------------------------
// LISTADO (del rol que se está viendo)
// -------------------------------------------------------------------------
$conteoRoles = ['alumno' => 0, 'docente' => 0, 'administrativo' => 0];
$materiasDocente = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE rol = :rol ORDER BY nombre ASC");
    $stmt->execute(['rol' => $rolVista]);
    $usuarios = $stmt->fetchAll();

    foreach ($pdo->query("SELECT rol, COUNT(*) AS n FROM usuarios GROUP BY rol")->fetchAll() as $r) {
        if (isset($conteoRoles[$r['rol']])) {
            $conteoRoles[$r['rol']] = (int)$r['n'];
        }
    }

    if ($rolVista === 'docente') {
        $stmt = $pdo->query("SELECT docente_id, nombre_materia FROM clases GROUP BY docente_id, nombre_materia ORDER BY nombre_materia ASC");
        foreach ($stmt->fetchAll() as $r) {
            $materiasDocente[$r['docente_id']][] = $r['nombre_materia'];
        }
    }
} catch (PDOException $e) {
    $error = mensajeErrorBD($e, 'cargar la lista de usuarios', $user['rol'] ?? null, 'usuarios.php');
    $usuarios = [];
}

// Cajas disponibles para el alta de tarjetas
try {
    $dispositivos = $pdo->query("SELECT device_id, nombre, aula FROM dispositivos WHERE estado = 'activo' ORDER BY nombre ASC")->fetchAll();
} catch (PDOException $e) {
    $dispositivos = [];
}

$v = function ($campo, $defecto = '') use ($formDatos) {
    return htmlspecialchars($formDatos[$campo] ?? $defecto);
};

require_once __DIR__ . '/header.php';
?>

<div class="top-bar">
    <div class="page-title">
        <span class="page-eyebrow">Módulo de gestión</span>
        <h2><?php echo $modoForm ? ($editando ? 'Editar ' : 'Nuevo ') . $rolesVista[$formDatos['rol'] ?? $rolVista]['singular'] : $rolesVista[$rolVista]['plural']; ?></h2>
    </div>
    <?php if (!$modoForm): ?>
        <a href="usuarios.php?rol=<?php echo $rolVista; ?>&nuevo=1" class="btn-primary">
            <i class="fa-solid fa-plus"></i> <span><?php echo $rolesVista[$rolVista]['nuevo']; ?></span>
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

<?php $rolForm = $formDatos['rol'] ?? $rolVista; if (!isset($rolesVista[$rolForm])) { $rolForm = $rolVista; } ?>

<!-- ===================== FORMULARIO (ALTA O EDICIÓN) ===================== -->
<form action="usuarios.php?rol=<?php echo htmlspecialchars($rolVista); ?>" method="POST" class="form-card">
    <input type="hidden" name="accion" value="<?php echo $editando ? 'actualizar' : 'guardar'; ?>">
    <?php if ($editando): ?>
        <input type="hidden" name="matricula_original" value="<?php echo $v('matricula'); ?>">
    <?php endif; ?>

    <div class="form-card-head">
        <h3>Datos del <?php echo $rolesVista[$rolForm]['singular']; ?></h3>
        <p><?php echo $editando ? 'Modifica la información de acceso, contacto y vinculación con la caja.' : 'Completa los datos para dar de alta la cuenta. Los campos con * son obligatorios.'; ?></p>
    </div>

    <div class="form-card-body">
        <div class="form-grid">

            <div class="form-group">
                <label for="matricula"><i class="fa-solid fa-id-badge"></i> Matrícula / ID *</label>
                <input type="text" id="matricula" name="matricula" class="form-select"
                       placeholder="Ej: 2330018" required autocomplete="off"
                       value="<?php echo $v('matricula'); ?>" <?php echo $editando ? 'readonly' : ''; ?>>
                <?php if ($editando): ?>
                    <p class="form-ayuda">La matrícula no se puede cambiar.</p>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label for="nombre"><i class="fa-solid fa-user"></i> Nombre completo *</label>
                <input type="text" id="nombre" name="nombre" class="form-select" placeholder="Ej: Carlos Sánchez"
                       required autocomplete="off" value="<?php echo $v('nombre'); ?>">
            </div>

            <div class="form-group">
                <label for="correo"><i class="fa-solid fa-envelope"></i> Correo electrónico</label>
                <input type="email" id="correo" name="correo" class="form-select"
                       placeholder="correo@upv.edu.mx" value="<?php echo $v('correo'); ?>">
            </div>

            <div class="form-group">
                <label for="contrasena"><i class="fa-solid fa-lock"></i> Contraseña <?php echo $editando ? '(vacío = no cambiarla)' : '*'; ?></label>
                <input type="password" id="contrasena" name="contrasena" class="form-select"
                       placeholder="Mínimo 6 caracteres" <?php echo $editando ? '' : 'required'; ?>>
            </div>

            <div class="form-group">
                <label for="rol"><i class="fa-solid fa-user-tag"></i> Rol *</label>
                <select id="rol" name="rol" class="form-select" onchange="toggleStudentFields(this.value)" required>
                    <?php foreach (['alumno' => 'Alumno', 'docente' => 'Docente', 'administrativo' => 'Administrativo'] as $val => $txt): ?>
                        <option value="<?php echo $val; ?>" <?php echo ($rolForm === $val) ? 'selected' : ''; ?>><?php echo $txt; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="estado"><i class="fa-solid fa-toggle-on"></i> Estado *</label>
                <select id="estado" name="estado" class="form-select" required>
                    <option value="activo" <?php echo ($v('estado', 'activo') === 'activo') ? 'selected' : ''; ?>>Activo</option>
                    <option value="inactivo" <?php echo ($v('estado') === 'inactivo') ? 'selected' : ''; ?>>Inactivo</option>
                </select>
            </div>

            <div class="form-group">
                <label for="tarjeta_rfid"><i class="fa-solid fa-id-card"></i> UID tarjeta RFID (HEX)</label>
                <div style="display: flex; gap: 8px;">
                    <input type="text" id="tarjeta_rfid" name="tarjeta_rfid" class="form-select"
                           placeholder="Ej: B144B71D" autocomplete="off" style="flex: 1;"
                           value="<?php echo $v('tarjeta_rfid'); ?>">
                    <?php if ($editando): ?>
                        <button type="button" class="btn-filter" style="white-space: nowrap;"
                                onclick="abrirEnrolamiento('<?php echo $v('matricula'); ?>', '<?php echo $v('nombre'); ?>')">
                            <i class="fa-solid fa-wifi"></i> Leer
                        </button>
                    <?php endif; ?>
                </div>
                <p class="form-ayuda">Escríbelo o usa «Leer» para capturarlo con la caja.</p>
            </div>

            <div class="form-group">
                <label for="id_telegram"><i class="fa-brands fa-telegram"></i> Telegram chat ID</label>
                <input type="text" id="id_telegram" name="id_telegram" class="form-select"
                       placeholder="Ej: 987654321" autocomplete="off" value="<?php echo $v('id_telegram'); ?>">
                <p class="form-ayuda">Sin Telegram, necesitará la firma del docente para registrar asistencia.</p>
            </div>

            <!-- CAMPOS EXCLUSIVOS PARA ALUMNOS -->
            <div id="campos_alumno" class="span-2">
                <div class="form-grid">
                    <div class="form-seccion">Detalles académicos</div>

                    <div class="form-group span-2">
                        <label for="carrera"><i class="fa-solid fa-graduation-cap"></i> Carrera</label>
                        <select id="carrera" name="carrera" class="form-select">
                            <?php echo opcionesCarrera($formDatos['carrera'] ?? '', 'Selecciona una carrera'); ?>
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
                </div>
            </div>

        </div>
    </div>

    <div class="form-card-foot">
        <div class="grupo-izq">
            <a href="usuarios.php?rol=<?php echo htmlspecialchars($rolVista); ?>" class="btn-tabla" style="padding: 0.6rem 1rem;">
                <i class="fa-solid fa-xmark"></i> Cancelar
            </a>
            <?php if ($editando && $v('matricula') !== htmlspecialchars($user['matricula'])): ?>
                <a href="usuarios.php?rol=<?php echo htmlspecialchars($rolVista); ?>&eliminar=<?php echo urlencode($editando['matricula']); ?>"
                   class="btn-tabla btn-tabla-danger" style="padding: 0.6rem 1rem;"
                   onclick="return confirm('Se eliminará a <?php echo htmlspecialchars($editando['nombre'] ?? '', ENT_QUOTES); ?> y todo su historial de asistencias. ¿Continuar?')">
                    <i class="fa-solid fa-trash"></i> Eliminar
                </a>
            <?php endif; ?>
        </div>
        <div class="grupo-der">
            <button type="submit" class="btn-primary btn-auto">
                <i class="fa-solid <?php echo $editando ? 'fa-floppy-disk' : 'fa-user-check'; ?>"></i>
                <span><?php echo $editando ? 'Guardar cambios' : 'Registrar ' . $rolesVista[$rolForm]['singular']; ?></span>
            </button>
        </div>
    </div>
</form>

<?php else: ?>

<!-- ===================== LISTADO ===================== -->
<div style="display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap;">
    <div class="buscador">
        <i class="fa-solid fa-magnifying-glass"></i>
        <input type="search" id="buscar" placeholder="Buscar por nombre, código o correo..." autocomplete="off">
    </div>
    <div class="segmentos">
        <?php foreach ($rolesVista as $clave => $info): ?>
            <a href="usuarios.php?rol=<?php echo $clave; ?>" class="segmento <?php echo $clave === $rolVista ? 'active' : ''; ?>">
                <?php echo $info['plural']; ?> (<?php echo $conteoRoles[$clave]; ?>)
            </a>
        <?php endforeach; ?>
    </div>
</div>

<div class="table-card">
    <div class="table-responsive">
        <table class="custom-table">
            <thead>
                <tr>
                    <th>Código</th>
                    <th><?php echo $rolVista === 'alumno' ? 'Nombre completo' : 'Nombre'; ?></th>
                    <?php if ($rolVista === 'alumno'): ?>
                        <th>Programa</th>
                        <th>Semestre</th>
                    <?php elseif ($rolVista === 'docente'): ?>
                        <th>Materias</th>
                    <?php endif; ?>
                    <th>Vinculación</th>
                    <th>Estado</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody id="tablaUsuarios">
                <?php $columnas = $rolVista === 'alumno' ? 7 : ($rolVista === 'docente' ? 6 : 5); ?>
                <?php if (empty($usuarios)): ?>
                    <tr>
                        <td colspan="<?php echo $columnas; ?>" style="text-align: center; color: var(--text-muted); padding: 36px 10px;">
                            Todavía no hay <?php echo strtolower($rolesVista[$rolVista]['plural']); ?> registrados.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($usuarios as $u): ?>
                        <tr data-buscar="<?php echo htmlspecialchars(strtolower($u['nombre'] . ' ' . $u['matricula'] . ' ' . ($u['correo'] ?? ''))); ?>">
                            <td class="celda-codigo"><?php echo htmlspecialchars($u['matricula']); ?></td>
                            <td>
                                <strong><?php echo htmlspecialchars($u['nombre']); ?></strong>
                                <div class="celda-sub"><?php echo htmlspecialchars($u['correo'] ?: 'Sin correo'); ?></div>
                            </td>

                            <?php if ($rolVista === 'alumno'): ?>
                                <td style="max-width: 16rem;">
                                    <?php echo htmlspecialchars($u['carrera'] ?: 'Sin carrera'); ?>
                                    <?php if ($u['grupo']): ?>
                                        <div class="celda-sub">Grupo <?php echo htmlspecialchars($u['grupo']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $u['semestre'] ? (int)$u['semestre'] . '°' : '—'; ?></td>
                            <?php elseif ($rolVista === 'docente'): ?>
                                <td>
                                    <?php if (!empty($materiasDocente[$u['matricula']])): ?>
                                        <?php foreach ($materiasDocente[$u['matricula']] as $mat): ?>
                                            <span class="chip"><?php echo htmlspecialchars($mat); ?></span>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <span style="color: var(--text-muted); font-size: 0.82rem;">Sin asignar</span>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>

                            <td style="white-space: nowrap;">
                                <span title="<?php echo empty($u['tarjeta_rfid']) ? 'Sin tarjeta RFID' : 'Tarjeta: ' . htmlspecialchars($u['tarjeta_rfid']); ?>"
                                      style="<?php echo empty($u['tarjeta_rfid']) ? 'opacity: .3;' : 'color: var(--success);'; ?>">
                                    <i class="fa-solid fa-id-card"></i>
                                </span>
                                &nbsp;
                                <span title="<?php echo empty($u['id_telegram']) ? 'Sin Telegram: necesitará la firma del docente' : 'Telegram vinculado'; ?>"
                                      style="<?php echo empty($u['id_telegram']) ? 'opacity: .3;' : 'color: var(--info);'; ?>">
                                    <i class="fa-brands fa-telegram"></i>
                                </span>
                            </td>
                            <td>
                                <span class="badge badge-<?php echo ($u['estado'] === 'activo') ? 'active' : 'inactive'; ?>">
                                    <?php echo htmlspecialchars($u['estado']); ?>
                                </span>
                            </td>
                            <td>
                                <div style="display: flex; gap: 6px;">
                                    <a href="usuarios.php?rol=<?php echo $rolVista; ?>&editar=<?php echo urlencode($u['matricula']); ?>" class="btn-tabla">Editar</a>
                                    <?php if ($u['matricula'] !== $user['matricula']): ?>
                                        <a href="usuarios.php?rol=<?php echo $rolVista; ?>&eliminar=<?php echo urlencode($u['matricula']); ?>"
                                           class="btn-tabla btn-tabla-danger"
                                           onclick="return confirm('Se eliminará a <?php echo htmlspecialchars($u['nombre'], ENT_QUOTES); ?> y todo su historial de asistencias. ¿Continuar?')">Eliminar</a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <tr id="sinResultados" style="display: none;">
                        <td colspan="<?php echo $columnas; ?>" style="text-align: center; color: var(--text-muted); padding: 30px 10px;">
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
        document.querySelectorAll('#tablaUsuarios tr[data-buscar]').forEach(function (tr) {
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

<!-- ===================================================================== -->
<!-- MODAL: REGISTRO DE TARJETA RFID CON EL LECTOR DE LA CAJA              -->
<!-- ===================================================================== -->
<div id="modalRfid" class="modal-fondo">
    <div class="modal-caja">
        <h3 style="margin-bottom: 6px;">
            <i class="fa-solid fa-id-card icon-purple"></i> Registrar tarjeta
        </h3>
        <p style="color: var(--text-muted); font-size: 0.85rem; margin-bottom: 16px;">
            Asignando tarjeta a <strong id="rfidNombre"></strong>
        </p>

        <?php if (count($dispositivos) > 1): ?>
            <div class="form-group">
                <label for="rfidDispositivo">CAJA QUE LEERÁ LA TARJETA</label>
                <select id="rfidDispositivo" class="form-select">
                    <option value="">Cualquier caja disponible</option>
                    <?php foreach ($dispositivos as $dev): ?>
                        <option value="<?php echo htmlspecialchars($dev['device_id']); ?>">
                            <?php echo htmlspecialchars($dev['nombre'] ?: $dev['device_id']); ?>
                            <?php echo $dev['aula'] ? ' — ' . htmlspecialchars($dev['aula']) : ''; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php else: ?>
            <select id="rfidDispositivo" style="display: none;"><option value=""></option></select>
        <?php endif; ?>

        <div id="rfidEstado" class="rfid-estado">
            <i class="fa-solid fa-circle-nodes fa-spin"></i>
            <span id="rfidMensaje">Preparando...</span>
        </div>

        <div style="display: flex; gap: 8px; margin-top: 18px;">
            <button type="button" class="btn-primary" style="flex: 1;" onclick="iniciarLectura()">
                <i class="fa-solid fa-play"></i> <span id="rfidBotonTexto">Iniciar lectura</span>
            </button>
            <button type="button" class="btn-reset" onclick="cerrarEnrolamiento()">Cerrar</button>
        </div>
    </div>
</div>

<style>
.modal-fondo {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(43, 13, 16, 0.55);
    backdrop-filter: blur(3px);
    z-index: 999;
    align-items: center;
    justify-content: center;
    padding: 20px;
}
.modal-fondo.abierto { display: flex; }
.modal-caja {
    background: #ffffff;
    border: 1px solid var(--line);
    border-radius: 14px;
    padding: 24px;
    width: 100%;
    max-width: 420px;
    box-shadow: 0 20px 50px rgba(43, 13, 16, 0.3);
}
.rfid-estado {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 14px;
    border-radius: 10px;
    background: var(--info-bg);
    border: 1px solid var(--info-line);
    color: #204c6e;
    font-size: 0.88rem;
    min-height: 54px;
}
.rfid-estado.ok { background: var(--success-bg); border-color: var(--success-line); color: #17603a; }
.rfid-estado.error { background: var(--danger-bg); border-color: var(--danger-line); color: #8f1d14; }
</style>

<script>
// -------------------------------------------------------------------------
// Mostrar u ocultar los campos exclusivos de alumnos
// -------------------------------------------------------------------------
function toggleStudentFields(role) {
    var caja = document.getElementById('campos_alumno');
    if (caja) { caja.style.display = (role === 'alumno') ? 'block' : 'none'; }
}

document.addEventListener("DOMContentLoaded", function () {
    var selRol = document.getElementById('rol');
    if (selRol) { toggleStudentFields(selRol.value); }
});

// -------------------------------------------------------------------------
// Registro de tarjeta RFID usando el lector de la caja ESP32
// -------------------------------------------------------------------------
let enrolId = null;
let enrolTimer = null;
let enrolMatricula = null;

function abrirEnrolamiento(matricula, nombre) {
    enrolMatricula = matricula;
    document.getElementById('rfidNombre').textContent = nombre + ' (' + matricula + ')';
    document.getElementById('modalRfid').classList.add('abierto');
    pintarEstado('Pulsa "Iniciar lectura" y acerca la tarjeta a la caja.', '');
    document.getElementById('rfidBotonTexto').textContent = 'Iniciar lectura';
}

function cerrarEnrolamiento() {
    detenerSondeo();
    if (enrolId) {
        enviar({ accion: 'cancel', enrol_id: enrolId });
        enrolId = null;
    }
    document.getElementById('modalRfid').classList.remove('abierto');
}

function pintarEstado(mensaje, clase) {
    const caja = document.getElementById('rfidEstado');
    caja.className = 'rfid-estado ' + clase;
    document.getElementById('rfidMensaje').textContent = mensaje;
}

function enviar(datos) {
    return fetch('api/rfid_enroll.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(datos)
    }).then(r => r.json());
}

function iniciarLectura() {
    detenerSondeo();
    pintarEstado('Acerca la tarjeta al lector de la caja...', '');
    document.getElementById('rfidBotonTexto').textContent = 'Reintentar';

    enviar({
        accion: 'start',
        matricula: enrolMatricula,
        device_id: document.getElementById('rfidDispositivo').value
    }).then(res => {
        if (!res.success) {
            pintarEstado(res.message || 'No se pudo iniciar la lectura.', 'error');
            return;
        }
        enrolId = res.enrol_id;
        enrolTimer = setInterval(consultarEstado, 1500);
    }).catch(() => pintarEstado('Se perdió la conexión con el servidor.', 'error'));
}

function consultarEstado() {
    if (!enrolId) return;

    enviar({ accion: 'status', enrol_id: enrolId }).then(res => {
        if (!res.success) {
            pintarEstado(res.message || 'Error al consultar.', 'error');
            detenerSondeo();
            return;
        }

        if (res.estado === 'completado') {
            detenerSondeo();
            enrolId = null;
            pintarEstado('¡Listo! Tarjeta ' + res.uid + ' asignada. Recargando...', 'ok');
            const campo = document.getElementById('tarjeta_rfid');
            if (campo && campo.form && campo.form.matricula_original
                && campo.form.matricula_original.value === res.matricula) {
                campo.value = res.uid;
            }
            setTimeout(() => location.reload(), 1200);
        } else if (res.estado === 'expirado') {
            detenerSondeo();
            enrolId = null;
            pintarEstado('Se agotó el tiempo. Pulsa "Reintentar".', 'error');
        } else if (res.estado === 'cancelado') {
            detenerSondeo();
            enrolId = null;
            pintarEstado('La solicitud fue cancelada.', 'error');
        } else {
            pintarEstado(res.message, '');
        }
    }).catch(() => {});
}

function detenerSondeo() {
    if (enrolTimer) {
        clearInterval(enrolTimer);
        enrolTimer = null;
    }
}
</script>

<?php
require_once __DIR__ . '/footer.php';
?>
