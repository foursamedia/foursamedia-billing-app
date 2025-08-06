<?php
// public/manage_whatsapp_messages.php
require_once '../includes/db_connect.php';
require_once '../includes/session.php';
require_once '../includes/functions.php';
require_once '../includes/whatsapp_messages.php'; // Memuat template pesan

check_login();
check_role(['superadmin', 'admin']);

$title = "Manajemen Pesan WhatsApp";

$errors = [];
$success_message = '';

// --- FUNGSI HELPER ---
if (!function_exists('getMonthName')) {
    function getMonthName($month_number) {
        $month_names = [
            1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
            7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
        ];
        return $month_names[(int)$month_number];
    }
}

// --- BAGIAN 1: LOGIKA MANAJEMEN TEMPLATE WHATSAPP ---
$messages_file_path = '../includes/whatsapp_messages.php';

// Fungsi untuk membaca isi file whatsapp_messages.php dan mengekstrak variabelnya
function getWhatsappMessageTemplates($file_path) {
    ob_start();
    require $file_path;
    ob_end_clean();

    $templates = [];
    if (isset($whatsapp_message_new_customer)) {
        $templates['whatsapp_message_new_customer'] = $whatsapp_message_new_customer;
    }
    if (isset($whatsapp_message_billing)) {
        $templates['whatsapp_message_billing'] = $whatsapp_message_billing;
    }
    if (isset($whatsapp_message_invoice)) {
        $templates['whatsapp_message_invoice'] = $whatsapp_message_invoice;
    }
    if (isset($whatsapp_message_payment_confirmation)) {
        $templates['whatsapp_message_payment_confirmation'] = $whatsapp_message_payment_confirmation;
    }
    if (isset($whatsapp_message_down_service_info)) {
        $templates['whatsapp_message_down_service_info'] = $whatsapp_message_down_service_info;
    }
    return $templates;
}

// Membaca template saat ini
$current_templates = getWhatsappMessageTemplates($messages_file_path);

// Logika untuk menyimpan perubahan template
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_templates'])) {
    $updated_templates = [];
    $updated_templates['whatsapp_message_new_customer'] = $_POST['whatsapp_message_new_customer'] ?? '';
    $updated_templates['whatsapp_message_billing'] = $_POST['whatsapp_message_billing'] ?? '';
    $updated_templates['whatsapp_message_invoice'] = $_POST['whatsapp_message_invoice'] ?? '';
    $updated_templates['whatsapp_message_payment_confirmation'] = $_POST['whatsapp_message_payment_confirmation'] ?? '';
    $updated_templates['whatsapp_message_down_service_info'] = $_POST['whatsapp_message_down_service_info'] ?? '';

    foreach ($updated_templates as $key => $value) {
        if (empty(trim($value))) {
            $errors[] = "Pesan untuk '" . str_replace('_', ' ', $key) . "' tidak boleh kosong.";
        }
    }

    if (empty($errors)) {
        $new_file_content = "<?php\n";
        foreach ($updated_templates as $var_name => $content) {
            $new_file_content .= "// -- Pesan WhatsApp untuk " . ucwords(str_replace(['whatsapp_message_', '_'], ['', ' '], $var_name)) . " --\n";
            $new_file_content .= "$" . $var_name . " = \"" . addslashes($content) . "\";\n\n";
        }
        $new_file_content .= "?>";

        if (file_put_contents($messages_file_path, $new_file_content) !== false) {
            $_SESSION['success_message'] = "Pesan WhatsApp berhasil diperbarui!";
            $current_templates = $updated_templates; // Update for display
            header("Location: manage_whatsapp_messages.php"); // Redirect to prevent resubmission
            exit();
        } else {
            $errors[] = "Gagal menulis ke file pesan WhatsApp. Pastikan direktori 'includes' memiliki izin tulis (chmod 775 atau 777 di lingkungan dev, sesuaikan di produksi).";
        }
    }
}
// Ambil pesan sukses dari redirect sebelumnya (misalnya dari pengiriman template)
if (isset($_SESSION['success_message'])) {
    $success_message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

// --- BAGIAN 2: LOGIKA PENGIRIMAN PESAN INDIVIDUAL DENGAN REKAP TAGIHAN ---
$customers = [];
$customer_id_for_send = $_GET['customer_id'] ?? null; // Get customer_id for message generation

// Ambil data pelanggan untuk daftar dropdown
// KOREKSI: Menggunakan 'phone' dan 'created_at' sebagai nama kolom yang benar
$sql_customers = "SELECT id, name, phone, created_at FROM users WHERE role_id = (SELECT id FROM roles WHERE name = 'customer')";
$result_customers = $conn->query($sql_customers);

if ($result_customers === false) {
    error_log("SQL Error fetching customers: " . $conn->error);
    $errors[] = "Gagal mengambil data pelanggan: " . $conn->error;
} else {
    while ($row = $result_customers->fetch_assoc()) {
        $customers[] = $row;
    }
}

$whatsapp_message_content_for_send = ''; // Isi pesan untuk form pengiriman
$selected_customer_phone = '';
$selected_customer_name = '';

// Jika customer_id_for_send dipilih, generate pesan
if ($customer_id_for_send) {
    // KOREKSI: Menggunakan 'phone' dan 'created_at' sebagai nama kolom yang benar
    $stmt_selected_customer = $conn->prepare("SELECT id, name, phone, created_at FROM users WHERE id = ? AND role_id = (SELECT id FROM roles WHERE name = 'customer')");
    if ($stmt_selected_customer === false) {
        error_log("Error preparing select customer statement: " . $conn->error);
        $errors[] = "Gagal menyiapkan query pelanggan: " . $conn->error;
    } else {
        $stmt_selected_customer->bind_param("i", $customer_id_for_send);
        $stmt_selected_customer->execute();
        $result_selected_customer = $stmt_selected_customer->get_result();

        if ($result_selected_customer->num_rows > 0) {
            $customer_data = $result_selected_customer->fetch_assoc();
            // KOREKSI: Menggunakan 'phone' untuk nomor telepon
            $selected_customer_phone = $customer_data['phone'];
            $selected_customer_name = $customer_data['name'];
            // KOREKSI: Menggunakan 'created_at' untuk tanggal pendaftaran
            $registration_date_str = $customer_data['created_at'];

            // --- Logika Rekap Bulan Belum Bayar ---
            $unpaid_months = [];
            if (!empty($registration_date_str)) {
                $registration_date = new DateTime($registration_date_str);
                $current_date = new DateTime(); // Tanggal saat ini

                $iterator_date = clone $registration_date;
                $iterator_date->setDate($iterator_date->format('Y'), $iterator_date->format('m'), 1);

                while ($iterator_date <= $current_date) {
                    $month_name_id = getMonthName($iterator_date->format('m'));
                    $year = $iterator_date->format('Y');
                    $display_month = $month_name_id . ' ' . $year;

                    $month_start = $iterator_date->format('Y-m-01');
                    $month_end = $iterator_date->format('Y-m-t');

                    $payment_check_sql = "SELECT COUNT(id) FROM payments WHERE user_id = ? AND payment_date BETWEEN ? AND ?";
                    $stmt_payment_check = $conn->prepare($payment_check_sql);
                    if ($stmt_payment_check === false) {
                        error_log("Error preparing payment check statement: " . $conn->error);
                        $errors[] = "Gagal menyiapkan query cek pembayaran: " . $conn->error;
                        break;
                    }
                    $stmt_payment_check->bind_param("iss", $customer_data['id'], $month_start, $month_end);
                    $stmt_payment_check->execute();
                    $stmt_payment_check->bind_result($payments_count);
                    $stmt_payment_check->fetch();
                    $stmt_payment_check->close();

                    if ($payments_count == 0) {
                        $is_current_month = ($iterator_date->format('Y-m') == $current_date->format('Y-m'));
                        // Masukkan bulan saat ini jika belum ada pembayaran, atau bulan sebelumnya yang belum terbayar
                        if (!$is_current_month || ($is_current_month && $payments_count == 0)) {
                             $unpaid_months[] = $display_month;
                        }
                    }

                    $iterator_date->modify('+1 month');
                }
            }

            $unpaid_message_text = "";
            if (!empty($unpaid_months)) {
                $unpaid_message_text = "Anda memiliki tunggakan untuk bulan:\n";
                foreach ($unpaid_months as $month) {
                    $unpaid_message_text .= "- " . $month . "\n";
                }
                $unpaid_message_text .= "\nMohon segera lakukan pembayaran untuk bulan-bulan tersebut.\n";
            } else {
                $unpaid_message_text = "Pembayaran Anda lancar sejauh ini. Terima kasih atas ketepatan waktunya!\n";
            }
            // --- Akhir Logika Rekap Bulan Belum Bayar ---

            // Ambil template billing yang sudah ada
            $base_billing_message_template = $whatsapp_message_billing ?? "Halo {nama_pelanggan}!\n\nIni adalah notifikasi tagihan bulanan Anda.\n{rekap_tunggakan}\n\nJumlah Tagihan Bulan Ini: [Jumlah Tagihan]\nJatuh Tempo: [Tanggal Jatuh Tempo]\n\nSilakan lakukan pembayaran melalui [Metode Pembayaran Anda].\nTerima kasih atas perhatiannya.\n[Nama Perusahaan Anda]";

            // Ganti placeholder di template dasar dengan data aktual
            $whatsapp_message_content_for_send = str_replace(
                ['{nama_pelanggan}', '{rekap_tunggakan}'],
                [htmlspecialchars($selected_customer_name), $unpaid_message_text],
                $base_billing_message_template
            );
            // Anda masih perlu mengganti placeholder lain seperti {jumlah_tagihan}, {tanggal_jatuh_tempo}
            // dengan data yang relevan dari sistem Anda saat ini.
            // Contoh:
            // $whatsapp_message_content_for_send = str_replace('[Jumlah Tagihan]', 'Rp 150.000', $whatsapp_message_content_for_send);
            // $whatsapp_message_content_for_send = str_replace('[Tanggal Jatuh Tempo]', '30 Juni 2025', $whatsapp_message_content_for_send);

        } else {
            $whatsapp_message_content_for_send = "Pelanggan tidak ditemukan.";
            $customer_id_for_send = null;
        }
        $stmt_selected_customer->close();
    }
}

// Cek pesan status dari `app/api/send_whatsapp.php` jika ada redirect
$whatsapp_send_status_message = '';
$whatsapp_send_status_type = '';
if (isset($_SESSION['whatsapp_send_status'])) {
    $whatsapp_send_status_message = $_SESSION['whatsapp_send_status']['message'];
    $whatsapp_send_status_type = $_SESSION['whatsapp_send_status']['type'];
    unset($_SESSION['whatsapp_send_status']);
}

?>

<?php require_once '../includes/header.php'; ?>

<div class="d-flex" id="wrapper">
    <?php include_once '../includes/sidebar.php'; ?>

    <div id="page-content-wrapper" class="flex-grow-1 mx-2 mx-lg-4 py-lg-4">
        <div class="d-flex justify-content-between align-items-center flex-column flex-lg-row gap-3 m-4 mx-lg-0 mb-4">
            <h1 class="display-5"><?php echo $title; ?></h1>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger mx-4 mx-lg-0">
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        <?php if (!empty($success_message)): ?>
            <div class="alert alert-success mx-4 mx-lg-0"><?php echo htmlspecialchars($success_message); ?></div>
        <?php endif; ?>

        <?php if (!empty($whatsapp_send_status_message)): ?>
            <div class="alert alert-<?php echo $whatsapp_send_status_type == 'success' ? 'success' : 'danger'; ?> mx-4 mx-lg-0">
                <?php echo htmlspecialchars($whatsapp_send_status_message); ?>
            </div>
        <?php endif; ?>

        <div class="card mb-5 mx-4 mx-lg-0">
            <div class="card-header bg-info text-white">
                Kelola Template Pesan WhatsApp
            </div>
            <div class="card-body">
                <p>Ubah isi pesan standar yang akan digunakan sebagai template.</p>
                <form action="manage_whatsapp_messages.php" method="POST">
                    <input type="hidden" name="save_templates" value="1"> <div class="mb-3">
                        <label for="whatsapp_message_new_customer" class="form-label">Pesan Selamat Datang Pelanggan Baru:</label>
                        <textarea class="form-control" id="whatsapp_message_new_customer" name="whatsapp_message_new_customer" rows="8"><?php echo htmlspecialchars($current_templates['whatsapp_message_new_customer'] ?? ''); ?></textarea>
                        <small class="form-text text-muted">Gunakan placeholder: <code>{nama_pelanggan}</code>, <code>{email}</code>, <code>{paket}</code></small>
                    </div>

                    <div class="mb-3">
                        <label for="whatsapp_message_billing" class="form-label">Pesan Notifikasi Penagihan:</label>
                        <textarea class="form-control" id="whatsapp_message_billing" name="whatsapp_message_billing" rows="8"><?php echo htmlspecialchars($current_templates['whatsapp_message_billing'] ?? ''); ?></textarea>
                        <small class="form-text text-muted">Gunakan placeholder: <code>{nama_pelanggan}</code>, <code>{bulan_tagihan}</code>, <code>{jumlah_tagihan}</code>, <code>{tanggal_jatuh_tempo}</code>, <code>{link_portal_pelanggan}</code>. **Placeholder {rekap_tunggakan} akan ditambahkan otomatis di bawah.**</small>
                    </div>

                    <div class="mb-3">
                        <label for="whatsapp_message_invoice" class="form-label">Pesan Pengiriman Invoice:</label>
                        <textarea class="form-control" id="whatsapp_message_invoice" name="whatsapp_message_invoice" rows="8"><?php echo htmlspecialchars($current_templates['whatsapp_message_invoice'] ?? ''); ?></textarea>
                        <small class="form-text text-muted">Gunakan placeholder: <code>{nama_pelanggan}</code>, <code>{nomor_invoice}</code>, <code>{jumlah_total}</code>, <code>{status_pembayaran}</code>, <code>{link_unduh_invoice}</code></small>
                    </div>

                    <div class="mb-3">
 			   <label for="whatsapp_message_payment_confirmation" class="form-label">Pesan Konfirmasi Pembayaran:</label>
			   <textarea class="form-control" id="whatsapp_message_payment_confirmation" name="whatsapp_message_payment_confirmation" rows="8"><?php echo htmlspecialchars($current_templates['whatsapp_message_payment_confirmation'] ?? ''); ?></textarea>
 			   <small class="form-text text-muted">Gunakan placeholder: <code>{nama_pelanggan}</code>, <code>{nomor_invoice}</code>, <code>{description}</code>, <code>{jumlah_pembayaran}</code>, <code>{tanggal_pembayaran}</code>, <code>{metode_pembayaran}</code>, <code>{nomor_referensi}</code></small>
		    </div>
                    <div class="mb-3">
                        <label for="whatsapp_message_down_service_info" class="form-label">Pesan Informasi Gangguan Layanan:</label>
                        <textarea class="form-control" id="whatsapp_message_down_service_info" name="whatsapp_message_down_service_info" rows="8"><?php echo htmlspecialchars($current_templates['whatsapp_message_down_service_info'] ?? ''); ?></textarea>
                        <small class="form-text text-muted">Gunakan placeholder: <code>{nama_pelanggan}</code></small>
                    </div>

                    <button type="submit" class="btn btn-primary">Simpan Template Perubahan</button>
                </form>
            </div>
        </div>

        <div class="card mb-4 mx-4 mx-lg-0">
            <div class="card-header bg-primary text-white">
                Kirim Notifikasi Tagihan WhatsApp ke Pelanggan
            </div>
            <div class="card-body">
                <p>Pilih pelanggan untuk melihat dan mengirim pesan tagihan dinamis.</p>
                <form id="selectCustomerForm" action="manage_whatsapp_messages.php" method="GET">
                    <div class="mb-3">
                        <label for="customerSelect" class="form-label">Pilih Pelanggan:</label>
                        <select class="form-select" id="customerSelect" name="customer_id" required>
                            <option value="">-- Pilih Pelanggan --</option>
                            <?php foreach ($customers as $cust): ?>
                                <option value="<?php echo htmlspecialchars($cust['id']); ?>"
                                    data-phone="<?php echo htmlspecialchars($cust['phone']); ?>"
                                    data-name="<?php echo htmlspecialchars($cust['name']); ?>"
                                    <?php echo ($customer_id_for_send == $cust['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cust['name'] . ' (' . $cust['phone'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </form>

                <form action="../api/send_whatsapp.php" method="POST">
                    <input type="hidden" name="customer_id" value="<?php echo htmlspecialchars($customer_id_for_send ?? ''); ?>">
                    <div class="mb-3">
                        <label for="phoneNumber" class="form-label">Nomor Telepon:</label>
                        <input type="text" class="form-control" id="phoneNumber" name="phone_number" readonly value="<?php echo htmlspecialchars($selected_customer_phone); ?>">
                    </div>

                    <div class="mb-3">
                        <label for="messageContent" class="form-label">Isi Pesan WhatsApp:</label>
                        <textarea class="form-control" id="messageContent" name="message_content" rows="10" required><?php echo htmlspecialchars($whatsapp_message_content_for_send); ?></textarea>
                        <small class="form-text text-muted">Isi pesan ini sudah menyertakan rekap tunggakan. Sesuaikan placeholder lain jika diperlukan.</small>
                    </div>

                    <button type="submit" class="btn btn-success" <?php echo empty($customer_id_for_send) ? 'disabled' : ''; ?>><i class="bi bi-whatsapp me-2"></i>Kirim Pesan WhatsApp</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const customerSelect = document.getElementById('customerSelect');

    customerSelect.addEventListener('change', function() {
        // Ketika pelanggan dipilih, submit form GET untuk memuat ulang halaman
        // dengan pesan yang sudah di-generate
        document.getElementById('selectCustomerForm').submit();
    });
});
</script>
