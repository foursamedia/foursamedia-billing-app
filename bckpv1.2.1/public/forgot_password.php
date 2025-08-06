<?php
// public/forgot_password.php
require_once '../includes/db_connect.php';
require_once '../includes/session.php';
require_once '../includes/functions.php';

$message = ''; // Untuk pesan sukses/info
$error = '';   // Untuk pesan error

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = $_POST['email'] ?? '';

    if (empty($email)) {
        $error = "Silakan masukkan alamat email Anda.";
    } else {
        // Cek apakah email ada di database
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $user = $result->fetch_assoc();
            // Email ditemukan, simpan ID user ke sesi dan langsung redirect
            $_SESSION['reset_user_id'] = $user['id'];
            header("Location: reset_password_direct.php"); // <<< LANGSUNG REDIRECT
            exit();
        } else {
            // Email tidak ditemukan, tampilkan pesan error langsung
            $error = "Email tidak terdaftar."; // <<< PESAN SPESIFIK
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
    <title>Lupa Password</title>
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
            margin: 0 auto 20px auto;
            max-width: 150px;
            height: auto;
        }
    </style>
</head>
<body class="d-flex justify-content-center align-items-center min-vh-100 bg-light">
    <div class="card shadow" style="max-width: 400px; width: 100%;">
        <div class="card-body p-4">
            <img src="assets/img/logo.png" alt="Logo Perusahaan" class="login-logo">
            <h2 class="card-title text-center mb-4">Lupa Password</h2>
            <?php if (!empty($error)): ?>
                <div class="alert alert-danger" role="alert">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            <?php if (!empty($message)): ?>
                <div class="alert alert-info" role="alert">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>
            <form action="forgot_password.php" method="POST">
                <div class="mb-3">
                    <label for="email" class="form-label">Masukkan Email Anda</label>
                    <input type="email" class="form-control" id="email" name="email" required autofocus value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                </div>
                <button type="submit" class="btn btn-primary w-100">Kirim Link Reset</button>
            </form>
            <div class="text-center mt-3">
                <a href="login.php" class="text-decoration-none">Kembali ke Login</a>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>