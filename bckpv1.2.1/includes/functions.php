<?php
// includes/functions.php

/**
 * Memastikan pengguna sudah login. Jika belum, redirect ke halaman login.
 */
function check_login() {
    if (!isset($_SESSION['user_id'])) {
        header('Location: login.php'); // Atau sesuaikan path ke login.php
        exit();
    }
}

/**
 * Memastikan pengguna memiliki peran (role) yang diizinkan.
 *
 * @param array $allowed_roles Array berisi nama-nama peran yang diizinkan (misal: ['superadmin', 'admin']).
 */
function check_role(array $allowed_roles) {
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['role_name'])) {
        // Jika belum login atau role tidak ada, redirect ke login
        header('Location: login.php'); // Atau sesuaikan path ke login.php
        exit();
    }

    if (!in_array($_SESSION['role_name'], $allowed_roles)) {
        // Jika role tidak diizinkan, redirect ke halaman tidak ada akses (atau dashboard)
        header('Location: index.php?msg=access_denied'); // Atau halaman error kustom
        exit();
    }
}

/**
 * Fungsi untuk mendapatkan nama peran berdasarkan role_id.
 * Berguna untuk menampilkan nama peran di tabel atau detail pengguna.
 *
 * @param mysqli $conn Objek koneksi database.
 * @param int $role_id ID peran yang ingin dicari namanya.
 * @return string Nama peran jika ditemukan, 'Tidak Ditemukan' jika tidak.
*/
function get_role_name($conn, $role_id) {
    $stmt = $conn->prepare("SELECT role_name FROM roles WHERE id = ?");
    $stmt->bind_param("i", $role_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $row = $result->fetch_assoc();
        $stmt->close();
        return $row['role_name'];
    }
    $stmt->close();
    return 'Tidak Ditemukan';
}

// Fungsi isLoggedIn() dan getUserRole() dari session.php
// Jika Anda memutuskan untuk tetap menggunakannya di tempat lain,
// letakkan juga di functions.php
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function getUserRole() {
    return $_SESSION['role_name'] ?? null;
}

function isValidDate($dateString) {
    // Memastikan format Y-m-d dan tanggal valid
    $dateTime = DateTime::createFromFormat('Y-m-d', $dateString);
    return $dateTime && $dateTime->format('Y-m-d') === $dateString;
}

function cleanPhoneNumber(string $phoneNumber): string
{
    // Hapus semua karakter non-numerik
    $cleanedNumber = preg_replace('/[^0-9]/', '', $phoneNumber);

    // Opsional: Jika Anda ingin menangani angka '0' di depan atau awalan internasional
    // Contoh: Ganti '0' di depan dengan '62' untuk Indonesia jika itu nomor lokal
    // if (substr($cleanedNumber, 0, 1) === '0') {
    //      $cleanedNumber = '62' . substr($cleanedNumber, 1);
    // }

    return $cleanedNumber;
}

/**
 * Menampilkan pesan sukses dan error yang disimpan dalam session.
 * Pesan akan dihapus dari session setelah ditampilkan.
 */
function display_session_messages() {
    if (isset($_SESSION['success_message'])) {
        echo '<div class="alert alert-success alert-dismissible fade show" role="alert">';
        echo htmlspecialchars($_SESSION['success_message']);
        echo '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>';
        echo '</div>';
        unset($_SESSION['success_message']);
    }
    if (isset($_SESSION['error_message'])) {
        echo '<div class="alert alert-danger alert-dismissible fade show" role="alert">';
        echo htmlspecialchars($_SESSION['error_message']);
        echo '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>';
        echo '</div>';
        unset($_SESSION['error_message']);
    }
    // Anda bisa menambahkan jenis pesan lain jika diperlukan (misal: warning, info)
    if (isset($_SESSION['warning_message'])) {
        echo '<div class="alert alert-warning alert-dismissible fade show" role="alert">';
        echo htmlspecialchars($_SESSION['warning_message']);
        echo '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>';
        echo '</div>';
        unset($_SESSION['warning_message']);
    }
    if (isset($_SESSION['info_message'])) {
        echo '<div class="alert alert-info alert-dismissible fade show" role="alert">';
        echo htmlspecialchars($_SESSION['info_message']);
        echo '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>';
        echo '</div>';
        unset($_SESSION['info_message']);
    }
}

/**
 * Fungsi untuk memformat tanggal dan waktu ke format yang lebih mudah dibaca WIB.
 *
 * @param string $datetime_str String tanggal dan waktu dari database (misal: 'YYYY-MM-DD HH:MM:SS').
 * @return string Tanggal dan waktu yang diformat atau 'N/A' jika kosong/invalid.
 */
function format_datetime($datetime_str) {
    if (empty($datetime_str) || $datetime_str === '0000-00-00 00:00:00') {
        return 'N/A';
    }
    // Set timezone ke WIB (Waktu Indonesia Barat)
    $timezone = new DateTimeZone('Asia/Jakarta');
    try {
        $dt = new DateTime($datetime_str);
        $dt->setTimezone($timezone);
        return $dt->format('d-m-Y H:i:s');
    } catch (Exception $e) {
        return 'Invalid Date'; // Handle invalid date strings gracefully
    }
}

// Letakkan ini di file seperti includes/functions.php
// ATAU di bagian atas manage_payments.php setelah require_once
if (!function_exists('ref_values')) {
    function ref_values($arr) {
        $refs = [];
        foreach ($arr as $key => $value) {
            $refs[$key] = &$arr[$key];
        }
        return $refs;
    }
}


/**
 * Membangun HTML untuk kontrol paginasi.
 *
 * @param int $total_pages Jumlah total halaman.
 * @param int $current_page Halaman yang sedang aktif.
 * @param array $get_params Array parameter GET saat ini untuk mempertahankan filter.
 * @return string HTML untuk paginasi.
 */
function build_pagination_html($total_pages, $current_page, $get_params) {
    // Pastikan $total_pages dan $current_page adalah integer
    // Ini penting untuk mencegah TypeError "Unsupported operand types: string - int"
    $total_pages = (int)$total_pages;
    $current_page = (int)$current_page;

    $html = '<nav aria-label="Page navigation">';
    $html .= '<ul class="pagination justify-content-center">';

    // Previous button
    $prev_page = $current_page - 1;
    $prev_disabled = ($current_page <= 1) ? 'disabled' : '';
    $prev_link = ($current_page <= 1) ? '#' : '?';
    $temp_params = $get_params;
    $temp_params['page'] = $prev_page;
    $prev_link .= http_build_query($temp_params);

    $html .= '<li class="page-item ' . $prev_disabled . '"><a class="page-link" href="' . htmlspecialchars($prev_link) . '" data-page="' . $prev_page . '">Previous</a></li>';

    // Page numbers
    // Perbaikan di sini: memastikan $current_page adalah integer
    $start_page = max(1, $current_page - 2);
    $end_page = min($total_pages, $current_page + 2);

    for ($i = $start_page; $i <= $end_page; $i++) {
        $active_class = ($i == $current_page) ? 'active' : '';
        $page_link = '?';
        $temp_params = $get_params;
        $temp_params['page'] = $i;
        $page_link .= http_build_query($temp_params);

        $html .= '<li class="page-item ' . $active_class . '"><a class="page-link" href="' . htmlspecialchars($page_link) . '" data-page="' . $i . '">' . $i . '</a></li>';
    }

    // Next button
    $next_page = $current_page + 1;
    $next_disabled = ($current_page >= $total_pages) ? 'disabled' : '';
    $next_link = ($current_page >= $total_pages) ? '#' : '?';
    $temp_params = $get_params;
    $temp_params['page'] = $next_page;
    $next_link .= http_build_query($temp_params);

    $html .= '<li class="page-item ' . $next_disabled . '"><a class="page-link" href="' . htmlspecialchars($next_link) . '" data-page="' . $next_page . '">Next</a></li>';

    $html .= '</ul></nav>';
    return $html;
}