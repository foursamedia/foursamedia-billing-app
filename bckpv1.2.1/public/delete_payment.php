<?php
// public/delete_payment.php
require_once '../includes/db_connect.php';
require_once '../includes/session.php';
require_once '../includes/functions.php';
check_login();
check_role(['superadmin', 'admin', 'teknisi']); // Teknisi juga bisa menghapus pembayaran jika diperlukan

$payment_id = $_GET['id'] ?? 0;

if ($payment_id > 0) {
    $stmt = $conn->prepare("DELETE FROM payments WHERE id = ?");
    $stmt->bind_param("i", $payment_id);

    if ($stmt->execute()) {
        header("Location: manage_payments.php?status=success_delete");
        exit();
    } else {
        // Jika gagal, mungkin redirect dengan pesan error
        header("Location: manage_payments.php?status=error&message=" . urlencode("Gagal menghapus pembayaran."));
        exit();
    }
    $stmt->close();
} else {
    header("Location: manage_payments.php?status=error&message=" . urlencode("ID pembayaran tidak valid."));
    exit();
}


?>