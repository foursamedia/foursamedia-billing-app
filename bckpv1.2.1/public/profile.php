<?php
// public/profile.php
require_once '../includes/db_connect.php';
require_once '../includes/session.php';
require_once '../includes/functions.php';

// Pastikan pengguna sudah login
check_login();

$title = "Profil Saya";
$user_id = $_SESSION['user_id'];
$user_data = null;

$stmt = $conn->prepare("SELECT u.name, u.username, u.email, r.role_name
                        FROM users u
                        JOIN roles r ON u.role_id = r.id
                        WHERE u.id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 1) {
    $user_data = $result->fetch_assoc();
} else {
    // Ini seharusnya tidak terjadi jika user sudah login
    $_SESSION['error_message'] = "Data profil Anda tidak ditemukan.";
    header("Location: logout.php"); // Redirect ke logout jika data tidak ada
    exit();
}
$stmt->close();

require_once '../includes/header.php';
?>

<header class="mb-4">
    <h1 class="display-5">Profil Saya</h1>
</header>

<body>
    <div class="d-flex">
        <?php include_once '../includes/sidebar.php'; ?>

         <div id="page-content-wrapper" class="flex-grow-1">

<div class="card mb-4">
    <div class="card-header">Informasi Profil</div>
    <div class="card-body">
        <?php if (!empty($_SESSION['success_message'])): ?>
            <div class="alert alert-success" role="alert">
                <?php echo htmlspecialchars($_SESSION['success_message']); unset($_SESSION['success_message']); ?>
            </div>
        <?php endif; ?>
        <?php if (!empty($_SESSION['error_message'])): ?>
            <div class="alert alert-danger" role="alert">
                <?php echo htmlspecialchars($_SESSION['error_message']); unset($_SESSION['error_message']); ?>
            </div>
        <?php endif; ?>

        <?php if ($user_data): ?>
            <div class="mb-3">
                <strong>Nama Lengkap:</strong> <?php echo htmlspecialchars($user_data['name']); ?>
            </div>
            <div class="mb-3">
                <strong>Username:</strong> <?php echo htmlspecialchars($user_data['username']); ?>
            </div>
            <div class="mb-3">
                <strong>Email:</strong> <?php echo htmlspecialchars($user_data['email']); ?>
            </div>
            <div class="mb-3">
                <strong>Role:</strong> <?php echo htmlspecialchars(ucfirst($user_data['role_name'])); ?>
            </div>

            <hr>
            <h4>Aksi:</h4>
            <a href="edit_profile.php" class="btn btn-primary me-2">Edit Profil</a> <?php else: ?>
            <div class="alert alert-info" role="alert">
                Tidak dapat memuat data profil Anda.
            </div>
        <?php endif; ?>
    </div>
</div>
</div>
</div>

</body>

<?php require_once '../includes/footer.php'; ?>