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
$salesperson_id = $_GET['salesperson_id'] ?? '';
$payment_method_id = $_GET['payment_method_id'] ?? '';
$include_cancelled = isset($_GET['include_cancelled']); // Check if checkbox is ticked

$report_results = [];
$total_report_amount = 0;
$report_generated = false; // Flag to indicate if a report has been generated

// --- Fetch Data for Report Filters ---

// Fetch Salespeople for dropdown
$salespeople = [];
$sql_salespeople = "SELECT id, username FROM users WHERE role_id = (SELECT id FROM roles WHERE name = 'Salesperson') ORDER BY username ASC";
if ($result_salespeople = $conn->query($sql_salespeople)) {
    while ($row_salespeople = $result_salespeople->fetch_assoc()) {
        $salespeople[] = $row_salespeople;
    }
    $result_salespeople->free();
} // Consider error handling if query fails

// Fetch Payment Methods for dropdown
$payment_methods = [];
$sql_payment_methods = "SELECT id, name FROM payment_methods ORDER BY name ASC";
if ($result_payment_methods = $conn->query($sql_payment_methods)) {
    while ($row_payment_methods = $result_payment_methods->fetch_assoc()) {
        $payment_methods[] = $row_payment_methods;
    }
    $result_payment_methods->free();
} // Consider error handling if query fails


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
        $sql = "SELECT s.id, s.receipt_number, s.sale_date, s.total_amount, s.is_cancelled,
                        u.username AS salesperson_name,
                        c.name AS customer_name,
                        pm.name AS payment_method_name,
                        ucancel.username AS cancelled_by_name, s.cancelled_at
                FROM sales s
                LEFT JOIN users u ON s.user_id = u.id
                LEFT JOIN customers c ON s.customer_id = c.id
                LEFT JOIN payment_methods pm ON s.payment_method_id = pm.id
                LEFT JOIN users ucancel ON s.cancelled_by_user_id = ucancel.id
                WHERE 1"; // Start with a true condition

        $params = [];
        $types = '';

        // Filter by date range
        if (!empty($start_date)) {
            $sql .= " AND DATE(s.sale_date) >= ?";
            $params[] = $start_date; // Assuming date format YYYY-MM-DD from input type="date"
            $types .= 's';
        }
        if (!empty($end_date)) {
             $sql .= " AND DATE(s.sale_date) <= ?";
             $params[] = $end_date; // Assuming date format YYYY-MM-DD from input type="date"
             $types .= 's';
        }

        // Filter by Salesperson
        if (!empty($salesperson_id) && filter_var($salesperson_id, FILTER_VALIDATE_INT)) {
            $sql .= " AND s.user_id = ?";
            $params[] = (int)$salesperson_id;
            $types .= 'i';
        }

        // Filter by Payment Method
         if (!empty($payment_method_id) && filter_var($payment_method_id, FILTER_VALIDATE_INT)) {
            $sql .= " AND s.payment_method_id = ?";
            $params[] = (int)$payment_method_id;
            $types .= 'i';
        }

        // Filter by cancelled status
        if (!$include_cancelled) {
            $sql .= " AND s.is_cancelled = FALSE";
        }


        $sql .= " ORDER BY s.sale_date DESC";

        if ($stmt = $conn->prepare($sql)) {
            if (!empty($params)) {
                 // Dynamically bind parameters
                 $bind_params = [];
                 $bind_params[] = &$types; // First parameter is the types string
                 for ($i = 0; $i < count($params); $i++) {
                     $bind_params[] = &$params[$i];
                 }
                 call_user_func_array([$stmt, 'bind_param'], $bind_params);
            }

            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $report_results[] = $row;
                if (!$row['is_cancelled']) { // Sum only non-cancelled sales
                     $total_report_amount += $row['total_amount'];
                } else {
                     // Decide if cancelled sales amount should be included in a separate total or not
                     // For now, only sum non-cancelled for the main total.
                }
            }
            $result->free();
            $stmt->close();

             if (count($report_results) == 0) {
                $message = 'No se encontraron ventas para los criterios seleccionados.';
                $message_type = 'info';
             }

        } else {
           $message = 'Error de base de datos al preparar la consulta del reporte: ' . $conn->error;
           $message_type = 'danger';
        }
    }
}


// Close DB connection (optional, depending on your config.php logic)
// $conn->close();

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reportes de Ventas - Panel de Administrador</title>
    <link rel="stylesheet" href="../vendor/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="../vendor/font-awesome/css/all.min.css">
    <link rel="stylesheet" href="../css/style.css">
    </head>
<body>
    <div class="container-fluid">
        <div class="row">

            <?php include 'includes/admin_sidebar.php'; ?>

            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">Reportes de Ventas</h1>
                </div>

                 <?php if ($message): ?>
                    <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
                        <?php echo $message; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <div class="card mb-4">
                    <div class="card-header">Filtros del Reporte</div>
                    <div class="card-body">
                        <form action="sales_reports.php" method="GET">
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
                                    <label for="salesperson_id" class="form-label">Vendedor</label>
                                    <select class="form-select" id="salesperson_id" name="salesperson_id">
                                        <option value="">-- Todos --</option>
                                        <?php foreach ($salespeople as $sp): ?>
                                            <option value="<?php echo $sp['id']; ?>" <?php echo ($salesperson_id == $sp['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($sp['username']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label for="payment_method_id" class="form-label">Medio de Pago</label>
                                    <select class="form-select" id="payment_method_id" name="payment_method_id">
                                         <option value="">-- Todos --</option>
                                        <?php foreach ($payment_methods as $pm): ?>
                                            <option value="<?php echo $pm['id']; ?>" <?php echo ($payment_method_id == $pm['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($pm['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                 <div class="col-md-auto form-check mt-4">
                                     <input class="form-check-input" type="checkbox" value="1" id="include_cancelled" name="include_cancelled" <?php echo $include_cancelled ? 'checked' : ''; ?>>
                                     <label class="form-check-label" for="include_cancelled">
                                         Incluir Ventas Anuladas
                                     </label>
                                 </div>
                                <div class="col-md-auto align-self-end">
                                    <button type="submit" class="btn btn-primary btn-animated"><i class="fas fa-file-alt"></i> Generar Reporte</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <?php if ($report_generated && empty($message)): // Only show if report generated and no major errors ?>
                     <div class="card">
                          <div class="card-header">Detalle del Reporte</div>
                          <div class="card-body">
                               <?php if (count($report_results) > 0): ?>
                                    <div class="table-responsive">
                                        <table class="table table-striped table-sm">
                                            <thead>
                                                <tr>
                                                    <th>ID Venta</th>
                                                    <th>Nº Comprobante</th>
                                                    <th>Fecha Venta</th>
                                                    <th>Total</th>
                                                    <th>Vendedor</th>
                                                    <th>Cliente</th>
                                                    <th>Medio Pago</th>
                                                     <th>Estado</th>
                                                    <th>Anulado Por</th>
                                                    <th>Fecha Anulación</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($report_results as $sale): ?>
                                                    <tr class="<?php echo $sale['is_cancelled'] ? 'table-danger' : ''; ?>"> <td><?php echo htmlspecialchars($sale['id']); ?></td>
                                                        <td><?php echo htmlspecialchars($sale['receipt_number'] ?? 'N/A'); ?></td>
                                                        <td><?php echo htmlspecialchars($sale['sale_date']); ?></td>
                                                        <td>$ <?php echo number_format($sale['total_amount'], 2); ?></td>
                                                        <td><?php echo htmlspecialchars($sale['salesperson_name'] ?? 'Desconocido'); ?></td>
                                                        <td><?php echo htmlspecialchars($sale['customer_name'] ?? 'Público General'); ?></td>
                                                        <td><?php echo htmlspecialchars($sale['payment_method_name'] ?? 'N/A'); ?></td>
                                                        <td>
                                                            <?php if ($sale['is_cancelled']): ?>
                                                                <span class="badge bg-danger"><i class="fas fa-times-circle"></i> Anulada</span>
                                                            <?php else: ?>
                                                                 <span class="badge bg-success"><i class="fas fa-check-circle"></i> Activa</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td><?php echo htmlspecialchars($sale['cancelled_by_name'] ?? 'N/A'); ?></td>
                                                        <td><?php echo htmlspecialchars($sale['cancelled_at'] ?? 'N/A'); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>

                                    <h4 class="mt-4">Total de Ventas del Reporte (sin Anuladas): $ <?php echo number_format($total_report_amount, 2); ?></h4>

                                <?php else: ?>
                                    <p class="text-center">No se encontraron ventas para los criterios seleccionados.</p>
                                <?php endif; ?>
                           </div>
                     </div>
                 <?php elseif ($report_generated && !empty($message)): ?>
                      <?php else: ?>
                      <div class="card">
                           <div class="card-body">
                                <p class="text-center">Seleccione los filtros y haga clic en "Generar Reporte".</p>
                           </div>
                      </div>
                 <?php endif; ?>


            </main>
        </div>
    </div>

    <script src="../vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
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

         // Optional: You might need JS to ensure end_date is not before start_date
         // if not relying solely on PHP validation after submit.
    </script>
</body>
</html>