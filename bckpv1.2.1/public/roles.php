<?php
// public/manage_roles.php
require_once '../includes/db_connect.php';
require_once '../includes/session.php';
require_once '../includes/functions.php';
check_login();
check_role(['superadmin']); // Hanya superadmin yang bisa mengelola peran

// Set judul halaman
$title = "Manajemen Peran";

$roles = [];
$sql = "SELECT id, role_name FROM roles ORDER BY role_name ASC";
$result = $conn->query($sql);

if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $roles[] = $row;
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
        <div class="d-flex justify-content-between align-items-start flex-column m-4 mx-lg-0 mb-4">
            <h1 class="display-5">Manajemen Peran Pengguna</h1>
            <p class="text-muted">Hati-hati dalam mengubah peran pengguna karena dapat memengaruhi akses.</p>
        </div>

        <div class="table-responsive">
            <table class="table table-striped table-bordered">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Nama Peran</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($roles)): ?>
                        <?php foreach ($roles as $role): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($role['id']); ?></td>
                                <td><?php echo htmlspecialchars($role['role_name']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="2">Tidak ada peran yang ditemukan.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
// Sertakan footer
require_once '../includes/footer.php';
?>