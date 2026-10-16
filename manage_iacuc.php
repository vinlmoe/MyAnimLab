<?php

/**
 * Project authorizations (admin)
 *
 * Each authorization is identified by its ethics number and carries the
 * ministerial authorization number, its validity period — the period every
 * linked trial must fit in — and the three regulatory PDF documents: the
 * authorization request, the ethics committee opinion and the authorization
 * decision. The PDFs are stored privately and served by ethics_file.php.
 */

session_start();
require 'dbcon.php';
require 'animal_lib.php';
require 'ethics_lib.php';

if (!isset($_SESSION['username'])) {
    header('Location: index.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}
if (($_SESSION['role'] ?? '') !== 'admin') {
    $_SESSION['message'] = tr('Admin only.');
    header('Location: home.php');
    exit;
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$currentUserId = $_SESSION['user_id'] ?? null;

// Trials linked to an authorization.
function authorization_trials(mysqli $con, string $ethicsNumber): array
{
    $stmt = $con->prepare("SELECT id, trial_number, title, start_date, end_date FROM trials WHERE iacuc_id = ? ORDER BY start_date, trial_number");
    $stmt->bind_param("s", $ethicsNumber);
    $stmt->execute();
    $trials = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $trials;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], (string) ($_POST['csrf_token'] ?? ''))) {
        die(tr('CSRF token validation failed'));
    }
    $ethicsNumber = trim((string) ($_POST['iacuc_id'] ?? ''));
    $back = 'manage_iacuc.php';

    try {
        if (isset($_POST['delete'])) {
            $linked = authorization_trials($con, $ethicsNumber);
            if ($linked) {
                throw new Exception(tr('Authorization {number} is used by {n} trial(s): it cannot be deleted.', ['number' => $ethicsNumber, 'n' => count($linked)]));
            }
            $stmt = $con->prepare("DELETE FROM iacuc WHERE iacuc_id = ?");
            $stmt->bind_param("s", $ethicsNumber);
            $stmt->execute();
            $stmt->close();
            log_activity($con, 'delete', 'authorization', $ethicsNumber, null, $currentUserId);
            $_SESSION['message'] = tr('Authorization {number} deleted.', ['number' => $ethicsNumber]);
        } else {
            $isEdit = isset($_POST['edit']);
            $title = trim((string) ($_POST['iacuc_title'] ?? ''));
            $authorizationNumber = trim((string) ($_POST['authorization_number'] ?? '')) ?: null;
            $validFrom = (string) ($_POST['valid_from'] ?? '');
            $validUntil = (string) ($_POST['valid_until'] ?? '');
            $back = $isEdit ? 'manage_iacuc.php?' . http_build_query(['edit' => $ethicsNumber]) : 'manage_iacuc.php?new=1';

            if ($ethicsNumber === '' || $title === '') {
                throw new Exception(tr('The ethics number and the title are required.'));
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $validFrom) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $validUntil)) {
                throw new Exception(tr('Give both validity dates of the authorization.'));
            }
            if ($validFrom > $validUntil) {
                throw new Exception(tr('The validity must end after it starts.'));
            }
            $existing = load_authorization($con, $ethicsNumber);
            if (!$isEdit && $existing) {
                throw new Exception(tr('Ethics number {number} already exists.', ['number' => $ethicsNumber]));
            }
            if ($isEdit && !$existing) {
                throw new Exception(tr('Authorization not found.'));
            }
            // Shortening the validity must not leave a linked trial outside it.
            if ($isEdit) {
                $updated = ['iacuc_id' => $ethicsNumber, 'valid_from' => $validFrom, 'valid_until' => $validUntil];
                foreach (authorization_trials($con, $ethicsNumber) as $t) {
                    if ($t['start_date'] && $t['end_date'] && trial_period_error($updated, $t['start_date'], $t['end_date'])) {
                        throw new Exception(tr('Trial {trial} ({start} → {end}) would no longer fit in this validity: change the trial first.', ['trial' => $t['trial_number'], 'start' => $t['start_date'], 'end' => $t['end_date']]));
                    }
                }
            }

            // Store the uploaded PDFs; a document not re-uploaded is kept.
            $files = [];
            foreach (ETHICS_DOCUMENTS as $doc => [, $column]) {
                $stored = store_ethics_pdf($_FILES[$doc] ?? []);
                $files[$column] = $stored ?? ($existing[$column] ?? null);
            }

            if ($isEdit) {
                $stmt = $con->prepare("UPDATE iacuc SET iacuc_title = ?, authorization_number = ?, valid_from = ?, valid_until = ?, request_file = ?, committee_opinion_file = ?, decision_file = ? WHERE iacuc_id = ?");
                $stmt->bind_param("ssssssss", $title, $authorizationNumber, $validFrom, $validUntil, $files['request_file'], $files['committee_opinion_file'], $files['decision_file'], $ethicsNumber);
            } else {
                $stmt = $con->prepare("INSERT INTO iacuc (iacuc_id, iacuc_title, authorization_number, valid_from, valid_until, request_file, committee_opinion_file, decision_file) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("ssssssss", $ethicsNumber, $title, $authorizationNumber, $validFrom, $validUntil, $files['request_file'], $files['committee_opinion_file'], $files['decision_file']);
            }
            $stmt->execute();
            $stmt->close();
            log_activity($con, $isEdit ? 'update' : 'create', 'authorization', $ethicsNumber, tr('Authorization {auth}, valid {from} → {until}', ['auth' => $authorizationNumber ?? '—', 'from' => $validFrom, 'until' => $validUntil]), $currentUserId);
            $_SESSION['message'] = $isEdit ? tr('Authorization {number} updated.', ['number' => $ethicsNumber]) : tr('Authorization {number} added.', ['number' => $ethicsNumber]);
            $back = 'manage_iacuc.php';
        }
    } catch (Exception $e) {
        $_SESSION['message'] = $e->getMessage();
    }
    header('Location: ' . $back);
    exit;
}

$authorizations = $con->query(
    "SELECT i.*, (SELECT COUNT(*) FROM trials t WHERE t.iacuc_id = i.iacuc_id) AS n_trials
     FROM iacuc i ORDER BY i.valid_until IS NULL, i.valid_until DESC, i.iacuc_id"
)->fetch_all(MYSQLI_ASSOC);
$editing = isset($_GET['edit']) ? load_authorization($con, (string) $_GET['edit']) : null;
$showForm = $editing || isset($_GET['new']);

require 'header.php';
?>
<!doctype html>
<html lang="<?= htmlspecialchars(current_locale()) ?>">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= trh('Project authorizations') ?> | <?= htmlspecialchars($labName); ?></title>
</head>

<body>
    <div class="container mt-4 content">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
            <h4 class="mb-0"><i class="fas fa-file-signature"></i> <?= trh('Project authorizations') ?></h4>
            <?php if (!$showForm) : ?>
                <a href="manage_iacuc.php?new=1" class="btn btn-primary"><i class="fas fa-plus"></i> <?= trh('New authorization') ?></a>
            <?php endif; ?>
        </div>
        <p class="text-muted"><?= trh('Each authorization is identified by its ethics number. Its validity period is checked for every linked trial. The authorization request, the ethics committee opinion and the authorization decision are kept as PDF files.') ?></p>

        <?php include 'message.php'; ?>

        <?php if ($showForm) : ?>
            <div class="card mb-4 border-primary" id="authorizationForm">
                <div class="card-header bg-primary text-white">
                    <?= $editing ? trh('Edit authorization {number}', ['number' => $editing['iacuc_id']]) : trh('New authorization') ?>
                </div>
                <div class="card-body">
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label" for="iacuc_id"><?= trh('Ethics number') ?> *</label>
                                <input type="text" class="form-control" id="iacuc_id" name="iacuc_id" required maxlength="255"
                                    value="<?= htmlspecialchars($editing['iacuc_id'] ?? ''); ?>" <?= $editing ? 'readonly' : ''; ?> placeholder="<?= trh('e.g. APAFIS#12345-2026010112345678 v1') ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="authorization_number"><?= trh('Authorization number') ?></label>
                                <input type="text" class="form-control" id="authorization_number" name="authorization_number" maxlength="100"
                                    value="<?= htmlspecialchars($editing['authorization_number'] ?? ''); ?>">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label" for="valid_from"><?= trh('Valid from') ?> *</label>
                                <input type="date" class="form-control" id="valid_from" name="valid_from" required value="<?= htmlspecialchars($editing['valid_from'] ?? ''); ?>">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label" for="valid_until"><?= trh('Valid until') ?> *</label>
                                <input type="date" class="form-control" id="valid_until" name="valid_until" required value="<?= htmlspecialchars($editing['valid_until'] ?? ''); ?>">
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="iacuc_title"><?= trh('Project title') ?> *</label>
                                <input type="text" class="form-control" id="iacuc_title" name="iacuc_title" required maxlength="255" value="<?= htmlspecialchars($editing['iacuc_title'] ?? ''); ?>">
                            </div>
                            <?php foreach (ETHICS_DOCUMENTS as $doc => [$label, $column]) : ?>
                                <div class="col-md-4">
                                    <label class="form-label" for="doc_<?= $doc; ?>"><?= trh($label) ?> (PDF)</label>
                                    <input type="file" class="form-control" id="doc_<?= $doc; ?>" name="<?= $doc; ?>" accept="application/pdf,.pdf">
                                    <?php if (!empty($editing[$column])) : ?>
                                        <small class="text-muted"><a href="<?= htmlspecialchars(ethics_document_url($editing['iacuc_id'], $doc)); ?>" target="_blank"><i class="fas fa-file-pdf"></i> <?= trh('Current file') ?></a> — <?= trh('a new file replaces it') ?></small>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="mt-3">
                            <button type="submit" name="<?= $editing ? 'edit' : 'add'; ?>" class="btn btn-primary"><i class="fas fa-save"></i> <?= trh('Save') ?></button>
                            <a href="manage_iacuc.php" class="btn btn-secondary"><?= trh('Cancel') ?></a>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <div class="table-responsive">
            <table class="table table-hover align-middle" id="authorizationList">
                <thead class="table-light">
                    <tr>
                        <th><?= trh('Ethics number') ?></th>
                        <th><?= trh('Authorization number') ?></th>
                        <th><?= trh('Validity') ?></th>
                        <th><?= trh('Documents') ?></th>
                        <th class="text-center"><?= trh('Trials') ?></th>
                        <th class="text-end"><?= trh('Actions') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($authorizations as $a) : ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($a['iacuc_id']); ?></strong><br><small class="text-muted"><?= htmlspecialchars($a['iacuc_title']); ?></small></td>
                            <td><?= htmlspecialchars($a['authorization_number'] ?: '—'); ?></td>
                            <td><?= authorization_badge($a); ?><br><small><?= htmlspecialchars(authorization_period($a)); ?></small></td>
                            <td>
                                <?php foreach (ETHICS_DOCUMENTS as $doc => [$label, $column]) : ?>
                                    <?php if (!empty($a[$column])) : ?>
                                        <a href="<?= htmlspecialchars(ethics_document_url($a['iacuc_id'], $doc)); ?>" target="_blank" class="d-block small"><i class="fas fa-file-pdf text-danger"></i> <?= trh($label) ?></a>
                                    <?php else : ?>
                                        <span class="d-block small text-muted"><i class="far fa-file"></i> <?= trh($label) ?> — <?= trh('missing') ?></span>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                                <?php if (!empty($a['file_url'])) : ?>
                                    <a href="<?= htmlspecialchars($a['file_url']); ?>" target="_blank" class="d-block small"><i class="fas fa-paperclip"></i> <?= trh('Former attachment') ?></a>
                                <?php endif; ?>
                            </td>
                            <td class="text-center"><?= (int) $a['n_trials']; ?></td>
                            <td class="text-end text-nowrap">
                                <a href="manage_iacuc.php?<?= htmlspecialchars(http_build_query(['edit' => $a['iacuc_id']])); ?>" class="btn btn-sm btn-outline-primary" title="<?= trh('Edit') ?>"><i class="fas fa-edit"></i></a>
                                <form method="POST" class="d-inline" onsubmit="return confirm(<?= htmlspecialchars(trj('Delete this authorization?')) ?>);">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']); ?>">
                                    <input type="hidden" name="iacuc_id" value="<?= htmlspecialchars($a['iacuc_id']); ?>">
                                    <button type="submit" name="delete" class="btn btn-sm btn-outline-danger" title="<?= trh('Delete') ?>" <?= $a['n_trials'] ? 'disabled' : ''; ?>><i class="fas fa-trash-alt"></i></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$authorizations) : ?>
                        <tr><td colspan="6" class="text-muted text-center"><?= trh('No authorization yet.') ?></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <br>
    <?php include 'footer.php'; ?>
</body>

</html>
