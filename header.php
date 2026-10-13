<?php

/**
 * Header and Navigation Menu
 *
 * This script generates a header and navigation menu for the web application. The header displays the lab name and logo,
 * and the navigation menu includes links to various dashboards and settings, with additional options for admin users.
 *
 */

// Security Headers - Protect against common web vulnerabilities
header("X-Frame-Options: SAMEORIGIN"); // Prevent clickjacking
header("X-Content-Type-Options: nosniff"); // Prevent MIME type sniffing
header("X-XSS-Protection: 1; mode=block"); // Enable browser XSS protection
header("Referrer-Policy: strict-origin-when-cross-origin"); // Control referrer information
header("Permissions-Policy: geolocation=(), microphone=(), camera=(self)"); // Restrict powerful features; camera kept for same-origin cage QR scanning

// Include the database connection file
require 'dbcon.php';
require_once 'i18n.php';

$navigationReturnTo = $_SERVER['REQUEST_URI'] ?? 'home.php';
$showBeaReview = ($_SESSION['role'] ?? '') === 'admin';
if (!$showBeaReview && !empty($_SESSION['user_id'])) {
    $beaNavigationStmt = $con->prepare('SELECT is_bea_responsible FROM users WHERE id = ?');
    if ($beaNavigationStmt) {
        $beaNavigationStmt->bind_param('i', $_SESSION['user_id']);
        $beaNavigationStmt->execute();
        $showBeaReview = !empty($beaNavigationStmt->get_result()->fetch_assoc()['is_bea_responsible']);
        $beaNavigationStmt->close();
    }
}

// Query to fetch settings from the database
$query = "SELECT * FROM settings";
$result = mysqli_query($con, $query);

// Default lab name if the query fails or returns no result
$labName = "My Vivarium";

// Initialize sensor variables
$r1_temp = $r1_humi = $r1_illu = $r1_pres = $r2_temp = $r2_humi = $r2_illu = $r2_pres = "";

// Fetch the settings from the database
$settings = [];
while ($row = mysqli_fetch_assoc($result)) {
    $settings[$row['name']] = $row['value'];
}

// Set variables based on fetched settings
if (isset($settings['lab_name'])) {
    $labName = $settings['lab_name'];
}
if (isset($settings['url'])) {
    $url = $settings['url'];
}
if (isset($settings['r1_temp'])) {
    $r1_temp = $settings['r1_temp'];
}
if (isset($settings['r1_humi'])) {
    $r1_humi = $settings['r1_humi'];
}
if (isset($settings['r1_illu'])) {
    $r1_illu = $settings['r1_illu'];
}
if (isset($settings['r1_pres'])) {
    $r1_pres = $settings['r1_pres'];
}
if (isset($settings['r2_temp'])) {
    $r2_temp = $settings['r2_temp'];
}
if (isset($settings['r2_humi'])) {
    $r2_humi = $settings['r2_humi'];
}
if (isset($settings['r2_illu'])) {
    $r2_illu = $settings['r2_illu'];
}
if (isset($settings['r2_pres'])) {
    $r2_pres = $settings['r2_pres'];
}
?>

<!DOCTYPE html>
<html lang="<?= htmlspecialchars(current_locale(), ENT_QUOTES, 'UTF-8') ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <!-- Favicon and icons for different devices -->
    <link rel="icon" href="./icons/favicon.ico" type="image/x-icon">
    <link rel="apple-touch-icon" sizes="180x180" href="./icons/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="./icons/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="./icons/favicon-16x16.png">
    <link rel="icon" sizes="192x192" href="./icons/android-chrome-192x192.png">
    <link rel="icon" sizes="512x512" href="./icons/android-chrome-512x512.png">
    <link rel="manifest" href="manifest.json" crossorigin="use-credentials">

    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-1BmE4kWBq78iYhFldvKuhfTAU6auU8tT94WrHftjDbrCEXSU1oBoqyl2QvZ6jIW3" crossorigin="anonymous">

    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">

    <!-- Google Font: Poppins -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;700&display=swap" rel="stylesheet">

    <style>
        .header {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            align-items: center;
            background-color: #343a40;
            color: white;
            padding: 1rem;
            text-align: center;
            margin: 0;
        }

        .header .logo-container {
            padding: 0;
            margin: 0;
        }

        .header img.header-logo {
            width: 300px;
            height: auto;
            display: block;
            margin: 0;
        }

        .header h2 {
            margin-left: 15px;
            margin-bottom: 0;
            margin-top: 12px;
            font-size: 3.5rem;
            white-space: nowrap;
            font-family: 'Poppins', sans-serif;
            font-weight: 500;
        }

        /* Responsive styling for smaller screens */
        @media (max-width: 576px) {
            .header h2 {
                font-size: 1.8rem;
                margin-bottom: 5px;
            }

            .header img.header-logo {
                width: 150px;
            }
        }

        /* Styling for navigation container */
        .nav-container {
            background-color: #343a40;
            padding: 0px 0px 20px 0px;
            text-align: center;
            margin: 0;
        }

        .nav .btn {
            margin: 0 5px;
        }

        .dropdown-menu {
            min-width: auto;
        }
    </style>
</head>

<body>
    <!-- Header Section -->
    <?php if ($demo === "yes") include('demo/demo-banner.php'); ?>
    <div class="header">
        <div class="logo-container">
            <a href="home.php">
                <img src="images/logo1.jpg" alt="Logo" class="header-logo">
            </a>
        </div>
        <h2><?php echo htmlspecialchars($labName); ?></h2>
    </div>

    <!-- Navigation Menu Section -->
    <div class="nav-container">
        <nav class="nav justify-content-center">
            <a href="home.php" class="btn btn-primary">
                <i class="fas fa-home"></i> <?= htmlspecialchars(t('nav.home')) ?>
            </a>

            <!-- Dropdown for Dashboard -->
            <div class="dropdown">
                <button class="btn btn-primary dropdown-toggle" type="button" id="dashboardMenuButton" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="fas fa-tachometer-alt"></i> <?= htmlspecialchars(t('nav.dashboards')) ?>
                </button>
                <ul class="dropdown-menu" aria-labelledby="dashboardMenuButton">
                    <li><a class="dropdown-item" href="hc_dash.php"><?= htmlspecialchars(t('nav.holding_cages')) ?></a></li>
                    <li><a class="dropdown-item" href="bc_dash.php"><?= htmlspecialchars(t('nav.breeding_cages')) ?></a></li>
                    <?php
                    if (!empty($r1_temp) || !empty($r1_humi) || !empty($r1_illu) || !empty($r1_pres) || !empty($r2_temp) || !empty($r2_humi) || !empty($r2_illu) || !empty($r2_pres)) {
                        echo '<li><a class="dropdown-item" href="iot_sensors.php">' . htmlspecialchars(t('nav.iot_sensors')) . '</a></li>';
                    }
                    ?>
                </ul>
            </div>

            <!-- Dropdown for Animals / Experimentation -->
            <div class="dropdown">
                <button class="btn btn-primary dropdown-toggle" type="button" id="animalsMenuButton" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="fas fa-paw"></i> <?= htmlspecialchars(t('nav.animals')) ?>
                </button>
                <ul class="dropdown-menu" aria-labelledby="animalsMenuButton">
                    <li><a class="dropdown-item" href="daily_round.php"><i class="fas fa-clipboard-check me-1"></i> <?= htmlspecialchars(t('nav.daily_round')) ?></a></li>
                    <li><a class="dropdown-item" href="qr_scan.php"><i class="fas fa-qrcode me-1"></i> <?= htmlspecialchars(t('nav.scan_cage')) ?></a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item" href="animal_search.php?list=1"><i class="fas fa-search me-1"></i> <?= htmlspecialchars(t('nav.find_animal')) ?></a></li>
                    <li><a class="dropdown-item" href="animal_transfer.php"><?= htmlspecialchars(t('nav.transfer_animals')) ?></a></li>
                    <li><a class="dropdown-item" href="cage_bulk_create.php"><?= htmlspecialchars(t('nav.create_bulk')) ?></a></li>
                    <li><a class="dropdown-item" href="animal_use_schedule.php"><?= htmlspecialchars(t('nav.use_schedule')) ?></a></li>
                    <li><a class="dropdown-item" href="trial_manage.php"><?= htmlspecialchars(t('nav.trials')) ?></a></li>
                    <li><a class="dropdown-item" href="clinical_grids.php"><i class="fas fa-table me-1"></i> <?= htmlspecialchars(t('nav.clinical_grids')) ?></a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item" href="health_report.php"><i class="fas fa-notes-medical me-1"></i> <?= htmlspecialchars(t('nav.report_health')) ?></a></li>
                    <li><a class="dropdown-item" href="health_registry.php"><i class="fas fa-book-medical me-1"></i> <?= htmlspecialchars(t('nav.health_registry')) ?></a></li>
                    <li><a class="dropdown-item" href="pharmacy_manage.php"><i class="fas fa-pills me-1"></i> Pharmacie, ordonnances et administrations</a></li>
                </ul>
            </div>

            <!-- Quick animal search: an exact number opens the animal record -->
            <form class="d-flex" method="GET" action="animal_search.php" role="search">
                <input class="form-control form-control-sm" type="search" name="q" placeholder="<?= htmlspecialchars(t('nav.search_placeholder')) ?>" aria-label="<?= htmlspecialchars(t('nav.search_aria')) ?>" style="width: 170px;">
                <button class="btn btn-primary btn-sm ms-1" type="submit" title="<?= htmlspecialchars(t('nav.search_aria')) ?>"><i class="fas fa-search"></i></button>
            </form>

            <!-- Notification Bell -->
            <div class="dropdown">
                <button class="btn btn-primary position-relative" type="button" id="notifBellButton" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="fas fa-bell"></i>
                    <span id="notifBadge" class="badge rounded-pill bg-danger" style="display:none; position:absolute; top:-5px; right:-5px;">0</span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end" id="notifDropdown" aria-labelledby="notifBellButton" style="min-width: 320px; max-height: 400px; overflow-y: auto;">
                    <li><span class="dropdown-item-text text-muted"><?= htmlspecialchars(t('nav.loading')) ?></span></li>
                </ul>
            </div>

            <!-- Dropdown for Settings -->
            <div class="dropdown">
                <button class="btn btn-primary dropdown-toggle" type="button" id="settingsMenuButton" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="fas fa-cog"></i> <?= htmlspecialchars(t('nav.settings')) ?>
                </button>
                <ul class="dropdown-menu" aria-labelledby="settingsMenuButton">
                    <li><a class="dropdown-item" href="user_profile.php"><?= htmlspecialchars(t('nav.profile')) ?></a></li>
                    <li><a class="dropdown-item" href="manage_tasks.php"><?= htmlspecialchars(t('nav.tasks')) ?></a></li>
                    <?php if ($showBeaReview) : ?>
                        <li><a class="dropdown-item" href="experiment_retrospective.php"><i class="fas fa-clipboard-list me-1"></i> Analyse rétrospective BEA</a></li>
                        <li><a class="dropdown-item" href="annual_report.php"><i class="fas fa-chart-bar me-1"></i> <?= htmlspecialchars(t('nav.annual_report')) ?></a></li>
                    <?php endif; ?>
                    <?php
                    // Display admin options if the user is an admin
                    if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
                        echo '<li><hr class="dropdown-divider"></li>';
                        echo '<li class="dropdown-header">' . htmlspecialchars(t('nav.administration')) . '</li>';
                        echo '<li><a class="dropdown-item" href="manage_users.php">' . htmlspecialchars(t('nav.manage_users')) . '</a></li>';
                        echo '<li><a class="dropdown-item" href="manage_iacuc.php">' . htmlspecialchars(t('nav.manage_iacuc')) . '</a></li>';
                        echo '<li><a class="dropdown-item" href="manage_strain.php">' . htmlspecialchars(t('nav.manage_strains')) . '</a></li>';
                        echo '<li><a class="dropdown-item" href="manage_animal_lines.php">' . htmlspecialchars(t('nav.manage_lines')) . '</a></li>';
                        echo '<li><a class="dropdown-item" href="manage_lab.php">' . htmlspecialchars(t('nav.manage_lab')) . '</a></li>';
                        echo '<li><a class="dropdown-item" href="export_data.php">' . htmlspecialchars(t('nav.export_csv')) . '</a></li>';
                        echo '<li><a class="dropdown-item" href="export_for_v2.php"><i class="fas fa-file-export me-1"></i> ' . htmlspecialchars(t('nav.export_v2')) . '</a></li>';
                        echo '<li><hr class="dropdown-divider"></li>';
                    }
                    ?>
                    <li><a class="dropdown-item" href="logout.php"><?= htmlspecialchars(t('nav.logout')) ?></a></li>
                </ul>
            </div>
            <form class="d-flex align-items-center ms-1" method="POST" action="set_locale.php">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(locale_csrf_token()) ?>">
                <input type="hidden" name="return_to" value="<?= htmlspecialchars($navigationReturnTo, ENT_QUOTES, 'UTF-8') ?>">
                <label class="visually-hidden" for="locale-selector"><?= htmlspecialchars(t('language.label')) ?></label>
                <select id="locale-selector" class="form-select form-select-sm" name="locale" onchange="this.form.submit()" aria-label="<?= htmlspecialchars(t('language.label')) ?>">
                    <option value="fr" <?= current_locale() === 'fr' ? 'selected' : '' ?>><?= htmlspecialchars(t('language.french')) ?></option>
                    <option value="en" <?= current_locale() === 'en' ? 'selected' : '' ?>><?= htmlspecialchars(t('language.english')) ?></option>
                </select>
            </form>
        </nav>
    </div>

    <!-- Bootstrap and jQuery JS -->
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.5.1/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-ka7Sk0Gln4gmtz2MlQnikT1wXgYsOg+OMhuP+IlRH9sENBO0LRn5q+8nbTov4+1p" crossorigin="anonymous"></script>

    <?php if (isset($_SESSION['username'])) : ?>
    <script>
        function loadNotifications() {
            fetch('get_notifications.php')
                .then(r => r.json())
                .then(data => {
                    const badge = document.getElementById('notifBadge');
                    const list = document.getElementById('notifDropdown');
                    if (!badge || !list) return;

                    if (data.unread_count > 0) {
                        badge.style.display = 'inline-block';
                        badge.textContent = data.unread_count;
                    } else {
                        badge.style.display = 'none';
                    }

                    list.innerHTML = '';
                    if (!data.notifications || data.notifications.length === 0) {
                        list.innerHTML = '<li><span class="dropdown-item-text text-muted"><?= htmlspecialchars(t('nav.no_notifications'), ENT_QUOTES, 'UTF-8') ?></span></li>';
                        return;
                    }

                    // Notification text can contain user input (e.g. health reports): escape it.
                    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
                    data.notifications.forEach(n => {
                        const li = document.createElement('li');
                        const link = n.link ? esc(n.link) : '#';
                        li.innerHTML = `<a class="dropdown-item ${n.is_read ? '' : 'fw-bold'}" href="${link}" onclick="markNotificationRead(${Number(n.id)})">
                            ${esc(n.title)}<br><small class="text-muted">${esc(n.message)}</small>
                        </a>`;
                        list.appendChild(li);
                    });
                })
                .catch(() => {});
        }

        function markNotificationRead(id) {
            fetch('mark_notification.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id })
            });
        }

        document.addEventListener('DOMContentLoaded', function() {
            loadNotifications();
            setInterval(loadNotifications, 60000);
        });
    </script>
    <?php endif; ?>
</body>

</html>
