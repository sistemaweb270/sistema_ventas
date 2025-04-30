<?php
// Start session if it hasn't been started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if the user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username']) || !isset($_SESSION['role'])) {
    // If not logged in, redirect to login page
    header("Location: ../login.php");
    exit();
}

// Optional: Add more specific role checks in individual pages
// Example in admin/dashboard.php:
/*
if ($_SESSION['role'] !== 'Admin') {
    header("Location: ../index.php"); // Or an unauthorized page
    exit();
}
*/
?>