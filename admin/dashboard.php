<?php
session_start();
require_once '../includes/config.php';
require_once '../includes/session_check.php'; // Check if logged in and is Admin

// Check if user is admin, redirect if not
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Admin') {
    header("Location: ../index.php"); // Redirect to index or login page
    exit();
}

// Fetch dashboard data (PLACEHOLDER - requires actual SQL queries)
// --- Fetch Dashboard Data ---
$total_sales_today = 0;
$total_sales_week = 0;
$total_sales_month = 0;
$salesperson_ranking = [];
$registered_users_count = 0;
$stock_available_count = 0;
$stock_agotado_count = 0;

// Usar $conn del archivo config.php
// Asegúrate de que $conn es válido antes de intentar usarlo
if ($conn && !$conn->connect_error) {
    // Total Sales Today
    $sql_today = "SELECT SUM(total_amount) AS total FROM sales WHERE DATE(sale_date) = CURDATE() AND is_cancelled = FALSE";
    $result_today = $conn->query($sql_today);
    if ($result_today && $row = $result_today->fetch_assoc()) {
        $total_sales_today = $row['total'] ?? 0;
    }

    // Total Sales This Week (Example: Monday to Sunday)
    $sql_week = "SELECT SUM(total_amount) AS total FROM sales WHERE YEARWEEK(sale_date, 1) = YEARWEEK(CURDATE(), 1) AND is_cancelled = FALSE";
    $result_week = $conn->query($sql_week);
    if ($result_week && $row = $result_week->fetch_assoc()) {
        $total_sales_week = $row['total'] ?? 0;
    }

    // Total Sales This Month
    $sql_month = "SELECT SUM(total_amount) AS total FROM sales WHERE YEAR(sale_date) = YEAR(CURDATE()) AND MONTH(sale_date) = MONTH(CURDATE()) AND is_cancelled = FALSE";
    $result_month = $conn->query($sql_month);
    if ($result_month && $row = $result_month->fetch_assoc()) {
        $total_sales_month = $row['total'] ?? 0;
    }

    // Salesperson Ranking (Example: Top 5 by sales amount this month)
    $sql_ranking = "SELECT u.username, SUM(s.total_amount) AS total_sales
                    FROM sales s
                    JOIN users u ON s.user_id = u.id
                    WHERE YEAR(s.sale_date) = YEAR(CURDATE()) AND MONTH(s.sale_date) = MONTH(CURDATE()) AND s.is_cancelled = FALSE
                    GROUP BY u.username
                    ORDER BY total_sales DESC
                    LIMIT 5";
    $result_ranking = $conn->query($sql_ranking);
    $salesperson_ranking = [];
    if ($result_ranking) {
        while ($row = $result_ranking->fetch_assoc()) {
            $salesperson_ranking[] = $row;
        }
    }

    // Registered Users Count
    $sql_users = "SELECT COUNT(*) AS count FROM users";
    $result_users = $conn->query($sql_users);
    if ($result_users && $row = $result_users->fetch_assoc()) {
        $registered_users_count = $row['count'];
    }

    // Stock Available Count (assuming stock > 0)
    $sql_stock_available = "SELECT COUNT(*) AS count FROM products WHERE stock > 0";
    $result_stock_available = $conn->query($sql_stock_available);
    if ($result_stock_available && $row = $result_stock_available->fetch_assoc()) {
        $stock_available_count = $row['count'];
    }

    // Stock Agotado (Out of Stock) Count (assuming stock <= 0)
    $sql_stock_agotado = "SELECT COUNT(*) AS count FROM products WHERE stock <= 0";
    $result_stock_agotado = $conn->query($sql_stock_agotado);
    if ($result_stock_agotado && $row = $result_stock_agotado->fetch_assoc()) {
        $stock_agotado_count = $row['count'];
    }

    // No cerrar la conexión aquí si otros archivos incluidos la necesitan más tarde.
    // $conn->close();
} else {
    // Handle database connection error - message is already shown by config.php die()
    // Or display a message in the dashboard body if using a softer error handling
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel de Administrador</title>
    <link rel="stylesheet" href="../vendor/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="../vendor/font-awesome/css/all.min.css"> <link rel="stylesheet" href="../css/style.css"> </head>
<body>
    <div class="container-fluid">
        <div class="row">

            <?php include 'includes/admin_sidebar.php'; ?> <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">Dashboard Administrador</h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <span class="me-2"><i class="fas fa-calendar-alt"></i> <?php echo date('d/m/Y'); ?></span>
                        <span><i class="fas fa-clock"></i> <span id="current-time"></span></span>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-4 mb-3">
                        <div class="card text-white bg-primary mb-3">
                            <div class="card-header">Ventas del Día</div>
                            <div class="card-body">
                                <h5 class="card-title">$ <?php echo number_format($total_sales_today, 2); ?></h5>
                            </div>
                        </div>
                    </div>
                     <div class="col-md-4 mb-3">
                        <div class="card text-white bg-success mb-3">
                            <div class="card-header">Ventas Semanales</div>
                            <div class="card-body">
                                <h5 class="card-title">$ <?php echo number_format($total_sales_week, 2); ?></h5>
                            </div>
                        </div>
                    </div>
                     <div class="col-md-4 mb-3">
                        <div class="card text-white bg-info mb-3">
                            <div class="card-header">Ventas Mensuales</div>
                            <div class="card-body">
                                <h5 class="card-title">$ <?php echo number_format($total_sales_month, 2); ?></h5>
                            </div>
                        </div>
                    </div>
                </div>

                 <div class="row">
                    <div class="col-md-6 mb-3">
                        <div class="card mb-3">
                            <div class="card-header">Ranking de Vendedores (Este Mes)</div>
                            <div class="card-body">
                                <ul class="list-group list-group-flush">
                                    <?php if (!empty($salesperson_ranking)): ?>
                                        <?php foreach ($salesperson_ranking as $rank): ?>
                                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                                <?php echo htmlspecialchars($rank['username']); ?>
                                                <span class="badge bg-primary rounded-pill">$<?php echo number_format($rank['total_sales'], 2); ?></span>
                                            </li>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <li class="list-group-item">No hay datos de ventas este mes.</li>
                                    <?php endif; ?>
                                </ul>
                            </div>
                        </div>
                    </div>
                     <div class="col-md-6 mb-3">
                         <div class="card mb-3">
                            <div class="card-header">Resumen General</div>
                            <div class="card-body">
                                <ul class="list-group list-group-flush">
                                    <li class="list-group-item d-flex justify-content-between align-items-center">
                                        Usuarios Registrados
                                        <span class="badge bg-secondary rounded-pill"><?php echo $registered_users_count; ?></span>
                                    </li>
                                     <li class="list-group-item d-flex justify-content-between align-items-center">
                                        Stock Disponible
                                        <span class="badge bg-success rounded-pill"><?php echo $stock_available_count; ?></span>
                                    </li>
                                     <li class="list-group-item d-flex justify-content-between align-items-center">
                                        Stock Agotado
                                        <span class="badge bg-danger rounded-pill"><?php echo $stock_agotado_count; ?></span>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>


                </main>
        </div>
    </div>

    <script src="../vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
     <script src="../js/script.js"></script>
    <script>
        // Function to update current time
        function updateTime() {
            const now = new Date();
            const hours = String(now.getHours()).padStart(2, '0');
            const minutes = String(now.getMinutes()).padStart(2, '0');
            const seconds = String(now.getSeconds()).padStart(2, '0');
            document.getElementById('current-time').textContent = `${hours}:${minutes}:${seconds}`;
        }
        // Update time every second
        setInterval(updateTime, 1000);
        // Initial call to display time immediately
        updateTime();
    </script>
</body>
</html>