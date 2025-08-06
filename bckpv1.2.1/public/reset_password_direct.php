<?php
// public/reset_password_direct.php
require_once '../includes/db_connect.php';
require_once '../includes/session.php';
require_once '../includes/functions.php';

$message = '';
$error = '';

// Pastikan ada user_id yang disimpan di sesi dari forgot_password.php
$user_id_to_reset = $_SESSION['reset_user_id'] ?? null;

if ($user_id_to_reset === null) {
    $_SESSION['error_message'] = "Akses tidak sah. Silakan mulai ulang proses lupa password.";
    header("Location: forgot_password.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (empty($new_password) || empty($confirm_password)) {
        $error = "Password baru dan konfirmasi password harus diisi.";
    } elseif ($new_password !== $confirm_password) {
        $error = "Password baru dan konfirmasi password tidak cocok.";
    } elseif (strlen($new_password) < 6) {
        $error = "Password minimal 6 karakter.";
    } else {
        // Hash password baru
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

        // Update password di database
        $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");

        if ($stmt) {
            $stmt->bind_param("si", $hashed_password, $user_id_to_reset);

            if ($stmt->execute()) {
                $message = "Password Anda berhasil direset. Silakan login dengan password baru Anda.";

                // BAGIAN UNTUK LOGGING (Opsional, tapi disarankan untuk tetap ada)
                // Ambil email user untuk log
                $stmt_email = $conn->prepare("SELECT email FROM users WHERE id = ?");
                $stmt_email->bind_param("i", $user_id_to_reset);
                $stmt_email->execute();
                $result_email = $stmt_email->get_result();
                $email_dari_db = '';
                if ($result_email->num_rows > 0) {
                    $email_dari_db = $result_email->fetch_assoc()['email'];
                }
                $stmt_email->close();

                $log_action = 'password_reset_direct';
                $log_ip = $_SERVER['REMOTE_ADDR'] ?? 'N/A';
                $log_user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'N/A';
                $log_description = 'User ' . htmlspecialchars($email_dari_db) . ' (ID: ' . htmlspecialchars($user_id_to_reset) . ') berhasil mereset password secara langsung.';

                $stmt_log = $conn->prepare("INSERT INTO activity_logs (user_id, action, description, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)");
                if ($stmt_log) {
                    $stmt_log->bind_param("issss", $user_id_to_reset, $log_action, $log_description, $log_ip, $log_user_agent);
                    $stmt_log->execute();
                    $stmt_log->close();
                }
                // AKHIR BAGIAN LOGGING

                // Hapus user_id dari sesi setelah reset berhasil untuk keamanan
                unset($_SESSION['reset_user_id']);

                // Set pesan sukses di sesi dan redirect ke halaman login
                $_SESSION['success_message'] = $message;
                header("Location: login.php");
                exit();
            } else {
                $error = "Gagal mereset password. Silakan coba lagi. Error execute: " . $stmt->error;
            }
            $stmt->close();
        } else {
            $error = "Terjadi kesalahan sistem saat menyiapkan pembaruan password. Error prepare: " . $conn->error;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setel Ulang Password</title>
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
            <h2 class="card-title text-center mb-4">Setel Ulang Password</h2>
            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-danger" role="alert">
                    <?php echo $_SESSION['error_message'];
                    unset($_SESSION['error_message']); ?>
                </div>
            <?php endif; ?>
            <?php if (!empty($error)): ?>
                <div class="alert alert-danger" role="alert">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            <?php if (!empty($message)): ?>
                <div class="alert alert-success" role="alert">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>
            <form action="reset_password_direct.php" method="POST">
                <div class="mb-3">
                    <label for="new_password" class="form-label">Password Baru</label>
                    <input type="password" class="form-control" id="new_password" name="new_password" required autofocus>
                </div>
                <div class="mb-3">
                    <label for="confirm_password" class="form-label">Konfirmasi Password Baru</label>
                    <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                </div>
                <button type="submit" class="btn btn-primary w-100">Reset Password</button>
            </form>
            <div class="text-center mt-3">
                <a href="login.php" class="text-decoration-none">Kembali ke Login</a>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>