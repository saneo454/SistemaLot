<?php
// =========================================================================
// PÁGINA DE DIAGNÓSTICO
// =========================================================================
// Ábrela en el navegador para comprobar que todo quedó bien instalado:
//   http://localhost/esp32-attendance-system/verificacion.php
//
// Como enseña versión de PHP, nombre de la base, cuántos usuarios hay y el
// bot de Telegram, en un servidor público NO puede quedar abierta: pide
// sesión de administrativo. La sesión vive en archivos, así que esta
// comprobación sigue funcionando aunque MySQL esté caído.
//
// Si te quedas fuera y necesitas el diagnóstico, comenta el bloque de abajo.
// =========================================================================

session_start();

if (!isset($_SESSION['usuario']) || $_SESSION['usuario']['rol'] !== 'administrativo') {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    exit('<!doctype html><meta charset="utf-8">'
       . '<p style="font-family:sans-serif;padding:30px">'
       . 'Esta página es sólo para el administrador. '
       . '<a href="login.php">Inicia sesión</a>.</p>');
}

define('CONEXION_SILENCIOSA', true);
require_once __DIR__ . '/includes/conexion.php';

$pruebas = [];

/**
 * Registra el resultado de una comprobación.
 * $nivel: 'ok' | 'aviso' | 'error'
 */
function revisar(&$pruebas, $nombre, $nivel, $detalle, $solucion = '') {
    $pruebas[] = compact('nombre', 'nivel', 'detalle', 'solucion');
}

// -------------------------------------------------------------------------
// 1. ENTORNO PHP
// -------------------------------------------------------------------------
revisar($pruebas, 'Versión de PHP',
    version_compare(PHP_VERSION, '7.4', '>=') ? 'ok' : 'error',
    PHP_VERSION,
    'Se necesita PHP 7.4 o superior.');

foreach (['pdo_mysql' => 'Conexión a MySQL', 'curl' => 'Envío de mensajes a Telegram'] as $ext => $paraQue) {
    revisar($pruebas, "Extensión $ext",
        extension_loaded($ext) ? 'ok' : 'error',
        extension_loaded($ext) ? 'Instalada' : 'NO instalada',
        "Se usa para: $paraQue. Actívala en php.ini.");
}

// -------------------------------------------------------------------------
// 2. BASE DE DATOS
// -------------------------------------------------------------------------
if (!$pdo) {
    revisar($pruebas, 'Conexión a la base de datos', 'error',
        $conexionError ?? 'No se pudo conectar',
        'Revisa usuario, contraseña y nombre de la base en includes/conexion.php. ¿Está encendido MySQL en XAMPP?');
} else {
    // Se pregunta el nombre real en vez de escribirlo a mano: si la página
    // apunta a otra base (por ejemplo la de la versión anterior), aquí se ve.
    $baseActual = $pdo->query('SELECT DATABASE()')->fetchColumn();
    revisar($pruebas, 'Conexión a la base de datos', 'ok', 'Conectado a ' . $baseActual);

    // ---------------------------------------------------------------------
    // 3. ZONA HORARIA (el punto 1 del prompt)
    // ---------------------------------------------------------------------
    $horaPhp = date('Y-m-d H:i:s');
    $horaMysql = $pdo->query("SELECT NOW()")->fetchColumn();
    $diferencia = abs(strtotime($horaPhp) - strtotime($horaMysql));

    revisar($pruebas, 'Zona horaria PHP',
        date_default_timezone_get() === APP_TIMEZONE ? 'ok' : 'error',
        date_default_timezone_get() . ' → ' . $horaPhp);

    revisar($pruebas, 'Hora de MySQL alineada con PHP',
        $diferencia <= 60 ? 'ok' : 'error',
        "MySQL dice: $horaMysql (diferencia: {$diferencia}s)",
        'Si la diferencia es de horas, MySQL no está aceptando el SET time_zone. Revisa includes/conexion.php.');

    // ---------------------------------------------------------------------
    // 4. MIGRACIÓN
    // ---------------------------------------------------------------------
    $tablas = ['usuarios', 'clases', 'asistencias', 'codigos_verificacion',
               'dispositivos', 'inscripciones', 'enrolamientos_rfid'];
    $nuevas = ['dispositivos', 'inscripciones', 'enrolamientos_rfid'];
    $faltantes = [];

    foreach ($tablas as $t) {
        $existe = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($t))->fetch();
        if (!$existe) $faltantes[] = $t;
    }

    if (empty($faltantes)) {
        revisar($pruebas, 'Tablas de la base de datos', 'ok', 'Las 7 tablas existen');
    } else {
        $sonNuevas = array_intersect($faltantes, $nuevas);
        revisar($pruebas, 'Tablas de la base de datos', 'error',
            'Faltan: ' . implode(', ', $faltantes),
            !empty($sonNuevas)
                ? 'Ejecuta database/migracion_v2.sql en phpMyAdmin.'
                : 'Importa primero database/esp32_attendance.sql.');
    }

    // Columna nueva en asistencias
    $col = $pdo->query("SHOW COLUMNS FROM asistencias LIKE 'dispositivo_id'")->fetch();
    revisar($pruebas, 'Columna asistencias.dispositivo_id',
        $col ? 'ok' : 'error',
        $col ? 'Existe' : 'No existe',
        'Ejecuta database/migracion_v2.sql.');

    // ---------------------------------------------------------------------
    // 5. CONTENIDO
    // ---------------------------------------------------------------------
    if (empty($faltantes)) {
        $conteo = function ($sql) use ($pdo) { return (int)$pdo->query($sql)->fetchColumn(); };

        $admins   = $conteo("SELECT COUNT(*) FROM usuarios WHERE rol='administrativo' AND estado='activo'");
        $docentes = $conteo("SELECT COUNT(*) FROM usuarios WHERE rol='docente' AND estado='activo'");
        $alumnos  = $conteo("SELECT COUNT(*) FROM usuarios WHERE rol='alumno' AND estado='activo'");
        $clases   = $conteo("SELECT COUNT(*) FROM clases");
        $inscrip  = $conteo("SELECT COUNT(*) FROM inscripciones");
        $cajas    = $conteo("SELECT COUNT(*) FROM dispositivos");

        revisar($pruebas, 'Administradores activos',
            $admins > 0 ? 'ok' : 'error',
            "$admins",
            'Sin un administrativo activo nadie puede entrar al panel de gestión.');

        revisar($pruebas, 'Usuarios registrados', 'ok',
            "$alumnos alumnos, $docentes docentes, $admins administrativos");

        revisar($pruebas, 'Clases programadas',
            $clases > 0 ? 'ok' : 'aviso', "$clases",
            'Sin clases, las asistencias se guardan como "General / Sin Horario".');

        revisar($pruebas, 'Inscripciones (clases asignadas a alumnos)',
            $inscrip > 0 ? 'ok' : 'aviso', "$inscrip",
            'Ve a Inscripciones y usa "Inscribir a todo el grupo configurado".');

        revisar($pruebas, 'Cajas ESP32 registradas',
            $cajas > 0 ? 'ok' : 'aviso', "$cajas",
            'Aparecen solas cuando la caja envía su primera lectura.');

        // Avisos de configuración incompleta
        $sinTarjeta = $conteo("SELECT COUNT(*) FROM usuarios WHERE rol='alumno' AND estado='activo' AND (tarjeta_rfid IS NULL OR tarjeta_rfid='')");
        $sinTg      = $conteo("SELECT COUNT(*) FROM usuarios WHERE rol='alumno' AND estado='activo' AND (id_telegram IS NULL OR id_telegram='')");

        revisar($pruebas, 'Alumnos sin tarjeta RFID',
            $sinTarjeta === 0 ? 'ok' : 'aviso', "$sinTarjeta",
            'Regístralas desde Usuarios con el botón de la tarjeta, o con "*" en la caja.');

        revisar($pruebas, 'Alumnos sin Telegram',
            $sinTg === 0 ? 'ok' : 'aviso', "$sinTg",
            'Esos alumnos necesitarán la firma del docente cada vez que pasen lista.');
    }
}

// -------------------------------------------------------------------------
// 6. BOT DE TELEGRAM
// -------------------------------------------------------------------------
if (extension_loaded('curl')) {
    $ch = curl_init('https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/getMe');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_SSL_VERIFYPEER => false]);
    $respuesta = curl_exec($ch);
    $codigo = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $datos = json_decode($respuesta, true);

    if ($codigo === 200 && !empty($datos['ok'])) {
        revisar($pruebas, 'Bot de Telegram', 'ok',
            'Responde correctamente: @' . ($datos['result']['username'] ?? '?'));
    } elseif ($codigo === 0) {
        revisar($pruebas, 'Bot de Telegram', 'aviso',
            'Sin salida a internet desde el servidor',
            'Los códigos de verificación no podrán enviarse.');
    } else {
        revisar($pruebas, 'Bot de Telegram', 'error',
            "Telegram respondió con código $codigo",
            'Revisa TELEGRAM_BOT_TOKEN en includes/config.php.');
    }
}

// -------------------------------------------------------------------------
// RESUMEN
// -------------------------------------------------------------------------
$errores = count(array_filter($pruebas, fn($p) => $p['nivel'] === 'error'));
$avisos  = count(array_filter($pruebas, fn($p) => $p['nivel'] === 'aviso'));
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verificación del Sistema</title>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Outfit:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="public/dashboard.css">
    <style>
        body { padding: clamp(16px, 3vw, 40px); width: 100%; max-width: min(100%, 1400px); margin: 0 auto; display: block; }
        .fila { display: flex; gap: 14px; padding: 14px; border-radius: 10px;
                margin-bottom: 8px; border: 1px solid var(--line); align-items: flex-start; }
        .fila.ok    { background: var(--success-bg); border-color: var(--success-line); }
        .fila.aviso { background: var(--warning-bg); border-color: var(--warning-line); }
        .fila.error { background: var(--danger-bg); border-color: var(--danger-line); }
        .fila i { font-size: 1.1rem; margin-top: 2px; }
        .fila.ok i    { color: var(--success); }
        .fila.aviso i { color: var(--warning); }
        .fila.error i { color: var(--danger); }
        .fila .nombre { font-weight: 600; }
        .fila .detalle { font-size: 0.87rem; color: var(--text-muted); margin-top: 2px; }
        .fila .solucion { font-size: 0.82rem; margin-top: 6px; opacity: 0.9; }
    </style>
</head>
<body>
    <h1 style="margin-bottom: 6px;">Verificación del Sistema</h1>
    <p style="color: var(--text-muted); margin-bottom: 24px;">
        Comprobación de la instalación — <?php echo date('d/m/Y h:i A'); ?>
    </p>

    <?php if ($errores === 0 && $avisos === 0): ?>
        <div class="alert alert-success" style="margin-bottom: 20px;">
            <i class="fa-solid fa-circle-check"></i>
            <span>Todo está listo. El sistema puede recibir asistencias.</span>
        </div>
    <?php elseif ($errores === 0): ?>
        <div class="alert alert-success" style="margin-bottom: 20px;">
            <i class="fa-solid fa-circle-check"></i>
            <span>Sin errores. Hay <?php echo $avisos; ?> aviso<?php echo $avisos === 1 ? '' : 's'; ?> de configuración pendiente, pero el sistema funciona.</span>
        </div>
    <?php else: ?>
        <div class="alert alert-danger" style="margin-bottom: 20px;">
            <i class="fa-solid fa-circle-exclamation"></i>
            <span><?php echo $errores; ?> error<?php echo $errores === 1 ? '' : 'es'; ?> que hay que corregir antes de usar el sistema.</span>
        </div>
    <?php endif; ?>

    <?php foreach ($pruebas as $p): ?>
        <div class="fila <?php echo $p['nivel']; ?>">
            <i class="fa-solid fa-<?php
                echo $p['nivel'] === 'ok' ? 'circle-check'
                    : ($p['nivel'] === 'aviso' ? 'triangle-exclamation' : 'circle-xmark'); ?>"></i>
            <div style="flex: 1;">
                <div class="nombre"><?php echo htmlspecialchars($p['nombre']); ?></div>
                <div class="detalle"><?php echo htmlspecialchars($p['detalle']); ?></div>
                <?php if ($p['nivel'] !== 'ok' && $p['solucion']): ?>
                    <div class="solucion">
                        <i class="fa-solid fa-arrow-right" style="font-size: 0.7rem;"></i>
                        <?php echo htmlspecialchars($p['solucion']); ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>

    <div class="alert alert-danger" style="margin-top: 24px;">
        <i class="fa-solid fa-trash"></i>
        <span>Cuando termines de configurar, <strong>borra este archivo</strong> (<code>verificacion.php</code>).</span>
    </div>

    <p style="margin-top: 20px;">
        <a href="login.php" class="btn-primary" style="display: inline-flex; width: auto; padding: 10px 20px;">
            <i class="fa-solid fa-arrow-right-to-bracket"></i>
            <span>Ir al sistema</span>
        </a>
    </p>
</body>
</html>
