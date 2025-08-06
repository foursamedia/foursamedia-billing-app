<?php
// public/mitra_details.php
require_once '../includes/db_connect.php';
require_once '../includes/session.php';
require_once '../includes/functions.php'; // Memuat fungsi cleanPhoneNumber()
require_once '../includes/whatsapp_messages.php'; // Memuat semua variabel template pesan

// Pastikan pengguna sudah login
check_login();

// Cek peran untuk mengizinkan akses ke halaman ini.
// Mitra, admin, dan superadmin bisa melihat detail mitra.
check_role(['superadmin', 'admin', 'mitra']);

// Ambil ID pengguna yang ingin dilihat detailnya dari parameter URL
$user_id_from_url = $_GET['id'] ?? 0;

// Ambil ID dan peran pengguna yang sedang login dari sesi
$current_user_id = $_SESSION['user_id'] ?? 0;
$current_role = $_SESSION['role_name'] ?? '';

// --- VALIDASI KEAMANAN UNTUK ROLE 'MITRA' ---
if ($current_role === 'mitra') {
    if ($user_id_from_url != $current_user_id) {
        $_SESSION['error_message'] = "Anda tidak memiliki izin untuk melihat detail mitra lain.";
        header("Location: mitra_details.php?id=" . htmlspecialchars($current_user_id));
        exit();
    }
    if (empty($user_id_from_url)) {
        header("Location: mitra_details.php?id=" . htmlspecialchars($current_user_id));
        exit();
    }
    $user_id_from_url = $current_user_id;
}
// --- AKHIR VALIDASI KEAMANAN ---

// Pastikan ID mitra yang valid diberikan
if (empty($user_id_from_url)) {
    $_SESSION['error_message'] = "ID mitra tidak ditemukan.";
    header("Location: mitra.php");
    exit();
}

// Ambil data mitra dari database, TERMASUK created_at, latitude, dan longitude
// Filter berdasarkan peran 'mitra'
$mitra = null;

// --- PERBAIKAN PADA BAGIAN INI ---
// Gunakan JOIN untuk menggabungkan tabel 'users' dan 'roles'
// Sehingga kita bisa memfilter berdasarkan 'role_name' dari tabel 'roles'.
$stmt = $conn->prepare("SELECT u.id, u.username, u.name, u.email, u.phone, u.address, u.latitude, u.longitude, u.paket, u.created_at FROM users u JOIN roles r ON u.role_id = r.id WHERE u.id = ? AND r.role_name = 'mitra'");
// --- AKHIR PERBAIKAN ---

if ($stmt) {
    $stmt->bind_param("i", $user_id_from_url);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $mitra = $result->fetch_assoc();
        if (isset($_SESSION['error_message'])) {
            unset($_SESSION['error_message']);
        }
    }
    $stmt->close();
}

// Jika mitra tidak ditemukan
if (!$mitra) {
    $_SESSION['error_message'] = "Detail mitra tidak ditemukan atau peran pengguna tidak valid.";
    if ($current_role === 'mitra') {
        header("Location: mitra_details.php?id=" . htmlspecialchars($current_user_id));
    } else {
        header("Location: mitra.php");
    }
    exit();
}

// --- LOGIKA UNTUK STATUS PEMBAYARAN BULANAN DAN PENGHITUNGAN TUNGGAKAN ---
$payments_by_month = [];
$latest_payment_date_obj = null;

$stmt_payments_all = $conn->prepare("SELECT payment_date FROM payments WHERE user_id = ? ORDER BY payment_date ASC");
$stmt_payments_all->bind_param("i", $user_id_from_url);
$stmt_payments_all->execute();
$result_payments_all = $stmt_payments_all->get_result();
while ($row = $result_payments_all->fetch_assoc()) {
    $payment_date = new DateTime($row['payment_date']);
    $month_year = $payment_date->format('Y-m');
    $payments_by_month[$month_year] = true;

    if ($latest_payment_date_obj === null || $payment_date > $latest_payment_date_obj) {
        $latest_payment_date_obj = $payment_date;
    }
}
$stmt_payments_all->close();

$monthly_payment_status = [];
$mitra_start_date_str = $mitra['created_at'];
try {
    $mitra_start_datetime = new DateTime($mitra_start_date_str);
} catch (Exception $e) {
    // Fallback jika created_at tidak valid, gunakan awal tahun saat ini
    $mitra_start_datetime = new DateTime(date('Y-01-01'));
}

$current_datetime = new DateTime();
$period_start = new DateTime($mitra_start_datetime->format('Y-m-01'));
$period_end = new DateTime($current_datetime->format('Y-m-t'));

if ($latest_payment_date_obj !== null) {
    $latest_payment_month_end = new DateTime($latest_payment_date_obj->format('Y-m-t'));
    if ($latest_payment_month_end > $period_end) {
        $period_end = $latest_payment_month_end;
    }
}

$unpaid_months = []; // Ini array yang akan menampung bulan-bulan yang belum dibayar
$total_amount_due_including_arrears = 0; // Variabel baru untuk total tagihan termasuk tunggakan

while ($period_start <= $period_end) {
    $month_year_key = $period_start->format('Y-m');
    $status_paid = isset($payments_by_month[$month_year_key]);

    $monthly_payment_status[$month_year_key] = [
        'month_name' => $period_start->format('M Y'),
        'paid'       => $status_paid
    ];

    // Cek apakah bulan ini belum dibayar DAN bulan ini adalah bulan saat ini atau bulan yang sudah lewat
    // Hindari menandai bulan di masa depan sebagai 'belum dibayar'
    if (!$status_paid && $period_start->format('Y-m') <= $current_datetime->format('Y-m')) {
        $unpaid_months[] = $period_start->format('F Y'); // Tambahkan ke array unpaid_months

        // Tambahkan jumlah paket mitra ke total tunggakan
        $total_amount_due_including_arrears += floatval($mitra['paket']);
    }

    $period_start->modify('+1 month');
}
// --- AKHIR LOGIKA PEMBAYARAN BULANAN DAN PENGHITUNGAN TUNGGAKAN ---

// --- PREPARASI TEKS REKAP TUNGGAKAN UNTUK WHATSAPP ---
$rekap_tunggakan_text = "";
if (!empty($unpaid_months)) {
    $rekap_tunggakan_text = "Anda memiliki tunggakan untuk bulan:\n";
    foreach ($unpaid_months as $month) {
        $rekap_tunggakan_text .= "- " . $month . "\n";
    }
    $rekap_tunggakan_text .= "\nMohon segera lakukan pembayaran untuk bulan-bulan tersebut.";
} else {
    $rekap_tunggakan_text = "Pembayaran Anda lancar sejauh ini. Terima kasih atas ketepatan waktunya!";
}
// --- AKHIR PREPARASI TEKS REKAP TUNGGAKAN ---

// --- ASUMSI DATA UNTUK WHATSAPP TAGIHAN ---
$total_amount_rupiah = $total_amount_due_including_arrears;
$due_date = '25 Juli 2025'; // Contoh: Ambil dari tagihan aktif terakhir
$billing_month = 'Juli 2025'; // Contoh: Ambil dari tagihan aktif terakhir
$portal_link = 'http://10.57.58.21/app/public/login.php'; // Ganti dengan URL portal mitra Anda
// --- AKHIR ASUMSI DATA UNTUK WHATSAPP TAGIHAN ---

// Ambil riwayat pembayaran untuk mitra ini, termasuk nama penginput
$payments = [];
$stmt_payments = $conn->prepare("
    SELECT
        p.id,
        p.amount,
        p.payment_date,
        p.description,
        p.payment_method,
        p.reference_number,
        p.created_at,
        u.name AS input_by_user_name
    FROM
        payments p
    LEFT JOIN
        users u ON p.input_by_user_id = u.id
    WHERE
        p.user_id = ?
    ORDER BY
        p.payment_date DESC
");
if ($stmt_payments) {
    $stmt_payments->bind_param("i", $user_id_from_url);
    $stmt_payments->execute();
    $result_payments = $stmt_payments->get_result();
    while ($row = $result_payments->fetch_assoc()) {
        $payments[] = $row;
    }
    $stmt_payments->close();
}

$title = "Detail Mitra: " . htmlspecialchars($mitra['name']);

require_once '../includes/header.php';
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<style>
    #mapid {
        height: 300px;
        width: 100%;
        border: 1px solid #ccc;
        border-radius: 0.25rem;
        margin-top: 15px;
    }
</style>

<div class="d-flex" id="wrapper">
    <?php include_once '../includes/sidebar.php'; ?>

    <div id="page-content-wrapper" class="flex-grow-1">
        <div class="container-fluid px-4">
            <header class="mb-4">
                <h1 class="display-5">Detail Mitra</h1>
                <p class="lead">Informasi Lengkap untuk: <?php echo htmlspecialchars($mitra['name']); ?></p>
                <?php if ($current_role === 'superadmin' || $current_role === 'admin'): ?>
                    <a href="mitra.php" class="btn btn-secondary btn-sm mb-3"> < Kembali</a>
                <?php endif; ?>
            </header>

            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-danger"><?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?></div>
            <?php endif; ?>
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success"><?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?></div>
            <?php endif; ?>
            <?php if (isset($info_message) && $info_message): ?>
                <div class="alert alert-warning"><?php echo htmlspecialchars($info_message); ?></div>
            <?php endif; ?>

            <div class="row">
                <div class="col-md-6">
                    <div class="card mb-4">
                        <div class="card-header">
                            Informasi Personal
                        </div>
                        <div class="card-body">
                            <p><strong>Username:</strong> <?php echo htmlspecialchars($mitra['username']); ?></p>
                            <p><strong>Nama Lengkap:</strong> <?php echo htmlspecialchars($mitra['name']); ?></p>
                            <p><strong>Email:</strong> <?php echo htmlspecialchars($mitra['email']); ?></p>
                            <p><strong>Telepon:</strong> <span id="mitra_phone_number"><?php echo htmlspecialchars($mitra['phone'] ?? '-'); ?></span></p>
                            <p><strong>Alamat:</strong> <?php echo htmlspecialchars($mitra['address'] ?? '-'); ?></p>
                            <p><strong>Paket:</strong> <?php echo htmlspecialchars($mitra['paket'] ?? '-'); ?></p>
                            <p><strong>Bergabung Sejak:</strong> <?php echo htmlspecialchars((new DateTime($mitra['created_at']))->format('d M Y H:i')); ?></p>

                            <p><strong>Latitude:</strong> <?php echo htmlspecialchars($mitra['latitude'] ?? '-'); ?></p>
                            <p><strong>Longitude:</strong> <?php echo htmlspecialchars($mitra['longitude'] ?? '-'); ?></p>

                            <?php if (!empty($mitra['latitude']) && !empty($mitra['longitude'])): ?>
                                <div class="mb-3">
                                    <label class="form-label">Lokasi Mitra di Peta:</label>
                                    <div id="mapid"></div>
                                </div>
                            <?php else: ?>
                                <div class="alert alert-info mt-3" role="alert">
                                    Koordinat lokasi (Latitude & Longitude) belum tersedia untuk mitra ini.
                                </div>
                            <?php endif; ?>

                            <?php if ($current_role === 'superadmin' || $current_role === 'admin' || $current_role === 'mitra'): ?>
                                <div class="d-flex flex-wrap gap-2 mt-3">
                                    <a href="edit_user.php?id=<?php echo htmlspecialchars($mitra['id']); ?>" class="btn btn-warning btn-sm mb-2">Edit Informasi</a>
                                    <a href="add_payment.php?mitra_id=<?php echo htmlspecialchars($mitra['id']); ?>" class="btn btn-success btn-sm mb-2">Bayar Tagihan</a>
                                    <button type="button" class="btn btn-primary btn-sm mb-2" data-bs-toggle="modal" data-bs-target="#printInvoiceModal">Cetak Invoice</button>

                                    <?php if (!empty($mitra['phone'])): ?>
                                        <button type="button" class="btn btn-success btn-sm mb-2" id="sendBillingWhatsappBtn">
                                            <i class="fab fa-whatsapp"></i> Tagihan WhatsApp
                                        </button>
                                        <div id="whatsapp_status_message" class="mt-2"></div>
                                    <?php else: ?>
                                        <div class="alert alert-warning mt-3">Nomor telepon mitra tidak tersedia untuk mengirim pesan WhatsApp.</div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card mb-4">
                        <div class="card-header">
                            Status Pembayaran Bulanan
                        </div>
                        <div class="card-body">
                            <?php if (empty($monthly_payment_status)): ?>
                                <p class="text-muted">Tidak ada data status pembayaran bulanan yang tersedia.</p>
                            <?php else: ?>
                                <div class="row row-cols-1 row-cols-sm-2 row-cols-md-2 row-cols-lg-2 g-2">
                                    <?php foreach ($monthly_payment_status as $month_data): ?>
                                        <div class="col">
                                            <div class="card h-100 <?= $month_data['paid'] ? 'border-success' : 'border-danger' ?>">
                                                <div class="card-body d-flex justify-content-between align-items-center p-2">
                                                    <h6 class="card-title mb-0 fs-6"><?= htmlspecialchars($month_data['month_name']) ?></h6>
                                                    <?php if ($month_data['paid']): ?>
                                                        <i class="bi bi-check-circle-fill text-success fs-4" title="Sudah Dibayar"></i>
                                                    <?php else: ?>
                                                        <i class="bi bi-x-circle-fill text-danger fs-4" title="Belum Dibayar"></i>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row mt-4">
                <div class="col-12">
                    <div class="card mb-4">
                        <div class="card-header">
                            Riwayat Pembayaran Lengkap
                        </div>
                        <div class="card-body">
                            <?php if (!empty($payments)): ?>
                                <ul class="list-group">
                                    <?php foreach ($payments as $payment): ?>
                                        <li class="list-group-item d-flex justify-content-between align-items-center flex-wrap">
                                            <div>
                                                Pembayaran sejumlah **Rp<?php echo number_format($payment['amount'], 0, ',', '.'); ?>** pada <?php echo htmlspecialchars($payment['payment_date']); ?>
                                                <?php if (!empty($payment['input_by_user_name'])): ?>
                                                    <br><small class="text-muted">Diterima oleh: <?php echo htmlspecialchars($payment['input_by_user_name']); ?></small>
                                                <?php else: ?>
                                                    <br><small class="text-muted">Diterima oleh: Tidak Diketahui</small>
                                                <?php endif; ?>
                                                <br><small class="text-muted"><?php echo htmlspecialchars($payment['description']); ?></small>
                                            </div>
                                            <?php if ($current_role === 'superadmin' || $current_role === 'admin' || $current_role === 'mitra'): ?>
                                                <div class="d-flex gap-2 mt-2 mt-sm-0">
                                                    <a href="edit_payment.php?id=<?php echo htmlspecialchars($payment['id']); ?>" class="btn btn-outline-primary btn-sm">Edit</a>
                                                    <?php if (!empty($mitra['phone'])): ?>
                                                        <button type="button" class="btn btn-outline-success btn-sm send-payment-whatsapp"
                                    data-phone="<?php echo htmlspecialchars($mitra['phone']); ?>"
                                    data-mitra-name="<?php echo htmlspecialchars($mitra['name']); ?>"
                                    data-invoice-number="INV-<?php echo htmlspecialchars($payment['id']); ?>"
                                    data-amount="<?php echo htmlspecialchars($payment['amount']); ?>"
                                    data-payment-date="<?php echo htmlspecialchars($payment['payment_date']); ?>"
                                    data-payment-method="<?php echo htmlspecialchars($payment['payment_method'] ?? 'Transfer'); ?>"
                                    data-reference-number="<?php echo htmlspecialchars($payment['reference_number'] ?? '-'); ?>"
                                    data-description="<?php echo htmlspecialchars($payment['description'] ?? '-'); ?>">
                                    <i class="fab fa-whatsapp"></i> Kirim Notifikasi
</button>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php else: ?>
                                <p class="text-muted">Belum ada riwayat pembayaran.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="printInvoiceModal" tabindex="-1" aria-labelledby="printInvoiceModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="printInvoiceModalLabel">Pilih Bulan Invoice</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label for="invoiceMonth" class="form-label">Bulan:</label>
                    <select class="form-select" id="invoiceMonth">
                        <?php
                        $start_month = (new DateTime($mitra['created_at']))->format('Y-m');
                        $end_month = new DateTime();
                        if ($latest_payment_date_obj !== null && $latest_payment_date_obj->format('Y-m') > $end_month->format('Y-m')) {
                            $end_month = $latest_payment_date_obj;
                        }

                        $temp_month = new DateTime($start_month . '-01');
                        while ($temp_month->format('Y-m') <= $end_month->format('Y-m')) {
                            $selected = ($temp_month->format('Y-m') == date('Y-m')) ? 'selected' : '';
                            echo '<option value="' . $temp_month->format('Y-m') . '" ' . $selected . '>' . $temp_month->format('F Y') . '</option>';
                            $temp_month->modify('+1 month');
                        }
                        ?>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
                <button type="button" class="btn btn-primary" id="generatePrintInvoiceBtn">Cetak Invoice</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="billingMessageModal" tabindex="-1" aria-labelledby="billingMessageModalLabel" aria-hidden="true" style="display: none;">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="billingMessageModalLabel">Pesan Penagihan (Dinonaktifkan)</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <textarea id="generatedBillingMessageContent" style="width: 100%; height: 200px; white-space: pre-wrap; word-wrap: break-word; resize: vertical; border: 1px solid #ccc; padding: 10px;" readonly disabled></textarea>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
            </div>
        </div>
    </div>
</div>


<?php require_once '../includes/footer.php'; ?>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>


<script>
document.addEventListener('DOMContentLoaded', function() {
    const mitraName = <?php echo json_encode($mitra['name'] ?? 'Mitra'); ?>;
    const mitraPhone = <?php echo json_encode($mitra['phone'] ?? ''); ?>;
    const rekapTunggakanText = <?php echo json_encode($rekap_tunggakan_text); ?>; // Ambil teks rekap dari PHP

    // Arahkan ke file proxy API yang benar
    const sendWhatsappApiUrl = '../api/send_whatsapp.php';
    const whatsappStatusMessage = document.getElementById('whatsapp_status_message');

    // --- LEAFLET MAP INTEGRATION START ---
    const latitude = parseFloat('<?php echo $mitra['latitude'] ?? ''; ?>');
    const longitude = parseFloat('<?php echo $mitra['longitude'] ?? ''; ?>');
    const mitraAddress = <?php echo json_encode(htmlspecialchars($mitra['address'] ?? 'Lokasi Mitra')); ?>;
    const mitraNameMap = <?php echo json_encode(htmlspecialchars($mitra['name'] ?? 'Mitra')); ?>;

    if (!isNaN(latitude) && !isNaN(longitude)) {
        const map = L.map('mapid').setView([latitude, longitude], 15);

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
        }).addTo(map);

        L.marker([latitude, longitude])
            .addTo(map)
            .bindPopup(`<b>${mitraNameMap}</b><br>${mitraAddress}`)
            .openPopup();
    } else {
        console.log("Latitude atau Longitude tidak valid atau kosong. Peta tidak ditampilkan.");
    }
    // --- LEAFLET MAP INTEGRATION END ---

    // JavaScript untuk modal cetak invoice
    document.getElementById('generatePrintInvoiceBtn').addEventListener('click', function() {
        const mitraId = <?php echo json_encode($mitra['id']); ?>;
        const selectedMonthYear = document.getElementById('invoiceMonth').value; // e.g., "2025-06"

        if (mitraId && selectedMonthYear) {
            const url = `print_invoice.php?mitra_id=${mitraId}&month=${selectedMonthYear.substring(5, 7)}&year=${selectedMonthYear.substring(0, 4)}`;
            window.open(url, '_blank');
            const modalElement = document.getElementById('printInvoiceModal');
            const modal = bootstrap.Modal.getInstance(modalElement);
            if (modal) {
                modal.hide();
            }
        } else {
            alert('Mohon pilih bulan terlebih dahulu.');
        }
    });

    // =====================================================================================
    // WHATSAPP GATEWAY FONTE INTEGRATION START (DIREVISI UNTUK PROXY)

    // Fungsi untuk mengirim pesan WhatsApp via PHP Proxy Gateway (send_whatsapp.php)
    async function sendWhatsAppMessage(targetPhoneNumber, templateName, replacements) {
        if (!whatsappStatusMessage) {
            console.error("Elemen 'whatsapp_status_message' tidak ditemukan.");
            return;
        }
        whatsappStatusMessage.innerHTML = '<div class="alert alert-info">Mengirim pesan WhatsApp...</div>';

        if (!targetPhoneNumber) {
            whatsappStatusMessage.innerHTML = '<div class="alert alert-danger">Nomor telepon tidak valid.</div>';
            return;
        }

        const requestBody = {
            number: targetPhoneNumber,
            template_name: templateName,
            replacements: replacements
        };

        try {
            const response = await fetch(sendWhatsappApiUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(requestBody)
            });

            const responseText = await response.text();
            let data;
            try {
                data = JSON.parse(responseText);
            } catch (e) {
                whatsappStatusMessage.innerHTML = `<div class="alert alert-danger">Respon tidak valid dari server: ${responseText.substring(0, 100)}...</div>`;
                console.error('JSON Parse Error:', e, 'Raw response:', responseText);
                return;
            }

            if (response.ok && data.status === 'success') {
                whatsappStatusMessage.innerHTML = '<div class="alert alert-success">Pesan WhatsApp berhasil dikirim!</div>';
            } else {
                let errorMessage = data.message || 'Terjadi kesalahan saat mengirim pesan WhatsApp (via proxy).';
                whatsappStatusMessage.innerHTML = `<div class="alert alert-danger">Gagal mengirim pesan WhatsApp: ${errorMessage}</div>`;
                console.error('WhatsApp API Proxy Error:', data);
            }
        } catch (error) {
            whatsappStatusMessage.innerHTML = `<div class="alert alert-danger">Terjadi kesalahan jaringan atau server saat menghubungi proxy: ${error.message}</div>`;
            console.error('WhatsApp Proxy Fetch Error:', error);
        }
    }

    // Event Listener untuk tombol "Tagihan WhatsApp"
    const sendBillingWhatsappBtn = document.getElementById('sendBillingWhatsappBtn');
    if (sendBillingWhatsappBtn) {
        sendBillingWhatsappBtn.addEventListener('click', async function() {
            const phoneNumber = mitraPhone;
            if (!phoneNumber) {
                whatsappStatusMessage.innerHTML = '<div class="alert alert-warning">Nomor telepon mitra tidak tersedia.</div>';
                return;
            }

            // Data untuk mengisi placeholder di template "whatsapp_message_billing"
            const replacements = {
                'nama_mitra': mitraName, // Perubahan di sini: 'nama_mitra'
                'bulan_tagihan': '<?php echo htmlspecialchars($billing_month ?? 'Belum ada data bulan'); ?>',
                'jumlah_tagihan': '<?php echo number_format($total_amount_rupiah ?? 0, 0, ',', '.'); ?>',
                'tanggal_jatuh_tempo': '<?php echo htmlspecialchars($due_date ?? 'N/A'); ?>',
                'link_portal_mitra': '<?php echo htmlspecialchars($portal_link ?? ''); ?>', // Perubahan di sini: 'link_portal_mitra'
                'rekap_tunggakan': rekapTunggakanText
            };

            await sendWhatsAppMessage(phoneNumber, 'whatsapp_message_billing', replacements);
        });
    }

    // Event Listener untuk tombol "Kirim Notifikasi" pada riwayat pembayaran
    document.querySelectorAll('.send-payment-whatsapp').forEach(button => {
        button.addEventListener('click', async function() {
            const phoneNumber = this.dataset.phone;
            const mitraName = this.dataset.mitraName; // Perubahan di sini: 'mitraName'
            const invoiceNumber = this.dataset.invoiceNumber;
            const amount = this.dataset.amount;
            const paymentDate = this.dataset.paymentDate;
            const paymentMethod = this.dataset.paymentMethod;
            const referenceNumber = this.dataset.referenceNumber;
            const description = this.dataset.description;

            if (!phoneNumber) {
                alert('Nomor telepon mitra tidak tersedia untuk notifikasi ini.');
                return;
            }

            // Data untuk mengisi placeholder di template "whatsapp_message_payment_confirmation"
            const replacements = {
                'nama_mitra': mitraName, // Perubahan di sini: 'nama_mitra'
                'nomor_invoice': invoiceNumber,
                'jumlah_pembayaran': new Intl.NumberFormat('id-ID').format(amount),
                'tanggal_pembayaran': paymentDate,
                'metode_pembayaran': paymentMethod,
                'nomor_referensi': referenceNumber,
                'description': description
            };

            await sendWhatsAppMessage(phoneNumber, 'whatsapp_message_payment_confirmation', replacements);
        });
    });
});
</scri