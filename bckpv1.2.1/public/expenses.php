<?php
// public/expenses.php

require_once '../includes/db_connect.php';
require_once '../includes/session.php'; // Ini harus di atas untuk mengaktifkan session
require_once '../includes/functions.php';
require_once '../includes/header.php';

// Pastikan user sudah login dan memiliki peran yang sesuai
check_login();
check_role(['superadmin', 'admin', 'finance']); // Sesuaikan peran yang diizinkan

// --- AMBIL PESAN DARI SESSION (INI PENTING UNTUK NOTIFIKASI) ---
$error_message = $_SESSION['error_message'] ?? '';
$success_message = $_SESSION['success_message'] ?? '';
unset($_SESSION['error_message']); // Hapus setelah dibaca
unset($_SESSION['success_message']); // Hapus setelah dibaca

// --- Logika Hapus Pengeluaran ---
if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id'])) {
    if (isset($_SESSION['user_id'])) {
        $expense_id_to_delete = $_GET['id'];

        // Mulai transaksi
        $conn->begin_transaction();
        try {
            $stmt_delete = $conn->prepare("DELETE FROM expenses WHERE id = ?");
            if ($stmt_delete) {
                $stmt_delete->bind_param("i", $expense_id_to_delete);
                if ($stmt_delete->execute()) {
                    $conn->commit(); // Commit transaksi jika berhasil
                    $_SESSION['success_message'] = "Pengeluaran berhasil dihapus.";
                } else {
                    throw new Exception("Gagal menghapus pengeluaran: " . $stmt_delete->error);
                }
                $stmt_delete->close();
            } else {
                throw new Exception("Gagal menyiapkan statement delete: " . $conn->error);
            }
        } catch (Exception $e) {
            $conn->rollback(); // Rollback jika ada kesalahan
            $_SESSION['error_message'] = $e->getMessage();
        }
    } else {
        $_SESSION['error_message'] = "Anda harus login untuk menghapus pengeluaran.";
    }

    // Redirect kembali ke halaman dengan filter yang sama
    $redirect_params = http_build_query(array_diff_key($_GET, ['action' => '', 'id' => '']));
    header("Location: expenses.php?" . $redirect_params);
    exit(); // Penting: Hentikan eksekusi setelah redirect
}

// --- Parameter Filter, Pencarian, Sorting, dan Paginasi ---
$selected_month = $_GET['month'] ?? date('m');
$selected_year = $_GET['year'] ?? date('Y');
$search_query = $_GET['search'] ?? '';

// Default sorting
$sort_by = $_GET['sort_by'] ?? 'expense_date';
$sort_order = $_GET['sort_order'] ?? 'DESC';

// Validasi sort_by untuk mencegah SQL Injection
$allowed_sort_by = ['expense_date', 'description', 'amount', 'input_by_username', 'id'];
if (!in_array($sort_by, $allowed_sort_by)) {
    $sort_by = 'expense_date';
}

// Validasi sort_order
$sort_order = strtoupper($sort_order);
if (!in_array($sort_order, ['ASC', 'DESC'])) {
    $sort_order = 'DESC';
}

// Pengaturan paginasi
$current_page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$limit_per_page_options = [10, 25, 50, 100, 250, 500, 1000];
$limit_per_page = isset($_GET['limit']) && in_array((int)$_GET['limit'], $limit_per_page_options) ? (int)$_GET['limit'] : 10;

$total_expenses = 0; // Jumlah pengeluaran yang difilter
$expenses = [];      // Data pengeluaran yang difilter dan dipaginasi
$total_expenses_global = 0; // Total pengeluaran tanpa filter (untuk display statistik)

// AWAL QUERY SQL UTAMA DAN COUNT
$base_sql = "
    SELECT
        e.id,
        e.expense_date,
        e.description,
        e.amount,
        u.username AS input_by_username
    FROM
        expenses e
    JOIN
        users u ON e.input_by_user_id = u.id
    WHERE 1=1
";

$count_sql = "
    SELECT COUNT(e.id) AS total_rows
    FROM expenses e
    JOIN users u ON e.input_by_user_id = u.id
    WHERE 1=1
";

$params = [];
$param_types = "";

// Tambahkan kondisi filter bulan/tahun
if (!empty($selected_month) && !empty($selected_year)) {
    $base_sql .= " AND DATE_FORMAT(e.expense_date, '%Y-%m') = ?";
    $count_sql .= " AND DATE_FORMAT(e.expense_date, '%Y-%m') = ?";
    $params[] = $selected_year . '-' . $selected_month;
    $param_types .= "s";
}

// Tambahkan kondisi pencarian
if (!empty($search_query)) {
    $base_sql .= " AND (e.description LIKE ? OR u.username LIKE ?)";
    $count_sql .= " AND (e.description LIKE ? OR u.username LIKE ?)";
    $search_param = '%' . $search_query . '%';
    $params[] = $search_param;
    $params[] = $search_param;
    $param_types .= "ss";
}

// Eksekusi COUNT query
$stmt_count = $conn->prepare($count_sql);
if ($stmt_count) {
    if (!empty($params) && !empty($param_types)) {
        $stmt_count->bind_param($param_types, ...$params);
    }
    $stmt_count->execute();
    $result_count = $stmt_count->get_result();
    $row_count = $result_count->fetch_assoc();
    $total_expenses = (int)($row_count['total_rows'] ?? 0);
    $stmt_count->close();
} else {
    error_log("Gagal menghitung total baris expenses: " . $conn->error);
    $total_expenses = 0;
}

$total_pages = ceil($total_expenses / $limit_per_page);
if ($total_expenses > 0 && $current_page > $total_pages) {
    $current_page = $total_pages;
}
if ($total_expenses == 0) {
    $current_page = 1;
}
$offset = ($current_page - 1) * $limit_per_page;
if ($offset < 0) $offset = 0;

$base_sql .= " ORDER BY " . $sort_by . " " . $sort_order . " LIMIT ? OFFSET ?";
$params_for_data = array_merge($params, [$limit_per_page, $offset]);
$param_types_for_data = $param_types . "ii";

$stmt = $conn->prepare($base_sql);
if ($stmt) {
    if (!empty($params_for_data) && !empty($param_types_for_data)) {
        $stmt->bind_param($param_types_for_data, ...$params_for_data);
    }
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $expenses[] = $row;
        }
    }
    $stmt->close();
} else {
    error_log("Gagal menyiapkan query expenses: " . $conn->error);
}

// Query untuk Total Pengeluaran Global (tanpa filter, untuk display statistik)
$global_expense_sql = "SELECT SUM(amount) AS total FROM expenses";
$stmt_global_expense = $conn->prepare($global_expense_sql);
if ($stmt_global_expense) {
    $stmt_global_expense->execute();
    $result_global_expense = $stmt_global_expense->get_result();
    $row_global_expense = $result_global_expense->fetch_assoc();
    $total_expenses_global = (int)($row_global_expense['total'] ?? 0);
    $stmt_global_expense->close();
} else {
    error_log("Error preparing global expense statement: " . $conn->error);
}

// Set judul halaman untuk header.php
$title = "Daftar Pengeluaran";

// Include header (yang juga akan meng-include sidebar dan HTML pembuka)
// Pastikan header.php Anda memiliki logika untuk menampilkan $success_message dan $error_message
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
        <header class="mb-4 d-flex justify-content-between align-items-center">
            <h1 class="display-5 mb-0">Daftar Pengeluaran</h1>
            <a href="add_expense.php" class="btn btn-danger">Tambah Pengeluaran Baru</a>
        </header>

        <?php if ($success_message): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($success_message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        <?php if ($error_message): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($error_message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        <div class="card mb-4">
            <div class="card-body">
                <form action="expenses.php" method="GET" class="row mb-3 align-items-end">
                    <div class="col-md-3">
                        <label for="monthSelect" class="form-label">Bulan</label>
                        <select class="form-select" id="monthSelect" name="month" onchange="this.form.submit()">
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
                        <select class="form-select" id="yearSelect" name="year" onchange="this.form.submit()">
                            <?php
                            $current_year_option = (int)date('Y');
                            for ($y = $current_year_option; $y >= $current_year_option - 4; $y--) {
                                echo "<option value=\"$y\"" . ($selected_year == $y ? ' selected' : '') . ">$y</option>";
                            }
                            ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label for="searchInput" class="form-label">Cari Deskripsi / User</label>
                        <input type="text" id="searchInput" name="search" class="form-control" placeholder="Cari deskripsi atau user..." value="<?php echo htmlspecialchars($search_query); ?>">
                    </div>
                    <div class="col-md-auto">
                        <button type="submit" class="btn btn-primary">Filter / Cari</button>
                        <a href="expenses.php" class="btn btn-secondary">Reset</a>
                    </div>
                </form>
                <div class="row mt-3">
                    <div class="col-12 text-end">
                        <p class="h4 text-primary mb-0">Total Pengeluaran Global: Rp<?php echo number_format($total_expenses_global, 0, ',', '.'); ?></p>
                    </div>
                </div>
            </div>
        </div>

        <div class="table-responsive">
            <table id="myTable" class="table table-striped table-hover">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>
                            <a href="?month=<?php echo htmlspecialchars($selected_month); ?>&year=<?php echo htmlspecialchars($selected_year); ?>&search=<?php echo htmlspecialchars($search_query); ?>&page=<?php echo htmlspecialchars($current_page); ?>&limit=<?php echo htmlspecialchars($limit_per_page); ?>&sort_by=expense_date&sort_order=<?php echo ($sort_by == 'expense_date' && $sort_order == 'ASC') ? 'DESC' : 'ASC'; ?>">
                                Tanggal
                                <?php if ($sort_by == 'expense_date'): ?><i class="bi bi-arrow-<?php echo ($sort_order == 'ASC') ? 'up' : 'down'; ?>"></i><?php endif; ?>
                            </a>
                        </th>
                        <th>
                            <a href="?month=<?php echo htmlspecialchars($selected_month); ?>&year=<?php echo htmlspecialchars($selected_year); ?>&search=<?php echo htmlspecialchars($search_query); ?>&page=<?php echo htmlspecialchars($current_page); ?>&limit=<?php echo htmlspecialchars($limit_per_page); ?>&sort_by=description&sort_order=<?php echo ($sort_by == 'description' && $sort_order == 'ASC') ? 'DESC' : 'ASC'; ?>">
                                Deskripsi
                                <?php if ($sort_by == 'description'): ?><i class="bi bi-arrow-<?php echo ($sort_order == 'ASC') ? 'up' : 'down'; ?>"></i><?php endif; ?>
                            </a>
                        </th>

                        <th>
                            <a href="?month=<?php echo htmlspecialchars($selected_month); ?>&year=<?php echo htmlspecialchars($selected_year); ?>&search=<?php echo htmlspecialchars($search_query); ?>&page=<?php echo htmlspecialchars($current_page); ?>&limit=<?php echo htmlspecialchars($limit_per_page); ?>&sort_by=amount&sort_order=<?php echo ($sort_by == 'amount' && $sort_order == 'ASC') ? 'DESC' : 'ASC'; ?>">
                                Jumlah
                                <?php if ($sort_by == 'amount'): ?><i class="bi bi-arrow-<?php echo ($sort_order == 'ASC') ? 'up' : 'down'; ?>"></i><?php endif; ?>
                            </a>
                        </th>
                        <th>
                            <a href="?month=<?php echo htmlspecialchars($selected_month); ?>&year=<?php echo htmlspecialchars($selected_year); ?>&search=<?php echo htmlspecialchars($search_query); ?>&page=<?php echo htmlspecialchars($current_page); ?>&limit=<?php echo htmlspecialchars($limit_per_page); ?>&sort_by=input_by_username&sort_order=<?php echo ($sort_by == 'input_by_username' && $sort_order == 'ASC') ? 'DESC' : 'ASC'; ?>">
                                Dicatat Oleh
                                <?php if ($sort_by == 'input_by_username'): ?><i class="bi bi-arrow-<?php echo ($sort_order == 'ASC') ? 'up' : 'down'; ?>"></i><?php endif; ?>
                            </a>
                        </th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($expenses)): ?>
                        <?php foreach ($expenses as $expense): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($expense['id']); ?></td>
                                <td><?php echo htmlspecialchars(date('d-m-Y', strtotime($expense['expense_date']))); ?></td>
                                <td><?php echo htmlspecialchars($expense['description']); ?></td>
                                <td>Rp<?php echo number_format($expense['amount'], 2, ',', '.'); ?></td>
                                <td><?php echo htmlspecialchars($expense['input_by_username']); ?></td>
                                <td class="text-end">
                                    <a href="edit_expense.php?id=<?php echo htmlspecialchars($expense['id']); ?>" class="btn btn-sm btn-info" title="Edit Pengeluaran"><i class="bi bi-pencil-fill"></i></a>
                                    <a href="expenses.php?action=delete&id=<?php echo htmlspecialchars($expense['id']); ?>&month=<?php echo htmlspecialchars($selected_month); ?>&year=<?php echo htmlspecialchars($selected_year); ?>&page=<?php echo htmlspecialchars($current_page); ?>&limit=<?php echo htmlspecialchars($limit_per_page); ?>&search=<?php echo htmlspecialchars($search_query); ?>&sort_by=<?php echo htmlspecialchars($sort_by); ?>&sort_order=<?php echo htmlspecialchars($sort_order); ?>" class="btn btn-sm btn-danger" onclick="return confirm('Apakah Anda yakin ingin menghapus pengeluaran ini?');" title="Hapus Pengeluaran"><i class="bi bi-trash-fill"></i></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6">
                                <div class="alert alert-info mb-0" role="alert">
                                    Tidak ada catatan pengeluaran yang ditemukan dengan kriteria tersebut.
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
                        <a class="page-link" href="?page=<?php echo $current_page - 1; ?>&month=<?php echo htmlspecialchars($selected_month); ?>&year=<?php echo htmlspecialchars($selected_year); ?>&search=<?php echo htmlspecialchars($search_query); ?>&limit=<?php echo htmlspecialchars($limit_per_page); ?>&sort_by=<?php echo htmlspecialchars($sort_by); ?>&sort_order=<?php echo htmlspecialchars($sort_order); ?>">Previous</a>
                    </li>
                    <?php
                    $start_page = max(1, $current_page - 2);
                    $end_page = min($total_pages, $current_page + 2);

                    if ($start_page > 1 && $end_page < $total_pages) {
                        if ($current_page - $start_page < 2) {
                            $end_page = min($total_pages, $end_page + (2 - ($current_page - $start_page)));
                        }
                        if ($end_page - $current_page < 2) {
                            $start_page = max(1, $start_page - (2 - ($end_page - $current_page)));
                        }
                    }

                    if ($start_page > 1) {
                        echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                    }

                    for ($i = $start_page; $i <= $end_page; $i++): ?>
                        <li class="page-item <?php echo ($i == $current_page) ? 'active' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $i; ?>&month=<?php echo htmlspecialchars($selected_month); ?>&year=<?php echo htmlspecialchars($selected_year); ?>&search=<?php echo htmlspecialchars($search_query); ?>&limit=<?php echo htmlspecialchars($limit_per_page); ?>&sort_by=<?php echo htmlspecialchars($sort_by); ?>&sort_order=<?php echo htmlspecialchars($sort_order); ?>"><?php echo $i; ?></a>
                        </li>
                    <?php endfor;

                    if ($end_page < $total_pages) {
                        echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                    }
                    ?>
                    <li class="page-item <?php echo ($current_page >= $total_pages) ? 'disabled' : ''; ?>">
                        <a class="page-link" href="?page=<?php echo $current_page + 1; ?>&month=<?php echo htmlspecialchars($selected_month); ?>&year=<?php echo htmlspecialchars($selected_year); ?>&search=<?php echo htmlspecialchars($search_query); ?>&limit=<?php echo htmlspecialchars($limit_per_page); ?>&sort_by=<?php echo htmlspecialchars($sort_by); ?>&sort_order=<?php echo htmlspecialchars($sort_order); ?>">Next</a>
                    </li>
                </ul>
            </nav>
            <div class="d-flex justify-content-end align-items-center mt-2">
                <label for="limitPerPage" class="form-label me-2 mb-0">Tampilkan:</label>
                <select class="form-select form-select-sm w-auto" id="limitPerPage" onchange="window.location.href = `?page=1&limit=${this.value}&month=<?php echo htmlspecialchars($selected_month); ?>&year=<?php echo htmlspecialchars($selected_year); ?>&search=<?php echo htmlspecialchars($search_query); ?>&sort_by=<?php echo htmlspecialchars($sort_by); ?>&sort_order=<?php echo htmlspecialchars($sort_order); ?>`">
                    <?php foreach ($limit_per_page_options as $option): ?>
                        <option value="<?php echo $option; ?>" <?php echo ($option == $limit_per_page) ? 'selected' : ''; ?>>
                            <?php echo $option; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <span class="ms-2 text-muted">dari <?php echo $total_expenses; ?> data</span>
            </div>
        </div>
    </div>
</div>

<?php
// Include footer
include_once '../includes/footer.php';
?>