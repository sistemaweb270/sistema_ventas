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

$current_user_id = $_SESSION['user_id'];
$user_store_id = null; // Variable para almacenar el ID de la tienda del vendedor

// Fetch User's Store ID (Needed for filtering products if inventory is store-specific)
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

// Initialize cart in session if it doesn't exist
if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = []; // Array of product IDs => ['quantity' => N, 'price' => P, 'name' => Name, 'stock' => S, 'store_id' => SID]
}


// --- Handle AJAX Requests (Product Search/Filter, Add/Update/Remove Cart Items) ---
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
    // Request is AJAX

    $response = ['success' => false, 'message' => '', 'cart' => []];

    header('Content-Type: application/json'); // Respond with JSON

    // Handle different AJAX actions based on a parameter (e.g., $_POST['action'])
    $action = $_POST['action'] ?? $_GET['action'] ?? '';

    switch ($action) {
        case 'fetch_products': // Acción para obtener productos para la cuadrícula (con o sin filtro)
            $search_term = trim($_GET['term'] ?? ''); // Usar 'term' para compatibilidad con búsqueda si se adapta

            $sql = "SELECT p.id, p.name, p.description, p.price, p.stock, p.image_path, s.name AS store_name
                    FROM products p
                    LEFT JOIN stores s ON p.store_id = s.id
                    WHERE 1"; // Empezar con WHERE 1 para añadir condiciones fácilmente

            $params = [];
            $types = '';

            // Añadir filtro por término de búsqueda si existe
            if (!empty($search_term)) {
                $sql .= " AND (p.name LIKE ? OR p.description LIKE ?)";
                $params[] = '%' . $search_term . '%';
                $params[] = '%' . $search_term . '%';
                $types .= 'ss';
            }

             // Add store filter if user has a store assigned
             if ($user_store_id !== null) {
                 // Filter by products in the user's store OR products with no store assigned
                 $sql .= " AND (p.store_id = ? OR p.store_id IS NULL)";
                 $params[] = $user_store_id;
                 $types .= 'i';
             }

            // Opcional: Añadir orden o límite si hay muchos productos
            $sql .= " ORDER BY p.name ASC";
            // $sql .= " LIMIT 50"; // Limitar si hay demasiados productos

            if ($stmt = $conn->prepare($sql)) {
                // Check if parameters are needed before binding
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
                $products_list = [];
                while ($row = $result->fetch_assoc()) {
                    // Solo mostrar productos con stock > 0 en la cuadrícula de venta
                    if ($row['stock'] > 0) {
                        $products_list[] = [
                            'id' => $row['id'],
                            'name' => htmlspecialchars($row['name']),
                            'description' => htmlspecialchars($row['description'] ?? 'Sin descripción'),
                            'price' => $row['price'], // Keep as number for calculations
                            'stock' => $row['stock'], // Keep as number
                            'image_path' => htmlspecialchars($row['image_path'] ?? 'path/to/default/image.jpg'), // Usar una imagen por defecto si no hay
                            'store_name' => htmlspecialchars($row['store_name'] ?? 'Sin Asignar')
                        ];
                    }
                }
                $result->free();
                $stmt->close();
                $response['success'] = true;
                $response['products'] = $products_list;
            } else {
                $response['message'] = 'Error de base de datos al obtener productos.';
            }
            echo json_encode($response);
            exit(); // Stop further PHP processing for AJAX request

        case 'add_to_cart':
             $product_id = $_POST['product_id'] ?? 0;
             $quantity = $_POST['quantity'] ?? 1; // Default to 1

             if ($product_id <= 0 || $quantity <= 0 || !filter_var($quantity, FILTER_VALIDATE_INT)) {
                 $response['message'] = 'Datos inválidos para añadir al carrito.';
             } else {
                 // Fetch product details and current stock
                 $sql = "SELECT id, name, price, stock FROM products WHERE id = ?";
                 if ($stmt = $conn->prepare($sql)) {
                     $stmt->bind_param("i", $product_id);
                     $stmt->execute();
                     $result = $stmt->get_result();
                     if ($product = $result->fetch_assoc()) {
                         $result->free();
                         $stmt->close();

                         $current_cart_quantity = $_SESSION['cart'][$product_id]['quantity'] ?? 0;
                         $requested_total_quantity = $current_cart_quantity + $quantity;

                         if ($requested_total_quantity > $product['stock']) {
                             $response['message'] = 'Cantidad solicitada excede el stock disponible (' . $product['stock'] . ').';
                         } else {
                             // Add/Update item in cart
                             $_SESSION['cart'][$product_id] = [
                                 'id' => $product['id'], // Store ID explicitly
                                 'name' => $product['name'],
                                 'price' => $product['price'],
                                 'stock' => $product['stock'], // Store original stock for reference
                                 'quantity' => $requested_total_quantity // Add to existing quantity
                             ];
                             $response['success'] = true;
                             $response['message'] = 'Producto añadido al carrito.';
                         }
                     } else {
                         $response['message'] = 'Producto no encontrado.';
                     }
                 } else {
                     $response['message'] = 'Error de base de datos al obtener producto.';
                 }
             }
             $response['cart'] = array_values($_SESSION['cart']); // Return cart items as a list
             echo json_encode($response);
             exit();

        case 'update_cart_quantity':
            $product_id = $_POST['product_id'] ?? 0;
            $new_quantity = $_POST['quantity'] ?? 0; // New quantity

             if ($product_id <= 0 || $new_quantity < 0 || !filter_var($new_quantity, FILTER_VALIDATE_INT)) {
                 $response['message'] = 'Cantidad inválida.';
             } elseif (!isset($_SESSION['cart'][$product_id])) {
                  $response['message'] = 'Producto no encontrado en el carrito.';
             } else {
                 // Get product stock from stored cart data (or re-fetch from DB if preferred for accuracy)
                 $product_stock = $_SESSION['cart'][$product_id]['stock'];

                 if ($new_quantity > $product_stock) {
                      $response['message'] = 'Cantidad solicitada excede el stock disponible (' . $product_stock . ').';
                 } elseif ($new_quantity === 0) {
                      // Remove item if quantity is 0
                      unset($_SESSION['cart'][$product_id]);
                      $response['success'] = true;
                      $response['message'] = 'Producto eliminado del carrito.';
                 } else {
                      // Update quantity
                      $_SESSION['cart'][$product_id]['quantity'] = $new_quantity;
                      $response['success'] = true;
                      $response['message'] = 'Cantidad actualizada.';
                 }
             }
             $response['cart'] = array_values($_SESSION['cart']);
             echo json_encode($response);
             exit();

        case 'remove_from_cart':
             $product_id = $_POST['product_id'] ?? 0;

             if ($product_id <= 0 || !isset($_SESSION['cart'][$product_id])) {
                 $response['message'] = 'Producto no encontrado en el carrito.';
             } else {
                 unset($_SESSION['cart'][$product_id]);
                 $response['success'] = true;
                 $response['message'] = 'Producto eliminado del carrito.';
             }
             $response['cart'] = array_values($_SESSION['cart']);
             echo json_encode($response);
             exit();

         case 'clear_cart':
             $_SESSION['cart'] = [];
             $response['success'] = true;
             $response['message'] = 'Carrito vaciado.';
             $response['cart'] = [];
             echo json_encode($response);
             exit();


        // Add more AJAX actions if needed (e.g., get cart contents, validate cart before final sale)

        default:
            // No specific AJAX action requested
            break; // Continue with normal page load
    }
}


// --- Handle Final Sale Processing (POST when submitting the sale form) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['process_sale'])) {

    $customer_id = $_POST['customer_id'] ?? NULL; // Can be NULL for general public
    $payment_method_id = $_POST['payment_method_id'] ?? 0;
    $sale_items = $_SESSION['cart']; // Get items from the session cart

    // Convert customer_id to int or NULL
    $customer_id = filter_var($customer_id, FILTER_VALIDATE_INT) ? (int)$customer_id : NULL;


    if (empty($sale_items)) {
        $message = 'El carrito está vacío. Añada productos para procesar la venta.';
        $message_type = 'warning';
    } elseif ($payment_method_id <= 0 || !filter_var($payment_method_id, FILTER_VALIDATE_INT)) {
        $message = 'Seleccione un medio de pago válido.';
        $message_type = 'warning';
    } else {
        // Start a database transaction
        $conn->begin_transaction();
        $success = true; // Flag to track transaction success

        try {
            // 1. Calculate Total Amount
            $total_amount = 0;
            foreach ($sale_items as $item) {
                $total_amount += $item['quantity'] * $item['price'];
            }

            // 2. Generate Receipt Number (Basic example: Timestamp + User ID)
            // In production, use a more robust unique receipt number generation system
            $receipt_number = date('YmdHis') . '-' . $current_user_id;


            // 3. Insert into sales table
            $sql_sale = "INSERT INTO sales (receipt_number, sale_date, total_amount, user_id, customer_id, payment_method_id) VALUES (?, NOW(), ?, ?, ?, ?)";
            if ($stmt_sale = $conn->prepare($sql_sale)) {
                 // Handle binding customer_id which can be NULL.
                 // If $customer_id is NULL, bind_param can handle it correctly with type "i".
                 $stmt_sale->bind_param("sddii", $receipt_number, $total_amount, $current_user_id, $customer_id, $payment_method_id);

                if (!$stmt_sale->execute()) {
                     throw new Exception('Error al insertar la venta principal: ' . $stmt_sale->error);
                }
                $sale_id = $conn->insert_id; // Get the ID of the newly inserted sale
                $stmt_sale->close();
            } else {
                 // CORREGIDO: Añadido el paréntesis de cierre en la excepción
                 throw new Exception('Error de base de datos al preparar la consulta de venta principal: ' . $conn->error);
            }

            // 4. Insert into sale_items table and Update product stock for each item
            // CORREGIDO: Cambiado 'price' a 'price_at_sale' según la estructura de tu BD
            $sql_item = "INSERT INTO sale_items (sale_id, product_id, quantity, price_at_sale) VALUES (?, ?, ?, ?)";
            $sql_stock_update = "UPDATE products SET stock = stock - ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?";

            if (!($stmt_item = $conn->prepare($sql_item)) || !($stmt_stock_update = $conn->prepare($sql_stock_update))) {
                 throw new Exception('Error de base de datos al preparar consultas de ítems/stock: ' . $conn->error);
            }

            foreach ($sale_items as $item) {
                // Insert sale item
                // $item['price'] viene de la sesión, que sí guarda el precio.
                // La consulta SQL es la que debe usar 'price_at_sale'.
                $stmt_item->bind_param("iidd", $sale_id, $item['id'], $item['quantity'], $item['price']);
                if (!$stmt_item->execute()) {
                     throw new Exception('Error al insertar ítem de venta (Producto ID ' . $item['id'] . '): ' . $stmt_item->error);
                }

                // Update product stock
                 $stmt_stock_update->bind_param("ii", $item['quantity'], $item['id']);
                 if (!$stmt_stock_update->execute()) {
                     throw new Exception('Error al actualizar stock (Producto ID ' . $item['id'] . '): ' . $stmt_stock_update->error);
                 }
                 // Check if affected rows is 0 - could mean product ID doesn't exist or stock was already too low
                 // although we should have validated stock when adding to cart.
                 // Given FK ON DELETE RESTRICT on products, product ID *should* exist if it was added to cart.
                 // If affected_rows is 0, it's likely a stock issue or race condition.
                 if ($stmt_stock_update->affected_rows === 0) {
                      // This is a critical error if stock was checked earlier. Handle appropriately.
                      // For now, throw an exception to rollback.
                      throw new Exception('Error inesperado al actualizar stock para Producto ID ' . $item['id'] . '. El stock pudo haber cambiado o el producto no existe.');
                 }
            }

            $stmt_item->close();
            $stmt_stock_update->close();

            // If everything was successful, commit the transaction
            $conn->commit();
            $message = 'Venta procesada con éxito. Nº Comprobante: ' . $receipt_number;
            $message_type = 'success';

            // Clear the cart after successful sale
            $_SESSION['cart'] = [];

            // Redirect to the receipt page with the new sale_id and messages
            header("Location: issue_receipt.php?sale_id=" . $sale_id . "&message=" . urlencode($message) . "&type=" . urlencode($message_type));
            exit(); // Important to exit after redirect


        } catch (Exception $e) {
            // An error occurred, rollback the transaction
            $conn->rollback();
            // Mantenemos el mensaje de error para mostrarlo en la misma página
            $message = 'Error al procesar la venta: ' . $e->getMessage();
            $message_type = 'danger';
            // Log the error $e->getMessage() for debugging
        }
         // $conn->close(); // Optional: Close DB connection

         // Si hubo un error, el código sigue ejecutándose para mostrar el formulario
         // generate_sale.php con el mensaje de error.
    }
}

// Handle messages passed via GET (e.g., after a failed POST redirect)
if (isset($_GET['message']) && isset($_GET['type'])) {
    $message = htmlspecialchars($_GET['message']);
    $message_type = htmlspecialchars($_GET['type']);
}


// --- Fetch Data for Dropdowns and Display ---

// Fetch Customers for dropdown (optional)
// ADAPTADO a tu tabla customers sin la columna 'identification'
$customers = [];
$sql_customers = "SELECT id, name FROM customers ORDER BY name ASC"; // <-- ADAPTADO
if ($result_cust = $conn->query($sql_customers)) {
    while ($row_cust = $result_cust->fetch_assoc()) {
        $customers[] = $row_cust;
    }
    $result_cust->free();
} // Consider error handling

// Fetch Payment Methods for dropdown
$payment_methods = [];
$sql_payment_methods = "SELECT id, name FROM payment_methods ORDER BY name ASC";
if ($result_pm = $conn->query($sql_payment_methods)) {
    while ($row_pm = $result_pm->fetch_assoc()) {
        $payment_methods[] = $row_pm;
    }
    $result_pm->free();
} // Consider error handling

// Close DB connection (optional if not closed above)
// $conn->close();

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Generar Venta - Panel de Vendedor</title>
    <link rel="stylesheet" href="../vendor/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="../vendor/font-awesome/css/all.min.css">
    <link rel="stylesheet" href="../css/style.css">
    <style>
        /* Estilos para la cuadrícula de productos */
        .product-card {
            border: 1px solid #ccc;
            border-radius: 5px;
            padding: 10px;
            margin-bottom: 20px;
            text-align: center;
            background-color: #fff;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            display: flex;
            flex-direction: column;
            height: 100%; /* Para que todas las tarjetas tengan la misma altura en la fila */
        }
        .product-card img {
            max-width: 100%; /* No exceder el ancho del contenedor */
            width: 100%; /* Intentar ocupar el 100% del ancho disponible (dentro del padding) */
            height: 120px; /* **Altura fija más pequeña** */
            object-fit: cover; /* Asegura que la imagen cubra el área sin distorsionarse */
            margin-bottom: 10px;
            border-radius: 4px;
        }
        .product-card h5 {
            font-size: 1.1em;
            margin-bottom: 5px;
            flex-grow: 1; /* Permite que el título y descripción ocupen el espacio disponible */
        }
         .product-card p.description {
             font-size: 0.9em;
             color: #555;
             margin-bottom: 10px;
             flex-grow: 1;
             overflow: hidden; /* Ocultar el texto si es demasiado largo */
             text-overflow: ellipsis; /* Añadir puntos suspensivos si se oculta texto */
             display: -webkit-box; /* Para limitar líneas en navegadores Webkit */
             -webkit-line-clamp: 2; /* Limitar a 2 líneas */
             -webkit-box-orient: vertical;
         }
        .product-card .price {
            font-size: 1.2em;
            font-weight: bold;
            color: #28a745; /* Color verde para el precio */
            margin-bottom: 5px;
        }
        .product-card .stock {
             font-size: 0.9em;
             color: #6c757d; /* Color gris para el stock */
             margin-bottom: 10px;
         }
        .product-card .add-to-cart-form {
            display: flex;
            align-items: center;
            justify-content: center;
            margin-top: auto; /* Empuja el formulario a la parte inferior */
        }
        .product-card .add-to-cart-form input[type="number"] {
            width: 60px;
            margin-right: 5px;
            text-align: center;
        }

        /* Styles for the right sidebar area */
        .right-sidebar-area {
            padding: 15px; /* Add padding */
            height: calc(100vh - 80px); /* Adjust height to fill remaining vertical space (approx) */
            overflow-y: auto; /* Add scroll if content exceeds height */
            position: sticky; /* Make it sticky */
            top: 15px; /* Stick it below the header/navbar */
        }


         #cart-items-tbody td, #cart-items-tbody th {
            white-space: nowrap; /* Prevent text wrapping in cart table */
         }
         #cart-table .form-control-sm {
             width: 50px; /* Smaller input for quantity */
         }
         #cart-grand-total {
             font-size: 1.5em;
             font-weight: bold;
             color: #007bff; /* Blue color for total */
         }
         #process-sale-button {
             width: 100%; /* Make the process sale button full width */
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
                    <h1 class="h2">Generar Nueva Venta</h1>
                     <div class="btn-toolbar mb-2 mb-md-0">
                         <button class="btn btn-warning btn-animated me-2" id="clear-cart-btn"><i class="fas fa-trash"></i> Vaciar Carrito</button>
                     </div>
                </div>

                 <?php if ($message): ?>
                     <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
                         <?php echo htmlspecialchars($message); ?>
                         <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                     </div>
                 <?php endif; ?>

                <div class="row"> <div class="col-md-7 col-lg-8"> <div class="card mb-4">
                             <div class="card-header"><i class="fas fa-search"></i> Buscar/Filtrar Productos</div>
                             <div class="card-body">
                                 <div class="input-group">
                                     <input type="text" class="form-control" id="product-filter-input" placeholder="Filtrar productos por nombre o descripción...">
                                      <button class="btn btn-outline-secondary" type="button" id="product-filter-button"><i class="fas fa-filter"></i> Aplicar Filtro</button>
                                 </div>
                             </div>
                         </div>

                         <div class="card mb-4">
                             <div class="card-header"><i class="fas fa-boxes"></i> Productos Disponibles</div>
                             <div class="card-body">
                                  <div id="product-grid" class="row">
                                       <p class="text-center text-muted" id="loading-products">Cargando productos...</p>
                                  </div>
                                  <p class="text-center text-muted" id="no-products-found" style="display: none;">No se encontraron productos.</p>
                             </div>
                         </div>
                    </div>

                    <div class="col-md-5 col-lg-4 right-sidebar-area"> <div class="card mb-4">
                             <div class="card-header"><i class="fas fa-user-plus"></i> Añadir Cliente</div>
                             <div class="card-body">
                                 <p>Si el cliente no existe, puedes añadirlo aquí.</p>
                                 <a href="add_customer.php" class="btn btn-primary w-100 btn-animated"><i class="fas fa-user-plus"></i> Ir a Añadir Cliente</a>
                             </div>
                         </div>

                         <div class="card mb-4">
                             <div class="card-header"><i class="fas fa-shopping-cart"></i> Ítems de Venta</div>
                             <div class="card-body">
                                  <?php if (empty($_SESSION['cart'])): ?>
                                       <p class="text-center" id="cart-empty-message">El carrito está vacío. Busca y añade productos.</p>
                                  <?php endif; ?>
                                 <div class="table-responsive">
                                     <table class="table table-striped table-sm" id="cart-table">
                                         <thead>
                                             <tr>
                                                 <th>ID</th>
                                                 <th>Producto</th>
                                                 <th>Precio Unitario</th>
                                                 <th>Stock Disp.</th>
                                                 <th>Cantidad</th>
                                                 <th>Subtotal</th>
                                                 <th>Acciones</th>
                                             </tr>
                                         </thead>
                                         <tbody id="cart-items-tbody">
                                             <?php
                                             // Render current cart items from session
                                             $cart_total = 0;
                                             if (!empty($_SESSION['cart'])):
                                                 foreach ($_SESSION['cart'] as $productId => $item):
                                                     $subtotal = $item['quantity'] * $item['price'];
                                                     $cart_total += $subtotal;
                                             ?>
                                                      <tr data-product-id="<?php echo $productId; ?>">
                                                          <td><?php echo htmlspecialchars($item['id']); ?></td>
                                                          <td><?php echo htmlspecialchars($item['name']); ?></td>
                                                          <td>S/. <?php echo number_format($item['price'], 2); ?></td>
                                                          <td><?php echo htmlspecialchars($item['stock']); ?></td>
                                                          <td>
                                                              <input type="number" class="form-control form-control-sm cart-item-quantity"
                                                                      value="<?php echo htmlspecialchars($item['quantity']); ?>"
                                                                      min="1" max="<?php echo htmlspecialchars($item['stock']); ?>"
                                                                      data-product-id="<?php echo $productId; ?>">
                                                          </td>
                                                          <td class="item-subtotal">S/. <?php echo number_format($subtotal, 2); ?></td>
                                                          <td>
                                                              <button class="btn btn-danger btn-sm remove-item-btn" data-product-id="<?php echo $productId; ?>"><i class="fas fa-trash-alt"></i></button>
                                                          </td>
                                                      </tr>
                                             <?php
                                                 endforeach;
                                             endif;
                                             ?>
                                         </tbody>
                                     </table>
                                 </div>
                                  <h4 class="text-end mt-3">Total: <span id="cart-grand-total">S/. <?php echo number_format($cart_total, 2); ?></span></h4>
                             </div>
                         </div>

                         <div class="card mb-4">
                             <div class="card-header"><i class="fas fa-check-circle"></i> Finalizar Venta</div>
                             <div class="card-body">
                                 <form action="generate_sale.php" method="POST" id="finalize-sale-form">
                                      <input type="hidden" name="process_sale" value="1">
                                      <div class="row g-3">
                                          <div class="col-12"> <label for="customer_id" class="form-label">Cliente (Opcional)</label>
                                              <select class="form-select" id="customer_id" name="customer_id">
                                                  <option value="">-- Público General --</option>
                                                  <?php foreach ($customers as $customer): ?>
                                                      <option value="<?php echo $customer['id']; ?>"><?php echo htmlspecialchars($customer['name']); ?></option>
                                                  <?php endforeach; ?>
                                              </select>
                                          </div>
                                           <div class="col-12"> <label for="payment_method_id" class="form-label">Medio de Pago</label>
                                               <select class="form-select" id="payment_method_id" name="payment_method_id" required>
                                                    <option value="">-- Seleccionar --</option>
                                                    <?php foreach ($payment_methods as $method): ?>
                                                        <option value="<?php echo $method['id']; ?>"><?php echo htmlspecialchars($method['name']); ?></option>
                                                    <?php endforeach; ?>
                                               </select>
                                           </div>
                                      </div>
                                       <div class="mt-4">
                                           <button type="submit" class="btn btn-success btn-lg btn-block btn-animated" id="process-sale-button"><i class="fas fa-dollar-sign"></i> Procesar Venta</button>
                                       </div>
                                 </form>
                             </div>
                         </div>


                    </div> </div> </main>
        </div>
    </div>

    <script src="../vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
     <script src="../js/script.js"></script> <script>
         // JavaScript for Product Grid, Search/Filter, and Cart Management

         const filterInput = document.getElementById('product-filter-input'); // Input para filtrar
         const filterButton = document.getElementById('product-filter-button'); // Botón para aplicar filtro
         const productGridDiv = document.getElementById('product-grid'); // Div donde se mostrará la cuadrícula
         const loadingProductsMessage = document.getElementById('loading-products'); // Mensaje de carga
         const noProductsFoundMessage = document.getElementById('no-products-found'); // Mensaje de no encontrados

         const cartItemsTbody = document.getElementById('cart-items-tbody');
         const cartGrandTotalSpan = document.getElementById('cart-grand-total');
         const cartEmptyMessage = document.getElementById('cart-empty-message');
         const clearCartButton = document.getElementById('clear-cart-btn');
         const processSaleButton = document.getElementById('process-sale-button');


         // Function to format currency (using Soles)
         function formatCurrency(amount) {
             return 'S/. ' + parseFloat(amount).toFixed(2); // CAMBIO AQUÍ
         }

          // Function to render product grid items
          function renderProductGrid(products) {
              productGridDiv.innerHTML = ''; // Clear current grid
              loadingProductsMessage.style.display = 'none'; // Hide loading message
              if (products.length === 0) {
                  noProductsFoundMessage.style.display = 'block'; // Show no products found message
              } else {
                  noProductsFoundMessage.style.display = 'none'; // Hide no products found message
                  products.forEach(product => {
                      // Asegurarse de que la ruta de la imagen sea correcta, partiendo de la raíz del proyecto
                      const imageUrl = product.image_path ? `../${product.image_path}` : '../path/to/default/image.jpg'; // Ajusta la ruta de la imagen por defecto si es necesario
                      const productCardHtml = `
                          <div class="col-sm-6 col-md-6 col-lg-4 mb-4"> <div class="product-card" data-product-id="${product.id}">
                                  <img src="${imageUrl}" class="card-img-top" alt="${product.name}">
                                  <h5>${product.name}</h5>
                                  <p class="description">${product.description}</p>
                                  <p class="price">${formatCurrency(product.price)}</p>
                                  <p class="stock">Stock: ${product.stock}</p>
                                  <div class="add-to-cart-form">
                                      <input type="number" class="form-control form-control-sm product-quantity"
                                             value="1" min="1" max="${product.stock}"
                                             data-product-id="${product.id}">
                                      <button class="btn btn-primary btn-sm add-to-cart-btn" data-product-id="${product.id}"><i class="fas fa-cart-plus"></i> Añadir</button>
                                  </div>
                              </div>
                          </div>
                      `;
                      productGridDiv.innerHTML += productCardHtml;
                  });
              }
          }

        // Function to fetch products via AJAX
        function fetchProducts(term = '') {
            loadingProductsMessage.style.display = 'block'; // Show loading message
            noProductsFoundMessage.style.display = 'none'; // Hide no products found message
            productGridDiv.innerHTML = ''; // Clear grid while loading

            fetch(`generate_sale.php?action=fetch_products&term=${encodeURIComponent(term)}`, {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest' // Identify as AJAX request
                }
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    renderProductGrid(data.products);
                } else {
                    alert('Error al cargar productos: ' + data.message);
                    loadingProductsMessage.style.display = 'none'; // Hide loading message on error
                    noProductsFoundMessage.style.display = 'block'; // Show message if no products returned
                }
            })
            .catch(error => {
                console.error('Fetch error:', error);
                alert('Error de red al cargar productos.');
                loadingProductsMessage.style.display = 'none'; // Hide loading message on error
                noProductsFoundMessage.style.display = 'block'; // Show message on error
            });
        }

        // Initial load of products
        fetchProducts();

        // Event listener for filter button
        filterButton.addEventListener('click', () => {
            const searchTerm = filterInput.value;
            fetchProducts(searchTerm);
        });

        // Allow filtering by pressing Enter in the input field
         filterInput.addEventListener('keypress', function(event) {
             if (event.key === 'Enter') {
                 event.preventDefault(); // Prevent form submission if input is part of a form
                 filterButton.click(); // Trigger the filter button click
             }
         });

        // Function to render cart items table body
         function renderCartItems(cartItems) {
             cartItemsTbody.innerHTML = ''; // Clear current cart
             let total = 0;

             if (cartItems.length === 0) {
                 cartEmptyMessage.style.display = 'block';
                 document.getElementById('cart-table').style.display = 'none'; // Hide table if empty
                 processSaleButton.disabled = true; // Disable finalize button
             } else {
                 cartEmptyMessage.style.display = 'none';
                 document.getElementById('cart-table').style.display = 'table'; // Show table if not empty
                 processSaleButton.disabled = false; // Enable finalize button

                 cartItems.forEach(item => {
                     const subtotal = item.quantity * item.price;
                     total += subtotal;
                     const rowHtml = `
                         <tr data-product-id="${item.id}">
                             <td>${item.id}</td>
                             <td>${item.name}</td>
                             <td>${formatCurrency(item.price)}</td>
                              <td>${item.stock}</td>
                             <td>
                                 <input type="number" class="form-control form-control-sm cart-item-quantity"
                                        value="${item.quantity}" min="1" max="${item.stock}"
                                        data-product-id="${item.id}">
                             </td>
                             <td class="item-subtotal">${formatCurrency(subtotal)}</td>
                             <td>
                                 <button class="btn btn-danger btn-sm remove-item-btn" data-product-id="${item.id}"><i class="fas fa-trash-alt"></i></button>
                             </td>
                         </tr>
                     `;
                     cartItemsTbody.innerHTML += rowHtml;
                 });
             }
             cartGrandTotalSpan.textContent = formatCurrency(total);
         }

         // Function to add item to cart via AJAX
         function addToCart(productId, quantity) {
             fetch('generate_sale.php', {
                 method: 'POST',
                 headers: {
                     'Content-Type': 'application/x-www-form-urlencoded',
                     'X-Requested-With': 'XMLHttpRequest' // Identify as AJAX request
                 },
                 body: `action=add_to_cart&product_id=${productId}&quantity=${quantity}`
             })
             .then(response => {
                  if (!response.ok) {
                      throw new Error(`HTTP error! status: ${response.status}`);
                  }
                  return response.json();
             })
             .then(data => {
                 if (data.success) {
                     renderCartItems(data.cart);
                     // Optionally, show a success message
                     displayMessage(data.message, 'success');
                     // Re-fetch products to update stock display
                     fetchProducts(filterInput.value);

                 } else {
                     // Show error message from the server
                     displayMessage(data.message, 'danger');
                 }
             })
             .catch(error => {
                 console.error('Fetch error:', error);
                 displayMessage('Error de red al añadir producto al carrito.', 'danger');
             });
         }

         // Function to update cart item quantity via AJAX
         function updateCartQuantity(productId, quantity) {
              fetch('generate_sale.php', {
                  method: 'POST',
                  headers: {
                      'Content-Type': 'application/x-www-form-urlencoded',
                      'X-Requested-With': 'XMLHttpRequest'
                  },
                  body: `action=update_cart_quantity&product_id=${productId}&quantity=${quantity}`
              })
              .then(response => {
                   if (!response.ok) {
                       throw new Error(`HTTP error! status: ${response.status}`);
                   }
                   return response.json();
              })
              .then(data => {
                  if (data.success) {
                      renderCartItems(data.cart);
                      displayMessage(data.message, 'success');
                       // Re-fetch products to update stock display (if quantity decreased)
                      fetchProducts(filterInput.value);
                  } else {
                      // Show error message from the server
                      displayMessage(data.message, 'danger');
                       // Re-render cart to revert quantity if update failed (e.g., stock issue)
                      renderCartItems(data.cart); // data.cart will contain the state before the failed update
                  }
              })
              .catch(error => {
                  console.error('Fetch error:', error);
                  displayMessage('Error de red al actualizar cantidad.', 'danger');
                  // Consider re-fetching the cart state from the server on network error
                   fetchCart();
              });
         }

         // Function to remove item from cart via AJAX
         function removeFromCart(productId) {
              fetch('generate_sale.php', {
                  method: 'POST',
                  headers: {
                      'Content-Type': 'application/x-www-form-urlencoded',
                      'X-Requested-With': 'XMLHttpRequest'
                  },
                  body: `action=remove_from_cart&product_id=${productId}`
              })
              .then(response => {
                   if (!response.ok) {
                       throw new Error(`HTTP error! status: ${response.status}`);
                   }
                   return response.json();
              })
              .then(data => {
                  if (data.success) {
                      renderCartItems(data.cart);
                      displayMessage(data.message, 'success');
                       // Re-fetch products to update stock display
                      fetchProducts(filterInput.value);
                  } else {
                      displayMessage(data.message, 'danger');
                  }
              })
              .catch(error => {
                  console.error('Fetch error:', error);
                  displayMessage('Error de red al eliminar producto.', 'danger');
              });
         }

         // Function to clear the entire cart via AJAX
         function clearCart() {
              if (confirm('¿Está seguro de que desea vaciar el carrito?')) {
                  fetch('generate_sale.php', {
                      method: 'POST',
                      headers: {
                          'Content-Type': 'application/x-www-form-urlencoded',
                          'X-Requested-With': 'XMLHttpRequest'
                      },
                      body: 'action=clear_cart'
                  })
                  .then(response => {
                       if (!response.ok) {
                           throw new Error(`HTTP error! status: ${response.status}`);
                       }
                       return response.json();
                  })
                  .then(data => {
                      if (data.success) {
                          renderCartItems(data.cart); // Should be empty
                          displayMessage(data.message, 'success');
                           // Re-fetch products as stock is now fully available
                          fetchProducts(filterInput.value);
                      } else {
                          displayMessage(data.message, 'danger');
                      }
                  })
                  .catch(error => {
                      console.error('Fetch error:', error);
                      displayMessage('Error de red al vaciar el carrito.', 'danger');
                  });
              }
         }

         // Function to fetch current cart state (useful after page load or errors)
         function fetchCart() {
              fetch('generate_sale.php?action=get_cart', { // You might need to add a 'get_cart' AJAX action in PHP
                 headers: {
                     'X-Requested-With': 'XMLHttpRequest'
                 }
             })
             .then(response => response.json())
             .then(data => {
                 if (data.success) {
                     renderCartItems(data.cart);
                 } else {
                     console.error('Error fetching cart:', data.message);
                 }
             })
             .catch(error => {
                 console.error('Fetch cart error:', error);
             });
         }


         // Function to display dynamic messages
         function displayMessage(msg, type) {
             // Remove any existing alerts first
             const existingAlert = document.querySelector('.alert');
             if (existingAlert) {
                 existingAlert.remove();
             }

             const alertHtml = `
                 <div class="alert alert-${type} alert-dismissible fade show" role="alert">
                     ${msg}
                     <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                 </div>
             `;
             // Insert the alert after the h2 title
             const mainContent = document.querySelector('main');
             // Check if the first row exists before inserting
             const firstRow = mainContent.querySelector('.row');
             if (firstRow) {
                 mainContent.insertBefore(document.createRange().createContextualFragment(alertHtml), firstRow); // Insert before the main content row
             } else {
                 mainContent.insertBefore(document.createRange().createContextualFragment(alertHtml), mainContent.firstChild); // Insert at the beginning if no row found
             }
         }


        // Event delegation for "Add to Cart" buttons (as they are added dynamically)
        productGridDiv.addEventListener('click', function(event) {
            if (event.target.classList.contains('add-to-cart-btn') || event.target.parentElement.classList.contains('add-to-cart-btn')) {
                 const button = event.target.classList.contains('add-to-cart-btn') ? event.target : event.target.parentElement;
                 const productId = button.dataset.productId;
                 // Find the corresponding quantity input for this product
                 const quantityInput = button.closest('.product-card').querySelector('.product-quantity');
                 const quantity = parseInt(quantityInput.value);

                 if (productId && quantity > 0) {
                     addToCart(productId, quantity);
                 } else {
                     displayMessage('Cantidad inválida para añadir al carrito.', 'warning');
                 }
            }
        });

        // Event delegation for cart item quantity changes
         cartItemsTbody.addEventListener('change', function(event) {
             if (event.target.classList.contains('cart-item-quantity')) {
                 const quantityInput = event.target;
                 const productId = quantityInput.dataset.productId;
                 const newQuantity = parseInt(quantityInput.value);
                 const maxQuantity = parseInt(quantityInput.max);

                 if (productId && !isNaN(newQuantity) && newQuantity >= 0) {
                      if (newQuantity > maxQuantity) {
                          displayMessage(`Cantidad excede el stock disponible (${maxQuantity}).`, 'warning');
                          quantityInput.value = quantityInput.dataset.originalValue || quantityInput.max; // Revert or set to max
                      } else {
                          // Store original value before updating
                          quantityInput.dataset.originalValue = quantityInput.value;
                          updateCartQuantity(productId, newQuantity);
                      }
                 } else {
                      displayMessage('Cantidad inválida.', 'warning');
                      quantityInput.value = quantityInput.dataset.originalValue || 1; // Revert or default to 1
                 }
             }
         });

         // Event delegation for remove item buttons
         cartItemsTbody.addEventListener('click', function(event) {
              if (event.target.classList.contains('remove-item-btn') || event.target.parentElement.classList.contains('remove-item-btn')) {
                  const button = event.target.classList.contains('remove-item-btn') ? event.target : event.target.parentElement;
                  const productId = button.dataset.productId;
                   if (productId && confirm('¿Está seguro de que desea eliminar este producto del carrito?')) {
                       removeFromCart(productId);
                   }
              }
         });

         // Event listener for clear cart button
          clearCartButton.addEventListener('click', clearCart);


         // Initial render of cart items on page load
         // This relies on the PHP code having already populated $_SESSION['cart'] and calculated $cart_total
         // We can add a small script to fetch the current cart on load if needed, but the current PHP rendering handles the initial state.
         // The renderCartItems function is primarily used after successful AJAX updates.
         // Let's add a call to renderCartItems with the initial PHP data on load for consistency.
          document.addEventListener('DOMContentLoaded', (event) => {
              // Assuming the initial cart data is available in a JS variable or fetched
              // For now, the PHP renders the initial table, so we just need to ensure the JS logic
              // correctly attaches event listeners and updates the display based on AJAX responses.
              // The renderCartItems function can be called initially if we serialize the PHP cart data to JS.
              // Example: const initialCart = <?php echo json_encode(array_values($_SESSION['cart'])); ?>;
              // renderCartItems(initialCart); // Call this if you want JS to render the initial state

               // However, since the PHP already renders it, just ensuring the JS event listeners
               // are attached to the existing elements is sufficient for interactivity.
               // We can add the original value dataset for quantity inputs after the initial render.
                document.querySelectorAll('.cart-item-quantity').forEach(input => {
                    input.dataset.originalValue = input.value;
                });

                 // Initial state check for process sale button
                 if (cartItemsTbody.children.length === 0) {
                     processSaleButton.disabled = true;
                 } else {
                      document.getElementById('cart-table').style.display = 'table'; // Ensure table is visible if there are items
                 }

          });

    </script>
</body>
</html>