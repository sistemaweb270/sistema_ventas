<?php
session_start();
require_once '../includes/config.php';
require_once '../includes/session_check.php'; // Check if logged in

// Check if user is Admin
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Admin') {
     header("Location: ../index.php"); // Redirect to index or login if not Admin
     exit();
}

$message = '';
$message_type = ''; // 'success', 'danger', 'warning', 'info'

// Define the directory for product images relative to the admin/ directory
$upload_dir = '../uploads/product_images/';

// Ensure the upload directory exists and is writable
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0775, true); // Create directory recursively with permissions
}
if (!is_writable($upload_dir)) {
     $message = 'Error: El directorio de carga de imágenes (' . htmlspecialchars($upload_dir) . ') no tiene permisos de escritura.';
     $message_type = 'danger';
}


// --- Handle Add/Edit Product (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['add_product']) || isset($_POST['edit_product']))) {

    $product_id = $_POST['product_id'] ?? 0; // Will be 0 for add, > 0 for edit
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? ''); // Nuevo campo
    $price = trim($_POST['price'] ?? '');
    $stock = trim($_POST['stock'] ?? '');
    $category_id = $_POST['category_id'] ?? 0;
    $store_id = $_POST['store_id'] ?? NULL; // Can be NULL
    $current_image_path = $_POST['current_image_path'] ?? ''; // Hidden field for current image on edit
     $is_active = isset($_POST['is_active']) ? 1 : 0; // Nuevo campo: 1 si el checkbox está marcado, 0 si no

    $is_edit = ($product_id > 0);

    // Validate input
    if (empty($name) || !is_numeric($price) || $price < 0 || !filter_var($stock, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) || $category_id <= 0 || !filter_var($category_id, FILTER_VALIDATE_INT)) {
        $message = 'Por favor, complete todos los campos obligatorios y verifique los valores.';
        $message_type = 'warning';
    } else {
        // Handle image upload
        $image_path = $current_image_path; // Start with the current image path (for edit)
        $upload_success = true; // Flag to track if file upload was attempted and succeeded

        // Check if a new file was uploaded
        if (isset($_FILES['product_image']) && $_FILES['product_image']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['product_image'];
            $file_name = $file['name'];
            $file_tmp = $file['tmp_name'];
            $file_size = $file['size'];
            $file_error = $file['error'];
            $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

            // Allowed file extensions
            $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];

            if (in_array($file_ext, $allowed_extensions)) {
                // Check file size (e.g., max 5MB)
                if ($file_size <= 5 * 1024 * 1024) { // 5MB
                    // Generate a unique filename
                    $new_file_name = uniqid('product_', true) . '.' . $file_ext;
                    $file_destination = $upload_dir . $new_file_name;

                    // Move the uploaded file
                    if (move_uploaded_file($file_tmp, $file_destination)) {
                        // File uploaded successfully, set the new image path relative to the project root
                        $image_path = 'uploads/product_images/' . $new_file_name; // Save this path in DB

                        // If in edit mode and a new image was uploaded, delete the old one (optional but good practice)
                        if ($is_edit && !empty($current_image_path) && $current_image_path !== 'path/to/default/image.jpg' && file_exists('../' . $current_image_path)) {
                             // Only delete if it's not the default image and the file exists
                             unlink('../' . $current_image_path);
                        }

                    } else {
                        $message = 'Error al mover el archivo de imagen cargado.';
                        $message_type = 'danger';
                        $upload_success = false; // Mark upload as failed
                    }
                } else {
                    $message = 'El archivo de imagen es demasiado grande (máx 5MB).';
                    $message_type = 'warning';
                     $upload_success = false; // Mark upload as failed
                }
            } else {
                $message = 'Tipo de archivo de imagen no permitido. Solo JPG, JPEG, PNG y GIF.';
                $message_type = 'warning';
                 $upload_success = false; // Mark upload as failed
            }
        } elseif (isset($_FILES['product_image']) && $_FILES['product_image']['error'] !== UPLOAD_ERR_NO_FILE) {
             // Handle other upload errors (e.g., UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE, etc.)
             $php_upload_errors = [
                 UPLOAD_ERR_INI_SIZE => 'El archivo cargado excede la directiva upload_max_filesize en php.ini.',
                 UPLOAD_ERR_FORM_SIZE => 'El archivo cargado excede la directiva MAX_FILE_SIZE que estaba especificada en el formulario HTML.',
                 UPLOAD_ERR_PARTIAL => 'El archivo fue sólo parcialmente cargado.',
                 UPLOAD_ERR_NO_TMP_DIR => 'Falta una carpeta temporal.',
                 UPLOAD_ERR_CANT_WRITE => 'No se pudo escribir el archivo en el disco.',
                 UPLOAD_ERR_EXTENSION => 'Una extensión de PHP detuvo la carga del archivo.',
             ];
             $message = 'Error de carga de archivo: ' . ($php_upload_errors[$file_error] ?? 'Error desconocido.');
             $message_type = 'danger';
              $upload_success = false; // Mark upload as failed
        }
         // If no file was uploaded (UPLOAD_ERR_NO_FILE), image_path remains the current_image_path or default


        // Proceed with DB operation only if no critical file upload errors occurred
        if ($upload_success && ($message_type !== 'danger' || empty($message_type))) { // Only proceed if upload didn't result in a hard error

            if ($is_edit) {
                // --- Update Product ---
                // CORREGIDO: Añadido is_active a la consulta UPDATE
                $sql = "UPDATE products SET name = ?, description = ?, price = ?, stock = ?, category_id = ?, store_id = ?, image_path = ?, is_active = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?";
                 if ($stmt = $conn->prepare($sql)) {
                     // Handle store_id which can be NULL
                     // Convert empty string from select option to NULL
                     $store_id = ($store_id === '') ? NULL : $store_id;
                      // Bind store_id: use 'i' for integer, if it's NULL, mysqli will handle it
                      // CORREGIDO: Corregida cadena de tipos para el UPDATE: ssdiisiii
                     $stmt->bind_param("ssdiisiii", $name, $description, $price, $stock, $category_id, $store_id, $image_path, $is_active, $product_id); // 's','s','d','i','i','i','s','i','i'

                    if ($stmt->execute()) {
                        $message = 'Producto actualizado con éxito.' . (!empty($message) ? ' ' . $message : ''); // Append potential warning from upload
                        $message_type = ($message_type === 'warning') ? 'warning' : 'success'; // Keep warning if upload had one
                    } else {
                        $message = 'Error al actualizar el producto: ' . $stmt->error;
                        $message_type = 'danger';
                    }
                    $stmt->close();
                 } else {
                    $message = 'Error de base de datos al preparar la actualización: ' . $conn->error;
                    $message_type = 'danger';
                 }

            } else {
                // --- Add Product ---
                 // Default image path if none uploaded or upload failed
                 $image_path = ($upload_success && !empty($image_path)) ? $image_path : 'path/to/default/image.jpg'; // Use default only if no upload or upload failed

                // CORREGIDO: Añadido is_active a la consulta INSERT
                $sql = "INSERT INTO products (name, description, price, stock, category_id, store_id, image_path, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                 if ($stmt = $conn->prepare($sql)) {
                      // Handle store_id which can be NULL
                       // Convert empty string from select option to NULL
                     $store_id = ($store_id === '') ? NULL : $store_id;
                      // Bind store_id: use 'i' for integer, if it's NULL, mysqli will handle it
                      // CORREGIDO: Corregida cadena de tipos para el INSERT: ssdiissi
                      $stmt->bind_param("ssdiissi", $name, $description, $price, $stock, $category_id, $store_id, $image_path, $is_active); // 's','s','d','i','i','i','s','i'

                     if ($stmt->execute()) {
                         $message = 'Producto agregado con éxito.' . (!empty($message) ? ' ' . $message : ''); // Append potential warning from upload
                         $message_type = ($message_type === 'warning') ? 'warning' : 'success'; // Keep warning if upload had one
                         // Optional: Clear form fields after successful add if not redirecting
                     } else {
                         $message = 'Error al agregar el producto: ' . $stmt->error;
                         $message_type = 'danger';
                     }
                     $stmt->close();
                 } else {
                     $message = 'Error de base de datos al preparar la inserción: ' . $conn->error;
                     $message_type = 'danger';
                 }
            }
        }

         // Redirect after POST to prevent form resubmission and show message
         // Append message to URL
         header("Location: inventory.php?message=" . urlencode($message) . "&type=" . urlencode($message_type));
         exit();
    }
}

// --- Handle Delete/Deactivate Product (GET - simple example, POST is safer) ---
// Modificamos la lógica para DELETAR si no tiene ventas, DESACTIVAR si tiene
if (isset($_GET['action']) && ($_GET['action'] === 'delete' || $_GET['action'] === 'deactivate')) {
    $product_id_to_process = $_GET['product_id'] ?? 0;
    $action_type = $_GET['action']; // 'delete' or 'deactivate'

     if ($product_id_to_process > 0 && filter_var($product_id_to_process, FILTER_VALIDATE_INT)) {

        $product_has_sales = false;
        // Check if the product is associated with any sales items
        $sql_check_sales = "SELECT COUNT(*) AS sales_count FROM sale_items WHERE product_id = ?";
        if ($stmt_check_sales = $conn->prepare($sql_check_sales)) {
            $stmt_check_sales->bind_param("i", $product_id_to_process);
            $stmt_check_sales->execute();
            $result_check_sales = $stmt_check_sales->get_result();
            if ($row_sales_count = $result_check_sales->fetch_assoc()) {
                 if ($row_sales_count['sales_count'] > 0) {
                     $product_has_sales = true;
                 }
            }
            $result_check_sales->free();
            $stmt_check_sales->close();
        } else {
             // Handle database error during sales check
             $message = 'Error de base de datos al verificar ventas asociadas.';
             $message_type = 'danger';
             // Skip further processing if check fails critically
             header("Location: inventory.php?message=" . urlencode($message) . "&type=" . urlencode($message_type));
             exit();
        }


        if ($product_has_sales) {
            // If product has sales, DEACTIVATE it instead of deleting
            $sql_deactivate = "UPDATE products SET is_active = 0, updated_at = CURRENT_TIMESTAMP WHERE id = ?";
            if ($stmt_deactivate = $conn->prepare($sql_deactivate)) {
                 $stmt_deactivate->bind_param("i", $product_id_to_process);
                 if ($stmt_deactivate->execute()) {
                     $message = 'El producto tiene ventas asociadas y ha sido desactivado.';
                     $message_type = 'warning'; // Usar warning para indicar que no se eliminó
                 } else {
                     $message = 'Error al desactivar el producto: ' . $stmt_deactivate->error;
                     $message_type = 'danger';
                 }
                 $stmt_deactivate->close();
            } else {
                $message = 'Error de base de datos al preparar la desactivación: ' . $conn->error;
                $message_type = 'danger';
            }

        } elseif ($action_type === 'delete' && !$product_has_sales) {
            // If product has NO sales and action is explicitly 'delete', proceed with deletion
            // Optional: Fetch image path before deleting the product to delete the file
            $sql_get_image = "SELECT image_path FROM products WHERE id = ?";
            $image_to_delete = null;
            if ($stmt_get_image = $conn->prepare($sql_get_image)) {
                $stmt_get_image->bind_param("i", $product_id_to_process);
                $stmt_get_image->execute();
                $result_image = $stmt_get_image->get_result();
                if ($row_image = $result_image->fetch_assoc()) {
                     $image_to_delete = $row_image['image_path'];
                }
                $result_image->free();
                $stmt_get_image->close();
            }

            // Perform the delete
            $sql_delete = "DELETE FROM products WHERE id = ?";
            if ($stmt_delete = $conn->prepare($sql_delete)) {
                $stmt_delete->bind_param("i", $product_id_to_process);

                try {
                    if ($stmt_delete->execute()) {
                         // If delete was successful, try to delete the image file
                         if ($image_to_delete && !empty($image_to_delete) && $image_to_delete !== 'path/to/default/image.jpg' && file_exists('../' . $image_to_delete)) {
                              // Only delete if it's not the default image and the file exists
                              unlink('../' . $image_to_delete);
                         }

                        $message = 'Producto eliminado con éxito.';
                        $message_type = 'success';
                    } else {
                        // This else block might be reached for non-constraint related execute errors
                        $message = 'Error al eliminar el producto: ' . $stmt_delete->error;
                        $message_type = 'danger';
                    }
                } catch (mysqli_sql_exception $e) {
                     // Although we checked for sales, a race condition or other FK issue is possible
                     // This catch block ensures we don't get a fatal error
                     $message = 'Error inesperado al intentar eliminar el producto: ' . $e->getMessage();
                     $message_type = 'danger';
                     // Optionally log the error: error_log($e->getMessage());
                }
                $stmt_delete->close();
            } else {
                $message = 'Error de base de datos al preparar la eliminación: ' . $conn->error;
                $message_type = 'danger';
            }
        } else {
             // This case shouldn't typically happen if logic is followed
             $message = 'Acción no válida para este producto.';
             $message_type = 'warning';
        }


     } else {
          $message = 'ID de producto no válido para procesar.';
          $message_type = 'warning';
     }

    // Redirect after GET to show message and clear parameter
     header("Location: inventory.php?message=" . urlencode($message) . "&type=" . urlencode($message_type));
     exit();
}


// Handle messages passed via GET (e.g., after POST redirect)
if (isset($_GET['message']) && isset($_GET['type'])) {
    $message = htmlspecialchars($_GET['message']);
    $message_type = htmlspecialchars($_GET['type']);
}


// --- Fetch Data for Display ---

// Fetch Products for display
// CORREGIDO: Seleccionar description, image_path, p.category_id, p.store_id Y is_active
$products = [];
$sql_products = "SELECT p.id, p.name, p.description, p.price, p.stock, p.image_path,
                       p.category_id,
                       p.store_id,
                       p.is_active, -- CORREGIDO: Añadida la selección de is_active
                       c.name AS category_name, s.name AS store_name,
                       p.created_at, p.updated_at
                FROM products p
                JOIN categories c ON p.category_id = c.id
                LEFT JOIN stores s ON p.store_id = s.id
                ORDER BY p.name ASC";

if ($result_prod = $conn->query($sql_products)) {
    while ($row_prod = $result_prod->fetch_assoc()) {
        $products[] = $row_prod;
    }
    $result_prod->free();
} else {
    // Handle error fetching products for display
     // This might overwrite a message from POST/GET, handle carefully
     // error_log("Error fetching products for inventory display: " . $conn->error); // Log error
     // $message = 'Error de base de datos al cargar productos: ' . $conn->error;
     // $message_type = 'danger';
}

// Fetch Categories for dropdown
$categories = [];
$sql_categories = "SELECT id, name FROM categories ORDER BY name ASC";
if ($result_cat = $conn->query($sql_categories)) {
    while ($row_cat = $result_cat->fetch_assoc()) {
        $categories[] = $row_cat;
    }
    $result_cat->free();
} // Consider error handling


// Fetch Stores for dropdown (optional if store_id is used)
$stores = [];
$sql_stores = "SELECT id, name FROM stores ORDER BY name ASC";
if ($result_store_dd = $conn->query($sql_stores)) {
    while ($row_store_dd = $result_store_dd->fetch_assoc()) {
        $stores[] = $row_store_dd;
    }
    $result_store_dd->free();
} // Consider error handling


// Close DB connection (optional)
// $conn->close();

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Inventario - Panel de Admin</title>
    <link rel="stylesheet" href="../vendor/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="../vendor/font-awesome/css/all.min.css">
    <link rel="stylesheet" href="../css/style.css">
    <style>
        /* Estilos para la tabla de inventario */
        .table-inventory img {
            max-width: 50px; /* Tamaño miniatura para la imagen en la tabla */
            height: auto;
            border-radius: 3px;
            vertical-align: middle; /* Alinea la imagen verticalmente en la celda */
        }
         /* Ajuste para que las celdas de imagen no sean enormes */
         .table-inventory td:nth-child(2) { /* Selecciona la segunda columna (Imagen) */
             width: 60px; /* Ancho fijo para la columna de imagen */
             padding: 5px;
         }
          .table-inventory th:nth-child(2) { /* Selecciona el encabezado de la segunda columna */
             width: 60px; /* Ancho fijo para el encabezado de imagen */
             padding: 5px;
         }
          /* Estilo para la descripción en la tabla */
          .table-inventory td:nth-child(4) { /* Selecciona la cuarta columna (Descripción) */
              max-width: 200px; /* Limita el ancho de la columna de descripción */
              overflow: hidden;
              text-overflow: ellipsis;
              white-space: nowrap; /* Evita saltos de línea si no se trunca */
          }
           .table-inventory th:nth-child(4) { /* Selecciona el encabezado de la cuarta columna */
              max-width: 200px; /* Limita el ancho del encabezado de descripción */
          }
          /* Estilo para el estado activo/inactivo */
           .badge-active { background-color: #28a745; color: white; } /* Verde */
           .badge-inactive { background-color: #dc3545; color: white; } /* Rojo */

    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">

            <?php include 'includes/admin_sidebar.php'; ?>

            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">Gestión de Inventario</h1>
                     <div class="btn-toolbar mb-2 mb-md-0">
                         <button class="btn btn-success btn-animated" data-bs-toggle="modal" data-bs-target="#addProductModal"><i class="fas fa-plus"></i> Agregar Nuevo Producto</button>
                     </div>
                </div>

                 <?php if ($message): ?>
                    <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
                        <?php echo htmlspecialchars($message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <div class="card">
                    <div class="card-header"><i class="fas fa-box"></i> Listado de Productos</div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped table-sm table-inventory">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Imagen</th> <th>Nombre</th>
                                        <th>Descripción</th> <th>Precio</th>
                                        <th>Stock</th>
                                        <th>Categoría</th>
                                        <th>Tienda</th> <th>Estado</th> <th>Creado</th>
                                        <th>Actualizado</th>
                                        <th>Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($products)): ?>
                                        <?php foreach ($products as $product): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($product['id']); ?></td>
                                             <td>
                                                 <?php if (!empty($product['image_path']) && file_exists('../' . $product['image_path'])): ?>
                                                    <img src="../<?php echo htmlspecialchars($product['image_path']); ?>" alt="<?php echo htmlspecialchars($product['name']); ?>">
                                                 <?php else: ?>
                                                     <img src="../path/to/default/image.jpg" alt="Sin imagen"> <?php endif; ?>
                                             </td> <td><?php echo htmlspecialchars($product['name']); ?></td>
                                            <td><?php echo htmlspecialchars(substr($product['description'] ?? '', 0, 100)); ?><?php echo (strlen($product['description'] ?? '') > 100) ? '...' : ''; ?></td> <td>S/. <?php echo number_format($product['price'], 2); ?></td>
                                            <td><?php echo htmlspecialchars($product['stock']); ?></td>
                                            <td><?php echo htmlspecialchars($product['category_name']); ?></td>
                                             <td><?php echo htmlspecialchars($product['store_name'] ?? 'N/A'); ?></td> <td>
                                                <?php if ($product['is_active'] == 1): ?>
                                                    <span class="badge badge-active">Activo</span>
                                                <?php else: ?>
                                                    <span class="badge badge-inactive">Inactivo</span>
                                                <?php endif; ?>
                                            </td> <td><?php echo htmlspecialchars($product['created_at']); ?></td>
                                            <td><?php echo htmlspecialchars($product['updated_at']); ?></td>
                                            <td>
                                                <button class="btn btn-primary btn-sm edit-product-btn"
                                                        data-bs-toggle="modal"
                                                        data-bs-target="#editProductModal"
                                                        data-id="<?php echo $product['id']; ?>"
                                                        data-name="<?php echo htmlspecialchars($product['name']); ?>"
                                                        data-description="<?php echo htmlspecialchars($product['description'] ?? ''); ?>"
                                                        data-price="<?php echo htmlspecialchars($product['price']); ?>"
                                                        data-stock="<?php echo htmlspecialchars($product['stock']); ?>"
                                                        data-category-id="<?php echo htmlspecialchars($product['category_id']); ?>"
                                                        data-store-id="<?php echo htmlspecialchars($product['store_id']); ?>"
                                                        data-image-path="<?php echo htmlspecialchars($product['image_path'] ?? ''); ?>"
                                                        data-is-active="<?php echo htmlspecialchars($product['is_active']); ?>" title="Editar Producto">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <?php
                                                    // Añadimos un atributo data-has-sales para usar en JS o simplemente confiamos en la lógica backend/mensaje
                                                    // Para simplificar el frontend, la lógica principal de decidir si DELETE o DESACTIVAR se hace en el backend ahora
                                                    // El botón siempre intentará una acción, y el backend decidirá si DELETE o UPDATE is_active
                                                    // Usaremos la acción 'delete' y el backend decidirá (mantenemos 'delete' en el URL por simplicidad)
                                                ?>
                                                 <a href="inventory.php?action=delete&product_id=<?php echo $product['id']; ?>"
                                                    class="btn btn-danger btn-sm delete-product-btn"
                                                    onclick="return confirm('¿Está seguro de eliminar este producto? Si tiene ventas, se desactivará. Si no tiene ventas, se eliminará permanentemente.');"
                                                    title="Eliminar/Desactivar Producto">
                                                    <i class="fas fa-trash-alt"></i>
                                                 </a>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="12" class="text-center">No hay productos en el inventario.</td> </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

            </main>
        </div>
    </div>

    <div class="modal fade" id="addProductModal" tabindex="-1" aria-labelledby="addProductModalLabel" aria-hidden="true">
      <div class="modal-dialog">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="addProductModalLabel">Agregar Nuevo Producto</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <form action="inventory.php" method="POST" enctype="multipart/form-data"> <input type="hidden" name="add_product" value="1">
              <div class="mb-3">
                <label for="add-name" class="form-label">Nombre del Producto</label>
                <input type="text" class="form-control" id="add-name" name="name" required>
              </div>
               <div class="mb-3">
                <label for="add-description" class="form-label">Descripción</label> <textarea class="form-control" id="add-description" name="description" rows="3"></textarea>
              </div>
               <div class="mb-3">
                <label for="add-image" class="form-label">Imagen del Producto</label> <input type="file" class="form-control" id="add-image" name="product_image" accept="image/*">
                 <small class="form-text text-muted">Solo archivos JPG, JPEG, PNG, GIF (máx 5MB).</small>
              </div>
              <div class="mb-3">
                <label for="add-price" class="form-label">Precio (S/.)</label>
                <input type="number" class="form-control" id="add-price" name="price" step="0.01" required min="0">
              </div>
              <div class="mb-3">
                <label for="add-stock" class="form-label">Stock</label>
                <input type="number" class="form-control" id="add-stock" name="stock" required min="0">
              </div>
              <div class="mb-3">
                <label for="add-category" class="form-label">Categoría</label>
                <select class="form-select" id="add-category" name="category_id" required>
                     <option value="">-- Seleccionar Categoría --</option>
                     <?php foreach ($categories as $category): ?>
                         <option value="<?php echo $category['id']; ?>"><?php echo htmlspecialchars($category['name']); ?></option>
                     <?php endforeach; ?>
                </select>
              </div>
               <?php if (!empty($stores)): // Optional: Show store dropdown if stores exist ?>
               <div class="mb-3">
                <label for="add-store" class="form-label">Tienda (Opcional)</label>
                <select class="form-select" id="add-store" name="store_id">
                     <option value="">-- Sin Tienda Asignada --</option>
                     <?php foreach ($stores as $store): ?>
                         <option value="<?php echo $store['id']; ?>"><?php echo htmlspecialchars($store['name']); ?></option>
                     <?php endforeach; ?>
                </select>
              </div>
              <?php endif; ?>
               <div class="mb-3 form-check">
                    <input type="checkbox" class="form-check-input" id="add-is-active" name="is_active" value="1" checked>
                    <label class="form-check-label" for="add-is-active">Producto Activo (Disponible para venta)</label>
                </div>

              <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary">Guardar Producto</button>
              </div>
            </form>
          </div>
        </div>
      </div>
    </div>

    <div class="modal fade" id="editProductModal" tabindex="-1" aria-labelledby="editProductModalLabel" aria-hidden="true">
      <div class="modal-dialog">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="editProductModalLabel">Editar Producto</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <form action="inventory.php" method="POST" enctype="multipart/form-data"> <input type="hidden" name="edit_product" value="1">
                 <input type="hidden" name="product_id" id="edit-product-id">
                 <input type="hidden" name="current_image_path" id="edit-current-image-path"> <div class="mb-3">
                <label for="edit-name" class="form-label">Nombre del Producto</label>
                <input type="text" class="form-control" id="edit-name" name="name" required>
              </div>
               <div class="mb-3">
                <label for="edit-description" class="form-label">Descripción</label> <textarea class="form-control" id="edit-description" name="description" rows="3"></textarea>
              </div>
               <div class="mb-3">
                 <label for="edit-image" class="form-label">Imagen del Producto</label> <div id="current-product-image-preview" class="mb-2">
                     </div>
                 <input type="file" class="form-control" id="edit-image" name="product_image" accept="image/*">
                 <small class="form-text text-muted">Seleccione un archivo para reemplazar la imagen actual. Solo JPG, JPEG, PNG, GIF (máx 5MB).</small>
              </div>
              <div class="mb-3">
                <label for="edit-price" class="form-label">Precio (S/.)</label>
                <input type="number" class="form-control" id="edit-price" name="price" step="0.01" required min="0">
              </div>
              <div class="mb-3">
                <label for="edit-stock" class="form-label">Stock</label>
                <input type="number" class="form-control" id="edit-stock" name="stock" required min="0">
              </div>
              <div class="mb-3">
                <label for="edit-category" class="form-label">Categoría</label>
                <select class="form-select" id="edit-category" name="category_id" required>
                     <option value="">-- Seleccionar Categoría --</option>
                     <?php foreach ($categories as $category): ?>
                         <option value="<?php echo $category['id']; ?>"><?php echo htmlspecialchars($category['name']); ?></option>
                     <?php endforeach; ?>
                </select>
              </div>
               <?php if (!empty($stores)): // Optional: Show store dropdown if stores exist ?>
               <div class="mb-3">
                <label for="edit-store" class="form-label">Tienda (Opcional)</label>
                <select class="form-select" id="edit-store" name="store_id">
                     <option value="">-- Sin Tienda Asignada --</option>
                     <?php foreach ($stores as $store): ?>
                         <option value="<?php echo $store['id']; ?>"><?php echo htmlspecialchars($store['name']); ?></option>
                     <?php endforeach; ?>
                </select>
              </div>
               <?php endif; ?>
               <div class="mb-3 form-check">
                    <input type="checkbox" class="form-check-input" id="edit-is-active" name="is_active" value="1">
                    <label class="form-check-label" for="edit-is-active">Producto Activo (Disponible para venta)</label>
                </div>

              <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary">Guardar Cambios</button>
              </div>
            </form>
          </div>
        </div>
      </div>
    </div>


    <script src="../vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
     <script src="../js/script.js"></script>
     <script>
        // Script para pasar datos al modal de editar producto
         const editProductModal = document.getElementById('editProductModal');
         editProductModal.addEventListener('show.bs.modal', function (event) {
             // Button that triggered the modal
             const button = event.relatedTarget;

             // Extract info from data-* attributes
             const id = button.getAttribute('data-id');
             const name = button.getAttribute('data-name');
             const description = button.getAttribute('data-description');
             const price = button.getAttribute('data-price');
             const stock = button.getAttribute('data-stock');
             const categoryId = button.getAttribute('data-category-id');
             const storeId = button.getAttribute('data-store-id');
             const imagePath = button.getAttribute('data-image-path');
             const isActive = button.getAttribute('data-is-active');


             // Update the modal's content.
             const modalTitle = editProductModal.querySelector('.modal-title');
             const modalBodyInputId = editProductModal.querySelector('#edit-product-id');
             const modalBodyInputName = editProductModal.querySelector('#edit-name');
             const modalBodyInputDescription = editProductModal.querySelector('#edit-description');
             const modalBodyInputPrice = editProductModal.querySelector('#edit-price');
             const modalBodyInputStock = editProductModal.querySelector('#edit-stock');
             const modalBodySelectCategory = editProductModal.querySelector('#edit-category');
             const modalBodySelectStore = editProductModal.querySelector('#edit-store');
             const modalBodyCurrentImagePath = editProductModal.querySelector('#edit-current-image-path');
             const modalBodyCurrentImagePreview = editProductModal.querySelector('#current-product-image-preview');
              const modalBodyCheckboxIsActive = editProductModal.querySelector('#edit-is-active');


             modalTitle.textContent = 'Editar Producto: ' + name;
             modalBodyInputId.value = id;
             modalBodyInputName.value = name;
             modalBodyInputDescription.value = description;
             modalBodyInputPrice.value = price;
             modalBodyInputStock.value = stock;

             // Seleccionar la opción de categoría correcta
             modalBodySelectCategory.value = categoryId;

             // Seleccionar la opción de tienda correcta (manejar null)
            if (modalBodySelectStore) {
                modalBodySelectStore.value = storeId || '';
            }

             // Establecer el estado del checkbox activo/inactivo
             modalBodyCheckboxIsActive.checked = (isActive == 1);


             // Guardar la ruta actual de la imagen en el campo oculto
             modalBodyCurrentImagePath.value = imagePath;

             // Mostrar la imagen actual en la previsualización
            modalBodyCurrentImagePreview.innerHTML = ''; // Clear previous preview
             if (imagePath && imagePath !== 'path/to/default/image.jpg') { // Don't show default image as current
                 const imageUrl = '../' + imagePath;
                 modalBodyCurrentImagePreview.innerHTML = `<img src="${imageUrl}" alt="Imagen actual" style="max-width: 150px; height: auto; border-radius: 5px;">`;
             } else {
                 modalBodyCurrentImagePreview.innerHTML = '<p>Sin imagen actual.</p>';
             }

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

         // Basic client-side validation for price and stock (optional, server-side is required)
         document.querySelectorAll('#addProductModal, #editProductModal').forEach(modalElement => {
             modalElement.addEventListener('submit', function(event) {
                  const priceInput = modalElement.querySelector('input[name="price"]');
                  const stockInput = modalElement.querySelector('input[name="stock"]');

                  if (parseFloat(priceInput.value) < 0) {
                       alert('El precio no puede ser negativo.');
                       event.preventDefault(); // Prevent form submission
                       priceInput.focus();
                  }
                  if (parseInt(stockInput.value) < 0) {
                       alert('El stock no puede ser negativo.');
                       event.preventDefault(); // Prevent form submission
                       stockInput.focus();
                  }
             });
         });


    </script>
</body>
</html>