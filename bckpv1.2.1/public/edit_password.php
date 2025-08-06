<?php
// public/edit_password.php
require_once '../includes/db_connect.php';
require_once '../includes/session.php';
require_once '../includes/functions.php';
check_login(); // Pastikan user sudah login

// Set judul halaman
$title = "Ubah Password";

$user_id = $_SESSION['user_id'];
$error_message = '';
$success_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];

    // Ambil password lama dari database
    $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();

    if (!$user) {
        $error_message = "Pengguna tidak ditemukan.";
    } elseif (!password_verify($current_password, $user['password'])) {
        $error_message = "Password saat ini salah.";
    } elseif (empty($new_password) || empty($confirm_password)) {
        $error_message = "Password baru dan konfirmasi tidak boleh kosong.";
    } elseif ($new_password !== $confirm_password) {
        $error_message = "Password baru dan konfirmasi tidak cocok.";
    } elseif (strlen($new_password) < 6) {
        $error_message = "Password baru minimal 6 karakter.";
    } else {
        $hashed_new_password = password_hash($new_password, PASSWORD_DEFAULT);
        $stmt_update = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
        $stmt_update->bind_param("si", $hashed_new_password, $user_id);

        if ($stmt_update->execute()) {
            $success_message = "Password berhasil diubah.";
            // Opsional: Redirect atau kosongkan form
        } else {
            $error_message = "Gagal mengubah password: " . $stmt_update->error;
        }
        $stmt_update->close();
    }
}


// Sertakan header
require_once '../includes/header.php';
?>


<div class="d-flex" id="wrapper">
    <?php
    $sidebar_path = '../includes/sidebar.php';
    if (file_exists($sidebar_path)) {
        include $sidebar_path;
    } else {
        echo "<div style='color: red; padding: 20px;'>Sidebar not found at: " . htmlspecialchars($sidebar_path) . "</div>";
    }
    ?>

    <div id="page-content-wrapper" class="flex-grow-1 mx-2 mx-lg-4 py-lg-4">
        <header class="mb-4">
            <h1 class="display-5">Ubah Password</h1>
        </header>

        <div class="card mb-4">
            <div class="card-body">
                <?php if ($error_message): ?>
                    <div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div>
                <?php elseif ($success_message): ?>
                    <div class="alert alert-success"><?php echo htmlspecialchars($success_message); ?></div>
                <?php endif; ?>

                <form action="edit_password.php" method="POST">
                    <div class="mb-3">
                        <label for="current_password" class="form-label">Password Saat Ini</label>
                        <input type="password" class="form-control" id="current_password" name="current_password" required>
                    </div>
                    <div class="mb-3">
                        <label for="new_password" class="form-label">Password Baru</label>
                        <input type="password" class="form-control" id="new_password" name="new_password" required>
                    </div>
                    <div class="mb-3">
                        <label for="confirm_password" class="form-label">Konfirmasi Password Baru</label>
                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                    </div>
                    <button type="submit" class="btn btn-primary">Ubah Password</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php
// Sertakan footer
require_once '../includes/footer.php';
?>