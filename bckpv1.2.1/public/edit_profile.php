<?php
// public/edit_profile.php
require_once '../includes/db_connect.php';
require_once '../includes/session.php';
require_once '../includes/functions.php';

// Pastikan pengguna sudah login
check_login();

$title = "Edit Profil Saya";
$user_id = $_SESSION['user_id'];
$user_data = null;
$error_message = '';
$success_message = '';

// Ambil data profil pengguna yang ada
$stmt = $conn->prepare("SELECT u.id, u.name, u.username, u.email
                        FROM users u
                        WHERE u.id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 1) {
    $user_data = $result->fetch_assoc();
} else {
    $_SESSION['error_message'] = "Data profil Anda tidak ditemukan.";
    header("Location: profile.php"); // Redirect kembali ke halaman profil jika data tidak ditemukan
    exit();
}
$stmt->close();

// Proses form submission saat ada POST request
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $new_name = trim($_POST['name'] ?? '');
    $new_username = trim($_POST['username'] ?? '');
    $new_email = trim($_POST['email'] ?? '');

    // Validasi input
    if (empty($new_name) || empty($new_username) || empty($new_email)) {
        $error_message = "Nama lengkap, username, dan email tidak boleh kosong.";
    } elseif (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
        $error_message = "Format email tidak valid.";
    } else {
        // Cek apakah username atau email sudah digunakan oleh orang lain (kecuali diri sendiri)
        $check_stmt = $conn->prepare("SELECT id FROM users WHERE (username = ? OR email = ?) AND id != ?");
        $check_stmt->bind_param("ssi", $new_username, $new_email, $user_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();

        if ($check_result->num_rows > 0) {
            $error_message = "Username atau email sudah digunakan oleh pengguna lain.";
        } else {
            // Lakukan update data di database
            $update_stmt = $conn->prepare("UPDATE users SET name = ?, username = ?, email = ? WHERE id = ?");
            $update_stmt->bind_param("sssi", $new_name, $new_username, $new_email, $user_id);

            if ($update_stmt->execute()) {
                $success_message = "Profil berhasil diperbarui!";
                // Perbarui data sesi juga agar tampilan langsung berubah
                $_SESSION['username'] = $new_name; // Perbarui nama di sesi
                $_SESSION['user_email'] = $new_email; // Perbarui email di sesi
                // Perbarui $user_data agar form menampilkan data yang baru diperbarui
                $user_data['name'] = $new_name;
                $user_data['username'] = $new_username;
                $user_data['email'] = $new_email;
            } else {
                $error_message = "Gagal memperbarui profil: " . $conn->error;
            }
            $update_stmt->close();
        }
        $check_stmt->close();
    }
}


require_once '../includes/header.php';
?>

<header class="mb-4">
    <h1 class="display-5">Edit Profil Saya</h1>
</header>

<div class="card mb-4">
    <div class="card-header">Form Edit Profil</div>
    <div class="card-body">
        <?php if (!empty($error_message)): ?>
            <div class="alert alert-danger" role="alert">
                <?php echo htmlspecialchars($error_message); ?>
            </div>
        <?php endif; ?>
        <?php if (!empty($success_message)): ?>
            <div class="alert alert-success" role="alert">
                <?php echo htmlspecialchars($success_message); ?>
            </div>
        <?php endif; ?>

        <?php if ($user_data): ?>
            <form action="edit_profile.php" method="POST">
                <div class="mb-3">
                    <label for="name" class="form-label">Nama Lengkap</label>
                    <input type="text" class="form-control" id="name" name="name"
                           value="<?php echo htmlspecialchars($user_data['name']); ?>" required>
                </div>
                <div class="mb-3">
                    <label for="username" class="form-label">Username</label>
                    <input type="text" class="form-control" id="username" name="username"
                           value="<?php echo htmlspecialchars($user_data['username']); ?>" required>
                </div>
                <div class="mb-3">
                    <label for="email" class="form-label">Email</label>
                    <input type="email" class="form-control" id="email" name="email"
                           value="<?php echo htmlspecialchars($user_data['email']); ?>" required>
                </div>

                <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
                <a href="profile.php" class="btn btn-secondary ms-2">Batal</a>
            </form>
        <?php else: ?>
            <div class="alert alert-info" role="alert">
                Tidak dapat memuat data profil.
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>