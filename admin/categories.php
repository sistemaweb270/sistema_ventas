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

// Add New Category (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_category'])) {
    $name = trim($_POST['name'] ?? '');

    if (empty($name)) {
        $message = 'El nombre de la categoría no puede estar vacío.';
        $message_type = 'warning';
    } else {
        $sql = "INSERT INTO categories (name) VALUES (?)";
        if ($stmt = $conn->prepare($sql)) {
            $stmt->bind_param("s", $name);
            if ($stmt->execute()) {
                $message = 'Categoría agregada con éxito.';
                $message_type = 'success';
            } else {
                // Check for duplicate entry error
                if ($conn->errno == 1062) { // MySQL error code for duplicate entry
                    $message = 'Error: El nombre de la categoría ya existe.';
                    $message_type = 'warning';
                } else {
                     $message = 'Error al agregar la categoría: ' . $stmt->error;
                    $message_type = 'danger';
                }
            }
            $stmt->close();
        } else {
            $message = 'Error de base de datos al preparar la consulta para agregar categoría: ' . $conn->error;
            $message_type = 'danger';
        }
    }
}

// Update Category (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_category'])) {
    $id = $_POST['category_id'] ?? 0;
    $name = trim($_POST['name'] ?? '');

    if (empty($name) || $id <= 0) {
         $message = 'Datos incompletos para actualizar la categoría.';
         $message_type = 'warning';
    } else {
        $sql = "UPDATE categories SET name = ? WHERE id = ?";
        if ($stmt = $conn->prepare($sql)) {
            $stmt->bind_param("si", $name, $id);
             if ($stmt->execute()) {
                 if ($stmt->affected_rows > 0) {
                    $message = 'Categoría actualizada con éxito.';
                    $message_type = 'success';
                 } else {
                     $message = 'La categoría no fue modificada (nombre igual o ID no encontrado).';
                     $message_type = 'info';
                 }
            } else {
                // Check for duplicate entry error
                 if ($conn->errno == 1062) { // MySQL error code for duplicate entry
                     $message = 'Error: El nombre de la categoría ya existe.';
                    $message_type = 'warning';
                 } else {
                     $message = 'Error al actualizar la categoría: ' . $stmt->error;
                    $message_type = 'danger';
                 }
            }
            $stmt->close();
        } else {
            $message = 'Error de base de datos al preparar la consulta para actualizar categoría: ' . $conn->error;
            $message_type = 'danger';
        }
    }
}

// Delete Category (GET or POST) - Using GET for simplicity, better use POST in production
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['delete_category'])) {
    $id = $_GET['delete_category'] ?? 0;

     if ($id <= 0) {
         $message = 'ID de categoría no válido para eliminar.';
         $message_type = 'warning';
     } else {
         // TODO: Add confirmation step (e.g., via JavaScript)
         // TODO: Consider foreign key constraints (e.g., restrict deletion if category has products)
         // The current FK on products references categories with ON DELETE SET NULL.
         // If ON DELETE SET NULL, deleting a category will set category_id to NULL for related products.
         // If you changed it to RESTRICT, deletion would fail here if products are linked.

         $sql = "DELETE FROM categories WHERE id = ?";
         if ($stmt = $conn->prepare($sql)) {
             $stmt->bind_param("i", $id);
             if ($stmt->execute()) {
                 if ($stmt->affected_rows > 0) {
                    $message = 'Categoría eliminada con éxito.';
                    $message_type = 'success';
                 } else {
                     $message = 'No se encontró la categoría con el ID proporcionado.';
                     $message_type = 'info';
                 }
             } else {
                 // Check for foreign key constraint violation (if you changed ON DELETE to RESTRICT)
                 if ($conn->errno == 1451) {
                     $message = 'Error: No se puede eliminar la categoría porque tiene productos asociados. Elimine o actualice los productos primero.';
                     $message_type = 'danger';
                 } else {
                     $message = 'Error al eliminar la categoría: ' . $stmt->error;
                     $message_type = 'danger';
                 }
             }
             $stmt->close();
         } else {
            $message = 'Error de base de datos al preparar la consulta para eliminar categoría: ' . $conn->error;
            $message_type = 'danger';
        }
     }
     // Redirect to clean URL after GET delete
     header("Location: categories.php?message=" . urlencode($message) . "&type=" . urlencode($message_type));
     exit();
}

// Handle messages passed via GET after a redirect (e.g., from delete)
if (isset($_GET['message']) && isset($_GET['type'])) {
    $message = htmlspecialchars($_GET['message']);
    $message_type = htmlspecialchars($_GET['type']);
}


// --- Fetch Categories for Display ---
$categories = [];
$sql = "SELECT id, name FROM categories ORDER BY name ASC";
if ($result = $conn->query($sql)) {
    while ($row = $result->fetch_assoc()) {
        $categories[] = $row;
    }
    $result->free();
} else {
    $message = 'Error al cargar la lista de categorías: ' . $conn->error;
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
    <title>Categorías - Panel de Administrador</title>
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
                    <h1 class="h2">Categorías</h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                         <button class="btn btn-success btn-animated" data-bs-toggle="modal" data-bs-target="#addCategoryModal">
                             <i class="fas fa-plus-circle"></i> Agregar Categoría
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
                            <?php if (count($categories) > 0): ?>
                                <?php foreach ($categories as $category): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($category['id']); ?></td>
                                        <td><?php echo htmlspecialchars($category['name']); ?></td>
                                        <td>
                                            <button class="btn btn-info btn-sm btn-animated edit-category-btn"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#editCategoryModal"
                                                    data-id="<?php echo $category['id']; ?>"
                                                    data-name="<?php echo htmlspecialchars($category['name']); ?>">
                                                <i class="fas fa-edit"></i> Editar
                                            </button>
                                            <a href="categories.php?delete_category=<?php echo $category['id']; ?>"
                                               class="btn btn-danger btn-sm btn-animated"
                                               onclick="return confirm('¿Estás seguro de eliminar esta categoría? Los productos asociados quedarán sin categoría.');"> <i class="fas fa-trash-alt"></i> Eliminar
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="3" class="text-center">No hay categorías registradas.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

            </main>
        </div>
    </div>

    <div class="modal fade" id="addCategoryModal" tabindex="-1" aria-labelledby="addCategoryModalLabel" aria-hidden="true">
      <div class="modal-dialog">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="addCategoryModalLabel">Agregar Nueva Categoría</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <form action="categories.php" method="POST">
              <div class="modal-body">
                <input type="hidden" name="add_category" value="1">
                <div class="mb-3">
                  <label for="add-name" class="form-label">Nombre de la Categoría</label>
                  <input type="text" class="form-control" id="add-name" name="name" required>
                </div>
              </div>
              <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary">Guardar Categoría</button>
              </div>
          </form>
        </div>
      </div>
    </div>

    <div class="modal fade" id="editCategoryModal" tabindex="-1" aria-labelledby="editCategoryModalLabel" aria-hidden="true">
      <div class="modal-dialog">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="editCategoryModalLabel">Editar Categoría</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <form action="categories.php" method="POST">
              <div class="modal-body">
                <input type="hidden" name="update_category" value="1">
                <input type="hidden" name="category_id" id="edit-category-id">
                <div class="mb-3">
                  <label for="edit-name" class="form-label">Nombre de la Categoría</label>
                  <input type="text" class="form-control" id="edit-name" name="name" required>
                </div>
              </div>
              <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary">Actualizar Categoría</button>
              </div>
          </form>
        </div>
      </div>
    </div>


    <script src="../vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
     <script src="../js/script.js"></script>
    <script>
        // Script para pasar datos al modal de edición de Categoría
        var editCategoryModal = document.getElementById('editCategoryModal');
        editCategoryModal.addEventListener('show.bs.modal', function (event) {
            // Botón que activó el modal
            var button = event.relatedTarget;

            // Extraer información de los atributos data-*
            var id = button.getAttribute('data-id');
            var name = button.getAttribute('data-name');

            // Actualizar los campos del formulario en el modal
            var modalBodyInputId = editCategoryModal.querySelector('#edit-category-id');
            var modalBodyInputName = editCategoryModal.querySelector('#edit-name');

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