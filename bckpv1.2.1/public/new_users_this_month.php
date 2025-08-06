<?php
// public/new_users_this_month.php
require_once '../includes/db_connect.php';
require_once '../includes/session.php';
require_once '../includes/functions.php';

// Pastikan pengguna sudah login dan memiliki peran yang sesuai
check_login();
if ($_SESSION['role_name'] !== 'admin' && $_SESSION['role_name'] !== 'superadmin') {
    header("Location: index.php"); // Kembali ke halaman utama dashboard jika tidak diizinkan
    exit();
}

$title = "Detail Pengguna Baru";
$error_message = '';
$success_message = '';

// --- Parameter Filter, Pencarian, Sorting, dan Paginasi ---
$selected_month = $_GET['month'] ?? date('m');
$selected_year = $_GET['year'] ?? date('Y');
$search_query = $_GET['search'] ?? '';

// Default sorting
$sort_by = $_GET['sort_by'] ?? 'created_at'; // Default: sort by creation date
$sort_order = $_GET['sort_order'] ?? 'DESC'; // Default: descending

// Validasi sort_by untuk mencegah SQL Injection
$allowed_sort_by = ['id', 'username', 'name', 'created_at'];
if (!in_array($sort_by, $allowed_sort_by)) {
    $sort_by = 'created_at'; // Fallback to safe default
}

// Validasi sort_order
$sort_order = strtoupper($sort_order);
if (!in_array($sort_order, ['ASC', 'DESC'])) {
    $sort_order = 'DESC'; // Fallback to safe default
}

// Pengaturan paginasi
$current_page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$limit_per_page_options = [100, 250, 500, 1000]; // Options for limit per page
$limit_per_page = isset($_GET['limit']) && in_array((int)$_GET['limit'], $limit_per_page_options) ? (int)$_GET['limit'] : 100;
$offset = ($current_page - 1) * $limit_per_page;

$total_new_users = 0;
$new_users = [];

$is_ajax_request = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

// AWAL QUERY SQL UTAMA DAN COUNT
$base_sql_select = "SELECT id, username, name, created_at FROM users WHERE role_id = (SELECT id FROM roles WHERE role_name = 'pelanggan')";
$base_sql_count = "SELECT COUNT(id) AS total_rows FROM users WHERE role_id = (SELECT id FROM roles WHERE role_name = 'pelanggan')";

$where_clauses = [];
$params = [];
$param_types = "";

// Tambahkan kondisi filter bulan/tahun
if (!empty($selected_month) && !empty($selected_year)) {
    $where_clauses[] = "DATE_FORMAT(created_at, '%Y-%m') = ?";
    $params[] = $selected_year . '-' . $selected_month;
    $param_types .= "s";
}

// Tambahkan kondisi pencarian
if (!empty($search_query)) {
    $where_clauses[] = "(username LIKE ? OR name LIKE ?)";
    $search_param = '%' . $search_query . '%';
    $params[] = $search_param;
    $params[] = $search_param; // Bind parameter dua kali untuk OR
    $param_types .= "ss";
}

// Gabungkan klausa WHERE
if (!empty($where_clauses)) {
    $where_sql = " AND " . implode(" AND ", $where_clauses); // Perhatikan 'AND' karena WHERE utama sudah ada
} else {
    $where_sql = "";
}

// Eksekusi COUNT query
$count_sql = $base_sql_count . $where_sql;
$stmt_count = $conn->prepare($count_sql);

if ($stmt_count) {
    if (!empty($params)) {
        // Create an array of references for dynamic parameters
        $refs_count = [];
        foreach ($params as $key => $value) {
            $refs_count[$key] = &$params[$key];
        }
        call_user_func_array([$stmt_count, 'bind_param'], array_merge([$param_types], $refs_count));
    }
    $stmt_count->execute();
    $result_count = $stmt_count->get_result();
    $total_new_users = $result_count->fetch_assoc()['total_rows'];
    $stmt_count->close();
} else {
    $error_message .= " Gagal menghitung total baris: " . $conn->error;
}

$total_pages = ceil($total_new_users / $limit_per_page);

// Ambil data pengguna dari database dengan filter, pencarian, sorting, dan paginasi
$sql_new_users = $base_sql_select . $where_sql . " ORDER BY `" . $sort_by . "` " . $sort_order . " LIMIT ?, ?";
$stmt_new_users = $conn->prepare($sql_new_users);

if ($stmt_new_users) {
    // Siapkan parameter untuk filter, pencarian, dan limit/offset
    $all_params_for_users = array_merge($params, [$offset, $limit_per_page]);
    $all_types_for_users = $param_types . "ii"; // Tambahkan 'ii' untuk offset dan limit

    // Create an array of references for all parameters
    $refs_users = [];
    foreach ($all_params_for_users as $key => $value) {
        $refs_users[$key] = &$all_params_for_users[$key];
    }
    call_user_func_array([$stmt_new_users, 'bind_param'], array_merge([$all_types_for_users], $refs_users));
    
    $stmt_new_users->execute();
    $result_new_users = $stmt_new_users->get_result();

    if ($result_new_users) {
        if ($result_new_users->num_rows > 0) {
            while ($row = $result_new_users->fetch_assoc()) {
                $new_users[] = $row;
            }
        }
    } else {
        $error_message .= " Error mengambil data pengguna baru: " . $stmt_new_users->error;
    }
    $stmt_new_users->close();
} else {
    $error_message .= " Gagal menyiapkan query pengguna baru: " . $conn->error;
}
// AKHIR QUERY SQL UTAMA DAN COUNT

// Handle AJAX Request
if ($is_ajax_request) {
    ob_start(); // Start output buffering for tbody
    ?>
    <?php if (!empty($new_users)): ?>
        <?php foreach ($new_users as $user): ?>
            <tr>
                <td><?php echo htmlspecialchars($user['id']); ?></td>
                <td><?php echo htmlspecialchars($user['username']); ?></td>
                <td><?php echo htmlspecialchars($user['name']); ?></td>
                <td><?php echo htmlspecialchars((new DateTime($user['created_at']))->format('d M Y H:i:s')); ?></td>
                <td>
                    <a href="customer_details.php?id=<?php echo htmlspecialchars($user['id']); ?>" class="btn btn-info btn-sm">Lihat Detail</a>
                </td>
            </tr>
        <?php endforeach; ?>
    <?php else: ?>
        <tr>
            <td colspan="5">
                <div class="alert alert-info mb-0" role="alert">
                    Tidak ada pengguna baru yang ditemukan dengan kriteria tersebut.
                </div>
            </td>
        </tr>
    <?php endif; ?>
    <?php
    $tbody_content = ob_get_clean();

    ob_start(); // Start output buffering for pagination HTML
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
        <span class="ms-2 text-muted">dari <span id="totalUsersCount"><?php echo $total_new_users; ?></span> data</span>
    </div>
    <?php
    $pagination_content = ob_get_clean();

   
    echo json_encode([
        'html' => $tbody_content,
        'pagination_html' => $pagination_content,
        'total_users' => $total_new_users
    ]);
    exit();
}


require_once '../includes/header.php';
?>

<header class="mb-4">
    <h1 class="display-5">Detail Pengguna Baru</h1>
    <p class="lead">Daftar pelanggan yang mendaftar.</p>
</header>

<div class="row">
    <div class="col-12">
        <a href="index.php" class="btn btn-secondary mb-3"><i class="fas fa-arrow-left"></i> Kembali ke Dashboard</a>
        <div class="card">
            <div class="card-body">
                <div class="row mb-3 align-items-end">
                    <div class="col-md-3">
                        <label for="monthSelect" class="form-label">Bulan</label>
                        <select class="form-select" id="monthSelect">
                            <?php
                            $months = [
                                '01' => 'Januari', '02' => 'Februari', '03' => 'Maret', '04' => 'April',
                                '05' => 'Mei', '06' => 'Juni', '07' => 'Juli', '08' => 'Agustus',
                                '09' => 'September', '10' => 'Oktober', '11' => 'November', '12' => 'Desember'
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
                            $current_year_option = date('Y');
                            for ($y = $current_year_option; $y >= $current_year_option - 5; $y--) { // Menampilkan 5 tahun ke belakang
                                echo "<option value=\"$y\"" . ($selected_year == $y ? ' selected' : '') . ">$y</option>";
                            }
                            ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label for="searchInput" class="form-label">Cari Username/Nama</label>
                        <input type="text" id="searchInput" class="form-control" placeholder="Cari username atau nama..." value="<?php echo htmlspecialchars($search_query); ?>">
                    </div>
                    <div class="col-md-auto">
                        <button id="filterSearchButton" class="btn btn-primary">Filter / Cari</button>
                        <button id="resetButton" class="btn btn-secondary">Reset</button>
                    </div>
                </div>

                <?php if (empty($new_users) && $total_new_users == 0): // Initial load with no data ?>
                    <div class="alert alert-info mt-3" role="alert">
                        Tidak ada pengguna baru yang ditemukan dengan kriteria tersebut.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table id="myTable" class="table table-striped table-hover">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th class="sortable" data-sort-by="username" data-sort-order="<?php echo ($sort_by == 'username' && $sort_order == 'ASC') ? 'DESC' : 'ASC'; ?>">
                                        Username
                                        <?php if ($sort_by == 'username'): ?><i class="bi bi-arrow-<?php echo ($sort_order == 'ASC') ? 'up' : 'down'; ?>"></i><?php endif; ?>
                                    </th>
                                    <th class="sortable" data-sort-by="name" data-sort-order="<?php echo ($sort_by == 'name' && $sort_order == 'ASC') ? 'DESC' : 'ASC'; ?>">
                                        Nama
                                        <?php if ($sort_by == 'name'): ?><i class="bi bi-arrow-<?php echo ($sort_order == 'ASC') ? 'up' : 'down'; ?>"></i><?php endif; ?>
                                    </th>
                                    <th class="sortable" data-sort-by="created_at" data-sort-order="<?php echo ($sort_by == 'created_at' && $sort_order == 'ASC') ? 'DESC' : 'ASC'; ?>">
                                        Tanggal Bergabung
                                        <?php if ($sort_by == 'created_at'): ?><i class="bi bi-arrow-<?php echo ($sort_order == 'ASC') ? 'up' : 'down'; ?>"></i><?php endif; ?>
                                    </th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody id="usersTableBody">
                                <?php foreach ($new_users as $user): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($user['id']); ?></td>
                                        <td><?php echo htmlspecialchars($user['username']); ?></td>
                                        <td><?php echo htmlspecialchars($user['name']); ?></td>
                                        <td><?php echo htmlspecialchars((new DateTime($user['created_at']))->format('d M Y H:i:s')); ?></td>
                                        <td>
                                            <a href="customer_details.php?id=<?php echo htmlspecialchars($user['id']); ?>" class="btn btn-info btn-sm">Lihat Detail</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
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
                            <span class="ms-2 text-muted">dari <span id="totalUsersCount"><?php echo $total_new_users; ?></span> data</span>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const usersTableBody = document.getElementById('usersTableBody');
    const paginationControlsDiv = document.getElementById('paginationControls');
    const monthSelect = document.getElementById('monthSelect');
    const yearSelect = document.getElementById('yearSelect');
    const searchInput = document.getElementById('searchInput');
    const filterSearchButton = document.getElementById('filterSearchButton');
    const resetButton = document.getElementById('resetButton');
    const limitPerPageSelect = document.getElementById('limitPerPage');
    const totalUsersCountSpan = document.getElementById('totalUsersCount');

    // Ambil nilai awal dari PHP untuk sorting
    let currentSortBy = '<?php echo $sort_by; ?>';
    let currentSortOrder = '<?php echo $sort_order; ?>';

    // Fungsi untuk memuat data pengguna berdasarkan parameter
    function loadUsers(page = 1) {
        const month = monthSelect.value;
        const year = yearSelect.value;
        const searchQuery = searchInput.value;
        const limit = limitPerPageSelect.value;

        let url = `new_users_this_month.php?page=${page}&limit=${limit}&sort_by=${currentSortBy}&sort_order=${currentSortOrder}`;
        if (month) {
            url += `&month=${encodeURIComponent(month)}`;
        }
        if (year) {
            url += `&year=${encodeURIComponent(year)}`;
        }
        if (searchQuery) {
            url += `&search=${encodeURIComponent(searchQuery)}`;
        }

        fetch(url, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => {
            if (!response.ok) {
                return response.text().then(text => { throw new Error(`HTTP error! status: ${response.status}, response: ${text}`); });
            }
            return response.json();
        })
        .then(data => {
            usersTableBody.innerHTML = data.html;
            
            if (paginationControlsDiv) {
                paginationControlsDiv.innerHTML = data.pagination_html;
            }
            if (totalUsersCountSpan) {
                totalUsersCountSpan.textContent = data.total_users;
            }

            // Re-attach event listeners for newly loaded pagination and sort headers
            attachEventListeners();

            // Sembunyikan/tampilkan paginasi jika tidak ada data
            if (paginationControlsDiv) {
                if (data.total_users === 0) {
                    paginationControlsDiv.style.display = 'none';
                } else {
                    paginationControlsDiv.style.display = 'block';
                }
            }

            // Update sort icons on the header after successful load
            updateSortIcons();
        })
        .catch(error => {
            console.error('Error fetching new users:', error);
            usersTableBody.innerHTML = `<tr><td colspan="5" class="text-danger">Terjadi kesalahan saat memuat data pengguna. ${error.message}</td></tr>`;
            if (paginationControlsDiv) {
                paginationControlsDiv.style.display = 'none';
            }
        });
    }

    // Fungsi untuk memperbarui ikon sort di header tabel
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

    // Fungsi untuk melampirkan event listener (perlu dipanggil ulang setelah AJAX update)
    function attachEventListeners() {
        // Paginasi
        document.querySelectorAll('#paginationControls .page-link').forEach(link => {
            link.removeEventListener('click', handlePageClick); 
            link.addEventListener('click', handlePageClick); 
        });

        // Limit per page
        const currentLimitSelect = document.getElementById('limitPerPage');
        if (currentLimitSelect) {
            currentLimitSelect.removeEventListener('change', handleLimitChange); 
            currentLimitSelect.addEventListener('change', handleLimitChange); 
        }

        // Sorting
        document.querySelectorAll('.sortable').forEach(header => {
            header.removeEventListener('click', handleSortClick); 
            header.addEventListener('click', handleSortClick); 
        });
    }

    // Handler untuk klik tombol halaman paginasi
    function handlePageClick(e) {
        e.preventDefault();
        const newPage = parseInt(this.dataset.page);
        if (!isNaN(newPage) && newPage > 0) {
            loadUsers(newPage);
        }
    }

    // Handler untuk perubahan limit per halaman
    function handleLimitChange() {
        loadUsers(1); // Kembali ke halaman 1 saat limit berubah
    }

    // Handler untuk klik kolom sortir
    function handleSortClick() {
        const clickedSortBy = this.dataset.sortBy;
        
        if (clickedSortBy === currentSortBy) {
            currentSortOrder = (currentSortOrder === 'ASC') ? 'DESC' : 'ASC';
        } else {
            currentSortBy = clickedSortBy;
            currentSortOrder = 'ASC'; 
        }

        loadUsers(1); // Kembali ke halaman 1 saat sortir
    }

    // Handler untuk tombol filter/cari
    filterSearchButton.addEventListener('click', function() {
        loadUsers(1); // Kembali ke halaman 1 saat filter/cari
    });

    // Handler untuk tombol reset
    resetButton.addEventListener('click', function() {
        monthSelect.value = '<?php echo date('m'); ?>';
        yearSelect.value = '<?php echo date('Y'); ?>';
        searchInput.value = '';
        currentSortBy = 'created_at'; // Reset sort
        currentSortOrder = 'DESC';     // Reset sort
        limitPerPageSelect.value = 10; // Reset limit
        loadUsers(1); // Reset dan kembali ke halaman 1
    });

    // Event listener untuk Enter key di search input
    searchInput.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            filterSearchButton.click();
        }
    });

    // Event listeners untuk perubahan bulan dan tahun
    monthSelect.addEventListener('change', function() {
        loadUsers(1); // Kembali ke halaman 1
    });

    yearSelect.addEventListener('change', function() {
        loadUsers(1); // Kembali ke halaman 1
    });

    // Initial load and setup:
    attachEventListeners();
    updateSortIcons();

    const initialTotalUsers = <?php echo json_encode($total_new_users); ?>;
    if (paginationControlsDiv) {
        if (initialTotalUsers === 0) {
            paginationControlsDiv.style.display = 'none';
        } else {
            paginationControlsDiv.style.display = 'block';
        }
    }
});
</script>