<?php


// Para determinar si un enlace está activo: compara el nombre del archivo actual
$current_page = basename($_SERVER['PHP_SELF']);

?>

<nav id="sidebar" class="col-md-3 col-lg-2 d-md-block bg-dark sidebar collapse"> <div class="position-sticky pt-3">
        <div class="sidebar-heading text-center text-white"> <img src="../img/logo.png" alt="Logo Empresa" class="img-fluid" style="max-height: 50px;">
            <h5 class="mt-2 mb-1">Nombre de la Empresa</h5>
             <small class="text-muted">Administrador</small> </div>
        <ul class="nav flex-column mt-4"> <li class="nav-item">
                <a class="nav-link btn-animated text-white <?php echo ($current_page == 'dashboard.php') ? 'active' : ''; ?>" aria-current="page" href="dashboard.php">
                    <i class="fas fa-home"></i> Dashboard
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link btn-animated text-white <?php echo ($current_page == 'manage_stores.php') ? 'active' : ''; ?>" href="manage_stores.php">
                    <i class="fas fa-store"></i> Administrar Tiendas
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link btn-animated text-white <?php echo ($current_page == 'inventory.php') ? 'active' : ''; ?>" href="inventory.php">
                    <i class="fas fa-boxes"></i> Inventarios
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link btn-animated text-white <?php echo ($current_page == 'categories.php') ? 'active' : ''; ?>" href="categories.php">
                    <i class="fas fa-tags"></i> Categorias
                </a>
            </li>
             <li class="nav-item">
                <a class="nav-link btn-animated text-white <?php echo ($current_page == 'payment_methods.php') ? 'active' : ''; ?>" href="payment_methods.php">
                    <i class="fas fa-credit-card"></i> Medios de Pago
                </a>
            </li>
             <li class="nav-item">
                <a class="nav-link btn-animated text-white <?php echo ($current_page == 'cancel_receipts.php') ? 'active' : ''; ?>" href="cancel_receipts.php">
                    <i class="fas fa-file-excel"></i> Anular Comprobantes
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link btn-animated text-white <?php echo ($current_page == 'sales_reports.php') ? 'active' : ''; ?>" href="sales_reports.php">
                    <i class="fas fa-chart-bar"></i> Reportes de Ventas
                </a>
            </li>
             <li class="nav-item">
                <a class="nav-link btn-animated text-white <?php echo ($current_page == 'income_expenses.php') ? 'active' : ''; ?>" href="income_expenses.php">
                    <i class="fas fa-dollar-sign"></i> Ingresos Egresos
                </a>
            </li>
             <li class="nav-item">
                <a class="nav-link btn-animated text-white <?php echo ($current_page == 'user_manager.php') ? 'active' : ''; ?>" href="user_manager.php">
                    <i class="fas fa-users-cog"></i> Gestor de Usuarios
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link btn-animated text-white" href="../logout.php"> <i class="fas fa-sign-out-alt"></i> Cerrar Sesión
                </a>
            </li>
        </ul>
    </div>
</nav>