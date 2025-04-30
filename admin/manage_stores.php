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

// Add New Store (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_store'])) {
    $name = trim($_POST['name'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $phone = trim($_POST['phone'] ?? '');

    if (empty($name)) {
        $message = 'El nombre de la tienda no puede estar vacío.';
        $message_type = 'warning';
    } else {
        $sql = "INSERT INTO stores (name, address, phone) VALUES (?, ?, ?)";
        if ($stmt = $conn->prepare($sql)) {
            $stmt->bind_param("sss", $name, $address, $phone);
            if ($stmt->execute()) {
                $message = 'Tienda agregada con éxito.';
                $message_type = 'success';
            } else {
                // Check for duplicate entry error
                if ($conn->errno == 1062) { // MySQL error code for duplicate entry
                    $message = 'Error: El nombre de la tienda ya existe.';
                    $message_type = 'warning';
                } else {
                     $message = 'Error al agregar la tienda: ' . $stmt->error;
                    $message_type = 'danger';
                }
            }
            $stmt->close();
        } else {
            $message = 'Error de base de datos al preparar la consulta para agregar tienda: ' . $conn->error;
            $message_type = 'danger';
        }
    }
}

// Update Store (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_store'])) {
    $id = $_POST['store_id'] ?? 0;
    $name = trim($_POST['name'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $phone = trim($_POST['phone'] ?? '');

    if (empty($name) || $id <= 0) {
         $message = 'Datos incompletos para actualizar la tienda.';
         $message_type = 'warning';
    } else {
        $sql = "UPDATE stores SET name = ?, address = ?, phone = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?";
        if ($stmt = $conn->prepare($sql)) {
            $stmt->bind_param("sssi", $name, $address, $phone, $id);
             if ($stmt->execute()) {
                 if ($stmt->affected_rows > 0) {
                    $message = 'Tienda actualizada con éxito.';
                    $message_type = 'success';
                 } else {
                     $message = 'La tienda no fue modificada (datos iguales o ID no encontrado).';
                     $message_type = 'info';
                 }
            } else {
                // Check for duplicate entry error
                 if ($conn->errno == 1062) { // MySQL error code for duplicate entry
                     $message = 'Error: El nombre de la tienda ya existe.';
                    $message_type = 'warning';
                 } else {
                     $message = 'Error al actualizar la tienda: ' . $stmt->error;
                    $message_type = 'danger';
                 }
            }
            $stmt->close();
        } else {
            $message = 'Error de base de datos al preparar la consulta para actualizar tienda: ' . $conn->error;
            $message_type = 'danger';
        }
    }
}

// Delete Store (GET or POST) - Using GET for simplicity with a link, better use POST in production
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['delete_store'])) {
    $id = $_GET['delete_store'] ?? 0;

     if ($id <= 0) {
         $message = 'ID de tienda no válido para eliminar.';
         $message_type = 'warning';
     } else {
         // TODO: Add confirmation step (e.g., via JavaScript)
         // TODO: Consider foreign key constraints (e.g., restrict deletion if store has products, users, sales)
         // Depending on your DB structure and ON DELETE rules, you might need to handle related data first.
         // The current FKs have ON DELETE SET NULL or RESTRICT, so RESTRICT will prevent deletion if used.

         $sql = "DELETE FROM stores WHERE id = ?";
         if ($stmt = $conn->prepare($sql)) {
             $stmt->bind_param("i", $id);
             if ($stmt->execute()) {
                 if ($stmt->affected_rows > 0) {
                    $message = 'Tienda eliminada con éxito.';
                    $message_type = 'success';
                 } else {
                     $message = 'No se encontró la tienda con el ID proporcionado.';
                     $message_type = 'info';
                 }
             } else {
                 // Check for foreign key constraint violation
                 if ($conn->errno == 1451) { // MySQL error code for FK constraint violation
                     $message = 'Error: No se puede eliminar la tienda porque tiene productos, usuarios u otros datos asociados. Elimine primero los datos relacionados.';
                     $message_type = 'danger';
                 } else {
                     $message = 'Error al eliminar la tienda: ' . $stmt->error;
                     $message_type = 'danger';
                 }
             }
             $stmt->close();
         } else {
            $message = 'Error de base de datos al preparar la consulta para eliminar tienda: ' . $conn->error;
            $message_type = 'danger';
        }
     }
     // Redirect to clean URL after GET delete
     header("Location: manage_stores.php?message=" . urlencode($message) . "&type=" . urlencode($message_type));
     exit();
}

// Handle messages passed via GET after a redirect (e.g., from delete)
if (isset($_GET['message']) && isset($_GET['type'])) {
    $message = htmlspecialchars($_GET['message']);
    $message_type = htmlspecialchars($_GET['type']);
}


// --- Fetch Stores for Display ---
$stores = [];
$sql = "SELECT id, name, address, phone, created_at FROM stores ORDER BY name ASC";
if ($result = $conn->query($sql)) {
    while ($row = $result->fetch_assoc()) {
        $stores[] = $row;
    }
    $result->free();
} else {
    $message = 'Error al cargar la lista de tiendas: ' . $conn->error;
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
    <title>Administrar Tiendas - Panel de Administrador</title>
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
                    <h1 class="h2">Administrar Tiendas</h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                         <button class="btn btn-success btn-animated" data-bs-toggle="modal" data-bs-target="#addStoreModal">
                             <i class="fas fa-plus-circle"></i> Agregar Tienda
                         </button>
                    </div>
                </div>

                <?php if ($message): ?>
                    <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
                        <?php echo $message; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <div class="table-responsive">
                    <table class="table table-striped table-sm">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Nombre</th>
                                <th>Dirección</th>
                                <th>Teléfono</th>
                                <th>Fecha Creación</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($stores) > 0): ?>
                                <?php foreach ($stores as $store): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($store['id']); ?></td>
                                        <td><?php echo htmlspecialchars($store['name']); ?></td>
                                        <td><?php echo htmlspecialchars($store['address'] ?? ''); ?></td>
                                        <td><?php echo htmlspecialchars($store['phone'] ?? ''); ?></td>
                                        <td><?php echo htmlspecialchars($store['created_at']); ?></td>
                                        <td>
                                            <button class="btn btn-info btn-sm btn-animated edit-store-btn"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#editStoreModal"
                                                    data-id="<?php echo $store['id']; ?>"
                                                    data-name="<?php echo htmlspecialchars($store['name']); ?>"
                                                    data-address="<?php echo htmlspecialchars($store['address'] ?? ''); ?>"
                                                    data-phone="<?php echo htmlspecialchars($store['phone'] ?? ''); ?>">
                                                <i class="fas fa-edit"></i> Editar
                                            </button>
                                            <a href="manage_stores.php?delete_store=<?php echo $store['id']; ?>"
                                               class="btn btn-danger btn-sm btn-animated"
                                               onclick="return confirm('¿Estás seguro de eliminar esta tienda? Esta acción no se puede deshacer.');">
                                                <i class="fas fa-trash-alt"></i> Eliminar
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="text-center">No hay tiendas registradas.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

            </main>
        </div>
    </div>

    <div class="modal fade" id="addStoreModal" tabindex="-1" aria-labelledby="addStoreModalLabel" aria-hidden="true">
      <div class="modal-dialog">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="addStoreModalLabel">Agregar Nueva Tienda</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <form action="manage_stores.php" method="POST">
              <div class="modal-body">
                <input type="hidden" name="add_store" value="1">
                <div class="mb-3">
                  <label for="add-name" class="form-label">Nombre de la Tienda</label>
                  <input type="text" class="form-control" id="add-name" name="name" required>
                </div>
                 <div class="mb-3">
                  <label for="add-address" class="form-label">Dirección</label>
                  <input type="text" class="form-control" id="add-address" name="address">
                </div>
                <div class="mb-3">
                  <label for="add-phone" class="form-label">Teléfono</label>
                  <input type="text" class="form-control" id="add-phone" name="phone">
                </div>
              </div>
              <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary">Guardar Tienda</button>
              </div>
          </form>
        </div>
      </div>
    </div>

    <div class="modal fade" id="editStoreModal" tabindex="-1" aria-labelledby="editStoreModalLabel" aria-hidden="true">
      <div class="modal-dialog">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="editStoreModalLabel">Editar Tienda</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <form action="manage_stores.php" method="POST">
              <div class="modal-body">
                <input type="hidden" name="update_store" value="1">
                <input type="hidden" name="store_id" id="edit-store-id">
                <div class="mb-3">
                  <label for="edit-name" class="form-label">Nombre de la Tienda</label>
                  <input type="text" class="form-control" id="edit-name" name="name" required>
                </div>
                 <div class="mb-3">
                  <label for="edit-address" class="form-label">Dirección</label>
                  <input type="text" class="form-control" id="edit-address" name="address">
                </div>
                <div class="mb-3">
                  <label for="edit-phone" class="form-label">Teléfono</label>
                  <input type="text" class="form-control" id="edit-phone" name="phone">
                </div>
              </div>
              <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary">Actualizar Tienda</button>
              </div>
          </form>
        </div>
      </div>
    </div>


    <script src="../vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
     <script src="../js/script.js"></script>
    <script>
        // Script para pasar datos al modal de edición
        var editStoreModal = document.getElementById('editStoreModal');
        editStoreModal.addEventListener('show.bs.modal', function (event) {
            // Botón que activó el modal
            var button = event.relatedTarget;

            // Extraer información de los atributos data-*
            var id = button.getAttribute('data-id');
            var name = button.getAttribute('data-name');
            var address = button.getAttribute('data-address');
            var phone = button.getAttribute('data-phone');

            // Actualizar los campos del formulario en el modal
            var modalBodyInputId = editStoreModal.querySelector('#edit-store-id');
            var modalBodyInputName = editStoreModal.querySelector('#edit-name');
            var modalBodyInputAddress = editStoreModal.querySelector('#edit-address');
            var modalBodyInputPhone = editStoreModal.querySelector('#edit-phone');

            modalBodyInputId.value = id;
            modalBodyInputName.value = name;
            modalBodyInputAddress.value = address;
            modalBodyInputPhone.value = phone;
        });

         // Script para ocultar mensajes de alerta automáticamente (opcional)
         // window.setTimeout(function() {
         //     $(".alert").fadeTo(500, 0).slideUp(500, function(){
         //         $(this).remove();
         //     });
         // }, 4000); // Ocultar después de 4 segundos (requiere jQuery)

         // Alternativa simple en JS puro si no usas jQuery:
         const alerts = document.querySelectorAll('.alert');
         alerts.forEach(alert => {
             if (alert.classList.contains('alert-dismissible')) {
                 const bsAlert = new bootstrap.Alert(alert); // Usar componente JS de Bootstrap
                 setTimeout(() => {
                     bsAlert.close();
                 }, 5000); // Ocultar después de 5 segundos
             }
         });

    </script>
</body>
</html>