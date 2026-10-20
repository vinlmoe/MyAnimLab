<?php

/**
 * Home Page
 * 
 * This script serves as the home page for the web application. It displays a welcome message to the logged-in user, 
 * along with statistics on holding and breeding cages, and provides links to their respective dashboards. 
 * Additionally, it includes a section for general notes.
 * 
 */

// Start a new session or resume the existing session
require_once 'session_config.php';

// Include the database connection file
require 'dbcon.php';
require 'animal_lib.php';

// Check if the user is logged in, redirect to login page if not logged in
if (!isset($_SESSION['name'])) {
    header("Location: index.php"); // Redirect to login page if not logged in
    exit;
}

// Active cages per category (holding, breeding, experimental)
$cageCounts = array_fill_keys(array_keys(CAGE_KINDS), 0);
foreach ($con->query("SELECT " . CAGE_KIND_SQL . " AS kind, COUNT(*) AS n FROM cages c WHERE c.status = 'active' GROUP BY kind") as $row) {
    $cageCounts[$row['kind']] = (int) $row['n'];
}

// Fetch the task stats for the logged-in user
$userId = $_SESSION['user_id'];
$taskStatsQuery = "
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN status = 'Completed' THEN 1 ELSE 0 END) AS completed,
        SUM(CASE WHEN status = 'In Progress' THEN 1 ELSE 0 END) AS in_progress,
        SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) AS pending
    FROM tasks
    WHERE assigned_to = ?
";
$stmtTaskStats = $con->prepare($taskStatsQuery);
$stmtTaskStats->bind_param("i", $userId);
$stmtTaskStats->execute();
$taskStatsResult = $stmtTaskStats->get_result();
$taskStatsRow = $taskStatsResult->fetch_assoc();
$totalTasks = $taskStatsRow['total'] ?? 0;
$completedTasks = $taskStatsRow['completed'] ?? 0;
$inProgressTasks = $taskStatsRow['in_progress'] ?? 0;
$pendingTasks = $taskStatsRow['pending'] ?? 0;

// Set completedTasks, inProgressTasks, and pendingTasks to zero if totalTasks is zero
if ($totalTasks == 0) {
    $completedTasks = 0;
    $inProgressTasks = 0;
    $pendingTasks = 0;
}

// Include the header file
// Daily round progress: today, and cages missed yesterday.
$roundCages = array_column(cages_due_on($con, date('Y-m-d')), 'cage_id');
$seenToday = count(array_intersect($roundCages, array_keys(cage_checks_for_date($con, date('Y-m-d')))));
$yesterday = date('Y-m-d', strtotime('-1 day'));
$missedYesterday = count(array_diff(array_column(cages_due_on($con, $yesterday), 'cage_id'), array_keys(cage_checks_for_date($con, $yesterday))));

$pendingEndpointActions = $con->query(
    "SELECT a.*, m.animal_uid, e.experiment_number FROM endpoint_actions a
     JOIN experiment_animals ea ON ea.id = a.experiment_animal_id
     JOIN mice m ON m.id = ea.animal_id JOIN experiments e ON e.id = ea.experiment_id
     WHERE a.status = 'pending' ORDER BY a.created_at"
)->fetch_all(MYSQLI_ASSOC);

// Litters to wean within 2 days, or overdue.
$littersToWean = $con->query(
    "SELECT id, cage_id, litter_dob, expected_weaning_date FROM litters
     WHERE status = 'nursing' AND expected_weaning_date <= CURDATE() + INTERVAL 2 DAY ORDER BY expected_weaning_date"
)->fetch_all(MYSQLI_ASSOC);

$housingAnomalies = (int) $con->query(
    "SELECT COUNT(*) FROM mice m LEFT JOIN cages c ON c.cage_id = m.cage_id
     WHERE m.status = 'active' AND (c.cage_id IS NULL OR c.status <> 'active')"
)->fetch_row()[0];

require 'header.php';
?>

<!doctype html>
<html lang="<?= htmlspecialchars(current_locale()) ?>">

<head>
    <!-- Meta tags for character encoding and responsive design -->
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title><?= trh('Home') ?> | <?php echo htmlspecialchars($labName); ?></title>

    <style>
        body,
        html {
            margin: 0;
            padding: 0;
            height: 100%;
            width: 100%;
        }

        .main-content {
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
            box-sizing: border-box;
        }
    </style>

</head>

<body>
    <div class="main-content content">
        <!-- Display session messages if any -->
        <?php include('message.php'); ?>

        <!-- Security warning for default admin account -->
        <?php if ($_SESSION['username'] === 'admin@myvivarium.online' && $_SESSION['role'] === 'admin'): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <strong><i class="fas fa-exclamation-triangle"></i> <?= trh('Security Warning:') ?></strong> <?= trh('You are using the default admin account.') ?>
            <?= trh('For security reasons, please create a new admin user and delete this default account immediately.') ?>
            <button type="button" class="close" data-dismiss="alert" aria-label="<?= trh('Close') ?>">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>
        <?php endif; ?>

        <?php if ($pendingEndpointActions) : ?>
            <div class="alert alert-danger" id="pendingEndpointActions">
                <i class="fas fa-exclamation-triangle"></i> <strong><?= trh('{n} endpoint action(s) required', ['n' => count($pendingEndpointActions)]); ?></strong>
                <ul class="mb-0">
                    <?php foreach ($pendingEndpointActions as $pa) : ?>
                        <li><a href="endpoint_action.php?<?= htmlspecialchars(http_build_query(['experiment_animal_id' => $pa['experiment_animal_id'], 'return' => 'home.php'])); ?>"><?= htmlspecialchars($pa['animal_uid']); ?></a>
                            (<?= htmlspecialchars($pa['experiment_number']); ?>): <strong><?= htmlspecialchars(endpoint_required_actions()[$pa['required_action']]); ?></strong>
                            — <?= htmlspecialchars($pa['message'] ?? ''); ?> <small>(<?= trh('since {date}', ['date' => $pa['created_at']]); ?>)</small></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($littersToWean) : ?>
            <div class="alert alert-warning" id="littersToWean">
                <i class="fas fa-baby"></i> <strong><?= trh('{n} litter(s) to wean', ['n' => count($littersToWean)]); ?></strong>
                <ul class="mb-0">
                    <?php foreach ($littersToWean as $lw) : $over = $lw['expected_weaning_date'] < date('Y-m-d'); ?>
                        <li class="<?= $over ? 'text-danger fw-bold' : ''; ?>"><a href="litter_manage.php?litter_id=<?= (int) $lw['id']; ?>"><?= trh('Litter #{id}', ['id' => (int) $lw['id']]); ?></a> — <?= trh($over ? 'cage {cage}, born {dob}, weaning overdue since {date}' : 'cage {cage}, born {dob}, weaning expected {date}', ['cage' => $lw['cage_id'], 'dob' => $lw['litter_dob'], 'date' => $lw['expected_weaning_date']]); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($housingAnomalies) : ?>
            <div class="alert alert-danger" id="housingAnomalies">
                <i class="fas fa-exclamation-triangle"></i> <strong><?= trh('{n} living animal(s) without an active cage', ['n' => $housingAnomalies]); ?></strong> <?= trh('(data recorded before the housing rule).') ?>
                <a href="animal_search.php?list=1&amp;status=active&amp;housing_anomaly=1"><?= trh('Move them to a cage or record their exit') ?></a>.
            </div>
        <?php endif; ?>

        <?php if ($roundCages) : ?>
            <div class="alert <?= $seenToday === count($roundCages) ? 'alert-success' : 'alert-warning'; ?> d-flex justify-content-between align-items-center flex-wrap" id="dailyRoundStatus">
                <span>
                    <i class="fas fa-clipboard-check"></i> <strong><?= trh('Daily round:') ?></strong>
                    <?= trh('{seen} / {total} cage(s) seen today.', ['seen' => $seenToday, 'total' => count($roundCages)]); ?>
                    <?php if ($missedYesterday) : ?>
                        <a href="daily_round.php?date=<?= date('Y-m-d', strtotime('-1 day')); ?>" class="text-danger fw-bold"><?= trh('{n} cage(s) missed yesterday.', ['n' => $missedYesterday]); ?></a>
                    <?php endif; ?>
                </span>
                <span>
                    <a href="daily_round.php?hide_seen=1" class="btn btn-sm btn-primary"><?= trh('Start / continue the round') ?></a>
                    <a href="qr_scan.php" class="btn btn-sm btn-success"><i class="fas fa-qrcode"></i> <?= trh('Scan') ?></a>
                </span>
            </div>
        <?php endif; ?>

        <br>
        <div class="row align-items-center">
            <!-- Welcome message with user information -->
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                <h2><?= trh('Welcome, {name}', ['name' => $_SESSION['name']]); ?>
                    <span style="font-size: smaller; color: #555; border-bottom: 2px solid #ccc; padding: 0 5px;">
                        [<?php echo htmlspecialchars(tr((string) ($_SESSION['position'] ?? ''))); ?>]
                    </span>
                </h2>
            </div>

            <!-- Display stats for Holding Cage and Breeding Cage -->
            <h2 class="mt-4"><?= trh('Cages Summary') ?></h2>
            <div class="card">
                <div class="card-body">
                    <div class="row mt-4">
                        <?php foreach (CAGE_KINDS as $kind => [$kindLabel, $kindDash]) : ?>
                            <div class="col-md-4">
                                <div class="card text-center">
                                    <div class="card-header bg-primary text-white">
                                        <a href="<?= $kindDash; ?>" style="color: white; text-decoration: none;"><?= htmlspecialchars($kindLabel); ?></a>
                                    </div>
                                    <div class="card-body">
                                        <h5 class="card-title"><?= $cageCounts[$kind]; ?></h5>
                                        <p class="card-text"><?= trh('Active cages') ?></p>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Display Task Stats for Logged-in User -->
            <h2 class="mt-4"><?= trh('Summary of Your Tasks') ?></h2>
            <div class="card" style="margin-top: 20px;">
                <div class="card-body">
                    <div class="row mt-4">
                        <!-- Total Tasks -->
                        <div class="col-md-3">
                            <div class="card text-center">
                                <div class="card-header bg-info text-white">
                                    <a href="manage_tasks.php?filter=assigned_to_me" style="color: white; text-decoration: none;"><?= trh('Total Tasks') ?></a>
                                </div>
                                <div class="card-body">
                                    <h5 class="card-title"><?php echo $totalTasks; ?></h5>
                                    <p class="card-text"><?= trh('Total') ?></p>
                                </div>
                            </div>
                        </div>
                        <!-- Completed Tasks -->
                        <div class="col-md-3">
                            <div class="card text-center">
                                <div class="card-header bg-success text-white">
                                    <a href="manage_tasks.php?search=completed&filter=assigned_to_me" style="color: white; text-decoration: none;"><?= trh('Completed') ?></a>
                                </div>
                                <div class="card-body">
                                    <h5 class="card-title"><?php echo $completedTasks; ?></h5>
                                    <p class="card-text"><?= trh('Completed') ?></p>
                                </div>
                            </div>
                        </div>
                        <!-- In Progress Tasks -->
                        <div class="col-md-3">
                            <div class="card text-center">
                                <div class="card-header bg-warning text-white">
                                    <a href="manage_tasks.php?search=in+progress&filter=assigned_to_me" style="color: white; text-decoration: none;"><?= trh('In Progress') ?></a>
                                </div>
                                <div class="card-body">
                                    <h5 class="card-title"><?php echo $inProgressTasks; ?></h5>
                                    <p class="card-text"><?= trh('In Progress') ?></p>
                                </div>
                            </div>
                        </div>
                        <!-- Pending Tasks -->
                        <div class="col-md-3">
                            <div class="card text-center">
                                <div class="card-header bg-danger text-white">
                                    <a href="manage_tasks.php?search=pending&filter=assigned_to_me" style="color: white; text-decoration: none;"><?= trh('Pending') ?></a>
                                </div>
                                <div class="card-body">
                                    <h5 class="card-title"><?php echo $pendingTasks; ?></h5>
                                    <p class="card-text"><?= trh('Pending') ?></p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Display sticky notes section -->
            <div style="margin-top: 50px;">
                <h2><?php echo htmlspecialchars($labName); ?> - <?= trh('General Notes') ?></h2>
                <?php include 'nt_app.php'; ?> <!-- Include the note application file -->
            </div>
        </div>
    </div>
    <!-- Include the footer file -->
    <?php include 'footer.php'; ?>

    <script>
        function adjustFooter() {
            const footer = document.getElementById('footer');
            const container = document.querySelector('.top-container');
            const header = document.querySelector('.header');
            const navcontainer = document.querySelector('.nav-container');

            if (!footer) {
                console.warn("Footer element not found.");
                return;
            }

            if (!container) {
                console.warn("Top container element not found.");
                return;
            }

            // Reset footer styles to compute natural height
            footer.style.position = 'relative';
            footer.style.bottom = 'auto';
            footer.style.width = '100%';

            // Calculate the height occupied by header and nav container
            const headerHeight = header ? header.offsetHeight : 0;
            const navcontainerHeight = navcontainer ? navcontainer.offsetHeight : 0;
            const footerHeight = footer.offsetHeight;

            // Calculate available space minus header and nav container
            const availableSpace = window.innerHeight - headerHeight - navcontainerHeight;

            // Calculate total content height
            const contentHeight = container.scrollHeight + footerHeight;

            // Adjust footer position based on content height and available space
            if (contentHeight < availableSpace) {
                footer.style.position = 'absolute';
                footer.style.bottom = '0';
            } else {
                footer.style.position = 'relative';
            }
        }

        // Adjust footer on page load and window resize
        window.addEventListener('load', adjustFooter);
        window.addEventListener('resize', adjustFooter);
    </script>


</body>

</html>