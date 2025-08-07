<?php
// public/add_income.php
require_once '../includes/db_connect.php';
require_once '../includes/session.php';
require_once '../includes/functions.php';
check_login();
// Peran yang diizinkan untuk menambah pemasukan
// Sesuaikan dengan kebutuhan Anda, sebelumnya Anda menggunakan 'superadmin', 'admin', 'finance'
check_role(['superadmin', 'admin', 'finance']);

$title = "Tambah Pemasukan Baru";
$error_message = '';

// Inisialisasi variabel form
$description = '';
$amount = '';
$income_date = date('Y-m-d'); // Tanggal default hari ini

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $description = trim($_POST['description']);
    $amount = filter_var($_POST['amount'], FILTER_VALIDATE_FLOAT); // Validasi float
    $income_date = $_POST['income_date'];
    $input_by_user_id = $_SESSION['user_id']; // Ambil dari session

    // Validasi input
    if (empty($description) || $amount === false || $amount <= 0 || empty($income_date)) {
        $error_message = "Semua kolom wajib diisi dan Jumlah harus angka positif.";
    } elseif (!isValidDate($income_date)) { // Pastikan fungsi isValidDate() ada di functions.php
        $error_message = "Format tanggal tidak valid.";
    } else {
        // Masukkan data ke database
        // Ubah nama tabel dari 'expenses' ke 'incomes' dan kolom 'expense_date' ke 'income_date'
        $stmt_insert = $conn->prepare("INSERT INTO incomes (description, amount, income_date, input_by_user_id) VALUES (?, ?, ?, ?)");

        if ($stmt_insert) {
            // "sdsi" -> string (description), double (amount), string (income_date), integer (input_by_user_id)
            $stmt_insert->bind_param("sdsi", $description, $amount, $income_date, $input_by_user_id);

            if ($stmt_insert->execute()) {
                $_SESSION['success_message'] = "Pemasukan berhasil ditambahkan.";
                header("Location: incomes.php"); // Redirect ke halaman daftar pemasukan
                exit();
            } else {
                $error_message = "Gagal menambahkan pemasukan: " . $stmt_insert->error;
            }
            $stmt_insert->close();
        } else {
            $error_message = "Gagal menyiapkan statement: " . $conn->error;
        }
    }
}


include_once '../includes/header.php'; // Menggunakan include_once
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
            <h1 class="display-5">Tambah Pemasukan Baru</h1>
            <a href="incomes.php" class="btn btn-secondary">Kembali ke Daftar Pemasukan</a>
        </div>
        <div class="card mb-4">
            <div class="card-body">
                <?php if ($error_message): ?>
                    <div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div>
                <?php endif; ?>
                <form action="add_income.php" method="POST">
                    <div class="mb-3">
                        <label for="description" class="form-label">Deskripsi Pemasukan</label>
                        <input type="text" class="form-control" id="description" name="description" value="<?php echo htmlspecialchars($description); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label for="amount" class="form-label">Jumlah (Rp)</label>
                        <input type="number" class="form-control" id="amount" name="amount" step="0.01" min="0.01" value="<?php echo htmlspecialchars($amount); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label for="income_date" class="form-label">Tanggal Pemasukan</label>
                        <input type="date" class="form-control" id="income_date" name="income_date" value="<?php echo htmlspecialchars($income_date); ?>" required>
                    </div>
                    <button type="submit" class="btn btn-primary">Simpan Pemasukan</button>
                </form>
            </div>
        </div>
    </div>


</div>
</div>
</div>

<?php
include_once '../includes/footer.php'; // Menggunakan include_once
?>