<?php
// public/long_outstanding_customers.php
require_once '../includes/db_connect.php';
require_once '../includes/session.php';
require_once '../includes/functions.php';

// Pastikan pengguna sudah login dan memiliki peran yang sesuai
check_login();
if ($_SESSION['role_name'] !== 'admin' && $_SESSION['role_name'] !== 'superadmin') {
    // PERBAIKAN DI SINI: Ubah dashboard.php menjadi index.php
    header("Location: index.php"); // Kembali ke halaman utama dashboard jika tidak diizinkan
    exit();
}

$title = "Detail Pelanggan Tunggakan = 3 Bulan";

$customers_with_long_outstanding = [];
$current_date = new DateTime();
$previous_month_end = new DateTime(date('Y-m-t', strtotime('last month')));

$all_users_full_data = [];
$stmt_get_users = $conn->prepare("SELECT id, username, name, created_at, role_id FROM users WHERE role_id = (SELECT id FROM roles WHERE role_name = 'pelanggan')");
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


require_once '../includes/header.php';
?>

<header class="mb-4">
    <h1 class="display-5">Detail Pelanggan Tunggakan = 3 Bulan</h1>
    <p class="lead">Daftar pelanggan yang memiliki tunggakan pembayaran bulanan selama 3 bulan atau lebih berturut-turut hingga akhir bulan lalu.</p>
</header>

<div class="row">
    <div class="col-12">
        <a href="index.php" class="btn btn-secondary mb-3"><i class="fas fa-arrow-left"></i> Kembali ke Dashboard</a>
        <div class="card">
            <div class="card-body">
                <?php if (empty($customers_with_long_outstanding)): ?>
                    <p class="text-success">Tidak ada pelanggan dengan tunggakan pembayaran = 3 bulan saat ini.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-striped table-hover">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Username</th>
                                    <th>Nama</th>
                                    <th>Bergabung Sejak</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($customers_with_long_outstanding as $customer): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($customer['id']); ?></td>
                                        <td><?php htmlspecialchars($customer['username']); ?></td>
                                        <td><?php echo htmlspecialchars($customer['name']); ?></td>
                                        <td><?php echo htmlspecialchars((new DateTime($customer['created_at']))->format('d M Y')); ?></td>
                                        <td>
                                            <a href="customer_details.php?id=<?php echo htmlspecialchars($customer['id']); ?>" class="btn btn-info btn-sm">Lihat Detail</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>