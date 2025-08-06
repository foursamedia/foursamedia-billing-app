<?php
// public/edit_customer.php
require_once '../includes/db_connect.php';
require_once '../includes/session.php';
require_once '../includes/functions.php';

// Pastikan hanya superadmin atau admin yang bisa mengedit data pelanggan
check_role(['superadmin', 'admin']);

$customer_id = null;
$customer_data = null;
$message = '';

if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $customer_id = $_GET['id'];

    // Ambil data pelanggan yang akan diedit
    $stmt_fetch = $conn->prepare("
        SELECT
            u.id,
            u.name,
            u.email,
            c.phone,
            c.address,
            u.created_at
        FROM users u
        LEFT JOIN customers c ON u.id = c.user_id
        WHERE u.id = ? AND u.role_id = (SELECT id FROM roles WHERE role_name = 'pelanggan')
    ");
    $stmt_fetch->bind_param("i", $customer_id);
    $stmt_fetch->execute();
    $result_fetch = $stmt_fetch->get_result();

    if ($result_fetch->num_rows > 0) {
        $customer_data = $result_fetch->fetch_assoc();
    } else {
        $message = '<div class="alert alert-danger" role="alert">Pelanggan tidak ditemukan atau bukan role pelanggan.</div>';
        $customer_id = null; // Set null agar form tidak ditampilkan
    }
    $stmt_fetch->close();

} else {
    // Jika tidak ada ID yang valid di URL, redirect kembali ke daftar pelanggan
    header('Location: customers.php');
    exit();
}

// Tangani Update Data Pelanggan jika Form Disubmit
if ($_SERVER['REQUEST_METHOD'] == 'POST' && $customer_id) {
    $name = trim($_POST['name']);
    $email = filter_var(trim($_POST['email']), FILTER_VALIDATE_EMAIL);
    $phone = trim($_POST['phone']);
    $address = trim($_POST['address']);

    if (empty($name) || !$email) {
        $message = '<div class="alert alert-danger" role="alert">Nama dan Email wajib diisi dan harus valid.</div>';
    } else {
        // Mulai transaksi untuk memastikan konsistensi data di kedua tabel
        $conn->begin_transaction();
        try {
            // Update tabel users
            $stmt_update_user = $conn->prepare("UPDATE users SET name = ?, email = ? WHERE id = ?");
            $stmt_update_user->bind_param("ssi", $name, $email, $customer_id);
            $stmt_update_user->execute();
            $stmt_update_user->close();

            // Update atau Insert ke tabel customers
            // Cek apakah data pelanggan sudah ada di tabel customers
            $stmt_check_customer_detail = $conn->prepare("SELECT id FROM customers WHERE user_id = ?");
            $stmt_check_customer_detail->bind_param("i", $customer_id);
            $stmt_check_customer_detail->execute();
            $result_check = $stmt_check_customer_detail->get_result();

            if ($result_check->num_rows > 0) {
                // Data sudah ada, lakukan UPDATE
                $stmt_update_customer_detail = $conn->prepare("UPDATE customers SET phone = ?, address = ? WHERE user_id = ?");
                $stmt_update_customer_detail->bind_param("ssi", $phone, $address, $customer_id);
                $stmt_update_customer_detail->execute();
                $stmt_update_customer_detail->close();
            } else {
                // Data belum ada, lakukan INSERT
                $stmt_insert_customer_detail = $conn->prepare("INSERT INTO customers (user_id, phone, address) VALUES (?, ?, ?)");
                $stmt_insert_customer_detail->bind_param("iss", $customer_id, $phone, $address);
                $stmt_insert_customer_detail->execute();
                $stmt_insert_customer_detail->close();
            }
            $stmt_check_customer_detail->close();

            $conn->commit(); // Commit transaksi jika semua berhasil
            $message = '<div class="alert alert-success" role="alert">Data pelanggan berhasil diperbarui!</div>';

            // Refresh data pelanggan setelah update (untuk memastikan data terbaru di form)
            $stmt_refresh = $conn->prepare("
                SELECT
                    u.id,
                    u.name,
                    u.email,
                    c.phone,
                    c.address,
                    u.created_at
                FROM users u
                LEFT JOIN customers c ON u.id = c.user_id
                WHERE u.id = ?
            ");
            $stmt_refresh->bind_param("i", $customer_id);
            $stmt_refresh->execute();
            $customer_data = $stmt_refresh->get_result()->fetch_assoc();
            $stmt_refresh->close();

        } catch (mysqli_sql_exception $e) {
            $conn->rollback(); // Rollback jika ada error
            $message = '<div class="alert alert-danger" role="alert">Gagal memperbarui data pelanggan: ' . $e->getMessage() . '</div>';
        }
    }
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Pelanggan</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
</head>
<body>
    <div class="d-flex">
        <?php include_once '../includes/sidebar.php'; ?>

         <div id="page-content-wrapper" class="flex-grow-1">
            <header class="mb-4">
                <h1 class="display-5">Edit Data Pelanggan</h1>
                <?php if ($customer_id): ?>
                    <a href="customer_details.php?id=<?php echo htmlspecialchars($customer_id); ?>" class="btn btn-secondary btn-sm"><i class="bi bi-arrow-left"></i> Kembali ke Detail Pelanggan</a>
                <?php else: ?>
                    <a href="customers.php" class="btn btn-secondary btn-sm"><i class="bi bi-arrow-left"></i> Kembali ke Daftar Pelanggan</a>
                <?php endif; ?>
            </header>

            <?php echo $message; ?>

            <?php if ($customer_data): ?>
                <div class="card mb-4">
                    <div class="card-header">Edit Informasi Pelanggan: <?php echo htmlspecialchars($customer_data['name']); ?></div>
                    <div class="card-body">
                        <form action="edit_customer.php?id=<?php echo htmlspecialchars($customer_id); ?>" method="POST">
                            <div class="mb-3">
                                <label for="name" class="form-label">Nama Pelanggan <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="name" name="name" value="<?php echo htmlspecialchars($customer_data['name']); ?>" required>
                            </div>
                            <div class="mb-3">
                                <label for="email" class="form-label">Email <span class="text-danger">*</span></label>
                                <input type="email" class="form-control" id="email" name="email" value="<?php echo htmlspecialchars($customer_data['email']); ?>" required>
                            </div>
                            <div class="mb-3">
                                <label for="phone" class="form-label">Telepon</label>
                                <input type="text" class="form-control" id="phone" name="phone" value="<?php echo htmlspecialchars($customer_data['phone'] ?? ''); ?>">
                            </div>
                            <div class="mb-3">
                                <label for="address" class="form-label">Alamat</label>
                                <textarea class="form-control" id="address" name="address" rows="3"><?php echo htmlspecialchars($customer_data['address'] ?? ''); ?></textarea>
                            </div>
                            <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Simpan Perubahan</button>
                        </form>
                    </div>
                </div>
            <?php elseif (empty($message)): // Hanya tampilkan ini jika tidak ada data dan tidak ada pesan error spesifik ?>
                <div class="alert alert-info" role="alert">
                    Memuat data pelanggan...
                </div>
            <?php endif; ?>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>