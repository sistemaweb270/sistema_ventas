<?php
// salesperson/includes/salesperson_sidebar.php
// Este archivo contiene el código HTML para la barra lateral del panel del vendedor.
// No debe contener lógica de sesión o conexión a BD, ya que es solo una parte de la vista.
// Es incluido por otras páginas del panel de vendedor.

// Asegúrate de que la variable $currentPage (o similar) esté definida en la página que incluye este sidebar
// para marcar el enlace activo, si usas esa funcionalidad. Ejemplo: $currentPage = 'dashboard';

// Puedes obtener el rol del usuario si necesitas mostrar/ocultar elementos,
// pero la verificación de acceso completa debe estar al principio de cada página.
$user_role = $_SESSION['role'] ?? ''; // Asumiendo que el rol está en la sesión

?>

<nav id="sidebar" class="col-md-3 col-lg-2 d-md-block bg-dark sidebar collapse"> <div class="position-sticky pt-3">
    <div class="sidebar-heading text-center text-white"> <img src="../img/logo.png" alt="Logo Empresa" class="img-fluid" style="max-height: 50px;">
            <h5 class="mt-2 mb-1">Nombre de la Empresa</h5>
             <h5 class="mt-2 mb-1">Vendedor</h5> </div>
        <ul class="nav flex-column mt-4"> <li class="nav-item">
                <a class="nav-link <?php echo (isset($currentPage) && $currentPage == 'dashboard') ? 'active' : ''; ?>" aria-current="page" href="dashboard.php">
                    <i class="fas fa-home align-text-bottom"></i>
                    Dashboard
                </a>
            </li>
            <li class="nav-item">
                 <a class="nav-link <?php echo (isset($currentPage) && $currentPage == 'till_management') ? 'active' : ''; ?>" href="till_management.php">
                    <i class="fas fa-cash-register align-text-bottom"></i> Apertura/Cierre de Caja
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo (isset($currentPage) && $currentPage == 'generate_sale') ? 'active' : ''; ?>" href="generate_sale.php">
                    <i class="fas fa-cart-plus align-text-bottom"></i> Generar Nueva Venta
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo (isset($currentPage) && $currentPage == 'add_customer') ? 'active' : ''; ?>" href="add_customer.php">
                    <i class="fas fa-user-plus align-text-bottom"></i> Agregar Clientes
                </a>
            </li>
             <li class="nav-item">
                <a class="nav-link <?php echo (isset($currentPage) && $currentPage == 'view_cancel_receipts') ? 'active' : ''; ?>" href="view_cancel_receipts.php">
                    <i class="fas fa-file-invoice align-text-bottom"></i> Ver y Anular Comprobantes
                </a>
            </li>
            <?php if ($user_role === 'Admin'): ?>
            <li class="nav-item">
                <a class="nav-link" href="../admin/dashboard.php">
                    <i class="fas fa-user-shield align-text-bottom"></i>
                    Panel Administrador
                </a>
            </li>
             <?php endif; ?>


        </ul>

        <h6 class="sidebar-heading d-flex justify-content-between align-items-center px-3 mt-4 mb-1 text-muted text-uppercase">
            <span style="color: #FFF;">Reportes</span>
            <a class="link-secondary" href="#" aria-label="Add a new report">
                </a>
        </h6>
        <ul class="nav flex-column mb-2">
            <li class="nav-item">
                <a class="nav-link" href="#">
                    <i class="fas fa-file-alt align-text-bottom"></i>
                    Ventas Diarias
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="#">
                    <i class="fas fa-chart-line align-text-bottom"></i>
                    Rendimiento Mensual
                </a>
            </li>
            </ul>

         <hr class="my-3"> <ul class="nav flex-column mb-2">
             <li class="nav-item">
                 <a class="nav-link" href="../logout.php">
                    <i class="fas fa-sign-out-alt align-text-bottom"></i>
                    Cerrar Sesión
                 </a>
             </li>
        </ul>

    </div>
</nav>