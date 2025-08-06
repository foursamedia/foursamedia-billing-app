<?php
// public/index.php
require_once '../includes/db_connect.php';
require_once '../includes/session.php';
require_once '../includes/functions.php';

// Pastikan pengguna sudah login
check_login();

// Ambil peran pengguna dari sesi
$current_role = $_SESSION['role_name'] ?? '';
$user_id_in_session = $_SESSION['user_id'] ?? 0;

// --- REDIREKSI BERDASARKAN PERAN ---
if ($current_role === 'teknisi') {
    header("Location: customers.php");
    exit();
} elseif ($current_role === 'pelanggan') {
    header("Location: customer_details.php?id=" . htmlspecialchars($user_id_in_session));
    exit();
}
// --- AKHIR REDIREKSI ---

$title = "Dashboard Admin/Superadmin";

// --- Variabel yang mungkin masih dihitung untuk chart atau keperluan lain ---
$total_users = 0;
$total_payments_this_month = 0;
$total_new_users_this_month = 0;
$total_expenses_this_month = 0;
$total_expenses_today = 0;
$total_all_expenses = 0;

// Logika untuk menghitung total user (digunakan oleh logika tunggakan)
$stmt_users_count = $conn->prepare("SELECT COUNT(id) FROM users");
if ($stmt_users_count) {
    $stmt_users_count->execute();
    $stmt_users_count->bind_result($total_users);
    $stmt_users_count->fetch();
    $stmt_users_count->close();
}

// Logika untuk menghitung total pembayaran bulan ini (digunakan oleh chart pemasukan)
$first_day_of_month = date('Y-m-01');
$last_day_of_month = date('Y-m-t');

$stmt_payments_month = $conn->prepare("SELECT SUM(amount) FROM payments WHERE payment_date BETWEEN ? AND ?");
if ($stmt_payments_month) {
    $stmt_payments_month->bind_param("ss", $first_day_of_month, $last_day_of_month);
    $stmt_payments_month->execute();
    $stmt_payments_month->bind_result($total_payments_this_month);
    $stmt_payments_month->fetch();
    $stmt_payments_month->close();
}

// Logika untuk menghitung pengguna baru bulan ini (digunakan oleh chart pelanggan baru)
$stmt_new_users_month = $conn->prepare("SELECT COUNT(id) FROM users WHERE created_at BETWEEN ? AND ? AND role_id = (SELECT id FROM roles WHERE role_name = 'pelanggan')");
if ($stmt_new_users_month) {
    $stmt_new_users_month->bind_param("ss", $first_day_of_month, $last_day_of_month);
    $stmt_new_users_month->execute();
    $stmt_new_users_month->bind_result($total_new_users_this_month);
    $stmt_new_users_month->fetch();
    $stmt_new_users_month->close();
}

// --- LOGIKA PELANGGAN DENGAN TANGGUNGAN LEBIH DARI 3 BULAN ---
$customers_with_long_outstanding = [];
$current_date_dt = new DateTime();
$previous_month_end = new DateTime(date('Y-m-t', strtotime('last month')));

$all_users_full_data = [];
$stmt_get_users = $conn->prepare("SELECT id, username, name, address, created_at, role_id, paket FROM users WHERE role_id = (SELECT id FROM roles WHERE role_name = 'pelanggan')");
if ($stmt_get_users) {
    $stmt_get_users->execute();
    $result_get_users = $stmt_get_users->get_result();
    while ($user_row = $result_get_users->fetch_assoc()) {
        $all_users_full_data[$user_row['id']] = $user_row;
    }
    $stmt_get_users->close();
}

$all_payments_grouped_by_user = [];
$stmt_all_payments = $conn->prepare("SELECT user_id, payment_date FROM payments ORDER BY user_id, payment_date");
if ($stmt_all_payments) {
    $stmt_all_payments->execute();
    $result_all_payments = $stmt_all_payments->get_result();
    while ($payment_row = $result_all_payments->fetch_assoc()) {
        $all_payments_grouped_by_user[$payment_row['user_id']][] = $payment_row['payment_date'];
    }
    $stmt_all_payments->close();
}

foreach ($all_users_full_data as $user_id => $user_details) {
    $customer_start_date_str = $user_details['created_at'];
    $customer_start_datetime = null;
    try {
        $customer_start_datetime = new DateTime($customer_start_date_str);
    } catch (Exception $e) {
        $customer_start_datetime = new DateTime(date('Y-01-01')); // Fallback jika created_at invalid
    }

    $period_start_check = new DateTime($customer_start_datetime->format('Y-m-01'));

    if ($period_start_check > $previous_month_end) {
        continue;
    }

    $payments_for_user_mapped = [];
    if (isset($all_payments_grouped_by_user[$user_id])) {
        foreach ($all_payments_grouped_by_user[$user_id] as $payment_date_str) {
            $payments_for_user_mapped[(new DateTime($payment_date_str))->format('Y-m')] = true;
        }
    }

    $consecutive_unpaid_months = 0;
    $has_long_outstanding = false;

    $current_month_iter = clone $period_start_check;
    while ($current_month_iter <= $previous_month_end) {
        $month_key = $current_month_iter->format('Y-m');

        if (!isset($payments_for_user_mapped[$month_key])) {
            $consecutive_unpaid_months++;
            if ($consecutive_unpaid_months >= 3) {
                $has_long_outstanding = true;
                break;
            }
        } else {
            $consecutive_unpaid_months = 0;
        }
        $current_month_iter->modify('+1 month');
    }

    if ($has_long_outstanding) {
        $customers_with_long_outstanding[] = $user_details;
    }
}
$count_long_outstanding = count($customers_with_long_outstanding);
// --- AKHIR LOGIKA TANGGUNGAN ---

// --- LOGIKA UNTUK PENGELUARAN (untuk chart) ---
$current_year_month = date('Y-m');
$current_date_str = date('Y-m-d');

$stmt_month_expenses = $conn->prepare("SELECT SUM(amount) AS total_amount FROM expenses WHERE DATE_FORMAT(expense_date, '%Y-%m') = ?");
if ($stmt_month_expenses) {
    $stmt_month_expenses->bind_param("s", $current_year_month);
    $stmt_month_expenses->execute();
    $result_month_expenses = $stmt_month_expenses->get_result();
    if ($row = $result_month_expenses->fetch_assoc()) {
        $total_expenses_this_month = $row['total_amount'] ?? 0;
    }
    $stmt_month_expenses->close();
}

$stmt_today_expenses = $conn->prepare("SELECT SUM(amount) AS total_amount FROM expenses WHERE expense_date = ?");
if ($stmt_today_expenses) {
    $stmt_today_expenses->bind_param("s", $current_date_str);
    $stmt_today_expenses->execute();
    $result_today_expenses = $stmt_today_expenses->get_result();
    if ($row = $result_today_expenses->fetch_assoc()) {
        $total_expenses_today = $row['total_amount'] ?? 0;
    }
    $stmt_today_expenses->close();
}

$result_all_expenses = $conn->query("SELECT SUM(amount) AS total_amount FROM expenses");
if ($result_all_expenses && $row = $result_all_expenses->fetch_assoc()) {
    $total_all_expenses = $row['total_amount'] ?? 0;
}
// --- AKHIR LOGIKA PENGELUARAN ---


// --- LOGIKA BARU UNTUK CHART PENDAPATAN MITRA BERDASARKAN BULAN (TAHUN SAAT INI) ---
$mitra_revenue_monthly_amounts = [];
$mitra_revenue_month_labels = [];
$current_year = date('Y');
$current_month_num = date('n');

$mitra_role_id = 0;
$stmt_get_mitra_role_id = $conn->prepare("SELECT id FROM roles WHERE role_name = 'mitra'");
if ($stmt_get_mitra_role_id) {
    $stmt_get_mitra_role_id->execute();
    $stmt_get_mitra_role_id->bind_result($mitra_role_id);
    $stmt_get_mitra_role_id->fetch();
    $stmt_get_mitra_role_id->close();
}

if ($mitra_role_id > 0) {
    for ($month = 1; $month <= $current_month_num; $month++) {
        $month_padded = str_pad($month, 2, '0', STR_PAD_LEFT);
        $date = new DateTime("$current_year-$month_padded-01");
        $month_label = $date->format('M Y');
        $year_month_start = $date->format('Y-m-01');
        $year_month_end = $date->format('Y-m-t');

        $mitra_revenue_month_labels[] = $month_label;
        $mitra_revenue_this_month = 0;
        $stmt_mitra_revenue = $conn->prepare("
            SELECT SUM(p.amount) AS total_revenue
            FROM payments p
            JOIN users u ON p.user_id = u.id
            WHERE p.input_record_date BETWEEN ? AND ?
            AND u.role_id = ?
        ");
        if ($stmt_mitra_revenue) {
            $stmt_mitra_revenue->bind_param("ssi", $year_month_start, $year_month_end, $mitra_role_id);
            $stmt_mitra_revenue->execute();
            $result_mitra_revenue = $stmt_mitra_revenue->get_result();
            if ($row = $result_mitra_revenue->fetch_assoc()) {
                $mitra_revenue_this_month = $row['total_revenue'] ?? 0;
            }
            $stmt_mitra_revenue->close();
        }
        $mitra_revenue_monthly_amounts[] = (int)$mitra_revenue_this_month;
    }
}
// --- AKHIR LOGIKA CHART PENDAPATAN MITRA ---


// --- LOGIKA BARU UNTUK MENGHITUNG PELANGGAN BERDASARKAN STATUS PEMBAYARAN ---
$active_customers = 0;
$inactive_customers = 0;
$unpaid_customers = 0;

$pelanggan_role_id = 0;
$stmt_get_pelanggan_role_id = $conn->prepare("SELECT id FROM roles WHERE role_name = 'pelanggan'");
if ($stmt_get_pelanggan_role_id) {
    $stmt_get_pelanggan_role_id->execute();
    $stmt_get_pelanggan_role_id->bind_result($pelanggan_role_id);
    $stmt_get_pelanggan_role_id->fetch();
    $stmt_get_pelanggan_role_id->close();
}

if ($pelanggan_role_id > 0) {
    // Tanggal 30 hari yang lalu
    $thirty_days_ago = date('Y-m-d H:i:s', strtotime('-30 days'));

    // 1. Menghitung pelanggan aktif (pembayaran dalam 30 hari terakhir)
    $stmt_active = $conn->prepare("
        SELECT COUNT(DISTINCT p.user_id) AS active_count
        FROM payments p
        JOIN users u ON p.user_id = u.id
        WHERE p.payment_date >= ?
        AND u.role_id = ?
    ");
    if ($stmt_active) {
        $stmt_active->bind_param("si", $thirty_days_ago, $pelanggan_role_id);
        $stmt_active->execute();
        $result_active = $stmt_active->get_result();
        $row_active = $result_active->fetch_assoc();
        $active_customers = $row_active['active_count'] ?? 0;
        $stmt_active->close();
    }

    // 2. Menghitung pelanggan yang belum pernah bayar
    $stmt_unpaid = $conn->prepare("
        SELECT COUNT(u.id) AS unpaid_count
        FROM users u
        LEFT JOIN payments p ON u.id = p.user_id
        WHERE u.role_id = ? AND p.id IS NULL
    ");
    if ($stmt_unpaid) {
        $stmt_unpaid->bind_param("i", $pelanggan_role_id);
        $stmt_unpaid->execute();
        $result_unpaid = $stmt_unpaid->get_result();
        $row_unpaid = $result_unpaid->fetch_assoc();
        $unpaid_customers = $row_unpaid['unpaid_count'] ?? 0;
        $stmt_unpaid->close();
    }

    // 3. Menghitung pelanggan total untuk kalkulasi pelanggan tidak aktif
    $total_customers_by_role = 0;
    $stmt_total = $conn->prepare("SELECT COUNT(id) AS total_count FROM users WHERE role_id = ?");
    if ($stmt_total) {
        $stmt_total->bind_param("i", $pelanggan_role_id);
        $stmt_total->execute();
        $result_total = $stmt_total->get_result();
        $row_total = $result_total->fetch_assoc();
        $total_customers_by_role = $row_total['total_count'] ?? 0;
        $stmt_total->close();
    }

    // Kalkulasi pelanggan tidak aktif
    $inactive_customers = $total_customers_by_role - $active_customers - $unpaid_customers;
}
// --- AKHIR LOGIKA STATUS PELANGGAN ---


// --- LOGIKA HITUNG PELANGGAN BERDASARKAN PAKET (untuk chart) ---
$users_by_package = [];
$package_labels = [];
$package_data_counts = [];

$stmt_users_by_package = $conn->prepare("
    SELECT paket, COUNT(id) AS user_count
    FROM users
    WHERE role_id = (SELECT id FROM roles WHERE role_name = 'pelanggan')
    GROUP BY paket
    ORDER BY paket
");
if ($stmt_users_by_package) {
    $stmt_users_by_package->execute();
    $result_users_by_package = $stmt_users_by_package->get_result();
    while ($row = $result_users_by_package->fetch_assoc()) {
        $users_by_package[] = $row;
        $package_labels[] = $row['paket'];
        $package_data_counts[] = $row['user_count'];
    }
    $stmt_users_by_package->close();
}
// --- AKHIR LOGIKA HITUNG PELANGGAN BERDASARKAN PAKET ---


// --- LOGIKA UNTUK CHART PEMASUKAN DAN PENGELUARAN TIAP BULAN (TAHUN SAAT INI) ---
$monthly_incomes = [];
$monthly_expenses = [];
$month_labels = [];

$current_year = date('Y');
$current_month_num = date('n');

for ($month = 1; $month <= $current_month_num; $month++) {
    $month_padded = str_pad($month, 2, '0', STR_PAD_LEFT);
    $date = new DateTime("$current_year-$month_padded-01");

    $month_label = $date->format('M Y');
    $year_month = $date->format('Y-m');
    $month_labels[] = $month_label;

    $income_amount = 0;
    $stmt_income_chart = $conn->prepare("SELECT SUM(amount) AS total_amount FROM incomes WHERE DATE_FORMAT(income_date, '%Y-%m') = ?");
    if ($stmt_income_chart) {
        $stmt_income_chart->bind_param("s", $year_month);
        $stmt_income_chart->execute();
        $result_income_chart = $stmt_income_chart->get_result();
        if ($row = $result_income_chart->fetch_assoc()) {
            $income_amount = $row['total_amount'] ?? 0;
        }
        $stmt_income_chart->close();
    }
    $monthly_incomes[] = (float)$income_amount;

    $expense_amount = 0;
    $stmt_expense_chart = $conn->prepare("SELECT SUM(amount) AS total_amount FROM expenses WHERE DATE_FORMAT(expense_date, '%Y-%m') = ?");
    if ($stmt_expense_chart) {
        $stmt_expense_chart->bind_param("s", $year_month);
        $stmt_expense_chart->execute();
        $result_expense_chart = $stmt_expense_chart->get_result();
        if ($row = $result_expense_chart->fetch_assoc()) {
            $expense_amount = $row['total_amount'] ?? 0;
        }
        $stmt_expense_chart->close();
    }
    $monthly_expenses[] = (float)$expense_amount;
}
// --- AKHIR LOGIKA CHART PEMASUKAN DAN PENGELUARAN TIAP BULAN ---


// --- LOGIKA BARU UNTUK CHART PEMASUKAN BULANAN DARI TEKNISI (TAHUN SAAT INI) ---
$tech_income_labels_monthly = [];
$tech_income_datasets = [];

$current_year = date('Y');
// $current_month_num = date('n'); // Baris ini tidak lagi krusial untuk batas loop 12 bulan

$teknisi_role_id = 0;
$stmt_get_teknisi_role_id = $conn->prepare("SELECT id FROM roles WHERE role_name = 'teknisi'");
if ($stmt_get_teknisi_role_id) {
    $stmt_get_teknisi_role_id->execute();
    $stmt_get_teknisi_role_id->bind_result($teknisi_role_id);
    $stmt_get_teknisi_role_id->fetch();
    $stmt_get_teknisi_role_id->close();
}

if ($teknisi_role_id > 0) {
    $teknisi_data = [];
    $stmt_get_teknisi_users = $conn->prepare("SELECT id, name FROM users WHERE role_id = ?");
    if ($stmt_get_teknisi_users) {
        $stmt_get_teknisi_users->bind_param("i", $teknisi_role_id);
        $stmt_get_teknisi_users->execute();
        $result_teknisi_users = $stmt_get_teknisi_users->get_result();
        while ($row = $result_teknisi_users->fetch_assoc()) {
            $teknisi_data[$row['id']] = htmlspecialchars($row['name']);
        }
        $stmt_get_teknisi_users->close();
    }

    foreach ($teknisi_data as $id => $name) {
        $tech_income_datasets[$id] = [
            'label' => $name,
            'data' => [],
            'borderColor' => 'hsl(' . (mt_rand(0, 360)) . ', 70%, 50%)',
            'backgroundColor' => 'hsla(' . (mt_rand(0, 360)) . ', 70%, 50%, 0.7)',
            'fill' => false,
            'tension' => 0.3
        ];
    }

    // Ubah batas loop dari $current_month_num menjadi 12
    for ($month = 1; $month <= 12; $month++) {
        $month_padded = str_pad($month, 2, '0', STR_PAD_LEFT);
        $date = new DateTime("$current_year-$month_padded-01");
        $month_label = $date->format('M Y');

        $year_month_start = $date->format('Y-m-01');
        $year_month_end = $date->format('Y-m-t');

        $tech_income_labels_monthly[] = $month_label;

        $current_month_tech_incomes = [];
        foreach ($teknisi_data as $teknisi_id => $teknisi_name) {
            $current_month_tech_incomes[$teknisi_id] = 0;
        }

        if (!empty($teknisi_data)) {
            $teknisi_ids_in_clause = implode(',', array_keys($teknisi_data));
            $query_tech_income_per_month = "
                SELECT input_by_user_id, SUM(amount) AS total_amount
                FROM payments
                WHERE input_record_date BETWEEN ? AND ?  -- <<< INI PERUBAHAN UTAMANYA!
                    AND input_by_user_id IN ($teknisi_ids_in_clause)
                GROUP BY input_by_user_id
            ";
            $stmt_tech_income_per_month = $conn->prepare($query_tech_income_per_month);

            if ($stmt_tech_income_per_month) {
                $stmt_tech_income_per_month->bind_param("ss", $year_month_start, $year_month_end);
                $stmt_tech_income_per_month->execute();
                $result_tech_income_per_month = $stmt_tech_income_per_month->get_result();

                while ($row = $result_tech_income_per_month->fetch_assoc()) {
                    $current_month_tech_incomes[$row['input_by_user_id']] = (float)($row['total_amount'] ?? 0);
                }
                $stmt_tech_income_per_month->close();
            } else {
                // Tambahkan penanganan error untuk debug jika prepare gagal
                error_log("Error preparing statement: " . $conn->error);
            }
        }

        foreach ($teknisi_data as $teknisi_id => $teknisi_name) {
            $tech_income_datasets[$teknisi_id]['data'][] = $current_month_tech_incomes[$teknisi_id];
        }
    }
}

$final_tech_chart_datasets = array_values($tech_income_datasets);
// --- AKHIR LOGIKA CHART PEMASUKAN BULANAN DARI TEKNISI ---
// --- LOGIKA BARU UNTUK CHART PELANGGAN BARU BERDASARKAN BULAN INPUT (TAHUN SAAT INI) ---
$new_customers_monthly_counts = [];
$new_customers_month_labels = [];

// Get current year
$current_year = date('Y');
$current_month_num = date('n'); // Bulan saat ini (1-12)

// Get role_id for 'pelanggan'
$pelanggan_role_id = 0;
$stmt_get_pelanggan_role_id = $conn->prepare("SELECT id FROM roles WHERE role_name = 'pelanggan'");
if ($stmt_get_pelanggan_role_id) {
    $stmt_get_pelanggan_role_id->execute();
    $stmt_get_pelanggan_role_id->bind_result($pelanggan_role_id);
    $stmt_get_pelanggan_role_id->fetch();
    $stmt_get_pelanggan_role_id->close();
}

if ($pelanggan_role_id > 0) {
    // Loop dari Januari (bulan 1) hingga bulan saat ini
    for ($month = 1; $month <= $current_month_num; $month++) {
        $month_padded = str_pad($month, 2, '0', STR_PAD_LEFT);
        $date = new DateTime("$current_year-$month_padded-01");

        $month_label = $date->format('M Y');
        $year_month_start = $date->format('Y-m-01');
        $year_month_end = $date->format('Y-m-t');

        $new_customers_month_labels[] = $month_label;

        $new_customer_count_this_month = 0;
        $stmt_new_customers = $conn->prepare("
            SELECT COUNT(id) AS total_new_customers
            FROM users
            WHERE created_at BETWEEN ? AND ?
            AND role_id = ?
        ");
        if ($stmt_new_customers) {
            $stmt_new_customers->bind_param("ssi", $year_month_start, $year_month_end, $pelanggan_role_id);
            $stmt_new_customers->execute();
            $result_new_customers = $stmt_new_customers->get_result();
            if ($row = $result_new_customers->fetch_assoc()) {
                $new_customer_count_this_month = $row['total_new_customers'] ?? 0;
            }
            $stmt_new_customers->close();
        }
        $new_customers_monthly_counts[] = (int)$new_customer_count_this_month;
    }
}
// --- AKHIR LOGIKA CHART PELANGGAN BARU BERDASARKAN BULAN INPUT (TAHUN SAAT INI) ---


// --- LOGIKA UNTUK CHART REKAP PENDAPATAN DARI PELANGGAN (TAHUN SAAT INI) ---
$customer_income_labels = [];
$customer_income_amounts = [];

$current_year = date('Y');
$current_month_num = date('n');

for ($month = 1; $month <= $current_month_num; $month++) {
    $month_padded = str_pad($month, 2, '0', STR_PAD_LEFT);
    $date = new DateTime("$current_year-$month_padded-01");

    $month_label = $date->format('M Y');
    $year_month = $date->format('Y-m');
    $customer_income_labels[] = $month_label;

    $income_from_customers = 0;
    $stmt_customer_income = $conn->prepare("
        SELECT SUM(p.amount) AS total_amount
        FROM payments p
        JOIN users u ON p.user_id = u.id
        JOIN roles r ON u.role_id = r.id
        WHERE r.role_name = 'pelanggan'
        AND DATE_FORMAT(p.payment_date, '%Y-%m') = ?
    ");
    if ($stmt_customer_income) {
        $stmt_customer_income->bind_param("s", $year_month);
        $stmt_customer_income->execute();
        $result_customer_income = $stmt_customer_income->get_result();
        if ($row = $result_customer_income->fetch_assoc()) {
            $income_from_customers = $row['total_amount'] ?? 0;
        }
        $stmt_customer_income->close();
    }
    $customer_income_amounts[] = (float)$income_from_customers;
}
// --- AKHIR LOGIKA CHART REKAP PENDAPATAN DARI PELANGGAN ---

// --- LOGIKA BARU UNTUK CHART TOTAL PEMASUKAN BERDASARKAN TANGGAL INPUT (TAHUN SAAT INI) ---
$total_input_income_labels_monthly = [];
$total_input_income_data = [];

$current_year_for_total_income = date('Y');
$first_day_of_current_year_total_income = new DateTime("$current_year_for_total_income-01-01");
$last_day_of_current_year_total_income = new DateTime("$current_year_for_total_income-12-31");

$sql_total_input_income_per_month = "
    SELECT
        DATE_FORMAT(input_record_date, '%Y-%m') AS month_year,
        SUM(amount) AS total_amount
    FROM payments
    WHERE input_record_date BETWEEN ? AND ?
    GROUP BY month_year
    ORDER BY month_year ASC
";

$stmt_total_input_income = $conn->prepare($sql_total_input_income_per_month);
$fetched_total_input_income = [];

if ($stmt_total_input_income) {
    $stmt_total_input_income->bind_param("ss", $first_day_of_current_year_total_income->format('Y-m-d'), $last_day_of_current_year_total_income->format('Y-m-d'));
    $stmt_total_input_income->execute();
    $result_total_input_income = $stmt_total_input_income->get_result();

    while ($row = $result_total_input_income->fetch_assoc()) {
        $fetched_total_input_income[$row['month_year']] = (float)($row['total_amount'] ?? 0);
    }
    $stmt_total_input_income->close();
}

// Loop untuk mengisi label dan data chart untuk 12 bulan penuh
$iterator_month_for_total_income_chart = clone $first_day_of_current_year_total_income;
$end_of_year_for_total_income_chart_loop = new DateTime("$current_year_for_total_income-12-01");

while ($iterator_month_for_total_income_chart <= $end_of_year_for_total_income_chart_loop) {
    $month_key = $iterator_month_for_total_income_chart->format('Y-m');
    $total_input_income_labels_monthly[] = $iterator_month_for_total_income_chart->format('M Y');
    $total_input_income_data[] = $fetched_total_input_income[$month_key] ?? 0;
    $iterator_month_for_total_income_chart->modify('+1 month');
}

// Final dataset untuk Chart.js (hanya satu dataset)
$final_total_input_income_datasets = [
    [
        'label' => 'Total Pemasukan Berdasarkan Tanggal Input (Rp)',
        'data' => $total_input_income_data,
        'backgroundColor' => 'rgba(153, 102, 255, 0.7)', // Warna ungu
        'borderColor' => 'rgba(153, 102, 255, 1)',
        'borderWidth' => 1
    ]
];
// --- AKHIR LOGIKA CHART TOTAL PEMASUKAN BERDASARKAN TANGGAL INPUT ---


require_once '../includes/header.php';
?>

<div class="d-flex" id="wrapper">

    <!-- Sidebar -->
    <?php
    $sidebar_path = '../includes/sidebar.php';
    if (file_exists($sidebar_path)) {
        include $sidebar_path;
    } else {
        echo "<div style='color: red; padding: 20px;'>Sidebar not found at: " . htmlspecialchars($sidebar_path) . "</div>";
    }
    ?>

    <div id="page-content-wrapper" class="flex-grow-1 mx-3 mx-lg-4 py-lg-4">
        <div class="mb-4 mt-4 mt-lg-0">
            <h1 class="display-5 text-center text-lg-start">Dashboard Admin/Superadmin</h1>
            <p class="lead text-center text-lg-start">Selamat datang, <?php echo htmlspecialchars($_SESSION['username']); ?>!</p>
        </div>


        <div class="row">
            <div class="col-12 mb-4">
                <div class="card bg-danger text-white h-100">
                    <div class="card-body w-100" style="max-width: 100%;">
                        <h5 class="card-title text-center">Detail Pelanggan dengan Tunggakan &ge; 3 Bulan</h5>
                        <?php if ($count_long_outstanding > 0): ?>
                            <div class="table-responsive" style="max-height: 280px; overflow-y: auto; background-color: rgba(255,255,255,0.1);">
                                <table class="table table-light table-striped table-hover table-sm mt-3 mb-0">
                                    <thead>
                                        <tr>
                                            <th scope="col">Nama Pelanggan</th>
                                            <th scope="col">Alamat</th>
                                            <th scope="col">Aksi</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($customers_with_long_outstanding as $customer): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($customer['name']); ?></td>
                                                <td><?php echo htmlspecialchars($customer['address'] ?? '-'); ?></td>
                                                <td>
                                                    <a href='customer_details.php?id=<?php echo htmlspecialchars($customer['id']); ?>' class='btn btn-sm btn-info text-white' title="Lihat Detail">
                                                        <i class="bi bi-info-circle-fill"></i>
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <p class="mt-3 mb-0">Total: **<?php echo $count_long_outstanding; ?>** pelanggan</p>
                        <?php else: ?>
                            <p class="card-text text-center">Tidak ada pelanggan dengan tunggakan panjang saat ini. Kerja bagus!</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
         
		
		
		<!-- === KARTU BARU: STATUS PELANGGAN === -->
		<div class="col-lg-4 col-md-6 mb-4">
			<div class="card text-white bg-success">
				<div class="card-body">
					<h5 class="card-title">Pelanggan Aktif</h5>
					<p class="card-text fs-2"><?php echo number_format($active_customers, 0, ',', '.'); ?></p>
				</div>
			</div>
		</div>
		<div class="col-lg-4 col-md-6 mb-4">
			<div class="card text-white bg-warning">
				<div class="card-body">
					<h5 class="card-title">Pelanggan Tidak Aktif</h5>
					<p class="card-text fs-2"><?php echo number_format($inactive_customers, 0, ',', '.'); ?></p>
				</div>
			</div>
		</div>
		<div class="col-lg-4 col-md-6 mb-4">
			<div class="card text-white bg-danger">
				<div class="card-body">
					<h5 class="card-title">Pelanggan Belum Bayar</h5>
					<p class="card-text fs-2"><?php echo number_format($unpaid_customers, 0, ',', '.'); ?></p>
				</div>
			</div>
		</div>
		<!-- === AKHIR KARTU STATUS PELANGGAN === -->
</div>

        <div class="mt-4 d-flex flex-column flex-md-row gap-4 flex-wrap justify-content-center align-items-center">
            <!-- New Chart for Customer Income -->
            <div class="flex-1 p-0 card-db">
                <div class="card h-100">
                    <div class="card-body d-flex flex-column" style="min-height: 380px;">
                        <h5 class="card-title">Rekap Pendapatan dari Pelanggan (Tahun <?php echo date('Y'); ?>)</h5>
                        <div style="flex-grow: 1; position: relative;">
                            <canvas id="customerIncomeChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
            <!-- End New Chart -->
			 <!-- New Chart for Customer Income -->
            <div class="flex-1 p-0 card-db">
                <div class="card h-100">
                    <div class="card-body d-flex flex-column" style="min-height: 380px;">
                        <h5 class="card-title">Rekap Pendapatan dari Pelanggan (Tahun <?php echo date('Y'); ?>)</h5>
                        <div style="flex-grow: 1; position: relative;">
                            <canvas id="mitraRevenueChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
            <!-- End New Chart -->
            <div class="flex-1 p-0 card-db">
                <div class="card h-100">
                    <div class="card-body d-flex flex-column" style="min-height: 380px;">
                        <h5 class="card-title">Pemasukan Bulanan dari Teknisi (Tahun <?php echo date('Y'); ?>)</h5>
                        <div style="flex-grow: 1; position: relative;">
                            <canvas id="techIncomeChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
            <div class="flex-1 p-0 card-db">
                <div class="card h-100">
                    <div class="card-body d-flex flex-column" style="min-height: 380px;">
                        <h5 class="card-title">Jumlah Pelanggan Berdasarkan Paket</h5>
                        <div style="flex-grow: 1; position: relative;">
                            <canvas id="packageChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
            <div class="flex-1 p-0 card-db">
                <div class="card h-100">
                    <div class="card-body d-flex flex-column" style="min-height: 380px;">
                        <h5 class="card-title">Pemasukan dan Pengeluaran Bulanan (Tahun <?php echo date('Y'); ?>)</h5>
                        <div style="flex-grow: 1; position: relative;">
                            <canvas id="incomeExpenseChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
            <div class="flex-1 p-0 card-db">
                <div class="card h-100">
                    <div class="card-body d-flex flex-column" style="min-height: 380px;">
                        <h5 class="card-title">Pelanggan Baru Berdasarkan Bulan Input (Tahun <?php echo date('Y'); ?>)</h5>
                        <div style="flex-grow: 1; position: relative;">
                            <canvas id="newCustomersChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
<div class="flex-1 p-0 card-db">
    <div class="card h-100">
        <div class="card-body d-flex flex-column" style="min-height: 380px;">
            <h5 class="card-title">Total Pemasukan Berdasarkan Tanggal Input (Tahun <?php echo $current_year_for_total_income; ?>)</h5>
            <div style="flex-grow: 1; position: relative;">
                <canvas id="totalInputIncomeChart"></canvas>
            </div>
        </div>
    </div>
</div>
</div>


    </div>
</div>



<?php
require_once '../includes/footer.php';
?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    // Data untuk Pemasukan dan Pengeluaran (sekarang akan jadi Line Chart)
    const monthLabels = <?php echo json_encode($month_labels); ?>;
    const monthlyIncomes = <?php echo json_encode($monthly_incomes); ?>;
    const monthlyExpenses = <?php echo json_encode($monthly_expenses); ?>;

    // Data untuk Pelanggan Berdasarkan Paket (Doughnut Chart - tidak berubah)
    const packageLabels = <?php echo json_encode($package_labels); ?>;
    const packageDataCounts = <?php echo json_encode($package_data_counts); ?>;

    // Data untuk Pemasukan Bulanan dari Teknisi (sekarang akan jadi Bar Chart)
    const techIncomeLabelsMonthly = <?php echo json_encode($tech_income_labels_monthly); ?>;
    const finalTechChartDatasets = <?php echo json_encode($final_tech_chart_datasets); ?>;

    // Data BARU untuk Pelanggan Baru Bulanan
    const newCustomersMonthLabels = <?php echo json_encode($new_customers_month_labels); ?>;
    const newCustomersMonthlyCounts = <?php echo json_encode($new_customers_monthly_counts); ?>;

    // Data BARU untuk Rekap Pendapatan dari Pelanggan (Tahun Saat Ini)
    const customerIncomeLabels = <?php echo json_encode($customer_income_labels); ?>;
    const customerIncomeAmounts = <?php echo json_encode($customer_income_amounts); ?>;

    // Array warna yang bisa dipakai untuk Doughnut Chart
    const predefinedColors = [
        'rgba(255, 99, 132, 0.7)', // Merah
        'rgba(54, 162, 235, 0.7)', // Biru
        'rgba(255, 206, 86, 0.7)', // Kuning
        'rgba(75, 192, 192, 0.7)', // Hijau
        'rgba(153, 102, 255, 0.7)', // Ungu
        'rgba(255, 159, 64, 0.7)', // Oranye
        'rgba(199, 199, 199, 0.7)', // Abu-abu
        'rgba(83, 102, 255, 0.7)', // Indigo
        'rgba(201, 100, 255, 0.7)', // Lavender
        'rgba(100, 201, 100, 0.7)' // Hijau muda
    ];
    // Pastikan jumlah warna cukup untuk semua paket
    const backgroundColorsForPackages = packageLabels.map((_, index) => predefinedColors[index % predefinedColors.length]);


    // Konfigurasi Pemasukan dan Pengeluaran (LINE CHART)
    const ctxIncomeExpense = document.getElementById('incomeExpenseChart').getContext('2d');
    const incomeExpenseChart = new Chart(ctxIncomeExpense, {
        type: 'line',
        data: {
            labels: monthLabels,
            datasets: [{
                    label: 'Pemasukan (Rp)',
                    data: monthlyIncomes,
                    borderColor: 'rgba(75, 192, 192, 1)',
                    backgroundColor: 'rgba(75, 192, 192, 0.2)',
                    fill: false,
                    tension: 0.3
                },
                {
                    label: 'Pengeluaran (Rp)',
                    data: monthlyExpenses,
                    borderColor: 'rgba(255, 99, 132, 1)',
                    backgroundColor: 'rgba(255, 99, 132, 0.2)',
                    fill: false,
                    tension: 0.3
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    title: {
                        display: true,
                        text: 'Jumlah (Rp)'
                    },
                    ticks: {
                        callback: function(value) {
                            return 'Rp' + value.toLocaleString('id-ID');
                        }
                    }
                },
                x: {
                    title: {
                        display: true,
                        text: 'Bulan'
                    }
                }
            },
            plugins: {
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            let label = context.dataset.label || '';
                            if (label) {
                                label += ': ';
                            }
                            if (context.parsed.y !== null) {
                                label += 'Rp' + context.parsed.y.toLocaleString('id-ID');
                            }
                            return label;
                        }
                    }
                },
                title: {
                    display: true,
                    text: 'Tren Pemasukan dan Pengeluaran Bulanan (Tahun <?php echo date('Y'); ?>)'
                }
            }
        }
    });

 // --- SCRIPT BARU UNTUK CHART PENDAPATAN MITRA ---
        const mitraRevenueMonthlyAmounts = <?php echo json_encode($mitra_revenue_monthly_amounts); ?>;
        const mitraRevenueMonthLabels = <?php echo json_encode($mitra_revenue_month_labels); ?>;
        const mitraRevenueChartConfig = {
            type: 'line', // Menggunakan chart garis untuk tren
            data: {
                labels: mitraRevenueMonthLabels,
                datasets: [{
                    label: 'Pendapatan Mitra (Rp)',
                    backgroundColor: 'rgba(23, 162, 184, 0.5)',
                    borderColor: 'rgb(23, 162, 184)',
                    borderWidth: 2,
                    data: mitraRevenueMonthlyAmounts,
                    fill: true,
                    tension: 0.1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            // Mengatur format mata uang pada sumbu Y
                            callback: function(value, index, values) {
                                return 'Rp ' + value.toLocaleString('id-ID');
                            }
                        }
                    }
                }
            }
        };
        new Chart(document.getElementById('mitraRevenueChart'), mitraRevenueChartConfig);
        // --- AKHIR SCRIPT BARU ---


	// Data BARU untuk Total Pemasukan Berdasarkan Tanggal Input
const totalInputIncomeLabelsMonthly = <?php echo json_encode($total_input_income_labels_monthly); ?>;
const finalTotalInputIncomeDatasets = <?php echo json_encode($final_total_input_income_datasets); ?>;

// Konfigurasi LINE CHART Total Pemasukan Berdasarkan Tanggal Input
const ctxTotalInputIncome = document.getElementById('totalInputIncomeChart').getContext('2d');
const totalInputIncomeChart = new Chart(ctxTotalInputIncome, {
    type: 'line', // PERUBAHAN UTAMA: Mengubah jenis grafik menjadi 'line'
    data: {
        labels: totalInputIncomeLabelsMonthly,
        datasets: finalTotalInputIncomeDatasets.map(dataset => ({
            ...dataset,
            label: 'Total Pemasukan', // Label untuk legenda
            backgroundColor: 'rgba(54, 162, 235, 0.2)', // Warna area di bawah garis
            borderColor: 'rgb(75, 192, 192)', // Warna garis
            pointBackgroundColor: 'rgb(75, 192, 192)', // Warna titik
            pointBorderColor: '#fff', // Warna border titik
            borderWidth: 2, // Ketebalan garis
            tension: 0.4, // PERUBAHAN: Menambahkan kelengkungan pada garis
            fill: false, // Menghilangkan area di bawah garis jika tidak diperlukan
            // Jika Anda ingin dataset menampilkan warna yang berbeda, Anda bisa mengaturnya di sini
        }))
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
            y: {
                beginAtZero: true,
                title: {
                    display: true,
                    text: 'Jumlah (Rp)'
                },
                ticks: {
                    callback: function(value) {
                        return 'Rp' + value.toLocaleString('id-ID');
                    }
                }
            },
            x: {
                title: {
                    display: true,
                    text: 'Bulan'
                }
            }
        },
        plugins: {
            tooltip: {
                callbacks: {
                    label: function(context) {
                        let label = context.dataset.label || '';
                        if (label) {
                            label += ': ';
                        }
                        if (context.parsed.y !== null) {
                            label += 'Rp' + context.parsed.y.toLocaleString('id-ID');
                        }
                        return label;
                    }
                }
            },
            title: {
                display: true,
                text: 'Total Pemasukan Berdasarkan Tanggal Input'
            }
        }
    }
});

    // Konfigurasi Doughnut Chart Pelanggan Berdasarkan Paket
    const ctxPackage = document.getElementById('packageChart').getContext('2d');
    const packageChart = new Chart(ctxPackage, {
        type: 'doughnut',
        data: {
            labels: packageLabels,
            datasets: [{
                label: 'Jumlah Pelanggan',
                data: packageDataCounts,
                backgroundColor: backgroundColorsForPackages,
                borderColor: '#ffffff',
                borderWidth: 2
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        boxWidth: 20
                    }
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            const label = context.label || '';
                            const value = context.parsed;
                            const total = context.dataset.data.reduce((sum, current) => sum + current, 0);
                            const percentage = (total > 0) ? (value / total * 100).toFixed(2) + '%' : '0.00%';
                            return `${label}: ${value} (${percentage})`;
                        }
                    }
                },
                title: {
                    display: true,
                    text: 'Distribusi Pelanggan Berdasarkan Paket'
                }
            }
        }
    });

    // Konfigurasi Pemasukan Bulanan dari Teknisi (BAR CHART)
    const ctxTechIncome = document.getElementById('techIncomeChart').getContext('2d');
    const techIncomeChart = new Chart(ctxTechIncome, {
        type: 'bar',
        data: {
            labels: techIncomeLabelsMonthly,
            datasets: finalTechChartDatasets
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    stacked: false,
                    title: {
                        display: true,
                        text: 'Jumlah (Rp)'
                    },
                    ticks: {
                        callback: function(value) {
                            return 'Rp' + value.toLocaleString('id-ID');
                        }
                    }
                },
                x: {
                    stacked: false,
                    title: {
                        display: true,
                        text: 'Bulan'
                    }
                }
            },
            plugins: {
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            let label = context.dataset.label || '';
                            if (label) {
                                label += ': ';
                            }
                            if (context.parsed.y !== null) {
                                label += 'Rp' + context.parsed.y.toLocaleString('id-ID');
                            }
                            return label;
                        }
                    }
                },
                title: {
                    display: true,
                    text: 'Pemasukan Bulanan dari Teknisi (Tahun <?php echo date('Y'); ?>)'
                }
            }
        }
    });

    // Konfigurasi BAR CHART Pelanggan Baru Bulanan
    const ctxNewCustomers = document.getElementById('newCustomersChart').getContext('2d');
    const newCustomersChart = new Chart(ctxNewCustomers, {
        type: 'bar',
        data: {
            labels: newCustomersMonthLabels,
            datasets: [{
                label: 'Pelanggan Baru',
                data: newCustomersMonthlyCounts,
                backgroundColor: 'rgba(54, 162, 235, 0.7)',
                borderColor: 'rgba(54, 162, 235, 1)',
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    title: {
                        display: true,
                        text: 'Jumlah Pelanggan Baru'
                    },
                    ticks: {
                        stepSize: 1
                    }
                },
                x: {
                    title: {
                        display: true,
                        text: 'Bulan'
                    }
                }
            },
            plugins: {
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            let label = context.dataset.label || '';
                            if (label) {
                                label += ': ';
                            }
                            if (context.parsed.y !== null) {
                                label += context.parsed.y;
                            }
                            return label;
                        }
                    }
                },
                title: {
                    display: true,
                    text: 'Tren Pelanggan Baru Bulanan'
                }
            }
        }
    });

    // Konfigurasi LINE CHART Rekap Pendapatan dari Pelanggan
    const ctxCustomerIncome = document.getElementById('customerIncomeChart').getContext('2d');
    const customerIncomeChart = new Chart(ctxCustomerIncome, {
        type: 'line',
        data: {
            labels: customerIncomeLabels,
            datasets: [{
                label: 'Pendapatan dari Pelanggan (Rp)',
                data: customerIncomeAmounts,
                borderColor: 'rgba(40, 167, 69, 1)',
                backgroundColor: 'rgba(40, 167, 69, 0.2)',
                fill: true,
                tension: 0.3
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    title: {
                        display: true,
                        text: 'Jumlah (Rp)'
                    },
                    ticks: {
                        callback: function(value) {
                            return 'Rp' + value.toLocaleString('id-ID');
                        }
                    }
                },
                x: {
                    title: {
                        display: true,
                        text: 'Bulan'
                    }
                }
            },
            plugins: {
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            let label = context.dataset.label || '';
                            if (label) {
                                label += ': ';
                            }
                            if (context.parsed.y !== null) {
                                label += 'Rp' + context.parsed.y.toLocaleString('id-ID');
                            }
                            return label;
                        }
                    }
                },
                title: {
                    display: true,
                    text: 'Rekap Pendapatan Bulanan dari Pelanggan (Tahun <?php echo date('Y'); ?>)'
                }
            }
        }
    });
</script>
