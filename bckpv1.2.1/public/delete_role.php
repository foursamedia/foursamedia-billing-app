<?php
require_once '../includes/db_connect.php';
require_once '../includes/session.php';
require_once '../includes/functions.php';

check_role(['superadmin', 'admin']);

if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $role_id = $_GET['id'];

    // Ambil role_id default untuk pengguna jika role yang dihapus memiliki user
    // Anda harus memutuskan apa yang terjadi pada pengguna jika perannya dihapus.
    // Opsi:
    // 1. Set role_id user tersebut ke NULL (jika role_id di users mengizinkan NULL)
    // 2. Set role_id user tersebut ke role_id default (misal: 'pelanggan' jika ada)
    // 3. Jangan izinkan penghapusan peran jika ada user yang menggunakannya (lebih aman)
    // Saya memilih opsi 2 (asumsi ada role default, misal 'pelanggan' dengan id 4)
    // Atau bisa juga NULL jika table users.role_id mengizinkan NULL
    
    // Asumsi: jika peran dihapus, pengguna dengan peran tersebut akan diassign ke peran 'pelanggan' (ID 4)
    // Anda harus menyesuaikan ID ini jika 'pelanggan' tidak ID 4 atau jika ingin NULL.
    $default_role_id = null; // Ganti dengan ID peran default jika Anda punya
    $stmt_default_role = $conn->prepare("SELECT id FROM roles WHERE role_name = 'pelanggan'");
    $stmt_default_role->execute();
    $result_default_role = $stmt_default_role->get_result();
    if ($result_default_role->num_rows > 0) {
        $default_role_id = $result_default_role->fetch_assoc()['id'];
    }
    $stmt_default_role->close();


    if ($default_role_id !== null) {
        // Update pengguna yang memiliki peran yang akan dihapus
        $stmt_update_users = $conn->prepare("UPDATE users SET role_id = ? WHERE role_id = ?");
        $stmt_update_users->bind_param("ii", $default_role_id, $role_id);
        $stmt_update_users->execute();
        $stmt_update_users->close();
    } else {
        // Jika tidak ada default role, atau Anda mengizinkan NULL
        // Anda bisa memilih untuk tidak melakukan apa-apa atau set ke NULL jika kolom role_id di users NULLable
        // UPDATE users SET role_id = NULL WHERE role_id = ?;
        // ATAU, lebih baik: Jangan izinkan penghapusan jika ada user yang menggunakannya.
        // Untuk saat ini, asumsikan default role pelanggan selalu ada.
    }


    // Kemudian hapus peran
    $stmt = $conn->prepare("DELETE FROM roles WHERE id = ?");
    $stmt->bind_param("i", $role_id);

    if ($stmt->execute()) {
        header("Location: roles.php?status=success_delete");
        exit();
    } else {
        $_SESSION['error_message'] = "Gagal menghapus peran: " . $stmt->error;
        header("Location: roles.php");
        exit();
    }
    $stmt->close();
} else {
    $_SESSION['error_message'] = "ID peran tidak valid.";
    header("Location: roles.php");
    exit();
}


?>