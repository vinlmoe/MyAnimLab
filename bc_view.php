<?php

/**
 * View Breeding Cage 
 *
 * This script displays detailed information about a specific breeding cage identified by its cage ID.
 * It retrieves data from the database, including basic information, associated files, and litter details.
 * The script ensures that only logged-in users can access the page and provides options for editing, printing, and viewing a QR code.
 *
 */

// Start a new session or resume the existing session
require_once 'session_config.php';

// Include the database connection file
require 'dbcon.php';
require 'animal_lib.php';
require 'cage_view_parts.php';

// Check if the user is not logged in, redirect them to index.php with the current URL for redirection after login
if (!isset($_SESSION['username'])) {
    $currentUrl = urlencode($_SERVER['REQUEST_URI']);
    header("Location: index.php?redirect=$currentUrl");
    exit; // Exit to ensure no further code is executed
}

// Disable error display in production (errors logged to server logs)
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Query to get lab data (URL) from the settings table
$labQuery = "SELECT value FROM settings WHERE name = 'url' LIMIT 1";
$labResult = mysqli_query($con, $labQuery);

// Default value if the query fails or returns no result
$url = "";
if ($row = mysqli_fetch_assoc($labResult)) {
    $url = $row['value'];
}

// Check if the ID parameter is set in the URL
if (isset($_GET['id'])) {
    $id = mysqli_real_escape_string($con, $_GET['id']);

    // Fetch the breeding cage record with the specified ID
    $query = "SELECT b.*, c.remarks AS remarks, pi.initials AS pi_initials, pi.name AS pi_name
          FROM breeding b
          LEFT JOIN cages c ON b.cage_id = c.cage_id
          LEFT JOIN users pi ON c.pi_name = pi.id
          WHERE b.cage_id = ?";
    $stmt = $con->prepare($query);
    $stmt->bind_param("s", $id);
    $stmt->execute();
    $result = $stmt->get_result();

    // Fetch files associated with the specified cage ID
    $query2 = "SELECT * FROM files WHERE cage_id = ?";
    $stmt2 = $con->prepare($query2);
    $stmt2->bind_param("s", $id);
    $stmt2->execute();
    $files = $stmt2->get_result();

    // Fetch the breeding cage litter records with the specified ID
    $query3 = "SELECT * FROM litters WHERE `cage_id` = ?";
    $stmt3 = $con->prepare($query3);
    $stmt3->bind_param("s", $id);
    $stmt3->execute();
    $litters = $stmt3->get_result();

    // Check if the breeding cage record exists
    if (mysqli_num_rows($result) === 1) {
        $breedingcage = mysqli_fetch_assoc($result);

        // Fetch IACUC codes associated with the cage
        $iacucQuery = "SELECT ci.iacuc_id, i.file_url
                        FROM cage_iacuc ci
                        LEFT JOIN iacuc i ON ci.iacuc_id = i.iacuc_id
                        WHERE ci.cage_id = ?";
        $stmtIacuc = $con->prepare($iacucQuery);
        $stmtIacuc->bind_param("s", $id);
        $stmtIacuc->execute();
        $iacucResult = $stmtIacuc->get_result();
        $iacucLinks = [];
        while ($row = mysqli_fetch_assoc($iacucResult)) {
            if (!empty($row['file_url'])) {
                $iacucLinks[] = "<a href='" . htmlspecialchars($row['file_url']) . "' target='_blank'>" . htmlspecialchars($row['iacuc_id']) . "</a>";
            } else {
                $iacucLinks[] = htmlspecialchars($row['iacuc_id']);
            }
        }
        $iacucDisplayString = implode(', ', $iacucLinks);
    } else {
        // If the record does not exist, set an error message and redirect to the dashboard
        $_SESSION['message'] = tr('Invalid ID.');
        header("Location: bc_dash.php");
        exit();
    }
} else {
    // If the ID parameter is missing, set an error message and redirect to the dashboard
    $_SESSION['message'] = tr('ID parameter is missing.');
    header("Location: bc_dash.php");
    exit();
}

function getUserDetailsByIds($con, $userIds)
{
    // Archived cages have no assigned users left: avoid an invalid "IN ()".
    if (empty($userIds)) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($userIds), '?'));
    $query = "SELECT id, initials, name FROM users WHERE id IN ($placeholders)";
    $stmt = $con->prepare($query);
    $stmt->bind_param(str_repeat('i', count($userIds)), ...$userIds);
    $stmt->execute();
    $result = $stmt->get_result();
    $userDetails = [];
    while ($row = $result->fetch_assoc()) {
        $userDetails[$row['id']] = htmlspecialchars($row['initials'] . ' [' . $row['name'] . ']');
    }
    $stmt->close();
    return $userDetails;
}

// Fetch user IDs associated with the cage
$userIdsQuery = "SELECT cu.user_id
                 FROM cage_users cu
                 WHERE cu.cage_id = ?";
$stmtUserIds = $con->prepare($userIdsQuery);
$stmtUserIds->bind_param("s", $id);
$stmtUserIds->execute();
$userIdsResult = $stmtUserIds->get_result();
$userIds = [];
while ($row = mysqli_fetch_assoc($userIdsResult)) {
    $userIds[] = $row['user_id'];
}

// Fetch the user details based on IDs
$userDetails = getUserDetailsByIds($con, $userIds);

// Prepare a string to display user details
$userDisplay = [];
foreach ($userIds as $userId) {
    if (isset($userDetails[$userId])) {
        $userDisplay[] = $userDetails[$userId];
    } else {
        $userDisplay[] = htmlspecialchars($userId);
    }
}
$userDisplayString = implode(', ', $userDisplay);

// Fetch the maintenance logs for the current cage
$maintenanceQuery = "
    SELECT m.timestamp, u.name AS user_name, m.comments 
    FROM maintenance m
    JOIN users u ON m.user_id = u.id
    WHERE m.cage_id = ?
    ORDER BY m.timestamp DESC";

$stmtMaintenance = $con->prepare($maintenanceQuery);
$stmtMaintenance->bind_param("s", $id); // Assuming $id holds the current cage_id
$stmtMaintenance->execute();
$maintenanceLogs = $stmtMaintenance->get_result();

// Breeders currently registered for this cage (breeding_animals)
$breedersStmt = $con->prepare("SELECT m.id, m.animal_uid, m.sex, m.dob, m.status FROM breeding b
    JOIN breeding_animals ba ON ba.breeding_id = b.id JOIN mice m ON m.id = ba.animal_id
    WHERE b.cage_id = ? ORDER BY ba.sex, m.animal_uid");
$breedersStmt->bind_param("s", $id);
$breedersStmt->execute();
$breeders = $breedersStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$breedersStmt->close();
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Include the header file
require 'header.php';
?>

<!doctype html>
<html lang="<?= htmlspecialchars(current_locale()) ?>">

<head>
    <title>Cage d’accouplement | <?php echo htmlspecialchars($labName); ?></title>

    <script>
        // Function to display a QR code popup for the cage
        function showQrCodePopup(cageId) {
            var qrTitle = <?= trj('QR Code for Cage {cage}') ?>.replace('{cage}', decodeURIComponent(cageId));
            var popup = window.open("", "qr_" + cageId, "width=400,height=400");
            var qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=https://' + <?= json_encode($url, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?> + '/cage_scan_route.php?id=' + encodeURIComponent(cageId);
            var htmlContent = `
            <html>
            <head>
                <title>${qrTitle}</title>
                <style>
                    body { font-family: Arial, sans-serif; text-align: center; padding-top: 40px; }
                    h1 { color: #333; }
                    img { margin-top: 20px; }
                </style>
            </head>
            <body>
                <h1>${qrTitle}</h1>
                <img src="${qrUrl}" alt="${qrTitle}" />
            </body>
            </html>
        `;
            popup.document.write(htmlContent);
            popup.document.close();
        }

        // Function to navigate back to the previous page
        function goBack() {
            const urlParams = new URLSearchParams(window.location.search);
            const page = urlParams.get('page') || 1;
            const search = urlParams.get('search') || '';
            window.location.href = 'bc_dash.php?page=' + page + '&search=' + encodeURIComponent(search);
        }
    </script>

    <style>
        body {
            background: none !important;
            background-color: transparent !important;
        }

        .container {
            max-width: 800px;
            background-color: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin: auto;
        }

        .table-wrapper {
            padding: 10px;
        }

        .table-wrapper table {
            width: 100%;
            border: 1px solid #000;
            border-collapse: separate;
            border-spacing: 0;
        }

        .table-wrapper th,
        .table-wrapper td {
            border: 1px solid gray;
            padding: 8px;
            text-align: left;
            word-wrap: break-word;
            overflow-wrap: break-word;
        }

        .table-wrapper th:nth-child(1),
        .table-wrapper td:nth-child(1) {
            width: 30%;
        }

        .table-wrapper th:nth-child(2),
        .table-wrapper td:nth-child(2) {
            width: 70%;
        }

        .remarks-column {
            max-width: 400px;
            word-wrap: break-word;
            overflow-wrap: break-word;
        }

        span {
            font-size: 12pt;
            line-height: 1;
            display: inline-block;
        }

        .note-app-container {
            margin-top: 20px;
            padding: 20px;
            background-color: #e9ecef;
            border-radius: 8px;
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .action-buttons {
            display: flex;
            gap: 10px;
        }

        .btn-icon {
            width: 30px;
            height: 30px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0;
        }

        .btn-icon i {
            font-size: 16px;
            margin: 0;
        }

        @media (max-width: 768px) {

            .table-wrapper th,
            .table-wrapper td {
                padding: 12px 8px;
            }

            .table-wrapper th,
            .table-wrapper td {
                text-align: center;
            }
        }
    </style>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>

<body>

    <div class="container content mt-4">
        <?php
        // Daily check banner: printed cage QR codes open this page.
        $cageTodayChecks = cage_checks_for_date($con, date('Y-m-d'))[$breedingcage['cage_id']] ?? [];
        $lifeStmt = $con->prepare("SELECT status, created_at, retired_at FROM cages WHERE cage_id = ?");
        $lifeStmt->bind_param("s", $breedingcage['cage_id']);
        $lifeStmt->execute();
        $cageLife = $lifeStmt->get_result()->fetch_assoc();
        $lifeStmt->close();
        ?>
        <?php if (($cageLife['status'] ?? 'active') === 'archived') : ?>
            <div class="alert alert-secondary d-flex justify-content-between align-items-center flex-wrap mb-3" id="dailyCheckBanner">
                <span>
                    <strong><?= trh('Retired cage') ?></strong>
                    <?= $cageLife['retired_at'] ? trh('since {date}', ['date' => substr($cageLife['retired_at'], 0, 10)]) : ''; ?>
                    — <?= trh('no daily check expected.') ?>
                </span>
                <span class="text-muted small"><?= trh('A retired cage is not put back in service; its animals were moved or left the facility.') ?></span>
            </div>
        <?php else : ?>
            <div class="alert <?= $cageTodayChecks ? 'alert-success' : 'alert-warning'; ?> d-flex justify-content-between align-items-center flex-wrap mb-3" id="dailyCheckBanner">
                <span>
                    <strong><?= trh('Daily check:') ?></strong>
                    <?php if ($cageTodayChecks) : ?>
                        <?= trh('seen today') ?> <?php foreach ($cageTodayChecks as $chk) echo cage_check_badge($chk); ?>
                    <?php else : ?>
                        <?= trh('this cage has not been seen yet today.') ?>
                    <?php endif; ?>
                    <?php if (!empty($cageLife['created_at'])) : ?>
                        <small class="text-muted">· <?= trh('in service since {date}', ['date' => substr($cageLife['created_at'], 0, 10)]); ?></small>
                    <?php endif; ?>
                </span>
                <span>
                    <a href="cage_check.php?<?= htmlspecialchars(http_build_query(['id' => $breedingcage['cage_id'], 'via' => 'cage_page'])); ?>" class="btn btn-success btn-sm"><i class="fas fa-clipboard-check"></i> <?= trh('Validate daily check') ?></a>
                    <a href="cage_retire.php?id=<?= rawurlencode($breedingcage['cage_id']); ?>" class="btn btn-outline-danger btn-sm"><?= trh('Retire cage') ?></a>
                </span>
            </div>
        <?php endif; ?>
        <?php render_cage_location($con, $breedingcage['cage_id'], 'bc_view.php?id=' . rawurlencode($breedingcage['cage_id'])); ?>
        <div class="card">
            <div class="card-header">
                <h4>Cage d’accouplement <?= htmlspecialchars($breedingcage['cage_id']); ?></h4>
                <div class="action-buttons">
                    <a href="javascript:void(0);" onclick="goBack()" class="btn btn-primary btn-sm btn-icon" data-toggle="tooltip" data-placement="top" title="<?= trh('Go Back') ?>">
                        <i class="fas fa-arrow-circle-left"></i>
                    </a>
                    <!-- Button to mange tasks for the cage -->
                    <a href="manage_tasks.php?id=<?= rawurlencode($breedingcage['cage_id']); ?>" class="btn btn-secondary btn-sm btn-icon" data-toggle="tooltip" data-placement="top" title="<?= trh('Manage Tasks') ?>">
                        <i class="fas fa-tasks"></i>
                    </a>
                    <a href="javascript:void(0);" onclick="showQrCodePopup('<?= rawurlencode($breedingcage['cage_id']); ?>')" class="btn btn-success btn-sm btn-icon" data-toggle="tooltip" data-placement="top" title="<?= trh('QR Code') ?>">
                        <i class="fas fa-qrcode"></i>
                    </a>
                    <a href="cage_labels.php?id=<?= rawurlencode($breedingcage['cage_id']); ?>" target="_blank" class="btn btn-primary btn-sm btn-icon" data-toggle="tooltip" data-placement="top" title="Imprimer l’étiquette de cage">
                        <i class="fas fa-print"></i>
                    </a>
                    <a href="medication_administration.php?cage_id=<?= rawurlencode($breedingcage['cage_id']); ?>" class="btn btn-outline-primary btn-sm btn-icon" data-toggle="tooltip" data-placement="top" title="Administrations en cours">
                        <i class="fas fa-syringe"></i>
                    </a>
                </div>
            </div>
            <br>
            <div class="table-wrapper">
                <table class="table table-bordered" id="mouseTable">
                    <tr>
                        <th><?= trh('Cage #:') ?></th>
                        <td><?= htmlspecialchars($breedingcage['cage_id']); ?></td>
                    </tr>
                    <tr>
                        <th><?= trh('PI Name') ?></th>
                        <td><?= htmlspecialchars($breedingcage['pi_initials'] . ' [' . $breedingcage['pi_name'] . ']'); ?></td>
                    </tr>
                    <tr>
                        <th><?= trh('Cross') ?></th>
                        <td><?= htmlspecialchars($breedingcage['cross']); ?></td>
                    </tr>
                    <tr>
                        <th><?= trh('IACUC') ?></th>
                        <td><?= $iacucDisplayString; ?></td>
                    </tr>
                    <tr>
                        <th><?= trh('User') ?></th>
                        <td><?= $userDisplayString; ?></td>
                    </tr>
                    <tr>
                        <th>Reproducteurs</th>
                        <td>
                            <?php foreach ($breeders as $br) : ?>
                                <div>
                                    <a href="animal_view.php?id=<?= (int) $br['id']; ?>"><?= htmlspecialchars($br['animal_uid']); ?></a>
                                    — <?= $br['sex'] === 'male' ? 'Mâle' : 'Femelle'; ?><?= $br['dob'] ? ', né(e) le ' . htmlspecialchars(date('d/m/Y', strtotime($br['dob']))) : ''; ?>
                                    <?php if ($br['status'] !== 'active') : ?><span class="badge bg-secondary">sorti</span><?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                            <?php if (!$breeders) : ?>
                                <?= htmlspecialchars(trim(implode(' · ', array_filter([
                                    $breedingcage['male_id'] ? 'Mâle ' . $breedingcage['male_id'] . ($breedingcage['male_dob'] ? ' (' . $breedingcage['male_dob'] . ')' : '') : '',
                                    $breedingcage['female_id'] ? 'Femelle ' . $breedingcage['female_id'] . ($breedingcage['female_dob'] ? ' (' . $breedingcage['female_dob'] . ')' : '') : '',
                                ])))) ?: '—'; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th><?= trh('Remarks') ?></th>
                        <td class="remarks-column"><?= htmlspecialchars($breedingcage['remarks'] ?? ''); ?></td>
                    </tr>
                </table>
                <?php render_cage_animals($con, $id, 'bc_view.php'); ?>

                <!-- Separator -->
                <hr class="mt-4 mb-4" style="border-top: 3px solid #000;">

                <!-- Display Files Section -->
                <div class="card mt-4">

                    <div class="card-header">
                        <h4><?= trh('Manage Files') ?></h4>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th><?= trh('File Name') ?></th>
                                    <th><?= trh('Actions') ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($file = $files->fetch_assoc()) : ?>
                                    <tr>
                                        <td><?= htmlspecialchars($file['file_name']); ?></td>
                                        <td><a href="<?= htmlspecialchars($file['file_path']); ?>" download="<?= htmlspecialchars($file['file_name']); ?>" class="btn btn-sm btn-outline-primary"><i class="fas fa-cloud-download-alt"></i></a></td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>


                </div>

                <!-- Litter Details Section -->
                <div class="card mt-4">

                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h4 class="mb-0"><?= trh('Litter Details') ?> - <?= htmlspecialchars($id) ?>
                        </h4>
                        <a href="litter_manage.php?cage_id=<?= rawurlencode($id); ?>" class="btn btn-success btn-sm" id="manageLitters"><i class="fas fa-baby"></i> <?= trh('Litters: birth → weaning') ?></a>
                    </div>

                    <?php while ($litter = mysqli_fetch_assoc($litters)) : ?>
                        <div class="table-wrapper">
                            <table class="table table-bordered">
                                <tbody>
                                    <tr>
                                        <th><?= trh('DOM') ?></th>
                                        <td><?= htmlspecialchars($litter['dom'] ?? ''); ?></td>
                                    </tr>
                                    <tr>
                                        <th><?= trh('Litter DOB') ?></th>
                                        <td><?= htmlspecialchars($litter['litter_dob'] ?? ''); ?>
                                            <a href="litter_manage.php?litter_id=<?= (int) $litter['id']; ?>" class="ms-2 small"><?= trh((string) ($litter['status'] ?? '')); ?><?= ($litter['status'] ?? '') === 'nursing' ? ' — ' . trh('weaning expected {date}', ['date' => $litter['expected_weaning_date'] ?? '?']) : ''; ?></a></td>
                                    </tr>
                                    <tr>
                                        <th><?= trh('Pups Alive') ?></th>
                                        <td><?= htmlspecialchars($litter['pups_alive'] ?? ''); ?></td>
                                    </tr>
                                    <tr>
                                        <th><?= trh('Pups Dead') ?></th>
                                        <td><?= htmlspecialchars($litter['pups_dead'] ?? ''); ?></td>
                                    </tr>
                                    <tr>
                                        <th><?= trh('Pups Male') ?></th>
                                        <td><?= htmlspecialchars($litter['pups_male'] ?? ''); ?></td>
                                    </tr>
                                    <tr>
                                        <th><?= trh('Pups Female') ?></th>
                                        <td><?= htmlspecialchars($litter['pups_female'] ?? ''); ?></td>
                                    </tr>
                                    <tr>
                                        <th><?= trh('Remarks') ?></th>
                                        <td class="remarks-column"><?= htmlspecialchars($litter['remarks'] ?? ''); ?></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    <?php endwhile; ?>

                </div>


                <div class="card mt-4">
                    <div class="card-header d-flex flex-column flex-md-row justify-content-between">
                        <h4><?= trh('Maintenance Log for Cage ID: {cage}', ['cage' => $id ?? tr('Unknown')]); ?></h4>
                        <div class="action-icons mt-3 mt-md-0">
                            <!-- Maintenance button with tooltip -->
                            <a href="maintenance.php?from=bc_dash" class="btn btn-warning btn-icon" data-toggle="tooltip" data-placement="top" title="<?= trh('Add Maintenance Record') ?>">
                                <i class="fas fa-wrench"></i>
                            </a>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if ($maintenanceLogs->num_rows > 0) : ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th style="width: 25%;"><?= trh('Date') ?></th>
                                            <th style="width: 25%;"><?= trh('User') ?></th>
                                            <th style="width: 50%;"><?= trh('Comment') ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php while ($log = $maintenanceLogs->fetch_assoc()) : ?>
                                            <tr>
                                                <td style="width: 25%;"><?= htmlspecialchars($log['timestamp'] ?? ''); ?></td>
                                                <td style="width: 25%;"><?= htmlspecialchars($log['user_name'] ?? tr('Unknown')); ?></td>
                                                <td style="width: 50%;"><?= htmlspecialchars($log['comments'] ?? tr('No comment')); ?></td>
                                            </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else : ?>
                            <p><?= trh('No maintenance records found for this cage.') ?></p>
                        <?php endif; ?>
                    </div>
                </div>


            </div>
        </div>

        <!-- Note App Highlight -->
        <div class="note-app-container">
            <?php include 'nt_app.php'; ?>
        </div>
    </div>

    <br>
    <?php include 'footer.php'; ?>

</body>

</html>
