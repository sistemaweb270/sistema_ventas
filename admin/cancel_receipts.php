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
$search_receipt_number = '';
$sales_results = [];

// --- Handle Cancel Operation ---
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['cancel_sale'])) {
    $sale_id = $_GET['cancel_sale'] ?? 0;
    $admin_user_id = $_SESSION['user_id']; // Get the ID of the cancelling admin

    if ($sale_id <= 0) {
         $message = 'ID de venta no válido para anular.';
         $message_type = 'warning';
     } else {
         // Check if the sale is already cancelled (optional but good practice)
         $check_sql = "SELECT is_cancelled FROM sales WHERE id = ?";
         if ($stmt_check = $conn->prepare($check_sql)) {
             $stmt_check->bind_param("i", $sale_id);
             $stmt_check->execute();
             $stmt_check->bind_result($is_cancelled);
             $stmt_check->fetch();
             $stmt_check->close();

             if ($is_cancelled) {
                 $message = 'Esta venta ya ha sido anulada previamente.';
                 $message_type = 'info';
             } else {
                 // Anular la venta: Actualizar is_cancelled, cancelled_by_user_id, cancelled_at
                 $sql = "UPDATE sales SET is_cancelled = TRUE, cancelled_by_user_id = ?, cancelled_at = CURRENT_TIMESTAMP WHERE id = ?";
                 if ($stmt = $conn->prepare($sql)) {
                     $stmt->bind_param("ii", $admin_user_id, $sale_id);
                     if ($stmt->execute()) {
                         if ($stmt->affected_rows > 0) {
                            $message = 'Venta anulada con éxito.';
                            $message_type = 'success';
                            // TODO: You might want to reverse the stock for the items in this sale.
                            // This would require fetching sale items and updating product stock.
                            // This is a critical business logic step for a real system.
                         } else {
                             $message = 'No se encontró la venta con el ID proporcionado o ya estaba anulada.';
                             $message_type = 'info';
                         }
                     } else {
                         $message = 'Error al anular la venta: ' . $stmt->error;
                         $message_type = 'danger';
                     }
                     $stmt->close();
                 } else {
                    $message = 'Error de base de datos al preparar la consulta para anular venta: ' . $conn->error;
                    $message_type = 'danger';
                }
             }
         } else {
             $message = 'Error de base de datos al verificar el estado de la venta: ' . $conn->error;
             $message_type = 'danger';
         }


     }
     // Redirect to clean URL after GET cancel
     // Preserve search query if applicable
     $redirect_url = 'cancel_receipts.php';
     if (isset($_GET['receipt_number'])) {
         $redirect_url .= '?receipt_number=' . urlencode($_GET['receipt_number']);
     }
     $redirect_url .= (strpos($redirect_url, '?') === false ? '?' : '&') . "message=" . urlencode($message) . "&type=" . urlencode($message_type);

     header("Location: " . $redirect_url);
     exit();
}


// --- Handle Search Operation ---
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['search'])) {
     $search_receipt_number = trim($_GET['receipt_number'] ?? '');
     // Add more search filters here if needed (e.g., date range, salesperson_id)

     $sql = "SELECT s.id, s.receipt_number, s.sale_date, s.total_amount, s.is_cancelled,
                     u.username AS salesperson_name,
                     c.name AS customer_name,
                     ucancel.username AS cancelled_by_name, s.cancelled_at
             FROM sales s
             LEFT JOIN users u ON s.user_id = u.id
             LEFT JOIN customers c ON s.customer_id = c.id
             LEFT JOIN users ucancel ON s.cancelled_by_user_id = ucancel.id
             WHERE 1"; // Start with a true condition

     $params = [];
     $types = '';

     if (!empty($search_receipt_number)) {
         $sql .= " AND s.receipt_number LIKE ?";
         $params[] = '%' . $search_receipt_number . '%';
         $types .= 's';
     }

     // Add conditions for other search filters if implemented

     $sql .= " ORDER BY s.sale_date DESC LIMIT 100"; // Limit results for performance

     if ($stmt = $conn->prepare($sql)) {
         if (!empty($params)) {
             $stmt->bind_param($types, ...$params);
         }
         $stmt->execute();
         $result = $stmt->get_result();
         while ($row = $result->fetch_assoc()) {
             $sales_results[] = $row;
         }
         $result->free();
         $stmt->close();
     } else {
        $message = 'Error de base de datos al preparar la consulta de búsqueda: ' . $conn->error;
        $message_type = 'danger';
     }
}


// Handle messages passed via GET after a redirect (e.g., from delete or cancel)
if (isset($_GET['message']) && isset($_GET['type'])) {
    $message = htmlspecialchars($_GET['message']);
    $message_type = htmlspecialchars($_GET['type']);
    // Preserve search term after redirect if needed
    if (isset($_GET['receipt_number'])) {
         $search_receipt_number = htmlspecialchars($_GET['receipt_number']);
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
    <title>Anular Comprobantes - Panel de Administrador</title>
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
                    <h1 class="h2">Anular Comprobantes</h1>
                </div>

                <?php if ($message): ?>
                    <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
                        <?php echo $message; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <div class="card mb-4">
                    <div class="card-header">Buscar Comprobante</div>
                    <div class="card-body">
                        <form action="cancel_receipts.php" method="GET">
                            <input type="hidden" name="search" value="1">
                            <div class="row g-3 align-items-end">
                                <div class="col-md-4">
                                    <label for="receipt_number" class="form-label">Número de Comprobante</label>
                                    <input type="text" class="form-control" id="receipt_number" name="receipt_number" value="<?php echo htmlspecialchars($search_receipt_number); ?>">
                                </div>
                                <div class="col-md-auto">
                                    <button type="submit" class="btn btn-primary btn-animated"><i class="fas fa-search"></i> Buscar</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>


                <div class="card">
                     <div class="card-header">Resultados de la Búsqueda</div>
                     <div class="card-body">
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
                                        <th>Estado</th>
                                        <th>Anulado Por</th>
                                        <th>Fecha Anulación</th>
                                        <th>Acción</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (isset($_GET['search']) && count($sales_results) > 0): ?>
                                        <?php foreach ($sales_results as $sale): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($sale['id']); ?></td>
                                                <td><?php echo htmlspecialchars($sale['receipt_number'] ?? 'N/A'); ?></td>
                                                <td><?php echo htmlspecialchars($sale['sale_date']); ?></td>
                                                <td>$ <?php echo number_format($sale['total_amount'], 2); ?></td>
                                                <td><?php echo htmlspecialchars($sale['salesperson_name'] ?? 'Desconocido'); ?></td>
                                                <td><?php echo htmlspecialchars($sale['customer_name'] ?? 'Público General'); ?></td>
                                                <td>
                                                    <?php if ($sale['is_cancelled']): ?>
                                                        <span class="badge bg-danger"><i class="fas fa-times-circle"></i> Anulada</span>
                                                    <?php else: ?>
                                                         <span class="badge bg-success"><i class="fas fa-check-circle"></i> Activa</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo htmlspecialchars($sale['cancelled_by_name'] ?? 'N/A'); ?></td>
                                                <td><?php echo htmlspecialchars($sale['cancelled_at'] ?? 'N/A'); ?></td>
                                                <td>
                                                    <?php if (!$sale['is_cancelled']): ?>
                                                        <a href="cancel_receipts.php?cancel_sale=<?php echo $sale['id']; ?>&receipt_number=<?php echo urlencode($search_receipt_number); ?>&search=1"
                                                           class="btn btn-warning btn-sm btn-animated"
                                                           onclick="return confirm('¿Estás seguro de ANULAR el comprobante Nº <?php echo htmlspecialchars($sale['receipt_number'] ?? $sale['id']); ?>? Esta acción no se puede revertir.');">
                                                            <i class="fas fa-times-circle"></i> Anular
                                                        </a>
                                                    <?php else: ?>
                                                        <button class="btn btn-secondary btn-sm" disabled><i class="fas fa-check"></i> Anulada</button>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php elseif (isset($_GET['search'])): ?>
                                        <tr>
                                            <td colspan="10" class="text-center">No se encontraron comprobantes con los criterios de búsqueda.</td>
                                        </tr>
                                    <?php else: ?>
                                         <tr>
                                            <td colspan="10" class="text-center">Realice una búsqueda para ver los comprobantes.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                     </div>
                </div>


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

         // Initialize datepickers if using them (requires a datepicker library)
         // $(function() {
         //     $("#start_date").datepicker();
         //     $("#end_date").datepicker();
         // }); // Example using jQuery UI datepicker
    </script>
</body>
</html>