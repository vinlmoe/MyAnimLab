<?php

/**
 * BEA retrospective review: actual severity of every animal after its
 * experimental participation. Browsed by trial → protocol → experiment; the
 * classifications of an experiment are validated together, on one page, with
 * each animal's complete record one click away (animal_dossier.php, new tab).
 * Access: admin or BEA responsible.
 */
require_once 'session_config.php';
require 'dbcon.php';
require 'animal_lib.php';
require 'ethics_lib.php';

if (!isset($_SESSION['username'])) {
    header('Location: index.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}
if (!is_bea_user($con)) {
    http_response_code(403);
    exit(trh('Access restricted to the BEA responsible.'));
}

$classes = retrospective_severities();
$trialId = (int) ($_GET['trial_id'] ?? 0);
$protocolId = (int) ($_GET['protocol_id'] ?? 0);
$experimentId = (int) ($_GET['experiment_id'] ?? $_POST['experiment_id'] ?? 0);

$fetchOne = function (string $sql, int $id) use ($con): ?array {
    $stmt = $con->prepare($sql);
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
};

$experiment = $protocol = $trial = null;
if ($experimentId) {
    $experiment = $fetchOne('SELECT * FROM experiments WHERE id = ?', $experimentId);
    $protocolId = (int) ($experiment['protocol_id'] ?? 0);
}
if ($protocolId) {
    $protocol = $fetchOne('SELECT * FROM protocols WHERE id = ?', $protocolId);
    $trialId = (int) ($protocol['trial_id'] ?? 0);
}
if ($trialId) {
    $trial = $fetchOne('SELECT * FROM trials WHERE id = ?', $trialId);
}
if (($experimentId && !$experiment) || ($protocolId && !$protocol) || ($trialId && !$trial)) {
    $_SESSION['message'] = tr('Item not found.');
    header('Location: experiment_retrospective.php');
    exit;
}
$experimentUrl = 'experiment_retrospective.php?' . http_build_query(['experiment_id' => $experimentId]);

// Closed participations of the experiment with their current assessment.
$closedEnrollments = function () use ($con, $experimentId): array {
    $stmt = $con->prepare(
        "SELECT ea.id, m.animal_uid, m.mouse_id, m.id AS animal_id, ra.severity_classification, ra.assessment_notes
         FROM experiment_animals ea
         JOIN mice m ON m.id = ea.animal_id
         LEFT JOIN experiment_retrospective_assessments ra ON ra.experiment_animal_id = ea.id
         WHERE ea.experiment_id = ? AND ea.removed_at IS NOT NULL"
    );
    $stmt->bind_param('i', $experimentId);
    $stmt->execute();
    $rows = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $rows[(int) $row['id']] = $row;
    }
    $stmt->close();
    return $rows;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $experiment) {
    if (!csrf_valid()) {
        die(trh('CSRF token validation failed'));
    }
    $postedClasses = (array) ($_POST['classification'] ?? []);
    $postedNotes = (array) ($_POST['notes'] ?? []);
    $rows = $closedEnrollments();
    $missing = [];
    $entries = [];
    foreach ($rows as $id => $row) {
        $class = (string) ($postedClasses[$id] ?? '');
        $notes = trim((string) ($postedNotes[$id] ?? ''));
        $entries[$id] = ['class' => $class, 'notes' => $notes];
        if (!isset($classes[$class]) || $notes === '') {
            $missing[] = $row['animal_uid'] ?: $row['mouse_id'] ?: ('#' . $row['animal_id']);
        }
    }
    if (!$rows) {
        $_SESSION['message'] = tr('No closed participation to classify in this experiment.');
    } elseif ($missing) {
        // Nothing is saved: the page is validated as a whole.
        $_SESSION['retrospective_draft'][$experimentId] = $entries;
        $_SESSION['message'] = tr('Nothing saved: classification or justification missing for {animals}. Every animal of the experiment must be classified and justified.', ['animals' => implode(', ', $missing)]);
    } else {
        $userId = (int) $_SESSION['user_id'];
        $userName = $_SESSION['name'] ?? '';
        $changed = 0;
        $con->begin_transaction();
        try {
            $stmt = $con->prepare(
                "INSERT INTO experiment_retrospective_assessments (experiment_animal_id, severity_classification, assessment_notes, assessed_by, assessed_by_name)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE severity_classification = VALUES(severity_classification), assessment_notes = VALUES(assessment_notes),
                                         assessed_by = VALUES(assessed_by), assessed_by_name = VALUES(assessed_by_name)"
            );
            foreach ($entries as $id => $entry) {
                $row = $rows[$id];
                // An unchanged assessment keeps its author and date.
                if ($row['severity_classification'] === $entry['class'] && $row['assessment_notes'] === $entry['notes']) {
                    continue;
                }
                $stmt->bind_param('issis', $id, $entry['class'], $entry['notes'], $userId, $userName);
                $stmt->execute();
                log_activity($con, 'retrospective_assessment', 'experiment_animal', (string) $id, 'Actual severity: ' . $entry['class'] . ($row['severity_classification'] ? ' (was ' . $row['severity_classification'] . ')' : ''), $userId);
                $changed++;
            }
            $stmt->close();
            log_activity($con, 'retrospective_validation', 'experiment', (string) $experimentId, count($rows) . ' animal(s) classified, ' . $changed . ' change(s)', $userId);
            $con->commit();
            unset($_SESSION['retrospective_draft'][$experimentId]);
            $_SESSION['message'] = tr('Classifications of experiment {experiment} validated ({n} animal(s), {changed} change(s)).', ['experiment' => $experiment['experiment_number'], 'n' => count($rows), 'changed' => $changed]);
        } catch (Throwable $e) {
            $con->rollback();
            $_SESSION['retrospective_draft'][$experimentId] = $entries;
            $_SESSION['message'] = tr('Nothing saved: {error}', ['error' => error_message($e)]);
        }
    }
    header('Location: ' . $experimentUrl);
    exit;
}

// Participation counts (enrolled, closed, assessed) grouped by a column.
$counts = function (string $groupColumn, string $where = '', ?int $id = null) use ($con): array {
    $stmt = $con->prepare(
        "SELECT $groupColumn AS gid, COUNT(ea.id) AS enrolled, SUM(ea.removed_at IS NOT NULL) AS closed, SUM(ra.id IS NOT NULL) AS assessed
         FROM experiment_animals ea
         JOIN experiments e ON e.id = ea.experiment_id
         JOIN protocols p ON p.id = e.protocol_id
         LEFT JOIN experiment_retrospective_assessments ra ON ra.experiment_animal_id = ea.id
         $where GROUP BY gid"
    );
    if ($id !== null) {
        $stmt->bind_param('i', $id);
    }
    $stmt->execute();
    $out = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $out[(int) $row['gid']] = array_map('intval', $row);
    }
    $stmt->close();
    return $out;
};
$progressBadge = function (?array $c): string {
    $c = $c ?? ['enrolled' => 0, 'closed' => 0, 'assessed' => 0];
    if (!$c['closed']) {
        return '<span class="badge bg-secondary">' . trh('No closed participation') . '</span>';
    }
    $toAssess = $c['closed'] - $c['assessed'];
    return $toAssess
        ? '<span class="badge bg-warning text-dark">' . trh('{n} to classify', ['n' => $toAssess]) . '</span>'
        : '<span class="badge bg-success"><i class="fas fa-check"></i> ' . trh('All classified') . '</span>';
};
$countCells = function (?array $c): string {
    $c = $c ?? ['enrolled' => 0, 'closed' => 0, 'assessed' => 0];
    return '<td class="text-center">' . $c['enrolled'] . '</td><td class="text-center">' . $c['closed'] . '</td><td class="text-center">' . $c['assessed'] . '</td>';
};

$level = $experiment ? 'experiment' : ($protocol ? 'protocol' : ($trial ? 'trial' : 'root'));
$items = [];
$itemCounts = [];
$enrollments = [];
$draft = [];
if ($level === 'root') {
    $items = $con->query("SELECT t.*, (SELECT COUNT(*) FROM protocols p WHERE p.trial_id = t.id) AS n_children FROM trials t ORDER BY t.start_date IS NULL, t.start_date DESC, t.trial_number")->fetch_all(MYSQLI_ASSOC);
    $itemCounts = $counts('p.trial_id');
} elseif ($level === 'trial') {
    $stmt = $con->prepare("SELECT p.*, (SELECT COUNT(*) FROM experiments e WHERE e.protocol_id = p.id) AS n_children FROM protocols p WHERE p.trial_id = ? ORDER BY p.start_date IS NULL, p.start_date, p.protocol_number");
    $stmt->bind_param('i', $trialId);
    $stmt->execute();
    $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $itemCounts = $counts('e.protocol_id', 'WHERE p.trial_id = ?', $trialId);
} elseif ($level === 'protocol') {
    $stmt = $con->prepare("SELECT e.*, (SELECT COUNT(*) FROM experiment_animals ea WHERE ea.experiment_id = e.id) AS n_children FROM experiments e WHERE e.protocol_id = ? ORDER BY e.start_date IS NULL, e.start_date, e.experiment_number");
    $stmt->bind_param('i', $protocolId);
    $stmt->execute();
    $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $itemCounts = $counts('ea.experiment_id', 'WHERE e.protocol_id = ?', $protocolId);
} else {
    // One row per participation, with what the BEA needs to classify it.
    $severityRank = "'minor','moderate','severe','critical'";
    $window = "BETWEEN DATE(ea.enrolled_at) AND DATE(COALESCE(ea.removed_at, NOW()))";
    $stmt = $con->prepare(
        "SELECT ea.id AS experiment_animal_id, ea.baseline_weight_g, ea.enrolled_at, ea.removed_at, ea.removal_reason,
                m.id AS animal_id, m.animal_uid, m.mouse_id, m.sex, m.status AS animal_status, m.exit_date, m.exit_code,
                ra.severity_classification, ra.assessment_notes, ra.assessed_at, ra.assessed_by_name, ra.updated_at,
                (SELECT COUNT(*) FROM experiment_followups f WHERE f.experiment_animal_id = ea.id) AS n_followups,
                (SELECT MAX(f.clinical_score) FROM experiment_followups f WHERE f.experiment_animal_id = ea.id) AS max_score,
                (SELECT MIN(f.weight_g) FROM experiment_followups f WHERE f.experiment_animal_id = ea.id) AS min_weight,
                (SELECT SUM(f.endpoint_reached) FROM experiment_followups f WHERE f.experiment_animal_id = ea.id) AS n_endpoints,
                (SELECT COUNT(*) FROM endpoint_actions a WHERE a.experiment_animal_id = ea.id) AS n_actions,
                (SELECT COUNT(*) FROM endpoint_actions a WHERE a.experiment_animal_id = ea.id AND a.status = 'pending') AS n_pending_actions,
                (SELECT COUNT(*) FROM health_reports r WHERE r.animal_id = ea.animal_id AND r.observed_at $window) AS n_health,
                (SELECT MAX(GREATEST(FIELD(r.severity, $severityRank),
                        COALESCE((SELECT MAX(FIELD(u.severity, $severityRank)) FROM health_report_updates u WHERE u.report_id = r.id), 0)))
                   FROM health_reports r WHERE r.animal_id = ea.animal_id AND r.observed_at $window) AS worst_health,
                (SELECT COUNT(*) FROM medication_administrations ma WHERE ma.animal_id = ea.animal_id AND ma.prescription_id IS NOT NULL AND DATE(ma.administered_at) $window) AS n_treatments,
                (SELECT COUNT(*) FROM experiment_act_animals aa WHERE aa.experiment_animal_id = ea.id AND aa.status = 'done') AS n_acts
         FROM experiment_animals ea
         JOIN mice m ON m.id = ea.animal_id
         LEFT JOIN experiment_retrospective_assessments ra ON ra.experiment_animal_id = ea.id
         WHERE ea.experiment_id = ?
         ORDER BY ea.removed_at IS NULL, m.animal_uid, m.id"
    );
    $stmt->bind_param('i', $experimentId);
    $stmt->execute();
    $enrollments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $draft = $_SESSION['retrospective_draft'][$experimentId] ?? [];
    unset($_SESSION['retrospective_draft'][$experimentId]);
    $auth = load_authorization($con, $trial['iacuc_id'] ?? null);
}
$closed = array_values(array_filter($enrollments, fn($en) => $en['removed_at'] !== null));
$ongoing = array_values(array_filter($enrollments, fn($en) => $en['removed_at'] === null));
$assessedCount = count(array_filter($closed, fn($en) => $en['severity_classification']));
$healthSeverities = [1 => 'minor', 2 => 'moderate', 3 => 'severe', 4 => 'critical'];
$animalLabel = fn(array $en): string => $en['animal_uid'] ?: $en['mouse_id'] ?: ('#' . $en['animal_id']);
$statusLabel = fn(string $s): string => tr($s);
$expected = $protocol['expected_severity'] ?? null;
// Comparison of an actual severity with the protocol's expected one.
$severityRank = ['mild' => 1, 'moderate' => 2, 'severe' => 3];
$compareExpected = function (?string $actual) use ($expected, $severityRank): string {
    if (!$actual || !$expected) {
        return '';
    }
    if ($actual === $expected) {
        return '<span class="badge bg-success">' . trh('As expected') . '</span>';
    }
    if (isset($severityRank[$actual], $severityRank[$expected]) && $severityRank[$actual] < $severityRank[$expected]) {
        return '<span class="badge bg-info text-dark">' . trh('Lower than expected') . '</span>';
    }
    if (isset($severityRank[$actual], $severityRank[$expected])) {
        return '<span class="badge bg-danger">' . trh('Higher than expected') . '</span>';
    }
    return '<span class="badge bg-warning text-dark">' . trh('Different from expected') . '</span>';
};

require 'header.php';
?>
<!doctype html>
<html lang="<?= htmlspecialchars(current_locale()) ?>">

<head>
    <title><?= trh('BEA retrospective review') ?> | <?= htmlspecialchars($labName) ?></title>
    <style>
        .retro-summary dt { font-weight: normal; color: #6c757d; }
        .retro-summary dd { margin-bottom: .15rem; }
    </style>
</head>

<body>
    <main class="container mt-4 content" style="max-width: 1300px">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-2">
                <li class="breadcrumb-item<?= $level === 'root' ? ' active' : '' ?>">
                    <?php if ($level === 'root') : ?><i class="fas fa-clipboard-list"></i> <?= trh('BEA retrospective review') ?>
                    <?php else : ?><a href="experiment_retrospective.php"><i class="fas fa-clipboard-list"></i> <?= trh('BEA retrospective review') ?></a><?php endif; ?>
                </li>
                <?php if ($trial) : ?>
                    <li class="breadcrumb-item<?= $level === 'trial' ? ' active' : '' ?>">
                        <?php if ($level === 'trial') : ?><?= htmlspecialchars($trial['trial_number']) ?>
                        <?php else : ?><a href="experiment_retrospective.php?trial_id=<?= (int) $trial['id'] ?>"><?= htmlspecialchars($trial['trial_number']) ?></a><?php endif; ?>
                    </li>
                <?php endif; ?>
                <?php if ($protocol) : ?>
                    <li class="breadcrumb-item<?= $level === 'protocol' ? ' active' : '' ?>">
                        <?php if ($level === 'protocol') : ?><?= htmlspecialchars($protocol['protocol_number']) ?>
                        <?php else : ?><a href="experiment_retrospective.php?protocol_id=<?= (int) $protocol['id'] ?>"><?= htmlspecialchars($protocol['protocol_number']) ?></a><?php endif; ?>
                    </li>
                <?php endif; ?>
                <?php if ($experiment) : ?>
                    <li class="breadcrumb-item active" aria-current="page"><?= htmlspecialchars($experiment['experiment_number']) ?></li>
                <?php endif; ?>
            </ol>
        </nav>

        <h4 class="mb-1"><i class="fas fa-clipboard-list"></i>
            <?php if ($level === 'root') : ?><?= trh('BEA retrospective review — actual severity') ?>
            <?php elseif ($level === 'trial') : ?><?= trh('Trial {number}', ['number' => $trial['trial_number']]) ?> — <?= htmlspecialchars($trial['title']) ?>
            <?php elseif ($level === 'protocol') : ?><?= trh('Protocol {number}', ['number' => $protocol['protocol_number']]) ?> — <?= htmlspecialchars($protocol['title']) ?>
            <?php else : ?><?= trh('Experiment {number}', ['number' => $experiment['experiment_number']]) ?> — <?= htmlspecialchars($experiment['title']) ?><?php endif; ?>
        </h4>
        <p class="text-muted"><?= trh('Restricted to the BEA responsible. The actual severity (non-recovery, mild, moderate or severe) is assigned once the animal has left the experiment; the classifications of an experiment are validated together.') ?></p>

        <?php include 'message.php'; ?>

        <?php if ($level !== 'experiment') :
            $childLabel = ['root' => tr('Protocols'), 'trial' => tr('Experiments'), 'protocol' => tr('Animals')][$level]; ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle" id="retroNavigation">
                    <thead class="table-light">
                        <tr>
                            <th><?= $level === 'root' ? trh('Trial') : ($level === 'trial' ? trh('Protocol') : trh('Experiment')) ?></th>
                            <th><?= trh('Period') ?></th>
                            <th><?= trh('Status') ?></th>
                            <th class="text-center"><?= htmlspecialchars($childLabel) ?></th>
                            <th class="text-center"><?= trh('Participations') ?></th>
                            <th class="text-center"><?= trh('Closed') ?></th>
                            <th class="text-center"><?= trh('Classified') ?></th>
                            <th><?= trh('Retrospective review') ?></th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item) :
                            $param = ['root' => 'trial_id', 'trial' => 'protocol_id', 'protocol' => 'experiment_id'][$level];
                            $number = $item['trial_number'] ?? $item['protocol_number'] ?? $item['experiment_number'];
                            $c = $itemCounts[(int) $item['id']] ?? null;
                            $url = 'experiment_retrospective.php?' . http_build_query([$param => $item['id']]); ?>
                            <tr>
                                <td><a href="<?= htmlspecialchars($url) ?>" class="fw-bold"><?= htmlspecialchars($number) ?></a><br><small class="text-muted"><?= htmlspecialchars($item['title']) ?></small>
                                    <?php if ($level === 'trial') : ?><br><small><?= trh('Expected severity') ?> : <?= $item['expected_severity'] ? '<strong>' . htmlspecialchars($classes[$item['expected_severity']]) . '</strong>' : '<span class="text-warning">' . trh('Not specified') . '</span>' ?></small><?php endif; ?>
                                    <?php if ($level === 'root' && $item['iacuc_id']) : ?><br><small class="text-muted"><?= trh('Ethics number') ?> : <?= htmlspecialchars($item['iacuc_id']) ?></small><?php endif; ?></td>
                                <td class="text-nowrap"><?= htmlspecialchars(($item['start_date'] ?: '?') . ' → ' . ($item['end_date'] ?: '?')) ?></td>
                                <td><?= htmlspecialchars($statusLabel($item['status'])) ?></td>
                                <td class="text-center"><?= (int) $item['n_children'] ?></td>
                                <?= $countCells($c) ?>
                                <td><?= $progressBadge($c) ?></td>
                                <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= htmlspecialchars($url) ?>"><?= trh('Open') ?> <i class="fas fa-chevron-right"></i></a></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$items) : ?><tr><td colspan="9" class="text-muted"><?= trh('Nothing to show.') ?></td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php else : ?>
            <div class="card mb-3">
                <div class="card-body py-2">
                    <div class="row small g-2">
                        <div class="col-md-4"><strong><?= trh('Trial') ?></strong> : <?= htmlspecialchars($trial['trial_number'] . ' — ' . $trial['title']) ?><br>
                            <strong><?= trh('Ethics number') ?></strong> : <?= htmlspecialchars($trial['iacuc_id'] ?: '—') ?> <?= authorization_badge($auth) ?>
                            <?php if (!empty($auth['authorization_number'])) : ?><br><strong><?= trh('Authorization number') ?></strong> : <?= htmlspecialchars($auth['authorization_number']) ?><?php endif; ?></div>
                        <div class="col-md-4"><strong><?= trh('Protocol') ?></strong> : <?= htmlspecialchars($protocol['protocol_number'] . ' — ' . $protocol['title']) ?><br>
                            <strong><?= trh('Period') ?></strong> : <?= htmlspecialchars(($experiment['start_date'] ?: '?') . ' → ' . ($experiment['end_date'] ?: '?')) ?> · <?= htmlspecialchars($statusLabel($experiment['status'])) ?><br>
                            <strong><?= trh('Expected severity of the protocol') ?></strong> :
                            <?php if ($expected) : ?><span class="badge bg-primary fs-6"><?= htmlspecialchars($classes[$expected]) ?></span>
                            <?php else : ?><span class="text-warning"><i class="fas fa-exclamation-triangle"></i> <?= trh('Not specified') ?></span> <a class="small" href="protocol_manage.php?trial_id=<?= (int) $trialId ?>#protocol<?= (int) $protocolId ?>"><?= trh('Fill in') ?></a><?php endif; ?></div>
                        <div class="col-md-4">
                            <strong><?= trh('{done}/{total} closed participation(s) classified', ['done' => $assessedCount, 'total' => count($closed)]) ?></strong>
                            <?php if ($ongoing) : ?><br><span class="text-muted"><?= trh('{n} participation(s) still ongoing', ['n' => count($ongoing)]) ?></span><?php endif; ?>
                            <br><a href="experiment_report.php?experiment_id=<?= (int) $experimentId ?>" target="_blank" rel="noopener"><i class="fas fa-external-link-alt"></i> <?= trh('Experiment report') ?></a>
                        </div>
                    </div>
                </div>
            </div>

            <?php if (!$closed) : ?>
                <div class="alert alert-secondary"><?= trh('No closed participation to classify in this experiment.') ?></div>
            <?php else : ?>
                <form method="POST" id="retroForm">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="experiment_id" value="<?= (int) $experimentId ?>">

                    <div class="card mb-3 bg-light">
                        <div class="card-body py-2">
                            <div class="row g-2 align-items-end">
                                <div class="col-md-3">
                                    <label class="form-label small mb-1" for="bulkClass"><?= trh('Same classification for several animals') ?></label>
                                    <select class="form-select form-select-sm" id="bulkClass">
                                        <option value=""><?= trh('Classification…') ?></option>
                                        <?php foreach ($classes as $key => $label) : ?><option value="<?= $key ?>"><?= htmlspecialchars($label) ?></option><?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-5">
                                    <label class="form-label small mb-1" for="bulkNotes"><?= trh('Justification') ?></label>
                                    <input type="text" class="form-control form-control-sm" id="bulkNotes" placeholder="<?= trh('Justification, 3R, points to improve…') ?>">
                                </div>
                                <div class="col-md-4 d-flex gap-2">
                                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bulk="empty"><?= trh('Apply to unclassified animals') ?></button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bulk="all"><?= trh('Apply to all') ?></button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-bordered align-middle" id="retroTable">
                            <thead class="table-light">
                                <tr>
                                    <th><?= trh('Animal') ?></th>
                                    <th><?= trh('Participation') ?></th>
                                    <th style="min-width: 260px"><?= trh('Course of the experiment') ?></th>
                                    <th style="min-width: 320px"><?= trh('BEA classification') ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($closed as $en) :
                                    $id = (int) $en['experiment_animal_id'];
                                    $value = $draft[$id]['class'] ?? $en['severity_classification'];
                                    $notes = $draft[$id]['notes'] ?? $en['assessment_notes'];
                                    $loss = ($en['baseline_weight_g'] > 0 && $en['min_weight'] !== null) ? max(0, ((float) $en['baseline_weight_g'] - (float) $en['min_weight']) * 100 / (float) $en['baseline_weight_g']) : null;
                                    $worst = $healthSeverities[(int) $en['worst_health']] ?? null; ?>
                                    <tr class="<?= $en['severity_classification'] ? '' : 'table-warning' ?>" data-row>
                                        <td>
                                            <strong><?= htmlspecialchars($animalLabel($en)) ?></strong> <?= $en['sex'] === 'male' ? '♂' : ($en['sex'] === 'female' ? '♀' : '') ?>
                                            <div class="mt-1"><a class="btn btn-sm btn-outline-primary" href="animal_dossier.php?experiment_animal_id=<?= $id ?>" target="_blank" rel="noopener" title="<?= trh('Opens in a new tab') ?>"><i class="fas fa-folder-open"></i> <?= trh('Full record') ?> <i class="fas fa-external-link-alt small"></i></a></div>
                                        </td>
                                        <td class="small">
                                            <?= trh('Enrolled') ?> : <?= htmlspecialchars(substr($en['enrolled_at'], 0, 10)) ?><br>
                                            <?= trh('Removed') ?> : <?= htmlspecialchars(substr($en['removed_at'], 0, 10)) ?>
                                            <?php if ($en['removal_reason']) : ?><br><span class="text-muted"><?= htmlspecialchars($en['removal_reason']) ?></span><?php endif; ?>
                                            <br><?= trh('Exit') ?> : <?= $en['animal_status'] === 'active' ? trh('still alive in the facility') : htmlspecialchars(exit_label($en['exit_code'], $en['animal_status']) . ($en['exit_date'] ? ' (' . $en['exit_date'] . ')' : '')) ?>
                                        </td>
                                        <td class="small">
                                            <dl class="row retro-summary mb-0">
                                                <dt class="col-7"><?= trh('Daily follow-ups') ?></dt><dd class="col-5"><?= (int) $en['n_followups'] ?></dd>
                                                <dt class="col-7"><?= trh('Highest clinical score') ?></dt><dd class="col-5"><?= $en['max_score'] !== null ? (int) $en['max_score'] : '—' ?></dd>
                                                <dt class="col-7"><?= trh('Maximum weight loss') ?></dt><dd class="col-5"><?= $loss !== null ? htmlspecialchars(number_format($loss, 1, ',', ' ')) . ' %' : '—' ?></dd>
                                                <dt class="col-7"><?= trh('Endpoint reached') ?></dt><dd class="col-5"><?= (int) $en['n_endpoints'] ? '<span class="badge bg-danger">' . trh('{n} time(s)', ['n' => (int) $en['n_endpoints']]) . '</span>' : trh('No') ?>
                                                    <?php if ((int) $en['n_actions']) : ?><br><span class="text-muted"><?= trh('{n} endpoint action(s)', ['n' => (int) $en['n_actions']]) ?><?= (int) $en['n_pending_actions'] ? ', ' . trh('{n} pending', ['n' => (int) $en['n_pending_actions']]) : '' ?></span><?php endif; ?></dd>
                                                <dt class="col-7"><?= trh('Health reports') ?></dt><dd class="col-5"><?= (int) $en['n_health'] ?><?= $worst ? ' ' . health_badge($worst, HEALTH_SEVERITIES) : '' ?></dd>
                                                <dt class="col-7"><?= trh('Experimental acts done') ?></dt><dd class="col-5"><?= (int) $en['n_acts'] ?></dd>
                                                <dt class="col-7"><?= trh('Treatments given') ?></dt><dd class="col-5"><?= (int) $en['n_treatments'] ?></dd>
                                            </dl>
                                        </td>
                                        <td>
                                            <?php if ($expected) : ?><div class="small mb-1"><?= trh('Expected') ?> : <strong><?= htmlspecialchars($classes[$expected]) ?></strong> <span data-compare><?= $compareExpected($value) ?></span></div><?php endif; ?>
                                            <select class="form-select form-select-sm mb-1" name="classification[<?= $id ?>]" data-class required>
                                                <option value="" <?= !$value ? 'selected' : '' ?>><?= trh('Classification…') ?></option>
                                                <?php foreach ($classes as $key => $label) : ?><option value="<?= $key ?>" <?= $value === $key ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option><?php endforeach; ?>
                                            </select>
                                            <textarea class="form-control form-control-sm" name="notes[<?= $id ?>]" rows="2" data-notes required placeholder="<?= trh('Justification, 3R, points to improve…') ?>"><?= htmlspecialchars($notes ?? '') ?></textarea>
                                            <?php if ($en['severity_classification']) : ?>
                                                <small class="d-block text-muted mt-1"><i class="fas fa-check text-success"></i> <?= htmlspecialchars($classes[$en['severity_classification']]) ?> · <?= htmlspecialchars($en['assessed_by_name']) ?> · <?= htmlspecialchars($en['updated_at'] ?: $en['assessed_at']) ?></small>
                                            <?php else : ?>
                                                <small class="d-block text-warning mt-1"><?= trh('Not classified yet') ?></small>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
                        <small class="text-muted"><?= trh('Every animal must be classified and justified: the page is saved as a whole.') ?></small>
                        <button type="submit" class="btn btn-success"><i class="fas fa-check-double"></i> <?= trh('Validate the classifications of the experiment') ?></button>
                    </div>
                </form>
            <?php endif; ?>

            <?php if ($ongoing) : ?>
                <h5 class="mt-2"><?= trh('Participations still ongoing') ?></h5>
                <p class="text-muted small"><?= trh('These animals are still in the experiment; they can be classified once their participation is closed.') ?></p>
                <ul class="list-group mb-4">
                    <?php foreach ($ongoing as $en) : ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <span><strong><?= htmlspecialchars($animalLabel($en)) ?></strong> · <?= trh('Enrolled') ?> : <?= htmlspecialchars(substr($en['enrolled_at'], 0, 10)) ?> · <?= trh('{n} follow-up(s)', ['n' => (int) $en['n_followups']]) ?></span>
                            <a class="btn btn-sm btn-outline-secondary" href="animal_dossier.php?experiment_animal_id=<?= (int) $en['experiment_animal_id'] ?>" target="_blank" rel="noopener"><i class="fas fa-folder-open"></i> <?= trh('Full record') ?> <i class="fas fa-external-link-alt small"></i></a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <a class="btn btn-outline-secondary mb-4" href="experiment_retrospective.php?protocol_id=<?= (int) $protocolId ?>"><i class="fas fa-arrow-left"></i> <?= trh('Back to the protocol') ?></a>
        <?php endif; ?>
    </main>
    <script>
        // Live comparison of each chosen classification with the expected one.
        var expectedSeverity = <?= json_encode($expected) ?>;
        var rank = {mild: 1, moderate: 2, severe: 3};
        var compareLabels = <?= json_encode([
            'same' => '<span class="badge bg-success">' . tr('As expected') . '</span>',
            'lower' => '<span class="badge bg-info text-dark">' . tr('Lower than expected') . '</span>',
            'higher' => '<span class="badge bg-danger">' . tr('Higher than expected') . '</span>',
            'other' => '<span class="badge bg-warning text-dark">' . tr('Different from expected') . '</span>',
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        function refreshCompare(row) {
            var target = row.querySelector('[data-compare]');
            var value = row.querySelector('[data-class]').value;
            if (!target || !expectedSeverity) {
                return;
            }
            if (!value) {
                target.innerHTML = '';
            } else if (value === expectedSeverity) {
                target.innerHTML = compareLabels.same;
            } else if (rank[value] && rank[expectedSeverity]) {
                target.innerHTML = rank[value] < rank[expectedSeverity] ? compareLabels.lower : compareLabels.higher;
            } else {
                target.innerHTML = compareLabels.other;
            }
        }
        document.querySelectorAll('#retroTable [data-row]').forEach(function (row) {
            row.querySelector('[data-class]').addEventListener('change', function () { refreshCompare(row); });
        });
        document.querySelectorAll('[data-bulk]').forEach(function (button) {
            button.addEventListener('click', function () {
                var cls = document.getElementById('bulkClass').value;
                var notes = document.getElementById('bulkNotes').value.trim();
                var onlyEmpty = button.dataset.bulk === 'empty';
                document.querySelectorAll('#retroTable [data-row]').forEach(function (row) {
                    var select = row.querySelector('[data-class]');
                    var area = row.querySelector('[data-notes]');
                    if (onlyEmpty && select.value) {
                        return;
                    }
                    if (cls) {
                        select.value = cls;
                        refreshCompare(row);
                    }
                    if (notes && (!onlyEmpty || !area.value.trim())) {
                        area.value = notes;
                    }
                });
            });
        });
    </script>
    <?php require 'footer.php'; ?>
</body>

</html>
