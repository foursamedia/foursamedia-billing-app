<?php
require_once '../includes/db_connect.php';
require_once '../includes/session.php';
require_once '../includes/functions.php';

check_role(['pelanggan']); // Hanya pelanggan yang bisa mengakses ini

$customer_id = $_SESSION['user_id']; // ID pelanggan adalah ID user yang sedang login
$customer_payments = [];

if ($customer_id) {
    // Ambil riwayat pembayaran pelanggan yang sedang login
    // Tidak perlu join dengan inputter_name karena fokus pada pembayaran pelanggan itu sendiri
    $stmt_payments = $conn->prepare("
        SELECT
            id AS payment_id,
            amount,
            payment_date,
            description
        FROM
            payments
        WHERE
            user_id = ?
        ORDER BY
            payment_date DESC
    ");
    $stmt_payments->bind_param("i", $customer_id);
    $stmt_payments->execute();
    $result_payments = $stmt_payments->get_result();

    if ($result_payments->num_rows > 0) {
        while ($row = $result_payments->fetch_assoc()) {
            $customer_payments[] = $row;
        }
    }
    $stmt_payments->close();
}


?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pembayaran Saya</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
</head>
<body>
    <div class="d-flex">
        <?php include_once '../includes/sidebar.php'; ?>

        <div class="content-area">
            <header class="mb-4">
                <h1 class="display-5">Riwayat Pembayaran Saya</h1>
            </header>

            <?php if (!empty($customer_payments)): ?>
                <div class="table-responsive">
                    <table class="table table-striped table-bordered">
                        <thead>
                            <tr>
                                <th>ID Pembayaran</th>
                                <th>Jumlah</th>
                                <th>Tanggal Pembayaran</th>
                                <th>Deskripsi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($customer_payments as $payment): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($payment['payment_id']); ?></td>
                                    <td>Rp<?php echo number_format($payment['amount'], 0, ',', '.'); ?></td>
                                    <td><?php echo htmlspecialchars($payment['payment_date']); ?></td>
                                    <td><?php echo htmlspecialchars($payment['description']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="alert alert-info" role="alert">
                    Anda belum memiliki riwayat pembayaran.
                </div>
            <?php endif; ?>
        </div>
    </div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>