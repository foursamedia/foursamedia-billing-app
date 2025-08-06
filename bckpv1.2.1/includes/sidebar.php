<?php
// includes/sidebar.php
require_once '../includes/db_connect.php';
require_once '../includes/session.php';
require_once '../includes/functions.php';

check_role(['superadmin', 'admin', 'teknisi','pelanggan']);

// Pastikan session sudah dimulai dan variabel role/user_id sudah ada
if (!isset($current_role)) {
    $current_role = $_SESSION['role_name'] ?? '';
}
if (!isset($user_id_in_session)) {
    $user_id_in_session = $_SESSION['user_id'] ?? 0;
}

// Untuk menentukan menu aktif, kita bisa gunakan basename($_SERVER['PHP_SELF'])
// atau jika Anda memiliki variabel $current_page yang diset di setiap halaman.
// Saya akan menggunakan basename($_SERVER['PHP_SELF']) untuk keandalan.
$current_page = basename($_SERVER['PHP_SELF']);

?>
<div id="sidebar-wrapper" class="d-flex flex-column flex-shrink-0 bg-white border-end py-lg-4 ms-lg-2 mt-lg-4">

    <!-- <a href="index.php" class="sidebar-brand d-flex align-items-center mb-3 mb-md-0 me-md-auto text-decoration-none p-3">
        <i class="bi bi-bar-chart-fill me-2 fs-4 text-primary"></i>
        <span class="fs-4 fw-bold text-dark">FOURSAMEDIA</span>
    </a>

    <hr class="sidebar-divider my-0"> -->
    <ul class="nav nav-pills flex-column pt-2">

        <?php if ($current_role == 'superadmin' || $current_role == 'admin'): ?>
            <li class="nav-item">
                <a href="index.php" class="nav-link text-dark <?php echo ($current_page == 'index.php') ? 'active' : ''; ?>" aria-current="page">
                    <i class="bi bi-house"></i>
                    Dashboard
                </a>
            </li>
        <?php endif; ?>

        <?php if ($current_role == 'superadmin' || $current_role == 'admin'): ?>
            <li>
                <a href="manage_users.php" class="nav-link text-dark <?php echo ($current_page == 'manage_users.php') ? 'active' : ''; ?>">
                    <i class="bi bi-people"></i>
                    Manajemen Pengguna
                </a>
            </li>
        <?php endif; ?>

        <?php if (in_array($current_role, ['superadmin', 'admin', 'finance'])): // Atur peran yang bisa melihat bagian ini 
        ?>
            <li class="nav-item ps-3 pt-3 pb-1 text-muted text-uppercase small">Manajemen Keuangan</li>
            <li>
                <a href="manage_payments.php" class="nav-link text-dark <?php echo (strpos($current_page, 'payment') !== false) ? 'active' : ''; // Cek jika ada 'payment' di nama file 
                                                                        ?>">
                    <i class="bi bi-currency-dollar"></i>
                    Pembayaran Pelanggan
                </a>
            </li>
            <li>
                <a href="incomes.php" class="nav-link text-dark <?php echo (strpos($current_page, 'income') !== false) ? 'active' : ''; ?>">
                    <i class="bi bi-arrow-down-left"></i>
                    Pemasukan Lain
                </a>
            </li>
            <li>
                <a href="expenses.php" class="nav-link text-dark <?php echo (strpos($current_page, 'expense') !== false) ? 'active' : ''; ?>">
                    <i class="bi bi-arrow-up-right"></i>
                    Pengeluaran
                </a>
            </li>
        <?php endif; ?>
        <?php if (in_array($current_role, ['superadmin', 'admin', 'teknisi'])): // Atur peran yang bisa melihat bagian ini 
        ?>
            <li class="nav-item ps-3 pt-3 pb-1 text-muted text-uppercase small">Data Master & Operasional</li>
            <li>
                <a href="customers.php" class="nav-link text-dark <?php echo ($current_page == 'customers.php') ? 'active' : ''; ?>">
                    <i class="bi bi-person-lines-fill"></i>
                    Daftar Pelanggan
                </a>
            </li>
        <?php endif; ?>
        <?php if ($current_role == 'superadmin'): ?>
            <li>
                <a href="roles.php" class="nav-link text-dark <?php echo ($current_page == 'roles.php') ? 'active' : ''; ?>">
                    <i class="bi bi-shield-lock"></i>
                    Manajemen Peran
                </a>
            </li>
        <?php endif; ?>

        <?php if ($current_role == 'admin'): ?>
            <li>
                <a href="customer_details.php?id=<?php echo htmlspecialchars($user_id_in_session); ?>" class="nav-link text-dark <?php echo ($current_page == 'customer_details.php') ? 'active' : ''; ?>">
                    <i class="bi bi-wallet2"></i>
                    Riwayat Pembayaran Saya
                </a>
            </li>
        <?php endif; ?>

        <li>
            <a href="edit_password.php" class="nav-link text-dark <?php echo ($current_page == 'edit_password.php') ? 'active' : ''; ?>">
                <i class="bi bi-key"></i>
                Ubah Password
            </a>
        </li>
	
	<?php if ($current_role == 'superadmin' || $current_role == 'admin' || $current_role == 'teknisi'): ?>
	<li>
            <a href="technician_payments.php" class="nav-link text-dark <?php echo ($current_page == 'technician_payments.php') ? 'active' : ''; ?>">
                <i class="bi bi-book"></i>
                Data Bayar
            </a>
        </li>

	<?php endif; ?>
	<?php if ($current_role == 'superadmin'): ?>
            <li>
                <a class="list-group-item list-group-item-action list-group-item-light p-3" href="../public/mitra.php">
                    <i class="bi bi-clipboard-data"></i> Daftar Mitra
                </a>
            </li>
        <?php endif; ?>

        <?php if ($current_role == 'superadmin' || $current_role == 'admin'): ?>
            <li>
                <a class="list-group-item list-group-item-action list-group-item-light p-3" href="../public/manage_whatsapp_messages.php">
                    <i class="bi bi-whatsapp"></i> Manajemen Pesan WA
                </a>
            </li>
        <?php endif; ?>
    </ul>
    <hr class="sidebar-divider my-0">
    <ul class="p-0 d-lg-none" style="list-style-type: none;">
        <li class="nav-item dropdown d-lg-none account">
            <a class="nav-link dropdown-toggle justify-content-between" href="#" id="navbarUserDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                <div class="d-flex align-items-center">
                    <img src="https://placehold.jp/cccccc/ffffff/150x150.png?css=%7B%22border-radius%22%3A%22100%25%22%7D"
                        alt="Profile" width="32" height="32" class="rounded-circle me-1">
                    <?php
                    echo htmlspecialchars($_SESSION['username'] ?? $_SESSION['user_name'] ?? 'Guest');
                    ?>
                </div>
                <span class="dropdown-arrow ms-2">▾</span>
            </a>

            <ul class="dropdown-menu dropdown-menu-end mobile-user" aria-labelledby="navbarUserDropdown">
                <li><a class="dropdown-item" href="profile.php">Profile</a></li>
                <li>
                    <hr class="dropdown-divider">
                </li>
                <li><a class="dropdown-item" href="logout.php">Logout</a></li>
            </ul>
        </li>

    </ul>
    <!-- <div class="mt-auto p-3 d-lg-none">

        <a href="logout.php" class="btn btn-outline-danger w-100">
            <i class="bi bi-box-arrow-right"></i> Logout
        </a>
    </div> -->
</div>