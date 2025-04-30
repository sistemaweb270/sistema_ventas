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

// --- Handle CRUD Operations ---

// Add New Payment Method (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_method'])) {
    $name = trim($_POST['name'] ?? '');

    if (empty($name)) {
        $message = 'El nombre del medio de pago no puede estar vacío.';
        $message_type = 'warning';
    } else {
        $sql = "INSERT INTO payment_methods (name) VALUES (?)";
        if ($stmt = $conn->prepare($sql)) {
            $stmt->bind_param("s", $name);
            if ($stmt->execute()) {
                $message = 'Medio de pago agregado con éxito.';
                $message_type = 'success';
            } else {
                // Check for duplicate entry error
                if ($conn->errno == 1062) { // MySQL error code for duplicate entry
                    $message = 'Error: El nombre del medio de pago ya existe.';
                    $message_type = 'warning';
                } else {
                     $message = 'Error al agregar el medio de pago: ' . $stmt->error;
                    $message_type = 'danger';
                }
            }
            $stmt->close();
        } else {
            $message = 'Error de base de datos al preparar la consulta para agregar medio de pago: ' . $conn->error;
            $message_type = 'danger';
        }
    }
}

// Update Payment Method (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_method'])) {
    $id = $_POST['method_id'] ?? 0;
    $name = trim($_POST['name'] ?? '');

    if (empty($name) || $id <= 0) {
         $message = 'Datos incompletos para actualizar el medio de pago.';
         $message_type = 'warning';
    } else {
        $sql = "UPDATE payment_methods SET name = ? WHERE id = ?";
        if ($stmt = $conn->prepare($sql)) {
            $stmt->bind_param("si", $name, $id);
             if ($stmt->execute()) {
                 if ($stmt->affected_rows > 0) {
                    $message = 'Medio de pago actualizado con éxito.';
                    $message_type = 'success';
                 } else {
                     $message = 'El medio de pago no fue modificado (nombre igual o ID no encontrado).';
                     $message_type = 'info';
                 }
            } else {
                // Check for duplicate entry error
                 if ($conn->errno == 1062) { // MySQL error code for duplicate entry
                     $message = 'Error: El nombre del medio de pago ya existe.';
                    $message_type = 'warning';
                 } else {
                     $message = 'Error al actualizar el medio de pago: ' . $stmt->error;
                    $message_type = 'danger';
                 }
            }
            $stmt->close();
        } else {
            $message = 'Error de base de datos al preparar la consulta para actualizar medio de pago: ' . $conn->error;
            $message_type = 'danger';
        }
    }
}

// Delete Payment Method (GET or POST) - Using GET for simplicity, better use POST in production
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['delete_method'])) {
    $id = $_GET['delete_method'] ?? 0;

     if ($id <= 0) {
         $message = 'ID de medio de pago no válido para eliminar.';
         $message_type = 'warning';
     } else {
         // TODO: Add confirmation step (e.g., via JavaScript)
         // TODO: Consider foreign key constraints (e.g., restrict deletion if method is used in sales)
         // The current FK on sales references payment_methods with ON DELETE SET NULL.
         // If ON DELETE SET NULL, deleting a method will set payment_method_id to NULL for sales using it.
         // If you changed it to RESTRICT, deletion would fail here if sales are linked.

         $sql = "DELETE FROM payment_methods WHERE id = ?";
         if ($stmt = $conn->prepare($sql)) {
             $stmt->bind_param("i", $id);
             if ($stmt->execute()) {
                 if ($stmt->affected_rows > 0) {
                    $message = 'Medio de pago eliminado con éxito.';
                    $message_type = 'success';
                 } else {
                     $message = 'No se encontró el medio de pago con el ID proporcionado.';
                     $message_type = 'info';
                 }
             } else {
                 // Check for foreign key constraint violation (if you changed ON DELETE to RESTRICT)
                 if ($conn->errno == 1451) {
                     $message = 'Error: No se puede eliminar el medio de pago porque está asociado a ventas existentes. Actualice las ventas primero.';
                     $message_type = 'danger';
                 } else {
                     $message = 'Error al eliminar el medio de pago: ' . $stmt->error;
                     $message_type = 'danger';
                 }
             }
             $stmt->close();
         } else {
            $message = 'Error de base de datos al preparar la consulta para eliminar medio de pago: ' . $conn->error;
            $message_type = 'danger';
        }
     }
     // Redirect to clean URL after GET delete
     header("Location: payment_methods.php?message=" . urlencode($message) . "&type=" . urlencode($message_type));
     exit();
}

// Handle messages passed via GET after a redirect (e.g., from delete)
if (isset($_GET['message']) && isset($_GET['type'])) {
    $message = htmlspecialchars($_GET['message']);
    $message_type = htmlspecialchars($_GET['type']);
}


// --- Fetch Payment Methods for Display ---
$payment_methods = [];
$sql = "SELECT id, name FROM payment_methods ORDER BY name ASC";
if ($result = $conn->query($sql)) {
    while ($row = $result->fetch_assoc()) {
        $payment_methods[] = $row;
    }
    $result->free();
} else {
    $message = 'Error al cargar la lista de medios de pago: ' . $conn->error;
    $message_type = 'danger';
}

// Close DB connection (optional, depending on your config.php logic)
// $conn->close();

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Medios de Pago - Panel de Administrador</title>
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
                    <h1 class="h2">Medios de Pago</h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                         <button class="btn btn-success btn-animated" data-bs-toggle="modal" data-bs-target="#addMethodModal">
                             <i class="fas fa-plus-circle"></i> Agregar Medio de Pago
                         </button>
                    </div>
                </div>

                <?php if ($message): ?>
                    <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
                        <?php echo htmlspecialchars($message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <div class="table-responsive">
                    <table class="table table-striped table-sm">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Nombre</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($payment_methods) > 0): ?>
                                <?php foreach ($payment_methods as $method): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($method['id']); ?></td>
                                        <td><?php echo htmlspecialchars($method['name']); ?></td>
                                        <td>
                                            <button class="btn btn-info btn-sm btn-animated edit-method-btn"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#editMethodModal"
                                                    data-id="<?php echo $method['id']; ?>"
                                                    data-name="<?php echo htmlspecialchars($method['name']); ?>">
                                                <i class="fas fa-edit"></i> Editar
                                            </button>
                                            <a href="payment_methods.php?delete_method=<?php echo $method['id']; ?>"
                                               class="btn btn-danger btn-sm btn-animated"
                                               onclick="return confirm('¿Estás seguro de eliminar este medio de pago? Las ventas asociadas quedarán sin medio de pago.');"> <i class="fas fa-trash-alt"></i> Eliminar
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="3" class="text-center">No hay medios de pago registrados.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

            </main>
        </div>
    </div>

    <div class="modal fade" id="addMethodModal" tabindex="-1" aria-labelledby="addMethodModalLabel" aria-hidden="true">
      <div class="modal-dialog">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="addMethodModalLabel">Agregar Nuevo Medio de Pago</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <form action="payment_methods.php" method="POST">
              <div class="modal-body">
                <input type="hidden" name="add_method" value="1">
                <div class="mb-3">
                  <label for="add-name" class="form-label">Nombre del Medio de Pago</label>
                  <input type="text" class="form-control" id="add-name" name="name" required>
                </div>
              </div>
              <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary">Guardar Medio de Pago</button>
              </div>
          </form>
        </div>
      </div>
    </div>

    <div class="modal fade" id="editMethodModal" tabindex="-1" aria-labelledby="editMethodModalLabel" aria-hidden="true">
      <div class="modal-dialog">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="editMethodModalLabel">Editar Medio de Pago</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <form action="payment_methods.php" method="POST">
              <div class="modal-body">
                <input type="hidden" name="update_method" value="1">
                <input type="hidden" name="method_id" id="edit-method-id">
                <div class="mb-3">
                  <label for="edit-name" class="form-label">Nombre del Medio de Pago</label>
                  <input type="text" class="form-control" id="edit-name" name="name" required>
                </div>
              </div>
              <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary">Actualizar Medio de Pago</button>
              </div>
          </form>
        </div>
      </div>
    </div>


    <script src="../vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
     <script src="../js/script.js"></script>
    <script>
        // Script para pasar datos al modal de edición de Medio de Pago
        var editMethodModal = document.getElementById('editMethodModal');
        editMethodModal.addEventListener('show.bs.modal', function (event) {
            // Botón que activó el modal
            var button = event.relatedTarget;

            // Extraer información de los atributos data-*
            var id = button.getAttribute('data-id');
            var name = button.getAttribute('data-name');

            // Actualizar los campos del formulario en el modal
            var modalBodyInputId = editMethodModal.querySelector('#edit-method-id');
            var modalBodyInputName = editMethodModal.querySelector('#edit-name');

            modalBodyInputId.value = id;
            modalBodyInputName.value = name;
        });

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