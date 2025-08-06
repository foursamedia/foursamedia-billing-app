<?php
// public/customers.php
require_once '../includes/db_connect.php';
require_once '../includes/session.php';
require_once '../includes/functions.php';

check_role(['superadmin', 'admin', 'teknisi']);
check_login();

$title = "Daftar Pelanggan";

$sort_by = $_GET['sort_by'] ?? 'name';
$sort_order = $_GET['sort_order'] ?? 'ASC';
$search_query = $_GET['search'] ?? '';
$status_filter = $_GET['status_filter'] ?? '';
$package_filter = $_GET['package_filter'] ?? '';

// Tambahkan 'phone' ke dalam daftar kolom yang bisa disortir
$allowed_sort_by = ['name', 'email', 'address', 'phone', 'last_payment_date', 'paket'];
if (!in_array($sort_by, $allowed_sort_by)) $sort_by = 'name';
$sort_order = strtoupper($sort_order);
if (!in_array($sort_order, ['ASC', 'DESC'])) $sort_order = 'ASC';

$current_page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$limit_per_page_options = [50, 100, 250, 500, 1000];
$limit_per_page = isset($_GET['limit']) && in_array((int)$_GET['limit'], $limit_per_page_options) ? (int)$_GET['limit'] : 50;
$offset = ($current_page - 1) * $limit_per_page;

$total_customers = 0;
$customers = [];
$threshold_days = 90;

// Tambahkan u.phone ke SELECT statement
$base_sql = "
    SELECT
        u.id,
        u.name,
        u.email,
        u.username,
        u.address,
        u.phone, 
        u.paket,
        r.role_name,
        (SELECT MAX(py.payment_date) FROM payments py WHERE py.user_id = u.id) AS last_payment_date
    FROM users u
    JOIN roles r ON u.role_id = r.id
    WHERE r.role_name = 'pelanggan'";

$count_sql = "
    SELECT COUNT(u.id)
    FROM users u
    JOIN roles r ON u.role_id = r.id
    WHERE r.role_name = 'pelanggan'";

$params = [];
$param_types = "";

if (!empty($search_query)) {
    // Tambahkan u.phone ke kondisi pencarian
    $base_sql .= " AND (u.name LIKE ? OR u.email LIKE ? OR u.username LIKE ? OR u.address LIKE ? OR u.phone LIKE ?)";
    $count_sql .= " AND (u.name LIKE ? OR u.email LIKE ? OR u.username LIKE ? OR u.address LIKE ? OR u.phone LIKE ?)";
    $search_param = '%' . $search_query . '%';
    $params = array_merge($params, array_fill(0, 5, $search_param)); // 5 placeholders now
    $param_types .= "sssss"; // 5 's' for string
}

if (!empty($status_filter)) {
    if ($status_filter === 'not_active') {
        $base_sql .= " AND DATEDIFF(CURDATE(), (SELECT MAX(py.payment_date) FROM payments py WHERE py.user_id = u.id)) >= ?";
        $count_sql .= " AND DATEDIFF(CURDATE(), (SELECT MAX(py.payment_date) FROM payments py WHERE py.user_id = u.id)) >= ?";
        $params[] = $threshold_days;
        $param_types .= "i";
    } elseif ($status_filter === 'no_payment') {
        $base_sql .= " AND (SELECT MAX(py.payment_date) FROM payments py WHERE py.user_id = u.id) IS NULL";
        $count_sql .= " AND (SELECT MAX(py.payment_date) FROM payments py WHERE py.user_id = u.id) IS NULL";
    } elseif ($status_filter === 'active') {
        $base_sql .= " AND DATEDIFF(CURDATE(), (SELECT MAX(py.payment_date) FROM payments py WHERE py.user_id = u.id)) < ?";
        $count_sql .= " AND DATEDIFF(CURDATE(), (SELECT MAX(py.payment_date) FROM payments py WHERE py.user_id = u.id)) < ?";
        $params[] = $threshold_days;
        $param_types .= "i";
    }
}

if (!empty($package_filter)) {
    $base_sql .= " AND u.paket = ?";
    $count_sql .= " AND u.paket = ?";
    $params[] = $package_filter;
    $param_types .= "s";
}

$stmt_count = $conn->prepare($count_sql);
if (!empty($params) && !empty($param_types)) {
    $stmt_count->bind_param($param_types, ...$params);
}
$stmt_count->execute();
$stmt_count->bind_result($total_customers);
$stmt_count->fetch();
$stmt_count->close();

$total_pages = ceil($total_customers / $limit_per_page);

$base_sql .= " ORDER BY $sort_by $sort_order LIMIT ? OFFSET ?";
$params[] = $limit_per_page;
$params[] = $offset;
$param_types .= "ii";

$stmt = $conn->prepare($base_sql);
$stmt->bind_param($param_types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $customers[] = $row;
}
$stmt->close();

$packages = [];
$stmt_packages = $conn->prepare("SELECT DISTINCT paket FROM users WHERE role_id = (SELECT id FROM roles WHERE role_name = 'pelanggan') AND paket IS NOT NULL AND paket != '' ORDER BY paket ASC");
$stmt_packages->execute();
$result_packages = $stmt_packages->get_result();
while ($row_package = $result_packages->fetch_assoc()) {
    $packages[] = $row_package['paket'];
}
$stmt_packages->close();

// Handle AJAX response
$is_ajax_request = isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

ob_start();
echo "<div class='d-flex justify-content-between align-items-center flex-column-reverse flex-lg-row gap-4 flex-wrap mt-4'>";
echo "<div class='d-flex align-items-center mb-2 mb-md-0'>";
echo "<label for='limitPerPage' class='form-label me-2 mb-0'>Tampilkan:</label>";
echo "<select class='form-select form-select-sm w-auto' id='limitPerPage'>";
foreach ($limit_per_page_options as $option) {
    $selected = ($option == $limit_per_page) ? 'selected' : '';
    echo "<option value='$option' $selected>$option</option>";
}
echo "</select><span class='ms-2 text-muted'>dari <span id='totalCustomersCount'>$total_customers</span> data</span></div>";

if ($total_pages > 1) {
    echo "<ul class='pagination mb-0'>";
    echo "<li class='page-item " . ($current_page <= 1 ? 'disabled' : '') . "'><a class='page-link' href='#' data-page='" . max(1, $current_page - 1) . "'>&laquo;</a></li>";

    $max_display = 5;
    $start_page = max(1, $current_page - floor($max_display / 2));
    $end_page = min($total_pages, $start_page + $max_display - 1);
    if ($end_page - $start_page < $max_display - 1) {
        $start_page = max(1, $end_page - $max_display + 1);
    }
    for ($i = $start_page; $i <= $end_page; $i++) {
        echo "<li class='page-item " . ($i == $current_page ? 'active' : '') . "'><a class='page-link' href='#' data-page='$i'>$i</a></li>";
    }

    echo "<li class='page-item " . ($current_page >= $total_pages ? 'disabled' : '') . "'><a class='page-link' href='#' data-page='" . min($total_pages, $current_page + 1) . "'>&raquo;</a></li>";
    echo "</ul>";
}
echo "</div>";
$pagination_content = ob_get_clean();

if ($is_ajax_request) {
    header('Content-Type: application/json');
    ini_set('display_errors', 1);
    error_reporting(E_ALL);

    ob_start();
    if (!empty($customers)) {
        foreach ($customers as $customer) {
            $customer_id = htmlspecialchars($customer['id']);
            $customer_name = htmlspecialchars($customer['name']);
            $customer_email = htmlspecialchars($customer['email']);
            $customer_phone = htmlspecialchars($customer['phone'] ?? '-'); // Ambil nomor telepon
            $customer_address = htmlspecialchars($customer['address'] ?? '-');
            $customer_paket = htmlspecialchars($customer['paket'] ?? '-');
            $last_payment_date_str = $customer['last_payment_date'];
            $notification_icon = '';
            $notification_title = '';

            if (!empty($last_payment_date_str)) {
                $last_payment_timestamp = strtotime($last_payment_date_str);
                $days_since = floor((time() - $last_payment_timestamp) / (60 * 60 * 24));
                if ($days_since >= $threshold_days) {
                    $notification_icon = '<i class="bi bi-question-circle-fill text-warning fs-5"></i>';
                    $notification_title = "Pembayaran terakhir $days_since hari yang lalu (Tidak Aktif)";
                } else {
                    $notification_icon = '<i class="bi bi-check-circle-fill text-success fs-5"></i>';
                    $notification_title = "Aktif (Pembayaran terakhir: $last_payment_date_str)";
                }
            } else {
                $notification_icon = '<i class="bi bi-exclamation-circle-fill text-danger fs-5 icon-red"></i>';
                $notification_title = 'Belum ada pembayaran';
            }
            $action_buttons = "<a href='customer_details.php?id=$customer_id' class='btn btn-sm btn-info me-1'><i class='bi bi-info-circle-fill'></i></a>";
            if ($_SESSION['role_name'] === 'superadmin' || $_SESSION['role_name'] === 'admin') {
                $action_buttons .= "<a href='delete_user.php?id=$customer_id' class='btn btn-sm btn-danger' onclick='return confirm(\"Apakah Anda yakin?\")'><i class='bi bi-trash-fill'></i></a>";
            }
            echo "<tr id='customer'>
                    <td data-label='Nama Pelanggan'>$customer_name</td>
                    <td data-label='Email'>$customer_email</td>
                    <td data-label='Nomor Telepon'>$customer_phone</td> <td data-label='Alamat'>$customer_address</td>
                    <td data-label='Paket'>$customer_paket</td>
                    <td data-label='Status Pembayaran'><span title='$notification_title'>$notification_icon</span></td>
                    <td data-label='Aksi'>$action_buttons</td>
                </tr>";
        }
    } else {
        echo "<tr><td class='not-found' colspan='7'><div class='alert alert-info mb-0'>Tidak ada data pelanggan yang ditemukan.</div></td></tr>"; // colspan diubah menjadi 7
    }
    $tbody_content = ob_get_clean();

    echo json_encode([
        'html' => $tbody_content,
        'pagination_html' => $pagination_content,
        'total_customers' => $total_customers
    ]);
    exit();
}

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

    <div id="page-content-wrapper" class="flex-grow-1 mx-2 mx-lg-4">
        <div class="d-flex justify-content-between align-items-center flex-column flex-lg-row gap-3 m-4 mx-lg-0 mb-4">
            <h1 class="display-5 mb-0">Daftar Pelanggan</h1>
        </div>

        <div class="card">
            <div class="card-body">
                <div class="row mb-3 align-items-center">
                    <div class="col-md-3 mb-2 mb-md-0">
                        <input type="text" id="searchInput" class="form-control" placeholder="Cari nama, email, username, alamat, atau telepon..." value="<?php echo htmlspecialchars($search_query); ?>">
                    </div>
                    <div class="col-md-3 mb-2 mb-md-0">
                        <select id="statusFilter" class="form-select">
                            <option value="">Semua Status</option>
                            <option value="active" <?php echo ($status_filter === 'active') ? 'selected' : ''; ?>>Aktif</option>
                            <option value="not_active" <?php echo ($status_filter === 'not_active') ? 'selected' : ''; ?>>Tidak Aktif</option>
                            <option value="no_payment" <?php echo ($status_filter === 'no_payment') ? 'selected' : ''; ?>>Belum Ada Pembayaran</option>
                        </select>
                    </div>
                    <div class="col-md-3 mb-2 mb-md-0"> <select id="packageFilter" class="form-select">
                            <option value="">Semua Paket</option>
                            <?php foreach ($packages as $package_name_option): ?>
                                <option value="<?php echo htmlspecialchars($package_name_option); ?>" <?php echo ($package_filter === $package_name_option) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($package_name_option); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <button id="searchButton" class="btn btn-primary w-100">Cari / Filter</button>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th data-label="Nama Pelanggan" class="sortable" data-sort-by="name" data-sort-order="<?php echo ($sort_by == 'name' && $sort_order == 'ASC') ? 'DESC' : 'ASC'; ?>">
                                    Nama Pelanggan
                                    <?php if ($sort_by == 'name'): ?><i class="bi bi-arrow-<?php echo ($sort_order == 'ASC') ? 'up' : 'down'; ?>"></i><?php endif; ?>
                                </th>
                                <th class="sortable" data-sort-by="email" data-sort-order="<?php echo ($sort_by == 'email' && $sort_order == 'ASC') ? 'DESC' : 'ASC'; ?>">
                                    Email
                                    <?php if ($sort_by == 'email'): ?><i class="bi bi-arrow-<?php echo ($sort_order == 'ASC') ? 'up' : 'down'; ?>"></i><?php endif; ?>
                                </th>
                                <th class="sortable" data-sort-by="phone" data-sort-order="<?php echo ($sort_by == 'phone' && $sort_order == 'ASC') ? 'DESC' : 'ASC'; ?>">
                                    Nomor Telepon
                                    <?php if ($sort_by == 'phone'): ?><i class="bi bi-arrow-<?php echo ($sort_order == 'ASC') ? 'up' : 'down'; ?>"></i><?php endif; ?>
                                </th>
                                <th class="sortable" data-sort-by="address" data-sort-order="<?php echo ($sort_by == 'address' && $sort_order == 'ASC') ? 'DESC' : 'ASC'; ?>">
                                    Alamat
                                    <?php if ($sort_by == 'address'): ?><i class="bi bi-arrow-<?php echo ($sort_order == 'ASC') ? 'up' : 'down'; ?>"></i><?php endif; ?>
                                </th>
                                <th class="sortable" data-sort-by="paket" data-sort-order="<?php echo ($sort_by == 'paket' && $sort_order == 'ASC') ? 'DESC' : 'ASC'; ?>">
                                    Paket
                                    <?php if ($sort_by == 'paket'): ?><i class="bi bi-arrow-<?php echo ($sort_order == 'ASC') ? 'up' : 'down'; ?>"></i><?php endif; ?>
                                </th>
                                <th class="text-center">Status Pembayaran</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody id="customersTableBody">
                            <?php if (!empty($customers) || $total_customers > 0): ?>
                                <?php foreach ($customers as $customer):
                                    $customer_id = htmlspecialchars($customer['id']);
                                    $customer_name = htmlspecialchars($customer['name']);
                                    $customer_email = htmlspecialchars($customer['email']);
                                    $customer_phone = htmlspecialchars($customer['phone'] ?? '-'); // Ambil nomor telepon
                                    $customer_address = htmlspecialchars($customer['address'] ?? '-');
                                    $customer_paket = htmlspecialchars($customer['paket'] ?? '-'); // Get 'paket' value
                                    $last_payment_date_str = $customer['last_payment_date'];

                                    $notification_icon = '';
                                    $notification_title = '';

                                    if (!empty($last_payment_date_str)) {
                                        $last_payment_timestamp = strtotime($last_payment_date_str);
                                        $current_timestamp = time();
                                        $days_since_last_payment = floor(($current_timestamp - $last_payment_timestamp) / (60 * 60 * 24));

                                        if ($days_since_last_payment >= $threshold_days) {
                                            $notification_icon = '<i class="bi bi-question-circle-fill text-warning fs-5"></i>';
                                            $notification_title = 'Pembayaran terakhir ' . $days_since_last_payment . ' hari yang lalu (Tidak Aktif)';
                                        } else {
                                            $notification_icon = '<i class="bi bi-check-circle-fill text-success fs-5"></i>';
                                            $notification_title = 'Aktif (Pembayaran terakhir: ' . htmlspecialchars($last_payment_date_str) . ')';
                                        }
                                    } else {
                                        $notification_icon = '<i class="bi bi-exclamation-circle-fill text-danger fs-5 icon-red"></i>';
                                        $notification_title = 'Belum ada pembayaran';
                                    }
                                ?>
                                    <tr id="customer">
                                        <td data-label="Nama Pelanggan"><?php echo $customer_name; ?></td>
                                        <td data-label="Email"><?php echo $customer_email; ?></td>
                                        <td data-label="Nomor Telepon"><?php echo $customer_phone; ?></td> <td data-label="Alamat"><?php echo $customer_address; ?></td>
                                        <td data-label="Paket"><?php echo $customer_paket; ?></td>
                                        <td data-label="Status Pembayaran"><span title="<?php echo $notification_title; ?>"><?php echo $notification_icon; ?></span></td>
                                        <td data-label="Aksi">
                                            <a href='customer_details.php?id=<?php echo $customer_id; ?>' class='btn btn-sm btn-info me-1' title="Detail Pelanggan"><i class="bi bi-info-circle-fill"></i></a>
                                            <?php if ($_SESSION['role_name'] === 'superadmin' || $_SESSION['role_name'] === 'admin'): ?>
                                                <a href='delete_user.php?id=<?php echo $customer_id; ?>' class='btn btn-sm btn-danger' onclick="return confirm('Apakah Anda yakin ingin menghapus pengguna ini?')" title="Hapus Pelanggan"><i class="bi bi-trash-fill"></i></a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7"> <div class="alert alert-info mb-0" role="alert">
                                            Tidak ada data pelanggan yang ditemukan dengan kriteria tersebut.
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div id="paginationControls">
                    <?php echo "<div class='pagination-custom'>" . $pagination_content . "</div>"; ?>
                </div>

            </div>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const customersTableBody = document.getElementById('customersTableBody');
        const paginationControlsDiv = document.getElementById('paginationControls');
        const searchInput = document.getElementById('searchInput');
        const searchButton = document.getElementById('searchButton');
        const statusFilterSelect = document.getElementById('statusFilter');
        const packageFilterSelect = document.getElementById('packageFilter');
        const limitPerPageSelect = document.getElementById('limitPerPage');
        const totalCustomersCountSpan = document.getElementById('totalCustomersCount');

        const defaultDesktopLimit = 50;
        const defaultMobileLimit = 10;
        const mobileBreakpoint = 768;

        let currentSortBy = '<?php echo $sort_by; ?>';
        let currentSortOrder = '<?php echo $sort_order; ?>';

        function getLimitByScreenWidth() {
            return window.innerWidth <= mobileBreakpoint ? defaultMobileLimit : defaultDesktopLimit;
        }

        let activeLimit = <?php echo $limit_per_page; ?>;

        function updateLimitSelect() {
            let optionExists = Array.from(limitPerPageSelect.options).some(option => parseInt(option.value) === activeLimit);
            if (!optionExists) {
                let newOption = document.createElement('option');
                newOption.value = activeLimit;
                newOption.textContent = activeLimit;
                limitPerPageSelect.appendChild(newOption);
            }
            limitPerPageSelect.value = activeLimit;
        }

        function updateTableAndPagination(data) {
            customersTableBody.style.display = 'none';
            customersTableBody.innerHTML = data.html;
            paginationControlsDiv.innerHTML = data.pagination_html;
            totalCustomersCountSpan.textContent = data.total_customers;
            customersTableBody.style.display = '';
            attachEventListeners();
            paginationControlsDiv.style.display = data.total_customers === 0 ? 'none' : 'block';

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
                    header.dataset.sortOrder = (currentSortOrder === 'ASC') ? 'DESC' : 'ASC';
                } else {
                    header.dataset.sortOrder = 'ASC';
                }
            });
            updateLimitSelect();
        }

        function loadCustomers(page = 1, searchQuery = '', sortBy = currentSortBy, sortOrder = currentSortOrder, limit = activeLimit, statusFilter = '', packageFilter = '') {
            let url = `customers.php?page=${page}&limit=${limit}&sort_by=${sortBy}&sort_order=${sortOrder}`;
            if (searchQuery) {
                url += `&search=${encodeURIComponent(searchQuery)}`;
            }
            if (statusFilter) {
                url += `&status_filter=${encodeURIComponent(statusFilter)}`;
            }
            if (packageFilter) {
                url += `&package_filter=${encodeURIComponent(packageFilter)}`;
            }

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
                    updateTableAndPagination(data);
                })
                .catch(error => {
                    console.error("Error loading customers:", error);
                    // Update colspan here to 7
                    customersTableBody.innerHTML = `<tr><td class='not-found' colspan='7'><div class='alert alert-danger mb-0'>Gagal memuat data: ${error.message}</div></td></tr>`;
                    paginationControlsDiv.innerHTML = '';
                    totalCustomersCountSpan.textContent = '0';
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
        }

        function handlePageClick(e) {
            e.preventDefault();
            const newPage = parseInt(this.dataset.page);
            const currentSearchQuery = searchInput.value;
            const currentStatusFilter = statusFilterSelect.value;
            const currentPackageFilter = packageFilterSelect.value;
            if (!isNaN(newPage) && newPage > 0) {
                loadCustomers(newPage, currentSearchQuery, currentSortBy, currentSortOrder, activeLimit, currentStatusFilter, currentPackageFilter);
            }
        }

        function handleLimitChange() {
            activeLimit = parseInt(this.value);
            const currentSearchQuery = searchInput.value;
            const currentStatusFilter = statusFilterSelect.value;
            const currentPackageFilter = packageFilterSelect.value;
            loadCustomers(1, currentSearchQuery, currentSortBy, currentSortOrder, activeLimit, currentStatusFilter, currentPackageFilter);
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

            const currentSearchQuery = searchInput.value;
            const currentStatusFilter = statusFilterSelect.value;
            const currentPackageFilter = packageFilterSelect.value;

            loadCustomers(1, currentSearchQuery, currentSortBy, currentSortOrder, activeLimit, currentStatusFilter, currentPackageFilter);
        }

        searchButton.addEventListener('click', function() {
            const currentSearchQuery = searchInput.value;
            const currentStatusFilter = statusFilterSelect.value;
            const currentPackageFilter = packageFilterSelect.value;
            loadCustomers(1, currentSearchQuery, currentSortBy, currentSortOrder, activeLimit, currentStatusFilter, currentPackageFilter);
        });

        searchInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                searchButton.click();
            }
        });

        statusFilterSelect.addEventListener('change', function() {
            const currentSearchQuery = searchInput.value;
            const currentStatusFilter = statusFilterSelect.value;
            const currentPackageFilter = packageFilterSelect.value;
            loadCustomers(1, currentSearchQuery, currentSortBy, currentSortOrder, activeLimit, currentStatusFilter, currentPackageFilter);
        });

        packageFilterSelect.addEventListener('change', function() {
            const currentSearchQuery = searchInput.value;
            const currentStatusFilter = statusFilterSelect.value;
            const currentPackageFilter = packageFilterSelect.value;
            loadCustomers(1, currentSearchQuery, currentSortBy, currentSortOrder, activeLimit, currentStatusFilter, currentPackageFilter);
        });

        function adjustLimitAndReload() {
            const newLimit = getLimitByScreenWidth();
            if (newLimit !== activeLimit) {
                activeLimit = newLimit;
                const currentSearchQuery = searchInput.value;
                const currentStatusFilter = statusFilterSelect.value;
                const currentPackageFilter = packageFilterSelect.value;
                loadCustomers(1, currentSearchQuery, currentSortBy, currentSortOrder, activeLimit, currentStatusFilter, currentPackageFilter);
            } else {
                updateLimitSelect();
            }
        }

        window.addEventListener('resize', adjustLimitAndReload);

        if (!<?php echo json_encode($is_ajax_request); ?>) {
            adjustLimitAndReload();
        }

        attachEventListeners();
    });
</script>