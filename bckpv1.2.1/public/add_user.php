<?php
// public/add_user.php
require_once '../includes/db_connect.php';
require_once '../includes/session.php';
require_once '../includes/functions.php'; // Memuat fungsi cleanPhoneNumber()
require_once '../includes/whatsapp_messages.php'; // Memuat semua variabel template pesan

check_login();
check_role(['superadmin', 'admin']);

// Set judul halaman
$title = "Tambah Pengguna Baru";

$roles = [];
$sql_roles = "SELECT id, role_name FROM roles ORDER BY role_name ASC";
$result_roles = $conn->query($sql_roles);
if ($result_roles->num_rows > 0) {
    while ($row = $result_roles->fetch_assoc()) {
        $roles[] = $row;
    }
}

$errors = []; // Menggunakan array untuk error
$success_message = ''; // Tetap menggunakan ini untuk pesan sukses

// Mengisi ulang nilai POST jika ada error, agar form tidak kosong
$old_input = [
    'name' => '',
    'email' => '',
    'username' => '',
    'phone' => '',
    'address' => '',
    'latitude' => '',
    'longitude' => '',
    'paket' => '',
    'role_id' => ''
];

// Fungsi untuk menormalisasi nama menjadi bagian email yang valid
function normalizeNameForEmail($name) {
    // Konversi ke huruf kecil
    $normalized = strtolower($name);
    // Ganti spasi dengan titik
    $normalized = str_replace(' ', '.', $normalized);
    // Hapus karakter yang tidak valid untuk email (hanya biarkan a-z, 0-9, dan titik)
    $normalized = preg_replace('/[^a-z0-9.]/', '', $normalized);
    // Hapus titik ganda atau titik di awal/akhir jika ada
    $normalized = trim($normalized, '.');
    $normalized = preg_replace('/\.\.+/', '.', $normalized);
    return $normalized;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    // Baris ini tidak lagi diperlukan, karena email akan selalu digenerate
    // $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $role_id = $_POST['role_id'] ?? '';
    $username = trim($_POST['username'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $latitude = trim($_POST['latitude'] ?? '');
    $longitude = trim($_POST['longitude'] ?? '');
    $paket = trim($_POST['paket'] ?? '');

    // Ambil nama peran berdasarkan role_id yang dipilih
    $selected_role_name = '';
    foreach ($roles as $role) {
        if ($role['id'] == $role_id) {
            $selected_role_name = $role['role_name'];
            break;
        }
    }

// --- LOGIKA GENERASI EMAIL UNTUK SEMUA PENGGUNA DARI NAMA ---
$email = ''; // Inisialisasi email di sini
if (!empty($name)) {
    $generated_email_prefix = normalizeNameForEmail($name);
    $base_email = $generated_email_prefix . '@foursamedia.id'; // Domain email Anda
    $email = $base_email; // Set email awal

    // Cek apakah email yang digenerate sudah ada di database
    $counter = 0;
    while (true) {
        $stmt_check_email_generated = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $stmt_check_email_generated->bind_param("s", $email);
        $stmt_check_email_generated->execute();
        $stmt_check_email_generated->store_result();
        if ($stmt_check_email_generated->num_rows > 0) {
            // Jika sudah ada, tambahkan angka ke prefix dan coba lagi
            $counter++;
            // Pastikan untuk membangun kembali email dari prefix asli + counter
            $email = normalizeNameForEmail($name) . $counter . '@foursamedia.id';
            $stmt_check_email_generated->close(); // Tutup statement sebelum loop berikutnya
        } else {
            $stmt_check_email_generated->close();
            break; // Email unik ditemukan
        }
    }
    // Penting: update $_POST['email'] di sini, setelah loop selesai dan email unik ditemukan
    $_POST['email'] = $email;
} else {
    // Jika nama kosong, email tidak bisa digenerate, tambahkan error
    $errors[] = "Nama Pengguna harus diisi untuk menggenerasi email."; // Pesan error diperbarui
    $email = ''; // Pastikan email kosong jika nama kosong
}
// --- AKHIR LOGIKA GENERASI EMAIL ---

    // Simpan input ke $old_input untuk mengisi ulang form (termasuk email yang digenerate)
    $old_input = [
        'name' => $name,
        'email' => $email, // Menggunakan email yang mungkin sudah digenerate
        'username' => $username,
        'phone' => $phone,
        'address' => $address,
        'latitude' => $latitude,
        'longitude' => $longitude,
        'paket' => $paket,
        'role_id' => $role_id
    ];

    // Validasi dasar
    if (empty($name)) {
        $errors[] = "Nama Pengguna harus diisi.";
    }
    
    // Validasi email: Cukup periksa format jika email sudah terisi (setelah digenerate).
    // Pengecekan keunikan sudah dilakukan di bagian generasi email di atas.
if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Format email tidak valid (terjadi kesalahan internal dalam generasi email).";
    }

    if (empty($password)) {
        $errors[] = "Password harus diisi.";
    } elseif (strlen($password) < 6) {
        $errors[] = "Password minimal 6 karakter.";
    }
    if (empty($role_id)) {
        $errors[] = "Peran harus dipilih.";
    }    // --- VALIDASI TAMBAHAN UNTUK ROLE 'pelanggan' ---
    if ($selected_role_name === 'pelanggan') {
        if (empty($username)) {
            $errors[] = "ID Pelanggan wajib diisi untuk peran 'Pelanggan'.";
        }
        if (empty($phone)) {
            $errors[] = "Nomor Telepon wajib diisi untuk peran 'Pelanggan'.";
        }
        if (empty($address)) {
            $errors[] = "Alamat wajib diisi untuk peran 'Pelanggan'.";
        }
        if (empty($paket)) {
            $errors[] = "Paket wajib diisi untuk peran 'Pelanggan'.";
        }
    }
    // --- AKHIR VALIDASI TAMBAHAN ---

    // Validasi format latitude dan longitude jika diisi
    if (!empty($latitude) && (!is_numeric($latitude) || floatval($latitude) < -90 || floatval($latitude) > 90)) {
        $errors[] = "Lintang (Latitude) harus berupa angka antara -90 dan 90.";
    }
    if (!empty($longitude) && (!is_numeric($longitude) || floatval($longitude) < -180 || floatval($longitude) > 180)) {
        $errors[] = "Bujur (Longitude) harus berupa angka antara -180 dan 180.";
    }

    if (empty($errors)) { // Lanjutkan hanya jika tidak ada error dari validasi awal
        // Cek apakah username sudah terdaftar jika username diisi (username UNIK)
        if (!empty($username)) {
            $stmt_check_username = $conn->prepare("SELECT id FROM users WHERE username = ?");
            $stmt_check_username->bind_param("s", $username);
            $stmt_check_username->execute();
            $stmt_check_username->store_result();
            if ($stmt_check_username->num_rows > 0) {
                $errors[] = "ID Pelanggan ini sudah terdaftar.";
            }
            $stmt_check_username->close();
        }

        // --- VALIDASI UNTUK NOMOR HANDPHONE UNIK ---
        if (!empty($phone)) {
            $stmt_check_phone = $conn->prepare("SELECT id FROM users WHERE phone = ?");
            $stmt_check_phone->bind_param("s", $phone);
            $stmt_check_phone->execute();
            $stmt_check_phone->store_result();
            if ($stmt_check_phone->num_rows > 0) {
                $errors[] = "Nomor telepon ini sudah terdaftar.";
            }
            $stmt_check_phone->close();
        }
        // --- AKHIR VALIDASI NOMOR HANDPHONE UNIK ---

        if (empty($errors)) {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            
            // --- LOGIKA PENENTUAN created_at BARU ---
            $current_date_obj = new DateTime();
            $day = (int)$current_date_obj->format('d');

            if ($day >= 20 && $day <= 31) {
                // Jika tanggal 20-31, set ke tanggal 1 bulan berikutnya
                $current_date_obj->modify('+1 month');
                $current_date_obj->setDate($current_date_obj->format('Y'), $current_date_obj->format('m'), 1);
                $created_at = $current_date_obj->format('Y-m-d H:i:s');
            } else {
                // Jika tanggal 1-19, gunakan tanggal saat ini
                $created_at = date('Y-m-d H:i:s');
            }
            // --- AKHIR LOGIKA PENENTUAN created_at ---

            // Siapkan latitude dan longitude untuk INSERT
            $latitude_db = !empty($latitude) ? floatval($latitude) : NULL;
            $longitude_db = !empty($longitude) ? floatval($longitude) : NULL;

            // --- QUERY INSERT BARU DENGAN LATITUDE DAN LONGITUDE ---
            $stmt_insert = $conn->prepare("INSERT INTO users (name, email, username, password, role_id, phone, address, latitude, longitude, paket, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            
            if ($stmt_insert) {
                $stmt_insert->bind_param("ssssisssdds",
                    $name, $email, $username, $hashed_password, $role_id, $phone, $address, $latitude_db, $longitude_db, $paket, $created_at);

                if ($stmt_insert->execute()) {
                    $_SESSION['success_message'] = "Pengguna baru berhasil ditambahkan.";
                    $new_user_id = $stmt_insert->insert_id; // Dapatkan ID pengguna yang baru saja ditambahkan

                    // --- LOGIKA NOTIFIKASI PELANGGAN BARU UNTUK ADMIN ---
                    if ($selected_role_name === 'pelanggan') {
                        $new_user_name = $name;

                        // Ambil semua user_id yang memiliki peran 'admin' atau 'superadmin'
                        $admin_user_ids_to_notify = [];
                        $stmt_get_admin_roles = $conn->prepare("SELECT id FROM roles WHERE role_name IN ('admin', 'superadmin')");
                        if ($stmt_get_admin_roles) {
                            $stmt_get_admin_roles->execute();
                            $result_admin_roles = $stmt_get_admin_roles->get_result();
                            while ($role_row = $result_admin_roles->fetch_assoc()) {
                                $stmt_get_admin_users = $conn->prepare("SELECT id FROM users WHERE role_id = ?");
                                if ($stmt_get_admin_users) {
                                    $stmt_get_admin_users->bind_param("i", $role_row['id']);
                                    $stmt_get_admin_users->execute();
                                    $result_admin_users = $stmt_get_admin_users->get_result();
                                    while ($admin_user = $result_admin_users->fetch_assoc()) {
                                        $admin_user_ids_to_notify[] = $admin_user['id'];
                                    }
                                    $stmt_get_admin_users->close();
                                }
                            }
                            $stmt_get_admin_roles->close();
                        }
                        
                        // Masukkan notifikasi untuk setiap user admin/superadmin
                        if (!empty($admin_user_ids_to_notify)) {
                            $message = "Pelanggan baru **" . htmlspecialchars($new_user_name) . "** (ID Pelanggan: " . htmlspecialchars($username) . ") telah ditambahkan.";
                            $link = "user_details.php?id=" . $new_user_id; // Sesuaikan dengan halaman detail pengguna Anda

                            $stmt_insert_notification = $conn->prepare("INSERT INTO notifications (user_id, message, link) VALUES (?, ?, ?)");
                            if ($stmt_insert_notification) {
                                foreach ($admin_user_ids_to_notify as $target_user_id) {
                                    $stmt_insert_notification->bind_param("iss", $target_user_id, $message, $link);
                                    $stmt_insert_notification->execute();
                                }
                                $stmt_insert_notification->close();
                            }
                        }

                        // =========================================================
                        // LOGIKA PENGIRIMAN PESAN WHATSAPP UNTUK PELANGGAN BARU
                        // =========================================================
                        if (!empty($phone)) {
                            // Bersihkan nomor telepon sebelum dikirim
                            $cleanedPhone = cleanPhoneNumber($phone);

                            // Siapkan data pengganti (replacements) untuk template pesan
                            $replacements_for_whatsapp = [
                                'nama_pelanggan' => htmlspecialchars($name),
                                'email'          => htmlspecialchars($email),
                                'username'       => htmlspecialchars($username), // ID Pelanggan
                                'paket'          => htmlspecialchars($paket),
                                'link_portal_pelanggan' => 'http://10.57.58.21/app/public/login.php' // Ganti dengan URL portal login Anda
                            ];

                            // URL ke API sentral pengirim WhatsApp
                            $whatsapp_api_url = 'http://10.57.58.21/app/api/send_whatsapp.php';

                            $ch = curl_init();
                            
                            $postData = json_encode([
                                'number'        => $cleanedPhone,
                                'template_name' => 'whatsapp_message_new_customer', // Nama template yang digunakan
                                'replacements'  => $replacements_for_whatsapp // Data untuk mengisi placeholder
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
                                CURLOPT_SSL_VERIFYPEER => false, // HANYA UNTUK DEV, set TRUE di PRODUKSI
                                CURLOPT_SSL_VERIFYHOST => false, // HANYA UNTUK DEV, set 2 di PRODUKSI
                            ]);

                            $response_whatsapp = curl_exec($ch);
                            $err_whatsapp = curl_error($ch);
                            $http_code_whatsapp = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                            curl_close($ch);

                            if ($err_whatsapp) {
                                error_log("add_user.php: Gagal mengirim WA selamat datang (CURL Error): " . $err_whatsapp);
                                $_SESSION['info_message'] = ($_SESSION['info_message'] ?? '') . " Gagal mengirim pesan WA selamat datang (CURL Error).";
                            } else {
                                $api_response = json_decode($response_whatsapp, true);
                                if ($api_response && isset($api_response['status']) && $api_response['status'] === 'success') {
                                    error_log("add_user.php: Pesan WA selamat datang berhasil dikirim ke " . $cleanedPhone);
                                    $_SESSION['success_message'] .= " Pesan WA selamat datang berhasil dikirim!";
                                } else {
                                    $error_msg = $api_response['message'] ?? 'Respon tidak diketahui dari WA API proxy.';
                                    error_log("add_user.php: Gagal mengirim WA selamat datang: " . $error_msg . " (HTTP: " . $http_code_whatsapp . ") Raw: " . $response_whatsapp);
                                    $_SESSION['info_message'] = ($_SESSION['info_message'] ?? '') . " Gagal mengirim pesan WA selamat datang: " . $error_msg;
                                }
                            }
                        } else {
                            $_SESSION['info_message'] = ($_SESSION['info_message'] ?? '') . " Nomor telepon tidak tersedia untuk mengirim pesan WA selamat datang.";
                        }
                        // =========================================================
                        // AKHIR LOGIKA PENGIRIMAN PESAN WHATSAPP
                        // =========================================================
                    }
                    // --- AKHIR LOGIKA NOTIFIKASI PELANGGAN BARU UNTUK ADMIN ---

                    header("Location: manage_users.php?status=success_add");
                    exit();
                } else {
                    $errors[] = "Gagal menambahkan pengguna: " . $stmt_insert->error;
                }
                $stmt_insert->close();
            } else {
                $errors[] = "Gagal menyiapkan statement: " . $conn->error;
            }
        }
    }
}

// Bagian HTML dimulai dari sini
?>
<?php require_once '../includes/header.php'; ?>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<style>
    #mapid {
        height: 400px; /* Atur tinggi peta sesuai kebutuhan */
        width: 100%;
        border: 1px solid #ccc;
        border-radius: 0.25rem;
        margin-top: 10px;
    }
</style>

<div class="d-flex" id="wrapper">
    <?php include_once '../includes/sidebar.php'; ?>

    <div id="page-content-wrapper" class="flex-grow-1">
        <div class="container-fluid px-4">
            <header class="mb-4">
                <h1 class="display-5">Tambah Pengguna Baru</h1>
                <a href="manage_users.php" class="btn btn-secondary">Kembali ke Manajemen Pengguna</a>
            </header>

            <div class="card mb-4">
                <div class="card-body">
                    <?php if (!empty($errors)): // Menampilkan semua error jika ada ?>
                        <div class="alert alert-danger">
                            <ul>
                                <?php foreach ($errors as $error): ?>
                                    <li><?php echo htmlspecialchars($error); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                    <?php if (isset($_SESSION['success_message'])): ?>
                        <div class="alert alert-success"><?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?></div>
                    <?php endif; ?>
                    <?php if (isset($_SESSION['info_message'])): ?>
                        <div class="alert alert-warning"><?php echo $_SESSION['info_message']; unset($_SESSION['info_message']); ?></div>
                    <?php endif; ?>

                    <form action="add_user.php" method="POST" id="addUserForm">
                        <div class="mb-3">
                            <label for="name" class="form-label">Nama Pengguna</label>
                            <input type="text" class="form-control" id="name" name="name" required value="<?php echo htmlspecialchars($old_input['name']); ?>">
                        </div>

                        <div class="mb-3">
                            <label for="role_id" class="form-label">Peran (Role)</label>
                            <select class="form-select" id="role_id" name="role_id" required>
                                <option value="">Pilih Peran</option>
                                <?php
                                $initial_selected_role_name = '';
                                foreach ($roles as $role):
                                    if ($old_input['role_id'] == $role['id']) {
                                        $initial_selected_role_name = $role['role_name'];
                                    }
                                ?>
                                    <option value="<?php echo htmlspecialchars($role['id']); ?>" data-role-name="<?php echo htmlspecialchars($role['role_name']); ?>" <?php echo ($old_input['role_id'] == $role['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($role['role_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label for="email" class="form-label">Email</label>
                            <input type="email" class="form-control" id="email" name="email"
                                value="<?php echo htmlspecialchars($old_input['email']); ?>"
                                data-original-email="<?php echo htmlspecialchars($old_input['email']); ?>"
                                <?php echo ($initial_selected_role_name === 'pelanggan') ? 'readonly' : 'required'; ?>
                            >
                            <small class="form-text text-muted" id="emailHelpText">
                                <?php
                                if ($initial_selected_role_name === 'pelanggan') {
                                    echo 'Email akan digenerate otomatis dari Nama Pengguna untuk peran Pelanggan.';
                                } else {
                                    echo 'Diisi manual.';
                                }
                                ?>
                            </small>
                        </div>
                        <div class="mb-3">
                            <label for="username" class="form-label">ID Pelanggan</label>
                            <input type="text" class="form-control" id="username" name="username" value="<?php echo htmlspecialchars($old_input['username']); ?>">
                            <small class="form-text text-muted" id="usernameHelp">Opsional, harus unik jika diisi.</small>
                        </div>
                        <div class="mb-3">
                            <label for="password" class="form-label">Password</label>
                            <input type="password" class="form-control" id="password" name="password" required>
                        </div>
                        <div class="mb-3">
                            <label for="phone" class="form-label">Nomor Telepon</label>
                            <input type="text" class="form-control" id="phone" name="phone" value="<?php echo htmlspecialchars($old_input['phone']); ?>">
                            <small class="form-text text-muted" id="phoneHelp">Opsional.</small>
                        </div>
                        <div class="mb-3">
                            <label for="address" class="form-label">Alamat</label>
                            <textarea class="form-control" id="address" name="address" rows="3"><?php echo htmlspecialchars($old_input['address']); ?></textarea>
                            <small class="form-text text-muted" id="addressHelp">Opsional.</small>
                        </div>

                        <div class="mb-3">
                            <label for="latitude" class="form-label">Lintang (Latitude)</label>
                            <input type="text" class="form-control" id="latitude" name="latitude" value="<?php echo htmlspecialchars($old_input['latitude']); ?>" placeholder="Pilih dari peta">
                            <small class="form-text text-muted">Akan terisi otomatis dari peta. Rentang -90 hingga 90.</small>
                        </div>
                        <div class="mb-3">
                            <label for="longitude" class="form-label">Bujur (Longitude)</label>
                            <input type="text" class="form-control" id="longitude" name="longitude" value="<?php echo htmlspecialchars($old_input['longitude']); ?>" placeholder="Pilih dari peta">
                            <small class="form-text text-muted">Akan terisi otomatis dari peta. Rentang -180 hingga 180.</small>
                        </div>

                        <div class="mb-3">
                            <label for="mapid" class="form-label">Pilih Lokasi di Peta</label>
                            <div id="mapid"></div>
                            <small class="form-text text-muted">Seret penanda atau klik pada peta untuk menetapkan lokasi.</small>
                        </div>
                        <div class="mb-3">
                            <label for="paket" class="form-label">Paket</label>
                            <input type="text" class="form-control" id="paket" name="paket" value="<?php echo htmlspecialchars($old_input['paket']); ?>">
                            <small class="form-text text-muted" id="paketHelp">Opsional.</small>
                        </div>
                        <button type="submit" class="btn btn-primary">Tambah Pengguna</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>


<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const roleSelect = document.getElementById('role_id');
    const nameInput = document.getElementById('name'); // Get reference to name input
    const emailInput = document.getElementById('email');
    const emailHelpText = document.getElementById('emailHelpText');
    const usernameInput = document.getElementById('username');
    const phoneInput = document.getElementById('phone');
    const addressInput = document.getElementById('address');
    const latitudeInput = document.getElementById('latitude');
    const longitudeInput = document.getElementById('longitude');
    const paketInput = document.getElementById('paket');
    const form = document.getElementById('addUserForm');
    const mapDiv = document.getElementById('mapid');

    // Function to normalize name for email prefix (JS version)
    function normalizeNameForEmailJS(name) {
        let normalized = name.toLowerCase();
        normalized = normalized.replace(/ /g, '.'); // Replace spaces with dots
        normalized = normalized.replace(/[^a-z0-9.]/g, ''); // Remove non-alphanumeric and non-dot characters
        normalized = normalized.replace(/^\.+|\.+$/g, ''); // Remove leading/trailing dots
        normalized = normalized.replace(/\.\.+/g, '.'); // Replace multiple dots with a single dot
        return normalized;
    }

    function handleEmailFieldAndRequiredFields() {
        const selectedOption = roleSelect.options[roleSelect.selectedIndex];
        const roleName = selectedOption ? selectedOption.dataset.roleName : '';
        const isPelanggan = (roleName === 'pelanggan');

        // --- Handle Email Field ---
        if (isPelanggan) {
            const userName = nameInput.value.trim();
            if (userName) {
                const generatedEmailPrefix = normalizeNameForEmailJS(userName);
                emailInput.value = generatedEmailPrefix + '@foursamedia.id';
            } else {
                emailInput.value = ''; // Clear email if name is empty
            }
            emailInput.readOnly = true; // Make it read-only
            emailInput.removeAttribute('required'); // Email is generated, not manually required
            emailHelpText.textContent = 'Email digenerate otomatis dari Nama Pengguna untuk peran Pelanggan.';
        } else {
            // Restore original state for other roles
            emailInput.value = emailInput.dataset.originalEmail; // Restore value if it was pre-filled by PHP
            emailInput.readOnly = false; // Make it editable
            emailInput.required = true; // Re-add required for manual input
            emailHelpText.textContent = 'Diisi manual.';
        }

        // --- Handle Other Required Fields for 'pelanggan' ---
        usernameInput.required = isPelanggan;
        phoneInput.required = isPelanggan;
        addressInput.required = isPelanggan;
        paketInput.required = isPelanggan;

        // Update help text for other fields
        document.getElementById('usernameHelp').textContent = isPelanggan ? 'Wajib diisi untuk peran Pelanggan.' : 'Opsional, harus unik jika diisi.';
        document.getElementById('phoneHelp').textContent = isPelanggan ? 'Wajib diisi untuk peran Pelanggan.' : 'Opsional.';
        document.getElementById('addressHelp').textContent = isPelanggan ? 'Wajib diisi untuk peran Pelanggan.' : 'Opsional.';
        document.getElementById('paketHelp').textContent = isPelanggan ? 'Wajib diisi untuk peran Pelanggan.' : 'Opsional.';
    }

    // Panggil saat DOM dimuat
    handleEmailFieldAndRequiredFields();
    // Panggil saat perubahan role
    roleSelect.addEventListener('change', handleEmailFieldAndRequiredFields);
    // Panggil saat nama pengguna berubah (untuk update email secara real-time)
    nameInput.addEventListener('input', function() {
        // Hanya update email jika peran saat ini adalah 'pelanggan'
        const selectedOption = roleSelect.options[roleSelect.selectedIndex];
        const roleName = selectedOption ? selectedOption.dataset.roleName : '';
        if (roleName === 'pelanggan') {
            handleEmailFieldAndRequiredFields();
        }
    });
    // Panggil saat submit form untuk memastikan validasi terakhir
    form.addEventListener('submit', function(event) {
        // Re-evaluate the required state just before submission
        handleEmailFieldAndRequiredFields();
    });


    // --- LEAFLET MAP INTEGRATION START ---
    let map;
    let marker;
    // Default koordinat jika geolocation gagal (Dampit, Kab Malang)
    const defaultLat = -8.21036;
    const defaultLng = 112.76483;
    const defaultZoom = 14; // Zoom untuk lokasi default

    function updateCoordinates(lat, lng) {
        latitudeInput.value = lat.toFixed(8);
        longitudeInput.value = lng.toFixed(8);
    }

    function initializeMap(lat, lng, zoom) {
        // Hapus peta yang sudah ada jika ada
        if (map) {
            map.remove();
        }
        map = L.map('mapid').setView([lat, lng], zoom);

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
            maxZoom: 19 // Zoom maksimal default OpenStreetMap
        }).addTo(map);

        // Hapus marker yang sudah ada jika ada
        if (marker) {
            map.removeLayer(marker);
        }

        // Tambahkan marker ke lokasi awal
        marker = L.marker([lat, lng], { draggable: true }).addTo(map);
        updateCoordinates(lat, lng); // Perbarui input dengan nilai awal

        marker.on('dragend', function(event) {
            const newLatLng = event.target.getLatLng();
            updateCoordinates(newLatLng.lat, newLatLng.lng);
        });

        map.on('click', function(e) {
            if (marker) {
                map.removeLayer(marker);
            }
            marker = L.marker(e.latlng, { draggable: true }).addTo(map);
            updateCoordinates(e.latlng.lat, e.latlng.lng);

            marker.on('dragend', function(event) {
                const newLatLng = event.target.getLatLng();
                updateCoordinates(newLatLng.lat, newLatLng.lng);
            });
        });

        // Pastikan peta merespons perubahan ukuran div jika div dihidupkan/dimatikan
        map.invalidateSize();
    }

    // Fungsi untuk memperbarui peta berdasarkan input manual latitude/longitude
    function updateMapFromInputs() {
        const inputLat = parseFloat(latitudeInput.value);
        const inputLng = parseFloat(longitudeInput.value);

        // Hanya perbarui peta jika input valid dan bukan NaN
        if (!isNaN(inputLat) && !isNaN(inputLng) && inputLat >= -90 && inputLat <= 90 && inputLng >= -180 && inputLng <= 180) {
            const newLatLng = L.latLng(inputLat, inputLng);
            if (marker) {
                marker.setLatLng(newLatLng);
            } else {
                marker = L.marker(newLatLng, { draggable: true }).addTo(map);
                marker.on('dragend', function(event) {
                    const newLatLng = event.target.getLatLng();
                    updateCoordinates(newLatLng.lat, newLatLng.lng);
                });
            }
            map.setView(newLatLng, map.getZoom()); // Pindahkan tampilan peta ke lokasi baru
        } else {
            // Jika input tidak valid atau kosong, hapus marker jika ada
            if (marker) {
                map.removeLayer(marker);
                marker = null;
            }
        }
    }

    // Event listener untuk perubahan input Latitude dan Longitude
    latitudeInput.addEventListener('input', updateMapFromInputs);
    longitudeInput.addEventListener('input', updateMapFromInputs);

    // Prioritas inisialisasi awal peta:
    // 1. Koordinat yang sudah ada di form (dari $old_input) jika ada (setelah submit dengan error)
    // 2. Geolocation dari perangkat
    // 3. Koordinat default
    let initialLat = parseFloat('<?php echo $old_input['latitude']; ?>');
    let initialLng = parseFloat('<?php echo $old_input['longitude']; ?>');

    // Cek apakah ada data lama di input latitude/longitude
    if (!isNaN(initialLat) && !isNaN(initialLng) && (initialLat !== 0 || initialLng !== 0)) {
        initializeMap(initialLat, initialLng, 15); // Gunakan data lama dengan zoom yang cukup
        updateCoordinates(initialLat, initialLng); // Pastikan input terisi dengan nilai ini
    } else if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(
            function(position) {
                // Berhasil mendapatkan lokasi
                const lat = position.coords.latitude;
                const lng = position.coords.longitude;
                initializeMap(lat, lng, 15); // Gunakan lokasi pengguna dengan zoom lebih dekat
            },
            function(error) {
                // Gagal mendapatkan lokasi
                console.warn('Geolocation ERROR(' + error.code + '): ' + error.message + ' - Menggunakan lokasi default.');
                initializeMap(defaultLat, defaultLng, defaultZoom); // Gunakan lokasi default
            },
            {
                enableHighAccuracy: true,    // Coba dapatkan lokasi seakurat mungkin
                timeout: 10000,              // Batas waktu untuk mendapatkan lokasi (10 detik)
                maximumAge: 60000            // Lokasi yang di-cache berlaku hingga 1 menit
            }
        );
    } else {
        // Geolocation tidak didukung browser
        console.log("Geolocation tidak didukung oleh browser ini. Menggunakan lokasi default.");
        initializeMap(defaultLat, defaultLng, defaultZoom); // Gunakan lokasi default
    }
    // --- LEAFLET MAP INTEGRATION END ---
});
</script>

<?php require_once '../includes/footer.php'; ?>
