<?php
// public/add_payment.php
require_once '../includes/db_connect.php';
require_once '../includes/session.php';
require_once '../includes/functions.php';
require_once '../includes/whatsapp_messages.php'; // Memuat semua variabel template pesan WhatsApp

// Periksa apakah pengguna yang login memiliki peran yang diizinkan
check_login();
check_role(['superadmin', 'admin', 'teknisi']);

$title = "Tambah Pembayaran Baru";
$error_message = '';
$success_message = '';

// Mengambil ID pengguna target (bisa pelanggan atau mitra) dari parameter URL
// Kode ini sekarang mencari baik 'customer_id' maupun 'mitra_id'
$target_user_id = $_GET['customer_id'] ?? ($_GET['mitra_id'] ?? 0);

$target_user_name = '';
$target_user_phone = '';
$default_amount = '';

// Jika ada ID pengguna di URL, ambil datanya untuk pre-fill form
if ($target_user_id > 0) {
    $stmt_user = $conn->prepare("
        SELECT
            u.id,
            u.name,
            u.username,
            u.phone,
            u.paket,
            r.role_name
        FROM users u
        JOIN roles r ON u.role_id = r.id
        WHERE u.id = ? AND (r.role_name = 'pelanggan' OR r.role_name = 'mitra')
    ");
    $stmt_user->bind_param("i", $target_user_id);
    $stmt_user->execute();
    $result_user = $stmt_user->get_result();

    if ($result_user->num_rows > 0) {
        $user_data = $result_user->fetch_assoc();
        $target_user_name = $user_data['name'];
        $target_user_phone = $user_data['phone'];
        $selected_user_id = $user_data['id'];

        if (!is_null($user_data['paket'])) {
            $default_amount = (float) $user_data['paket'];
        }
    } else {
        $error_message = "Pengguna dengan ID tersebut tidak ditemukan atau peran tidak valid.";
        $target_user_id = 0;
    }
    $stmt_user->close();
}

$users = [];
// Ambil semua pengguna dengan peran 'pelanggan' ATAU 'mitra'
$sql_users = "SELECT u.id, u.name, u.username, u.paket, u.phone, r.role_name
              FROM users u
              JOIN roles r ON u.role_id = r.id
              WHERE r.role_name IN ('pelanggan', 'mitra') ORDER BY u.name ASC";
$result_users = $conn->query($sql_users);
if ($result_users->num_rows > 0) {
    while ($row = $result_users->fetch_assoc()) {
        $users[] = $row;
    }
}


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Ambil user_id dari POST, yang bisa dari form atau hidden input
    $user_id = $_POST['user_id'];
    $amount = filter_input(INPUT_POST, 'amount', FILTER_VALIDATE_FLOAT);
    $payment_date = $_POST['payment_date'];
    $description = trim($_POST['description'] ?? '');
    $payment_method = $_POST['payment_method'] ?? 'Cash';
    $reference_number = trim($_POST['reference_number'] ?? '');
    $input_by_user_id = $_SESSION['user_id'];
    $input_record_date = $_POST['input_record_date'] ?? date('Y-m-d');

    $temp_user_name = '';
    $temp_user_phone = '';
    $temp_user_role = '';
    foreach ($users as $user) {
        if ($user['id'] == $user_id) {
            $temp_user_name = $user['name'];
            $temp_user_phone = $user['phone'];
            $temp_user_role = $user['role_name'];
            break;
        }
    }

    if (empty($user_id) || !is_numeric($user_id)) {
        $error_message = "Pengguna harus dipilih.";
    } elseif ($amount === false || $amount <= 0) {
        $error_message = "Jumlah pembayaran tidak valid.";
    } elseif (empty($payment_date)) {
        $error_message = "Tanggal pembayaran harus diisi.";
    } elseif (empty($input_record_date)) {
        $error_message = "Tanggal input record harus diisi.";
    } elseif ($payment_method === 'Transfer' && empty($reference_number)) {
        $error_message = "Nomor Referensi wajib diisi untuk pembayaran Transfer.";
    } else {
        $stmt_insert = $conn->prepare("INSERT INTO payments (user_id, amount, payment_date, description, payment_method, reference_number, input_by_user_id, input_record_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        if ($stmt_insert) {
            $stmt_insert->bind_param("idssssis", $user_id, $amount, $payment_date, $description, $payment_method, $reference_number, $input_by_user_id, $input_record_date);

            if ($stmt_insert->execute()) {
                $_SESSION['success_message'] = "Pembayaran untuk " . htmlspecialchars($temp_user_name) . " (".$temp_user_role.") berhasil ditambahkan.";
                $new_payment_id = $stmt_insert->insert_id;

                if (!empty($temp_user_phone)) {
                    $cleanedPhone = cleanPhoneNumber($temp_user_phone);

                    if ($cleanedPhone) {
                        $replacements_for_whatsapp = [
                            'nama_pelanggan'    => htmlspecialchars($temp_user_name),
                            'nomor_invoice'     => 'INV-' . htmlspecialchars($new_payment_id),
                            'jumlah_pembayaran' => number_format($amount, 0, ',', '.'),
                            'tanggal_pembayaran'=> (new DateTime($payment_date))->format('d F Y'),
                            'metode_pembayaran' => htmlspecialchars($payment_method),
                            'nomor_referensi'   => htmlspecialchars($reference_number),
                            'description'       => htmlspecialchars($description)
                        ];

                        $whatsapp_api_url = 'http://10.57.58.21/bckpv1.2/api/send_whatsapp.php';

                        $ch = curl_init();
                        
                        $postData = json_encode([
                            'number'        => $cleanedPhone,
                            'template_name' => 'whatsapp_message_payment_confirmation',
                            'replacements'  => $replacements_for_whatsapp
                        ]);

                        curl_setopt_array($ch, [
                            CURLOPT_URL => $whatsapp_api_url,
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_POST => true,
                            CURLOPT_POSTFIELDS => $postData,
                            CURLOPT_HTTPHEADER => [
                                'Content-Type: application/json',
                                'Content-Length: ' . strlen($postData)
                            ],
                            CURLOPT_TIMEOUT => 10,
                            CURLOPT_SSL_VERIFYPEER => false,
                            CURLOPT_SSL_VERIFYHOST => false,
                        ]);

                        $response_whatsapp = curl_exec($ch);
                        $err_whatsapp = curl_error($ch);
                        $http_code_whatsapp = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                        curl_close($ch);

                        if ($err_whatsapp) {
                            error_log("add_payment.php: Gagal mengirim WA konfirmasi pembayaran (CURL Error): " . $err_whatsapp);
                            $_SESSION['info_message'] = ($_SESSION['info_message'] ?? '') . " Gagal mengirim pesan WA konfirmasi pembayaran (CURL Error).";
                        } else {
                            $api_response = json_decode($response_whatsapp, true);
                            if ($api_response && isset($api_response['status']) && $api_response['status'] === 'success') {
                                error_log("add_payment.php: Pesan WA konfirmasi pembayaran berhasil dikirim ke " . $cleanedPhone);
                                $_SESSION['success_message'] .= " Pesan WA konfirmasi pembayaran berhasil dikirim!";
                            } else {
                                $error_msg = $api_response['message'] ?? 'Respon tidak diketahui dari WA API proxy.';
                                error_log("add_payment.php: Gagal mengirim WA konfirmasi pembayaran: " . $error_msg . " (HTTP: " . $http_code_whatsapp . ") Raw: " . $response_whatsapp);
                                $_SESSION['info_message'] = ($_SESSION['info_message'] ?? '') . " Gagal mengirim pesan WA konfirmasi pembayaran: " . $error_msg;
                            }
                        }
                    } else {
                        $_SESSION['info_message'] = ($_SESSION['info_message'] ?? '') . " Nomor telepon tidak valid untuk mengirim konfirmasi pembayaran.";
                    }
                } else {
                    $_SESSION['info_message'] = ($_SESSION['info_message'] ?? '') . " Nomor telepon pengguna tidak tersedia untuk mengirim konfirmasi pembayaran.";
                }

                header("Location: customer_details.php?id=" . htmlspecialchars($user_id));
                exit();
            } else {
                $error_message = "Gagal menambahkan pembayaran: " . $stmt_insert->error;
            }
            $stmt_insert->close();
        } else {
            $error_message = "Gagal menyiapkan statement: " . $conn->error;
        }
    }
}


require_once '../includes/header.php';
?>

<div class="d-flex" id="wrapper">
    <?php include_once '../includes/sidebar.php'; ?>
    <div id="page-content-wrapper" class="flex-grow-1">
        <div class="container-fluid px-4">
            <header class="mb-4">
                <h1 class="display-5">Tambah Pembayaran Baru</h1>
                <?php if ($target_user_id): ?>
                    <a href="customer_details.php?id=<?php echo htmlspecialchars($target_user_id); ?>" class="btn btn-secondary">Kembali ke Detail Pengguna</a>
                <?php else: ?>
                    <a href="dashboard.php" class="btn btn-secondary">Kembali ke Dashboard</a>
                <?php endif; ?>
            </header>

            <div class="card mb-4">
                <div class="card-body">
                    <?php if ($error_message): ?>
                        <div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div>
                    <?php endif; ?>
                    <?php if (isset($_SESSION['success_message'])): ?>
                        <div class="alert alert-success"><?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?></div>
                    <?php endif; ?>
                    <?php if (isset($_SESSION['info_message'])): ?>
                        <div class="alert alert-warning"><?php echo $_SESSION['info_message']; unset($_SESSION['info_message']); ?></div>
                    <?php endif; ?>

                    <form action="add_payment.php<?php echo $target_user_id ? '?customer_id=' . htmlspecialchars($target_user_id) : ''; ?>" method="POST">
                        <div class="mb-3">
                            <label for="user_id" class="form-label">Pilih Pengguna</label>
                            <select class="form-select" id="user_id" name="user_id" required <?php echo ($target_user_id ? 'disabled' : ''); ?>>
                                <?php if (!$target_user_id): ?>
                                    <option value="">Pilih Pengguna</option>
                                <?php endif; ?>
                                <?php foreach ($users as $user): ?>
                                    <option
                                        value="<?php echo htmlspecialchars($user['id']); ?>"
                                        data-package-price="<?php echo htmlspecialchars($user['paket'] ?? ''); ?>"
                                        data-user-phone="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>"
                                        data-user-name="<?php echo htmlspecialchars($user['name'] ?? ''); ?>"
                                        <?php echo ($target_user_id == $user['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($user['name'] . ' (' . $user['role_name'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($target_user_id): ?>
                                <input type="hidden" name="user_id" value="<?php echo htmlspecialchars($target_user_id); ?>">
                                <small class="form-text text-muted">Pengguna: **<?php echo htmlspecialchars($target_user_name); ?>** (dipilih dari URL)</small>
                            <?php endif; ?>
                        </div>

                        <div class="mb-3">
                            <label for="amount" class="form-label">Jumlah Pembayaran (Rp)</label>
                            <input type="number" class="form-control" id="amount" name="amount" step="0.01" min="0" required
                                value="<?php echo htmlspecialchars($_POST['amount'] ?? $default_amount ?? ''); ?>">
                        </div>
                        <div class="mb-3">
                            <label for="payment_date" class="form-label">Tanggal Pembayaran (Tanggal Efektif Pembayaran)</label>
                            <input type="datetime-local" class="form-control" id="payment_date" name="payment_date" required value="<?php echo htmlspecialchars($_POST['payment_date'] ?? date('Y-m-d\TH:i')); ?>">
                            <small class="form-text text-muted">Tanggal ini adalah tanggal pengguna melakukan pembayaran.</small>
                        </div>

                        <div class="mb-3">
                            <label for="input_record_date" class="form-label">Tanggal Input Record (Tanggal Pencatatan)</label>
                            <input type="datetime-local" class="form-control" id="input_record_date" name="input_record_date" required value="<?php echo htmlspecialchars($_POST['input_record_date'] ?? date('Y-m-d\TH:i')); ?>" readonly>
                            <small class="form-text text-muted">Tanggal ini adalah tanggal pembayaran dicatat dalam sistem, tidak bisa diubah.</small>
                        </div>

                        <div class="mb-3">
                            <label for="payment_method" class="form-label">Metode Pembayaran</label>
                            <select class="form-select" id="payment_method" name="payment_method" required>
                                <option value="Cash" <?php echo ((isset($_POST['payment_method']) && $_POST['payment_method'] == 'Cash') || (!isset($_POST['payment_method']))) ? 'selected' : ''; ?>>Cash</option>
                                <option value="Transfer" <?php echo (isset($_POST['payment_method']) && $_POST['payment_method'] == 'Transfer') ? 'selected' : ''; ?>>Transfer</option>
                            </select>
                        </div>

                        <div class="mb-3" id="reference_number_group" style="display: none;">
                            <label for="reference_number" class="form-label">Nomor Referensi Transfer</label>
                            <input type="text" class="form-control" id="reference_number" name="reference_number" value="<?php echo htmlspecialchars($_POST['reference_number'] ?? ''); ?>">
                            <small class="form-text text-muted">Wajib diisi jika metode pembayaran adalah Transfer.</small>
                        </div>

                        <div class="mb-3">
                            <label for="description" class="form-label">Deskripsi (Opsional)</label>
                            <textarea class="form-control" id="description" name="description" rows="3"><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
                        </div>
                        <button type="submit" class="btn btn-primary">Simpan Pembayaran</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const paymentMethodSelect = document.getElementById('payment_method');
    const referenceNumberGroup = document.getElementById('reference_number_group');
    const referenceNumberInput = document.getElementById('reference_number');
    
    const userIdSelect = document.getElementById('user_id');    
    const amountInput = document.getElementById('amount');

    function toggleReferenceNumberField() {
        if (paymentMethodSelect.value === 'Transfer') {
            referenceNumberGroup.style.display = 'block';
            referenceNumberInput.setAttribute('required', 'required');
        } else {
            referenceNumberGroup.style.display = 'none';
            referenceNumberInput.removeAttribute('required');
            referenceNumberInput.value = '';
        }
    }

    function updateAmountFromPackage() {
        if (userIdSelect && !userIdSelect.disabled) {    
            const selectedOption = userIdSelect.options[userIdSelect.selectedIndex];
            const packagePrice = selectedOption.getAttribute('data-package-price');
            
            if (packagePrice) {
                amountInput.value = packagePrice;
            } else {
                amountInput.value = '';    
            }
        }
    }

    toggleReferenceNumberField();
    
    if (userIdSelect && !userIdSelect.disabled && !amountInput.value) {
        updateAmountFromPackage();
    }


    paymentMethodSelect.addEventListener('change', toggleReferenceNumberField);

    if (userIdSelect && !userIdSelect.disabled) {    
        userIdSelect.addEventListener('change', updateAmountFromPackage);
    }
});
</script>

<?php require_once '../includes/footer.php'; ?>
