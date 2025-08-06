<?php
// public/edit_income.php

// Pastikan TIDAK ADA spasi, newline, atau karakter lain sebelum tag <?php ini!
// Ini sangat penting untuk mencegah "headers already sent"

ini_set('display_errors', 1); // Aktifkan untuk debugging
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../includes/db_connect.php';
require_once '../includes/session.php'; // Ini harus di atas untuk mengaktifkan session
require_once '../includes/functions.php'; // Pastikan ini ada dan mengacu ke file functions.php yang benar

check_login();
// Peran yang diizinkan untuk mengedit pemasukan
check_role(['superadmin', 'admin', 'finance']);

$title = "Edit Pemasukan";
$income_data = null; // Variabel untuk menyimpan data pemasukan yang diambil dari DB
$error_message_local = ''; // Variabel untuk pesan error validasi form langsung

// Ambil ID dari URL
$income_id = $_GET['id'] ?? null;

if (!$income_id) {
    $_SESSION['error_message'] = "ID Pemasukan tidak ditemukan.";
    header("Location: incomes.php");
    exit();
}

// --- Logika Ambil Data Pemasukan yang Akan Diedit (untuk ditampilkan di form) ---
// Ini akan selalu dijalankan untuk memuat data awal atau data terbaru setelah POST
$stmt_select = $conn->prepare("
    SELECT
        i.id,
        i.income_date,
        i.description,
        i.amount,
        i.created_at,
        i.updated_at,
        u.username AS input_by_username
    FROM
        incomes i
    JOIN
        users u ON i.input_by_user_id = u.id
    WHERE
        i.id = ?
");

if ($stmt_select) {
    $stmt_select->bind_param("i", $income_id);
    $stmt_select->execute();
    $result_select = $stmt_select->get_result();
    if ($result_select->num_rows == 1) {
        $income_data = $result_select->fetch_assoc();
    } else {
        $_SESSION['error_message'] = "Pemasukan tidak ditemukan.";
        header("Location: incomes.php");
        exit();
    }
    $stmt_select->close();
} else {
    $_SESSION['error_message'] = "Gagal menyiapkan pengambilan data: " . $conn->error;
    header("Location: incomes.php");
    exit();
}

// Inisialisasi variabel form dengan data yang ada dari database
// Ini penting agar nilai di input form terisi saat halaman pertama kali dimuat
$income_date = $income_data['income_date'];
$description = $income_data['description'];
$amount = $income_data['amount'];


// --- Proses form jika disubmit (POST Request) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Ambil data dari POST
    $income_date_post = $_POST['income_date'] ?? '';
    $description_post = trim($_POST['description'] ?? '');
    $amount_post = filter_var($_POST['amount'] ?? '', FILTER_VALIDATE_FLOAT);
    $user_id_input_by = $_SESSION['user_id']; // ID user yang sedang login

    // Validasi input
    if (empty($description_post) || $amount_post === false || $amount_post <= 0 || empty($income_date_post)) {
        $error_message_local = "Semua kolom wajib diisi dan Jumlah harus angka positif.";
    } elseif (!isValidDate($income_date_post)) {
        $error_message_local = "Format tanggal pemasukan tidak valid.";
    } else {
        // Mulai transaksi
        $conn->begin_transaction();
        try {
            // Update data di database
            // updated_at akan otomatis diupdate oleh MySQL karena ON UPDATE CURRENT_TIMESTAMP
            // Kita juga menyimpan user_id yang mengupdate (input_by_user_id)
            $stmt_update = $conn->prepare("UPDATE incomes SET income_date = ?, description = ?, amount = ?, input_by_user_id = ? WHERE id = ?");

            if ($stmt_update) {
                $stmt_update->bind_param("ssdii", $income_date_post, $description_post, $amount_post, $user_id_input_by, $income_id);

                if ($stmt_update->execute()) {
                    $conn->commit();
                    $_SESSION['success_message'] = "Pemasukan berhasil diperbarui.";
                    header("Location: incomes.php"); // Redirect ke halaman daftar pemasukan
                    exit();
                } else {
                    throw new Exception("Gagal memperbarui pemasukan: " . $stmt_update->error);
                }
                $stmt_update->close();
            } else {
                throw new Exception("Gagal menyiapkan statement update: " . $conn->error);
            }
        } catch (Exception $e) {
            $conn->rollback();
            $error_message_local = $e->getMessage(); // Tangkap pesan error untuk ditampilkan
        }
    }

    // Jika ada error validasi atau database (dan tidak ada redirect),
    // data yang dimasukkan akan tetap ada di form
    $income_date = $income_date_post;
    $description = $description_post;
    $amount = $amount_post;
}

// Sekarang baru include header setelah semua logika PHP selesai
require_once '../includes/header.php';
// sidebar.php biasanya sudah di-include di header.php
?>

<div class="main-content">
    <div class="container-fluid">
        <header class="mb-4">
            <h1 class="display-5">Edit Pemasukan</h1>
            <a href="incomes.php" class="btn btn-secondary">Kembali ke Daftar Pemasukan</a>
        </header>

        <div class="card mb-4">
            <div class="card-body">
                <?php
                // Tampilkan pesan error validasi langsung (jika ada)
                if ($error_message_local) {
                    echo '<div class="alert alert-danger alert-dismissible fade show" role="alert">';
                    echo htmlspecialchars($error_message_local);
                    echo '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>';
                    echo '</div>';
                }
                // Tampilkan pesan sukses/error dari session (misal setelah redirect dari validasi ID)
                display_session_messages();
                ?>

                <?php if ($income_data): // Pastikan data pemasukan ada sebelum menampilkan form ?>
                    <form action="edit_income.php?id=<?= htmlspecialchars($income_id) ?>" method="POST">
                        <div class="mb-3">
                            <label for="income_date" class="form-label">Tanggal Pemasukan</label>
                            <input type="date" class="form-control" id="income_date" name="income_date" value="<?= htmlspecialchars($income_date) ?>" required>
                        </div>
                        <div class="mb-3">
                            <label for="description" class="form-label">Deskripsi Pemasukan</label>
                            <input type="text" class="form-control" id="description" name="description" value="<?= htmlspecialchars($description) ?>" required>
                        </div>
                        <div class="mb-3">
                            <label for="amount" class="form-label">Jumlah (Rp)</label>
                            <input type="number" class="form-control" id="amount" name="amount" step="0.01" min="0.01" value="<?= htmlspecialchars($amount) ?>" required>
                        </div>

                        <div class="mb-3">
                            <label>Dicatat Oleh:</label>
                            <p class="form-control-static"><?= htmlspecialchars($income_data['input_by_username']) ?></p>
                            <small class="form-text text-muted">Dicatat oleh pengguna ini. Perubahan akan disimpan atas nama Anda (pengguna saat ini).</small>
                        </div>
                        <div class="mb-3">
                            <label>Dibuat Pada:</label>
                            <p class="form-control-static"><?= htmlspecialchars(format_datetime($income_data['created_at'])) ?></p>
                        </div>
                        <div class="mb-3">
                            <label>Terakhir Diperbarui:</label>
                            <p class="form-control-static"><?= htmlspecialchars(format_datetime($income_data['updated_at'])) ?></p>
                        </div>

                        <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
                        <a href="incomes.php" class="btn btn-secondary">Batal</a>
                    </form>
                <?php else: ?>
                    <div class="alert alert-warning text-center" role="alert">
                        Data pemasukan tidak dapat dimuat. Silakan kembali ke daftar pemasukan.
                        <a href="incomes.php" class="alert-link">Daftar Pemasukan</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php
require_once '../includes/footer.php';
?>