<?php
// public/print_invoice.php
require_once '../includes/db_connect.php';
require_once '../includes/session.php';
require_once '../includes/functions.php';

// Pastikan pengguna sudah login
check_login();

// Hanya peran yang diizinkan untuk mencetak invoice
check_role(['superadmin', 'admin', 'teknisi']);

$customer_id = $_GET['customer_id'] ?? 0;
$invoice_month = $_GET['month'] ?? ''; // Format MM (e.g., 06)
$invoice_year = $_GET['year'] ?? '';   // Format YYYY (e.g., 2025)

// Validasi input
if (empty($customer_id) || !is_numeric($customer_id)) {
    $_SESSION['error_message'] = "ID Pelanggan tidak valid untuk mencetak invoice.";
    header("Location: customers.php");
    exit();
}

// Jika bulan/tahun tidak disediakan, default ke bulan saat ini
if (empty($invoice_month) || empty($invoice_year)) {
    $invoice_month = date('m');
    $invoice_year = date('Y');
}

// Validasi format bulan dan tahun
if (!preg_match('/^\d{2}$/', $invoice_month) || !preg_match('/^\d{4}$/', $invoice_year)) {
    $_SESSION['error_message'] = "Format bulan atau tahun tidak valid.";
    header("Location: customers.php");
    exit();
}

// Buat objek DateTime untuk bulan invoice yang dipilih
try {
    $invoice_date_obj = new DateTime("{$invoice_year}-{$invoice_month}-01");
    $invoice_month_name = $invoice_date_obj->format('F Y'); // Contoh: Juni 2025
} catch (Exception $e) {
    $_SESSION['error_message'] = "Bulan invoice tidak valid.";
    header("Location: customers.php");
    exit();
}


// Ambil detail pelanggan
$customer = null;
$stmt_customer = $conn->prepare("SELECT id, username, name, email, phone, address, paket, created_at FROM users WHERE id = ?");
if ($stmt_customer) {
    $stmt_customer->bind_param("i", $customer_id);
    $stmt_customer->execute();
    $result_customer = $stmt_customer->get_result();
    if ($result_customer->num_rows > 0) {
        $customer = $result_customer->fetch_assoc();
    }
    $stmt_customer->close();
}

if (!$customer) {
    $_SESSION['error_message'] = "Pelanggan tidak ditemukan.";
    header("Location: customers.php");
    exit();
}

// Ambil riwayat pembayaran untuk bulan yang dipilih
$payments = [];
$stmt_payments = $conn->prepare("
    SELECT
        p.id,
        p.amount,
        p.payment_date,
        p.description,
        p.payment_method,
        p.reference_number,
        u.name AS input_by_user_name,
        p.created_at
    FROM
        payments p
    LEFT JOIN
        users u ON p.input_by_user_id = u.id
    WHERE
        p.user_id = ? AND DATE_FORMAT(p.payment_date, '%Y-%m') = ?
    ORDER BY
        p.payment_date ASC, p.created_at ASC
");
if ($stmt_payments) {
    $target_month_year = $invoice_year . '-' . $invoice_month;
    $stmt_payments->bind_param("is", $customer_id, $target_month_year);
    $stmt_payments->execute();
    $result_payments = $stmt_payments->get_result();
    while ($row = $result_payments->fetch_assoc()) {
        $payments[] = $row;
    }
    $stmt_payments->close();
}



// --- Tampilan HTML untuk Invoice ---
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice Pembayaran Bulan <?php echo $invoice_month_name; ?> - <?php echo htmlspecialchars($customer['name']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
            color: #333;
        }
        .invoice-box {
            max-width: 800px;
            margin: auto;
            padding: 30px;
            border: 1px solid #eee;
            box-shadow: 0 0 10px rgba(0, 0, 0, .15);
            font-size: 14px;
            line-height: 24px;
            color: #555;
        }
        .invoice-box table {
            width: 100%;
            line-height: inherit;
            text-align: left;
            border-collapse: collapse;
        }
        .invoice-box table td {
            padding: 5px;
            vertical-align: top;
        }
        .invoice-box table tr td:nth-child(2) {
            text-align: right;
        }
        .invoice-box table tr.top table td {
            padding-bottom: 20px;
        }
        .invoice-box table tr.top table td.title {
            font-size: 30px;
            line-height: 30px;
            color: #333;
        }
        .invoice-box table tr.information table td {
            padding-bottom: 30px;
        }
        .invoice-box table tr.heading td {
            background: #eee;
            border-bottom: 1px solid #ddd;
            font-weight: bold;
        }
        .invoice-box table tr.details td {
            padding-bottom: 20px;
        }
        .invoice-box table tr.item td {
            border-bottom: 1px solid #eee;
        }
        .invoice-box table tr.item.last td {
            border-bottom: none;
        }
        .invoice-box table tr.total td:nth-child(2) {
            border-top: 2px solid #eee;
            font-weight: bold;
        }
        @media print {
            body {
                margin: 0;
                padding: 0;
                font-size: 12px;
            }
            .invoice-box {
                box-shadow: none;
                border: none;
                margin: 0;
                padding: 0;
            }
            .no-print {
                display: none;
            }
        }
        .text-end { text-align: right; }
        .text-start { text-align: left; }
    </style>
</head>
<body>
    <div class="invoice-box">
        <table cellpadding="0" cellspacing="0">
            <tr class="top">
                <td colspan="2">
                    <table>
                        <tr>
                            <td class="title">
                                <img src="assets/img/logo.png" style="width:100%; max-width:150px;">
                            </td>
                            <td>
                                Invoice #: <?php echo date('Ym') . $customer['id'] . '-' . $invoice_month . $invoice_year; ?><br>
                                Tanggal Cetak: <?php echo date('d M Y H:i'); ?><br>
                                Invoice Untuk Bulan: **<?php echo $invoice_month_name; ?>**
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>

            <tr class="information">
                <td colspan="2">
                    <table>
                        <tr>
                            <td>
                                **Foursamedia**<br>
                                Jl. Gunung Kelop<br>
                                +62 851-7989-0012<br>
                                office@4saudara.id
                            </td>
                            <td>
                                **Kepada:**<br>
                                <?php echo htmlspecialchars($customer['name']); ?><br>
                                <?php echo htmlspecialchars($customer['address']); ?><br>
                                <?php echo htmlspecialchars($customer['email']); ?><br>
                                <?php echo htmlspecialchars($customer['phone']); ?>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>

            <tr class="heading">
                <td>Deskripsi</td>
                <td class="text-end">Jumlah</td>
            </tr>

            <?php
            $total_paid = 0;
            $paket_price = floatval($customer['paket'] ?? 0); // Asumsi kolom 'paket' menyimpan harga bulanan

            // Jika ada pembayaran untuk bulan ini
            if (!empty($payments)):
                foreach ($payments as $payment):
                    $total_paid += $payment['amount'];
            ?>
            <tr class="item">
                <td>Pembayaran tgl <?php echo htmlspecialchars($payment['payment_date']); ?> via <?php echo htmlspecialchars($payment['payment_method']); ?> (<?php echo htmlspecialchars($payment['description'] ?? 'Tanpa deskripsi'); ?>)</td>
                <td class="text-end">Rp<?php echo number_format($payment['amount'], 0, ',', '.'); ?></td>
            </tr>
            <?php
                endforeach;
            // Jika tidak ada pembayaran untuk bulan ini, tapi ada harga paket
            elseif ($paket_price > 0):
                // Ini adalah tagihan untuk bulan ini (belum dibayar)
            ?>
            <tr class="item">
                <td>Tagihan Layanan Internet Bulan <?php echo $invoice_month_name; ?></td>
                <td class="text-end">Rp<?php echo number_format($paket_price, 0, ',', '.'); ?></td>
            </tr>
            <?php
            else:
            ?>
            <tr class="item last">
                <td colspan="2">Tidak ada pembayaran tercatat untuk bulan ini dan harga paket tidak tersedia.</td>
            </tr>
            <?php endif; ?>

            <?php
            // Tambahkan baris total yang dibayar untuk bulan ini
            if ($total_paid > 0) {
            ?>
            <tr class="total">
                <td></td>
                <td class="text-end">
                   Total Terbayar Bulan Ini: Rp<?php echo number_format($total_paid, 0, ',', '.'); ?>
                </td>
            </tr>
            <?php
            }

            // Hitung status pembayaran: Lunas atau Belum Lunas
            $status_pembayaran = "Belum Lunas";
            if ($total_paid >= $paket_price && $paket_price > 0) {
                $status_pembayaran = "Lunas";
            } elseif ($total_paid > 0 && $paket_price == 0) {
                 $status_pembayaran = "Lunas (Harga paket tidak ditentukan)";
            } elseif ($total_paid == 0 && $paket_price == 0) {
                $status_pembayaran = "Belum Ada Data";
            }

            ?>
            <tr class="total">
                <td></td>
                <td class="text-end">
                   **Status Pembayaran: <?php echo $status_pembayaran; ?>**
                </td>
            </tr>

        </table>
        <div class="no-print mt-4 text-center">
            <button onclick="window.print()" class="btn btn-primary me-2">Cetak Invoice</button>
            <button onclick="window.close()" class="btn btn-secondary">Tutup</button>
        </div>
    </div>
</body>
</html>