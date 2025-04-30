<?php
session_start();
require_once '../includes/config.php';
require_once '../includes/session_check.php'; // Check if logged in

// Check if user is salesperson or allowed access (e.g., Admin)
if (!isset($_SESSION['role']) || ($_SESSION['role'] !== 'Salesperson' && $_SESSION['role'] !== 'Admin')) {
     header("Location: ../index.php"); // Redirect to index or login
     exit();
}

$sale_id = $_GET['sale_id'] ?? 0; // Get sale ID from URL
$sale_details = null;
$sale_items = [];
$error_message = '';
$message = $_GET['message'] ?? ''; // Get message from URL (passed by generate_sale.php)
$message_type = $_GET['type'] ?? ''; // Get message type from URL

// Validate Sale ID
// Esta condición debe ser FALSE si $sale_id es un entero positivo (como 6)
if ($sale_id <= 0 || !filter_var($sale_id, FILTER_VALIDATE_INT)) {
    $error_message = 'ID de venta no válido.';
    // If message was passed from generate_sale.php, use it as the primary message
    if (empty($message)) {
         $message = $error_message;
         $message_type = 'danger';
    }
    $sale_details = false; // Indicate that sale details couldn't be fetched
} else {
    // --- Fetch Sale Details --- (Esta parte solo se ejecuta si $sale_id es válido)
    $sql_sale = "SELECT s.id, s.receipt_number, s.sale_date, s.total_amount,
                       u.username AS salesperson_name,
                       c.name AS customer_name, -- ADAPTADO: Eliminado c.identification
                       pm.name AS payment_method_name
                FROM sales s
                JOIN users u ON s.user_id = u.id
                LEFT JOIN customers c ON s.customer_id = c.id -- LEFT JOIN because customer_id can be NULL
                LEFT JOIN payment_methods pm ON s.payment_method_id = pm.id -- LEFT JOIN if payment_method_id can be NULL or is optional
                WHERE s.id = ?";

    if ($stmt_sale = $conn->prepare($sql_sale)) {
        $stmt_sale->bind_param("i", $sale_id);
        if (!$stmt_sale->execute()) { // Added execution check
             error_log("Error executing sale details query: " . $stmt_sale->error); // Log error
             $error_message = 'Error al ejecutar la consulta de detalles de la venta.';
        } else {
            $result_sale = $stmt_sale->get_result();

            if ($result_sale->num_rows === 1) {
                $sale_details = $result_sale->fetch_assoc();
                // Ensure payment_method_name is fetched correctly, if it's a LEFT JOIN and might be null
                 $sale_details['payment_method_name'] = $sale_details['payment_method_name'] ?? 'N/A';

            } else {
                $error_message = 'Venta no encontrada.';
            }
            $result_sale->free();
        }
        $stmt_sale->close();
    } else {
        $error_message = 'Error de base de datos al preparar la consulta de detalles de la venta: ' . $conn->error;
    }

    // If an error occurred during sale details fetch, update main message if it's empty
    if (!empty($error_message) && empty($message)) {
         $message = $error_message;
         $message_type = 'danger';
    }


    // --- Fetch Sale Items (if sale details were found successfully AND no prior error) ---
    if ($sale_details && empty($error_message)) { // Only fetch items if sale details were found without DB error
        $sql_items = "SELECT si.quantity, si.price_at_sale AS price, p.name AS product_name -- Usar price_at_sale y alias como 'price' para consistencia con carrito
                      FROM sale_items si
                      JOIN products p ON si.product_id = p.id
                      WHERE si.sale_id = ?";

        if ($stmt_items = $conn->prepare($sql_items)) {
            $stmt_items->bind_param("i", $sale_id);
            if (!$stmt_items->execute()) { // Added execution check
                 error_log("Error executing sale items query: " . $stmt_items->error); // Log error
                 // Add error message for items fetch, but still display sale details header if available
                 $message = ($message ? $message . " " : "") . 'Error al ejecutar la consulta de ítems de la venta.';
                 $message_type = 'warning'; // Use warning as header info is still displayed
            } else {
                $result_items = $stmt_items->get_result();
                while ($row_item = $result_items->fetch_assoc()) {
                    $sale_items[] = $row_item;
                }
                $result_items->free();
            }
            $stmt_items->close();
        } else {
             // Add error message for items fetch, but still display sale details header if available
             $message = ($message ? $message . " " : "") . 'Error de base de datos al preparar consulta de ítems de la venta: ' . $conn->error;
             $message_type = 'warning'; // Use warning as header info is still displayed
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
    <title><?php echo $sale_details ? 'Recibo Nº ' . htmlspecialchars($sale_details['receipt_number']) : 'Recibo de Venta'; ?></title>
    <link rel="stylesheet" href="../vendor/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="../vendor/font-awesome/css/all.min.css">
    <link rel="stylesheet" href="../css/style.css">
    <style>
        /* Custom styles for receipt */
        .receipt-container {
            max-width: 600px; /* Max width for a typical receipt */
            margin: 20px auto;
            padding: 20px;
            border: 1px solid #ccc;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
            background-color: #fff;
        }
        .receipt-header, .receipt-footer {
            text-align: center;
            margin-bottom: 20px;
        }
        .receipt-details, .receipt-items {
            margin-bottom: 20px;
        }
        .receipt-items table {
            width: 100%;
            border-collapse: collapse;
        }
        .receipt-items th, .receipt-items td {
            border-bottom: 1px dashed #ccc;
            padding: 8px 0;
            text-align: left;
        }
         .receipt-items td:last-child, .receipt-items th:last-child {
             text-align: right; /* Align amounts to the right */
         }
        .receipt-total {
            font-size: 1.2em;
            font-weight: bold;
            text-align: right;
            margin-top: 10px;
        }
         /* Hide elements not needed for printing */
        @media print {
            .btn-print, .alert {
                display: none; /* Hide print button and alerts when printing */
            }
             /* Optionally hide header/footer if printing raw receipt */
             /* .receipt-header, .receipt-footer { display: none; } */
        }
    </style>
</head>
<body>
    <div class="container">

        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?> mt-4 alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <?php
        // Determine if sale_details is valid before attempting to display
        $is_sale_details_valid = ($sale_details !== null && $sale_details !== false);
        ?>

        <?php if ($is_sale_details_valid): ?>
            <div class="receipt-container">
                <div class="receipt-header">
                    <h2>Comprobante de Venta</h2>
                    <p>Nº Comprobante: <strong><?php echo htmlspecialchars($sale_details['receipt_number']); ?></strong></p>
                    <p>Fecha: <?php echo htmlspecialchars($sale_details['sale_date']); ?></p>
                </div>

                <div class="receipt-details">
                    <p>Vendedor: <?php echo htmlspecialchars($sale_details['salesperson_name']); ?></p>
                    <p>Cliente: <?php echo htmlspecialchars($sale_details['customer_name'] ?? 'Público General'); ?></p>
                    <p>Medio de Pago: <?php echo htmlspecialchars($sale_details['payment_method_name']); ?></p>
                </div>

                <div class="receipt-items">
                    <table>
                        <thead>
                            <tr>
                                <th>Producto</th>
                                <th style="width: 80px;">Cant.</th>
                                <th style="width: 100px; text-align: right;">Precio Unit.</th>
                                <th style="width: 100px; text-align: right;">Subtotal</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $items_total = 0;
                            if (!empty($sale_items)):
                                foreach ($sale_items as $item):
                                    // Usar $item['price'] porque así la renombramos en la consulta SQL para consistencia
                                    $item_subtotal = $item['quantity'] * $item['price'];
                                    $items_total += $item_subtotal;
                            ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($item['product_name']); ?></td>
                                    <td><?php echo htmlspecialchars($item['quantity']); ?></td>
                                    <td>S/. <?php echo number_format($item['price'], 2); ?></td>
                                    <td>S/. <?php echo number_format($item_subtotal, 2); ?></td>
                                </tr>
                            <?php endforeach;
                            else: ?>
                                <tr>
                                    <td colspan="4" class="text-center">No hay ítems registrados para esta venta.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                     <div class="receipt-total">
                         Total Venta: S/. <?php echo number_format($sale_details['total_amount'], 2); ?>
                         </div>
                </div>

                <div class="receipt-footer">
                    <p>¡Gracias por su compra!</p>
                    </div>

                <div class="text-center mt-4">
                    <button class="btn btn-primary btn-animated btn-print" onclick="window.print()"><i class="fas fa-print"></i> Imprimir Comprobante</button>
                     <a href="generate_sale.php" class="btn btn-secondary btn-animated"><i class="fas fa-cart-plus"></i> Nueva Venta</a>
                     <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'Admin'): ?>
                          <?php endif; ?>

                </div>

            </div>
        <?php elseif (!$message): ?>
            <div class="alert alert-danger mt-4" role="alert">
                No se pudieron cargar los detalles de la venta. Por favor, verifique el ID.
            </div>
        <?php endif; ?>


    </div>

    <script src="../vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
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