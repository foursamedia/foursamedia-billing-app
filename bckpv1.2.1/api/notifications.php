<?php
// app/api/notifications.php
require_once '../includes/db_connect.php';
require_once '../includes/session.php'; // Pastikan session_start() ada di sini atau sudah dipanggil
require_once '../includes/functions.php';

header('Content-Type: application/json');

$response = [
    'count' => 0,
    'notifications' => [],
    'error' => ''
];

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    $response['error'] = 'User not logged in.';
    echo json_encode($response);
    exit();
}

$user_id_for_notifications = $_SESSION['user_id'];
$role_name = $_SESSION['role_name'] ?? '';

// Hanya ambil notifikasi jika user_id ada di session DAN perannya adalah admin atau superadmin
// Sesuaikan peran yang relevan untuk menerima notifikasi pelanggan baru
if ($role_name === 'admin' || $role_name === 'superadmin') {
    // Query untuk mengambil notifikasi yang belum dibaca (misal 5 terbaru)
    $stmt_notifications = $conn->prepare("SELECT id, message, link, created_at, is_read FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
    // Catatan: is_read = FALSE sudah di-filter di header.php sebelumnya, tapi untuk API ini
    // mungkin Anda ingin menampilkan semua dan biarkan JS yang menentukan mana yang bold
    // ATAU tetap filter is_read = FALSE di sini jika hanya ingin notif belum dibaca di dropdown.
    // Saya biarkan dulu TANPA filter is_read=FALSE agar API ini lebih fleksibel,
    // tapi Anda bisa menambahkan kembali `AND is_read = FALSE` jika hanya notif baru yang ingin ditampilkan di dropdown.

    if ($stmt_notifications) {
        $stmt_notifications->bind_param("i", $user_id_for_notifications);
        $stmt_notifications->execute();
        $result_notifications = $stmt_notifications->get_result();
        while ($row = $result_notifications->fetch_assoc()) {
            $response['notifications'][] = $row;
        }
        $stmt_notifications->close();
    } else {
        $response['error'] = "Failed to prepare notifications statement: " . $conn->error;
    }

    // Query untuk menghitung total notifikasi yang belum dibaca
    $stmt_count_notifications = $conn->prepare("SELECT COUNT(id) FROM notifications WHERE user_id = ? AND is_read = FALSE");
    if ($stmt_count_notifications) {
        $stmt_count_notifications->bind_param("i", $user_id_for_notifications);
        $stmt_count_notifications->execute();
        $stmt_count_notifications->bind_result($count);
        $stmt_count_notifications->fetch();
        $response['count'] = $count;
        $stmt_count_notifications->close();
    } else {
        $response['error'] .= " Failed to prepare count statement: " . $conn->error;
    }
}

$conn->close();
echo json_encode($response);
?>