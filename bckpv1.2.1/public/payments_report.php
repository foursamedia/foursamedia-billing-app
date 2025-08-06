<?php
// public/payments_report.php
require_once '../includes/db_connect.php';
require_once '../includes/session.php';
require_once '../includes/functions.php';

check_login();
check_role(['superadmin', 'admin', 'teknisi']); // Hanya peran ini yang bisa melihat laporan pembayaran

$title = "Laporan Pembayaran";

// Default sorting dan filter
$sort_by = $_GET['sort_by'] ?? 'payment_date';
$sort_order = $_GET['sort_order'] ?? 'DESC';
$search_customer_name = $_GET['search_customer_name'] ?? '';
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';
$start_input_date = $_GET['start_input_date'] ?? ''; // Filter berdasarkan input_record_date
$end_input_date = $_GET['end_input_date'] ?? '';     // Filter berdasarkan input_record_date
$payment_method_filter = $_GET['payment_method_filter'] ?? '';

// Validasi sort_by untuk mencegah SQL Injection
$allowed_sort_by = ['payment_date', 'amount', 'name', 'input_record_date'];
if (!in_array($sort_by, $allowed_sort_by)) {
    $sort_by = 'payment_date';
}

// Validasi sort_order
$sort_order = strtoupper($sort_order);
if (!in_array($sort_order, ['ASC', 'DESC'])) {
    $sort_order = 'DESC';
}

// Pengaturan paginasi
$current_page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$limit_per_page_options = [10, 25, 50, 100];
$limit_per_page = isset($_GET['limit']) && in_array((int)$_GET['limit'], $limit_per_page_options) ? (int)$_GET['limit'] : 10;
$offset = ($current_page - 1) * $limit_per_page;

$total_payments = 0;
$payments = [];
$total_filtered_amount = 0; // Variabel untuk menyimpan total jumlah pembayaran yang difilter

$is_ajax_request = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

// AWAL QUERY SQL UTAMA DAN COUNT UNTUK FILTER
$base_sql = "
    SELECT
        p.id,
        p.user_id,
        u.name AS customer_name,
        p.amount,
        p.payment_date,
        p.description,
        p.payment_method,
        p.reference_number,
        p.input_by_user_id,
        iu.name AS input_by_user_name,
        p.input_record_date -- Tambahkan kolom input_record_date
    FROM
        payments p
    JOIN
        users u ON p.user_id = u.id
    LEFT JOIN
        users iu ON p.input_by_user_id = iu.id
    WHERE 1=1
";

$count_sql = "
    SELECT COUNT(p.id)
    FROM payments p
    JOIN users u ON p.user_id = u.id
    LEFT JOIN users iu ON p.input_by_user_id = iu.id
    WHERE 1=1
";

$sum_sql = "
    SELECT SUM(p.amount) AS total_amount
    FROM payments p
    JOIN users u ON p.user_id = u.id
    LEFT JOIN users iu ON p.input_by_user_id = iu.id
    WHERE 1=1
";


$params = [];
$param_types = "";

// Tambahkan kondisi pencarian nama pelanggan
if (!empty($search_customer_name)) {
    $base_sql .= " AND u.name LIKE ?";
    $count_sql .= " AND u.name LIKE ?";
    $sum_sql .= " AND u.name LIKE ?";
    $search_param = '%' . $search_customer_name . '%';
    $params[] = $search_param;
    $param_types .= "s";
}

// Tambahkan filter tanggal pembayaran
if (!empty($start_date)) {
    $base_sql .= " AND p.payment_date >= ?";
    $count_sql .= " AND p.payment_date >= ?";
    $sum_sql .= " AND p.payment_date >= ?";
    $params[] = $start_date;
    $param_types .= "s";
}
if (!empty($end_date)) {
    $base_sql .= " AND p.payment_date <= ?";
    $count_sql .= " AND p.payment_date <= ?";
    $sum_sql .= " AND p.payment_date <= ?";
    $params[] = $end_date;
    $param_types .= "s";
}

// Tambahkan filter tanggal input (input_record_date)
if (!empty($start_input_date)) {
    $base_sql .= " AND p.input_record_date >= ?";
    $count_sql .= " AND p.input_record_date >= ?";
    $sum_sql .= " AND p.input_record_date >= ?";
    $params[] = $start_input_date;
    $param_types .= "s";
}
if (!empty($end_input_date)) {
    $base_sql .= " AND p.input_record_date <= ?";
    $count_sql .= " AND p.input_record_date <= ?";
    $sum_sql .= " AND p.input_record_date <= ?";
    $params[] = $end_input_date;
    $param_types .= "s";
}

// Tambahkan filter metode pembayaran
if (!empty($payment_method_filter)) {
    $base_sql .= " AND p.payment_method = ?";
    $count_sql .= " AND p.payment_method = ?";
    $sum_sql .= " AND p.payment_method = ?";
    $params[] = $payment_method_filter;
    $param_types .= "s";
}

// Salin parameter dan tipe untuk count dan sum query sebelum limit/offset ditambahkan ke $params utama
$original_params_for_where = $params;
$original_param_types_for_where = $param_types;

// Eksekusi COUNT query
$stmt_count = $conn->prepare($count_sql);
if ($stmt_count === false) {
    error_log("Failed to prepare count statement: " . $conn->error);
    $_SESSION['error_message'] = "Gagal menyiapkan statement count: " . $conn->error;
} else {
    if (!empty($original_params_for_where) && !empty($original_param_types_for_where)) {
        $bind_count_params = [];
        $bind_count_params[] = &$original_param_types_for_where;
        foreach ($original_params_for_where as $key => &$val) {
            $bind_count_params[] = &$val;
        }
        call_user_func_array([$stmt_count, 'bind_param'], $bind_count_params);
    }
    $stmt_count->execute();
    $stmt_count->bind_result($total_payments);
    $stmt_count->fetch();
    $stmt_count->close();
}

// Eksekusi SUM query
$stmt_sum = $conn->prepare($sum_sql);
if ($stmt_sum === false) {
    error_log("Failed to prepare sum statement: " . $conn->error);
    $_SESSION['error_message'] = "Gagal menyiapkan statement sum: " . $conn->error;
} else {
    if (!empty($original_params_for_where) && !empty($original_param_types_for_where)) {
        $bind_sum_params = [];
        $bind_sum_params[] = &$original_param_types_for_where;
        foreach ($original_params_for_where as $key => &$val) {
            $bind_sum_params[] = &$val;
        }
        call_user_func_array([$stmt_sum, 'bind_param'], $bind_sum_params);
    }
    $stmt_sum->execute();
    $stmt_sum->bind_result($total_filtered_amount);
    $stmt_sum->fetch();
    $stmt_sum->close();
    $total_filtered_amount = $total_filtered_amount ?? 0; // Pastikan 0 jika NULL
}

$total_pages = ceil($total_payments / $limit_per_page);

// Tambahkan ORDER BY dan LIMIT/OFFSET ke query utama
$base_sql .= " ORDER BY " . $sort_by . " " . $sort_order . " LIMIT ? OFFSET ?";
$params[] = $limit_per_page;
$params[] = $offset;
$param_types .= "ii"; // Menambahkan 'ii' untuk LIMIT dan OFFSET

$stmt = $conn->prepare($base_sql);
if ($stmt === false) {
    $_SESSION['error_message'] = "Gagal menyiapkan statement utama: " . $conn->error;
    $payments = [];
} else {
    if (!empty($params) && !empty($param_types)) {
        $bind_main_params = [];
        $bind_main_params[] = &$param_types;
        foreach ($params as $key => &$val) {
            $bind_main_params[] = &$val;
        }
        call_user_func_array([$stmt, 'bind_param'], $bind_main_params);
    }
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $payments[] = $row;
        }
    }
    $stmt->close();
}
// AKHIR QUERY SQL UTAMA DAN COUNT UNTUK FILTER

// Function to render payment table rows HTML
function renderPaymentTableRows($payments) {
    ob_start();
    if (!empty($payments)):
        foreach ($payments as $payment):
            $payment_id = htmlspecialchars($payment['id']);
            $customer_id = htmlspecialchars($payment['user_id']);
            $customer_name = htmlspecialchars($payment['customer_name']);
            $amount = number_format($payment['amount'], 0, ',', '.');
            $payment_date_formatted = (new DateTime($payment['payment_date']))->format('d M Y');
            $input_record_date_formatted = (new DateTime($payment['input_record_date']))->format('d M Y H:i:s');
            $description = htmlspecialchars($payment['description'] ?? '-');
            $payment_method = htmlspecialchars($payment['payment_method']);
            $reference_number = htmlspecialchars($payment['reference_number'] ?? '-');
            $input_by_user_name = htmlspecialchars($payment['input_by_user_name'] ?? 'N/A');
        ?>
            <tr>
                <td><?php echo $customer_name; ?></td>
                <td>Rp <?php echo $amount; ?></td>
                <td><?php echo $payment_date_formatted; ?></td>
                <td><?php echo $input_record_date_formatted; ?></td>
                <td><?php echo $payment_method; ?></td>
                <td><?php echo $reference_number; ?></td>
                <td><?php echo $input_by_user_name; ?></td>
                <td><?php echo $description; ?></td>
                <td>
                    <a href='edit_payment.php?id=<?php echo $payment_id; ?>' class='btn btn-sm btn-warning me-1' title="Edit Pembayaran"><i class="bi bi-pencil-fill"></i></a>
                    <a href='delete_payment.php?id=<?php echo $payment_id; ?>' class='btn btn-sm btn-danger' onclick="return confirm('Apakah Anda yakin ingin menghapus pembayaran ini?')" title="Hapus Pembayaran"><i class="bi bi-trash-fill"></i></a>
                </td>
            </tr>
        <?php endforeach; ?>
    <?php else: ?>
        <tr>
            <td colspan="9">
                <div class="alert alert-info mb-0" role="alert">
                    Tidak ada data pembayaran yang ditemukan dengan kriteria tersebut.
                </div>
            </td>
        </tr>
    <?php endif;
    return ob_get_clean();
}

// Function to render pagination controls HTML
function renderPaginationControls($current_page, $total_pages, $total_payments, $limit_per_page, $limit_per_page_options) {
    ob_start();
?>
    <nav aria-label="Page navigation" class="mt-3">
        <ul class="pagination justify-content-center">
            <?php if ($current_page > 1): ?>
                <li class="page-item">
                    <a class="page-link" href="#" data-page="<?php echo $current_page - 1; ?>">Previous</a>
                </li>
            <?php else: ?>
                <li class="page-item disabled">
                    <span class="page-link">Previous</span>
                </li>
            <?php endif; ?>

            <?php
            $start_page = max(1, $current_page - 2);
            $end_page = min($total_pages, $current_page + 2);

            if ($start_page > 1) {
                echo '<li class="page-item"><a class="page-link" href="#" data-page="1">1</a></li>';
                if ($start_page > 2) {
                    echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                }
            }

            for ($i = $start_page; $i <= $end_page; $i++):
            ?>
                <li class="page-item <?php echo ($i == $current_page) ? 'active' : ''; ?>">
                    <a class="page-link" href="#" data-page="<?php echo $i; ?>"><?php echo $i; ?></a>
                </li>
            <?php endfor; ?>

            <?php if ($end_page < $total_pages): ?>
                <?php if ($end_page < $total_pages - 1): ?>
                    <li class="page-item disabled"><span class="page-link">...</span></li>
                <?php endif; ?>
                <li class="page-item">
                    <a class="page-link" href="#" data-page="<?php echo $total_pages; ?>"><?php echo $total_pages; ?></a>
                </li>
            <?php endif; ?>

            <?php if ($current_page < $total_pages): ?>
                <li class="page-item">
                    <a class="page-link" href="#" data-page="<?php echo $current_page + 1; ?>">Next</a>
                </li>
            <?php else: ?>
                <li class="page-item disabled">
                    <span class="page-link">Next</span>
                </li>
            <?php endif; ?>
        </ul>
    </nav>
    <div class="d-flex justify-content-end align-items-center mt-2">
        <label for="limitPerPage" class="form-label me-2 mb-0">Tampilkan:</label>
        <select class="form-select form-select-sm w-auto" id="limitPerPage">
            <?php foreach ($limit_per_page_options as $option): ?>
                <option value="<?php echo $option; ?>" <?php echo ($option == $limit_per_page) ? 'selected' : ''; ?>>
                    <?php echo $option; ?>
                </option>
            <?php endforeach; ?>
        </select>
        <span class="ms-2 text-muted">dari <span id="totalPaymentsCount"><?php echo $total_payments; ?></span> data</span>
    </div>
<?php
    return ob_get_clean();
}


// Handle AJAX Request for content update
if ($is_ajax_request) {
    echo json_encode([
        'html' => renderPaymentTableRows($payments),
        'pagination_html' => renderPaginationControls($current_page, $total_pages, $total_payments, $limit_per_page, $limit_per_page_options),
        'total_payments' => $total_payments,
        'total_filtered_amount_formatted' => 'Rp ' . number_format($total_filtered_amount, 0, ',', '.')
    ]);
    exit();
}

require_once '../includes/header.php';
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
<style>
    /* Styles for the sticky header and search bar */
    .sticky-header-container {
        position: sticky;
        top: 0;
        background-color: #f8f9fa; /* Warna background navbar/header */
        z-index: 1020; /* Lebih tinggi dari konten lain tapi di bawah modal */
        padding-top: 1rem;
        padding-bottom: 1rem;
        box-shadow: 0 2px 4px rgba(0,0,0,0.05);
    }
    .search-filter-row {
        display: flex;
        flex-wrap: wrap; /* Izinkan wrap pada layar kecil */
        gap: 0.5rem; /* Jarak antar elemen */
        align-items: center;
    }
    .search-input-group,
    .filter-select,
    .date-input {
        flex: 1 1 auto; /* Izinkan elemen tumbuh dan menyusut */
        min-width: 150px; /* Lebar minimum untuk input/select */
    }
    .filter-btn-group {
        flex-shrink: 0;
        width: 100%; /* Tombol filter ambil lebar penuh di mobile */
    }
    @media (min-width: 768px) {
        .filter-btn-group {
            width: auto; /* Kembali ke auto di desktop */
        }
    }
    /* Style untuk header tabel yang bisa di-sort */
    .sortable {
        cursor: pointer;
        user-select: none;
    }
    .sortable i {
        margin-left: 5px;
    }
</style>

<div class="d-flex" id="wrapper">
    <?php include_once '../includes/sidebar.php'; ?>

    <div id="page-content-wrapper" class="flex-grow-1">
        <div class="container-fluid px-4">
            <!-- Sticky Header and Search Bar -->
            <div class="sticky-header-container">
                <h1 class="display-5 mb-0">Laporan Pembayaran</h1>
                <p class="lead text-muted">Total: <span id="totalPaymentsDisplay"><?php echo $total_payments; ?></span> data pembayaran</p>
                <div class="alert alert-info mt-2 mb-3">
                    <strong>Total Pendapatan Filtered:</strong> <span id="totalFilteredAmount"><?php echo 'Rp ' . number_format($total_filtered_amount, 0, ',', '.'); ?></span>
                </div>

                <div class="search-filter-row mb-3">
                    <div class="input-group search-input-group">
                        <input type="text" class="form-control" placeholder="Cari nama pelanggan..." aria-label="Cari pelanggan" name="search_customer_name_input" id="search_customer_name_input" value="<?php echo htmlspecialchars($search_customer_name); ?>">
                        <button class="btn btn-outline-secondary" type="button" id="search_button">
                            <i class="fas fa-search"></i>
                        </button>
                    </div>

                    <select id="paymentMethodFilter" class="form-select filter-select">
                        <option value="">Semua Metode</option>
                        <option value="Cash" <?php echo ($payment_method_filter === 'Cash') ? 'selected' : ''; ?>>Cash</option>
                        <option value="Transfer" <?php echo ($payment_method_filter === 'Transfer') ? 'selected' : ''; ?>>Transfer</option>
                    </select>

                    <div class="date-input">
                        <label for="startDate" class="form-label mb-0">Tgl Bayar Dari:</label>
                        <input type="date" class="form-control" id="startDate" value="<?php echo htmlspecialchars($start_date); ?>">
                    </div>
                    <div class="date-input">
                        <label for="endDate" class="form-label mb-0">Tgl Bayar Sampai:</label>
                        <input type="date" class="form-control" id="endDate" value="<?php echo htmlspecialchars($end_date); ?>">
                    </div>
                    <div class="date-input">
                        <label for="startInputDate" class="form-label mb-0">Tgl Input Dari:</label>
                        <input type="date" class="form-control" id="startInputDate" value="<?php echo htmlspecialchars($start_input_date); ?>">
                    </div>
                    <div class="date-input">
                        <label for="endInputDate" class="form-label mb-0">Tgl Input Sampai:</label>
                        <input type="date" class="form-control" id="endInputDate" value="<?php echo htmlspecialchars($end_input_date); ?>">
                    </div>
                    
                    <div class="filter-btn-group">
                         <button id="applyFiltersButton" class="btn btn-primary w-100">Terapkan Filter</button>
                    </div>
                </div>
            </div>

            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success mt-3"><?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?></div>
            <?php endif; ?>
            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-danger mt-3"><?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?></div>
            <?php endif; ?>

            <div class="card mb-4">
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped table-hover">
                            <thead>
                                <tr>
                                    <th class="sortable" data-sort-by="name" data-sort-order="<?php echo ($sort_by == 'name' && $sort_order == 'ASC') ? 'DESC' : 'ASC'; ?>">
                                        Nama Pelanggan
                                        <?php if ($sort_by == 'name'): ?><i class="bi bi-arrow-<?php echo ($sort_order == 'ASC') ? 'up' : 'down'; ?>"></i><?php endif; ?>
                                    </th>
                                    <th class="sortable" data-sort-by="amount" data-sort-order="<?php echo ($sort_by == 'amount' && $sort_order == 'ASC') ? 'DESC' : 'ASC'; ?>">
                                        Jumlah
                                        <?php if ($sort_by == 'amount'): ?><i class="bi bi-arrow-<?php echo ($sort_order == 'ASC') ? 'up' : 'down'; ?>"></i><?php endif; ?>
                                    </th>
                                    <th class="sortable" data-sort-by="payment_date" data-sort-order="<?php echo ($sort_by == 'payment_date' && $sort_order == 'ASC') ? 'DESC' : 'ASC'; ?>">
                                        Tanggal Bayar
                                        <?php if ($sort_by == 'payment_date'): ?><i class="bi bi-arrow-<?php echo ($sort_order == 'ASC') ? 'up' : 'down'; ?>"></i><?php endif; ?>
                                    </th>
                                    <th class="sortable" data-sort-by="input_record_date" data-sort-order="<?php echo ($sort_by == 'input_record_date' && $sort_order == 'ASC') ? 'DESC' : 'ASC'; ?>">
                                        Tanggal Input
                                        <?php if ($sort_by == 'input_record_date'): ?><i class="bi bi-arrow-<?php echo ($sort_order == 'ASC') ? 'up' : 'down'; ?>"></i><?php endif; ?>
                                    </th>
                                    <th>Metode</th>
                                    <th>No. Ref</th>
                                    <th>Diinput Oleh</th>
                                    <th>Deskripsi</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody id="paymentsTableBody">
                                <?php echo renderPaymentTableRows($payments); ?>
                            </tbody>
                        </table>
                    </div>

                    <div id="paginationControls">
                        <?php echo renderPaginationControls($current_page, $total_pages, $total_payments, $limit_per_page, $limit_per_page_options); ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const paymentsTableBody = document.getElementById('paymentsTableBody');
    const paginationControlsDiv = document.getElementById('paginationControls');
    const searchCustomerNameInput = document.getElementById('search_customer_name_input');
    const searchButton = document.getElementById('search_button');
    const startDateInput = document.getElementById('startDate');
    const endDateInput = document.getElementById('endDate');
    const startInputDateInput = document.getElementById('startInputDate'); // Input untuk input_record_date
    const endInputDateInput = document.getElementById('endInputDate');     // Input untuk input_record_date
    const paymentMethodFilterSelect = document.getElementById('paymentMethodFilter');
    const applyFiltersButton = document.getElementById('applyFiltersButton');
    const limitPerPageSelect = document.getElementById('limitPerPage');
    const totalPaymentsDisplay = document.getElementById('totalPaymentsDisplay');
    const totalFilteredAmountDisplay = document.getElementById('totalFilteredAmount');

    let currentSortBy = '<?php echo $sort_by; ?>';
    let currentSortOrder = '<?php echo $sort_order; ?>';
    let currentPage = <?php echo $current_page; ?>;
    let currentLimit = <?php echo $limit_per_page; ?>;

    function loadPayments(page = currentPage, sortBy = currentSortBy, sortOrder = currentSortOrder, limit = currentLimit) {
        let url = `payments_report.php?page=${page}&limit=${limit}&sort_by=${sortBy}&sort_order=${sortOrder}`;
        
        // Tambahkan parameter filter ke URL
        if (searchCustomerNameInput.value) {
            url += `&search_customer_name=${encodeURIComponent(searchCustomerNameInput.value)}`;
        }
        if (startDateInput.value) {
            url += `&start_date=${encodeURIComponent(startDateInput.value)}`;
        }
        if (endDateInput.value) {
            url += `&end_date=${encodeURIComponent(endDateInput.value)}`;
        }
        if (startInputDateInput.value) {
            url += `&start_input_date=${encodeURIComponent(startInputDateInput.value)}`;
        }
        if (endInputDateInput.value) {
            url += `&end_input_date=${encodeURIComponent(endInputDateInput.value)}`;
        }
        if (paymentMethodFilterSelect.value) {
            url += `&payment_method_filter=${encodeURIComponent(paymentMethodFilterSelect.value)}`;
        }

        // Tampilkan loading spinner
        paymentsTableBody.innerHTML = `<tr><td colspan="9" class="text-center py-5">
            <div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div>
            <p class="mt-2">Memuat data pembayaran...</p>
        </td></tr>`;
        paginationControlsDiv.style.display = 'none'; // Sembunyikan paginasi saat loading

        fetch(url, {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.json();
            })
            .then(data => {
                paymentsTableBody.innerHTML = data.html;
                paginationControlsDiv.innerHTML = data.pagination_html;
                totalPaymentsDisplay.textContent = data.total_payments;
                totalFilteredAmountDisplay.textContent = data.total_filtered_amount_formatted;

                currentPage = page;
                currentLimit = limit;

                attachEventListenersToPaginationAndSorting();

                if (data.total_payments === 0) {
                    paginationControlsDiv.style.display = 'none';
                } else {
                    paginationControlsDiv.style.display = 'block';
                }
            })
            .catch(error => {
                console.error('Error fetching payments:', error);
                paymentsTableBody.innerHTML = `<tr><td colspan="9" class="text-danger text-center py-5">Terjadi kesalahan saat memuat data pembayaran: ${error.message}</td></tr>`;
                paginationControlsDiv.style.display = 'none';
            });
    }

    function attachEventListenersToPaginationAndSorting() {
        // Event listeners untuk paginasi (page links)
        document.querySelectorAll('#paginationControls .page-link').forEach(link => {
            link.removeEventListener('click', handlePageClick);
            link.addEventListener('click', handlePageClick);
        });

        // Event listener untuk limit per halaman
        const currentLimitSelectElement = document.getElementById('limitPerPage');
        if (currentLimitSelectElement) {
            currentLimitSelectElement.removeEventListener('change', handleLimitChange);
            currentLimitSelectElement.addEventListener('change', handleLimitChange);
        }

        // Event listeners untuk sorting header tabel
        document.querySelectorAll('.sortable').forEach(header => {
            header.removeEventListener('click', handleSortClick);
            header.addEventListener('click', handleSortClick);
        });

        updateSortingArrows();
    }

    function handlePageClick(e) {
        e.preventDefault();
        const newPage = parseInt(this.dataset.page);
        loadPayments(newPage, currentSortBy, currentSortOrder, currentLimit);
    }

    function handleLimitChange() {
        const newLimit = parseInt(this.value);
        loadPayments(1, currentSortBy, currentSortOrder, newLimit); // Reset ke halaman 1
    }

    function handleSortClick() {
        const newSortBy = this.dataset.sortBy;
        let newSortOrder = this.dataset.sortOrder;

        if (newSortBy === currentSortBy) {
            newSortOrder = (currentSortOrder === 'ASC') ? 'DESC' : 'ASC';
        } else {
            newSortOrder = 'ASC';
        }

        currentSortBy = newSortBy;
        currentSortOrder = newSortOrder;

        loadPayments(1, currentSortBy, currentSortOrder, currentLimit); // Reset ke halaman 1
    }

    function updateSortingArrows() {
        document.querySelectorAll('.sortable').forEach(header => {
            let icon = header.querySelector('i');
            if (icon) {
                icon.remove();
            }

            if (header.dataset.sortBy === currentSortBy) {
                let newIcon = document.createElement('i');
                newIcon.classList.add('bi');
                newIcon.classList.add(currentSortOrder === 'ASC' ? 'bi-arrow-up' : 'bi-arrow-down');
                header.appendChild(newIcon);
            }
        });
    }

    // Event listeners untuk filter dan pencarian
    applyFiltersButton.addEventListener('click', function() {
        loadPayments(1); // Muat ulang pembayaran, reset ke halaman 1
    });

    searchButton.addEventListener('click', function() {
        applyFiltersButton.click();
    });

    searchCustomerNameInput.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            applyFiltersButton.click();
        }
    });

    // Panggil attachEventListeners saat halaman pertama kali dimuat
    attachEventListenersToPaginationAndSorting();

    // Initial visibility of pagination controls based on total_payments from PHP
    if (<?php echo json_encode($total_payments); ?> === 0) {
        if (paginationControlsDiv) {
            paginationControlsDiv.style.display = 'none';
        }
    } else {
        if (paginationControlsDiv) {
            paginationControlsDiv.style.display = 'block';
        }
    }
});
</script>
