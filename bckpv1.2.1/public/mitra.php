<?php
// public/mitra.php

// Pastikan file-file penting dimuat
require_once '../includes/db_connect.php';
require_once '../includes/session.php';
require_once '../includes/functions.php';

// Pastikan pengguna sudah login
check_login();

// Cek peran yang diizinkan untuk halaman ini. Hanya superadmin dan admin.
// Mitra tidak bisa melihat daftar mitra lain.
check_role(['superadmin', 'admin']);

// Ambil data mitra dari database
// Diasumsikan peran 'mitra' ada di tabel 'roles' dan tabel 'users' memiliki kolom 'role_id'
$mitra = [];
$stmt = $conn->prepare("
    SELECT
        u.id,
        u.name,
        u.email,
        u.phone,
        u.address,
        u.created_at,
        r.role_name
    FROM
        users u
    LEFT JOIN
        roles r ON u.role_id = r.id
    WHERE
        r.role_name = 'mitra'
    ORDER BY u.name ASC
");
if ($stmt) {
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $mitra[] = $row;
    }
    $stmt->close();
}

$title = "Daftar Mitra";

require_once '../includes/header.php';
?>

<div class="d-flex" id="wrapper">
    <?php include_once '../includes/sidebar.php'; ?>

    <div id="page-content-wrapper" class="flex-grow-1">
        <div class="container-fluid px-4">
            <div class="row">
                <div class="col-lg-12">
                    <header class="mb-4">
                        <h1 class="display-5">Daftar Mitra</h1>
                        <p class="lead">Kelola dan lihat informasi detail mitra.</p>
                    </header>

                    <?php if (isset($_SESSION['success_message'])): ?>
                        <div class="alert alert-success"><?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?></div>
                    <?php endif; ?>

                    <div class="card mb-4">
                        <div class="card-header">
                            Daftar Mitra Terdaftar
                        </div>
                        <div class="card-body">
                            <?php if (!empty($mitra)): ?>
                                <div class="table-responsive">
                                    <table class="table table-hover table-striped">
                                        <thead>
                                            <tr>
                                                <th>Nama</th>
                                                <th>Email</th>
                                                <th>Telepon</th>
                                                <th>Bergabung Sejak</th>
                                                <th>Aksi</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($mitra as $m): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($m['name']); ?></td>
                                                    <td><?php echo htmlspecialchars($m['email']); ?></td>
                                                    <td><?php echo htmlspecialchars($m['phone']); ?></td>
                                                    <td><?php echo htmlspecialchars((new DateTime($m['created_at']))->format('d M Y')); ?></td>
                                                    <td>
                                                        <a href="mitra_detail.php?id=<?php echo htmlspecialchars($m['id']); ?>" class="btn btn-info btn-sm">Detail</a>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <div class="alert alert-info">Tidak ada data mitra yang ditemukan.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
