<?php
// public/api/send_whatsapp.php
// Ini adalah API endpoint sentral yang menerima permintaan untuk mengirim pesan WhatsApp.
// File ini akan LANGSUNG berkomunikasi dengan API Fonnte.

require_once '../includes/db_connect.php'; // Biarkan jika diperlukan untuk konteks atau logging database
require_once '../includes/functions.php'; // Memuat fungsi cleanPhoneNumber()
require_once '../includes/whatsapp_messages.php'; // Memuat semua variabel template pesan WhatsApp

header('Content-Type: application/json');

// Aktifkan logging error SEMENTARA untuk debugging
// Hapus atau nonaktifkan baris ini di lingkungan produksi!
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$input = json_decode(file_get_contents('php://input'), true);

// Debugging: Catat input yang diterima
error_log("send_whatsapp.php: Raw input received: " . file_get_contents('php://input'));
error_log("send_whatsapp.php: Decoded input: " . json_encode($input));

$target_number = $input['number'] ?? '';
$template_name = $input['template_name'] ?? ''; // Nama variabel template dari whatsapp_messages.php
$replacements  = $input['replacements'] ?? [];  // Array asosiatif untuk penggantian placeholder

// --- Validasi Input Awal ---
// Memastikan nomor target dan nama template disediakan dari sisi pengirim (add_user.php, customer_details.php)
if (empty($target_number) || empty($template_name)) {
    error_log("send_whatsapp.php: Error - Invalid input: target_number=" . var_export($target_number, true) . ", template_name=" . var_export($template_name, true));
    echo json_encode(['status' => 'error', 'message' => 'Input data tidak valid. Nomor dan nama template harus disediakan.']);
    exit();
}

// Bersihkan dan format nomor telepon menggunakan fungsi dari functions.php
// Fonnte menerima '08xxx' atau '628xxx', tapi '628xxx' lebih universal.
$cleanedPhone = cleanPhoneNumber($target_number);
if (!$cleanedPhone) {
    error_log("send_whatsapp.php: Error - Nomor telepon tidak valid setelah dibersihkan: " . $target_number);
    echo json_encode(['status' => 'error', 'message' => 'Nomor telepon tidak valid.']);
    exit();
}

$final_message_content = '';

// --- Ambil Template dari Variabel Global yang Dimuat dari whatsapp_messages.php ---
// Mengakses variabel template berdasarkan nama yang diterima (misalnya $GLOBALS['whatsapp_message_new_customer'])
if (isset($GLOBALS[$template_name])) {
    $final_message_content = $GLOBALS[$template_name];
} else {
    error_log("send_whatsapp.php: Error - Template '" . $template_name . "' tidak ditemukan dalam includes/whatsapp_messages.php.");
    echo json_encode(['status' => 'error', 'message' => 'Template pesan tidak ditemukan.']);
    exit();
}

// --- Ganti Placeholder di Pesan ---
if (!empty($final_message_content)) {
    foreach ($replacements as $key => $value) {
        // htmlspecialchars untuk mencegah XSS jika pesan ditampilkan (meskipun WA tidak merender HTML, ini praktik baik)
        // Pastikan placeholder di template formatnya {key} dan di replacements juga key.
        $final_message_content = str_replace('{' . $key . '}', htmlspecialchars($value), $final_message_content);
    }
} else {
    error_log("send_whatsapp.php: Error - Isi template kosong setelah diambil dari variabel.");
    echo json_encode(['status' => 'error', 'message' => 'Isi pesan template kosong.']);
    exit();
}

// ===================================================================================
// --- BAGIAN KRITIS: Panggil API Fonnte LANGSUNG menggunakan cURL ---
// ===================================================================================
$fonnte_api_url = 'https://api.fonnte.com/send'; // URL API Fonnte
$fonnte_api_token = 'k1MxU3hkme9vDNJk2Mpn'; // <--- GANTI DENGAN TOKEN ASLI FONNTE ANDA!

// Pastikan format payload sesuai dengan DOKUMENTASI API Fonnte.
// Fonnte menerima form-data (array PHP) untuk POSTFIELDS, bukan JSON di sini.
$fonnte_payload = array(
    'target' => $cleanedPhone,
    'message' => $final_message_content,
    'countryCode' => '62', // Opsional, tetapi disarankan untuk konsistensi nomor Indonesia
);

$ch_fonnte = curl_init();

curl_setopt_array($ch_fonnte, array(
    CURLOPT_URL => $fonnte_api_url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_ENCODING => '',
    CURLOPT_MAXREDIRS => 10,
    CURLOPT_TIMEOUT => 30, // Timeout bisa diatur lebih lama jika koneksi API Fonnte lambat
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    CURLOPT_CUSTOMREQUEST => 'POST',
    CURLOPT_POSTFIELDS => $fonnte_payload, // Fonnte menggunakan application/x-www-form-urlencoded atau multipart/form-data
    CURLOPT_HTTPHEADER => array(
        'Authorization: ' . $fonnte_api_token // Menggunakan token otentikasi di header
    ),
));

$response_from_fonnte = curl_exec($ch_fonnte); // Eksekusi permintaan cURL
$err_from_fonnte = curl_error($ch_fonnte);     // Tangkap error cURL jika ada
$http_code_from_fonnte = curl_getinfo($ch_fonnte, CURLINFO_HTTP_CODE); // Dapatkan kode status HTTP
curl_close($ch_fonnte); // Tutup sesi cURL

// --- Penanganan Respons dari API Fonnte ---
if ($err_from_fonnte) {
    // Jika ada error dari cURL (misal tidak bisa terhubung ke URL API Fonnte)
    error_log("send_whatsapp.php: Gagal menghubungi API Fonnte (CURL Error): " . $err_from_fonnte);
    echo json_encode(['status' => 'error', 'message' => 'Gagal mengirim pesan WA: Masalah koneksi ke API Fonnte.']);
} else {
    // Debugging: Log respons mentah dari API Fonnte
    error_log("send_whatsapp.php: Respon dari API Fonnte (HTTP {$http_code_from_fonnte}): " . $response_from_fonnte);

    $decoded_fonnte_response = json_decode($response_from_fonnte, true);

    // KONDISI PENTING: Fonnte mengembalikan 'status': true (boolean), BUKAN string 'true'
    // Kita juga bisa cek HTTP code dan keberadaan 'id' pesan yang terkirim
    if ($http_code_from_fonnte >= 200 && $http_code_from_fonnte < 300 &&
        $decoded_fonnte_response &&
        isset($decoded_fonnte_response['status']) && $decoded_fonnte_response['status'] === true && // <--- PERBAIKAN DI SINI!
        isset($decoded_fonnte_response['id']) && is_array($decoded_fonnte_response['id']) && !empty($decoded_fonnte_response['id'][0])) { // <--- PERBAIKAN LAIN: Pastikan ada ID pesan
        
        error_log("send_whatsapp.php: Pesan WA berhasil dikirim ke " . $cleanedPhone . " (Template: " . $template_name . ") via Fonnte API. ID Pesan: " . $decoded_fonnte_response['id'][0]);
        echo json_encode(['status' => 'success', 'message' => 'Pesan WA berhasil dikirim!', 'fonnte_response' => $decoded_fonnte_response]);
    } else {
        // Jika Fonnte API mengembalikan error atau respons tidak sesuai
        $fonnte_error_msg = $decoded_fonnte_response['detail'] ?? $decoded_fonnte_response['message'] ?? 'API Fonnte mengembalikan error tidak diketahui.'; // Ambil dari 'detail' atau 'message'
        error_log("send_whatsapp.php: Gagal mengirim WA via Fonnte API: " . $fonnte_error_msg . " (HTTP: " . $http_code_from_fonnte . ") Raw response: " . $response_from_fonnte);
        echo json_encode(['status' => 'error', 'message' => 'Gagal mengirim pesan WA: ' . $fonnte_error_msg, 'fonnte_response' => $decoded_fonnte_response]);
    }
}
?>