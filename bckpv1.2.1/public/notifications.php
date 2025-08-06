<?php
// public/notifications.php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../includes/db_connect.php';
require_once '../includes/session.php';
require_once '../includes/functions.php'; // Pastikan display_session_messages() dan format_datetime() ada di sini

check_login();

$title = "Notifikasi Anda";
$user_id = $_SESSION['user_id'];

// Ambil role_id dari session. Jika tidak ada, fallback ke null atau lakukan lookup.
// Asumsi: Kita sudah mengatasi masalah role_id di session, atau menggunakan lookup jika hanya role_name yang ada.
// Jika Anda masih hanya memiliki role_name, gunakan logika lookup role_id yang saya berikan sebelumnya.
$user_role_id = $_SESSION['role_id'] ?? null; // Operator null coalescing untuk menghindari warning

// Mengambil notifikasi untuk user_id spesifik ATAU untuk role_id user tersebut
// ATAU notifikasi umum (user_id IS NULL AND role_id IS NULL)
$sql = "
    SELECT
        id,
        message,
        type,
        is_read,
        created_at
    FROM
        notifications
    WHERE
        user_id = ? OR role_id = ? OR (user_id IS NULL AND role_id IS NULL)
    ORDER BY created_at DESC
";

$notifications = [];
$stmt = $conn->prepare($sql);

if ($stmt) {
    // Periksa apakah user_role_id null atau tidak untuk binding parameter
    if ($user_role_id === null) {
        // Jika role_id null, kita tetap bisa binding, tapi value-nya null
        // Pastikan parameter yang sesuai dengan role_id juga diatur ke null
        // Ini mungkin memerlukan penyesuaian query WHERE clauses jika Anda tidak ingin melibatkan role_id sama sekali
        // Untuk saat ini, kita asumsikan bind_param bisa menerima null atau kita tangani secara spesifik.
        // Solusi yang lebih robust adalah membangun SQL secara kondisional.
        // Mari kita perbaiki agar lebih robust untuk bind_param.
        $sql_dynamic = "
            SELECT
                id,
                message,
                type,
                is_read,
                created_at
            FROM
                notifications
            WHERE
                user_id = ? ";

        $params_dynamic = [$user_id];
        $types_dynamic = "i";

        if ($user_role_id !== null) {
            $sql_dynamic .= " OR role_id = ?";
            $params_dynamic[] = $user_role_id;
            $types_dynamic .= "i";
        }
        $sql_dynamic .= " OR (user_id IS NULL AND role_id IS NULL) ORDER BY created_at DESC";

        $stmt = $conn->prepare($sql_dynamic);
        if ($stmt) {
            $stmt->bind_param($types_dynamic, ...$params_dynamic);
        } else {
            $_SESSION['error_message'] = "Gagal menyiapkan query notifikasi (dinamis): " . $conn->error;
        }

    } else {
        // Jika role_id ada, pakai query asli
        $stmt->bind_param("ii", $user_id, $user_role_id);
    }

    if ($stmt && $stmt->execute()) { // Hanya jalankan execute jika stmt berhasil disiapkan
        $result = $stmt->get_result();
        $notifications = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    } else if ($stmt) { // Jika execute gagal tapi prepare berhasil
        $_SESSION['error_message'] = "Gagal mengambil notifikasi: " . $stmt->error;
    }


} else {
    $_SESSION['error_message'] = "Gagal menyiapkan query notifikasi: " . $conn->error;
}

// Logika untuk menandai notifikasi sebagai sudah dibaca
if (isset($_GET['mark_as_read']) && $_GET['mark_as_read'] === 'all') {
    // Untuk mark_as_read, kita harus memastikan user_role_id juga digunakan dengan benar
    $stmt_mark_sql = "UPDATE notifications SET is_read = TRUE WHERE user_id = ?";
    $mark_params = [$user_id];
    $mark_types = "i";

    if ($user_role_id !== null) {
        $stmt_mark_sql .= " OR role_id = ?";
        $mark_params[] = $user_role_id;
        $mark_types .= "i";
    }
    $stmt_mark_sql .= " OR (user_id IS NULL AND role_id IS NULL)";


    $stmt_mark = $conn->prepare($stmt_mark_sql);
    if ($stmt_mark) {
        $stmt_mark->bind_param($mark_types, ...$mark_params);
        if ($stmt_mark->execute()) {
            $_SESSION['success_message'] = "Semua notifikasi berhasil ditandai sebagai sudah dibaca.";
            header("Location: notifications.php");
            exit();
        } else {
            $_SESSION['error_message'] = "Gagal menandai notifikasi sebagai sudah dibaca: " . $stmt_mark->error;
        }
        $stmt_mark->close();
    } else {
        $_SESSION['error_message'] = "Gagal menyiapkan query untuk menandai notifikasi: " . $conn->error;
    }
}

require_once '../includes/header.php';
?>

<div class="main-content">
    <div class="container-fluid">
        <header class="mb-4 d-flex justify-content-between align-items-center">
            <h1 class="display-5 mb-0">Notifikasi Anda</h1>
            <?php if (!empty($notifications)): ?>
                <a href="notifications.php?mark_as_read=all" class="btn btn-sm btn-outline-secondary" onclick="return confirm('Tandai semua notifikasi sebagai sudah dibaca?');">Tandai Semua Sudah Dibaca</a>
            <?php endif; ?>
        </header>

        <?php display_session_messages(); ?>

        <div class="card shadow mb-4">
            <div class="card-body">
                <?php if (!empty($notifications)): ?>
                    <div class="list-group">
                        <?php foreach ($notifications as $notification): ?>
                            <?php
                            $alert_class = 'alert-secondary'; // Default warna terang
                            if ($notification['type'] === 'success') {
                                $alert_class = 'alert-success';
                            } elseif ($notification['type'] === 'warning') {
                                $alert_class = 'alert-warning';
                            } elseif ($notification['type'] === 'danger') {
                                $alert_class = 'alert-danger';
                            } elseif ($notification['type'] === 'info') {
                                $alert_class = 'alert-info';
                            }

                            // Jika sudah dibaca, tambahkan kelas untuk tampilan "pudar"
                            $read_style_class = $notification['is_read'] ? 'text-muted-notification' : 'text-dark-notification';
                            $background_read_class = $notification['is_read'] ? 'bg-light-subtle' : 'bg-white'; // Lebih lembut untuk yang sudah dibaca

                            // Untuk ikon, Anda bisa menggunakan FontAwesome jika terinstal
                            $icon_class = 'bi bi-info-circle'; // Default icon Bootstrap Icons
                            if ($notification['type'] === 'success') {
                                $icon_class = 'bi bi-check-circle';
                            } elseif ($notification['type'] === 'warning') {
                                $icon_class = 'bi bi-exclamation-triangle';
                            } elseif ($notification['type'] === 'danger') {
                                $icon_class = 'bi bi-x-circle';
                            }
                            ?>
                            <div class="list-group-item list-group-item-action border-0 shadow-sm rounded mb-3 p-3 <?= $background_read_class ?>">
                                <div class="d-flex align-items-start">
                                    <div class="flex-shrink-0 me-3">
                                        <i class="<?= $icon_class ?> fs-4 <?= $read_style_class ?>"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <div class="d-flex w-100 justify-content-between align-items-center mb-1">
                                            <h6 class="mb-0 <?= $read_style_class ?>">
                                                <?= htmlspecialchars($notification['message']) ?>
                                            </h6>
                                            <small class="<?= $read_style_class ?> text-nowrap">
                                                <?= htmlspecialchars(format_datetime($notification['created_at'])) ?>
                                            </small>
                                        </div>
                                        <?php if (!$notification['is_read']): ?>
                                            <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="alert alert-info text-center" role="alert">
                        Tidak ada notifikasi baru untuk Anda.
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php
require_once '../includes/footer.php';
?>

<style>
    /* Custom styles untuk notifikasi agar lebih rapi */
    .text-muted-notification {
        color: #6c757d !important; /* Warna abu-abu yang sedikit lebih gelap dari default muted */
    }
    .text-dark-notification {
        color: #212529 !important; /* Warna teks gelap untuk notifikasi belum dibaca */
    }
    .list-group-item {
        transition: all 0.2s ease-in-out;
    }
    .list-group-item:hover {
        transform: translateY(-2px);
        box-shadow: 0 .5rem 1rem rgba(0,0,0,.15) !important;
    }
    .bg-light-subtle { /* Kelas bootstrap 5.3+ */
        background-color: var(--bs-light-bg-subtle) !important;
    }
</style>