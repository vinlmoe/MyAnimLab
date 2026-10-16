<?php

/**
 * Manage Trials (essais) — root level of the experimentation module:
 * trial -> protocol(s) -> experiment(s).
 */

session_start();
require 'dbcon.php';

if (!isset($_SESSION['username'])) {
    header("Location: index.php");
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$currentUserId = $_SESSION['user_id'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die(tr('CSRF token validation failed'));
    }

    $trialNumber = trim($_POST['trial_number']);
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    $iacucId = trim($_POST['iacuc_id']) ?: null;
    $startDate = $_POST['start_date'] ?: null;
    $endDate = $_POST['end_date'] ?: null;

    $check = $con->prepare("SELECT COUNT(*) FROM trials WHERE trial_number = ?");
    $check->bind_param("s", $trialNumber);
    $check->execute();
    $check->bind_result($count);
    $check->fetch();
    $check->close();

    if ($count > 0) {
        $_SESSION['message'] = tr('Error: Trial number already exists.');
    } else {
        $stmt = $con->prepare("INSERT INTO trials (trial_number, iacuc_id, title, description, start_date, end_date, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssssi", $trialNumber, $iacucId, $title, $description, $startDate, $endDate, $currentUserId);
        if ($stmt->execute()) {
            $_SESSION['message'] = tr('Trial created successfully.');
        } else {
            $_SESSION['message'] = tr('Error creating trial.');
        }
        $stmt->close();
    }

    header("Location: trial_manage.php");
    exit();
}

// Render the shared header only after request handling. Including it before the
// POST redirect sends HTML output and prevents PHP from modifying headers.
require 'header.php';

$trials = $con->query("SELECT * FROM trials ORDER BY created_at DESC");
$iacucList = $con->query("SELECT iacuc_id, iacuc_title FROM iacuc");
?>

<!doctype html>
<html lang="<?= htmlspecialchars(current_locale()) ?>">

<head>
    <title><?= trh('Trials') ?> | <?php echo htmlspecialchars($labName); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>

<body>
    <div class="container mt-4 content">
        <h4><?= trh('Trials') ?></h4>
        <p class="text-muted">Root level of the experimentation module: essai &rarr; protocole(s) &rarr; expérimentation(s).</p>

        <?php include('message.php'); ?>

        <div class="card mb-4">
            <div class="card-body">
                <h5><?= trh('New Trial') ?></h5>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <div class="mb-3">
                        <label class="form-label"><?= trh('Trial Number') ?> *</label>
                        <input type="text" class="form-control" name="trial_number" required placeholder="e.g. T-2026-001">
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?= trh('Title') ?> *</label>
                        <input type="text" class="form-control" name="title" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?= trh('IACUC') ?></label>
                        <select class="form-control" name="iacuc_id">
                            <option value=""><?= trh('None') ?></option>
                            <?php while ($row = $iacucList->fetch_assoc()) : ?>
                                <option value="<?= htmlspecialchars($row['iacuc_id']); ?>"><?= htmlspecialchars($row['iacuc_id'] . ' | ' . $row['iacuc_title']); ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?= trh('Description') ?></label>
                        <textarea class="form-control" name="description"></textarea>
                    </div>
                    <div class="row">
                        <div class="col mb-3">
                            <label class="form-label"><?= trh('Start Date') ?></label>
                            <input type="date" class="form-control" name="start_date">
                        </div>
                        <div class="col mb-3">
                            <label class="form-label"><?= trh('End Date') ?></label>
                            <input type="date" class="form-control" name="end_date">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary"><?= trh('Create Trial') ?></button>
                </form>
            </div>
        </div>

        <h5><?= trh('Existing Trials') ?></h5>
        <table class="table table-bordered">
            <thead>
                <tr>
                    <th><?= trh('Number') ?></th>
                    <th><?= trh('Title') ?></th>
                    <th><?= trh('Status') ?></th>
                    <th><?= trh('Dates') ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php while ($t = $trials->fetch_assoc()) : ?>
                    <tr>
                        <td><?= htmlspecialchars($t['trial_number']); ?></td>
                        <td><?= htmlspecialchars($t['title']); ?></td>
                        <td><?= trh($t['status']); ?></td>
                        <td><?= htmlspecialchars($t['start_date'] ?? ''); ?> &rarr; <?= htmlspecialchars($t['end_date'] ?? ''); ?></td>
                        <td><a class="btn btn-sm btn-outline-dark" href="trial_report.php?trial_id=<?= (int) $t['id']; ?>"><i class="fas fa-file-alt"></i> Rapport</a> <a class="btn btn-sm btn-secondary" href="protocol_manage.php?trial_id=<?= (int) $t['id']; ?>">Plans / protocoles &raquo;</a></td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>

    <br>
    <?php require 'footer.php'; ?>
</body>

</html>
