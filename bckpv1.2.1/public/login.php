<?php
// public/login.php
require_once '../includes/db_connect.php';
require_once '../includes/session.php'; // Pastikan ini ada dan memanggil session_start()
require_once '../includes/functions.php';

$error = '';

// --- AWAL KODE TAMBAHAN UNTUK MENAMPILKAN PESAN SUKSES DARI SESI ---
$success_message = '';
if (isset($_SESSION['success_message'])) {
    $success_message = $_SESSION['success_message'];
    unset($_SESSION['success_message']); // Hapus pesan dari sesi agar tidak muncul lagi setelah refresh
}
// --- AKHIR KODE TAMBAHAN ---

// Jika sudah login, redirect ke halaman utama (index.php)
if (isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $error = "Email dan password harus diisi.";
    } else {
        // Cek kredensial di database
        // Gunakan u.password AS password_hash_from_db jika kolom di DB Anda bernama 'password'
        // Jika kolom di DB Anda bernama 'password_hash', gunakan u.password_hash saja.
        $stmt = $conn->prepare("SELECT u.id, u.name, u.email, u.password AS password_hash_from_db, r.role_name
                                 FROM users u
                                 JOIN roles r ON u.role_id = r.id
                                 WHERE u.email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            // PENTING: Bandingkan dengan kolom yang benar (password_hash_from_db atau password_hash)
            if (password_verify($password, $user['password_hash_from_db'])) { // Ganti password_hash_from_db jika nama kolom DB Anda password_hash
                // Login berhasil, simpan data ke sesi
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['name']; // Menyimpan nama user untuk tampilan
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['role_name'] = $user['role_name'];

                // Selalu redirect ke index.php setelah login berhasil
                header("Location: index.php");
                exit();
            } else {
                $error = "Email atau password salah.";
            }
        } else {
            $error = "Email atau password salah.";
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        body.d-flex {
            background-color: #f8f9fa;
        }
        .card {
            border-radius: 0.5rem;
        }
        .login-logo {
            display: block;
            margin: 0 auto 20px auto; /* Center the logo and add space below it */
            max-width: 150px; /* Adjust size as needed */
            height: auto;
        }
    </style>
</head>
<body class="d-flex justify-content-center align-items-center min-vh-100 bg-light">
    <div class="card shadow" style="max-width: 400px; width: 100%;">
        <div class="card-body p-4">
            <img src="assets/img/logo.png" alt="Logo Perusahaan" class="login-logo">
              <?php if (!empty($error)): ?>
                <div class="alert alert-danger" role="alert">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($success_message)): ?>
                <div class="alert alert-success" role="alert">
                    <?php echo htmlspecialchars($success_message); ?>
                </div>
            <?php endif; ?>
            <form action="login.php" method="POST">
                <div class="mb-3">
                    <label for="email" class="form-label">Email</label>
                    <input type="email" class="form-control" id="email" name="email" required autofocus value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                </div>
                <div class="mb-3">
                    <label for="password" class="form-label">Password</label>
                    <input type="password" class="form-control" id="password" name="password" required>
                </div>
                <button type="submit" class="btn btn-primary w-100">Login</button>
            </form>
            <div class="text-center mt-3">
                <a href="forgot_password.php" class="text-decoration-none">Lupa Password?</a>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>