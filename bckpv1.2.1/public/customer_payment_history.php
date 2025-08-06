<?php
// public/customer_payment_history.php
require_once '../includes/db_connect.php';
require_once '../includes/session.php';
require_once '../includes/functions.php';

// Pastikan pengguna sudah login
check_login();

// Pastikan perannya adalah 'pelanggan'. Jika tidak, arahkan ke dashboard mereka.
// Ini penting agar admin/teknisi tidak langsung mengakses halaman ini (mereka punya manage_payments.php)
if ($_SESSION['role_name'] !== 'pelanggan') {
    header("Location: dashboard.php"); // Arahkan ke dashboard admin/teknisi
    exit();
}

$title = "Riwayat Pembayaran Anda";
$user_id = $_SESSION['user_id']; // ID pelanggan yang sedang login

$payments = []; // Array untuk menyimpan data pembayaran

// AWAL LOGIKA PAGINASI
$current_page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
// Opsi jumlah data per halaman, disesuaikan agar lebih relevan untuk pelanggan
$limit_per_page_options = [10, 20, 50]; 
$limit_per_page = isset($_GET['limit']) && in_array((int)$_GET['limit'], $limit_per_page_options) ? (int)$_GET['limit'] : 10;
$offset = ($current_page - 1) * $limit_per_page;

$total_payments = 0; // Inisialisasi total pembayaran
// Periksa apakah ini permintaan AJAX
$is_ajax_request = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
// AKHIR LOGIKA PAGINASI

// Query untuk menghitung total pembayaran untuk pelanggan yang sedang login
$count_sql = "SELECT COUNT(id) FROM payments WHERE user_id = ?";
$stmt_count = $conn->prepare($count_sql);
if ($stmt_count) {
    $stmt_count->bind_param("i", $user_id); // Bind user_id
    $stmt_count->execute();
    $stmt_count->bind_result($total_payments);
    $stmt_count->fetch();
    $stmt_count->close();
} else {
    // Log error jika persiapan statement gagal
    // error_log("Error preparing count statement for customer payments: " . $conn->error);
}

// Hitung total halaman
$total_pages = ceil($total_payments / $limit_per_page);

// Query untuk mengambil riwayat pembayaran pelanggan yang sedang login dengan paginasi
// Informasi 'input_by_user' diambil untuk menampilkan siapa yang menginput pembayaran
$sql = "SELECT p.id, p.amount, p.payment_date, p.description,
               ibu.name AS input_by_user_name, ibu.email AS input_by_user_email
        FROM payments p
        JOIN users ibu ON p.input_by_user_id = ibu.id
        WHERE p.user_id = ?
        ORDER BY p.payment_date DESC, p.id DESC
        LIMIT ? OFFSET ?";

$stmt = $conn->prepare($sql);
if ($stmt === false) {
    // Jika persiapan statement gagal, hentikan eksekusi dan tampilkan error
    die("Error preparing statement: " . $conn->error);
}

// Bind parameter: user_id (integer), limit (integer), offset (integer)
$stmt->bind_param("iii", $user_id, $limit_per_page, $offset);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $payments[] = $row;
    }
}
$stmt->close(); // Tutup statement

// Jika ini adalah permintaan AJAX, kirimkan JSON berisi HTML tbody dan HTML paginasi
if ($is_ajax_request) {
    ob_start(); // Mulai output buffering untuk menangkap HTML tbody
    ?>
    <?php if (!empty($payments)): ?>
        <?php foreach ($payments as $payment): ?>
        <tr>
            <td><?php echo htmlspecialchars($payment['id']); ?></td>
            <td><?php echo "Rp" . number_format($payment['amount'], 0, ',', '.'); ?></td>
            <td><?php echo htmlspecialchars($payment['payment_date']); ?></td>
            <td><?php echo htmlspecialchars($payment['description']); ?></td>
            <td>
                <?php echo htmlspecialchars($payment['input_by_user_name']); ?><br>
                <small class="text-muted"><?php echo htmlspecialchars($payment['input_by_user_email']); ?></small>
            </td>
        </tr>
        <?php endforeach; ?>
    <?php else: ?>
        <tr>
            <td colspan="5">
                <div class="alert alert-info mb-0" role="alert">
                    Belum ada riwayat pembayaran yang tercatat untuk Anda.
                </div>
            </td>
        </tr>
    <?php endif; ?>
    <?php
    $tbody_content = ob_get_clean(); // Ambil konten tbody

    ob_start(); // Mulai output buffering lagi untuk menangkap HTML paginasi
    ?>
    <nav aria-label="Page navigation" class="mt-3">
        <ul class="pagination justify-content-center">
            <li class="page-item <?php echo ($current_page <= 1) ? 'disabled' : ''; ?>">
                <a class="page-link" href="#" data-page="<?php echo $current_page - 1; ?>">Previous</a>
            </li>
            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                <li class="page-item <?php echo ($i == $current_page) ? 'active' : ''; ?>">
                    <a class="page-link" href="#" data-page="<?php echo $i; ?>"><?php echo $i; ?></a>
                </li>
            <?php endfor; ?>
            <li class="page-item <?php echo ($current_page >= $total_pages) ? 'disabled' : ''; ?>">
                <a class="page-link" href="#" data-page="<?php echo $current_page + 1; ?>">Next</a>
            </li>
        </ul>
    </nav>
    <div class="d-flex justify-content-end align-items-center mt-2">
        <label for="limitPerPageCustomerHistory" class="form-label me-2 mb-0">Tampilkan:</label>
        <select class="form-select form-select-sm w-auto" id="limitPerPageCustomerHistory">
            <?php foreach ($limit_per_page_options as $option): ?>
                <option value="<?php echo $option; ?>" <?php echo ($option == $limit_per_page) ? 'selected' : ''; ?>>
                    <?php echo $option; ?>
                </option>
            <?php endforeach; ?>
        </select>
        <span class="ms-2 text-muted">dari <span id="totalPaymentsCustomerHistoryCount"><?php echo $total_payments; ?></span> data</span>
    </div>
    <?php
    $pagination_content = ob_get_clean(); // Ambil konten paginasi

   
    // Kirim respons dalam format JSON
    echo json_encode([
        'html' => $tbody_content,
        'pagination_html' => $pagination_content,
        'total_payments' => $total_payments // Kirim total pembayaran untuk update tampilan
    ]);
    exit(); // Hentikan eksekusi script setelah mengirim respons AJAX
}

require_once '../includes/header.php'; // Sertakan header HTML (termasuk sidebar)
?>

<header class="mb-4">
    <h1 class="display-5">Riwayat Pembayaran Anda</h1>
</header>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>ID Pembayaran</th>
                        <th>Jumlah</th>
                        <th>Tanggal Pembayaran</th>
                        <th>Deskripsi</th>
                        <th>Input Oleh</th>
                    </tr>
                </thead>
                <tbody id="customerHistoryTableBody">
                    <?php // Konten tbody akan diisi oleh PHP saat load awal, atau oleh JS via Ajax ?>
                    <?php if (!empty($payments)): ?>
                        <?php foreach ($payments as $payment): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($payment['id']); ?></td>
                            <td><?php echo "Rp" . number_format($payment['amount'], 0, ',', '.'); ?></td>
                            <td><?php echo htmlspecialchars($payment['payment_date']); ?></td>
                            <td><?php echo htmlspecialchars($payment['description']); ?></td>
                            <td>
                                <?php echo htmlspecialchars($payment['input_by_user_name']); ?><br>
                                <small class="text-muted"><?php echo htmlspecialchars($payment['input_by_user_email']); ?></small>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5">
                                <div class="alert alert-info mb-0" role="alert">
                                    Belum ada riwayat pembayaran yang tercatat untuk Anda.
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div id="paginationControlsCustomerHistory">
            <nav aria-label="Page navigation" class="mt-3">
                <ul class="pagination justify-content-center">
                    <li class="page-item <?php echo ($current_page <= 1) ? 'disabled' : ''; ?>">
                        <a class="page-link" href="#" data-page="<?php echo $current_page - 1; ?>">Previous</a>
                    </li>
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <li class="page-item <?php echo ($i == $current_page) ? 'active' : ''; ?>">
                            <a class="page-link" href="#" data-page="<?php echo $i; ?>"><?php echo $i; ?></a>
                        </li>
                    <?php endfor; ?>
                    <li class="page-item <?php echo ($current_page >= $total_pages) ? 'disabled' : ''; ?>">
                        <a class="page-link" href="#" data-page="<?php echo $current_page + 1; ?>">Next</a>
                    </li>
                </ul>
            </nav>
            <div class="d-flex justify-content-end align-items-center mt-2">
                <label for="limitPerPageCustomerHistory" class="form-label me-2 mb-0">Tampilkan:</label>
                <select class="form-select form-select-sm w-auto" id="limitPerPageCustomerHistory">
                    <?php foreach ($limit_per_page_options as $option): ?>
                        <option value="<?php echo $option; ?>" <?php echo ($option == $limit_per_page) ? 'selected' : ''; ?>>
                            <?php echo $option; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <span class="ms-2 text-muted">dari <span id="totalPaymentsCustomerHistoryCount"><?php echo $total_payments; ?></span> data</span>
            </div>
        </div>

    </div> </div> <?php require_once '../includes/footer.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const customerHistoryTableBody = document.getElementById('customerHistoryTableBody');
    const paginationControlsCustomerHistoryDiv = document.getElementById('paginationControlsCustomerHistory');
    const limitPerPageCustomerHistorySelect = document.getElementById('limitPerPageCustomerHistory');
    const totalPaymentsCustomerHistoryCountSpan = document.getElementById('totalPaymentsCustomerHistoryCount');
    
    // Fungsi untuk memuat data pembayaran pelanggan berdasarkan parameter halaman dan limit
    function loadCustomerPayments(page = 1, limit = <?php echo $limit_per_page; ?>) {
        let url = `customer_payment_history.php?page=${page}&limit=${limit}`;

        fetch(url, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest' // Penting untuk deteksi AJAX di PHP
            }
        })
        .then(response => {
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            return response.json(); // Menguraikan respons JSON
        })
        .then(data => {
            customerHistoryTableBody.innerHTML = data.html; // Perbarui isi tbody
            paginationControlsCustomerHistoryDiv.innerHTML = data.pagination_html; // Perbarui kontrol paginasi
            totalPaymentsCustomerHistoryCountSpan.textContent = data.total_payments; // Perbarui total data

            // Re-attach event listeners untuk elemen paginasi yang baru dimuat
            attachPaginationEventListenersCustomerHistory();

            // Sembunyikan atau tampilkan kontrol paginasi jika tidak ada data
            if (data.total_payments === 0) {
                paginationControlsCustomerHistoryDiv.style.display = 'none';
            } else {
                paginationControlsCustomerHistoryDiv.style.display = 'block';
            }
        })
        .catch(error => {
            console.error('Error fetching customer payment history:', error);
            if (customerHistoryTableBody) {
                customerHistoryTableBody.innerHTML = `<tr><td colspan="5" class="text-danger">Terjadi kesalahan saat memuat riwayat pembayaran.</td></tr>`;
            }
            if (paginationControlsCustomerHistoryDiv) {
                paginationControlsCustomerHistoryDiv.style.display = 'none';
            }
        });
    }

    // Fungsi untuk melampirkan event listener ke elemen paginasi (tombol halaman dan dropdown limit)
    function attachPaginationEventListenersCustomerHistory() {
        // Event listener untuk tombol halaman (Previous, Next, Nomor Halaman)
        document.querySelectorAll('#paginationControlsCustomerHistory .page-link').forEach(link => {
            link.removeEventListener('click', handlePageClickCustomerHistory); // Hapus event listener lama
            link.addEventListener('click', handlePageClickCustomerHistory); // Tambahkan yang baru
        });

        // Event listener untuk dropdown 'Tampilkan'
        const currentLimitSelect = document.getElementById('limitPerPageCustomerHistory');
        if (currentLimitSelect) {
            currentLimitSelect.removeEventListener('change', handleLimitChangeCustomerHistory); // Hapus event listener lama
            currentLimitSelect.addEventListener('change', handleLimitChangeCustomerHistory); // Tambahkan yang baru
        }
    }

    // Handler untuk klik tombol halaman paginasi
    function handlePageClickCustomerHistory(e) {
        e.preventDefault(); // Mencegah link berpindah halaman
        const newPage = parseInt(this.dataset.page); // Ambil nomor halaman dari data-page atribut
        const currentLimit = parseInt(limitPerPageCustomerHistorySelect.value); // Ambil limit per halaman saat ini
        if (!isNaN(newPage) && newPage > 0) { // Validasi dasar nomor halaman
            loadCustomerPayments(newPage, currentLimit);
        }
    }

    // Handler untuk perubahan pada dropdown 'Tampilkan' (limit per halaman)
    function handleLimitChangeCustomerHistory() {
        const newLimit = parseInt(this.value); // Ambil nilai limit yang baru
        loadCustomerPayments(1, newLimit); // Kembali ke halaman 1 saat limit berubah
    }

    // Inisialisasi: Panggil attachPaginationEventListenersCustomerHistory saat DOM sudah dimuat
    // Ini memastikan bahwa event listeners terpasang pada kontrol paginasi yang dirender saat halaman pertama kali dimuat.
    attachPaginationEventListenersCustomerHistory();

    // Sembunyikan atau tampilkan paginasi awal berdasarkan total_payments
    if (<?php echo json_encode($total_payments); ?> === 0) {
        if (paginationControlsCustomerHistoryDiv) {
            paginationControlsCustomerHistoryDiv.style.display = 'none';
        }
    } else {
        if (paginationControlsCustomerHistoryDiv) {
            paginationControlsCustomerHistoryDiv.style.display = 'block';
        }
    }
});
</script>