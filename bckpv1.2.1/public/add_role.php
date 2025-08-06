<?php
require_once '../includes/db_connect.php';
require_once '../includes/session.php';
require_once '../includes/functions.php';

check_role(['superadmin', 'admin']);

$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $role_name = trim($_POST['role_name']);

    if (empty($role_name)) {
        $error = "Nama peran harus diisi.";
    } else {
        // Cek apakah nama peran sudah ada
        $stmt_check_role = $conn->prepare("SELECT id FROM roles WHERE role_name = ?");
        $stmt_check_role->bind_param("s", $role_name);
        $stmt_check_role->execute();
        $result_check_role = $stmt_check_role->get_result();
        if ($result_check_role->num_rows > 0) {
            $error = "Nama peran sudah ada.";
        }
        $stmt_check_role->close();

        if (empty($error)) {
            $stmt = $conn->prepare("INSERT INTO roles (role_name) VALUES (?)");
            $stmt->bind_param("s", $role_name);

            if ($stmt->execute()) {
                header("Location: roles.php?status=success_add");
                exit();
            } else {
                $error = "Gagal menambahkan peran: " . $stmt->error;
            }
            $stmt->close();
        }
    }
}


?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tambah Peran Baru</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
</head>
<body>
    <div class="d-flex">
        <?php include_once '../includes/sidebar.php'; ?>

         <div id="page-content-wrapper" class="flex-grow-1">
            <header class="mb-4">
                <h1 class="display-5">Tambah Peran Baru</h1>
                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger mt-3"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>
            </header>

            <form action="add_role.php" method="POST">
                <div class="mb-3">
                    <label for="role_name" class="form-label">Nama Peran</label>
                    <input type="text" class="form-control" id="role_name" name="role_name" required value="<?php echo htmlspecialchars($_POST['role_name'] ?? ''); ?>">
                </div>
                <button type="submit" class="btn btn-primary">Tambah Peran</button>
                <a href="roles.php" class="btn btn-secondary">Batal</a>
            </form>
        </div>
    </div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
   
</body>
</html>