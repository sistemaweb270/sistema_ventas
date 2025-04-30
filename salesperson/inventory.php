<?php
session_start();
require_once '../includes/config.php';
require_once '../includes/session_check.php'; // Check if logged in

// Check if user is salesperson or allowed access (e.g., Admin can view salesperson panels)
if (!isset($_SESSION['role']) || ($_SESSION['role'] !== 'Salesperson' && $_SESSION['role'] !== 'Admin')) {
     header("Location: ../index.php"); // Redirect to index or login
     exit();
}

$message = '';
$message_type = ''; // 'success', 'danger', 'warning', 'info'

$current_user_id = $_SESSION['user_id'];
$user_store_id = null; // Variable para almacenar el ID de la tienda del vendedor

// --- Fetch User's Store ID ---
// Necesitamos saber a qué tienda pertenece el vendedor para filtrar el inventario (si aplica)
$sql_user_store = "SELECT store_id FROM users WHERE id = ?";
if ($stmt_store = $conn->prepare($sql_user_store)) {
   $stmt_store->bind_param("i", $current_user_id);
   $stmt_store->execute();
   $result_store = $stmt_store->get_result();
   if($row_store = $result_store->fetch_assoc()) {
       $user_store_id = $row_store['store_id'];
   }
   $stmt_store->close();
} // Consider error handling if fetching store fails

// --- Fetch Products for Display ---
$products = [];
// Construir la consulta SQL
$sql = "SELECT p.id, p.name, p.description, p.price, p.stock, c.name AS category_name, s.name AS store_name
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN stores s ON p.store_id = s.id
        WHERE 1"; // Start with a true condition

$params = [];
$types = '';

// --- Filtrar por la tienda del vendedor si está asignada ---
// Si el vendedor tiene una tienda asignada (user_store_id no es NULL),
// mostramos solo los productos asociados a esa tienda O productos sin tienda asignada (NULL)
// Decide la lógica: ¿solo los de su tienda, o los de su tienda + los sin asignar globalmente?
// La siguiente lógica muestra los de su tienda O los sin asignar.
if ($user_store_id !== null) {
     $sql .= " AND (p.store_id = ? OR p.store_id IS NULL)";
     $params[] = $user_store_id;
     $types .= 'i';
}
// NOTA: Si quieres que vea *solo* los productos de su tienda asignada, cambia la condición a:
// if ($user_store_id !== null) {
//      $sql .= " AND p.store_id = ?";
//      $params[] = $user_store_id;
//      $types .= 'i';
// }
// Si no tiene tienda asignada (user_store_id es NULL), la cláusula WHERE 1 no filtra, mostrando todos los productos sin filtro de tienda.

$sql .= " ORDER BY p.name ASC"; // Ordenar por nombre de producto

if ($stmt = $conn->prepare($sql)) {
     if (!empty($params)) {
         // Dynamically bind parameters
         $bind_params = [];
         $bind_params[] = &$types; // First parameter is the types string
         for ($i = 0; $i < count($params); $i++) {
             $bind_params[] = &$params[$i];
         }
         call_user_func_array([$stmt, 'bind_param'], $bind_params);
     }

    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $products[] = $row;
    }
    $result->free();
    $stmt->close();
} else {
    // Handle error fetching products
     $message = 'Error al cargar la lista de productos: ' . $conn->error;
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
    <title>Inventario - Panel de Vendedor</title>
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
                    <h1 class="h2">Inventario de Productos</h1>
                     <div class="btn-toolbar mb-2 mb-md-0">
                         <span class="me-2">Tienda Asignada: <strong><?php echo htmlspecialchars($user_store_id ? ($stores_list[array_search($user_store_id, array_column($stores_list, 'id'))]['name'] ?? 'N/A') : 'Sin Asignar'); ?></strong></span>
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
                                <th>Descripción</th>
                                <th>Categoría</th>
                                <th>Stock</th>
                                <th>Precio</th>
                                </tr>
                        </thead>
                        <tbody>
                            <?php if (count($products) > 0): ?>
                                <?php foreach ($products as $product): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($product['id']); ?></td>
                                        <td><?php echo htmlspecialchars($product['name']); ?></td>
                                        <td><?php echo htmlspecialchars($product['description'] ?? ''); ?></td>
                                        <td><?php echo htmlspecialchars($product['category_name'] ?? 'Sin Categoría'); ?></td>
                                        <?php /* */ ?>
                                        <td>
                                             <?php
                                             $stock_class = '';
                                             if ($product['stock'] <= 5) { // Ejemplo: Stock bajo
                                                 $stock_class = 'text-danger fw-bold';
                                             } elseif ($product['stock'] > 5 && $product['stock'] <= 20) { // Ejemplo: Stock medio
                                                 $stock_class = 'text-warning';
                                             }
                                             ?>
                                            <span class="<?php echo $stock_class; ?>"><?php echo htmlspecialchars($product['stock']); ?></span>
                                        </td>
                                        <td>$ <?php echo number_format($product['price'], 2); ?></td>
                                        </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="text-center">No hay productos en el inventario disponibles para tu tienda (o no hay productos registrados).</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
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