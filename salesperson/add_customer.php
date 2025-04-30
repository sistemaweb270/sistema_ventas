<?php
session_start();
require_once '../includes/config.php';
require_once '../includes/session_check.php'; // Check if logged in

// Check if user is salesperson or allowed access (e.g., Admin)
if (!isset($_SESSION['role']) || ($_SESSION['role'] !== 'Salesperson' && $_SESSION['role'] !== 'Admin')) {
     header("Location: ../index.php"); // Redirect to index or login
     exit();
}

$message = '';
$message_type = ''; // 'success', 'danger', 'warning', 'info'

// Initialize form values for sticky form
$name = '';
$address = '';
$phone = '';
$email = '';


// --- Handle Add Customer (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_customer'])) {
    $name = trim($_POST['name'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    // identification is not in your DB, so we don't process it from the form

    // Basic Validation: Name is required
    if (empty($name)) {
        $message = 'El Nombre del Cliente es obligatorio.';
        $message_type = 'warning';
        // Keep form values for sticky form
        $name = htmlspecialchars($_POST['name'] ?? '');
        $address = htmlspecialchars($_POST['address'] ?? '');
        $phone = htmlspecialchars($_POST['phone'] ?? '');
        $email = htmlspecialchars($_POST['email'] ?? '');

    } else {
        // Prepare SQL query for inserting into customers table
        // Adapting to your table structure without 'identification'
        $sql = "INSERT INTO customers (name, address, phone, email, created_at) VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)";

        if ($stmt = $conn->prepare($sql)) {
            // Bind parameters
            // 's' for string type
            $stmt->bind_param("ssss", $name, $address, $phone, $email);

            if ($stmt->execute()) {
                $new_customer_id = $conn->insert_id; // Get the ID of the newly inserted customer
                $message = 'Cliente "' . htmlspecialchars($name) . '" agregado con éxito.';
                $message_type = 'success';

                 // Redirect to generate_sale.php with success message and new customer ID
                 // Esto permite pre-seleccionar el cliente en la venta si implementas esa l├│gica en generate_sale.php
                 header("Location: generate_sale.php?message=" . urlencode($message) . "&type=" . urlencode($message_type) . "&customer_id=" . $new_customer_id);
                 exit(); // Stop script execution after redirect

            } else {
                // Handle potential errors, e.g., if you had a unique constraint on another field later
                 $message = 'Error al agregar el cliente: ' . $stmt->error;
                 $message_type = 'danger';
                  // Keep form values for sticky form
                 $name = htmlspecialchars($_POST['name'] ?? '');
                 $address = htmlspecialchars($_POST['address'] ?? '');
                 $phone = htmlspecialchars($_POST['phone'] ?? '');
                 $email = htmlspecialchars($_POST['email'] ?? '');
            }
            $stmt->close();
        } else {
            $message = 'Error de base de datos al preparar la consulta para agregar cliente: ' . $conn->error;
            $message_type = 'danger';
             // Keep form values for sticky form
            $name = htmlspecialchars($_POST['name'] ?? '');
            $address = htmlspecialchars($_POST['address'] ?? '');
            $phone = htmlspecialchars($_POST['phone'] ?? '');
            $email = htmlspecialchars($_POST['email'] ?? '');
        }
    }
    // If there was an error, the script continues to display the form with the message
}

// Handle messages passed via GET after a redirect (e.g., if adding failed)
if (isset($_GET['message']) && isset($_GET['type'])) {
    $message = htmlspecialchars($_GET['message']);
    $message_type = htmlspecialchars($_GET['type']);
}


// --- Fetch All Customers for Display ---
$customers = [];
$sql_fetch_customers = "SELECT id, name, address, phone, email, created_at FROM customers ORDER BY created_at DESC"; // Order by newest first
if ($result_customers = $conn->query($sql_fetch_customers)) {
    while ($row = $result_customers->fetch_assoc()) {
        $customers[] = $row;
    }
    $result_customers->free();
} else {
     // Handle error fetching customers
     $message = 'Error al cargar la lista de clientes: ' . $conn->error;
     $message_type = 'danger';
}

// Close DB connection (optional)
// $conn->close();

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Agregar Cliente - Panel de Vendedor</title>
    <link rel="stylesheet" href="../vendor/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="../vendor/font-awesome/css/all.min.css">
    <link rel="stylesheet" href="../css/style.css">
    <style>
        /* Optional: Add some basic styling for the customer list table */
        .customer-list-table th, .customer-list-table td {
            vertical-align: middle; /* Align table content vertically */
        }
         /* Ensure long text wraps or is truncated */
         .customer-list-table td {
             max-width: 150px; /* Adjust as needed */
             overflow: hidden;
             text-overflow: ellipsis;
             white-space: nowrap;
         }
         .customer-list-table td:nth-child(2) { /* Name column */
              max-width: 200px; /* Give more space to name */
              white-space: normal; /* Allow name to wrap */
         }
         .card-header i {
             margin-right: 5px;
         }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">

            <?php include 'includes/salesperson_sidebar.php'; ?>

            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">Administrar Clientes</h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                         <a href="generate_sale.php" class="btn btn-success btn-animated"><i class="fas fa-cash-register"></i> Volver a Generar Venta</a>
                    </div>
                </div>

                 <?php if ($message): ?>
                     <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
                         <?php echo htmlspecialchars($message); ?>
                         <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                     </div>
                 <?php endif; ?>

                <div class="card mb-4">
                    <div class="card-header"><i class="fas fa-user-plus"></i> Agregar Nuevo Cliente</div>
                    <div class="card-body">
                        <form action="add_customer.php" method="POST">
                             <input type="hidden" name="add_customer" value="1">
                            <div class="mb-3">
                                <label for="name" class="form-label">Nombre Completo <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="name" name="name" required value="<?php echo $name; ?>">
                            </div>
                            <div class="mb-3">
                                <label for="address" class="form-label">Dirección</label>
                                <input type="text" class="form-control" id="address" name="address" value="<?php echo $address; ?>">
                            </div>
                            <div class="mb-3">
                                <label for="phone" class="form-label">Teléfono</label>
                                <input type="text" class="form-control" id="phone" name="phone" value="<?php echo $phone; ?>">
                            </div>
                             <div class="mb-3">
                                 <label for="email" class="form-label">Correo Electrónico</label>
                                 <input type="email" class="form-control" id="email" name="email" value="<?php echo $email; ?>">
                             </div>
                             <button type="submit" class="btn btn-primary btn-animated"><i class="fas fa-save"></i> Guardar Cliente</button>
                        </form>
                    </div>
                </div>

                <div class="card mb-4">
                    <div class="card-header"><i class="fas fa-users"></i> Clientes Registrados</div>
                    <div class="card-body">
                        <?php if (empty($customers)): ?>
                            <p class="text-center text-muted">No hay clientes registrados aún.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-striped table-hover table-sm customer-list-table">
                                    <thead>
                                        <tr>
                                            <th>ID</th>
                                            <th>Nombre</th>
                                            <th>Dirección</th>
                                            <th>Teléfono</th>
                                            <th>Correo Electrónico</th>
                                            <th>Fecha Registro</th>
                                            <th>Acciones</th> </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($customers as $customer): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($customer['id']); ?></td>
                                                <td><?php echo htmlspecialchars($customer['name']); ?></td>
                                                <td><?php echo htmlspecialchars($customer['address'] ?? '-'); ?></td>
                                                <td><?php echo htmlspecialchars($customer['phone'] ?? '-'); ?></td>
                                                <td><?php echo htmlspecialchars($customer['email'] ?? '-'); ?></td>
                                                <td><?php echo htmlspecialchars($customer['created_at']); ?></td>
                                                <td>
                                                    <a href="generate_sale.php?customer_id=<?php echo $customer['id']; ?>" class="btn btn-sm btn-success me-1" title="Comenzar Venta con este Cliente"><i class="fas fa-cash-register"></i> Venta</a>
                                                    </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
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