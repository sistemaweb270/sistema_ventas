<?php
session_start();
require_once '../includes/config.php';
require_once '../includes/session_check.php'; // Check if logged in

// Check if user is salesperson or allowed access (e.g., Admin)
if (!isset($_SESSION['role']) || ($_SESSION['role'] !== 'Salesperson' && $_SESSION['role'] !== 'Admin')) {
     header("Location: ../index.php"); // Redirect to index or login
     exit();
}

$current_user_id = $_SESSION['user_id']; // Get the logged-in user's ID
$message = '';
$message_type = ''; // 'success', 'danger', 'warning', 'info'

// Handle messages passed via GET (e.g., after cancellation)
if (isset($_GET['message']) && isset($_GET['type'])) {
    $message = htmlspecialchars($_GET['message']);
    $message_type = htmlspecialchars($_GET['type']);
}

// --- Handle Cancel Sale (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_sale'])) {
    $sale_id_to_cancel = $_POST['sale_id'] ?? 0;

    // Validate Sale ID
    if ($sale_id_to_cancel <= 0 || !filter_var($sale_id_to_cancel, FILTER_VALIDATE_INT)) {
        $message = 'ID de venta no válido para anular.';
        $message_type = 'warning';
    } else {
        // Start a database transaction for cancellation and stock reversal
        $conn->begin_transaction();

        try {
            // 1. Fetch Sale Details to verify ownership and status
            $sql_check_sale = "SELECT id, user_id, is_cancelled FROM sales WHERE id = ? AND user_id = ?";
            if (!($stmt_check = $conn->prepare($sql_check_sale))) {
                 throw new Exception('Error de base de datos al preparar consulta de verificación de venta: ' . $conn->error);
            }
            $stmt_check->bind_param("ii", $sale_id_to_cancel, $current_user_id);
            if (!$stmt_check->execute()) { // Added execution check
                 throw new Exception('Error al verificar la venta: ' . $stmt_check->error);
            }
            $result_check = $stmt_check->get_result();

            if (!($sale_to_cancel = $result_check->fetch_assoc())) {
                 $result_check->free();
                 $stmt_check->close();
                 throw new Exception('Venta no encontrada o no pertenece a este vendedor.');
            }
            $result_check->free();
            $stmt_check->close(); // Close the statement after fetching result

            if ($sale_to_cancel['is_cancelled'] == 1) {
                 throw new Exception('Esta venta ya ha sido anulada.');
            }

            // 2. Fetch Sale Items for Stock Reversal
            $sql_get_items = "SELECT product_id, quantity FROM sale_items WHERE sale_id = ?";
            if (!($stmt_get_items = $conn->prepare($sql_get_items))) {
                throw new Exception('Error de base de datos al preparar consulta para obtener ítems de venta: ' . $conn->error);
            }
            $stmt_get_items->bind_param("i", $sale_id_to_cancel);
            if (!$stmt_get_items->execute()) {
                throw new Exception('Error al obtener ítems de venta: ' . $stmt_get_items->error);
            }
            $result_items = $stmt_get_items->get_result();

            $sql_update_stock = "UPDATE products SET stock = stock + ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?";
            if (!($stmt_update_stock = $conn->prepare($sql_update_stock))) {
                 throw new Exception('Error de base de datos al preparar consulta de actualización de stock: ' . $conn->error);
            }

            while ($item = $result_items->fetch_assoc()) {
                // Revert stock for each item
                $stmt_update_stock->bind_param("ii", $item['quantity'], $item['product_id']);
                if (!$stmt_update_stock->execute()) {
                    // Note: if a specific stock update fails, the transaction will be rolled back by the catch block
                    throw new Exception('Error al revertir stock para Producto ID ' . $item['product_id'] . ': ' . $stmt_update_stock->error);
                }
            }
            $stmt_update_stock->close();
            $result_items->free();
            $stmt_get_items->close(); // Close the statement after processing items


            // 3. Mark Sale as Cancelled
            $sql_cancel_sale = "UPDATE sales SET is_cancelled = 1, cancelled_by_user_id = ?, cancelled_at = NOW() WHERE id = ? AND user_id = ?";
            if (!($stmt_cancel = $conn->prepare($sql_cancel_sale))) {
                throw new Exception('Error de base de datos al preparar consulta de anulación de venta: ' . $conn->error);
            }
            $stmt_cancel->bind_param("iii", $current_user_id, $sale_id_to_cancel, $current_user_id);
            if (!$stmt_cancel->execute()) {
                throw new Exception('Error al marcar la venta como anulada: ' . $stmt_cancel->error);
            }
            $stmt_cancel->close();

            // If we reached here, all database operations were successful within the try block
            $conn->commit();
            $message = 'Venta anulada con éxito.';
            $message_type = 'success';

        } catch (Exception $e) {
            // An error occurred, rollback the transaction
            $conn->rollback();
            $message = 'Error al anular la venta: ' . $e->getMessage();
            $message_type = 'danger';
            // Log the error $e->getMessage() for debugging
        }

        // Redirect after POST to prevent form resubmission and show message
        header("Location: view_cancel_receipts.php?message=" . urlencode($message) . "&type=" . urlencode($message_type));
        exit();
    }
}


// --- Fetch Sales for Display ---
$sales = [];
// Fetch sales *only* for the logged-in salesperson
// CORREGIDO: Cambiado el JOIN para usar ts.user_id y seleccionado el username con un alias
$sql_sales = "SELECT s.id, s.receipt_number, s.sale_date, s.total_amount,
                     s.is_cancelled, s.cancelled_at, s.cancelled_by_user_id, -- CORREGIDO: Añadida la columna
                     c.name AS customer_name, -- ADAPTADO: No seleccionar identification
                     pm.name AS payment_method_name
              FROM sales s
              LEFT JOIN customers c ON s.customer_id = c.id
              LEFT JOIN payment_methods pm ON s.payment_method_id = pm.id
              WHERE s.user_id = ? -- Filtrar por el vendedor actual
              ORDER BY s.sale_date DESC"; // Mostrar las más recientes primero

if ($stmt_sales = $conn->prepare($sql_sales)) {
    $stmt_sales->bind_param("i", $current_user_id);
     if (!$stmt_sales->execute()) { // Added execution check
         error_log("Error fetching sales: " . $stmt_sales->error); // Log the error server-side
     }
    $result_sales = $stmt_sales->get_result();
    while ($row_sale = $result_sales->fetch_assoc()) {
        // Fetch cancelled_by_user's username if exists, and add it to the $row_sale array
        $row_sale['cancelled_by_username'] = 'N/A'; // Initialize with N/A
        if ($row_sale['is_cancelled'] == 1 && $row_sale['cancelled_by_user_id'] !== NULL) {
             $sql_cancelled_by = "SELECT username FROM users WHERE id = ?";
             if ($stmt_cb = $conn->prepare($sql_cancelled_by)) {
                 $stmt_cb->bind_param("i", $row_sale['cancelled_by_user_id']);
                 if (!$stmt_cb->execute()) { // Added execution check
                      error_log("Error fetching cancelled_by_user: " . $stmt_cb->error); // Log the error
                 }
                 $result_cb = $stmt_cb->get_result();
                 if ($row_cb = $result_cb->fetch_assoc()) {
                     $row_sale['cancelled_by_username'] = htmlspecialchars($row_cb['username']);
                 }
                 $result_cb->free();
                 $stmt_cb->close();
             } else {
                  error_log("Error preparing cancelled_by_user query: " . $conn->error); // Log the error
             }
        }
        $sales[] = $row_sale; // Add the row (now including cancelled_by_username) to the sales array
    }
    $result_sales->free();
    $stmt_sales->close();
} else {
    // This message might overwrite a POST message, handle carefully or combine
    // error_log("Error preparing past till sessions query: " . $conn->error); // Log the error
    // $message = 'Error de base de datos al cargar historial de cajas: ' . $conn->error;
    // $message_type = 'danger';
}


// Close DB connection (optional)
// $conn->close();

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mis Comprobantes - Panel de Vendedor</title>
    <link rel="stylesheet" href="../vendor/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="../vendor/font-awesome/css/all.min.css">
    <link rel="stylesheet" href="../css/style.css">
</head>
<body>
    <div class="container-fluid">
        <div class="row">

            <?php include 'includes/salesperson_sidebar.php'; ?>

            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">Mis Comprobantes Emitidos</h1>
                     <div class="btn-toolbar mb-2 mb-md-0">
                         </div>
                </div>

                 <?php if ($message): ?>
                    <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
                        <?php echo htmlspecialchars($message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <div class="card">
                    <div class="card-header"><i class="fas fa-receipt"></i> Listado de Ventas</div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped table-sm">
                                <thead>
                                    <tr>
                                        <th>ID Venta</th>
                                        <th>Nº Comprobante</th>
                                        <th>Fecha Venta</th>
                                        <th>Total</th>
                                        <th>Cliente</th>
                                        <th>Estado</th>
                                        <th>Anulado Por</th>
                                        <th>Fecha Anulación</th>
                                        <th>Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($sales)): ?>
                                        <?php foreach ($sales as $sale): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($sale['id']); ?></td>
                                            <td><?php echo htmlspecialchars($sale['receipt_number']); ?></td>
                                            <td><?php echo htmlspecialchars($sale['sale_date']); ?></td>
                                            <td>S/. <?php echo number_format($sale['total_amount'], 2); ?></td>
                                            <td><?php echo htmlspecialchars($sale['customer_name'] ?? 'Público General'); ?></td>
                                            <td>
                                                <?php if ($sale['is_cancelled'] == 1): ?>
                                                    <span class="badge bg-danger">Anulada</span>
                                                <?php else: ?>
                                                    <span class="badge bg-success">Activa</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php
                                                    // Show cancelled_by_username which was fetched and added to $sale array
                                                    echo htmlspecialchars($sale['cancelled_by_username'] ?? 'N/A');
                                                ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($sale['cancelled_at'] ?? 'N/A'); ?></td>
                                            <td>
                                                <a href="issue_receipt.php?sale_id=<?php echo $sale['id']; ?>" class="btn btn-info btn-sm" title="Ver Comprobante"><i class="fas fa-eye"></i></a>
                                                <?php if ($sale['is_cancelled'] == 0): ?>
                                                    <form action="view_cancel_receipts.php" method="POST" class="d-inline" onsubmit="return confirm('¿Está seguro de anular este comprobante? Esta acción revertirá el stock.');">
                                                        <input type="hidden" name="cancel_sale" value="1">
                                                        <input type="hidden" name="sale_id" value="<?php echo $sale['id']; ?>">
                                                        <button type="submit" class="btn btn-warning btn-sm" title="Anular Comprobante"><i class="fas fa-times-circle"></i> Anular</button>
                                                    </form>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="9" class="text-center">No hay comprobantes emitidos por este vendedor.</td>
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
    </script>
</body>
</html>