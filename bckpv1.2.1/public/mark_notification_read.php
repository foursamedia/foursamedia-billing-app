<?php
require_once '../includes/db_connect.php';
require_once '../includes/session.php';
require_once '../includes/functions.php';

// Pastikan user sudah login dan memiliki ID
check_login(); // Mengalihkan jika belum login

if (isset($_GET['notification_id']) && is_numeric($_GET['notification_id'])) {
    $notification_id = (int)$_GET['notification_id'];
    $user_id = $_SESSION['user_id']; // ID user yang sedang login

    // Update status is_read notifikasi
    // Penting: Pastikan notifikasi yang diupdate adalah milik user yang sedang login
    $stmt = $conn->prepare("UPDATE notifications SET is_read = TRUE WHERE id = ? AND user_id = ?");
    if ($stmt) {
        $stmt->bind_param("ii", $notification_id, $user_id);
        $stmt->execute();
        $stmt->close();
    }

    // Ambil link atau redirect_to dari notifikasi atau parameter GET
    $target_link = '';
    if (isset($_GET['redirect_to']) && !empty($_GET['redirect_to'])) {
        $target_link = htmlspecialchars($_GET['redirect_to']);
    } elseif (isset($_GET['link'])) {
        $target_link = htmlspecialchars($_GET['link']);
    }

    // Jika target_link ada dan mengarah ke user_details.php, ubah ke customer_details.php
    if (!empty($target_link)) {
        $parsed_url = parse_url($target_link);
        if (isset($parsed_url['path']) && strpos($parsed_url['path'], 'user_details.php') !== false) {
            // Jika ada parameter 'id', pertahankan
            $query_params = [];
            if (isset($parsed_url['query'])) {
                parse_str($parsed_url['query'], $query_params);
            }
            if (isset($query_params['id'])) {
                header("Location: customer_details.php?id=" . urlencode($query_params['id']));
                exit();
            } else {
                // Jika tidak ada ID, mungkin redirect ke customer_details.php saja
                header("Location: customer_details.php");
                exit();
            }
        } else {
            // Jika link tidak mengarah ke user_details.php, redirect sesuai aslinya
            header("Location: " . $target_link);
            exit();
        }
    } else {
        // Jika tidak ada parameter 'redirect_to' atau 'link', redirect ke customer_details.php dengan user_id
        header("Location: customer_details.php?id=" . urlencode($user_id));
        exit();
    }

} else {
    // Jika notification_id tidak valid, redirect ke customer_details.php dengan user_id
    header("Location: customer_details.php?id=" . urlencode($_SESSION['user_id']));
    exit();
}
?>