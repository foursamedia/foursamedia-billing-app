<?php
require_once '../includes/db_connect.php';
require_once '../includes/session.php';
require_once '../includes/functions.php';

check_role(['superadmin', 'admin']);

$role_id = $_GET['id'] ?? null;
$role = null;
$error = '';

if ($role_id && is_numeric($role_id)) {
    // Ambil data peran yang akan diedit
    $stmt_role = $conn->prepare("SELECT id, role_name FROM roles WHERE id = ?");
    $stmt_role->bind_param("i", $role_id);
    $stmt_role->execute();
    $result_role = $stmt_role->get_result();

    if ($result_role->num_rows === 1) {
        $role = $result_role->fetch_assoc();
    } else {
        $error = "Peran tidak ditemukan.";
    }
    $stmt_role->close();
} else {
    $error = "ID peran tidak valid.";
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && $role) {
    $role_name = trim($_POST['role_name']);

    if (empty($role_name)) {
        $error = "Nama peran harus diisi.";
    } else {
        // Cek apakah nama peran sudah terdaftar untuk peran lain
        $stmt_check_role = $conn->prepare("SELECT id FROM roles WHERE role_name = ? AND id != ?");
        $stmt_check_role->bind_param("si", $role_name, $role_id);
        $stmt_check_role->execute();
        $result_check_role = $stmt_check_role->get_result();
        if ($result_check_role->num_rows > 0) {
            $error = "Nama peran sudah terdaftar.";
        }
        $stmt_check_role->close();

        if (empty($error)) {
            $stmt = $conn->prepare("UPDATE roles SET role_name = ? WHERE id = ?");
            $stmt->bind_param("si", $role_name, $role_id);

            if ($stmt->execute()) {
                header("Location: roles.php?status=success_edit");
                exit();
            } else {
                $error = "Gagal memperbarui peran: " . $stmt->error;
            }
            $stmt->close();
        }
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Peran</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
</head>
<body>
    <div class="d-flex">
        <?php include_once '../includes/sidebar.php'; ?>

         <div id="page-content-wrapper" class="flex-grow-1">
            <header class="mb-4">
                <h1 class="display-5">Edit Peran</h1>
                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger mt-3"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>
            </header>

            <?php if ($role): ?>
                <form action="edit_role.php?id=<?php echo htmlspecialchars($role_id); ?>" method="POST">
                    <div class="mb-3">
                        <label for="role_name" class="form-label">Nama Peran</label>
                        <input type="text" class="form-control" id="role_name" name="role_name" required value="<?php echo htmlspecialchars($_POST['role_name'] ?? $role['role_name']); ?>">
                    </div>
                    <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
                    <a href="roles.php" class="btn btn-secondary">Batal</a>
                </form>
            <?php else: ?>
                <div class="alert alert-warning">Data peran tidak ditemukan atau ID tidak valid.</div>
            <?php endif; ?>
        </div>
    </div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
   
</body>
</html>