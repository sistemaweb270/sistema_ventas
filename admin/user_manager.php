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

// Add New User (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_user'])) {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? ''; // Plain text password from form
    $role_id = trim($_POST['role_id'] ?? '');
    $store_id = trim($_POST['store_id'] ?? ''); // Can be NULL

    // Validate required fields
    if (empty($username) || empty($password) || empty($role_id)) {
        $message = 'Usuario, Contraseña y Rol son campos obligatorios.';
        $message_type = 'warning';
    } else {
        // Hash the password BEFORE storing
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        if ($hashed_password === false) {
             $message = 'Error al hashear la contraseña.';
             $message_type = 'danger';
        } else {

            // Handle potential empty string for store_id
            $store_id = filter_var($store_id, FILTER_VALIDATE_INT) ? $store_id : NULL;

            $sql = "INSERT INTO users (username, password, role_id, store_id) VALUES (?, ?, ?, ?)";
            if ($stmt = $conn->prepare($sql)) {
                $stmt->bind_param("ssii", $username, $hashed_password, $role_id, $store_id);
                if ($stmt->execute()) {
                    $message = 'Usuario agregado con éxito.';
                    $message_type = 'success';
                } else {
                    // Check for duplicate entry error on username
                    if ($conn->errno == 1062) { // MySQL error code for duplicate entry
                        $message = 'Error: El nombre de usuario "' . htmlspecialchars($username) . '" ya existe.';
                        $message_type = 'warning';
                    } else {
                         $message = 'Error al agregar el usuario: ' . $stmt->error;
                        $message_type = 'danger';
                    }
                }
                $stmt->close();
            } else {
                $message = 'Error de base de datos al preparar la consulta para agregar usuario: ' . $conn->error;
                $message_type = 'danger';
            }
        }
    }
     // Redirect after POST to prevent form resubmission
     header("Location: user_manager.php?message=" . urlencode($message) . "&type=" . urlencode($message_type));
     exit();
}

// Update User (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_user'])) {
    $id = $_POST['user_id'] ?? 0;
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? ''); // <<-- CORREGIDO: Añadimos trim() aquí
    $role_id = trim($_POST['role_id'] ?? '');
    $store_id = trim($_POST['store_id'] ?? ''); // Can be NULL


    if (empty($username) || empty($role_id) || $id <= 0) {
         $message = 'Datos incompletos o ID de usuario no válido para actualizar.';
         $message_type = 'warning';
    } else {
        // Handle potential empty string for store_id
        $store_id = filter_var($store_id, FILTER_VALIDATE_INT) ? $store_id : NULL;

        // Build SQL query dynamically based on whether password is being updated
        $sql = "UPDATE users SET username = ?, role_id = ?, store_id = ?, updated_at = CURRENT_TIMESTAMP";
        $types = "sii";
        $params = [$username, $role_id, $store_id];

        if (!empty($password)) {
            // Hash the new password if provided
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            if ($hashed_password === false) {
                 $message = 'Error al hashear la nueva contraseña.';
                 $message_type = 'danger';
                 // Redirect or handle error without attempting DB update
                 header("Location: user_manager.php?message=" . urlencode($message) . "&type=" . urlencode($message_type));
                 exit();
            }
            $sql .= ", password = ?";
            $types .= "s";
            $params[] = $hashed_password; // Add hashed password to params
        }

        $sql .= " WHERE id = ?";
        $types .= "i";
        $params[] = $id; // Add user ID to params

        if ($stmt = $conn->prepare($sql)) {
             // Dynamically bind parameters
             $bind_params = [];
             $bind_params[] = &$types; // First parameter is the types string
             for ($i = 0; $i < count($params); $i++) {
                 $bind_params[] = &$params[$i];
             }
             call_user_func_array([$stmt, 'bind_param'], $bind_params);

             if ($stmt->execute()) {
                 if ($stmt->affected_rows > 0 || $stmt->warning_count > 0) { // Check affected_rows or warnings (e.g., if username is same)
                    $message = 'Usuario actualizado con éxito.';
                    $message_type = 'success';
                 } else {
                      // This might happen if *nothing* was changed and no warning was generated
                      // or if the ID wasn't found, though that should give affected_rows = 0 without warning
                      $message = 'El usuario no fue modificado (datos iguales o ID no encontrado).';
                      $message_type = 'info';
                 }
            } else {
                 // Check for duplicate entry error on username
                 if ($conn->errno == 1062) { // MySQL error code for duplicate entry
                     $message = 'Error: El nombre de usuario "' . htmlspecialchars($username) . '" ya existe.';
                     $message_type = 'warning';
                 } else {
                    $message = 'Error al actualizar el usuario: ' . $stmt->error;
                    $message_type = 'danger';
                 }
            }
            $stmt->close();
        } else {
            $message = 'Error de base de datos al preparar la consulta para actualizar usuario: ' . $conn->error;
            $message_type = 'danger';
        }
    }
     // Redirect after POST
     header("Location: user_manager.php?message=" . urlencode($message) . "&type=" . urlencode($message_type));
     exit();
}


// Delete User (GET or POST) - Using GET for simplicity, better use POST in production
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['delete_user'])) {
    $id = $_GET['delete_user'] ?? 0;

     // Prevent deleting the currently logged-in admin user
     if (isset($_SESSION['user_id']) && $id > 0 && $id == $_SESSION['user_id']) { // Added isset($_SESSION['user_id']) check
         $message = 'No puedes eliminar tu propia cuenta de usuario.';
         $message_type = 'warning';
     } elseif ($id <= 0) {
         $message = 'ID de usuario no válido para eliminar.';
         $message_type = 'warning';
     } else {
         // TODO: Add confirmation step (e.g., via JavaScript)
         // TODO: Consider foreign key constraints (e.g., users have sales, till sessions, etc.)
         // The current FKs referencing users have ON DELETE RESTRICT or SET NULL.
         // RESTRICT will prevent deletion if sales or till sessions are linked.

         $sql = "DELETE FROM users WHERE id = ?";
         if ($stmt = $conn->prepare($sql)) {
             $stmt->bind_param("i", $id);
             if ($stmt->execute()) {
                 if ($stmt->affected_rows > 0) {
                    $message = 'Usuario eliminado con éxito.';
                    $message_type = 'success';
                 } else {
                     $message = 'No se encontró el usuario con el ID proporcionado.';
                     $message_type = 'info';
                 }
             } else {
                 // Check for foreign key constraint violation
                 if ($conn->errno == 1451) { // MySQL error code for FK constraint violation
                     $message = 'Error: No se puede eliminar el usuario porque tiene ventas, sesiones de caja u otros datos asociados. Elimine o reasigne los datos relacionados primero.';
                     $message_type = 'danger';
                 } else {
                     $message = 'Error al eliminar el usuario: ' . $stmt->error;
                     $message_type = 'danger';
                 }
             }
             $stmt->close();
         } else {
            $message = 'Error de base de datos al preparar la consulta para eliminar usuario: ' . $conn->error;
            $message_type = 'danger';
        }
     }
     // Redirect to clean URL after GET delete
     header("Location: user_manager.php?message=" . urlencode($message) . "&type=" . urlencode($message_type));
     exit();
}

// Handle messages passed via GET after a redirect
if (isset($_GET['message']) && isset($_GET['type'])) {
    $message = htmlspecialchars($_GET['message']);
    $message_type = htmlspecialchars($_GET['type']);
}


// --- Fetch Users for Display ---
$users = [];
$sql = "SELECT u.id, u.username, r.name AS role_name, u.store_id, s.name AS store_name, u.created_at
        FROM users u
        JOIN roles r ON u.role_id = r.id
        LEFT JOIN stores s ON u.store_id = s.id
        ORDER BY u.username ASC";
if ($result = $conn->query($sql)) {
    while ($row = $result->fetch_assoc()) {
        $users[] = $row;
    }
    $result->free(); // Free result set
} else {
    $message = 'Error al cargar la lista de usuarios: ' . $conn->error;
    $message_type = 'danger';
}

// --- Fetch Data for Dropdowns ---

// Fetch Roles for dropdowns
$roles = [];
$sql_roles = "SELECT id, name FROM roles ORDER BY name ASC";
if ($result_roles = $conn->query($sql_roles)) {
    while ($row_roles = $result_roles->fetch_assoc()) {
        $roles[] = $row_roles;
    }
    $result_roles->free(); // Free result set
} // Consider error handling

// Fetch Stores for dropdowns
$stores_list = []; // Using a different name to avoid conflict with $stores if used elsewhere
$sql_stores = "SELECT id, name FROM stores ORDER BY name ASC";
if ($result_store = $conn->query($sql_stores)) {
    while ($row_store = $result_store->fetch_assoc()) {
        $stores_list[] = $row_store;
    }
    $result_store->free(); // Free result set
} // Consider error handling


// Close DB connection (optional, depending on your config.php logic)
// $conn->close();

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestor de Usuarios - Panel de Administrador</title>
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
                    <h1 class="h2">Gestor de Usuarios</h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                         <button class="btn btn-success btn-animated" data-bs-toggle="modal" data-bs-target="#addUserModal">
                             <i class="fas fa-user-plus"></i> Agregar Usuario
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
                                <th>Usuario</th>
                                <th>Rol</th>
                                <th>Tienda</th>
                                <th>Fecha Creación</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($users) > 0): ?>
                                <?php foreach ($users as $user): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($user['id']); ?></td>
                                        <td><?php echo htmlspecialchars($user['username']); ?></td>
                                        <td><?php echo htmlspecialchars($user['role_name'] ?? 'N/A'); // Added ?? 'N/A' as safeguard ?></td>
                                        <td><?php echo htmlspecialchars($user['store_name'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($user['created_at']); ?></td>
                                        <td>
                                            <button class="btn btn-info btn-sm btn-animated edit-user-btn"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#editUserModal"
                                                    data-id="<?php echo htmlspecialchars($user['id']); ?>"
                                                    data-username="<?php echo htmlspecialchars($user['username']); ?>"
                                                    data-role-id="<?php echo htmlspecialchars($user['role_id'] ?? ''); // Used ?? '' to prevent Undefined array key warning if data is somehow missing ?>"
                                                    data-store-id="<?php echo htmlspecialchars((string)($user['store_id'] ?? '')); // Used (string)($user['store_id'] ?? '') to handle deprecation warning ?>">
                                                <i class="fas fa-edit"></i> Editar
                                            </button>
                                             <?php if (isset($_SESSION['user_id']) && $user['id'] != $_SESSION['user_id']): // Prevent deleting self ?>
                                                <a href="user_manager.php?delete_user=<?php echo htmlspecialchars($user['id']); ?>"
                                                   class="btn btn-danger btn-sm btn-animated"
                                                   onclick="return confirm('¿Estás seguro de eliminar el usuario <?php echo htmlspecialchars($user['username']); ?>?');">
                                                    <i class="fas fa-trash-alt"></i> Eliminar
                                                </a>
                                             <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="text-center">No hay usuarios registrados.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

            </main>
        </div>
    </div>

    <div class="modal fade" id="addUserModal" tabindex="-1" aria-labelledby="addUserModalLabel" aria-hidden="true">
      <div class="modal-dialog">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="addUserModalLabel">Agregar Nuevo Usuario</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <form action="user_manager.php" method="POST">
              <div class="modal-body">
                <input type="hidden" name="add_user" value="1">
                <div class="mb-3">
                  <label for="add-username" class="form-label">Nombre de Usuario</label>
                  <input type="text" class="form-control" id="add-username" name="username" required>
                </div>
                <div class="mb-3">
                  <label for="add-password" class="form-label">Contraseña</label>
                  <input type="password" class="form-control" id="add-password" name="password" required minlength="6"> </div>
                 <div class="mb-3">
                  <label for="add-role-id" class="form-label">Rol</label>
                  <select class="form-select" id="add-role-id" name="role_id" required>
                      <option value="">-- Seleccionar Rol --</option>
                      <?php foreach ($roles as $role): ?>
                          <option value="<?php echo $role['id']; ?>"><?php echo htmlspecialchars($role['name']); ?></option>
                      <?php endforeach; ?>
                  </select>
                </div>
                 <div class="mb-3">
                  <label for="add-store-id" class="form-label">Tienda Asignada (Opcional)</label>
                   <select class="form-select" id="add-store-id" name="store_id">
                      <option value="">-- Ninguna --</option>
                       <?php foreach ($stores_list as $store): ?>
                          <option value="<?php echo $store['id']; ?>"><?php echo htmlspecialchars($store['name']); ?></option>
                      <?php endforeach; ?>
                   </select>
                </div>
              </div>
              <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary">Guardar Usuario</button>
              </div>
          </form>
        </div>
      </div>
    </div>

    <div class="modal fade" id="editUserModal" tabindex="-1" aria-labelledby="editUserModalLabel" aria-hidden="true">
      <div class="modal-dialog">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="editUserModalLabel">Editar Usuario</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <form action="user_manager.php" method="POST">
              <div class="modal-body">
                <input type="hidden" name="update_user" value="1">
                <input type="hidden" name="user_id" id="edit-user-id">
                 <div class="mb-3">
                  <label for="edit-username" class="form-label">Nombre de Usuario</label>
                  <input type="text" class="form-control" id="edit-username" name="username" required>
                </div>
                 <div class="mb-3">
                  <label for="edit-password" class="form-label">Nueva Contraseña (Dejar vacío para no cambiar)</label>
                  <input type="password" class="form-control" id="edit-password" name="password" minlength="6"> <small class="form-text text-muted">Solo llena este campo si deseas cambiar la contraseña.</small>
                </div>
                 <div class="mb-3">
                  <label for="edit-role-id" class="form-label">Rol</label>
                  <select class="form-select" id="edit-role-id" name="role_id" required>
                      <option value="">-- Seleccionar Rol --</option>
                      <?php foreach ($roles as $role): ?>
                          <option value="<?php echo $role['id']; ?>"><?php echo htmlspecialchars($role['name']); ?></option>
                      <?php endforeach; ?>
                  </select>
                </div>
                 <div class="mb-3">
                  <label for="edit-store-id" class="form-label">Tienda Asignada (Opcional)</label>
                  <select class="form-select" id="edit-store-id" name="store_id">
                       <option value="">-- Ninguna --</option>
                       <?php foreach ($stores_list as $store): ?>
                          <option value="<?php echo $store['id']; ?>"><?php echo htmlspecialchars($store['name']); ?></option>
                      <?php endforeach; ?>
                   </select>
                </div>
              </div>
              <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary">Actualizar Usuario</button>
              </div>
          </form>
        </div>
      </div>
    </div>


    <script src="../vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
     <script src="../js/script.js"></script>
    <script>
        // Script para pasar datos al modal de edición de Usuario
        var editUserModal = document.getElementById('editUserModal');
        editUserModal.addEventListener('show.bs.modal', function (event) {
            // Botón que activó el modal
            var button = event.relatedTarget;

            // Extraer información de los atributos data-*
            var id = button.getAttribute('data-id');
            var username = button.getAttribute('data-username');
            var roleId = button.getAttribute('data-role-id'); // Will be "" if PHP echoed ?? ''
            var storeId = button.getAttribute('data-store-id'); // Will be "" if PHP echoed ?? '' and casted

            // Actualizar los campos del formulario en el modal
            var modalBodyInputId = editUserModal.querySelector('#edit-user-id');
            var modalBodyInputUsername = editUserModal.querySelector('#edit-username');
            var modalBodyInputPassword = editUserModal.querySelector('#edit-password'); // Get password field to clear it
            var modalBodySelectRole = editUserModal.querySelector('#edit-role-id');
             var modalBodySelectStore = editUserModal.querySelector('#edit-store-id');


            modalBodyInputId.value = id;
            modalBodyInputUsername.value = username;
            modalBodyInputPassword.value = ''; // IMPORTANT: Clear the password field for security
            modalBodySelectRole.value = roleId; // Bootstrap set value works for selects
             modalBodySelectStore.value = storeId; // Bootstrap set value works for selects

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