<?php
session_start();
require_once '../includes/config.php';
require_once '../includes/session_check.php'; // Check if logged in

// Check if user is salesperson or allowed access, redirect if not
// You might allow admins access too, depending on requirements
if (!isset($_SESSION['role']) || ($_SESSION['role'] !== 'Salesperson' && $_SESSION['role'] !== 'Admin')) {
     header("Location: ../index.php"); // Redirect to index or login
     exit();
}

// Fetch dashboard data for salesperson (PLACEHOLDER - requires actual SQL queries)
// Filter data by the logged-in user if necessary
$current_user_id = $_SESSION['user_id'];

$total_sales_today = 0; // Fetch from DB for this user
$total_sales_week = 0; // Fetch from DB for this user
$total_sales_month = 0; // Fetch from DB for this user
$salesperson_ranking = []; // Fetch from DB (could be overall or just show this user's rank)
$stock_available_count = 0; // Fetch from DB (could be store-specific)
$stock_agotado_count = 0; // Fetch from DB (could be store-specific)

// Usar $conn del archivo config.php
// Asegúrate de que $conn es válido antes de intentar usarlo
if ($conn && !$conn->connect_error) {
    // --- Fetch Dashboard Data (Filtered by User) ---
    // Total Sales Today for this user
    $sql_today = "SELECT SUM(total_amount) AS total FROM sales WHERE user_id = ? AND DATE(sale_date) = CURDATE() AND is_cancelled = FALSE";
    if ($stmt = $conn->prepare($sql_today)) {
         $stmt->bind_param("i", $current_user_id);
         $stmt->execute();
         $result_today = $stmt->get_result();
         if ($row = $result_today->fetch_assoc()) {
             $total_sales_today = $row['total'] ?? 0;
         }
         $stmt->close();
    }


    // Total Sales This Week for this user
    $sql_week = "SELECT SUM(total_amount) AS total FROM sales WHERE user_id = ? AND YEARWEEK(sale_date, 1) = YEARWEEK(CURDATE(), 1) AND is_cancelled = FALSE";
    if ($stmt = $conn->prepare($sql_week)) {
         $stmt->bind_param("i", $current_user_id);
         $stmt->execute();
         $result_week = $stmt->get_result();
         if ($row = $result_week->fetch_assoc()) {
             $total_sales_week = $row['total'] ?? 0;
         }
         $stmt->close();
    }


    // Total Sales This Month for this user
    $sql_month = "SELECT SUM(total_amount) AS total FROM sales WHERE user_id = ? AND YEAR(sale_date) = YEAR(CURDATE()) AND MONTH(sale_date) = MONTH(CURDATE()) AND is_cancelled = FALSE";
    if ($stmt = $conn->prepare($sql_month)) {
         $stmt->bind_param("i", $current_user_id);
         $stmt->execute();
         $result_month = $stmt->get_result();
         if ($row = $result_month->fetch_assoc()) {
             $total_sales_month = $row['total'] ?? 0;
         }
         $stmt->close();
    }


    // Stock Available Count (assuming stock > 0, potentially filtered by user's store_id if applicable)
    // Fetch store_id for the current user if needed to filter stock
    $user_store_id = null;
     $sql_user_store = "SELECT store_id FROM users WHERE id = ?";
     if ($stmt_store = $conn->prepare($sql_user_store)) {
        $stmt_store->bind_param("i", $current_user_id);
        $stmt_store->execute();
        $result_store = $stmt_store->get_result();
        if($row_store = $result_store->fetch_assoc()) {
            $user_store_id = $row_store['store_id'];
        }
        $stmt_store->close();
     }


    $sql_stock_available = "SELECT COUNT(*) AS count FROM products WHERE stock > 0";
    if ($user_store_id !== null) {
         $sql_stock_available .= " AND store_id = ?"; // Add store filter if applicable
    }
    if ($stmt_stock = $conn->prepare($sql_stock_available)) {
         if ($user_store_id !== null) {
             $stmt_stock->bind_param("i", $user_store_id);
         }
         $stmt_stock->execute();
         $result_stock_available = $stmt_stock->get_result();
         if ($row = $result_stock_available->fetch_assoc()) {
             $stock_available_count = $row['count'];
         }
         $stmt_stock->close();
    }


    // Stock Agotado (Out of Stock) Count (assuming stock <= 0, potentially filtered by user's store_id)
    $sql_stock_agotado = "SELECT COUNT(*) AS count FROM products WHERE stock <= 0";
    if ($user_store_id !== null) {
         $sql_stock_agotado .= " AND store_id = ?"; // Add store filter if applicable
    }
     if ($stmt_agotado = $conn->prepare($sql_stock_agotado)) {
         if ($user_store_id !== null) {
             $stmt_agotado->bind_param("i", $user_store_id);
         }
         $stmt_agotado->execute();
         $result_stock_agotado = $stmt_agotado->get_result();
         if ($row = $result_stock_agotado->fetch_assoc()) {
             $stock_agotado_count = $row['count'];
         }
         $stmt_agotado->close();
    }


    // Salesperson Ranking (Could show user's position or general ranking)
    // This query is the same as admin, just fetching the data. You'd then highlight the current user's row in the table.
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

    // No cerrar la conexión aquí si otros archivos incluidos la necesitan más tarde.
    // $conn->close();
} else {
    // Handle database connection error
}


?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel de Vendedor</title>
    <link rel="stylesheet" href="../vendor/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="../vendor/font-awesome/css/all.min.css"> <link rel="stylesheet" href="../css/style.css"> </head>
<body>
    <div class="container-fluid">
        <div class="row">
            <?php include 'includes/salesperson_sidebar.php'; ?> <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                 <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">Dashboard Vendedor</h1>
                     <div class="btn-toolbar mb-2 mb-md-0">
                        <span class="me-2"><i class="fas fa-calendar-alt"></i> <?php echo date('d/m/Y'); ?></span>
                        <span><i class="fas fa-clock"></i> <span id="current-time-salesperson"></span></span>
                    </div>
                </div>

                 <div class="row">
                    <div class="col-md-4 mb-3">
                        <div class="card text-white bg-primary mb-3">
                            <div class="card-header">Mis Ventas del Día</div>
                            <div class="card-body">
                                <h5 class="card-title">$ <?php echo number_format($total_sales_today, 2); ?></h5>
                            </div>
                        </div>
                    </div>
                     <div class="col-md-4 mb-3">
                        <div class="card text-white bg-success mb-3">
                            <div class="card-header">Mis Ventas Semanales</div>
                            <div class="card-body">
                                <h5 class="card-title">$ <?php echo number_format($total_sales_week, 2); ?></h5>
                            </div>
                        </div>
                    </div>
                     <div class="col-md-4 mb-3">
                        <div class="card text-white bg-info mb-3">
                            <div class="card-header">Mis Ventas Mensuales</div>
                            <div class="card-body">
                                <h5 class="card-title">$ <?php echo number_format($total_sales_month, 2); ?></h5>
                            </div>
                        </div>
                    </div>
                </div>

                 <div class="row">
                     <div class="col-md-6 mb-3">
                         <div class="card mb-3">
                            <div class="card-header">Resumen de Inventario</div>
                            <div class="card-body">
                                <ul class="list-group list-group-flush">
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
                    <div class="col-md-6 mb-3">
                         <div class="card mb-3">
                            <div class="card-header">Ranking de Vendedores (Este Mes)</div>
                            <div class="card-body">
                                 <ul class="list-group list-group-flush">
                                    <?php if (!empty($salesperson_ranking)): ?>
                                        <?php foreach ($salesperson_ranking as $rank): ?>
                                            <li class="list-group-item d-flex justify-content-between align-items-center <?php echo (isset($_SESSION['username']) && $rank['username'] === $_SESSION['username']) ? 'bg-warning' : ''; ?>">
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
                </div>

                </main>
        </div>
    </div>

    <script src="../vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
     <script src="../js/script.js"></script>
     <script>
        // Function to update current time
        function updateTimeSalesperson() {
            const now = new Date();
            const hours = String(now.getHours()).padStart(2, '0');
            const minutes = String(now.getMinutes()).padStart(2, '0');
            const seconds = String(now.getSeconds()).padStart(2, '0');
            document.getElementById('current-time-salesperson').textContent = `${hours}:${minutes}:${seconds}`;
        }
        // Update time every second
        setInterval(updateTimeSalesperson, 1000);
        // Initial call to display time immediately
        updateTimeSalesperson();
    </script>
</body>
</html>