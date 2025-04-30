<?php
// Database credentials
define('DB_SERVER', 'localhost:3308');
define('DB_USERNAME', 'root'); // Replace with your database username
define('DB_PASSWORD', 'root'); // Replace with your database password
define('DB_NAME', 'sistema_de_venta');

// Attempt to connect to MySQL database
$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);

// Check connection
if ($conn->connect_error) {
    die("ERROR: Could not connect. " . $conn->connect_error);
}

// Set charset to utf8mb4
$conn->set_charset("utf8mb4");

// You can add other global configuration variables here
?>