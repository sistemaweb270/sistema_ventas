<?php
session_start();
require_once '../includes/config.php';
require_once '../includes/session_check.php'; // Check if logged in

// Check if user is salesperson or allowed access
if (!isset($_SESSION['role']) || ($_SESSION['role'] !== 'Salesperson' && $_SESSION['role'] !== 'Admin')) {
     header("Location: ../index.php"); // Redirect to index or login
     exit();
}

$message = '';
$message_type = ''; // 'success', 'danger', 'warning', 'info'
$current_user_id = $_SESSION['user_id'];

// --- Handle Adding Income/Expense Transaction ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_transaction'])) {
    $type = $_POST['type'] ?? ''; // 'Income' or 'Expense'
    $amount = trim($_POST['amount'] ?? '');
    $description = trim($_POST['description'] ?? '');
    // Optional: Link to current till session if applicable (needs Till Session management logic)
    // $current_till_session_id = null; // Fetch from session or DB based on open till for this user

    // Fetch user's store_id if available
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


    // Validation
    if (!in_array($type, ['Income', 'Expense'])) {
        $message = 'Tipo de transacción inválido.';
        $message_type = 'warning';
    } elseif (!is_numeric($amount) || $amount <= 0) {
        $message = 'El monto debe ser un número positivo.';
        $message_type = 'warning';
    } elseif (empty($description)) {
        $message = 'La descripción es obligatoria para la transacción manual.';
        $message_type = 'warning';
    } else {
        $sql = "INSERT INTO income_expenses (type, amount, description, user_id, store_id, till_session_id) VALUES (?, ?, ?, ?, ?, ?)";
        if ($stmt = $conn->prepare($sql)) {
            // $till_session_id could be NULL
            $stmt->bind_param("sdsiii", $type, $amount, $description, $current_user_id, $user_store_id, $current_till_session_id); // Pass $current_till_session_id if available
            if ($stmt->execute()) {
                $message = 'Transacción manual registrada con éxito.';
                $message_type = 'success';
            } else {
                $message = 'Error al registrar la transacción manual: ' . $stmt->error;
                $message_type = 'danger';
            }
            $stmt->close();
        } else {
            $message = 'Error de base de datos al preparar la consulta para registrar transacción: ' . $conn->error;
            $message_type = 'danger';
        }
    }
     // Redirect to clean URL after POST
     header("Location: income_expenses.php?message=" . urlencode($message) . "&type=" . urlencode($message_type));
     exit();
}


// Handle messages passed via GET after a redirect (e.g., from POST submit)
if (isset($_GET['message']) && isset($_GET['type'])) {
    $message = htmlspecialchars($_GET['message']);
    $message_type = htmlspecialchars($_GET['type']);
}


// --- Fetch Recent Transactions for this Salesperson ---
$recent_transactions = [];
$sql_recent = "SELECT ie.id, ie.type, ie.amount, ie.description, ie.transaction_date,
                      s.name AS store_name
               FROM income_expenses ie
               LEFT JOIN stores s ON ie.store_id = s.id
               WHERE ie.user_id = ?
               ORDER BY ie.transaction_date DESC
               LIMIT 20"; // Show last 20 transactions

if ($stmt_recent = $conn->prepare($sql_recent)) {
    $stmt_recent->bind_param("i", $current_user_id);
    $stmt_recent->execute();
    $result_recent = $stmt_recent->get_result();
    while ($row_recent = $result_recent->fetch_assoc()) {
        $recent_transactions[] = $row_recent;
    }
    $result_recent->free();
    $stmt_recent->close();
} else {
     // Handle error fetching recent transactions
}


// Close DB connection (optional)
// $conn->close();

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ingresos / Egresos - Panel de Vendedor</title>
    <!-- Bootstrap CSS -->
    <link rel="stylesheet" href="../vendor/bootstrap/css/bootstrap.min.css">
    <!-- Font Awesome CSS -->
    <link rel="stylesheet" href="../vendor/font-awesome/css/all.min.css">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="../css/style.css">
</head>
<body>
    <div class="container-fluid">
        <div class="row">

            <!-- Sidebar -->
            <?php include 'includes/salesperson_sidebar.php'; ?>

            <!-- Main content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">Ingresos y Egresos Manuales</h1>
                </div>

                 <?php if ($message): ?>
                    <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
                        <?php echo htmlspecialchars($message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <!-- Add Transaction Form -->
                <div class="card mb-4">
                    <div class="card-header">Registrar Ingreso o Egreso Manual</div>
                    <div class="card-body">
                        <form action="income_expenses.php" method="POST">
                             <input type="hidden" name="add_transaction" value="1">
                            <div class="row g-3">
                                <div class="col-md-3">
                                    <label for="type" class="form-label">Tipo</label>
                                    <select class="form-select" id="type" name="type" required>
                                        <option value="">-- Seleccionar --</option>
                                        <option value="Income">Ingreso Manual (+)</option>
                                        <option value="Expense">Egreso Manual (-)</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label for="amount" class="form-label">Monto</label>
                                    <input type="number" step="0.01" class="form-control" id="amount" name="amount" required min="0.01">
                                </div>
                                 <div class="col-md-6">
                                    <label for="description" class="form-label">Descripción</label>
                                    <input type="text" class="form-control" id="description" name="description" required>
                                </div>
                                <!-- Optional: Link to Till Session if managing them -->
                                <!-- <input type="hidden" name="till_session_id" value="..."> -->
                                <div class="col-12">
                                     <button type="submit" class="btn btn-primary btn-animated"><i class="fas fa-save"></i> Registrar Transacción</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Recent Transactions Table -->
                 <div class="card">
                          <div class="card-header">Mis Últimas Transacciones Manuales</div>
                          <div class="card-body">
                               <?php if (count($recent_transactions) > 0): ?>
                                    <div class="table-responsive">
                                        <table class="table table-striped table-sm">
                                            <thead>
                                                <tr>
                                                    <th>ID</th>
                                                    <th>Tipo</th>
                                                    <th>Monto</th>
                                                    <th>Descripción</th>
                                                    <th>Fecha</th>
                                                    <th>Tienda Asociada</th>
                                                    <!-- Add Till Session ID column if needed -->
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($recent_transactions as $transaction): ?>
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
                                                         <td><?php echo htmlspecialchars($transaction['store_name'] ?? 'N/A'); ?></td>
                                                        <!-- Add Till Session ID data -->
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php else: ?>
                                    <p class="text-center">No has registrado transacciones manuales recientemente.</p>
                                <?php endif; ?>
                           </div>
                     </div>


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