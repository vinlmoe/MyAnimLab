<?php

/**
 * Personal training record (livret de formation / de compétences) of one
 * person: initial diploma, initial regulatory training per function, competences validated
 * after supervision, continuing training (with the check of the minimum over
 * the rolling period) and other training, each with its certificate.
 * Everyone keeps their own record (entries to validate); administrators and
 * BEA responsibles open anyone's record (?user_id=), add validated entries,
 * validate or reject declared ones. Printable.
 */

require_once 'session_config.php';
require 'dbcon.php';
require 'animal_lib.php';
require_once 'training_lib.php';

if (!isset($_SESSION['username'])) {
    header('Location: index.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$currentUserId = (int) ($_SESSION['user_id'] ?? 0);
$currentUserName = $_SESSION['name'] ?? null;
$isManager = is_bea_user($con);
$userId = (int) ($_GET['user_id'] ?? $_POST['user_id'] ?? $currentUserId);
if ($userId !== $currentUserId && !$isManager) {
    http_response_code(403);
    exit(trh('Access restricted to the BEA responsible.'));
}
$stmt = $con->prepare('SELECT id, name, username, position, role, status FROM users WHERE id = ?');
$stmt->bind_param('i', $userId);
$stmt->execute();
$person = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$person) {
    http_response_code(404);
    exit(trh('User not found.'));
}
$selfUrl = 'training_book.php' . ($userId !== $currentUserId ? '?user_id=' . $userId : '');
[$reqDays, $reqYears, $hoursPerDay] = training_requirement($con);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        die(tr('CSRF token validation failed'));
    }
    try {
        if (isset($_POST['add_entry'])) {
            $category = (string) ($_POST['category'] ?? '');
            $function = (string) ($_POST['function_code'] ?? '');
            $title = trim((string) ($_POST['title'] ?? ''));
            $start = (string) ($_POST['start_date'] ?? '');
            $end = (string) ($_POST['end_date'] ?? '') ?: null;
            $duration = parse_number($_POST['duration'] ?? '');
            $hours = $duration === null ? null : (($_POST['duration_unit'] ?? 'h') === 'd' ? $duration * $hoursPerDay : $duration);
            if (!isset(training_categories()[$category])) {
                throw new Exception(tr('Choose the type of entry.'));
            }
            if (in_array($category, ['initial', 'competence'], true) && !isset(training_functions()[$function])) {
                throw new Exception(tr('Choose the function concerned.'));
            }
            if (!in_array($category, ['initial', 'competence'], true)) {
                $function = null;
            }
            $level = $category === 'diploma' ? (string) ($_POST['diploma_level'] ?? '') : null;
            if ($category === 'diploma' && !isset(training_diploma_levels()[$level])) {
                throw new Exception(tr('Choose the level of the diploma.'));
            }
            if ($category === 'diploma' && trim((string) ($_POST['title'] ?? '')) === '') {
                throw new Exception(tr('Give the title and speciality of the diploma.'));
            }
            if ($title === '') {
                $title = training_categories()[$category];
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || ($end !== null && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end) || $end < $start))) {
                throw new Exception(tr('Give a valid start date (and an end date after it).'));
            }
            if (($end ?? $start) > date('Y-m-d')) {
                throw new Exception(tr('Only training already followed can be recorded.'));
            }
            if ($category === 'continuing' && ($hours === null || $hours <= 0)) {
                throw new Exception(tr('Give the duration of the continuing training.'));
            }
            if ($hours !== null && ($hours <= 0 || $hours > 2000)) {
                throw new Exception(tr('Invalid duration.'));
            }
            $file = store_training_pdf($_FILES['certificate'] ?? ['error' => UPLOAD_ERR_NO_FILE]);
            // An entry recorded by an administrator / BEA responsible is validated at once.
            $status = $isManager ? 'validated' : 'pending';
            $validatedBy = $isManager ? $currentUserId : null;
            $validatedByName = $isManager ? $currentUserName : null;
            $validatedAt = $isManager ? date('Y-m-d H:i:s') : null;
            $fields = [
                'organisation' => trim((string) ($_POST['organisation'] ?? '')) ?: null,
                'species' => trim((string) ($_POST['species'] ?? '')) ?: null,
                'supervisor' => trim((string) ($_POST['supervisor_name'] ?? '')) ?: null,
                'ref' => trim((string) ($_POST['certificate_ref'] ?? '')) ?: null,
                'notes' => trim((string) ($_POST['notes'] ?? '')) ?: null,
            ];
            $ins = $con->prepare(
                'INSERT INTO training_records (user_id, category, function_code, diploma_level, title, organisation, start_date, end_date, duration_hours, species, supervisor_name,
                    certificate_ref, certificate_file, notes, status, validated_by, validated_by_name, validated_at, created_by, created_by_name)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $ins->bind_param('isssssssdssssssissis', $userId, $category, $function, $level, $title, $fields['organisation'], $start, $end, $hours, $fields['species'], $fields['supervisor'],
                $fields['ref'], $file, $fields['notes'], $status, $validatedBy, $validatedByName, $validatedAt, $currentUserId, $currentUserName);
            $ins->execute();
            $entryId = (int) $con->insert_id;
            $ins->close();
            log_activity($con, 'training_entry_added', 'user', (string) $userId, "#$entryId " . training_categories()[$category] . ' — ' . $title . " ($status)", $currentUserId);
            $_SESSION['message'] = $isManager ? tr('Entry recorded and validated.') : tr('Entry recorded: it counts once validated by an administrator or a BEA responsible.');
        } elseif (isset($_POST['review_entry']) && $isManager) {
            $entryId = (int) ($_POST['entry_id'] ?? 0);
            $decision = ($_POST['decision'] ?? '') === 'validated' ? 'validated' : 'rejected';
            $note = trim((string) ($_POST['review_note'] ?? '')) ?: null;
            if ($decision === 'rejected' && $note === null) {
                throw new Exception(tr('Give the reason of the rejection.'));
            }
            $up = $con->prepare("UPDATE training_records SET status = ?, validated_by = ?, validated_by_name = ?, validated_at = NOW(), review_note = ? WHERE id = ? AND user_id = ? AND status = 'pending'");
            $up->bind_param('sissii', $decision, $currentUserId, $currentUserName, $note, $entryId, $userId);
            $up->execute();
            $done = $up->affected_rows === 1;
            $up->close();
            if (!$done) {
                throw new Exception(tr('This entry is no longer to validate.'));
            }
            log_activity($con, 'training_entry_' . $decision, 'user', (string) $userId, "#$entryId" . ($note ? " — $note" : ''), $currentUserId);
            if ($userId !== $currentUserId) {
                create_notification($con, $userId, $decision === 'validated' ? tr('Training entry validated') : tr('Training entry rejected'), $note, 'training_book.php', 'system');
            }
            $_SESSION['message'] = $decision === 'validated' ? tr('Entry validated.') : tr('Entry rejected.');
        } elseif (isset($_POST['delete_entry'])) {
            $entryId = (int) ($_POST['entry_id'] ?? 0);
            $stmt = $con->prepare('SELECT * FROM training_records WHERE id = ? AND user_id = ?');
            $stmt->bind_param('ii', $entryId, $userId);
            $stmt->execute();
            $entry = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            // A person removes their entries not validated; a manager any entry.
            if (!$entry || (!$isManager && $entry['status'] === 'validated')) {
                throw new Exception(tr('This entry cannot be deleted.'));
            }
            $del = $con->prepare('DELETE FROM training_records WHERE id = ?');
            $del->bind_param('i', $entryId);
            $del->execute();
            $del->close();
            if ($entry['certificate_file'] && preg_match('/^[0-9a-f]{32}\.pdf$/', $entry['certificate_file'])) {
                @unlink(training_storage_dir() . '/' . $entry['certificate_file']);
            }
            log_activity($con, 'training_entry_deleted', 'user', (string) $userId, "#$entryId " . $entry['title'] . ' (' . $entry['start_date'] . ', ' . $entry['status'] . ')', $currentUserId);
            $_SESSION['message'] = tr('Entry deleted.');
        }
    } catch (Exception $e) {
        $_SESSION['message'] = tr('Not saved: {error}', ['error' => $e->getMessage()]);
    }
    header("Location: $selfUrl");
    exit;
}

$records = training_records($con, [$userId])[$userId] ?? [];
$status = training_status($con, $records);
$cpd = $status['cpd'];
$byCategory = [];
foreach ($records as $r) {
    $byCategory[$r['category']][] = $r;
}
$statusLabels = training_entry_statuses();
$pct = $cpd['required_hours'] > 0 ? min(100, (int) round($cpd['hours'] * 100 / $cpd['required_hours'])) : 0;
$fileUrl = fn(array $r): string => 'training_file.php?id=' . (int) $r['id'];

require 'header.php';
?>
<!doctype html>
<html lang="<?= htmlspecialchars(current_locale()) ?>">

<head>
    <title><?= trh('Training record') ?> — <?= htmlspecialchars($person['name']) ?> | <?= htmlspecialchars($labName) ?></title>
    <style>
        .book table { font-size: .85rem; }
        @media print {
            body > *:not(.book-wrapper) { display: none !important; }
            .no-print { display: none !important; }
        }
    </style>
</head>

<body>
    <main class="container mt-4 content book-wrapper" style="max-width: 1150px">
        <div class="no-print d-flex justify-content-between flex-wrap gap-2 mb-2">
            <div>
                <?php if ($isManager) : ?><a href="training_overview.php">&laquo; <?= trh('Training records of the staff') ?></a><?php endif; ?>
            </div>
            <div>
                <button class="btn btn-outline-dark btn-sm" onclick="window.print()"><i class="fas fa-print"></i> <?= trh('Print / PDF') ?></button>
                <button class="btn btn-primary btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#addEntry"><i class="fas fa-plus"></i> <?= trh('Add an entry') ?></button>
            </div>
        </div>
        <div class="no-print"><?php include 'message.php'; ?></div>

        <div class="book">
            <h4><i class="fas fa-user-graduate"></i> <?= trh('Training record') ?> — <?= htmlspecialchars($person['name']) ?></h4>
            <p class="text-muted mb-2"><?= htmlspecialchars(trim($person['position'] . ' · ' . $person['username'])) ?> · <?= trh('printed on {date}', ['date' => date('Y-m-d')]) ?> · <?= htmlspecialchars($labName) ?></p>

            <p class="mb-2" id="initialDiploma"><strong><?= trh('Initial diploma:') ?></strong>
                <?php if ($status['diploma']) : ?><?= htmlspecialchars(training_diploma_text($status['diploma'])) ?>
                <?php else : ?><span class="text-danger"><?= trh('not recorded (or not validated yet)') ?></span><?php endif; ?></p>
            <div class="row g-3 mb-3">
                <div class="col-md-7">
                    <div class="card h-100">
                        <div class="card-header"><strong><?= trh('Functions (Directive 2010/63/EU, art. 23)') ?></strong></div>
                        <table class="table table-sm mb-0">
                            <?php foreach (training_functions() as $code => $label) : ?>
                                <tr><td><?= htmlspecialchars($label) ?></td><td class="text-end"><?= training_function_badge($status['functions'][$code]) ?></td></tr>
                            <?php endforeach; ?>
                        </table>
                        <div class="card-body py-2 small text-muted"><?= trh('A function is held once its initial regulatory training is validated; the person works under supervision until the competence is validated.') ?></div>
                    </div>
                </div>
                <div class="col-md-5">
                    <div class="card h-100 <?= $cpd['state'] === 'late' ? 'border-danger' : '' ?>" id="cpdStatus">
                        <div class="card-header d-flex justify-content-between"><strong><?= trh('Continuing training') ?></strong> <?= training_cpd_badge($cpd) ?></div>
                        <div class="card-body">
                            <?php if ($cpd['state'] === 'not_applicable') : ?>
                                <p class="small text-muted mb-0"><?= trh('The obligation starts with the first validated initial regulatory training.') ?></p>
                            <?php else : ?>
                                <div class="progress mb-2" style="height: 1.3rem">
                                    <div class="progress-bar <?= $pct >= 100 ? 'bg-success' : ($cpd['state'] === 'late' ? 'bg-danger' : 'bg-warning text-dark') ?>" style="width: <?= max($pct, 8) ?>%"><?= $pct ?> %</div>
                                </div>
                                <p class="small mb-1"><?= trh('{done} validated since {from}, for a minimum of {required} over {years} years.', [
                                    'done' => training_hours_text($cpd['hours'], $hoursPerDay), 'from' => $cpd['window_from'],
                                    'required' => training_hours_text($cpd['required_hours'], $hoursPerDay), 'years' => $reqYears]) ?></p>
                                <?php if ($cpd['state'] === 'to_complete') : ?>
                                    <p class="small mb-0"><?= trh('First regulatory training on {date}: the minimum must be reached by {deadline}.', ['date' => $cpd['first_initial'], 'deadline' => $cpd['deadline']]) ?></p>
                                <?php elseif (in_array($cpd['state'], ['ok', 'expiring'], true) && $cpd['deadline']) : ?>
                                    <p class="small mb-0"><?= trh('Compliant until {date}, when training followed now leaves the period: plan the next one before.', ['date' => $cpd['deadline']]) ?></p>
                                <?php elseif ($cpd['state'] === 'late') : ?>
                                    <p class="small text-danger mb-0"><?= trh('Below the regulatory minimum since {date}: a continuing training is to follow without delay.', ['date' => $cpd['deadline']]) ?></p>
                                <?php endif; ?>
                            <?php endif; ?>
                            <?php if ($status['pending']) : ?><p class="small text-warning-emphasis mt-2 mb-0"><i class="fas fa-hourglass-half"></i> <?= trh('{n} entry(ies) waiting for validation are not counted yet.', ['n' => $status['pending']]) ?></p><?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="collapse no-print <?= !$records ? 'show' : '' ?>" id="addEntry">
                <form method="POST" enctype="multipart/form-data" class="card card-body mb-3 border-primary">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="user_id" value="<?= $userId ?>">
                    <h6><?= trh('Add an entry') ?> <?= $isManager ? '<small class="text-muted">(' . trh('validated at once') . ')</small>' : '<small class="text-muted">(' . trh('to be validated by an administrator or a BEA responsible') . ')</small>' ?></h6>
                    <div class="row g-2">
                        <div class="col-md-4">
                            <label class="form-label small" for="category"><?= trh('Type') ?> *</label>
                            <select class="form-select form-select-sm" name="category" id="category" required>
                                <?php foreach (training_categories() as $code => $label) : ?><option value="<?= $code ?>"><?= htmlspecialchars($label) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4" id="functionBox">
                            <label class="form-label small" for="function_code"><?= trh('Function') ?> *</label>
                            <select class="form-select form-select-sm" name="function_code" id="function_code">
                                <?php foreach (training_functions() as $code => $label) : ?><option value="<?= $code ?>" <?= $code === 'procedures' ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4" id="levelBox" hidden>
                            <label class="form-label small" for="diploma_level"><?= trh('Level') ?> *</label>
                            <select class="form-select form-select-sm" name="diploma_level" id="diploma_level">
                                <option value=""><?= trh('Choose…') ?></option>
                                <?php foreach (training_diploma_levels() as $code => $label) : ?><option value="<?= $code ?>"><?= htmlspecialchars($label) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small" for="title"><span id="titleLabel"><?= trh('Title') ?></span><span id="diplomaTitleLabel" hidden><?= trh('Title and speciality') ?> *</span></label>
                            <input class="form-control form-control-sm" name="title" id="title" maxlength="255" placeholder="<?= trh('e.g. Regulatory training rodents & lagomorphs, Congress, Surgery module') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small" for="organisation"><span id="orgLabel"><?= trh('Organisation') ?></span><span id="schoolLabel" hidden><?= trh('Institution') ?></span></label>
                            <input class="form-control form-control-sm" name="organisation" id="organisation" maxlength="255">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small" for="start_date"><span id="startLabel"><?= trh('Start Date') ?></span><span id="obtainedLabel" hidden><?= trh('Date obtained') ?></span> *</label>
                            <input type="date" class="form-control form-control-sm" name="start_date" id="start_date" required max="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="col-md-2 not-diploma">
                            <label class="form-label small" for="end_date"><?= trh('End Date') ?></label>
                            <input type="date" class="form-control form-control-sm" name="end_date" id="end_date" max="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="col-md-4 not-diploma">
                            <label class="form-label small" for="duration"><?= trh('Duration') ?> <span id="durationStar" hidden>*</span></label>
                            <div class="input-group input-group-sm">
                                <input class="form-control" name="duration" id="duration" inputmode="decimal" pattern="[0-9]+([.,][0-9]+)?">
                                <select class="form-select" name="duration_unit" style="max-width: 7rem">
                                    <option value="h"><?= trh('hours') ?></option>
                                    <option value="d"><?= trh('days ({h} h)', ['h' => rtrim(rtrim(number_format($hoursPerDay, 2, '.', ''), '0'), '.')]) ?></option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-4 not-diploma">
                            <label class="form-label small" for="species"><?= trh('Species') ?></label>
                            <input class="form-control form-control-sm" name="species" id="species" maxlength="255" placeholder="<?= trh('e.g. mouse, rat') ?>">
                        </div>
                        <div class="col-md-4" id="supervisorBox">
                            <label class="form-label small" for="supervisor_name"><?= trh('Supervisor / tutor') ?></label>
                            <input class="form-control form-control-sm" name="supervisor_name" id="supervisor_name" maxlength="255">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small" for="certificate_ref"><?= trh('Certificate number') ?></label>
                            <input class="form-control form-control-sm" name="certificate_ref" id="certificate_ref" maxlength="100">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small" for="certificate"><?= trh('Certificate (PDF)') ?></label>
                            <input type="file" class="form-control form-control-sm" name="certificate" id="certificate" accept="application/pdf">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small" for="notes"><?= trh('Notes') ?></label>
                            <input class="form-control form-control-sm" name="notes" id="notes">
                        </div>
                    </div>
                    <div class="mt-2"><button class="btn btn-primary btn-sm" name="add_entry"><i class="fas fa-save"></i> <?= trh('Record') ?></button></div>
                </form>
            </div>

            <?php foreach (training_categories() as $category => $categoryLabel) :
                $list = $byCategory[$category] ?? [];
                if (!$list && $category === 'other') {
                    continue;
                } ?>
                <h5 class="mt-3"><?= htmlspecialchars($categoryLabel) ?> <span class="badge bg-secondary"><?= count($list) ?></span></h5>
                <?php if (!$list) : ?>
                    <p class="text-muted small"><?= trh('Nothing recorded.') ?></p>
                <?php else : ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th><?= trh('Dates') ?></th><th><?= trh('Training') ?></th>
                                    <?php if (in_array($category, ['initial', 'competence'], true)) : ?><th><?= trh('Function') ?></th><?php endif; ?>
                                    <?php if ($category === 'diploma') : ?><th><?= trh('Level') ?></th><?php else : ?><th><?= trh('Duration') ?></th><th><?= trh('Species') ?></th><?php endif; ?>
                                    <?php if ($category === 'competence') : ?><th><?= trh('Supervisor / tutor') ?></th><?php endif; ?>
                                    <th><?= trh('Certificate') ?></th><th><?= trh('Status') ?></th><th class="no-print"></th>
                                </tr>
                            </thead>
                            <?php foreach ($list as $r) :
                                $inWindow = $category === 'continuing' && ($r['end_date'] ?: $r['start_date']) > $cpd['window_from']; ?>
                                <tr class="<?= $r['status'] === 'rejected' ? 'text-muted text-decoration-line-through' : ($r['status'] === 'pending' ? 'table-warning' : '') ?>">
                                    <td class="text-nowrap"><?= htmlspecialchars($r['start_date'] . ($r['end_date'] && $r['end_date'] !== $r['start_date'] ? ' → ' . $r['end_date'] : '')) ?></td>
                                    <td><strong><?= htmlspecialchars($r['title']) ?></strong><?= $r['organisation'] ? '<br><small>' . htmlspecialchars($r['organisation']) . '</small>' : '' ?><?= $r['notes'] ? '<br><small class="text-muted">' . htmlspecialchars($r['notes']) . '</small>' : '' ?></td>
                                    <?php if (in_array($category, ['initial', 'competence'], true)) : ?><td><?= htmlspecialchars(training_functions()[$r['function_code']] ?? '—') ?></td><?php endif; ?>
                                    <?php if ($category === 'diploma') : ?>
                                        <td><?= htmlspecialchars(training_diploma_levels()[$r['diploma_level']] ?? (string) $r['diploma_level']) ?></td>
                                    <?php else : ?>
                                        <td class="text-nowrap"><?= $r['duration_hours'] !== null ? htmlspecialchars(training_hours_text((float) $r['duration_hours'], $hoursPerDay)) : '—' ?>
                                            <?php if ($category === 'continuing') : ?><br><small class="<?= $inWindow ? 'text-success' : 'text-muted' ?>"><?= $inWindow ? trh('counted') : trh('outside the period') ?></small><?php endif; ?></td>
                                        <td><?= htmlspecialchars($r['species'] ?? '') ?></td>
                                    <?php endif; ?>
                                    <?php if ($category === 'competence') : ?><td><?= htmlspecialchars($r['supervisor_name'] ?? '') ?></td><?php endif; ?>
                                    <td><?= htmlspecialchars($r['certificate_ref'] ?? '') ?><?php if ($r['certificate_file']) : ?> <a href="<?= htmlspecialchars($fileUrl($r)) ?>" target="_blank" class="no-print"><i class="fas fa-file-pdf"></i> PDF</a><?php endif; ?></td>
                                    <td>
                                        <span class="badge bg-<?= $r['status'] === 'validated' ? 'success' : ($r['status'] === 'pending' ? 'warning text-dark' : 'secondary') ?>"><?= htmlspecialchars($statusLabels[$r['status']]) ?></span>
                                        <?php if ($r['status'] !== 'pending') : ?><br><small class="text-muted"><?= htmlspecialchars(trim(($r['validated_by_name'] ?? '') . ' ' . substr((string) $r['validated_at'], 0, 10))) ?></small><?php endif; ?>
                                        <?php if ($r['review_note']) : ?><br><small><em><?= htmlspecialchars($r['review_note']) ?></em></small><?php endif; ?>
                                    </td>
                                    <td class="no-print text-nowrap">
                                        <?php if ($isManager && $r['status'] === 'pending') : ?>
                                            <form method="POST" class="d-flex gap-1 mb-1">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                                <input type="hidden" name="user_id" value="<?= $userId ?>">
                                                <input type="hidden" name="entry_id" value="<?= (int) $r['id'] ?>">
                                                <input class="form-control form-control-sm" name="review_note" placeholder="<?= trh('Note (required to reject)') ?>" style="width: 11rem">
                                                <button class="btn btn-sm btn-success" name="review_entry" value="1" onclick="this.form.decision.value='validated'"><?= trh('Validate') ?></button>
                                                <button class="btn btn-sm btn-outline-danger" name="review_entry" value="1" onclick="this.form.decision.value='rejected'"><?= trh('Reject') ?></button>
                                                <input type="hidden" name="decision" value="">
                                            </form>
                                        <?php endif; ?>
                                        <?php if ($isManager || $r['status'] !== 'validated') : ?>
                                            <form method="POST" onsubmit="return confirm(<?= htmlspecialchars(trj('Delete this entry?')) ?>)">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                                <input type="hidden" name="user_id" value="<?= $userId ?>">
                                                <input type="hidden" name="entry_id" value="<?= (int) $r['id'] ?>">
                                                <button class="btn btn-sm btn-outline-secondary" name="delete_entry" title="<?= trh('Delete') ?>"><i class="fas fa-trash"></i></button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </table>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
            <p class="small text-muted mt-3"><?= trh('Regulatory basis: Directive 2010/63/EU (art. 23 and 24) and its national transposition; continuing training requirement applied: {days} days over {years} years.', ['days' => rtrim(rtrim(number_format($reqDays, 1, '.', ''), '0'), '.'), 'years' => $reqYears]) ?></p>
        </div>
    </main>
    <script>
        // Function only for initial training and competences; duration required for continuing training.
        (function() {
            const category = document.getElementById('category');
            if (!category) return;
            const sync = () => {
                const withFunction = ['initial', 'competence'].includes(category.value);
                document.getElementById('functionBox').hidden = !withFunction;
                document.getElementById('supervisorBox').hidden = category.value !== 'competence';
                document.getElementById('duration').required = category.value === 'continuing';
                document.getElementById('durationStar').hidden = category.value !== 'continuing';
                const diploma = category.value === 'diploma';
                document.getElementById('levelBox').hidden = !diploma;
                document.getElementById('diploma_level').required = diploma;
                document.getElementById('title').required = diploma;
                document.querySelectorAll('.not-diploma').forEach(el => el.hidden = diploma);
                [['titleLabel', 'diplomaTitleLabel'], ['orgLabel', 'schoolLabel'], ['startLabel', 'obtainedLabel']].forEach(([a, b]) => {
                    document.getElementById(a).hidden = diploma;
                    document.getElementById(b).hidden = !diploma;
                });
            };
            category.addEventListener('change', sync);
            sync();
        })();
    </script>
    <?php require 'footer.php'; ?>
</body>

</html>
