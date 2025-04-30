<?php
session_start();
// Include database connection
require_once 'includes/config.php';

$error_message = '';

// Redirigir si ya está logueado
if (isset($_SESSION['user_id']) && isset($_SESSION['role'])) {
    if ($_SESSION['role'] === 'Admin') {
        header("Location: admin/dashboard.php");
    } elseif ($_SESSION['role'] === 'Salesperson') {
        header("Location: salesperson/dashboard.php");
    } else {
         // Si el rol es desconocido pero hay sesión, redirigir al login con mensaje o destruir sesión
         // Para seguridad, mejor destruir la sesión y redirigir al login.
         session_unset();
         session_destroy();
         header("Location: login.php?message=" . urlencode('Rol de usuario desconocido.'));
    }
    exit();
}

// Handle login POST request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get username and password from form, apply trim()
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? ''); // <-- Added trim() here

    // Basic validation
    if (empty($username) || empty($password)) {
        $error_message = 'Por favor, ingrese usuario y contraseña.';
    } else {
        // Prepare and execute SQL query to fetch user by username and get their role
        // Select password hash ($user['password']) for verification
        $sql = "SELECT u.id, u.username, u.password, r.name as role_name FROM users u JOIN roles r ON u.role_id = r.id WHERE u.username = ?";
        if ($stmt = $conn->prepare($sql)) {
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();

            // Check if a user was found
            if ($result->num_rows === 1) {
                $user = $result->fetch_assoc(); // Get user data including the hashed password from the DB

                // --- SECURE PASSWORD VERIFICATION USING password_verify() ---
                // This checks the plain text password from the form against the hash from the database
                if (password_verify($password, $user['password'])) {

                    // Password is correct. Start a new session and store user info.
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['role'] = $user['role_name']; // Store the role name

                    // Redirect based on the user's role
                    if ($user['role_name'] === 'Admin') {
                        header("Location: admin/dashboard.php");
                    } elseif ($user['role_name'] === 'Salesperson') { // Redirect Salespersons
                        header("Location: salesperson/dashboard.php");
                    } else {
                        // Handle unexpected roles - Log out and show error message
                        $error_message = 'Rol de usuario desconocido.';
                         session_unset();
                         session_destroy();
                         header("Location: login.php?message=" . urlencode($error_message));
                         exit(); // Important to exit after redirect
                    }
                    exit(); // Important to exit after any successful redirect

                } else {
                    // Password does NOT match the hash
                    $error_message = 'Usuario o contraseña incorrectos.'; // Use a generic message for security
                }
            } else {
                // No user found with that username
                $error_message = 'Usuario o contraseña incorrectos.'; // Use a generic message for security
            }
            $result->free(); // Free result set
            $stmt->close(); // Close statement
        } else {
             // Error preparing the SQL statement
             $error_message = 'Error en la base de datos al procesar la solicitud.'; // Generic database error message
             // You might want to log the actual database error ($conn->error) for debugging
        }
    }
    // $conn->close(); // Optional: Close DB connection here if not needed elsewhere
}

// Handle messages passed via GET (e.g., from failed role check or logout)
if (isset($_GET['message'])) {
    $error_message = htmlspecialchars($_GET['message']); // Display message from URL
    // You might add logic here to differentiate message types if needed
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar Sesión - Sistema de Ventas</title>
    <link rel="stylesheet" href="vendor/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="vendor/font-awesome/css/all.min.css">
    <link rel="stylesheet" href="css/login_animation.css">
    <link rel="stylesheet" href="css/style.css"> </head>
<body class="login-body">
    <div class="container">
        <div class="row justify-content-center align-items-center vh-100">
            <div class="col-md-6 col-lg-4">
                <div class="card login-card animated-card">
                    <div class="card-body">
                        <h3 class="card-title text-center mb-4">Iniciar Sesión</h3>

                        <?php if ($error_message): ?>
                            <div class="alert alert-danger" role="alert">
                                <?php echo htmlspecialchars($error_message); // Display error message ?>
                            </div>
                        <?php endif; ?>

                        <form action="login.php" method="POST">
                            <div class="mb-3">
                                <label for="username" class="form-label">Usuario</label>
                                <input type="text" class="form-control" id="username" name="username" required value="<?php echo htmlspecialchars($username ?? ''); ?>"> </div>
                            <div class="mb-3">
                                <label for="password" class="form-label">Contraseña</label>
                                <input type="password" class="form-control" id="password" name="password" required>
                            </div>
                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-primary btn-animated">Iniciar Sesión</button>
                            </div>
                        </form>
                        <hr>
                        <div class="text-center">
                            <a href="#" class="d-block mt-2 text-muted">Contactar con el Soporte</a>
                            <a href="#" class="d-block mt-1 text-muted">Reportar Error</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script src="js/script.js"></script>
     <script>
         // Optional: Script to hide alert messages automatically
         // This uses Bootstrap's JS component for alerts
         document.addEventListener('DOMContentLoaded', function() {
             const alerts = document.querySelectorAll('.alert');
             alerts.forEach(alert => {
                 if (alert.classList.contains('alert-dismissible')) {
                     const bsAlert = new bootstrap.Alert(alert);
                     setTimeout(() => {
                         bsAlert.close();
                     }, 5000); // Hide after 5 seconds
                 }
             });
         });
     </script>
</body>
</html>