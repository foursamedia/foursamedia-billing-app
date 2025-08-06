<?php
// public/manage_payments.php
require_once '../includes/db_connect.php';
require_once '../includes/session.php';
require_once '../includes/functions.php';

// Check if the user is logged in and has the required role
check_login();
check_role(['superadmin', 'admin']);

// Set page title
$title = "Manajemen Pembayaran";

// Initialize search query from GET parameter
$search_query = $_GET['search'] ?? '';
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';

// --- Pagination Settings ---
// Get current page, defaulting to 1
$current_page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;

// Define allowed limits per page
$limit_per_page_options = [20, 50, 100];
// Ensure $limit_per_page is one of the valid options, default to 20
$limit_per_page = isset($_GET['limit']) && in_array((int)$_GET['limit'], $limit_per_page_options) ? (int)$_GET['limit'] : 20;

// Calculate offset for SQL query
$offset = ($current_page - 1) * $limit_per_page;

$total_payments = 0;
$payments = [];

// Determine if the request is an AJAX call
$is_ajax_request = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

// --- START SQL QUERIES for Search & Pagination ---

// SQL for counting total data (without LIMIT/OFFSET)
$count_sql = "
    SELECT COUNT(p.id)
    FROM payments p
    JOIN users u ON p.user_id = u.id
    LEFT JOIN users ui ON p.input_by_user_id = ui.id
    WHERE 1=1
";

// SQL for fetching data with pagination and sorting
$base_sql = "
    SELECT
        p.id AS payment_id,
        p.amount,
        p.payment_date,
        p.description,
	p.input_record_date,
	p.created_at, -- MENAMBAHKAN INI
        p.updated_at, -- DAN INI
        u.name AS customer_name,
        ui.name AS inputter_name,
        ui.email AS inputter_email
    FROM
        payments p
    JOIN
        users u ON p.user_id = u.id
    LEFT JOIN
        users ui ON p.input_by_user_id = ui.id
    WHERE 1=1
";

// Parameters for COUNT query (only search parameters)
$count_params = [];
$count_param_types = "";

// Add search condition if search_query exists
if (!empty($search_query)) {
    $where_clause = " AND (u.name LIKE ? OR ui.name LIKE ? OR p.description LIKE ?)";
    $base_sql .= $where_clause;
    $count_sql .= $where_clause;

    $search_param = '%' . $search_query . '%';

    // For COUNT query
    $count_params[] = $search_param;
    $count_params[] = $search_param;
    $count_params[] = $search_param;
    $count_param_types .= "sss";
}

// Add date range condition if start_date or end_date exists
if (!empty($start_date) && !empty($end_date)) {
    // Both dates provided, use BETWEEN
    $date_where_clause = " AND p.payment_date BETWEEN ? AND ?";
    $base_sql .= $date_where_clause;
    $count_sql .= $date_where_clause;

    $count_params[] = $start_date;
    $count_params[] = $end_date;
    $count_param_types .= "ss"; // 's' for string (date)
} elseif (!empty($start_date)) {
    // Only start_date provided, search from this date onwards
    $date_where_clause = " AND p.payment_date >= ?";
    $base_sql .= $date_where_clause;
    $count_sql .= $date_where_clause;

    $count_params[] = $start_date;
    $count_param_types .= "s";
} elseif (!empty($end_date)) {
    // Only end_date provided, search up to this date
    $date_where_clause = " AND p.payment_date <= ?";
    $base_sql .= $date_where_clause;
    $count_sql .= $date_where_clause;

    $count_params[] = $end_date;
    $count_param_types .= "s";
}

// Execute COUNT query
$stmt_count = $conn->prepare($count_sql);
if ($stmt_count === false) {
    die("Error preparing count statement: " . $conn->error);
}
if (!empty($count_params) && !empty($count_param_types)) {
    $stmt_count->bind_param($count_param_types, ...$count_params);
}
$stmt_count->execute();
$stmt_count->bind_result($total_payments);
$stmt_count->fetch();
$stmt_count->close();

// Ensure $limit_per_page is not zero to prevent division by zero error
$total_pages = ($limit_per_page > 0) ? ceil($total_payments / $limit_per_page) : 0;

// Ensure current_page doesn't exceed total_pages if total_payments changes (e.g., search filters out all results)
// Or if there are no results, set current_page to 0 or 1 depending on desired display
if ($total_pages === 0) {
    $current_page = 1; // Display page 1 even if no results
    $offset = 0;
} elseif ($current_page > $total_pages) {
    $current_page = $total_pages;
    $offset = ($current_page - 1) * $limit_per_page; // Recalculate offset for the new current_page
}


// Add ORDER BY and LIMIT/OFFSET to main query
$base_sql .= " ORDER BY p.payment_date DESC, p.id DESC LIMIT ? OFFSET ?";
$main_params = array_merge($count_params, [$limit_per_page, $offset]); // Combine search params with limit/offset
$main_param_types = $count_param_types . "ii"; // Combine search param types with limit/offset types

// Execute MAIN query
$stmt = $conn->prepare($base_sql);
if ($stmt === false) {
    die("Error preparing main statement: " . $conn->error);
}
if (!empty($main_params) && !empty($main_param_types)) {
    $stmt->bind_param($main_param_types, ...$main_params);
}
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $payments[] = $row;
    }
}
$stmt->close();
// --- END SQL QUERIES for Search & Pagination ---

// Handle AJAX Request (returns JSON)
if ($is_ajax_request) {
    // Render tbody content
    ob_start();
    if (!empty($payments)) {
        foreach ($payments as $payment) {
?>
            <tr>
                <td data-label="ID"><?php echo htmlspecialchars($payment['payment_id']); ?></td>
                <td data-label="Pelanggan"><?php echo htmlspecialchars($payment['customer_name']); ?></td>
                <td data-label="Jumlah">Rp<?php echo number_format($payment['amount'], 0, ',', '.'); ?></td>
                <td data-label="Tanggal Pembayaran"><?php echo htmlspecialchars($payment['payment_date']); ?></td>
                <td data-label="Tanggal Input"><?php echo htmlspecialchars($payment['input_record_date']); ?></td>
				<td data-label="Deskripsi"><?php echo htmlspecialchars($payment['description']); ?></td>
                <td data-label="Diinput Oleh">
                    <?php
                    if (!empty($payment['inputter_name'])) {
                        echo htmlspecialchars($payment['inputter_name']) . " (" . htmlspecialchars($payment['inputter_email']) . ")";
                    } else {
                        echo "Tidak Diketahui";
                    }
                    ?>
                </td>
                <td data-label="Aksi">
                    <a href="edit_payment.php?id=<?php echo htmlspecialchars($payment['payment_id']); ?>" class="btn btn-warning btn-sm">Edit</a>
                    <?php if ($_SESSION['role_name'] === 'superadmin' || $_SESSION['role_name'] === 'admin'): ?>
                        <a href="delete_payment.php?id=<?php echo htmlspecialchars($payment['payment_id']); ?>" class="btn btn-danger btn-sm" onclick="return confirm('Apakah Anda yakin ingin menghapus pembayaran ini?');">Hapus</a>
                    <?php endif; ?>
                </td>
            </tr>
        <?php
        }
    } else {
        ?>
        <tr>
            <td class="not-found" colspan="7">
                <div class="alert alert-info mb-0" role="alert">
                    Tidak ada data pembayaran yang ditemukan dengan kriteria tersebut.
                </div>
            </td>
        </tr>
    <?php
    }
    $tbody_content = ob_get_clean();

    // Render pagination HTML for AJAX
    ob_start();

    // Define max pages to display for AJAX response
    $max_display_pages = 5;
    ?>
    <nav aria-label="Page navigation" class="mt-3">
        <ul class="pagination justify-content-center">
            <li class="page-item <?php echo ((int)$current_page <= 1) ? 'disabled' : ''; ?>">
                <a class="page-link" href="#" data-page="<?php echo max(1, (int)$current_page - 1); ?>">«</a>
            </li>

            <?php
            // Calculate start and end pages for display
            $start_page = max(1, (int)$current_page - floor($max_display_pages / 2));
            $end_page = min((int)$total_pages, $start_page + $max_display_pages - 1);

            // Adjust start_page if we are at the very end of pages
            if ($end_page - $start_page < $max_display_pages - 1) {
                $start_page = max(1, (int)$end_page - $max_display_pages + 1);
            }

            // Show ellipsis at the beginning if not starting from page 1
            if ($start_page > 1) {
                echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
            }

            // --- CORRECTED LOOP FOR AJAX RESPONSE ---
            for ($i = (int)$start_page; $i <= (int)$end_page; $i++) {
                echo "<li class='page-item " . (($i == (int)$current_page) ? 'active' : '') . "'><a class='page-link' href='#' data-page='" . (int)$i . "'>" . (int)$i . "</a></li>";
            }
            // --- END CORRECTED LOOP FOR AJAX RESPONSE ---

            // Show ellipsis at the end if not ending at total_pages
            if ($end_page < (int)$total_pages) {
                echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
            }
            ?>

            <li class="page-item <?php echo ((int)$current_page >= (int)$total_pages) ? 'disabled' : ''; ?>">
                <a class="page-link" href="#" data-page="<?php echo min((int)$total_pages, (int)$current_page + 1); ?>">»</a>
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
        <span class="ms-2 text-muted">dari <span id="totalPaymentsCount"><?php echo $total_payments; ?></span> data</span>
    </div>
<?php
    $pagination_content = ob_get_clean();

    // Return all data as JSON
    echo json_encode([
        'html' => $tbody_content,
        'pagination_html' => $pagination_content,
        'total_payments' => $total_payments
    ]);
    exit(); // Terminate script after AJAX response
}

// --- START HTML OUTPUT for initial page load ---
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
            <h1 class="display-5">Manajemen Pembayaran</h1>
            <div class="d-flex justify-content-between align-items-center">
                <?php // Display CRUD operation status messages
                if (isset($_GET['status'])):
                ?>
                    <?php if ($_GET['status'] == 'success_add'): ?>
                        <div class="alert alert-success mt-3 ms-auto">Pembayaran berhasil ditambahkan.</div>
                    <?php elseif ($_GET['status'] == 'success_edit'): ?>
                        <div class="alert alert-success mt-3 ms-auto">Pembayaran berhasil diperbarui.</div>
                    <?php elseif ($_GET['status'] == 'success_delete'): ?>
                        <div class="alert alert-success mt-3 ms-auto">Pembayaran berhasil dihapus.</div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-body">
                <div class="row g-3 align-items-center">
                    <div class="col-md-12">
                       <div class="row g-3 align-items-center">
				<div class="col-md-4">
					<div class="input-group">
						<input type="text" class="form-control" id="searchInput" name="search" placeholder="Cari Pelanggan, Deskripsi, atau Penginput..." value="<?php echo htmlspecialchars($search_query); ?>">
							<span class="input-group-text"><i class="bi bi-search"></i></span>
							</div>
						</div>
					<div class="col-md-8 d-flex flex-column flex-md-row gap-2">
						<div class="input-group">
							<span class="input-group-text">Dari Tanggal</span>
							<input type="date" class="form-control" id="startDateInput" name="start_date" value="<?php echo htmlspecialchars($_GET['start_date'] ?? ''); ?>">
						</div>
						<div class="input-group">
							<span class="input-group-text">Sampai Tanggal</span>
							<input type="date" class="form-control" id="endDateInput" name="end_date" value="<?php echo htmlspecialchars($_GET['end_date'] ?? ''); ?>">
						</div>			
					</div>
					<button type="button" id="resetFilterBtn" class="btn btn-secondary flex-shrink-0">
						<i class="bi bi-arrow-counterclockwise"></i> Reset
					</button>
					<a href="#" id="exportExcelBtn" class="btn btn-success flex-shrink-0">
						<i class="bi bi-file-earmark-excel"></i> Export Excel </a>					
			</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="table-responsive">
            <table id="paymentsTable" class="table table-striped table-bordered">
                <thead>
                    <tr>
                        <th data-label="ID">ID</th>
                        <th data-label="Pelanggan">Pelanggan</th>
                        <th data-label="Jumlah">Jumlah</th>
                        <th data-label="Tanggal Pembayaran">Tanggal Pembayaran</th>
			<th data-label="Tanggal Pembayaran">Input Pembayaran</th>
                        <th data-label="Deskripsi">Deskripsi</th>
                        <th data-label="Diinput Oleh">Diinput Oleh</th>
                        <th data-label="Aksi">Aksi</th>
                    </tr>
                </thead>
                <tbody id="paymentsTableBody">
                    <?php // Tbody content is filled by PHP on initial load, or by JS via Ajax
                    if (!empty($payments)): // Only iterate if $payments array is not empty
                    ?>
                        <?php foreach ($payments as $payment): ?>
                            <tr>
                                <td data-label="ID"><?php echo htmlspecialchars($payment['payment_id']); ?></td>
                                <td data-label="Pelanggan"><?php echo htmlspecialchars($payment['customer_name']); ?></td>
                                <td data-label="Jumlah">Rp<?php echo number_format($payment['amount'], 0, ',', '.'); ?></td>
                                <td data-label="Tanggal Pembayaran"><?php echo htmlspecialchars($payment['payment_date']); ?></td>
				<td data-label="Input Pembayaran"><?php echo htmlspecialchars($payment['input_record_date']); ?></td>
                                <td data-label="Deskripsi"><?php echo htmlspecialchars($payment['description']); ?></td>
                                <td data-label="Diinput Oleh">
                                    <?php
                                    if (!empty($payment['inputter_name'])) {
                                        echo htmlspecialchars($payment['inputter_name']) . " (" . htmlspecialchars($payment['inputter_email']) . ")";
                                    } else {
                                        echo "Tidak Diketahui"; // If input_by_user_id is NULL
                                    }
                                    ?>
                                </td>
                                <td data-label="Aksi">
                                    <a href="edit_payment.php?id=<?php echo htmlspecialchars($payment['payment_id']); ?>" class="btn btn-warning btn-sm">Edit</a>
                                    <?php if ($_SESSION['role_name'] === 'superadmin' ): ?>
                                        <a href="delete_payment.php?id=<?php echo htmlspecialchars($payment['payment_id']); ?>" class="btn btn-danger btn-sm" onclick="return confirm('Apakah Anda yakin ingin menghapus pembayaran ini?');">Hapus</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td class="not-found" data-label="null" colspan="7">
                                <div class="alert alert-info mb-0" role="alert">
                                    Tidak ada data pembayaran yang ditemukan.
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div id="paginationControls" class="d-flex flex-column flex-lg-row-reverse align-items-center justify-content-between">
            <?php
            // Define max pages to display for initial page load
            $max_display_pages = 5;
            ?>
            <nav aria-label="Page navigation" class="mt-3">
                <ul class="pagination justify-content-center">
                    <li class="page-item <?php echo ((int)$current_page <= 1) ? 'disabled' : ''; ?>">
                        <a class="page-link" href="#" data-page="<?php echo max(1, (int)$current_page - 1); ?>">«</a>
                    </li>

                    <?php
                    // Calculate start and end pages for display
                    $start_page = max(1, (int)$current_page - floor($max_display_pages / 2));
                    $end_page = min((int)$total_pages, $start_page + $max_display_pages - 1);

                    // Adjust start_page if we are at the very end of pages
                    if ($end_page - $start_page < $max_display_pages - 1) {
                        $start_page = max(1, (int)$end_page - $max_display_pages + 1);
                    }

                    // Show ellipsis at the beginning if not starting from page 1
                    if ($start_page > 1) {
                        echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                    }

                    // --- CORRECTED LOOP FOR INITIAL PAGE LOAD ---
                    for ($i = (int)$start_page; $i <= (int)$end_page; $i++) {
                        echo "<li class='page-item " . (($i == (int)$current_page) ? 'active' : '') . "'><a class='page-link' href='#' data-page='" . (int)$i . "'>" . (int)$i . "</a></li>";
                    }
                    // --- END CORRECTED LOOP FOR INITIAL PAGE LOAD ---

                    // Show ellipsis at the end if not ending at total_pages
                    if ($end_page < (int)$total_pages) {
                        echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                    }
                    ?>

                    <li class="page-item <?php echo ((int)$current_page >= (int)$total_pages) ? 'disabled' : ''; ?>">
                        <a class="page-link" href="#" data-page="<?php echo min((int)$total_pages, (int)$current_page + 1); ?>">»</a>
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
                <span class="ms-2 text-muted">dari <span id="totalPaymentsCount"><?php echo $total_payments; ?></span> data</span>
            </div>
        </div>
    </div>
</div>

<?php
if (file_exists('../includes/footer.php')) {
    require_once '../includes/footer.php';
} else {
    // Consider logging this error in a real application
    error_log("ERROR: Footer file not found at ../includes/footer.php in manage_payments.php");
    echo "<div style='color: red; padding: 20px;'>Footer not found. Please check your file path.</div>";
}
?>

<script>
const exportExcelBtn = document.getElementById('exportExcelBtn');
const startDateInput = document.getElementById('startDateInput'); // NEW
const endDateInput = document.getElementById('endDateInput'); // NEW
const resetFilterBtn = document.getElementById('resetFilterBtn'); // NEW

    function updateExportLink() {
    const currentSearchQuery = searchInput.value;
    const currentStartDate = startDateInput.value; // NEW
    const currentEndDate = endDateInput.value; // NEW

    let exportUrl = 'export_payments.php?';
    const params = [];

    if (currentSearchQuery) {
        params.push(`search=${encodeURIComponent(currentSearchQuery)}`);
    }
    if (currentStartDate) {
        params.push(`start_date=${encodeURIComponent(currentStartDate)}`);
    }
    if (currentEndDate) {
        params.push(`end_date=${encodeURIComponent(currentEndDate)}`);
    }

    exportUrl += params.join('&');

    if (exportExcelBtn) {
        exportExcelBtn.href = exportUrl;
    }
}
    // Call updateExportLink initially when the page loads
    updateExportLink();
    // Modify the searchInput event listener to also update the export link
		searchInput.addEventListener('input', function() {
		clearTimeout(searchTimeout);
		searchTimeout = setTimeout(() => {
			const currentSearchQuery = this.value;
			const currentLimit = paginationControlsDiv ? parseInt(paginationControlsDiv.querySelector('#limitPerPage').value) : <?php echo $limit_per_page; ?>;
			// Pass start_date and end_date to loadPayments
			loadPayments(1, currentSearchQuery, currentLimit, startDateInput.value, endDateInput.value); // MODIFIED
			updateExportLink();
		}, 300);
	});

    document.addEventListener('DOMContentLoaded', function() {
        const paymentsTableBody = document.getElementById('paymentsTableBody');
        const paginationControlsDiv = document.getElementById('paginationControls');
        const searchInput = document.getElementById('searchInput');
        const totalPaymentsCountSpan = document.getElementById('totalPaymentsCount'); // Get the span for total count

        let searchTimeout;

	function loadPayments(page = 1, searchQuery = '', limit = <?php echo $limit_per_page; ?>, startDate = '', endDate = '') { // ADD startDate, endDate
			// Add a loading indicator to the table body
			paymentsTableBody.innerHTML = `<tr><td colspan="7" class="text-center"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div> Memuat data...</td></tr>`;
			// Disable pagination controls during load
			if (paginationControlsDiv) {
				paginationControlsDiv.querySelectorAll('.page-link, #limitPerPage').forEach(el => el.setAttribute('disabled', 'disabled'));
			}

			let url = `manage_payments.php?page=${page}&limit=${limit}`;
			if (searchQuery) {
				url += `&search=${encodeURIComponent(searchQuery)}`;
			}
			// Add date parameters to the URL
			if (startDate) { // NEW
				url += `&start_date=${encodeURIComponent(startDate)}`;
			}
			if (endDate) { // NEW
				url += `&end_date=${encodeURIComponent(endDate)}`;
			}

			fetch(url, {
				headers: {
					'X-Requested-With': 'XMLHttpRequest'
				}
			})
			.then(response => {
				if (!response.ok) {
					throw new Error('Network response was not ok: ' + response.statusText);
				}
				return response.json();
			})
			.then(data => {
				paymentsTableBody.innerHTML = data.html;
				if (paginationControlsDiv) {
					paginationControlsDiv.innerHTML = data.pagination_html;
					if (totalPaymentsCountSpan) {
						totalPaymentsCountSpan.textContent = data.total_payments;
					}

					paginationControlsDiv.querySelectorAll('.page-link, #limitPerPage').forEach(el => el.removeAttribute('disabled'));

					attachEventListeners();

					if (data.total_payments === 0) {
						paginationControlsDiv.style.display = 'none';
					} else {
						paginationControlsDiv.style.display = 'block';
					}
				}
			})
			.catch(error => {
				console.error('Error fetching payments:', error);
				paymentsTableBody.innerHTML = '<tr><td class="not-found" colspan="7" class="text-danger">Terjadi kesalahan saat memuat data pembayaran.</td></tr>';
				if (paginationControlsDiv) {
					paginationControlsDiv.style.display = 'none';
					paginationControlsDiv.querySelectorAll('.page-link, #limitPerPage').forEach(el => el.removeAttribute('disabled'));
				}
			});
		}		
		// Event listener for Reset Filter button
			if (resetFilterBtn) { // Check if the button exists
				resetFilterBtn.addEventListener('click', function() {
					searchInput.value = ''; // Clear general search input
					startDateInput.value = ''; // Clear start date input
					endDateInput.value = ''; // Clear end date input
					
					// Reload payments with no search/date parameters (resetting to default page 1)
					loadPayments(1, '', <?php echo $limit_per_page; ?>, '', '');
					updateExportLink(); // Update export link after reset
				});
			}     
        function attachEventListeners() {
            // Re-attach listeners for pagination links
            document.querySelectorAll('#paginationControls .page-link').forEach(link => {
                link.removeEventListener('click', handlePageClick); // Remove existing listener to prevent duplicates before adding
                link.addEventListener('click', handlePageClick);
            });

            // Re-attach listener for limit per page select
            const currentLimitSelect = paginationControlsDiv.querySelector('#limitPerPage');
            if (currentLimitSelect) { // Check if element exists before attaching listener
                currentLimitSelect.removeEventListener('change', handleLimitChange);
                currentLimitSelect.addEventListener('change', handleLimitChange);
            }
        }		
	function handleResetFilterClick() {
			searchInput.value = ''; // Clear general search input
			startDateInput.value = ''; // Clear start date input
			endDateInput.value = ''; // Clear end date input
			
			// Reload payments with no search/date parameters (resetting to default page 1)
			loadPayments(1, '', <?php echo $limit_per_page; ?>, '', '');
			updateExportLink(); // Update export link after reset
		}     
        function handlePageClick(e) {
			e.preventDefault();
			const newPage = parseInt(this.dataset.page);
			const currentSearchQuery = searchInput.value;
			const currentLimit = parseInt(paginationControlsDiv.querySelector('#limitPerPage').value);
			const currentStartDate = startDateInput.value; // NEW
			const currentEndDate = endDateInput.value; // NEW

			if (!isNaN(newPage) && newPage > 0) {
				loadPayments(newPage, currentSearchQuery, currentLimit, currentStartDate, currentEndDate); // MODIFIED
			}
		}    
        function handleLimitChange() {
			const newLimit = parseInt(this.value);
			const currentSearchQuery = searchInput.value;
			const currentStartDate = startDateInput.value; // NEW
			const currentEndDate = endDateInput.value; // NEW
			loadPayments(1, currentSearchQuery, newLimit, currentStartDate, currentEndDate); // MODIFIED
		}

        // Event listener for search input with debounce to limit AJAX requests
        searchInput.addEventListener('input', function() {
			clearTimeout(searchTimeout);
			searchTimeout = setTimeout(() => {
				const currentSearchQuery = this.value;
				const currentLimit = paginationControlsDiv ? parseInt(paginationControlsDiv.querySelector('#limitPerPage').value) : <?php echo $limit_per_page; ?>;
				// When searching, always reset to the first page (page 1)
				loadPayments(1, currentSearchQuery, currentLimit, startDateInput.value, endDateInput.value); // MODIFIED
				updateExportLink(); // Ensure export link is updated on search
			}, 300); // 300ms debounce delay
		});

        // --- NEW ADDITION FOR INITIAL LOAD ---
		if (!window.location.search.includes('page=') && !window.location.search.includes('search=') && !window.location.search.includes('limit=') && !window.location.search.includes('start_date=') && !window.location.search.includes('end_date=')) { // MODIFIED
			loadPayments(1, '', <?php echo $limit_per_page; ?>, '', ''); // MODIFIED: Pass empty strings for dates
		} else {
			attachEventListeners();
			if (totalPaymentsCountSpan) {
				totalPaymentsCountSpan.textContent = <?php echo json_encode($total_payments); ?>;
			}
			// Call updateExportLink on initial load to ensure it's correct if dates are in URL
			updateExportLink(); // NEW
		}
        // --- END NEW ADDITION ---
	// Event listeners for date inputs
		startDateInput.addEventListener('change', function() { // Use 'change' for date inputs
			const currentSearchQuery = searchInput.value;
			const currentLimit = paginationControlsDiv ? parseInt(paginationControlsDiv.querySelector('#limitPerPage').value) : <?php echo $limit_per_page; ?>;
			loadPayments(1, currentSearchQuery, currentLimit, this.value, endDateInput.value); // Reset to page 1
			updateExportLink(); // Update export link
		});

		endDateInput.addEventListener('change', function() { // Use 'change' for date inputs
			const currentSearchQuery = searchInput.value;
			const currentLimit = paginationControlsDiv ? parseInt(paginationControlsDiv.querySelector('#limitPerPage').value) : <?php echo $limit_per_page; ?>;
			loadPayments(1, currentSearchQuery, currentLimit, startDateInput.value, this.value); // Reset to page 1
			updateExportLink(); // Update export link
		});  
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
