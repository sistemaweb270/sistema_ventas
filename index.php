<?php
session_start();

// Incluir el archivo de configuración (opcional aquí, pero buena práctica)
// require_once 'includes/config.php';

// Verificar si la sesión del usuario existe
if (isset($_SESSION['user_id']) && isset($_SESSION['role'])) {
    // Si está logueado, redirigir según su rol
    if ($_SESSION['role'] === 'Admin') {
        header("Location: admin/dashboard.php");
        exit(); // Importante salir después de redirigir
    } elseif ($_SESSION['role'] === 'Salesperson') {
        header("Location: salesperson/dashboard.php");
        exit(); // Importante salir después de redirigir
    } else {
        // Si tiene un rol desconocido pero está logueado,
        // podrías redirigirlo a una página de error o al login
        header("Location: login.php");
        exit();
    }
} else {
    // Si no está logueado, redirigir a la página de login
    header("Location: login.php");
    exit(); // Importante salir después de redirigir
}
?>