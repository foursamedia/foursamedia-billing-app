<?php
$db_host = "localhost";
$db_user = "root";
$db_pass = "debianserver";
$db_name = "crudapps"; // Ganti dengan nama database Anda

// Buat koneksi database
$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

// Periksa koneksi
if ($conn->connect_error) {
    die("Koneksi gagal: " . $conn->connect_error);
}

// --- TAMBAHKAN BARIS INI UNTUK MENENTUKAN CHARSET KE UTF8 ---
$conn->set_charset("utf8mb4"); // Atau "utf8" jika database Anda utf8
// --- AKHIR TAMBAH ---

// Atur mode error untuk debugging
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// ... kode lainnya ...
?>