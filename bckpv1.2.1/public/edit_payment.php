<?php
// public/edit_payment.php
require_once '../includes/db_connect.php';
require_once '../includes/session.php';
require_once '../includes/functions.php';
check_login();
check_role(['superadmin', 'admin', 'teknisi']);

// Set judul halaman
$title = "Edit Pembayaran";

$payment_id = $_GET['id'] ?? 0;
$payment = null;
$users = [];
$error_message = '';

// Ambil daftar user/pelanggan
$sql_users = "SELECT id, name, email FROM users WHERE role_id = (SELECT id FROM roles WHERE role_name = 'pelanggan') ORDER BY name ASC";
$result_users = $conn->query($sql_users);
if ($result_users->num_rows > 0) {
    while ($row = $result_users->fetch_assoc()) {
        $users[] = $row;
    }
}

// Ambil data pembayaran yang akan diedit, TERMASUK input_record_date
if ($payment_id > 0) {
    // Mengubah kolom 'input_date' menjadi 'input_record_date' dalam SELECT statement
    $stmt_payment = $conn->prepare("SELECT id, user_id, amount, payment_date, input_record_date, description FROM payments WHERE id = ?");
    $stmt_payment->bind_param("i", $payment_id);
    $stmt_payment->execute();
    $result_payment = $stmt_payment->get_result();
    if ($result_payment->num_rows > 0) {
        $payment = $result_payment->fetch_assoc();
    } else {
        $error_message = "Pembayaran tidak ditemukan.";
    }
    $stmt_payment->close();
} else {
    $error_message = "ID pembayaran tidak valid.";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $payment) {
    $user_id = $_POST['user_id'];
    $amount = filter_var($_POST['amount'], FILTER_VALIDATE_FLOAT);
    $payment_date = trim($_POST['payment_date']);
    $input_record_date = trim($_POST['input_record_date']); // Mengambil input_record_date dari POST
    $description = trim($_POST['description']);
    // input_by_user_id tidak diubah saat edit, hanya saat add

    // Validasi semua field, termasuk input_record_date
    if (empty($user_id) || $amount === false || $amount <= 0 || empty($payment_date) || empty($input_record_date) || empty($description)) {
        $error_message = "Semua field harus diisi dengan benar (jumlah harus angka positif).";
    } else {
        // Memperbarui query UPDATE untuk menyertakan input_record_date
        $stmt_update = $conn->prepare("UPDATE payments SET user_id = ?, amount = ?, payment_date = ?, input_record_date = ?, description = ? WHERE id = ?");
        // Menyesuaikan bind_param: 's' tambahan untuk input_record_date
        $stmt_update->bind_param("idsssi", $user_id, $amount, $payment_date, $input_record_date, $description, $payment_id);

        if ($stmt_update->execute()) {
            header("Location: manage_payments.php?status=success_edit");
            exit();
        } else {
            $error_message = "Gagal memperbarui pembayaran: " . $stmt_update->error;
        }
        $stmt_update->close();
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
        <div class="d-flex justify-content-between align-items-center flex-column flex-lg-row gap-3 m-4 mx-lg-0 mb-4">
            <h1 class="display-5">Edit Pembayaran</h1>
            <a href="manage_payments.php" class="btn btn-secondary">Kembali ke Manajemen Pembayaran</a>
        </div>
        <div class="card mb-4">
            <div class="card-body">
                <?php if ($error_message): ?>
                    <div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div>
                <?php elseif (!$payment): ?>
                    <div class="alert alert-warning">Pembayaran tidak ditemukan atau ID tidak valid.</div>
                <?php else: ?>
                    <form action="edit_payment.php?id=<?php echo htmlspecialchars($payment_id); ?>" method="POST">
                        <div class="mb-3">
                            <label for="user_id" class="form-label">Pelanggan</label>
                            <select class="form-select" id="user_id" name="user_id" required>
                                <option value="">Pilih Pelanggan</option>
                                <?php foreach ($users as $user): ?>
                                    <option value="<?php echo htmlspecialchars($user['id']); ?>"
                                        <?php
                                        // Pilih user berdasarkan data pembayaran atau data POST jika ada error form
                                        echo ((isset($_POST['user_id']) && $_POST['user_id'] == $user['id']) || (!isset($_POST['user_id']) && $payment['user_id'] == $user['id'])) ? 'selected' : '';
                                        ?>>
                                        <?php echo htmlspecialchars($user['name']) . " (" . htmlspecialchars($user['email']) . ")"; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="amount" class="form-label">Jumlah Pembayaran (Rp)</label>
                            <input type="number" class="form-control" id="amount" name="amount" step="0.01" min="0.01" required value="<?php echo htmlspecialchars($_POST['amount'] ?? $payment['amount']); ?>">
                        </div>
                        <div class="mb-3">
                            <label for="payment_date" class="form-label">Tanggal Pembayaran</label>
                            <input type="date" class="form-control" id="payment_date" name="payment_date" required value="<?php echo htmlspecialchars($_POST['payment_date'] ?? $payment['payment_date']); ?>">
                        </div>
                        <!-- Perubahan: Field Tanggal Input Bayar (input_record_date) -->
                        <div class="mb-3">
                            <label for="input_record_date" class="form-label">Tanggal Input Catatan</label>
                            <input type="date" class="form-control" id="input_record_date" name="input_record_date" required value="<?php echo htmlspecialchars($_POST['input_record_date'] ?? (isset($payment['input_record_date']) ? date('Y-m-d', strtotime($payment['input_record_date'])) : '')); ?>">
                        </div>
                        <div class="mb-3">
                            <label for="description" class="form-label">Deskripsi</label>
                            <textarea class="form-control" id="description" name="description" rows="3" required><?php echo htmlspecialchars($_POST['description'] ?? $payment['description']); ?></textarea>
                        </div>
                        <button type="submit" class="btn btn-primary">Perbarui Pembayaran</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>


</div>
<?php
// Sertakan footer
require_once '../includes/footer.php';
?>