<?php

/**
 * Health report detail and follow-up.
 *
 * Shows the original report (never editable) and its chronological follow-up
 * entries. Each follow-up is appended to health_report_updates and carries the
 * report's new status/severity; existing entries are never changed.
 */

session_start();
require 'dbcon.php';
require 'animal_lib.php';

if (!isset($_SESSION['username'])) {
    $currentUrl = urlencode($_SERVER['REQUEST_URI']);
    header("Location: index.php?redirect=$currentUrl");
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$currentUserId = $_SESSION['user_id'] ?? null;
$currentUserName = $_SESSION['name'] ?? null;
$reportId = (int) ($_GET['id'] ?? $_POST['report_id'] ?? 0);

$stmt = $con->prepare(HEALTH_REPORTS_WITH_STATE_SQL . " WHERE r.id = ?");
$stmt->bind_param("i", $reportId);
$stmt->execute();
$report = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($report && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die(tr('CSRF token validation failed'));
    }

    $status = $_POST['status'] ?? '';
    $severity = $_POST['severity'] ?? '';
    $note = trim($_POST['note'] ?? '');

    if (!isset(HEALTH_STATUSES[$status]) || !isset(HEALTH_SEVERITIES[$severity]) || $note === '') {
        $_SESSION['message'] = tr('Please write a follow-up note and choose a status and severity.');
    } else {
        $ins = $con->prepare(
            "INSERT INTO health_report_updates (report_id, status, severity, note, created_by, created_by_name) VALUES (?, ?, ?, ?, ?, ?)"
        );
        $ins->bind_param("isssis", $reportId, $status, $severity, $note, $currentUserId, $currentUserName);
        $ins->execute();
        $ins->close();

        $changes = [];
        if ($status !== $report['current_status']) {
            $changes[] = tr('status {from} → {to}', ['from' => tr($report['current_status']), 'to' => tr($status)]);
        }
        if ($severity !== $report['current_severity']) {
            $changes[] = tr('severity {from} → {to}', ['from' => tr($report['current_severity']), 'to' => tr($severity)]);
        }
        log_activity($con, 'health_followup', 'health_report', (string) $reportId, $changes ? implode(', ', $changes) : tr('note added'), $currentUserId);

        // Escalation to a higher severity is worth a fresh alert.
        $levels = array_keys(HEALTH_SEVERITIES);
        if (array_search($severity, $levels) > array_search($report['current_severity'], $levels)) {
            notify_admins(
                $con,
                tr('Health issue worsened ({severity}): {animal}', ['severity' => tr($severity), 'animal' => $report['animal_uid']]),
                health_categories()[$report['category']] ?? $report['category'],
                "health_report_view.php?id=$reportId",
                'health_alert'
            );
        }

        $_SESSION['message'] = tr('Follow-up added to health issue #{id}.', ['id' => $reportId]);
    }

    header("Location: health_report_view.php?id=$reportId");
    exit;
}

require 'header.php';

if (!$report) {
    echo "<div class='container mt-4'><div class='alert alert-danger'>" . trh('Health report not found.') . "</div></div>";
    require 'footer.php';
    exit;
}

$updates = $con->prepare("SELECT * FROM health_report_updates WHERE report_id = ? ORDER BY id ASC");
$updates->bind_param("i", $reportId);
$updates->execute();
$updates = $updates->get_result()->fetch_all(MYSQLI_ASSOC);

// The animal may have been permanently deleted since: the report keeps its snapshot.
$animalStmt = $con->prepare("SELECT id, cage_id, status FROM mice WHERE id = ?");
$animalStmt->bind_param("i", $report['animal_id']);
$animalStmt->execute();
$animal = $animalStmt->get_result()->fetch_assoc();
$animalStmt->close();
?>

<!doctype html>
<html lang="<?= htmlspecialchars(current_locale()) ?>">

<head>
    <title><?= trh('Health Issue #{id}', ['id' => (int) $report['id']]); ?> | <?php echo htmlspecialchars($labName); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .container {
            max-width: 900px;
        }

        .timeline-entry {
            border-left: 3px solid #adb5bd;
            padding-left: 12px;
            margin-bottom: 14px;
        }
    </style>
</head>

<body>
    <div class="container mt-4 content">
        <a href="health_registry.php">&laquo; <?= trh('Health registry') ?></a>
        <h4 class="mt-2">
            <?= trh('Health issue #{id}', ['id' => (int) $report['id']]); ?> —
            <?= htmlspecialchars(health_categories()[$report['category']] ?? $report['category']); ?>
            <?= health_badge($report['current_status'], HEALTH_STATUSES); ?>
            <?= health_badge($report['current_severity'], HEALTH_SEVERITIES); ?>
        </h4>

        <?php include('message.php'); ?>

        <div class="card mb-3">
            <div class="card-body">
                <h5 class="card-title"><?= trh('Original report') ?> <small class="text-muted">(<?= trh('permanent') ?>)</small></h5>
                <div class="row">
                    <div class="col-md-6">
                        <p class="mb-1"><strong><?= trh('Animal:') ?></strong>
                            <?php if ($animal) : ?>
                                <a href="animal_view.php?id=<?= (int) $animal['id']; ?>"><?= htmlspecialchars($report['animal_uid']); ?></a>
                            <?php else : ?>
                                <?= htmlspecialchars($report['animal_uid']); ?> <span class="badge bg-secondary"><?= trh('record deleted') ?></span>
                            <?php endif; ?>
                        </p>
                        <p class="mb-1"><strong><?= trh('Line:') ?></strong> <?= htmlspecialchars($report['line_code'] ?? tr('N/A')); ?></p>
                        <p class="mb-1"><strong><?= trh('Cage at report time:') ?></strong> <?= htmlspecialchars($report['cage_id'] ?? tr('N/A')); ?>
                            <?php if ($animal && $animal['cage_id'] !== $report['cage_id']) : ?>
                                <small class="text-muted">(<?= trh('now {cage}', ['cage' => $animal['cage_id'] ?? tr('none')]); ?>)</small>
                            <?php endif; ?>
                        </p>
                    </div>
                    <div class="col-md-6">
                        <p class="mb-1"><strong><?= trh('Observed on:') ?></strong> <?= htmlspecialchars($report['observed_at']); ?></p>
                        <p class="mb-1"><strong><?= trh('Initial severity:') ?></strong> <?= health_badge($report['severity'], HEALTH_SEVERITIES); ?></p>
                        <p class="mb-1"><strong><?= trh('Reported by:') ?></strong> <?= htmlspecialchars($report['reported_by_name'] ?? tr('N/A')); ?>, <?= htmlspecialchars($report['reported_at']); ?></p>
                    </div>
                </div>
                <p class="mt-2 mb-0"><strong><?= trh('Description:') ?></strong><br><?= nl2br(htmlspecialchars($report['description'])); ?></p>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-body">
                <h5 class="card-title"><?= trh('Follow-up') ?></h5>
                <?php foreach ($updates as $u) : ?>
                    <div class="timeline-entry">
                        <small class="text-muted"><?= htmlspecialchars($u['created_at']); ?> — <?= htmlspecialchars($u['created_by_name'] ?? tr('N/A')); ?></small>
                        <?= health_badge($u['status'], HEALTH_STATUSES); ?>
                        <?= health_badge($u['severity'], HEALTH_SEVERITIES); ?>
                        <div><?= nl2br(htmlspecialchars($u['note'])); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-body">
                <h5 class="card-title"><?= trh('Add a follow-up entry') ?></h5>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <input type="hidden" name="report_id" value="<?= (int) $report['id']; ?>">
                    <div class="mb-3">
                        <label class="form-label" for="note"><?= trh('Note (treatment, evolution, vet decision...)') ?> *</label>
                        <textarea class="form-control" id="note" name="note" rows="3" required></textarea>
                    </div>
                    <div class="row g-2 align-items-end">
                        <div class="col-md-4">
                            <label class="form-label" for="status"><?= trh('Status') ?></label>
                            <select class="form-select" id="status" name="status">
                                <?php foreach (array_keys(HEALTH_STATUSES) as $s) : ?>
                                    <option value="<?= $s; ?>" <?= $s === $report['current_status'] ? 'selected' : ''; ?>><?= htmlspecialchars(ucfirst(tr($s))); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="severity"><?= trh('Severity') ?></label>
                            <select class="form-select" id="severity" name="severity">
                                <?php foreach (array_keys(HEALTH_SEVERITIES) as $s) : ?>
                                    <option value="<?= $s; ?>" <?= $s === $report['current_severity'] ? 'selected' : ''; ?>><?= htmlspecialchars(ucfirst(tr($s))); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <button type="submit" class="btn btn-primary w-100"><?= trh('Add follow-up') ?></button>
                        </div>
                    </div>
                </form>
                <?php if ($animal && $animal['status'] === 'active') : ?>
                    <hr>
                    <a href="animal_transfer.php?animal_id=<?= (int) $animal['id']; ?>" class="btn btn-sm btn-outline-primary"><i class="fas fa-exchange-alt"></i> <?= trh('Isolate / transfer') ?></a>
                    <a href="animal_exit.php?id=<?= (int) $animal['id']; ?>" class="btn btn-sm btn-outline-danger"><i class="fas fa-sign-out-alt"></i> <?= trh('Exit (sacrifice / death / export)') ?></a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <br>
    <?php include 'footer.php'; ?>
</body>

</html>
