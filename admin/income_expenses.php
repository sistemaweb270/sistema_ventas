<?php
session_start();
require_once '../includes/config.php';
require_once '../includes/session_check.php'; // Check if logged in and is Admin

// Check if user is admin, redirect if not
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Admin') {
    header("Location: ../index.php"); // Redirect to index or login page
    exit();
}

$message = '';
$message_type = ''; // 'success', 'danger', 'warning', 'info'

// Default report criteria
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';
$transaction_type = $_GET['transaction_type'] ?? ''; // Income, Expense
$user_id = $_GET['user_id'] ?? ''; // User who recorded manual transaction
$store_id = $_GET['store_id'] ?? ''; // Store associated with manual transaction

$transactions_results = [];
$total_manual_income = 0; // Sum of manual 'Income' entries
$total_manual_expense = 0; // Sum of manual 'Expense' entries
$total_sales_income = 0; // Sum of non-cancelled sales
$net_income = 0; // Total Income (Sales + Manual Income) - Total Expenses

$report_generated = false;

// --- Fetch Data for Report Filters ---

// Fetch Users (Admins & Salespeople) for dropdown (who recorded transactions)
$users_list = [];
$sql_users = "SELECT id, username FROM users ORDER BY username ASC";
if ($result_users = $conn->query($sql_users)) {
    while ($row_users = $result_users->fetch_assoc()) {
        $users_list[] = $row_users;
    }
    $result_users->free();
}

// Fetch Stores for dropdown
$stores_list = [];
$sql_stores = "SELECT id, name FROM stores ORDER BY name ASC";
if ($result_stores = $conn->query($sql_stores)) {
    while ($row_stores = $result_stores->fetch_assoc()) {
        $stores_list[] = $row_stores;
    }
    $result_stores->free();
}


// --- Handle Report Generation (GET) ---
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['generate_report'])) {
    $report_generated = true;

    // Basic validation for date range
    if (!empty($start_date) && !empty($end_date) && strtotime($start_date) > strtotime($end_date)) {
        $message = 'La fecha de inicio no puede ser posterior a la fecha de fin.';
        $message_type = 'warning';
        $report_generated = false; // Prevent report generation if date range is invalid
    }

    if ($report_generated) {
        // --- Fetch Manual Income/Expense Transactions ---
        $sql_ie = "SELECT ie.id, ie.type, ie.amount, ie.description, ie.transaction_date,
                         u.username AS user_name,
                         s.name AS store_name
                  FROM income_expenses ie
                  LEFT JOIN users u ON ie.user_id = u.id
                  LEFT JOIN stores s ON ie.store_id = s.id
                  WHERE 1"; // Start with a true condition

        $params_ie = [];
        $types_ie = '';

        // Filter by date range
        if (!empty($start_date)) {
            $sql_ie .= " AND DATE(ie.transaction_date) >= ?";
            $params_ie[] = $start_date;
            $types_ie .= 's';
        }
        if (!empty($end_date)) {
             $sql_ie .= " AND DATE(ie.transaction_date) <= ?";
             $params_ie[] = $end_date;
             $types_ie .= 's';
        }

        // Filter by Type
        if (!empty($transaction_type) && ($transaction_type === 'Income' || $transaction_type === 'Expense')) {
            $sql_ie .= " AND ie.type = ?";
            $params_ie[] = $transaction_type;
            $types_ie .= 's';
        }

        // Filter by User
        if (!empty($user_id) && filter_var($user_id, FILTER_VALIDATE_INT)) {
            $sql_ie .= " AND ie.user_id = ?";
            $params_ie[] = (int)$user_id;
            $types_ie .= 'i';
        }

         // Filter by Store
        if (!empty($store_id) && filter_var($store_id, FILTER_VALIDATE_INT)) {
            $sql_ie .= " AND ie.store_id = ?";
            $params_ie[] = (int)$store_id;
            $types_ie .= 'i';
        }

        $sql_ie .= " ORDER BY ie.transaction_date DESC";

         if ($stmt_ie = $conn->prepare($sql_ie)) {
             if (!empty($params_ie)) {
                  $bind_params_ie = [];
                  $bind_params_ie[] = &$types_ie;
                  for ($i = 0; $i < count($params_ie); $i++) {
                      $bind_params_ie[] = &$params_ie[$i];
                  }
                  call_user_func_array([$stmt_ie, 'bind_param'], $bind_params_ie);
             }

             $stmt_ie->execute();
             $result_ie = $stmt_ie->get_result();
             while ($row_ie = $result_ie->fetch_assoc()) {
                 $transactions_results[] = $row_ie;
                 if ($row_ie['type'] === 'Income') {
                     $total_manual_income += $row_ie['amount'];
                 } else { // Expense
                      $total_manual_expense += $row_ie['amount'];
                 }
             }
             $result_ie->free();
             $stmt_ie->close();

         } else {
             $message = 'Error de base de datos al preparar la consulta de transacciones manuales: ' . $conn->error;
             $message_type = 'danger';
         }

         // --- Fetch Total Sales Income for the period (Non-cancelled) ---
         $sql_sales_total = "SELECT SUM(total_amount) AS total FROM sales WHERE is_cancelled = FALSE";

         $params_sales = [];
         $types_sales = '';

         if (!empty($start_date)) {
             $sql_sales_total .= " AND DATE(sale_date) >= ?";
             $params_sales[] = $start_date;
             $types_sales .= 's';
         }
         if (!empty($end_date)) {
              $sql_sales_total .= " AND DATE(sale_date) <= ?";
              $params_sales[] = $end_date;
              $types_sales .= 's';
         }
         // Note: Sales total might also need filtering by store if needed for the report summary


         if ($stmt_sales = $conn->prepare($sql_sales_total)) {
             if (!empty($params_sales)) {
                 $bind_params_sales = [];
                 $bind_params_sales[] = &$types_sales;
                 for ($i = 0; $i < count($params_sales); $i++) {
                     $bind_params_sales[] = &$params_sales[$i];
                 }
                 call_user_func_array([$stmt_sales, 'bind_param'], $bind_params_sales);
             }

             $stmt_sales->execute();
             $result_sales = $stmt_sales->get_result();
             if ($row_sales = $result_sales->fetch_assoc()) {
                 $total_sales_income = $row_sales['total'] ?? 0;
             }
             $result_sales->free();
             $stmt_sales->close();

         } else {
              $message = 'Error de base de datos al calcular el total de ventas: ' . $conn->error;
             $message_type = 'danger';
         }

        // Calculate Net Income
        $net_income = ($total_sales_income + $total_manual_income) - $total_manual_expense;

         if (count($transactions_results) == 0 && $total_sales_income == 0) {
            $message = 'No se encontraron transacciones (ventas, ingresos, egresos) para los criterios seleccionados.';
            $message_type = 'info';
         }
    }
}


// Close DB connection (optional)
// $conn->close();

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ingresos / Egresos - Panel de Administrador</title>
    <!-- Bootstrap CSS -->
    <link rel="stylesheet" href="../vendor/bootstrap/css/bootstrap.min.css">
    <!-- Font Awesome CSS -->
    <link rel="stylesheet" href="../vendor/font-awesome/css/all.min.css">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="../css/style.css">
    <!-- Optional: Datepicker CSS if needed -->
</head>
<body>
    <div class="container-fluid">
        <div class="row">

            <!-- Sidebar -->
            <?php include 'includes/admin_sidebar.php'; ?>

            <!-- Main content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">Reporte de Ingresos y Egresos</h1>
                </div>

                 <?php if ($message): ?>
                    <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
                        <?php echo $message; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <!-- Report Filter Form -->
                <div class="card mb-4">
                    <div class="card-header">Filtros del Reporte Financiero</div>
                    <div class="card-body">
                        <form action="income_expenses.php" method="GET">
                             <input type="hidden" name="generate_report" value="1">
                            <div class="row g-3">
                                <div class="col-md-3">
                                    <label for="start_date" class="form-label">Fecha Desde</label>
                                    <input type="date" class="form-control" id="start_date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>">
                                </div>
                                <div class="col-md-3">
                                    <label for="end_date" class="form-label">Fecha Hasta</label>
                                    <input type="date" class="form-control" id="end_date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>">
                                </div>
                                <div class="col-md-3">
                                     <label for="transaction_type" class="form-label">Tipo</label>
                                     <select class="form-select" id="transaction_type" name="transaction_type">
                                         <option value="">-- Todos --</option>
                                         <option value="Income" <?php echo ($transaction_type === 'Income') ? 'selected' : ''; ?>>Ingresos Manuales</option>
                                         <option value="Expense" <?php echo ($transaction_type === 'Expense') ? 'selected' : ''; ?>>Egresos Manuales</option>
                                     </select>
                                </div>
                                <div class="col-md-3">
                                    <label for="user_id" class="form-label">Registrado Por</label>
                                    <select class="form-select" id="user_id" name="user_id">
                                        <option value="">-- Todos --</option>
                                        <?php foreach ($users_list as $user): ?>
                                            <option value="<?php echo $user['id']; ?>" <?php echo ($user_id == $user['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($user['username']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                 <div class="col-md-3">
                                    <label for="store_id" class="form-label">Tienda Asociada (Manual)</label>
                                    <select class="form-select" id="store_id" name="store_id">
                                         <option value="">-- Todas --</option>
                                         <?php foreach ($stores_list as $store): ?>
                                            <option value="<?php echo $store['id']; ?>" <?php echo ($store_id == $store['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($store['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-auto align-self-end">
                                    <button type="submit" class="btn btn-primary btn-animated"><i class="fas fa-chart-line"></i> Generar Reporte</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Report Results -->
                 <?php if ($report_generated && empty($message)): ?>
                     <div class="card">
                          <div class="card-header">Detalle Financiero del Periodo</div>
                          <div class="card-body">

                               <div class="row mb-4">
                                    <div class="col-md-4">
                                        <div class="card bg-light">
                                            <div class="card-body text-success">
                                                <h5 class="card-title"><i class="fas fa-dollar-sign"></i> Total Ventas</h5>
                                                <p class="card-text h3"><?php echo number_format($total_sales_income, 2); ?></p>
                                            </div>
                                        </div>
                                    </div>
                                     <div class="col-md-4">
                                        <div class="card bg-light">
                                            <div class="card-body text-primary">
                                                <h5 class="card-title"><i class="fas fa-plus"></i> Otros Ingresos Manuales</h5>
                                                <p class="card-text h3"><?php echo number_format($total_manual_income, 2); ?></p>
                                            </div>
                                        </div>
                                    </div>
                                     <div class="col-md-4">
                                        <div class="card bg-light">
                                            <div class="card-body text-danger">
                                                <h5 class="card-title"><i class="fas fa-minus"></i> Egresos Manuales</h5>
                                                <p class="card-text h3"><?php echo number_format($total_manual_expense, 2); ?></p>
                                            </div>
                                        </div>
                                    </div>
                               </div>
                                <div class="row mb-4">
                                     <div class="col-md-12">
                                        <div class="card <?php echo ($net_income >= 0) ? 'bg-success text-white' : 'bg-danger text-white'; ?>">
                                            <div class="card-body">
                                                <h5 class="card-title"><i class="fas fa-calculator"></i> Ingreso Neto Estimado</h5>
                                                <p class="card-text h2"><?php echo number_format($net_income, 2); ?></p>
                                            </div>
                                        </div>
                                    </div>
                                </div>


                                <h5>Detalle de Transacciones Manuales (Ingresos/Egresos)</h5>
                               <?php if (count($transactions_results) > 0): ?>
                                    <div class="table-responsive">
                                        <table class="table table-striped table-sm">
                                            <thead>
                                                <tr>
                                                    <th>ID</th>
                                                    <th>Tipo</th>
                                                    <th>Monto</th>
                                                    <th>Descripción</th>
                                                    <th>Fecha Transacción</th>
                                                    <th>Registrado Por</th>
                                                    <th>Tienda Asociada</th>
                                                    <!-- Add Till Session ID column if needed -->
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($transactions_results as $transaction): ?>
                                                    <tr class="<?php echo ($transaction['type'] === 'Expense') ? 'table-danger' : 'table-success'; ?>">
                                                        <td><?php echo htmlspecialchars($transaction['id']); ?></td>
                                                        <td>
                                                             <?php if ($transaction['type'] === 'Income'): ?>
                                                                <span class="badge bg-primary"><i class="fas fa-plus"></i> Ingreso</span>
                                                            <?php else: ?>
                                                                 <span class="badge bg-danger"><i class="fas fa-minus"></i> Egreso</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>$ <?php echo number_format($transaction['amount'], 2); ?></td>
                                                        <td><?php echo htmlspecialchars($transaction['description'] ?? 'Sin descripción'); ?></td>
                                                        <td><?php echo htmlspecialchars($transaction['transaction_date']); ?></td>
                                                         <td><?php echo htmlspecialchars($transaction['user_name'] ?? 'Desconocido'); ?></td>
                                                         <td><?php echo htmlspecialchars($transaction['store_name'] ?? 'N/A'); ?></td>
                                                        <!-- Add Till Session ID data -->
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php else: ?>
                                    <p class="text-center">No se encontraron transacciones manuales (Ingresos/Egresos) para los criterios seleccionados.</p>
                                <?php endif; ?>
                           </div>
                     </div>
                 <?php elseif ($report_generated && !empty($message)): ?>
                      <!-- Message already displayed -->
                 <?php else: ?>
                      <div class="card">
                           <div class="card-body">
                                <p class="text-center">Seleccione los filtros y haga clic en "Generar Reporte" para ver el resumen financiero.</p>
                           </div>
                      </div>
                 <?php endif; ?>


            </main>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="../vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
     <!-- Custom JS -->
    <script src="../js/script.js"></script>
    <script>
         // Script para ocultar mensajes de alerta automáticamente (con JS de Bootstrap)
         const alerts = document.querySelectorAll('.alert');
         alerts.forEach(alert => {
             if (alert.classList.contains('alert-dismissible')) {
                 const bsAlert = new bootstrap.Alert(alert);
                 setTimeout(() => {
                     bsAlert.close();
                 }, 5000); // Ocultar después de 5 segundos
             }
         });
    </script>
</body>
</html>