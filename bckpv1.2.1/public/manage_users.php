<?php
// public/manage_users.php
require_once '../includes/db_connect.php';
require_once '../includes/session.php';
require_once '../includes/functions.php';

check_role(['superadmin', 'admin']);

$title = "Manajemen Pengguna";

$sort_by = $_GET['sort_by'] ?? 'name';
$sort_order = $_GET['sort_order'] ?? 'ASC';
$search_query = $_GET['search'] ?? '';
$role_filter = $_GET['role_filter'] ?? '';

// Add 'phone' to allowed sort by columns
$allowed_sort_by = ['name', 'email', 'username', 'role_name', 'phone'];
if (!in_array($sort_by, $allowed_sort_by)) $sort_by = 'name';

$sort_order = strtoupper($sort_order);
if (!in_array($sort_order, ['ASC', 'DESC'])) $sort_order = 'ASC';

// Define options for limit per page
$limit_per_page_options = [20, 50, 100, 250, 500, 1000]; // Added 20 for mobile

// Default desktop limit
$default_desktop_limit = 50;
// Default mobile limit
$default_mobile_limit = 20;
// Breakpoint for mobile (matches CSS media query)
$mobile_breakpoint = 991; // Based on Bootstrap's sm breakpoint

$limit_per_page = isset($_GET['limit']) && in_array((int)$_GET['limit'], $limit_per_page_options) ? (int)$_GET['limit'] : $default_desktop_limit;

$current_page = isset($_GET['page']) && is_numeric($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($current_page - 1) * $limit_per_page;

$total_users = 0;
$users = [];
$is_ajax_request = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

// Select 'phone' column
$base_sql = "SELECT u.id, u.name, u.email, u.username, u.phone, r.role_name FROM users u JOIN roles r ON u.role_id = r.id WHERE 1=1";
$count_sql = "SELECT COUNT(u.id) FROM users u JOIN roles r ON u.role_id = r.id WHERE 1=1";

$params = [];
$param_types = "";

if (!empty($search_query)) {
    // Add u.phone to the search criteria
    $base_sql .= " AND (u.name LIKE ? OR u.email LIKE ? OR u.username LIKE ? OR u.phone LIKE ?)";
    $count_sql .= " AND (u.name LIKE ? OR u.email LIKE ? OR u.username LIKE ? OR u.phone LIKE ?)";
    $search_param = '%' . $search_query . '%';
    // Add search_param for phone
    $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param]);
    $param_types .= "ssss"; // One more 's' for phone
}

if (!empty($role_filter)) {
    $base_sql .= " AND r.role_name = ?";
    $count_sql .= " AND r.role_name = ?";
    $params[] = $role_filter;
    $param_types .= "s";
}

$stmt_count = $conn->prepare($count_sql);
if ($stmt_count === false) {
    die("Error preparing count statement: " . $conn->error);
}
if (!empty($params) && !empty($param_types)) {
    $stmt_count->bind_param($param_types, ...$params);
}
$stmt_count->execute();
$stmt_count->bind_result($total_users);
$stmt_count->fetch();
$stmt_count->close();

$total_pages = ceil($total_users / $limit_per_page);

if ($current_page > $total_pages && $total_pages > 0) {
    $current_page = $total_pages;
    $offset = ($current_page - 1) * $limit_per_page;
} elseif ($total_pages === 0) {
    $current_page = 1;
    $offset = 0;
}

$base_sql .= " ORDER BY $sort_by $sort_order LIMIT ? OFFSET ?";
$params[] = $limit_per_page;
$params[] = $offset;
$param_types .= "ii";

$stmt = $conn->prepare($base_sql);
if ($stmt === false) {
    die("Error preparing main statement: " . $conn->error);
}
if (!empty($params) && !empty($param_types)) {
    $stmt->bind_param($param_types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $users[] = $row;
}
$stmt->close();

$roles = [];
$stmt_roles = $conn->prepare("SELECT role_name FROM roles ORDER BY role_name ASC");
if ($stmt_roles === false) {
    die("Error preparing roles statement: " . $conn->error);
}
$stmt_roles->execute();
$result_roles = $stmt_roles->get_result();
while ($row_role = $result_roles->fetch_assoc()) {
    $roles[] = $row_role['role_name'];
}
$stmt_roles->close();

$logged_in_user_role = $_SESSION['role_name'] ?? '';

ob_start();
?>
<div class='d-flex justify-content-between align-items-center flex-wrap mt-4'>
    <div class='d-flex align-items-center mb-2 mb-md-0'>
        <label for='limitPerPage' class='form-label me-2 mb-0'>Tampilkan:</label>
        <select class='form-select form-select-sm w-auto' id='limitPerPage'>
            <?php foreach ($limit_per_page_options as $option): ?>
                <option value='<?php echo $option; ?>' <?php echo ($option == $limit_per_page) ? 'selected' : ''; ?>>
                    <?php echo $option; ?>
                </option>
            <?php endforeach; ?>
        </select>
        <span class='ms-2 text-muted'>dari <span id='totalUsersCount'><?php echo $total_users; ?></span> data</span>
    </div>
    <?php if ($total_pages > 1): ?>
        <ul class='pagination mb-0'>
            <li class='page-item <?php echo ($current_page <= 1 ? 'disabled' : ''); ?>'>
                <a class='page-link' href='#' data-page='<?php echo max(1, $current_page - 1); ?>'>&laquo;</a>
            </li>
            <?php
            $max_display = 5;
            $start_page = max(1, $current_page - floor($max_display / 2));
            $end_page = min($total_pages, $start_page + $max_display - 1);
            if ($end_page - $start_page < $max_display - 1) {
                $start_page = max(1, $end_page - $max_display + 1);
            }
            if ($start_page > 1) {
                echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
            }
            for ($i = $start_page; $i <= $end_page; $i++) {
                echo "<li class='page-item " . ($i == $current_page ? 'active' : '') . "'><a class='page-link' href='#' data-page='$i'>$i</a></li>";
            }
            if ($end_page < $total_pages) {
                echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
            }
            ?>
            <li class='page-item <?php echo ($current_page >= $total_pages ? 'disabled' : ''); ?>'>
                <a class='page-link' href='#' data-page='<?php echo min($total_pages, $current_page + 1); ?>'>&raquo;</a>
            </li>
        </ul>
    <?php endif; ?>
</div>
<?php
$pagination_content = ob_get_clean();


if ($is_ajax_request) {
    ob_start();
    foreach ($users as $user) {
        echo "<tr>";
        echo "<td data-label='Nama Pengguna'>" . htmlspecialchars($user['name']) . "</td>";
        echo "<td data-label='Email'>" . htmlspecialchars($user['email']) . "</td>";
        echo "<td data-label='Username'>" . htmlspecialchars($user['username']) . "</td>";
        // Display phone number
        echo "<td data-label='Phone'>" . htmlspecialchars($user['phone']) . "</td>";
        echo "<td data-label='Role'>" . htmlspecialchars($user['role_name']) . "</td>";
        echo "<td data-label='Aksi'>";
        echo "<a href='edit_user.php?id=" . htmlspecialchars($user['id']) . "' class='btn btn-sm btn-warning me-1'><i class='bi bi-pencil-fill'></i></a>";
        if ($logged_in_user_role === 'superadmin') {
            echo "<a href='delete_user.php?id=" . htmlspecialchars($user['id']) . "' class='btn btn-sm btn-danger' onclick='return confirm(\"Apakah Anda yakin ingin menghapus pengguna ini?\")'><i class='bi bi-trash-fill'></i></a>";
        }
        echo "</td>";
        echo "</tr>";
    }
    // Change colspan to 6 because we added a new column
    if (empty($users)) {
        echo "<tr><td class='not-found' colspan='6'><div class='alert alert-info mb-0'>Tidak ada data pengguna yang ditemukan.</div></td></tr>";
    }
    $tbody_content = ob_get_clean();

    ob_start();
?>
    <div class='d-flex justify-content-between flex-column-reverse flex-lg-row gap-3 align-items-center flex-wrap mt-4'>
        <div class='d-flex align-items-center mb-2 mb-md-0'>
            <label for='limitPerPage' class='form-label me-2 mb-0'>Tampilkan:</label>
            <select class='form-select form-select-sm w-auto' id='limitPerPage'>
                <?php foreach ($limit_per_page_options as $option): ?>
                    <option value='<?php echo $option; ?>' <?php echo ($option == $limit_per_page) ? 'selected' : ''; ?>>
                        <?php echo $option; ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <span class='ms-2 text-muted'>dari <span id='totalUsersCount'><?php echo $total_users; ?></span> data</span>
        </div>
        <?php if ($total_pages > 1): ?>
            <ul class='pagination mb-0'>
                <li class='page-item <?php echo ($current_page <= 1 ? 'disabled' : ''); ?>'>
                    <a class='page-link' href='#' data-page='<?php echo max(1, $current_page - 1); ?>'>&laquo;</a>
                </li>
                <?php
                $max_display = 5;
                $start_page = max(1, $current_page - floor($max_display / 2));
                $end_page = min($total_pages, $start_page + $max_display - 1);
                if ($end_page - $start_page < $max_display - 1) {
                    $start_page = max(1, $end_page - $max_display + 1);
                }
                if ($start_page > 1) {
                    echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                }
                for ($i = $start_page; $i <= $end_page; $i++) {
                    echo "<li class='page-item " . ($i == $current_page ? 'active' : '') . "'><a class='page-link' href='#' data-page='$i'>$i</a></li>";
                }
                if ($end_page < $total_pages) {
                    echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                }
                ?>
                <li class='page-item <?php echo ($current_page >= $total_pages ? 'disabled' : ''); ?>'>
                    <a class='page-link' href='#' data-page='<?php echo min($total_pages, $current_page + 1); ?>'>&raquo;</a>
                </li>
            </ul>
        <?php endif; ?>
    </div>
<?php
    $pagination_content = ob_get_clean();

    echo json_encode([
        'html' => $tbody_content,
        'pagination_html' => $pagination_content,
        'total_users' => $total_users
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

    <div id="page-content-wrapper" class="flex-grow-1 mx-2 mx-lg-4 py-lg-4">
        <div class="d-flex justify-content-between align-items-center flex-column flex-lg-row gap-3 m-4 mx-lg-0 mb-4">
            <h1 class="display-5 mb-0">Manajemen Pengguna</h1>
            <a href="add_user.php" class="btn btn-success">
                <i class="bi bi-person-plus-fill me-2"></i>Tambah Pengguna
            </a>
        </div>

        <div class="card">
            <div class="card-body body-manajemen">
                <div class="row mb-3 align-items-center">
                    <div class="col-md-4 mb-2 mb-md-0">
                        <input type="text" id="searchInput" class="form-control" placeholder="Cari nama, email, username, atau telepon..." value="<?php echo htmlspecialchars($search_query); ?>">
                    </div>
                    <div class="col-md-4 mb-2 mb-md-0">
                        <select id="roleFilter" class="form-select">
                            <option value="">Semua Role</option>
                            <?php foreach ($roles as $role_name_option): ?>
                                <option value="<?php echo htmlspecialchars($role_name_option); ?>" <?php echo ($role_filter === $role_name_option) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($role_name_option); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <button id="searchButton" class="btn btn-primary w-100">Cari / Filter</button>
                    </div>
                </div>

                <div class="table-responsive">
                    <table id="usersTable" class="table table-striped">
                        <thead>
                            <tr>
                                <th class="sortable" data-label="Nama Pengguna" data-sort-by="name" data-sort-order="<?php echo ($sort_by == 'name' && $sort_order == 'ASC') ? 'DESC' : 'ASC'; ?>">
                                    Nama Pengguna
                                    <?php if ($sort_by == 'name'): ?><i class="bi bi-arrow-<?php echo ($sort_order == 'ASC') ? 'up' : 'down'; ?>"></i><?php endif; ?>
                                </th>
                                <th class="sortable" data-label="Email" data-sort-by="email" data-sort-order="<?php echo ($sort_by == 'email' && $sort_order == 'ASC') ? 'DESC' : 'ASC'; ?>">
                                    Email
                                    <?php if ($sort_by == 'email'): ?><i class="bi bi-arrow-<?php echo ($sort_order == 'ASC') ? 'up' : 'down'; ?>"></i><?php endif; ?>
                                </th>
                                <th class="sortable" data-label="Username" data-sort-by="username" data-sort-order="<?php echo ($sort_by == 'username' && $sort_order == 'ASC') ? 'DESC' : 'ASC'; ?>">
                                    Username
                                    <?php if ($sort_by == 'username'): ?><i class="bi bi-arrow-<?php echo ($sort_order == 'ASC') ? 'up' : 'down'; ?>"></i><?php endif; ?>
                                </th>
                                <th class="sortable" data-label="Phone" data-sort-by="phone" data-sort-order="<?php echo ($sort_by == 'phone' && $sort_order == 'ASC') ? 'DESC' : 'ASC'; ?>">
                                    Phone
                                    <?php if ($sort_by == 'phone'): ?><i class="bi bi-arrow-<?php echo ($sort_order == 'ASC') ? 'up' : 'down'; ?>"></i><?php endif; ?>
                                </th>
                                <th class="sortable" data-label="Role" data-sort-by="role_name" data-sort-order="<?php echo ($sort_by == 'role_name' && $sort_order == 'ASC') ? 'DESC' : 'ASC'; ?>">
                                    Role
                                    <?php if ($sort_by == 'role_name'): ?><i class="bi bi-arrow-<?php echo ($sort_order == 'ASC') ? 'up' : 'down'; ?>"></i><?php endif; ?>
                                </th>
                                <th data-label="Aksi">Aksi</th>
                            </tr>
                        </thead>
                        <tbody id="usersTableBody">
                            <?php if (!empty($users) || $total_users > 0): ?>
                                <?php foreach ($users as $user):
                                    $user_id = htmlspecialchars($user['id']);
                                    $user_name = htmlspecialchars($user['name']);
                                    $user_email = htmlspecialchars($user['email']);
                                    $user_username = htmlspecialchars($user['username']);
                                    $user_phone = htmlspecialchars($user['phone'] ?? ''); // Get phone, handle null
                                    $user_role_name = htmlspecialchars($user['role_name']);
                                ?>
                                    <tr>
                                        <td data-label="Nama Pengguna"><?php echo $user_name; ?></td>
                                        <td data-label="Email"><?php echo $user_email; ?></td>
                                        <td data-label="Username"><?php echo $user_username; ?></td>
                                        <td data-label="Phone"><?php echo $user_phone; ?></td>
                                        <td data-label="Role"><?php echo $user_role_name; ?></td>
                                        <td data-label="Aksi">
                                            <a href='edit_user.php?id=<?php echo $user_id; ?>' class='btn btn-sm btn-warning me-1' title="Edit Pengguna"><i class="bi bi-pencil-fill"></i></a>
                                            <?php
                                            if ($logged_in_user_role === 'superadmin') {
                                                echo "<a href='delete_user.php?id=" . $user_id . "' class='btn btn-sm btn-danger' onclick=\"return confirm('Apakah Anda yakin ingin menghapus pengguna ini?')\" title=\"Hapus Pengguna\"><i class=\"bi bi-trash-fill\"></i></a>";
                                            }
                                            ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td class="not-found" colspan="6">
                                        <div class="alert alert-info mb-0" role="alert">
                                            Tidak ada data pengguna yang ditemukan dengan kriteria tersebut.
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
        const usersTableBody = document.getElementById('usersTableBody');
        const paginationControlsDiv = document.getElementById('paginationControls');
        const searchInput = document.getElementById('searchInput');
        const searchButton = document.getElementById('searchButton');
        const limitPerPageSelect = document.getElementById('limitPerPage');
        const totalUsersCountSpan = document.getElementById('totalUsersCount');
        const roleFilterSelect = document.getElementById('roleFilter');

        const defaultDesktopLimit = <?php echo json_encode($default_desktop_limit); ?>;
        const defaultMobileLimit = <?php echo json_encode($default_mobile_limit); ?>;
        const mobileBreakpoint = <?php echo json_encode($mobile_breakpoint); ?>;

        let currentSortBy = '<?php echo $sort_by; ?>';
        let currentSortOrder = '<?php echo $sort_order; ?>';

        function getLimitByScreenWidth() {
            return window.innerWidth <= mobileBreakpoint ? defaultMobileLimit : defaultDesktopLimit;
        }

        let activeLimit = getLimitByScreenWidth();

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
            usersTableBody.style.display = 'none';

            usersTableBody.innerHTML = data.html;

            paginationControlsDiv.innerHTML = data.pagination_html;

            totalUsersCountSpan.textContent = data.total_users;

            usersTableBody.style.display = '';

            attachEventListeners();

            paginationControlsDiv.style.display = data.total_users === 0 ? 'none' : 'block';

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

        function loadUsers(page = 1, searchQuery = '', sortBy = currentSortBy, sortOrder = currentSortOrder, limit = activeLimit, roleFilter = '') {
            let url = `manage_users.php?page=${page}&limit=${limit}&sort_by=${sortBy}&sort_order=${sortOrder}`;
            if (searchQuery) {
                url += `&search=${encodeURIComponent(searchQuery)}`;
            }
            if (roleFilter) {
                url += `&role_filter=${encodeURIComponent(roleFilter)}`;
            }

            usersTableBody.innerHTML = `<tr><td colspan="6" class="text-center"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div> Memuat data...</td></tr>`; // Updated colspan

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
                    console.error("Error loading users:", error);
                    usersTableBody.innerHTML = `<tr><td colspan='6'><div class='alert alert-danger mb-0'>Gagal memuat data: ${error.message}</div></td></tr>`; // Updated colspan
                    paginationControlsDiv.innerHTML = '';
                    totalUsersCountSpan.textContent = '0';
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
            const currentRoleFilter = roleFilterSelect.value;
            if (!isNaN(newPage) && newPage > 0) {
                loadUsers(newPage, currentSearchQuery, currentSortBy, currentSortOrder, activeLimit, currentRoleFilter);
            }
        }

        function handleLimitChange() {
            activeLimit = parseInt(this.value);
            const currentSearchQuery = searchInput.value;
            const currentRoleFilter = roleFilterSelect.value;
            loadUsers(1, currentSearchQuery, currentSortBy, currentSortOrder, activeLimit, currentRoleFilter);
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
            const currentRoleFilter = roleFilterSelect.value;

            loadUsers(1, currentSearchQuery, currentSortBy, currentSortOrder, activeLimit, currentRoleFilter);
        }

        searchButton.addEventListener('click', function() {
            const currentSearchQuery = searchInput.value;
            const currentRoleFilter = roleFilterSelect.value;
            loadUsers(1, currentSearchQuery, currentSortBy, currentSortOrder, activeLimit, currentRoleFilter);
        });

        searchInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                searchButton.click();
            }
        });

        roleFilterSelect.addEventListener('change', function() {
            const currentSearchQuery = searchInput.value;
            const currentRoleFilter = roleFilterSelect.value;
            loadUsers(1, currentSearchQuery, currentSortBy, currentSortOrder, activeLimit, currentRoleFilter);
        });

        function adjustLimitAndReload() {
            const newLimit = getLimitByScreenWidth();
            const currentSearchQuery = searchInput.value;
            const currentRoleFilter = roleFilterSelect.value;

            if (newLimit !== activeLimit || !window.hasInitialLoadOccurred) {
                activeLimit = newLimit;
                window.hasInitialLoadOccurred = true;
                loadUsers(1, currentSearchQuery, currentSortBy, currentSortOrder, activeLimit, currentRoleFilter);
            } else {
                updateLimitSelect();
            }
        }

        window.addEventListener('resize', adjustLimitAndReload);

        adjustLimitAndReload();

        attachEventListeners();
    });
</script>