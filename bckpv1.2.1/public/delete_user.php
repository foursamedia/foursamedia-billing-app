<?php
// public/delete_user.php
require_once '../includes/db_connect.php';
require_once '../includes/session.php';
require_once '../includes/functions.php';

// Pastikan pengguna sudah login
check_login();

// HANYA superadmin atau admin yang bisa menghapus pengguna
if ($_SESSION['role_name'] !== 'superadmin' && $_SESSION['role_name'] !== 'admin') { // <<< PERUBAHAN DI SINI
    $_SESSION['error_message'] = "Anda tidak memiliki izin untuk menghapus pengguna.";
    header("Location: manage_users.php"); // Redirect ke halaman kelola pengguna
    exit();
}

// Lanjutkan dengan logika penghapusan yang sudah ada
if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $user_id = $_GET['id'];

    // Pencegahan penghapusan diri sendiri (opsional tapi disarankan)
    if ($user_id == $_SESSION['user_id']) {
        $_SESSION['error_message'] = "Anda tidak bisa menghapus akun Anda sendiri.";
        header("Location: manage_users.php");
        exit();
    }

    // Contoh: hapus semua pembayaran yang terkait dengan pengguna ini terlebih dahulu
    // Ini penting jika Anda tidak menggunakan ON DELETE CASCADE pada tabel payments
    // atau jika Anda ingin memastikan integritas data (misalnya, melog pembayaran ini sebelum dihapus)
    $stmt_payments = $conn->prepare("DELETE FROM payments WHERE user_id = ?");
    $stmt_payments->bind_param("i", $user_id);
    $stmt_payments->execute();
    $stmt_payments->close();

    // Kemudian hapus pengguna
    $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);

    if ($stmt->execute()) {
        $_SESSION['success_message'] = "Pengguna berhasil dihapus.";
    } else {
        $_SESSION['error_message'] = "Gagal menghapus pengguna: " . $conn->error;
    }
    $stmt->close();
} else {
    $_SESSION['error_message'] = "ID pengguna tidak valid.";
}


header("Location: manage_users.php"); // Redirect kembali ke halaman daftar pengguna
exit();
?>