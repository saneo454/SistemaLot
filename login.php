<?php
session_start();
require_once __DIR__ . '/includes/conexion.php';

// Si ya tiene sesión activa, redirigir según su rol o al panel principal
if (isset($_SESSION['usuario'])) {
    if (isset($_SESSION['usuario']['rol']) && $_SESSION['usuario']['rol'] === 'alumno') {
        header("Location: alumno.php");
        exit;
    } else {
        header("Location: /esp32_attendance_system/pase_lista.php");
        exit;
    }
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $matricula = isset($_POST['matricula']) ? trim(strtoupper($_POST['matricula'])) : '';
    $contrasena = isset($_POST['contrasena']) ? trim($_POST['contrasena']) : '';

    if (!empty($matricula) && !empty($contrasena)) {
        try {
            $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE matricula = :matricula LIMIT 1");
            $stmt->execute(['matricula' => $matricula]);
            $usuario = $stmt->fetch();

            if ($usuario && $usuario['estado'] !== 'activo' && password_verify($contrasena, $usuario['contrasena'])) {
                $error = 'Tu cuenta está dada de baja. Pide al administrador que la reactive.';
            } elseif ($usuario && $usuario['estado'] === 'activo' && password_verify($contrasena, $usuario['contrasena'])) {
                // Credenciales válidas: Iniciar sesión
                $_SESSION['usuario'] = [
                    'id' => $usuario['id'] ?? null, // Agregado por seguridad para consultas directas por ID
                    'matricula' => $usuario['matricula'],
                    'nombre' => $usuario['nombre'],
                    'rol' => $usuario['rol'],
                    'correo' => $usuario['correo'],
                    'carrera' => $usuario['carrera'],
                    'grupo' => $usuario['grupo'],
                    'semestre' => $usuario['semestre'],
                    'id_telegram' => $usuario['id_telegram']
                ];

                // REDIRECCIÓN SEGÚN EL ROL DEL USUARIO
                if ($usuario['rol'] === 'alumno') {
                    header("Location: alumno.php");
                    exit;
                } else {
                    header("Location: index.php");
                    exit;
                }

            } else {
                $error = 'La matrícula o la contraseña no coinciden. Revísalas e intenta de nuevo.';
            }
        } catch (PDOException $e) {
            error_log('[login.php] ' . $e->getMessage());
            $error = 'No se pudo conectar con la base de datos. Avisa al administrador.';
        }
    } else {
        $error = 'Escribe tu matrícula y tu contraseña.';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acceso al Sistema - Asistencia ESP32</title>
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Outfit:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <!-- FontAwesome para Iconos -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- CSS del Dashboard -->
    <link rel="stylesheet" href="public/dashboard.css">
</head>
<body class="login-container">
    <div class="login-card">
        <div class="login-header">
            <div style="display: flex; justify-content: center; margin-bottom: 15px;">
                <div class="brand-icon">
                    <i class="fa-solid fa-fingerprint"></i>
                </div>
            </div>
            <h1>Control de Asistencia</h1>
            <p>Ingresa tus credenciales para acceder</p>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <span><?php echo htmlspecialchars($error); ?></span>
            </div>
        <?php endif; ?>

        <form action="login.php" method="POST">
            <div class="form-group">
                <label for="matricula">MATRÍCULA / ID</label>
                <div class="input-container">
                    <input type="text" id="matricula" name="matricula" class="form-control" placeholder="Escribe tu matrícula" required autocomplete="off" autofocus>
                    <i class="fa-solid fa-id-card"></i>
                </div>
            </div>

            <div class="form-group">
                <label for="contrasena">CONTRASEÑA</label>
                <div class="input-container">
                    <input type="password" id="contrasena" name="contrasena" class="form-control" placeholder="Escribe tu contraseña" required>
                    <i class="fa-solid fa-lock"></i>
                </div>
            </div>

            <button type="submit" class="btn-primary" style="margin-top: 10px;">
                <span>Iniciar Sesión</span>
                <i class="fa-solid fa-arrow-right-to-bracket"></i>
            </button>
        </form>
    </div>
</body>
</html>