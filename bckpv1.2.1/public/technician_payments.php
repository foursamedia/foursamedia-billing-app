<?php
// public/technician_payments.php
require_once '../includes/db_connect.php';
require_once '../includes/session.php';
require_once '../includes/functions.php';

// Pastikan pengguna sudah login dan memiliki peran 'teknisi'
check_login();
check_role(['teknisi','admin','superadmin']);

$title = "Ringkasan Pembayaran Saya";

$logged_in_user_id = $_SESSION['user_id'];
$logged_in_username = $_SESSION['username']; // Ambil username dari sesi

// --- Variabel untuk Total Pembayaran Diinput (sekarang tidak ditampilkan di UI, tapi mungkin masih digunakan di bagian lain) ---
$total_payments_by_technician = 0;

// Query untuk mengambil total pembayaran diinput oleh teknisi ini
// Meskipun kartunya dihapus, data ini mungkin masih relevan untuk keperluan lain atau audit,
// jadi querynya tetap dipertahankan. Jika tidak dibutuhkan sama sekali, baris ini bisa dihapus juga.
$stmt_total = $conn->prepare("SELECT SUM(amount) FROM payments WHERE input_by_user_id = ?");
if ($stmt_total) {
    $stmt_total->bind_param("i", $logged_in_user_id);
    $stmt_total->execute();
    $stmt_total->bind_result($total_payments_by_technician);
    $stmt_total->fetch();
    $stmt_total->close();
}

// --- Data untuk Detail Pembayaran Bulanan & Rincian Penjumlahan Harian (berdasarkan input_record_date) ---
$monthly_payments_summary = []; // Akan menyimpan label dan total amount per bulan
$monthly_payments_details = []; // Akan menyimpan rincian penjumlahan per tanggal input_record_date per bulan

$current_year = date('Y');
$first_day_of_current_year = new DateTime("$current_year-01-01");
$last_day_of_current_year = new DateTime("$current_year-12-31");

// Query untuk mendapatkan total bulanan berdasarkan input_record_date
$sql_monthly_tech_summary = "
    SELECT
        DATE_FORMAT(input_record_date, '%Y-%m') AS month_key,
        SUM(amount) AS total_monthly_amount
    FROM payments
    WHERE input_by_user_id = ? AND input_record_date BETWEEN ? AND ?
    GROUP BY month_key
    ORDER BY month_key ASC
";

$stmt_monthly_tech_summary = $conn->prepare($sql_monthly_tech_summary);
if ($stmt_monthly_tech_summary) {
    $stmt_monthly_tech_summary->bind_param("iss", $logged_in_user_id, $first_day_of_current_year->format('Y-m-d'), $last_day_of_current_year->format('Y-m-d'));
    $stmt_monthly_tech_summary->execute();
    $result_monthly_tech_summary = $stmt_monthly_tech_summary->get_result();

    $fetched_summary_map = [];
    while ($row = $result_monthly_tech_summary->fetch_assoc()) {
        $fetched_summary_map[$row['month_key']] = $row['total_monthly_amount'];
    }
    $stmt_monthly_tech_summary->close();
}

// Query untuk mendapatkan penjumlahan pembayaran harian berdasarkan input_record_date
$sql_daily_tech_payments_by_input_date = "
    SELECT
        DATE_FORMAT(input_record_date, '%Y-%m') AS month_key,
        DATE(input_record_date) AS input_date,
        SUM(amount) AS daily_total_amount
    FROM
        payments
    WHERE
        input_by_user_id = ? AND input_record_date BETWEEN ? AND ?
    GROUP BY
        month_key, input_date
    ORDER BY
        input_date ASC
";

$stmt_daily_tech_payments_by_input_date = $conn->prepare($sql_daily_tech_payments_by_input_date);
if ($stmt_daily_tech_payments_by_input_date) {
    $stmt_daily_tech_payments_by_input_date->bind_param("iss", $logged_in_user_id, $first_day_of_current_year->format('Y-m-d'), $last_day_of_current_year->format('Y-m-d'));
    $stmt_daily_tech_payments_by_input_date->execute();
    $result_daily_tech_payments_by_input_date = $stmt_daily_tech_payments_by_input_date->get_result();

    while ($row = $result_daily_tech_payments_by_input_date->fetch_assoc()) {
        $month_key = $row['month_key'];
        if (!isset($monthly_payments_details[$month_key])) {
            $monthly_payments_details[$month_key] = [];
        }
        // Simpan total harian dengan format tanggal
        $monthly_payments_details[$month_key][] = [
            'input_date' => $row['input_date'],
            'total_amount' => (float)$row['daily_total_amount']
        ];
    }
    $stmt_daily_tech_payments_by_input_date->close();
}

// Isi data untuk daftar poin, pastikan semua 12 bulan tercakup (Januari - Desember)
$iterator_month = clone $first_day_of_current_year;
$end_of_year_for_loop = new DateTime("$current_year-12-01"); // Loop hingga Desember tahun berjalan
while ($iterator_month <= $end_of_year_for_loop) {
    $month_key = $iterator_month->format('Y-m');
    $month_label = $iterator_month->format('M Y'); // Contoh: 'Jul 2025'
    
    $monthly_payments_summary[] = [
        'month_key' => $month_key, // Tambahkan month_key untuk referensi dropdown
        'label' => $month_label,
        'amount' => $fetched_summary_map[$month_key] ?? 0
    ];
    $iterator_month->modify('+1 month');
}

require_once '../includes/header.php';
?>

<div class="d-flex" id="wrapper">
    <?php include_once '../includes/sidebar.php'; ?>

    <div id="page-content-wrapper" class="flex-grow-1 mx-2 mx-lg-4 py-lg-4">
        <div class="d-flex justify-content-between align-items-center flex-column flex-lg-row gap-3 m-4 mx-lg-0 mb-4">
            <h1 class="display-5">Ringkasan Pembayaran Saya</h1>
            <p class="lead">Pembayaran yang diinput oleh: <?php echo htmlspecialchars($logged_in_username); ?></p>
        </div>

        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success mt-3"><?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?></div>
        <?php endif; ?>
        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="alert alert-danger mt-3"><?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?></div>
        <?php endif; ?>

        <?php
        /*
        <div class="row g-3 mb-4">
            <div class="col-md-6 col-lg-4">
                <div class="card text-white bg-success h-100">
                    <div class="card-body">
                        <h5 class="card-title">Total Pembayaran Diinput</h5>
                        <p class="card-text fs-2">Rp <?php echo number_format($total_payments_by_technician, 0, ',', '.'); ?></p>
                    </div>
                </div>
            </div>
        </div>
        */
        ?>

        <div class="row mt-3">
            <div class="col-lg-12">
                <div class="card mb-4">
                    <div class="card-header bg-info text-white">
                        Detail Pembayaran Bulanan (Tahun <?php echo $current_year; ?>) Berdasarkan Tanggal Input
                    </div>
                    <div class="card-body">
                        <ul class="list-group list-group-flush">
                            <?php foreach ($monthly_payments_summary as $item): ?>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <span>
                                        <strong><?php echo htmlspecialchars($item['label']); ?>:</strong>
                                        Rp <?php echo number_format($item['amount'], 0, ',', '.'); ?>
                                    </span>
                                    <?php
                                    // Cek apakah ada detail pembayaran per tanggal untuk bulan ini
                                    $has_details = isset($monthly_payments_details[$item['month_key']]) && !empty($monthly_payments_details[$item['month_key']]);
                                    ?>
                                    <?php if ($has_details): ?>
                                        <button class="btn btn-sm btn-outline-primary"
                                                data-bs-toggle="modal"
                                                data-bs-target="#paymentDetailModal"
                                                data-month-key="<?php echo htmlspecialchars($item['month_key']); ?>"
                                                data-month-label="<?php echo htmlspecialchars($item['label']); ?>">
                                            Lihat Detail (<?php echo count($monthly_payments_details[$item['month_key']]); ?> Tanggal)
                                        </button>
                                    <?php else: ?>
                                        <span class="text-muted">Tidak ada detail</span>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        </div>
</div>

<div class="modal fade" id="paymentDetailModal" tabindex="-1" aria-labelledby="paymentDetailModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="paymentDetailModalLabel">Ringkasan Pembayaran Harian untuk <span id="modalMonthLabel"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="table-responsive">
                    <table class="table table-striped table-bordered" id="modalPaymentTable">
                        <thead>
                            <tr>
                                <th>Tanggal Input</th>
                                <th>Total Jumlah</th>
                            </tr>
                        </thead>
                        <tbody>
                            </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
            </div>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var paymentDetailModal = document.getElementById('paymentDetailModal');
    
    // Event listener yang akan aktif setiap kali modal akan ditampilkan
    paymentDetailModal.addEventListener('show.bs.modal', function (event) {
        // Tombol yang memicu modal
        var button = event.relatedTarget; 
        
        // Ambil informasi bulan (key dan label) dari atribut data-bs-* pada tombol
        var monthKey = button.getAttribute('data-month-key');
        var monthLabel = button.getAttribute('data-month-label');

        // Update judul modal dengan label bulan yang sesuai
        var modalMonthLabel = paymentDetailModal.querySelector('#modalMonthLabel');
        modalMonthLabel.textContent = monthLabel;

        // Ambil data detail harian yang sudah di-encode dari PHP.
        // Variabel `monthlyPaymentsDetailsJS` ini berisi semua data penjumlahan harian per bulan.
        const monthlyPaymentsDetailsJS = <?php echo json_encode($monthly_payments_details); ?>;
        
        var tableBody = paymentDetailModal.querySelector('#modalPaymentTable tbody');
        tableBody.innerHTML = ''; // Kosongkan isi tabel sebelumnya untuk menghindari duplikasi data

        // Dapatkan data penjumlahan harian spesifik untuk bulan yang dipilih
        const dailySumsForThisMonth = monthlyPaymentsDetailsJS[monthKey];

        // Periksa apakah ada data penjumlahan harian untuk bulan ini
        if (dailySumsForThisMonth && dailySumsForThisMonth.length > 0) {
            // Loop melalui setiap objek penjumlahan harian (tanggal dan total amount)
            dailySumsForThisMonth.forEach(function(dailySum) {
                var row = `
                    <tr>
                        <td>${dailySum.input_date}</td>
                        <td>Rp${parseFloat(dailySum.total_amount).toLocaleString('id-ID')}</td>
                    </tr>
                `;
                tableBody.insertAdjacentHTML('beforeend', row); // Tambahkan baris ke tabel
            });
        } else {
            // Jika tidak ada data penjumlahan harian untuk bulan ini
            var row = `
                <tr>
                    <td colspan="2" class="text-center">Tidak ada pembayaran yang diinput pada bulan ini.</td>
                </tr>
            `;
            tableBody.insertAdjacentHTML('beforeend', row);
        }
    });
});
</script>