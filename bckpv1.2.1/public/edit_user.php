<?php
// public/edit_user.php
require_once '../includes/db_connect.php';
require_once '../includes/session.php';
require_once '../includes/functions.php';
check_login();
check_role(['superadmin', 'admin', 'teknisi']);

$title = "Edit Pengguna";
$errors = [];
$success_message = '';

$user_id = $_GET['id'] ?? null;

// ==========================================================
// DEFINISI TINGKATAN ROLE
// Semakin kecil angkanya, semakin tinggi prioritas/tingkatannya
$role_hierarchy = [
    'superadmin' => 1,
    'admin'      => 2,
    'teknisi'    => 3,
    'pelanggan'  => 4,
];
// ==========================================================

// Dapatkan role dari user yang sedang login
$current_user_role = $_SESSION['user_role_name'] ?? '';
if (empty($current_user_role) && isset($_SESSION['user_id'])) {
    $stmt_current_role = $conn->prepare("SELECT r.role_name FROM users u JOIN roles r ON u.role_id = r.id WHERE u.id = ?");
    if ($stmt_current_role) {
        $stmt_current_role->bind_param("i", $_SESSION['user_id']);
        $stmt_current_role->execute();
        $result_current_role = $stmt_current_role->get_result();
        if ($row = $result_current_role->fetch_assoc()) {
            $current_user_role = $row['role_name'];
            $_SESSION['user_role_name'] = $current_user_role;
        }
        $stmt_current_role->close();
    }
}
$is_technician = ($current_user_role === 'teknisi');


if (!$user_id) {
    $_SESSION['error_message'] = "ID Pengguna tidak ditemukan.";
    header("Location: manage_users.php");
    exit();
}

// Ambil data peran (roles)
$roles = [];
$sql_roles = "SELECT id, role_name FROM roles ORDER BY role_name ASC";
$result_roles = $conn->query($sql_roles);
if ($result_roles->num_rows > 0) {
    while ($row = $result_roles->fetch_assoc()) {
        $roles[] = $row;
    }
}

// Ambil data pengguna yang akan diedit (TERMASUK latitude dan longitude)
$user_data = null;
$stmt_select = $conn->prepare("SELECT id, name, email, username, role_id, phone, address, latitude, longitude, paket FROM users WHERE id = ?");
if ($stmt_select) {
    $stmt_select->bind_param("i", $user_id);
    $stmt_select->execute();
    $result_select = $stmt_select->get_result();
    if ($result_select->num_rows > 0) {
        $user_data = $result_select->fetch_assoc();
    } else {
        $_SESSION['error_message'] = "Pengguna tidak ditemukan.";
        header("Location: manage_users.php");
        exit();
    }
    $stmt_select->close();
} else {
    $_SESSION['error_message'] = "Gagal menyiapkan query: " . $conn->error;
    header("Location: manage_users.php");
    exit();
}

// Ambil nama peran pengguna yang sedang diedit (penting untuk JS saat role_id disembunyikan)
$selected_role_name = '';
foreach ($roles as $role) {
    if ($user_data['role_id'] == $role['id']) {
        $selected_role_name = $role['role_name'];
        break;
    }
}

// ==========================================================
// LOGIKA PEMBATASAN EDIT ROLE
// Dapatkan tingkatan role dari user yang login dan user yang akan diedit
$current_user_role_level = $role_hierarchy[$current_user_role] ?? 99; // Default nilai tinggi jika role tidak ditemukan
$edited_user_role_level  = $role_hierarchy[$selected_role_name] ?? 99;

// Jika user yang sedang login mencoba mengedit user dengan role yang sama atau lebih tinggi (level angka lebih kecil atau sama)
// kecuali jika user adalah superadmin dan bukan mengedit dirinya sendiri (opsional: superadmin bisa edit superadmin lain)
// Jika user yang sedang login mencoba mengedit dirinya sendiri, biarkan (untuk kasus update profil)
if ($current_user_id !== $user_id) { // Hindari pembatasan jika user mengedit profilnya sendiri
    if ($current_user_role_level >= $edited_user_role_level) {
        $_SESSION['error_message'] = "Anda tidak diizinkan untuk mengedit pengguna dengan peran yang sama atau lebih tinggi dari peran Anda.";
        header("Location: manage_users.php");
        exit();
    }
}
// ==========================================================


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');

    // Conditional handling for technician role
    if ($is_technician) {
        // For technicians, use existing data for these fields
        $email = $user_data['email'] ?? '';
        $username = $user_data['username'] ?? '';
        $role_id = $user_data['role_id'] ?? 0;
        $password = ''; // Technicians cannot change password
    } else {
        $email = trim($_POST['email'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $role_id = (int)($_POST['role_id'] ?? 0); // Pastikan role_id adalah integer. Cast ke int.
        $password = $_POST['password'] ?? ''; // Opsional saat edit

        // ==========================================================
        // VALIDASI TAMBAHAN SAAT POST: USER TIDAK BISA MENURUNKAN/MENAIKKAN ROLE KE YANG TIDAK SEUSAI HIERARKI
        // Ambil tingkatan role dari role_id yang BARU DIPILIH
        $new_role_name = '';
        foreach ($roles as $role) {
            if ($role['id'] == $role_id) {
                $new_role_name = $role['role_name'];
                break;
            }
        }
        $new_role_level = $role_hierarchy[$new_role_name] ?? 99;

        // Pastikan user tidak bisa mengubah role ke yang lebih tinggi atau sama dengan role dia (kecuali dirinya sendiri)
        if ($current_user_id !== $user_id) {
            if ($current_user_role_level >= $new_role_level) {
                $errors[] = "Anda tidak dapat mengatur peran ini karena tingkatannya sama atau lebih tinggi dari peran Anda.";
            }
        }
        // ==========================================================
    }

    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $latitude = trim($_POST['latitude'] ?? ''); // Ambil latitude dari form
    $longitude = trim($_POST['longitude'] ?? ''); // Ambil longitude dari form
    $paket = trim($_POST['paket'] ?? '');

    // Konversi ke float atau NULL jika string kosong
    $latitude_db = !empty($latitude) ? (float)$latitude : NULL;
    $longitude_db = !empty($longitude) ? (float)$longitude : NULL;

    // Ambil nama peran berdasarkan role_id yang dipilih (dari POST atau data awal jika teknisi)
    $current_edited_user_role_name_from_post_or_db = '';
    foreach ($roles as $role) {
        if ($role['id'] == $role_id) { // Gunakan $role_id dari POST (atau yang sudah disetel jika teknisi)
            $current_edited_user_role_name_from_post_or_db = $role['role_name'];
            break;
        }
    }


    // Validasi dasar
    if (empty($name)) {
        $errors[] = "Nama Pengguna harus diisi.";
    }
    // Only validate email if not a technician (or if technician somehow provides it)
    if (!$is_technician) {
        if (empty($email)) {
            $errors[] = "Email harus diisi.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Format email tidak valid.";
        }
        if (empty($role_id)) {
            $errors[] = "Peran harus dipilih.";
        }
        if (!empty($password) && strlen($password) < 6) {
            $errors[] = "Password minimal 6 karakter jika diisi.";
        }
    }


    // VALIDASI TAMBAHAN UNTUK ROLE 'pelanggan'
    if ($current_edited_user_role_name_from_post_or_db === 'pelanggan') {
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

    // VALIDASI UNTUK LATITUDE DAN LONGITUDE
    if (!empty($latitude) && (!is_numeric($latitude) || floatval($latitude) < -90 || floatval($latitude) > 90)) {
        $errors[] = "Lintang (Latitude) harus berupa angka antara -90 dan 90.";
    }
    if (!empty($longitude) && (!is_numeric($longitude) || floatval($longitude) < -180 || floatval($longitude) > 180)) {
        $errors[] = "Bujur (Longitude) harus berupa angka antara -180 dan 180.";
    }

    if (empty($errors)) {
        // Cek apakah email sudah terdaftar oleh pengguna lain (EXCEPT self) - only if technician isn't editing it
        if (!$is_technician) {
            $stmt_check_email = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $stmt_check_email->bind_param("si", $email, $user_id);
            $stmt_check_email->execute();
            $stmt_check_email->store_result();
            if ($stmt_check_email->num_rows > 0) {
                $errors[] = "Email ini sudah terdaftar oleh pengguna lain.";
            }
            $stmt_check_email->close();
        }

        // Cek apakah username sudah terdaftar oleh pengguna lain (jika username diisi)
        if (empty($errors) && !empty($username)) {
            $stmt_check_username = $conn->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
            $stmt_check_username->bind_param("si", $username, $user_id);
            $stmt_check_username->execute();
            $stmt_check_username->store_result();
            if ($stmt_check_username->num_rows > 0) {
                $errors[] = "ID Pelanggan ini sudah terdaftar oleh pengguna lain.";
            }
            $stmt_check_username->close();
        }

        // VALIDASI UNTUK NOMOR HANDPHONE UNIK SAAT EDIT (EXCEPT self)
        if (empty($errors) && !empty($phone)) {
            $stmt_check_phone = $conn->prepare("SELECT id FROM users WHERE phone = ? AND id != ?");
            $stmt_check_phone->bind_param("si", $phone, $user_id);
            $stmt_check_phone->execute();
            $stmt_check_phone->store_result();
            if ($stmt_check_phone->num_rows > 0) {
                $errors[] = "Nomor telepon ini sudah terdaftar oleh pengguna lain.";
            }
            $stmt_check_phone->close();
        }

        if (empty($errors)) {
            $update_columns = [
                "name = ?",
                "phone = ?",
                "address = ?",
                "latitude = ?",
                "longitude = ?",
                "paket = ?"
            ];
            $param_values = [
                $name,
                $phone,
                $address,
                $latitude_db,
                $longitude_db,
                $paket
            ];
            $param_types_str = "sssdds";

            if (!$is_technician) {
                $update_columns[] = "email = ?";
                $param_values[] = $email;
                $param_types_str .= "s";

                $update_columns[] = "username = ?";
                $param_values[] = $username;
                $param_types_str .= "s";

                $update_columns[] = "role_id = ?";
                $param_values[] = $role_id;
                $param_types_str .= "i";

                if (!empty($password)) {
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                    $update_columns[] = "password = ?";
                    $param_values[] = $hashed_password;
                    $param_types_str .= "s";
                }
            }

            // Tambahkan ID pengguna untuk klausa WHERE
            $param_values[] = $user_id;
            $param_types_str .= "i";

            $sql_update = "UPDATE users SET " . implode(", ", $update_columns) . " WHERE id = ?";

            $stmt_update = $conn->prepare($sql_update);

            if ($stmt_update === false) {
                $errors[] = "Gagal menyiapkan query update: " . $conn->error;
            } else {
                $stmt_update->bind_param($param_types_str, ...$param_values);

                if ($stmt_update->execute()) {
                    $_SESSION['success_message'] = "Pengguna berhasil diperbarui.";
                    // Setelah update, ambil kembali data terbaru untuk ditampilkan, termasuk lat/lng
                    $stmt_reselect = $conn->prepare("SELECT id, name, email, username, role_id, phone, address, latitude, longitude, paket FROM users WHERE id = ?");
                    $stmt_reselect->bind_param("i", $user_id);
                    $stmt_reselect->execute();
                    $result_reselect = $stmt_reselect->get_result();
                    if ($result_reselect->num_rows > 0) {
                        $user_data = $result_reselect->fetch_assoc();
                        // Perbarui juga $selected_role_name jika role_id berubah
                        foreach ($roles as $role) {
                            if ($user_data['role_id'] == $role['id']) {
                                $selected_role_name = $role['role_name'];
                                break;
                            }
                        }
                    }
                    $stmt_reselect->close();

                } else {
                    $errors[] = "Gagal memperbarui pengguna: " . $stmt_update->error;
                }
                $stmt_update->close();
            }
        }
    }
    // Jika ada error, data yang dimasukkan akan tetap ada di form
    $user_data['name'] = $name;
    // Preserve technician's original values for hidden fields if there were POST errors
    if (!$is_technician) {
        $user_data['email'] = $email;
        $user_data['username'] = $username;
        $user_data['role_id'] = $role_id;
    }
    $user_data['phone'] = $phone;
    $user_data['address'] = $address;
    $user_data['latitude'] = $latitude;
    $user_data['longitude'] = $longitude;
    $user_data['paket'] = $paket;

}


require_once '../includes/header.php';
?>

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
                <h1 class="display-5">Edit Pengguna</h1>
                <a href='customer_details.php?id=$customer_id' class='btn btn-sm btn-info me-1'>kembali</i></a>
            </header>

            <div class="card mb-4">
                <div class="card-body">
                    <?php if (!empty($errors)): ?>
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
                    <?php if (isset($_SESSION['error_message'])): // Tambahkan ini untuk menampilkan error dari redirect ?>
                        <div class="alert alert-danger"><?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?></div>
                    <?php endif; ?>

                    <form action="edit_user.php?id=<?php echo htmlspecialchars($user_id); ?>" method="POST" id="editUserForm">
                        <div class="mb-3">
                            <label for="name" class="form-label">Nama Pengguna</label>
                            <input type="text" class="form-control" id="name" name="name" required value="<?php echo htmlspecialchars($user_data['name'] ?? ''); ?>">
                        </div>

                        <?php if (!$is_technician): ?>
                        <div class="mb-3">
                            <label for="role_id" class="form-label">Peran (Role)</label>
                            <select class="form-select" id="role_id" name="role_id" required>
                                <option value="">Pilih Peran</option>
                                <?php foreach ($roles as $role): ?>
                                    <option value="<?php echo htmlspecialchars($role['id']); ?>" data-role-name="<?php echo htmlspecialchars($role['role_name']); ?>" <?php echo ($user_data['role_id'] == $role['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($role['role_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label for="email" class="form-label">Email</label>
                            <input type="email" class="form-control" id="email" name="email" required value="<?php echo htmlspecialchars($user_data['email'] ?? ''); ?>">
                        </div>
                        <div class="mb-3">
                            <label for="username" class="form-label">ID Pelanggan</label>
                            <input type="text" class="form-control" id="username" name="username" value="<?php echo htmlspecialchars($user_data['username'] ?? ''); ?>">
                            <small class="form-text text-muted" id="usernameHelp">Opsional, harus unik jika diisi.</small>
                        </div>
                        <div class="mb-3">
                            <label for="password" class="form-label">Password (Biarkan kosong jika tidak ingin mengubah)</label>
                            <input type="password" class="form-control" id="password" name="password">
                        </div>
                        <?php else: ?>
                            <input type="hidden" id="role_id" name="role_id" value="<?php echo htmlspecialchars($user_data['role_id'] ?? ''); ?>">
                            <input type="hidden" id="email" name="email" value="<?php echo htmlspecialchars($user_data['email'] ?? ''); ?>">
                            <input type="hidden" id="username" name="username" value="<?php echo htmlspecialchars($user_data['username'] ?? ''); ?>">
                            <input type="hidden" id="password" name="password" value="">
                        <?php endif; ?>

                        <div class="mb-3">
                            <label for="phone" class="form-label">Nomor Telepon</label>
                            <input type="text" class="form-control" id="phone" name="phone" value="<?php echo htmlspecialchars($user_data['phone'] ?? ''); ?>">
                            <small class="form-text text-muted" id="phoneHelp">Opsional.</small>
                        </div>
                        <div class="mb-3">
                            <label for="address" class="form-label">Alamat</label>
                            <textarea class="form-control" id="address" name="address" rows="3"><?php echo htmlspecialchars($user_data['address'] ?? ''); ?></textarea>
                            <small class="form-text text-muted" id="addressHelp">Opsional.</small>
                        </div>

                        <div class="mb-3">
                            <label for="latitude" class="form-label">Lintang (Latitude)</label>
                            <input type="text" class="form-control" id="latitude" name="latitude" value="<?php echo htmlspecialchars($user_data['latitude'] ?? ''); ?>" placeholder="Pilih dari peta">
                            <small class="form-text text-muted">Akan terisi otomatis dari peta. Rentang -90 hingga 90.</small>
                        </div>
                        <div class="mb-3">
                            <label for="longitude" class="form-label">Bujur (Longitude)</label>
                            <input type="text" class="form-control" id="longitude" name="longitude" value="<?php echo htmlspecialchars($user_data['longitude'] ?? ''); ?>" placeholder="Pilih dari peta">
                            <small class="form-text text-muted">Akan terisi otomatis dari peta. Rentang -180 hingga 180.</small>
                        </div>

                        <div class="mb-3">
                            <label for="mapid" class="form-label">Pilih Lokasi di Peta</label>
                            <div id="mapid"></div>
                            <small class="form-text text-muted">Seret penanda atau klik pada peta untuk menetapkan lokasi.</small>
                        </div>
                        <div class="mb-3">
                            <label for="paket" class="form-label">Paket</label>
                            <input type="text" class="form-control" id="paket" name="paket" value="<?php echo htmlspecialchars($user_data['paket'] ?? ''); ?>">
                            <small class="form-text text-muted" id="paketHelp">Opsional.</small>
                        </div>
                        <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
require_once '../includes/footer.php';
?>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const roleSelect = document.getElementById('role_id');
    const usernameInput = document.getElementById('username');
    const phoneInput = document.getElementById('phone');
    const addressInput = document.getElementById('address');
    const latitudeInput = document.getElementById('latitude');
    const longitudeInput = document.getElementById('longitude');
    const paketInput = document.getElementById('paket');
    const form = document.getElementById('editUserForm');
    const mapDiv = document.getElementById('mapid');

    const isTechnician = <?php echo json_encode($is_technician); ?>;
    const editedUserRoleName = '<?php echo htmlspecialchars($selected_role_name); ?>';


    function toggleRequiredFields() {
        let roleName = '';

        if (roleSelect && roleSelect.tagName === 'SELECT') {
            const selectedOption = roleSelect.options[roleSelect.selectedIndex];
            roleName = selectedOption ? selectedOption.dataset.roleName : '';
        } else {
            roleName = editedUserRoleName;
        }

        const isPelanggan = (roleName === 'pelanggan');

        usernameInput.required = isPelanggan;
        phoneInput.required = isPelanggan;
        addressInput.required = isPelanggan;
        paketInput.required = isPelanggan;

        const usernameHelp = document.getElementById('usernameHelp');
        if (usernameHelp) {
            usernameHelp.textContent = isPelanggan ? 'Wajib diisi untuk peran Pelanggan.' : 'Opsional, harus unik jika diisi.';
        }

        const phoneHelp = document.getElementById('phoneHelp');
        if (phoneHelp) {
            phoneHelp.textContent = isPelanggan ? 'Wajib diisi untuk peran Pelanggan.' : 'Opsional.';
        }

        const addressHelp = document.getElementById('addressHelp');
        if (addressHelp) {
            addressHelp.textContent = isPelanggan ? 'Wajib diisi untuk peran Pelanggan.' : 'Opsional.';
        }

        const paketHelp = document.getElementById('paketHelp');
        if (paketHelp) {
            paketHelp.textContent = isPelanggan ? 'Wajib diisi untuk peran Pelanggan.' : 'Opsional.';
        }
    }

    toggleRequiredFields();

    if (roleSelect && roleSelect.tagName === 'SELECT') {
        roleSelect.addEventListener('change', toggleRequiredFields);
    }
    form.addEventListener('submit', function(event) {
        toggleRequiredFields();
    });

    // --- INTEGRASI PETA LEAFLET DIMULAI ---
    let map;
    let marker;

    const defaultLat = -6.2088; // Default Jakarta
    const defaultLng = 106.8456;

    function updateCoordinates(lat, lng) {
        latitudeInput.value = lat.toFixed(8);
        longitudeInput.value = lng.toFixed(8);
    }

    function initializeMap(lat, lng, zoom) {
        if (map) {
            map.remove();
        }
        map = L.map('mapid').setView([lat, lng], zoom);

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
        }).addTo(map);

        if (marker) {
            map.removeLayer(marker);
        }
        if (!isNaN(lat) && !isNaN(lng) && (lat !== 0 || lng !== 0)) {
            marker = L.marker([lat, lng], { draggable: true }).addTo(map);
            marker.on('dragend', function(event) {
                const newLatLng = event.target.getLatLng();
                updateCoordinates(newLatLng.lat, newLatLng.lng);
            });
        }
        updateCoordinates(lat, lng);

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
        map.whenReady(function() {
            map.invalidateSize();
        });
    }

    function updateMapFromInputs() {
        const inputLat = parseFloat(latitudeInput.value);
        const inputLng = parseFloat(longitudeInput.value);

        if (!isNaN(inputLat) && !isNaN(inputLng)) {
            const newLatLng = L.latLng(inputLat, inputLng);
            if (map) {
                if (marker) {
                    marker.setLatLng(newLatLng);
                } else {
                    marker = L.marker(newLatLng, { draggable: true }).addTo(map);
                    marker.on('dragend', function(event) {
                        const newLatLng = event.target.getLatLng();
                        updateCoordinates(newLatLng.lat, newLatLng.lng);
                    });
                }
                map.setView(newLatLng, map.getZoom());
            }
        } else {
            if (marker) {
                map.removeLayer(marker);
                marker = null;
            }
        }
    }

    latitudeInput.addEventListener('input', updateMapFromInputs);
    longitudeInput.addEventListener('input', updateMapFromInputs);

    const storedLat = parseFloat(latitudeInput.value);
    const storedLng = parseFloat(longitudeInput.value);

    if (!isNaN(storedLat) && !isNaN(storedLng) && (storedLat !== 0 || storedLng !== 0)) {
        initializeMap(storedLat, storedLng, 15);
    } else if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(function(position) {
            const lat = position.coords.latitude;
            const lng = position.coords.longitude;
            initializeMap(lat, lng, 15);
        }, function(error) {
            // Tidak ada console.warn di sini untuk produksi
            initializeMap(defaultLat, defaultLng, 13);
        });
    } else {
        // Tidak ada console.log di sini untuk produksi
        initializeMap(defaultLat, defaultLng, 13);
    }
    // --- INTEGRASI PETA LEAFLET BERAKHIR ---
});
</script>