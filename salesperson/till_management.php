<?php
session_start();
require_once '../includes/config.php';
require_once '../includes/session_check.php'; // Check if logged in

// Check if user is salesperson or allowed access (e.g., Admin)
if (!isset($_SESSION['role']) || ($_SESSION['role'] !== 'Salesperson' && $_SESSION['role'] !== 'Admin')) {
     header("Location: ../index.php"); // Redirect to index or login
     exit();
}

$current_user_id = $_SESSION['user_id'];
$current_store_id = null; // Assuming user might be linked to a store
$open_till_session = null;
$message = '';
$message_type = '';

// Fetch User's Store ID (if applicable)
$sql_user_store = "SELECT store_id FROM users WHERE id = ?";
if ($stmt_store = $conn->prepare($sql_user_store)) {
   $stmt_store->bind_param("i", $current_user_id);
   $stmt_store->execute();
   $result_store = $stmt_store->get_result();
   if($row_store = $result_store->fetch_assoc()) {
       $current_store_id = $row_store['store_id'];
   }
   $stmt_store->close();
}

// Fetch current open till session for this user
// We only expect one open session per user at a time
$sql_open_session = "SELECT * FROM till_sessions WHERE user_id = ? AND status = 'Open' ORDER BY opening_time DESC LIMIT 1";
if ($stmt_open = $conn->prepare($sql_open_session)) {
    $stmt_open->bind_param("i", $current_user_id);
    $stmt_open->execute();
    $result_open = $stmt_open->get_result();
    if ($row_open = $result_open->fetch_assoc()) {
        $open_till_session = $row_open;
    }
    $result_open->free();
    $stmt_open->close();
} else {
     $message = 'Error de base de datos al verificar sesión de caja abierta: ' . $conn->error;
     $message_type = 'danger';
}

// Handle messages passed via GET
if (isset($_GET['message']) && isset($_GET['type'])) {
    $message = htmlspecialchars($_GET['message']);
    $message_type = htmlspecialchars($_GET['type']);
}


// --- Handle Opening Till (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['open_till'])) {
    $opening_balance = trim($_POST['opening_balance'] ?? '');

    if ($open_till_session) {
        $message = 'Ya tienes una sesión de caja abierta.';
        $message_type = 'warning';
    } elseif (!is_numeric($opening_balance) || $opening_balance < 0) {
        $message = 'Ingrese un monto de apertura válido (número positivo o cero).';
        $message_type = 'warning';
    } else {
        // Insert new till session
        $sql_insert_open = "INSERT INTO till_sessions (user_id, store_id, opening_balance, status) VALUES (?, ?, ?, 'Open')";
        if ($stmt_insert_open = $conn->prepare($sql_insert_open)) {
             // Bind store_id which can be NULL
             $stmt_insert_open->bind_param("iid", $current_user_id, $current_store_id, $opening_balance); // use 'i' for store_id even if NULL

            if ($stmt_insert_open->execute()) {
                $message = 'Caja abierta con éxito.';
                $message_type = 'success';
                 // Reload the page to show the "Close Till" form
                 header("Location: till_management.php?message=" . urlencode($message) . "&type=" . urlencode($message_type));
                 exit();
            } else {
                $message = 'Error al abrir la caja: ' . $stmt_insert_open->error;
                $message_type = 'danger';
            }
            $stmt_insert_open->close();
        } else {
            $message = 'Error de base de datos al preparar la apertura de caja: ' . $conn->error;
            $message_type = 'danger';
        }
    }
    // Redirect on failure/warning to show message and clear POST
     header("Location: till_management.php?message=" . urlencode($message) . "&type=" . urlencode($message_type));
     exit(); // Important exit after header
}

// --- Handle Closing Till (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['close_till'])) {
    $till_session_id = $_POST['till_session_id'] ?? 0;
    $closing_balance_actual = trim($_POST['closing_balance_actual'] ?? '');

    // Find the open session again to ensure it's still open and belongs to the user
    $sql_verify_open = "SELECT id, user_id, opening_time, opening_balance FROM till_sessions WHERE id = ? AND user_id = ? AND status = 'Open'";
    if (!($stmt_verify = $conn->prepare($sql_verify_open))) {
         $message = 'Error de base de datos al verificar sesión de cierre: ' . $conn->error;
         $message_type = 'danger';
    } else {
         $stmt_verify->bind_param("ii", $till_session_id, $current_user_id);
         $stmt_verify->execute();
         $result_verify = $stmt_verify->get_result();
         $till_to_close = $result_verify->fetch_assoc();
         $result_verify->free();
         $stmt_verify->close();

        if (!$till_to_close) {
            $message = 'Sesión de caja no encontrada, no está abierta, o no te pertenece.';
            $message_type = 'warning';
        } elseif (!is_numeric($closing_balance_actual) || $closing_balance_actual < 0) {
            $message = 'Ingrese un monto de cierre válido (número positivo o cero).';
            $message_type = 'warning';
        } else {
            // Start a database transaction for calculating sales and closing
            $conn->begin_transaction();
            $closing_success = true;

            try {
                // 1. Find the payment_method_id for 'Cash'
                // ASSUMPTION: You have a payment method named 'Efectivo' or 'Cash'
                $cash_payment_method_id = null;
                $sql_cash_method = "SELECT id FROM payment_methods WHERE name = 'Efectivo' LIMIT 1"; // Adjust 'Efectivo' if your cash method name is different
                if ($result_cash = $conn->query($sql_cash_method)) {
                    if ($row_cash = $result_cash->fetch_assoc()) {
                        $cash_payment_method_id = $row_cash['id'];
                    }
                    $result_cash->free();
                } // Consider error handling if cash method not found

                $total_cash_sales = 0;
                if ($cash_payment_method_id !== null) {
                    // 2. Calculate total cash sales for this user within this till session time frame
                    $sql_cash_sales = "SELECT SUM(total_amount) AS cash_sales_sum
                                       FROM sales
                                       WHERE user_id = ?
                                         AND payment_method_id = ?
                                         AND sale_date >= ?
                                         AND sale_date <= NOW() -- Sales up to the moment of closing
                                         AND is_cancelled = 0"; // Only count non-cancelled sales

                    if ($stmt_sales_sum = $conn->prepare($sql_cash_sales)) {
                        $opening_time_str = $till_to_close['opening_time']; // Use the string timestamp directly
                        $stmt_sales_sum->bind_param("iis", $current_user_id, $cash_payment_method_id, $opening_time_str);
                        $stmt_sales_sum->execute();
                        $result_sales_sum = $stmt_sales_sum->get_result();
                        if ($row_sales_sum = $result_sales_sum->fetch_assoc()) {
                            $total_cash_sales = $row_sales_sum['cash_sales_sum'] ?? 0.00; // Use 0 if sum is NULL (no sales)
                        }
                        $result_sales_sum->free();
                        $stmt_sales_sum->close();
                    } else {
                         throw new Exception('Error de base de datos al calcular ventas en efectivo: ' . $conn->error);
                    }
                } else {
                     // If 'Cash' payment method wasn't found, total cash sales is 0.
                     // You might want to throw an exception here if 'Cash' is mandatory.
                }


                // 3. Calculate expected closing balance and discrepancy
                $opening_balance = $till_to_close['opening_balance'];
                $closing_balance_expected = $opening_balance + $total_cash_sales;
                $discrepancy = $closing_balance_actual - $closing_balance_expected;

                // 4. Update till session record
                $sql_update_close = "UPDATE till_sessions
                                     SET closing_time = NOW(),
                                         closing_balance_expected = ?,
                                         closing_balance_actual = ?,
                                         discrepancy = ?,
                                         status = 'Closed',
                                         updated_at = CURRENT_TIMESTAMP
                                     WHERE id = ? AND user_id = ? AND status = 'Open'"; // Double-check status and user_id

                if ($stmt_update_close = $conn->prepare($sql_update_close)) {
                     $stmt_update_close->bind_param("ddiii",
                         $closing_balance_expected,
                         $closing_balance_actual,
                         $discrepancy,
                         $till_to_close['id'], // Use the verified till session ID
                         $current_user_id
                     );

                    if (!$stmt_update_close->execute()) {
                        throw new Exception('Error al actualizar la sesión de caja (cierre): ' . $stmt_update_close->error);
                    }

                     // Check if the update affected exactly one row
                     if ($stmt_update_close->affected_rows !== 1) {
                         // This indicates something went wrong, e.g., session was already closed by another process
                          throw new Exception('Error al actualizar la sesión de caja. No se pudo cerrar la sesión esperada.');
                     }

                    $stmt_update_close->close();

                } else {
                    throw new Exception('Error de base de datos al preparar la actualización de cierre de caja: ' . $conn->error);
                }

                // If everything was successful, commit the transaction
                $conn->commit();
                $message = 'Caja cerrada con éxito. Discrepancia: S/. ' . number_format($discrepancy, 2); // CAMBIO AQUÍ
                $message_type = ($discrepancy == 0) ? 'success' : (($discrepancy > 0) ? 'info' : 'warning'); // Different message types for discrepancy

            } catch (Exception $e) {
                // An error occurred, rollback the transaction
                $conn->rollback();
                $message = 'Error al cerrar la caja: ' . $e->getMessage();
                $message_type = 'danger';
                // Log the error $e->getMessage() for debugging
            }
        }
    }

     // Redirect after POST to prevent form resubmission and show message
     header("Location: till_management.php?message=" . urlencode($message) . "&type=" . urlencode($message_type));
     exit(); // Important exit after header
}


// --- Fetch Past Till Sessions for Display ---
$past_sessions = [];
// Fetch sales *only* for the logged-in salesperson
// CORREGIDO: Cambiado el JOIN para usar ts.user_id y seleccionado el username con un alias
$sql_past_sessions = "SELECT ts.*, u.username AS salesperson_username
                      FROM till_sessions ts
                      JOIN users u ON ts.user_id = u.id -- Unir con users usando el user_id de la sesión
                      WHERE ts.user_id = ? AND ts.status != 'Open' -- Mostrar closed/cancelled sessions for this user
                      ORDER BY ts.closing_time DESC, ts.opening_time DESC"; // Show most recent closed sessions first

if ($stmt_past = $conn->prepare($sql_past_sessions)) {
    $stmt_past->bind_param("i", $current_user_id);
     if (!$stmt_past->execute()) { // Added execution check
         error_log("Error fetching past till sessions: " . $stmt_past->error); // Log error
     }
    $result_past = $stmt_past->get_result();
    while ($row_past = $result_past->fetch_assoc()) {
        // salesperson_username is already fetched in the query
        $past_sessions[] = $row_past;
    }
    $result_past->free();
    $stmt_past->close();
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
    <title>Gestión de Caja - Panel de Vendedor</title>
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
                    <h1 class="h2">Gestión de Caja</h1>
                     <div class="btn-toolbar mb-2 mb-md-0">
                         </div>
                </div>

                 <?php if ($message): ?>
                    <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
                        <?php echo htmlspecialchars($message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <div class="card mb-4">
                    <div class="card-header"><i class="fas fa-cash-register"></i>
                         <?php echo $open_till_session ? 'Caja Abierta' : 'Abrir Caja'; ?>
                    </div>
                    <div class="card-body">
                        <?php if ($open_till_session): ?>
                            <p><strong>Estado:</strong> <span class="badge bg-success">Abierta</span></p>
                            <p><strong>Hora de Apertura:</strong> <?php echo htmlspecialchars($open_till_session['opening_time']); ?></p>
                            <p><strong>Saldo Inicial:</strong> S/. <?php echo number_format($open_till_session['opening_balance'], 2); ?></p> <h5 class="mt-4">Cerrar Caja</h5>
                            <form action="till_management.php" method="POST">
                                <input type="hidden" name="close_till" value="1">
                                <input type="hidden" name="till_session_id" value="<?php echo $open_till_session['id']; ?>">
                                <div class="mb-3">
                                    <label for="closing_balance_actual" class="form-label">Monto Real Contado en Caja</label>
                                    <input type="number" class="form-control" id="closing_balance_actual" name="closing_balance_actual" step="0.01" required min="0">
                                </div>
                                <button type="submit" class="btn btn-danger btn-animated" onclick="return confirm('¿Está seguro de cerrar la caja?');"><i class="fas fa-lock"></i> Cerrar Caja</button>
                            </form>
                        <?php else: ?>
                            <p>No tienes una sesión de caja abierta.</p>
                            <form action="till_management.php" method="POST">
                                <input type="hidden" name="open_till" value="1">
                                <div class="mb-3">
                                    <label for="opening_balance" class="form-label">Monto Inicial en Caja</label>
                                    <input type="number" class="form-control" id="opening_balance" name="opening_balance" step="0.01" required min="0" value="0.00">
                                </div>
                                <button type="submit" class="btn btn-primary btn-animated"><i class="fas fa-unlock"></i> Abrir Caja</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card mb-4">
                    <div class="card-header"><i class="fas fa-history"></i> Historial de Sesiones de Caja</div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped table-sm">
                                <thead>
                                    <tr>
                                        <th>ID Sesión</th>
                                        <th>Vendedor</th> <th>Apertura</th>
                                        <th>Saldo Inicial</th>
                                        <th>Cierre</th>
                                        <th>Saldo Esperado</th>
                                        <th>Saldo Real</th>
                                        <th>Discrepancia</th>
                                        <th>Estado</th>
                                        </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($past_sessions)): ?>
                                        <?php foreach ($past_sessions as $session): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($session['id']); ?></td>
                                            <td><?php echo htmlspecialchars($session['salesperson_username'] ?? 'N/A'); ?></td> <td><?php echo htmlspecialchars($session['opening_time']); ?></td>
                                            <td>S/. <?php echo number_format($session['opening_balance'], 2); ?></td> <td><?php echo htmlspecialchars($session['closing_time'] ?? 'N/A'); ?></td>
                                             <td>S/. <?php echo number_format($session['closing_balance_expected'] ?? 0.00, 2); ?></td> <td>S/. <?php echo number_format($session['closing_balance_actual'] ?? 0.00, 2); ?></td> <td>S/. <?php echo number_format($session['discrepancy'] ?? 0.00, 2); ?></td> <td>
                                                <?php
                                                    $status_class = 'secondary';
                                                    if ($session['status'] === 'Closed') $status_class = 'success';
                                                    if ($session['status'] === 'Cancelled') $status_class = 'danger';
                                                ?>
                                                <span class="badge bg-<?php echo $status_class; ?>"><?php echo htmlspecialchars($session['status']); ?></span>
                                            </td>
                                             </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="9" class="text-center">No hay historial de sesiones de caja para este vendedor.</td> </tr>
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