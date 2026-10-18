<?php

/**
 * Manage Trials (essais) — root level of the experimentation module:
 * trial -> protocol(s) -> experiment(s).
 *
 * A trial is run under a project authorization (ethics number): its period
 * must lie within the authorization's validity, which is checked whenever a
 * trial is created or changed.
 */

require_once 'session_config.php';
require 'dbcon.php';
require 'animal_lib.php';
require 'ethics_lib.php';

if (!isset($_SESSION['username'])) {
    header("Location: index.php");
    exit;
}

$currentUserId = $_SESSION['user_id'] ?? null;
const TRIAL_STATUSES = ['planned', 'active', 'completed', 'cancelled'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) {
        die(tr('CSRF token validation failed'));
    }

    $trialId = (int) ($_POST['trial_id'] ?? 0);
    $isEdit = isset($_POST['update_trial']) && $trialId > 0;
    $trialNumber = trim((string) ($_POST['trial_number'] ?? ''));
    $title = trim((string) ($_POST['title'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));
    $iacucId = trim((string) ($_POST['iacuc_id'] ?? '')) ?: null;
    $startDate = ($_POST['start_date'] ?? '') ?: null;
    $endDate = ($_POST['end_date'] ?? '') ?: null;
    $status = in_array($_POST['status'] ?? '', TRIAL_STATUSES, true) ? $_POST['status'] : 'planned';
    $back = $isEdit ? "trial_manage.php?edit=$trialId" : 'trial_manage.php?new=1';

    // Keep what was typed so a refused form is not lost.
    $_SESSION['trial_form'] = $_POST;

    $auth = load_authorization($con, $iacucId);
    if (!$isEdit && $trialNumber === '') {
        $_SESSION['message'] = tr('The trial number is required.');
    } elseif ($title === '') {
        $_SESSION['message'] = tr('The title is required.');
    } elseif ($iacucId !== null && !$auth) {
        $_SESSION['message'] = tr('Authorization not found.');
    } elseif ($error = trial_period_error($auth, $startDate, $endDate)) {
        $_SESSION['message'] = $error;
    } elseif ($isEdit) {
        $stmt = $con->prepare("UPDATE trials SET iacuc_id = ?, title = ?, description = ?, start_date = ?, end_date = ?, status = ? WHERE id = ?");
        $stmt->bind_param("ssssssi", $iacucId, $title, $description, $startDate, $endDate, $status, $trialId);
        $stmt->execute();
        $stmt->close();
        log_activity($con, 'update', 'trial', (string) $trialId, tr('Authorization {number}, period {start} → {end}, status {status}', ['number' => $iacucId ?? '—', 'start' => $startDate ?? '?', 'end' => $endDate ?? '?', 'status' => $status]), $currentUserId);
        $_SESSION['message'] = tr('Trial updated.');
        unset($_SESSION['trial_form']);
        $back = 'trial_manage.php';
    } else {
        $check = $con->prepare("SELECT COUNT(*) FROM trials WHERE trial_number = ?");
        $check->bind_param("s", $trialNumber);
        $check->execute();
        $check->bind_result($count);
        $check->fetch();
        $check->close();

        if ($count > 0) {
            $_SESSION['message'] = tr('Error: Trial number already exists.');
        } else {
            $stmt = $con->prepare("INSERT INTO trials (trial_number, iacuc_id, title, description, start_date, end_date, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("sssssssi", $trialNumber, $iacucId, $title, $description, $startDate, $endDate, $status, $currentUserId);
            if ($stmt->execute()) {
                log_activity($con, 'create', 'trial', (string) $stmt->insert_id, $trialNumber, $currentUserId);
                $_SESSION['message'] = tr('Trial created successfully.');
                unset($_SESSION['trial_form']);
                $back = 'trial_manage.php';
            } else {
                $_SESSION['message'] = tr('Error creating trial.');
            }
            $stmt->close();
        }
    }

    header("Location: $back");
    exit();
}

$trials = $con->query(
    "SELECT t.*, i.iacuc_title, i.authorization_number, i.valid_from, i.valid_until,
            (SELECT COUNT(*) FROM protocols p WHERE p.trial_id = t.id) AS n_protocols,
            (SELECT COUNT(*) FROM experiments e JOIN protocols p ON p.id = e.protocol_id WHERE p.trial_id = t.id) AS n_experiments,
            (SELECT COUNT(DISTINCT ea.animal_id) FROM experiment_animals ea JOIN experiments e ON e.id = ea.experiment_id
                JOIN protocols p ON p.id = e.protocol_id WHERE p.trial_id = t.id) AS n_animals
     FROM trials t LEFT JOIN iacuc i ON i.iacuc_id = t.iacuc_id
     ORDER BY FIELD(t.status, 'active', 'planned', 'completed', 'cancelled'), t.start_date DESC, t.created_at DESC"
)->fetch_all(MYSQLI_ASSOC);
$authorizations = $con->query("SELECT * FROM iacuc ORDER BY valid_until DESC, iacuc_id")->fetch_all(MYSQLI_ASSOC);

$editing = null;
if (isset($_GET['edit'])) {
    foreach ($trials as $t) {
        if ((int) $t['id'] === (int) $_GET['edit']) {
            $editing = $t;
        }
    }
}
$form = $_SESSION['trial_form'] ?? null;
unset($_SESSION['trial_form']);
$values = $form ?? $editing ?? [];
$showForm = $editing || isset($_GET['new']) || !$trials;
$isAdmin = ($_SESSION['role'] ?? '') === 'admin';

// Render the shared header only after request handling. Including it before the
// POST redirect sends HTML output and prevents PHP from modifying headers.
require 'header.php';
?>

<!doctype html>
<html lang="<?= htmlspecialchars(current_locale()) ?>">

<head>
    <title><?= trh('Trials') ?> | <?php echo htmlspecialchars($labName); ?></title>
    <style>
        .trial-row td { vertical-align: middle; }
    </style>
</head>

<body>
    <div class="container mt-4 content">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-2">
                <li class="breadcrumb-item active" aria-current="page"><i class="fas fa-flask"></i> <?= trh('Trials') ?></li>
            </ol>
        </nav>
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h4 class="mb-0"><?= trh('Trials') ?></h4>
            <div>
                <?php if ($isAdmin) : ?><a href="manage_iacuc.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-file-signature"></i> <?= trh('Project authorizations') ?></a><?php endif; ?>
                <?php if (!$showForm) : ?><a href="trial_manage.php?new=1" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> <?= trh('New Trial') ?></a><?php endif; ?>
            </div>
        </div>
        <p class="text-muted mt-1"><?= trh('Trial → protocol(s) → experiment(s). The period of a trial must lie within the validity of its authorization.') ?></p>

        <?php include('message.php'); ?>

        <?php if ($showForm) : ?>
            <div class="card mb-4 border-primary" id="trialForm">
                <div class="card-header bg-primary text-white"><?= $editing ? trh('Edit trial {number}', ['number' => $editing['trial_number']]) : trh('New Trial') ?></div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <?php if ($editing) : ?><input type="hidden" name="trial_id" value="<?= (int) $editing['id']; ?>"><?php endif; ?>
                        <div class="row g-3">
                            <div class="col-md-3">
                                <label class="form-label" for="trial_number"><?= trh('Trial Number') ?> *</label>
                                <input type="text" class="form-control" id="trial_number" name="trial_number" required placeholder="<?= trh('e.g. T-2026-001') ?>"
                                    value="<?= htmlspecialchars($values['trial_number'] ?? ''); ?>" <?= $editing ? 'readonly' : ''; ?>>
                            </div>
                            <div class="col-md-9">
                                <label class="form-label" for="title"><?= trh('Title') ?> *</label>
                                <input type="text" class="form-control" id="title" name="title" required value="<?= htmlspecialchars($values['title'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="iacuc_id"><?= trh('Ethics number') ?></label>
                                <select class="form-select" id="iacuc_id" name="iacuc_id">
                                    <option value=""><?= trh('None') ?></option>
                                    <?php foreach ($authorizations as $a) : ?>
                                        <option value="<?= htmlspecialchars($a['iacuc_id']); ?>" data-from="<?= htmlspecialchars($a['valid_from'] ?? ''); ?>" data-until="<?= htmlspecialchars($a['valid_until'] ?? ''); ?>"
                                            <?= ($values['iacuc_id'] ?? '') === $a['iacuc_id'] ? 'selected' : ''; ?>>
                                            <?= htmlspecialchars($a['iacuc_id'] . ($a['authorization_number'] ? ' · ' . $a['authorization_number'] : '') . ' — ' . authorization_period($a)); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted" id="authorizationHint"></small>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label" for="start_date"><?= trh('Start Date') ?></label>
                                <input type="date" class="form-control" id="start_date" name="start_date" value="<?= htmlspecialchars($values['start_date'] ?? ''); ?>">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label" for="end_date"><?= trh('End Date') ?></label>
                                <input type="date" class="form-control" id="end_date" name="end_date" value="<?= htmlspecialchars($values['end_date'] ?? ''); ?>">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label" for="status"><?= trh('Status') ?></label>
                                <select class="form-select" id="status" name="status">
                                    <?php foreach (TRIAL_STATUSES as $s) : ?>
                                        <option value="<?= $s; ?>" <?= ($values['status'] ?? 'planned') === $s ? 'selected' : ''; ?>><?= htmlspecialchars(ucfirst(tr($s))); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="description"><?= trh('Description') ?></label>
                                <textarea class="form-control" id="description" name="description" rows="2"><?= htmlspecialchars($values['description'] ?? ''); ?></textarea>
                            </div>
                        </div>
                        <div class="mt-3">
                            <button type="submit" name="<?= $editing ? 'update_trial' : 'add_trial'; ?>" class="btn btn-primary"><i class="fas fa-save"></i> <?= $editing ? trh('Save Changes') : trh('Create Trial') ?></button>
                            <?php if ($trials) : ?><a href="trial_manage.php" class="btn btn-secondary"><?= trh('Cancel') ?></a><?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <div class="table-responsive">
            <table class="table table-hover align-middle" id="trialList">
                <thead class="table-light">
                    <tr>
                        <th><?= trh('Trial') ?></th>
                        <th><?= trh('Authorization') ?></th>
                        <th><?= trh('Period') ?></th>
                        <th><?= trh('Status') ?></th>
                        <th><?= trh('Content') ?></th>
                        <th class="text-end"><?= trh('Actions') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($trials as $t) :
                        $auth = $t['iacuc_id'] ? ['iacuc_id' => $t['iacuc_id'], 'valid_from' => $t['valid_from'], 'valid_until' => $t['valid_until']] : null;
                        $outside = $auth && $t['start_date'] && $t['end_date'] && trial_period_error($auth, $t['start_date'], $t['end_date']);
                    ?>
                        <tr class="trial-row">
                            <td>
                                <a href="protocol_manage.php?trial_id=<?= (int) $t['id']; ?>" class="fw-bold text-decoration-none"><?= htmlspecialchars($t['trial_number']); ?></a>
                                <div class="small"><?= htmlspecialchars($t['title']); ?></div>
                            </td>
                            <td>
                                <?php if ($t['iacuc_id']) : ?>
                                    <div><?= htmlspecialchars($t['iacuc_id']); ?></div>
                                    <?php if ($t['authorization_number']) : ?><div class="small text-muted"><?= trh('Authorization no. {number}', ['number' => $t['authorization_number']]); ?></div><?php endif; ?>
                                    <?= authorization_badge($auth); ?>
                                <?php else : ?>
                                    <?= authorization_badge(null); ?>
                                <?php endif; ?>
                            </td>
                            <td class="small">
                                <?= htmlspecialchars(($t['start_date'] ?: '?') . ' → ' . ($t['end_date'] ?: '?')); ?>
                                <?php if ($outside) : ?><div class="text-danger"><i class="fas fa-exclamation-triangle"></i> <?= trh('Outside the authorization validity') ?></div><?php endif; ?>
                            </td>
                            <td><span class="badge bg-<?= ['active' => 'primary', 'planned' => 'info text-dark', 'completed' => 'success', 'cancelled' => 'secondary'][$t['status']] ?? 'secondary'; ?>"><?= trh($t['status']); ?></span></td>
                            <td class="small text-nowrap">
                                <div><?= trh('{n} protocol(s)', ['n' => (int) $t['n_protocols']]); ?></div>
                                <div><?= trh('{n} experiment(s)', ['n' => (int) $t['n_experiments']]); ?></div>
                                <div><?= trh('{n} animal(s)', ['n' => (int) $t['n_animals']]); ?></div>
                            </td>
                            <td class="text-end text-nowrap">
                                <a class="btn btn-sm btn-primary" href="protocol_manage.php?trial_id=<?= (int) $t['id']; ?>"><?= trh('Protocols') ?> &raquo;</a>
                                <a class="btn btn-sm btn-outline-secondary" href="trial_manage.php?edit=<?= (int) $t['id']; ?>" title="<?= trh('Edit') ?>"><i class="fas fa-edit"></i></a>
                                <a class="btn btn-sm btn-outline-dark" href="trial_report.php?trial_id=<?= (int) $t['id']; ?>" title="<?= trh('Printable report') ?>"><i class="fas fa-file-alt"></i></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$trials) : ?><tr><td colspan="6" class="text-muted text-center"><?= trh('No trial yet.') ?></td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script>
        // Limit the trial dates to the validity of the chosen authorization.
        (function() {
            const auth = document.getElementById('iacuc_id');
            if (!auth) return;
            const start = document.getElementById('start_date');
            const end = document.getElementById('end_date');
            const hint = document.getElementById('authorizationHint');
            const sync = () => {
                const opt = auth.selectedOptions[0];
                const from = opt ? opt.dataset.from || '' : '';
                const until = opt ? opt.dataset.until || '' : '';
                [start, end].forEach(input => { input.min = from; input.max = until; input.required = auth.value !== ''; });
                hint.textContent = auth.value && from && until
                    ? <?= trj('The trial must lie between {from} and {until}.') ?>.replace('{from}', from).replace('{until}', until)
                    : '';
            };
            auth.addEventListener('change', sync);
            sync();
        })();
    </script>

    <br>
    <?php require 'footer.php'; ?>
</body>

</html>
