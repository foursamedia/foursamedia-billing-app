<?php
// includes/header.php
require_once '../includes/db_connect.php';
require_once '../includes/session.php';
require_once '../includes/functions.php';
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($title); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <!-- DataTables CSS -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.dataTables.min.css">
    <style>
        /* CSS tambahan untuk badge notifikasi */
        .notification-badge {
            position: absolute;
            top: 5px;
            /* Sesuaikan posisi vertikal */
            right: 5px;
            /* Sesuaikan posisi horizontal */
            padding: .2em .5em;
            border-radius: 50%;
            background-color: red;
            color: white;
            font-size: 0.7em;
            line-height: 1;
            font-weight: bold;
        }

        /* Style untuk dropdown notifikasi yang lebih lebar */
        .notification-dropdown {
            max-width: 350px;
            /* Lebar maksimum dropdown */
            min-width: 250px;
            /* Lebar minimum dropdown */
        }

        .notification-item {
            white-space: normal;
            /* Izinkan teks notifikasi wrap */
            padding: 8px 16px;
        }

        .notification-item small {
            display: block;
            color: #6c757d;
            font-size: 0.8em;
        }
    </style>
</head>

<body>


    <nav class="navbar navbar-expand-lg navbar-light bg-light border-bottom">
        <div class="container-fluid p-0 d-flex flex-row ">
            <div class="collapse navbar-collapse d-flex flex-row" id="navbarSupportedContent">
                <ul class="navbar-nav me-auto">
                    <a href="index.php" class="sidebar-brand d-flex align-items-center mb-md-0 me-md-auto text-decoration-none ">
                        <i class="bi bi-bar-chart-fill me-2 fs-4 text-primary"></i>
                        <span class="fs-4 fw-bold text-dark">FOURSAMEDIA</span>
                    </a>
                </ul>
                <ul class="navbar-nav d-flex flex-row align-items-center gap-3 gap-lg-0">
                    <li class="nav-item dropdown">
                        <a class="nav-link position-relative" href="#" id="navbarDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-bell fs-5"></i>
                            <span class="position-absolute translate-middle badge rounded-pill bg-danger notification-badge" style="display: none;">
                                0
                                <span class="visually-hidden">notifikasi baru</span>
                            </span>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end notification-dropdown desktop-notification" aria-labelledby="navbarDropdown">
                            <li><a class="dropdown-item text-center" href="#">Memuat notifikasi...</a></li>
                        </ul>
                    </li>
                    <div>
                        <button class="btn btn-primary d-md-none" id="sidebarToggle">
                            <i class="bi bi-list"></i>
                        </button>
                    </div>
                    <li class="nav-item dropdown ms-3 d-none d-lg-block">
                        <a class="nav-link dropdown-toggle" href="#" id="navbarUserDropdown1" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <img src="https://placehold.jp/cccccc/ffffff/150x150.png?css=%7B%22border-radius%22%3A%22100%25%22%7D" alt="Profile" width="32" height="32" class="rounded-circle me-1">
                            <?php
                            echo htmlspecialchars($_SESSION['username'] ?? $_SESSION['user_name'] ?? 'Guest');
                            ?>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="navbarUserDropdown1">
                            <li><a class="dropdown-item" href="profile.php">Profile</a></li>
                            <li>
                                <hr class="dropdown-divider">
                            </li>
                            <li><a class="dropdown-item" href="logout.php">Logout</a></li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- jQuery (required) -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>

    <!-- DataTables JS -->
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>