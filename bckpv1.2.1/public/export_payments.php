<?php

// Enable full error reporting for development (remove in production)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// public/export_payments.php
require_once '../includes/db_connect.php';
require_once '../includes/session.php';
require_once '../includes/functions.php';

// Include PHPSpreadsheet Autoloader
require_once '../vendor/autoload.php'; // Adjust path if Composer's autoload.php is elsewhere

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

// Check if the user is logged in and has the superadmin role
check_login();
if ($_SESSION['role_name'] !== 'superadmin') {
    // Redirect or show an error if the user is not a superadmin
    header("Location: manage_payments.php?status=unauthorized_export");
    exit();
}

// Get search query from GET parameter, if any
$search_query = $_GET['search'] ?? '';

// --- SQL Queries for fetching ALL data (no LIMIT/OFFSET for export) ---
$base_sql = "
    SELECT
        p.id AS payment_id,
        p.amount,
        p.payment_date,
        p.description,
        u.name AS customer_name,
        ui.name AS inputter_name,
        ui.email AS inputter_email
    FROM
        payments p
    JOIN
        users u ON p.user_id = u.id
    LEFT JOIN
        users ui ON p.input_by_user_id = ui.id
    WHERE 1=1
";

$params = [];
$param_types = "";

// Add search condition if search_query exists
if (!empty($search_query)) {
    $where_clause = " AND (u.name LIKE ? OR ui.name LIKE ? OR p.description LIKE ?)";
    $base_sql .= $where_clause;

    $search_param = '%' . $search_query . '%';

    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $param_types .= "sss";
}

// Add ORDER BY for consistent export order
$base_sql .= " ORDER BY p.payment_date DESC, p.id DESC";

// Execute MAIN query to get all payments (filtered by search if any)
$stmt = $conn->prepare($base_sql);
if ($stmt === false) {
    die("Error preparing export statement: " . $conn->error);
}
if (!empty($params) && !empty($param_types)) {
    $stmt->bind_param($param_types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

$payments_data = [];
if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $payments_data[] = $row;
    }
}
$stmt->close();
$conn->close();

// --- Create new Spreadsheet object ---
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Data Pembayaran');

// --- Set header row ---
$headers = [
    'ID Pembayaran',
    'Nama Pelanggan',
    'Jumlah',
    'Tanggal Pembayaran',
    'Deskripsi',
    'Diinput Oleh (Nama)',
    'Diinput Oleh (Email)'
];
$sheet->fromArray($headers, NULL, 'A1');

// --- Apply styling to header row ---
$headerStyle = [
    'font' => [
        'bold' => true,
        'color' => ['argb' => 'FFFFFFFF'],
    ],
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'rotation' => 90,
        'startColor' => ['argb' => 'FF4F81BD'],
    ],
    'borders' => [
        'allBorders' => [
            'borderStyle' => Border::BORDER_THIN,
            'color' => ['argb' => 'FF000000'],
        ],
    ],
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
        'vertical' => Alignment::VERTICAL_CENTER,
    ],
];
$sheet->getStyle('A1:' . $sheet->getHighestColumn() . '1')->applyFromArray($headerStyle);


// --- Add data rows ---
$row_number = 2; // Start from row 2 after headers
foreach ($payments_data as $payment) {
    $inputter_name = !empty($payment['inputter_name']) ? $payment['inputter_name'] : "Tidak Diketahui";
    $inputter_email = !empty($payment['inputter_email']) ? $payment['inputter_email'] : "";

    $sheet->setCellValue('A' . $row_number, $payment['payment_id']);
    $sheet->setCellValue('B' . $row_number, $payment['customer_name']);
    $sheet->setCellValue('C' . $row_number, $payment['amount']);
    $sheet->setCellValue('D' . $row_number, date('d-m-Y H:i:s', strtotime($payment['payment_date'])));
    $sheet->setCellValue('E' . $row_number, $payment['description']);
    $sheet->setCellValue('F' . $row_number, $inputter_name);
    $sheet->setCellValue('G' . $row_number, $inputter_email);
    $row_number++;
}

// --- Auto-size columns for better readability ---
foreach (range('A', $sheet->getHighestColumn()) as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

// --- Set the appropriate headers for file download ---
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="data_pembayaran_' . date('Ymd_His') . '.xlsx"');
header('Cache-Control: max-age=0');

// --- Write the spreadsheet to the output buffer ---
$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit();
?>