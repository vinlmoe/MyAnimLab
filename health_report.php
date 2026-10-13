<?php

/**
 * Report a health (sanitary) issue on an animal.
 *
 * Creates a permanent entry in the health registry (health_reports) plus its
 * first follow-up entry carrying the initial status/severity. Snapshots the
 * animal's number, cage and line so the record stays meaningful even if the
 * animal later moves or is deleted. Admins are notified.
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
$animalId = (int) ($_GET['animal_id'] ?? $_POST['animal_id'] ?? 0);
// Reached from the daily round or a cage check: record how, and go back there after.
$via = in_array($_GET['via'] ?? $_POST['via'] ?? '', ['round', 'qr', 'cage_page'], true) ? ($_GET['via'] ?? $_POST['via']) : 'auto';
$returnUrl = safe_check_return($_GET['return'] ?? $_POST['return'] ?? null);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die('CSRF token validation failed');
    }

    $observedAt = $_POST['observed_at'] ?: date('Y-m-d');
    $category = $_POST['category'] ?? '';
    $severity = $_POST['severity'] ?? '';
    $description = trim($_POST['description'] ?? '');
    $actions = trim($_POST['actions_taken'] ?? '');

    $stmt = $con->prepare(
        "SELECT m.id, m.animal_uid, m.mouse_id, m.cage_id, al.code AS line_code
         FROM mice m LEFT JOIN animal_lines al ON al.id = m.line_id WHERE m.id = ?"
    );
    $stmt->bind_param("i", $animalId);
    $stmt->execute();
    $animal = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$animal) {
        $_SESSION['message'] = "Animal not found.";
        header("Location: health_report.php");
        exit;
    }
    if (!isset(HEALTH_CATEGORIES[$category]) || !isset(HEALTH_SEVERITIES[$severity]) || $description === '') {
        $_SESSION['message'] = "Please fill in the category, severity and description.";
        header("Location: health_report.php?" . http_build_query(['animal_id' => $animalId, 'via' => $via, 'return' => $returnUrl]));
        exit;
    }

    $animalLabel = $animal['animal_uid'] ?? $animal['mouse_id'] ?? "#$animalId";

    mysqli_begin_transaction($con);
    try {
        $ins = $con->prepare(
            "INSERT INTO health_reports (animal_id, animal_uid, cage_id, line_code, observed_at, category, severity, description, reported_by, reported_by_name)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $ins->bind_param("isssssssis", $animalId, $animalLabel, $animal['cage_id'], $animal['line_code'], $observedAt, $category, $severity, $description, $currentUserId, $currentUserName);
        $ins->execute();
        $reportId = $con->insert_id;
        $ins->close();

        $note = "Issue reported." . ($actions !== '' ? " Initial actions: $actions" : '');
        $upd = $con->prepare(
            "INSERT INTO health_report_updates (report_id, status, severity, note, created_by, created_by_name) VALUES (?, 'open', ?, ?, ?, ?)"
        );
        $upd->bind_param("issis", $reportId, $severity, $note, $currentUserId, $currentUserName);
        $upd->execute();
        $upd->close();

        // A health report means the cage was seen today (with an issue).
        if ($animal['cage_id']) {
            record_cage_check($con, $animal['cage_id'], 'health_report', 'issue', $via, "$animalLabel: " . HEALTH_CATEGORIES[$category], $reportId, $currentUserId, $currentUserName);
        }

        log_animal_event($con, $animalId, 'health_report', [
            'report_id' => $reportId,
            'category' => $category,
            'severity' => $severity,
        ], $currentUserId);
        log_activity($con, 'health_report', 'animal', (string) $animalId, "$animalLabel: " . HEALTH_CATEGORIES[$category] . " ($severity)", $currentUserId);
        notify_admins(
            $con,
            "Health issue ($severity): $animalLabel",
            HEALTH_CATEGORIES[$category] . " — cage " . ($animal['cage_id'] ?? 'N/A'),
            "health_report_view.php?id=$reportId",
            'health_alert'
        );

        mysqli_commit($con);
        $_SESSION['message'] = "Health issue #$reportId recorded for $animalLabel.";
        header("Location: " . ($returnUrl ?? "health_report_view.php?id=$reportId"));
        exit;
    } catch (Exception $e) {
        mysqli_rollback($con);
        $_SESSION['message'] = "Failed to record the health issue.";
        header("Location: health_report.php?animal_id=$animalId");
        exit;
    }
}

$animal = null;
if ($animalId) {
    $stmt = $con->prepare(
        "SELECT m.id, m.animal_uid, m.mouse_id, m.cage_id, m.sex, m.status, al.code AS line_code, al.name AS line_name
         FROM mice m LEFT JOIN animal_lines al ON al.id = m.line_id WHERE m.id = ?"
    );
    $stmt->bind_param("i", $animalId);
    $stmt->execute();
    $animal = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

$activeAnimals = $con->query("SELECT id, animal_uid, cage_id FROM mice WHERE status = 'active' AND animal_uid IS NOT NULL ORDER BY animal_uid")->fetch_all(MYSQLI_ASSOC);

require 'header.php';
?>

<!doctype html>
<html lang="en">

<head>
    <title>Report Health Issue | <?php echo htmlspecialchars($labName); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .container {
            max-width: 800px;
        }

        .required-asterisk {
            color: red;
        }
    </style>
</head>

<body>
    <div class="container mt-4 content">
        <h4><i class="fas fa-notes-medical"></i> Report a Health Issue</h4>
        <p class="text-muted">The report is stored permanently in the <a href="health_registry.php">health registry</a>; it can be followed up but never edited or deleted.</p>

        <?php include('message.php'); ?>

        <form method="POST" class="card card-body">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']); ?>">
            <input type="hidden" name="via" value="<?= htmlspecialchars($via); ?>">
            <input type="hidden" name="return" value="<?= htmlspecialchars($returnUrl ?? ''); ?>">

            <div class="mb-3">
                <label class="form-label fw-bold" for="animal_id">Animal <span class="required-asterisk">*</span></label>
                <?php if ($animal) : ?>
                    <input type="hidden" name="animal_id" value="<?= (int) $animal['id']; ?>">
                    <div class="form-control-plaintext">
                        <a href="animal_view.php?id=<?= (int) $animal['id']; ?>"><strong><?= htmlspecialchars($animal['animal_uid'] ?? $animal['mouse_id'] ?? '#' . $animal['id']); ?></strong></a>
                        — line <?= htmlspecialchars($animal['line_code'] ?? 'N/A'); ?>,
                        cage <?= htmlspecialchars($animal['cage_id'] ?? 'N/A'); ?>,
                        <?= htmlspecialchars($animal['sex'] ?? ''); ?>
                        <?php if ($animal['status'] !== 'active') : ?>
                            <span class="badge bg-secondary"><?= htmlspecialchars($animal['status']); ?></span>
                        <?php endif; ?>
                        · <a href="health_report.php?<?= htmlspecialchars(http_build_query(['via' => $via, 'return' => $returnUrl])); ?>" class="small">change animal</a>
                    </div>
                <?php else : ?>
                    <select class="form-select" id="animal_id" name="animal_id" required>
                        <option value="" disabled selected>Select animal</option>
                        <?php foreach ($activeAnimals as $a) : ?>
                            <option value="<?= (int) $a['id']; ?>"><?= htmlspecialchars($a['animal_uid']); ?> (cage <?= htmlspecialchars($a['cage_id'] ?? 'N/A'); ?>)</option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted">Tip: use <a href="animal_search.php?list=1">Find Animal</a> and the <i class="fas fa-notes-medical"></i> button to report directly from the list.</small>
                <?php endif; ?>
            </div>

            <div class="row">
                <div class="col-md-4 mb-3">
                    <label class="form-label fw-bold" for="observed_at">Observed on <span class="required-asterisk">*</span></label>
                    <input type="date" class="form-control" id="observed_at" name="observed_at" required value="<?= date('Y-m-d'); ?>" max="<?= date('Y-m-d'); ?>">
                </div>
                <div class="col-md-5 mb-3">
                    <label class="form-label fw-bold" for="category">Category <span class="required-asterisk">*</span></label>
                    <select class="form-select" id="category" name="category" required>
                        <option value="" disabled selected>Select category</option>
                        <?php foreach (HEALTH_CATEGORIES as $key => $label) : ?>
                            <option value="<?= $key; ?>"><?= htmlspecialchars($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label fw-bold" for="severity">Severity <span class="required-asterisk">*</span></label>
                    <select class="form-select" id="severity" name="severity" required>
                        <?php foreach (array_keys(HEALTH_SEVERITIES) as $sev) : ?>
                            <option value="<?= $sev; ?>" <?= $sev === 'moderate' ? 'selected' : ''; ?>><?= ucfirst($sev); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label fw-bold" for="description">Description / clinical signs <span class="required-asterisk">*</span></label>
                <textarea class="form-control" id="description" name="description" rows="4" required placeholder="What was observed, where, since when..."></textarea>
            </div>

            <div class="mb-3">
                <label class="form-label fw-bold" for="actions_taken">Initial actions taken</label>
                <textarea class="form-control" id="actions_taken" name="actions_taken" rows="2" placeholder="e.g. isolated, vet called, analgesic given..."></textarea>
            </div>

            <button type="submit" class="btn btn-warning"><i class="fas fa-notes-medical"></i> Record health issue</button>
        </form>
    </div>

    <br>
    <?php include 'footer.php'; ?>
</body>

</html>
