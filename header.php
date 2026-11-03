<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Verificar si el usuario está autenticado
if (!isset($_SESSION['usuario'])) {
    header("Location: login.php");
    exit;
}

$user = $_SESSION['usuario'];
$current_page = basename($_SERVER['PHP_SELF']);
$rolVistaMenu = $_GET['rol'] ?? 'alumno';   // usuarios.php?rol=alumno|docente|administrativo
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistema de Asistencia ESP32</title>
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Outfit:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <!-- FontAwesome para Iconos -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- CSS del Dashboard -->
    <link rel="stylesheet" href="public/dashboard.css">
</head>
<body>
    <!-- Sidebar de Navegación -->
    <aside class="sidebar">
        <div class="sidebar-brand">
            <div class="brand-icon">
                <i class="fa-solid fa-fingerprint"></i>
            </div>
            <div class="brand-text">
                <div class="brand-name">Asistencia</div>
                <div class="brand-sub">UPV · Control IoT</div>
            </div>
        </div>

        <ul class="sidebar-menu">
            <?php if ($user['rol'] === 'administrativo'): ?>
                <li class="menu-item <?php echo ($current_page === 'usuarios.php' && $rolVistaMenu === 'alumno') ? 'active' : ''; ?>">
                    <a href="usuarios.php?rol=alumno">
                        <i class="fa-solid fa-user-graduate"></i>
                        <span>Estudiantes</span>
                    </a>
                </li>
                <li class="menu-item <?php echo ($current_page === 'usuarios.php' && $rolVistaMenu !== 'alumno') ? 'active' : ''; ?>">
                    <a href="usuarios.php?rol=docente">
                        <i class="fa-solid fa-chalkboard-user"></i>
                        <span>Profesores</span>
                    </a>
                </li>
                <li class="menu-item <?php echo $current_page === 'clases.php' ? 'active' : ''; ?>">
                    <a href="clases.php">
                        <i class="fa-solid fa-book-open"></i>
                        <span>Materias</span>
                    </a>
                </li>
                <li class="menu-item <?php echo $current_page === 'inscripciones.php' ? 'active' : ''; ?>">
                    <a href="inscripciones.php">
                        <i class="fa-solid fa-user-plus"></i>
                        <span>Inscripciones</span>
                    </a>
                </li>
                <li class="menu-item <?php echo $current_page === 'pase_lista.php' ? 'active' : ''; ?>">
                    <a href="pase_lista.php">
                        <i class="fa-solid fa-clipboard-user"></i>
                        <span>Pase de Lista</span>
                    </a>
                </li>
                <li class="menu-item <?php echo $current_page === 'historial.php' ? 'active' : ''; ?>">
                    <a href="historial.php">
                        <i class="fa-solid fa-list-check"></i>
                        <span>Historial</span>
                    </a>
                </li>
                <li class="menu-item <?php echo $current_page === 'dispositivos.php' ? 'active' : ''; ?>">
                    <a href="dispositivos.php">
                        <i class="fa-solid fa-microchip"></i>
                        <span>Cajas ESP32</span>
                    </a>
                </li>
            <?php elseif ($user['rol'] === 'docente'): ?>
                <li class="menu-item <?php echo $current_page === 'pase_lista.php' ? 'active' : ''; ?>">
                    <a href="pase_lista.php">
                        <i class="fa-solid fa-clipboard-user"></i>
                        <span>Pase de Lista</span>
                    </a>
                </li>
                <li class="menu-item <?php echo $current_page === 'mis_alumnos.php' ? 'active' : ''; ?>">
                    <a href="mis_alumnos.php">
                        <i class="fa-solid fa-user-graduate"></i>
                        <span>Mis Alumnos</span>
                    </a>
                </li>
                <li class="menu-item <?php echo $current_page === 'historial.php' ? 'active' : ''; ?>">
                    <a href="historial.php">
                        <i class="fa-solid fa-list-check"></i>
                        <span>Historial y Notas</span>
                    </a>
                </li>
                <li class="menu-item <?php echo $current_page === 'mi_asistencia.php' ? 'active' : ''; ?>">
                    <a href="mi_asistencia.php">
                        <i class="fa-solid fa-user-clock"></i>
                        <span>Mi Asistencia</span>
                    </a>
                </li>
            <?php else: ?>
                <li class="menu-item <?php echo $current_page === 'alumno.php' ? 'active' : ''; ?>">
                    <a href="alumno.php">
                        <i class="fa-solid fa-house"></i>
                        <span>Inicio</span>
                    </a>
                </li>
                <li class="menu-item <?php echo $current_page === 'mis_clases.php' ? 'active' : ''; ?>">
                    <a href="mis_clases.php">
                        <i class="fa-solid fa-graduation-cap"></i>
                        <span>Mis Clases</span>
                    </a>
                </li>
                <li class="menu-item <?php echo $current_page === 'historial.php' ? 'active' : ''; ?>">
                    <a href="historial.php">
                        <i class="fa-solid fa-list-check"></i>
                        <span>Historial</span>
                    </a>
                </li>
            <?php endif; ?>
        </ul>

        <div class="sidebar-footer">
            <div class="sidebar-profile">
                <div class="profile-avatar">
                    <?php echo strtoupper(substr($user['nombre'], 0, 1)); ?>
                </div>
                <div class="profile-info">
                    <div class="profile-name" title="<?php echo htmlspecialchars($user['nombre']); ?>">
                        <?php echo htmlspecialchars($user['nombre']); ?>
                    </div>
                    <div class="profile-role">
                        <?php echo $user['rol'] === 'administrativo' ? 'Administrador' : ($user['rol'] === 'docente' ? 'Docente' : 'Alumno'); ?>
                    </div>
                </div>
            </div>
            <a href="logout.php" class="btn-logout">
                <i class="fa-solid fa-right-from-bracket"></i>
                <span>Cerrar sesión</span>
            </a>
        </div>
    </aside>

    <!-- Wrapper para Contenido Principal -->
    <div class="main-wrapper">
