<?php
require_once '../includes/session.php';
require_once '../includes/db_connect.php';
require '../vendor/autoload.php'; // Path ke autoload.php dari Composer

// Proteksi halaman: Hanya superadmin yang bisa export
hasAccess(['superadmin']);

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls;

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// Header kolom
$sheet->setCellValue('A1', 'ID');
$sheet->setCellValue('B1', 'Nama');
$sheet->setCellValue('C1', 'Email');
$sheet->setCellValue('D1', 'Peran');

// Ambil data pengguna dari database
$sql = "SELECT u.id, u.name, u.email, r.role_name FROM users u JOIN roles r ON u.role_id = r.id ORDER BY u.id ASC";
$result = $conn->query($sql);

$row_num = 2; // Mulai dari baris kedua setelah header
if ($result->num_rows > 0) {
    while($user = $result->fetch_assoc()) {
        $sheet->setCellValue('A' . $row_num, $user['id']);
        $sheet->setCellValue('B' . $row_num, $user['name']);
        $sheet->setCellValue('C' . $row_num, $user['email']);
        $sheet->setCellValue('D' . $row_num, $user['role_name']);
        $row_num++;
    }
}

// Set header untuk download file Excel
header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment;filename="users_data.xls"');
header('Cache-Control: max-age=0');

$writer = new Xls($spreadsheet);
$writer->save('php://output');


exit();
?>