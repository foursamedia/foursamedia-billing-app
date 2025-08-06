<?php
// public/incomes.php
// Pastikan TIDAK ADA spasi, newline, atau karakter lain sebelum tag <?php ini!
// Ini sangat penting untuk mencegah "headers already sent"

ini_set('display_errors', 1); // Aktifkan untuk debugging
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../includes/db_connect.php';
require_once '../includes/session.php';
require_once '../includes/functions.php';

// Pastikan user sudah login dan memiliki peran yang sesuai
check_login();
check_role(['superadmin', 'admin', 'finance']);

// Cek apakah ini permintaan AJAX
$is_ajax_request = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

// --- Logika Hapus Pemasukan (tetap di sini) ---
// Catatan: Untuk permintaan AJAX, setelah delete, JS akan me-reload tabel
if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id'])) {
    if (isset($_SESSION['user_id'])) {
        $income_id_to_delete = $_GET['id'];
        $stmt_delete = $conn->prepare("DELETE FROM incomes WHERE id = ?");
        if ($stmt_delete) {
            $stmt_delete->bind_param("i", $income_id_to_delete);
            if ($stmt_delete->execute()) {
                $_SESSION['success_message'] = "Pemasukan berhasil dihapus.";
            } else {
                $_SESSION['error_message'] = "Gagal menghapus pemasukan: " . $stmt_delete->error;
            }
            $stmt_delete->close();
        } else {
            $_SESSION['error_message'] = "Gagal menyiapkan statement delete: " . $conn->error;
        }
    } else {
        $_SESSION['error_message'] = "Anda harus login untuk menghapus pemasukan.";
    }

    // Redirect setelah delete untuk menghindari re-submission form
    // Buat ulang parameter query string kecuali action dan id
    $redirect_params = array_diff_key($_GET, ['action' => '', 'id' => '']);
    header("Location: incomes.php?" . http_build_query($redirect_params));
    exit();
}

// --- Parameter Filter, Pencarian, Sorting, dan Paginasi ---
$selected_month = $_GET['month'] ?? date('m');
$selected_year = $_GET['year'] ?? date('Y');
$search_query = $_GET['search'] ?? '';

$sort_by = $_GET['sort_by'] ?? 'income_date';
$sort_order = $_GET['sort_order'] ?? 'DESC';

$allowed_sort_by = ['income_date', 'description', 'amount', 'input_by_username', 'id'];
if (!in_array($sort_by, $allowed_sort_by)) {
    $sort_by = 'income_date';
}

$sort_order = strtoupper($sort_order);
if (!in_array($sort_order, ['ASC', 'DESC'])) {
    $sort_order = 'DESC';
}

$current_page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$limit_per_page_options = [10, 25, 50, 100, 250, 500, 1000];
$limit_per_page = isset($_GET['limit']) && in_array((int)$_GET['limit'], $limit_per_page_options) ? (int)$_GET['limit'] : 10;
$offset = ($current_page - 1) * $limit_per_page;

$total_incomes = 0;
$incomes = [];
$total_incomes_global = 0;

$where_clauses = [];
$params = [];
$param_types = "";

if (!empty($selected_month) && !empty($selected_year)) {
    $where_clauses[] = "DATE_FORMAT(i.income_date, '%Y-%m') = ?";
    $params[] = $selected_year . '-' . $selected_month;
    $param_types .= "s";
}

if (!empty($search_query)) {
    $where_clauses[] = "(i.description LIKE ? OR u.username LIKE ?)";
    $search_param = '%' . $search_query . '%';
    $params[] = $search_param;
    $params[] = $search_param;
    $param_types .= "ss";
}

$where_sql = count($where_clauses) > 0 ? " WHERE " . implode(" AND ", $where_clauses) : "";

// --- Query untuk Total Pemasukan Global (Tanpa Filter Bulan/Tahun/Pencarian) ---
$global_income_sql = "SELECT SUM(amount) AS total FROM incomes";
$stmt_global_income = $conn->prepare($global_income_sql);
if ($stmt_global_income) {
    $stmt_global_income->execute();
    $result_global_income = $stmt_global_income->get_result();
    $row_global_income = $result_global_income->fetch_assoc();
    $total_incomes_global = (int)($row_global_income['total'] ?? 0);
    $stmt_global_income->close();
} else {
    error_log("Error preparing global income statement: " . $conn->error);
}

// --- Query untuk Total Pemasukan yang Difilter (untuk Paginasi) ---
$count_sql = "SELECT COUNT(i.id) AS total_rows FROM incomes i JOIN users u ON i.input_by_user_id = u.id" . $where_sql;
$stmt_count = $conn->prepare($count_sql);

if ($stmt_count) {
    // --- Penanganan parameter COUNT query seperti di customers.php ---
    if (!empty($params) && !empty($param_types)) {
        // Hanya ambil parameter yang digunakan untuk klausa WHERE (tanpa LIMIT/OFFSET)
        $count_params = [];
        $count_param_types = "";

        // $params dan $param_types sudah berisi parameter WHERE, jadi tinggal gunakan itu
        $count_params = $params;
        $count_param_types = $param_types;

        // Gunakan spread operator yang didukung PHP 5.6+
        if (!empty($count_params) && !empty($count_param_types)) {
            $stmt_count->bind_param($count_param_types, ...$count_params);
        }
    }
    // --- Akhir penanganan parameter COUNT query ---

    $stmt_count->execute();
    $result_count = $stmt_count->get_result();
    $row_count = $result_count->fetch_assoc();
    $total_incomes = (int)($row_count['total_rows'] ?? 0);
    $stmt_count->close();
} else {
    error_log("Gagal menghitung total baris: " . $conn->error);
}

$total_pages = ceil($total_incomes / $limit_per_page);
// Pastikan current_page tidak lebih dari total_pages jika total_incomes > 0
if ($total_incomes > 0 && $current_page > $total_pages) {
    $current_page = $total_pages;
    $offset = ($current_page - 1) * $limit_per_page;
}
// Jika total_incomes 0, pastikan current_page juga 0 atau 1
if ($total_incomes == 0) {
    $current_page = 1;
    $offset = 0;
}


// --- Query untuk Mengambil Data Pemasukan ---
$sql_incomes = "SELECT i.*, u.username AS input_by_username FROM incomes i JOIN users u ON i.input_by_user_id = u.id" . $where_sql . " ORDER BY " . $sort_by . " " . $sort_order . " LIMIT ? OFFSET ?";
$stmt_incomes = $conn->prepare($sql_incomes);

if ($stmt_incomes) {
    // Gabungkan parameter WHERE dengan LIMIT dan OFFSET
    $all_params_for_incomes = array_merge($params, [$limit_per_page, $offset]);
    $all_types_for_incomes = $param_types . "ii";

    // Gunakan spread operator yang didukung PHP 5.6+
    if (!empty($all_params_for_incomes) && !empty($all_types_for_incomes)) {
        $stmt_incomes->bind_param($all_types_for_incomes, ...$all_params_for_incomes);
    }

    $stmt_incomes->execute();
    $result_incomes = $stmt_incomes->get_result();

    if ($result_incomes) {
        if ($result_incomes->num_rows > 0) {
            while ($row = $result_incomes->fetch_assoc()) {
                $incomes[] = $row;
            }
        }
    } else {
        error_log("Error mengambil data pemasukan: " . $stmt_incomes->error);
    }
    $stmt_incomes->close();
} else {
    error_log("Gagal menyiapkan query pemasukan: " . $conn->error);
}


// ==============================================================================
// === BAGIAN INI HANYA UNTUK PERMINTAAN AJAX ==================================
// ==============================================================================
if ($is_ajax_request) {
    // Pastikan tidak ada output lain sebelum ini
    header('Content-Type: application/json'); // Set header sebelum output
    ob_start(); // Mulai buffering output untuk konten HTML

    // HTML untuk tbody (baris tabel)
    if (!empty($incomes)): ?>
        <?php foreach ($incomes as $income): ?>
            <tr>
                <td><?php echo htmlspecialchars($income['id']); ?></td>
                <td><?php echo htmlspecialchars(date('d-m-Y', strtotime($income['income_date']))); ?></td>
                <td><?php echo htmlspecialchars($income['description']); ?></td>
                <td>Rp<?php echo number_format($income['amount'], 2, ',', '.'); ?></td>
                <td><?php echo htmlspecialchars($income['input_by_username']); ?></td>
                <td class="text-end">
                    <a href="edit_income.php?id=<?php echo htmlspecialchars($income['id']); ?>" class="btn btn-sm btn-info" title="Edit Pemasukan"><i class="bi bi-pencil-fill"></i></a>
                    <a href="incomes.php?action=delete&id=<?php echo htmlspecialchars($income['id']); ?>&month=<?php echo htmlspecialchars($selected_month); ?>&year=<?php echo htmlspecialchars($selected_year); ?>&page=<?php echo htmlspecialchars($current_page); ?>&limit=<?php echo htmlspecialchars($limit_per_page); ?>&search=<?php echo htmlspecialchars($search_query); ?>&sort_by=<?php echo htmlspecialchars($sort_by); ?>&sort_order=<?php echo htmlspecialchars($sort_order); ?>" class="btn btn-sm btn-danger" onclick="return confirm('Apakah Anda yakin ingin menghapus pemasukan ini?');" title="Hapus Pemasukan"><i class="bi bi-trash-fill"></i></a>
                </td>
            </tr>
        <?php endforeach; ?>
    <?php else: ?>
        <tr>
            <td colspan="6">
                <div class="alert alert-info mb-0" role="alert">
                    Tidak ada catatan pemasukan yang ditemukan dengan kriteria tersebut.
                </div>
            </td>
        </tr>
    <?php endif;
    $tbody_html = ob_get_clean(); // Ambil konten dan bersihkan buffer

    ob_start(); // Mulai buffering output untuk paginasi
    // HTML untuk kontrol paginasi dan limit per halaman
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
        <label for="limitPerPage" class="form-label me-2 mb-0">Tampilkan:</label>
        <select class="form-select form-select-sm w-auto" id="limitPerPage">
            <?php foreach ($limit_per_page_options as $option): ?>
                <option value="<?php echo $option; ?>" <?php echo ($option == $limit_per_page) ? 'selected' : ''; ?>>
                    <?php echo $option; ?>
                </option>
            <?php endforeach; ?>
        </select>
        <span class="ms-2 text-muted">dari <span id="totalIncomesCount"><?php echo $total_incomes; ?></span> data</span>
    </div>
<?php
    $pagination_html = ob_get_clean(); // Ambil konten dan bersihkan buffer

    // Encode semua data ke JSON dan kirim
    echo json_encode([
        'html' => $tbody_html,
        'pagination_html' => $pagination_html,
        'total_incomes' => $total_incomes,
        'total_incomes_global' => $total_incomes_global
    ]);
    exit(); // PENTING: Hentikan eksekusi setelah mengirim respons JSON
}

// ==============================================================================
// === BAGIAN INI HANYA UNTUK PERMINTAAN NON-AJAX (HALAMAN LENGKAP) =============
// ==============================================================================

// Set judul halaman untuk header.php
$title = "Daftar Pemasukan";

// Ambil pesan dari session jika ada (untuk halaman penuh)
$error_message = $_SESSION['error_message'] ?? '';
$success_message = $_SESSION['success_message'] ?? '';
unset($_SESSION['error_message']);
unset($_SESSION['success_message']);

// Include header (yang juga akan meng-include sidebar)
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
            <h1 class="display-5 mb-0">Daftar Pemasukan</h1>
            <a href="add_income.php" class="btn btn-success">
                <i class="bi bi-plus-lg me-2"></i>Tambah Pemasukan Baru
            </a>
        </div>

        <?php if ($success_message): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success_message); ?></div>
        <?php endif; ?>
        <?php if ($error_message): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div>
        <?php endif; ?>

        <div class="card mb-4">
            <div class="card-body">
                <div class="row mb-3 align-items-end">
                    <div class="d-flex flex-column flex-lg-row align-items-center align-items-md-end justify-content-between gap-2">
                        <div class="d-flex col-12 col-md-10 gap-lg-4 flex-column flex-lg-row">
                            <div class="col-md-3">
                                <label for="monthSelect" class="form-label">Bulan</label>
                                <select class="form-select" id="monthSelect">
                                    <?php
                                    $months = [
                                        '01' => 'Januari',
                                        '02' => 'Februari',
                                        '03' => 'Maret',
                                        '04' => 'April',
                                        '05' => 'Mei',
                                        '06' => 'Juni',
                                        '07' => 'Juli',
                                        '08' => 'Agustus',
                                        '09' => 'September',
                                        '10' => 'Oktober',
                                        '11' => 'November',
                                        '12' => 'Desember'
                                    ];
                                    foreach ($months as $num => $name) {
                                        echo "<option value=\"$num\"" . ($selected_month == $num ? ' selected' : '') . ">$name</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label for="yearSelect" class="form-label">Tahun</label>
                                <select class="form-select" id="yearSelect">
                                    <?php
                                    $current_year_option = (int)date('Y');
                                    for ($y = $current_year_option; $y >= $current_year_option - 5; $y--) {
                                        echo "<option value=\"$y\"" . ($selected_year == $y ? ' selected' : '') . ">$y</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label for="searchInput" class="form-label">Cari Deskripsi / User</label>
                                <input type="text" id="searchInput" class="form-control" placeholder="Cari deskripsi atau user..." value="<?php echo htmlspecialchars($search_query); ?>">
                            </div>
                        </div>
                        <div class="d-flex flex-1">
                            <div class="col-md-auto">
                                <button id="filterSearchButton" class="btn btn-primary">Filter / Cari</button>
                                <button id="resetButton" class="btn btn-secondary">Reset</button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="row mt-3">
                    <div class="col-12 text-center text-lg-end">
                        <p class="h4 text-primary mb-0">Total Pemasukan Global: Rp<span id="totalGlobalIncome"><?php echo number_format($total_incomes_global, 0, ',', '.'); ?></span></p>
                    </div>
                </div>
            </div>
        </div>

        <div class="table-responsive manage-user">
            <table id="myTable" class="table table-striped table-hover">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th class="sortable" data-sort-by="income_date" data-sort-order="<?php echo ($sort_by == 'income_date' && $sort_order == 'ASC') ? 'DESC' : 'ASC'; ?>">
                            Tanggal
                            <?php if ($sort_by == 'income_date'): ?><i class="bi bi-arrow-<?php echo ($sort_order == 'ASC') ? 'up' : 'down'; ?>"></i><?php endif; ?>
                        </th>
                        <th class="sortable" data-sort-by="description" data-sort-order="<?php echo ($sort_by == 'description' && $sort_order == 'ASC') ? 'DESC' : 'ASC'; ?>">
                            Deskripsi
                            <?php if ($sort_by == 'description'): ?><i class="bi bi-arrow-<?php echo ($sort_order == 'ASC') ? 'up' : 'down'; ?>"></i><?php endif; ?>
                        </th>
                        <th class="sortable" data-sort-by="amount" data-sort-order="<?php echo ($sort_by == 'amount' && $sort_order == 'ASC') ? 'DESC' : 'ASC'; ?>">
                            Jumlah
                            <?php if ($sort_by == 'amount'): ?><i class="bi bi-arrow-<?php echo ($sort_order == 'ASC') ? 'up' : 'down'; ?>"></i><?php endif; ?>
                        </th>
                        <th class="sortable" data-sort-by="input_by_username" data-sort-order="<?php echo ($sort_by == 'input_by_username' && $sort_order == 'ASC') ? 'DESC' : 'ASC'; ?>">
                            Dicatat Oleh
                            <?php if ($sort_by == 'input_by_username'): ?><i class="bi bi-arrow-<?php echo ($sort_order == 'ASC') ? 'up' : 'down'; ?>"></i><?php endif; ?>
                        </th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody id="incomesTableBody">
                    <?php if (!empty($incomes)): // Populate initially for non-AJAX request 
                    ?>
                        <?php foreach ($incomes as $income): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($income['id']); ?></td>
                                <td><?php echo htmlspecialchars(date('d-m-Y', strtotime($income['income_date']))); ?></td>
                                <td><?php echo htmlspecialchars($income['description']); ?></td>
                                <td>Rp<?php echo number_format($income['amount'], 2, ',', '.'); ?></td>
                                <td><?php echo htmlspecialchars($income['input_by_username']); ?></td>
                                <td class="text-end">
                                    <a href="edit_income.php?id=<?php echo htmlspecialchars($income['id']); ?>" class="btn btn-sm btn-info" title="Edit Pemasukan"><i class="bi bi-pencil-fill"></i></a>
                                    <a href="incomes.php?action=delete&id=<?php echo htmlspecialchars($income['id']); ?>&month=<?php echo htmlspecialchars($selected_month); ?>&year=<?php echo htmlspecialchars($selected_year); ?>&page=<?php echo htmlspecialchars($current_page); ?>&limit=<?php echo htmlspecialchars($limit_per_page); ?>&search=<?php echo htmlspecialchars($search_query); ?>&sort_by=<?php echo htmlspecialchars($sort_by); ?>&sort_order=<?php echo htmlspecialchars($sort_order); ?>" class="btn btn-sm btn-danger" onclick="return confirm('Apakah Anda yakin ingin menghapus pemasukan ini?');" title="Hapus Pemasukan"><i class="bi bi-trash-fill"></i></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td class="not-found" colspan="6">
                                <div class="alert alert-info text-center mb-0" role="alert">
                                    Tidak ada catatan pemasukan yang ditemukan dengan kriteria tersebut.
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div id="paginationControls">
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
                <label for="limitPerPage" class="form-label me-2 mb-0">Tampilkan:</label>
                <select class="form-select form-select-sm w-auto" id="limitPerPage">
                    <?php foreach ($limit_per_page_options as $option): ?>
                        <option value="<?php echo $option; ?>" <?php echo ($option == $limit_per_page) ? 'selected' : ''; ?>>
                            <?php echo $option; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <span class="ms-2 text-muted">dari <span id="totalIncomesCount"><?php echo $total_incomes; ?></span> data</span>
            </div>
        </div>
    </div>
</div>

<?php
require_once '../includes/footer.php';
?>



<script>
    document.addEventListener('DOMContentLoaded', function() {
        const incomesTableBody = document.getElementById('incomesTableBody');
        const paginationControlsDiv = document.getElementById('paginationControls');
        const monthSelect = document.getElementById('monthSelect');
        const yearSelect = document.getElementById('yearSelect');
        const searchInput = document.getElementById('searchInput');
        const filterSearchButton = document.getElementById('filterSearchButton');
        const resetButton = document.getElementById('resetButton');
        const limitPerPageSelect = document.getElementById('limitPerPage');
        const totalIncomesCountSpan = document.getElementById('totalIncomesCount');
        const totalGlobalIncomeSpan = document.getElementById('totalGlobalIncome');

        // Ambil nilai awal dari URL atau set default
        let currentSortBy = '<?php echo $sort_by; ?>';
        let currentSortOrder = '<?php echo $sort_order; ?>';
        let currentPage = <?php echo $current_page; ?>;
        let currentLimit = <?php echo $limit_per_page; ?>; // Tambahkan ini

        function loadIncomes(page = currentPage, sortBy = currentSortBy, sortOrder = currentSortOrder) { // Sesuaikan parameter default
            currentPage = page; // Update current page
            const month = monthSelect.value;
            const year = yearSelect.value;
            const searchQuery = searchInput.value;
            currentLimit = limitPerPageSelect ? parseInt(limitPerPageSelect.value) : 10; // Pastikan ambil nilai terbaru

            let url = `incomes.php?page=${currentPage}&limit=${currentLimit}&sort_by=${sortBy}&sort_order=${sortOrder}`;
            if (month) {
                url += `&month=${encodeURIComponent(month)}`;
            }
            if (year) {
                url += `&year=${encodeURIComponent(year)}`;
            }
            if (searchQuery) {
                url += `&search=${encodeURIComponent(searchQuery)}`;
            }

            // Tampilkan loading state
            incomesTableBody.innerHTML = `<tr><td colspan="6" class="text-center"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div> Memuat data...</td></tr>`;
            if (paginationControlsDiv) {
                paginationControlsDiv.innerHTML = `<div class="text-center text-muted">Memuat paginasi...</div>`;
            }

            fetch(url, {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(response => {
                    if (!response.ok) {
                        return response.text().then(text => {
                            console.error('HTTP Error Response (Full Text):', text);
                            throw new Error(`HTTP error! status: ${response.status}. Full response logged to console.`);
                        });
                    }
                    return response.json();
                })
                .then(data => {
                    incomesTableBody.innerHTML = data.html;
                    if (paginationControlsDiv) {
                        paginationControlsDiv.innerHTML = data.pagination_html;
                    }
                    if (totalIncomesCountSpan) {
                        totalIncomesCountSpan.textContent = data.total_incomes;
                    }
                    if (totalGlobalIncomeSpan) {
                        totalGlobalIncomeSpan.textContent = formatRupiah(data.total_incomes_global);
                    }

                    attachEventListeners();
                    updateSortIcons();

                    if (paginationControlsDiv) {
                        if (data.total_incomes === 0) {
                            paginationControlsDiv.style.display = 'none';
                        } else {
                            paginationControlsDiv.style.display = 'block';
                        }
                    }
                })
                .catch(error => {
                    console.error('Error fetching incomes:', error);
                    incomesTableBody.innerHTML = `<tr><td colspan="6" class="text-danger">Terjadi kesalahan saat memuat data pemasukan: ${error.message}. Silakan coba lagi.</td></tr>`;
                    if (paginationControlsDiv) {
                        paginationControlsDiv.innerHTML = `<div class="alert alert-danger text-center">Gagal memuat paginasi.</div>`;
                        paginationControlsDiv.style.display = 'block';
                    }
                });
        }

        function updateSortIcons() {
            document.querySelectorAll('.sortable').forEach(header => {
                let existingIcon = header.querySelector('i.bi');
                if (existingIcon) {
                    existingIcon.remove();
                }

                if (header.dataset.sortBy === currentSortBy) {
                    const iconClass = (currentSortOrder === 'ASC') ? 'bi-arrow-up' : 'bi-arrow-down';
                    header.insertAdjacentHTML('beforeend', `<i class="bi ${iconClass}"></i>`);
                }
            });
        }

        function attachEventListeners() {
            document.querySelectorAll('#paginationControls .page-link').forEach(link => {
                link.removeEventListener('click', handlePageClick);
                link.addEventListener('click', handlePageClick);
            });

            const currentLimitSelect = document.getElementById('limitPerPage');
            if (currentLimitSelect) {
                currentLimitSelect.removeEventListener('change', handleLimitChange);
                currentLimitSelect.addEventListener('change', handleLimitChange);
            }

            document.querySelectorAll('.sortable').forEach(header => {
                header.removeEventListener('click', handleSortClick);
                header.addEventListener('click', handleSortClick);
            });

            // Event listener untuk tombol delete (didelegasikan)
            incomesTableBody.removeEventListener('click', handleDeleteClick);
            incomesTableBody.addEventListener('click', handleDeleteClick);
        }

        function handlePageClick(e) {
            e.preventDefault();
            const newPage = parseInt(this.dataset.page);
            if (!isNaN(newPage) && newPage > 0) {
                loadIncomes(newPage, currentSortBy, currentSortOrder);
            }
        }

        function handleLimitChange() {
            // currentLimit akan diperbarui di loadIncomes
            loadIncomes(1, currentSortBy, currentSortOrder); // Kembali ke halaman 1 saat limit berubah
        }

        function handleSortClick() {
            const clickedSortBy = this.dataset.sortBy;

            if (clickedSortBy === currentSortBy) {
                currentSortOrder = (currentSortOrder === 'ASC') ? 'DESC' : 'ASC';
            } else {
                currentSortBy = clickedSortBy;
                currentSortOrder = 'DESC'; // Default sort order when changing column
            }

            loadIncomes(1, currentSortBy, currentSortOrder); // Kembali ke halaman 1 saat sorting berubah
        }

        // Fungsi untuk menangani klik tombol delete (didelegasikan)
        function handleDeleteClick(e) {
            if (e.target.closest('.btn-danger')) {
                const deleteLink = e.target.closest('.btn-danger');
                if (confirm('Apakah Anda yakin ingin menghapus pemasukan ini?')) {
                    // Biarkan browser mengikuti href link, yang akan memicu redirect PHP
                    // Atau, jika ingin AJAX:
                    // e.preventDefault();
                    // fetch(deleteLink.href).then(response => {
                    //     if (!response.ok) throw new Error('Delete failed');
                    //     return response.json(); // Jika PHP mengembalikan JSON konfirmasi
                    // }).then(data => {
                    //     loadIncomes(currentPage); // Reload data setelah berhasil delete
                    // }).catch(error => console.error('Error deleting income:', error));
                } else {
                    e.preventDefault();
                }
            }
        }


        // --- Pasang Event Listener Awal ---
        if (filterSearchButton) {
            filterSearchButton.addEventListener('click', function() {
                loadIncomes(1, currentSortBy, currentSortOrder); // Selalu mulai dari halaman 1 saat filter/cari
            });
        }

        if (resetButton) {
            resetButton.addEventListener('click', function() {
                monthSelect.value = '<?php echo date('m'); ?>';
                yearSelect.value = '<?php echo date('Y'); ?>';
                searchInput.value = '';
                currentSortBy = 'income_date';
                currentSortOrder = 'DESC';
                if (limitPerPageSelect) limitPerPageSelect.value = 10;
                loadIncomes(1, currentSortBy, currentSortOrder);
            });
        }

        if (searchInput) {
            searchInput.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    filterSearchButton.click();
                }
            });
        }

        if (monthSelect) {
            monthSelect.addEventListener('change', function() {
                loadIncomes(1, currentSortBy, currentSortOrder);
            });
        }

        if (yearSelect) {
            yearSelect.addEventListener('change', function() {
                loadIncomes(1, currentSortBy, currentSortOrder);
            });
        }

        function formatRupiah(amount) {
            const numericAmount = parseFloat(amount);
            if (isNaN(numericAmount)) {
                return 'Rp0';
            }
            return new Intl.NumberFormat('id-ID', {
                style: 'currency',
                currency: 'IDR',
                minimumFractionDigits: 0,
                maximumFractionDigits: 0
            }).format(numericAmount);
        }

        // Panggil loadIncomes saat halaman pertama kali dimuat
        // Tapi karena PHP sudah mengisi awal, kita hanya perlu attach event listeners
        // Pastikan icon sorting diperbarui saat DOMContentLoaded
        updateSortIcons();
        attachEventListeners();
    });
</script>