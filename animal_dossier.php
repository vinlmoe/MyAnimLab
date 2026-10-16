<?php

/**
 * Complete record of an animal for the BEA retrospective review, opened in a
 * new tab from experiment_retrospective.php: identity, the participation
 * (trial, protocol, experiment, authorization), every daily follow-up with
 * its scores and observations, endpoint actions, health reports with all
 * their follow-up notes, treatments, cage movements and exit. Printable.
 * Access: admin or BEA responsible.
 */
session_start();
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

$enrollmentId = (int) ($_GET['experiment_animal_id'] ?? 0);
$rows = function (string $sql, string $types, ...$params) use ($con): array {
    $stmt = $con->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $out = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $out;
};

$en = $rows(
    "SELECT ea.*, e.experiment_number, e.title AS experiment_title, e.start_date AS experiment_start, e.end_date AS experiment_end, e.status AS experiment_status,
            p.id AS protocol_id, p.protocol_number, p.title AS protocol_title, p.endpoint_criteria, p.expected_severity,
            t.id AS trial_id, t.trial_number, t.title AS trial_title, t.iacuc_id,
            m.animal_uid, m.mouse_id, m.sex, m.dob, m.distinctive_mark, m.genotype, m.notes AS animal_notes, m.cage_id, m.status AS animal_status,
            m.exit_date, m.exit_reason, m.exit_destination, m.exit_code, l.code AS line_code, l.name AS line_name, l.species,
            ra.severity_classification, ra.assessment_notes, ra.assessed_by_name, ra.assessed_at, ra.updated_at AS assessment_updated_at
     FROM experiment_animals ea
     JOIN experiments e ON e.id = ea.experiment_id
     JOIN protocols p ON p.id = e.protocol_id
     JOIN trials t ON t.id = p.trial_id
     JOIN mice m ON m.id = ea.animal_id
     LEFT JOIN animal_lines l ON l.id = m.line_id
     LEFT JOIN experiment_retrospective_assessments ra ON ra.experiment_animal_id = ea.id
     WHERE ea.id = ?",
    'i',
    $enrollmentId
)[0] ?? null;

if (!$en) {
    require 'header.php';
    echo "<div class='container mt-4'><div class='alert alert-danger'>" . trh('Participation not found.') . "</div></div>";
    require 'footer.php';
    exit;
}
$animalId = (int) $en['animal_id'];
$auth = load_authorization($con, $en['iacuc_id']);
$label = $en['animal_uid'] ?: $en['mouse_id'] ?: ('#' . $animalId);
$windowFrom = substr($en['enrolled_at'], 0, 10);
$windowTo = $en['removed_at'] ? substr($en['removed_at'], 0, 10) : date('Y-m-d');
$inWindow = fn(?string $date): bool => $date !== null && substr($date, 0, 10) >= $windowFrom && substr($date, 0, 10) <= $windowTo;

// Daily follow-ups with their grid scores and observations.
$followups = $rows("SELECT f.*, u.name AS recorded_by_name FROM experiment_followups f LEFT JOIN users u ON u.id = f.recorded_by WHERE f.experiment_animal_id = ? ORDER BY f.observed_at", 'i', $enrollmentId);
$scores = $observations = [];
if ($followups) {
    foreach ($rows("SELECT s.* FROM experiment_followup_scores s JOIN experiment_followups f ON f.id = s.followup_id WHERE f.experiment_animal_id = ? ORDER BY s.id", 'i', $enrollmentId) as $s) {
        $scores[$s['followup_id']][] = $s;
    }
    foreach ($rows("SELECT o.* FROM experiment_followup_observations o JOIN experiment_followups f ON f.id = o.followup_id WHERE f.experiment_animal_id = ? ORDER BY o.id", 'i', $enrollmentId) as $o) {
        $observations[$o['followup_id']][] = $o;
    }
}
$actions = $rows("SELECT a.*, f.observed_at FROM endpoint_actions a LEFT JOIN experiment_followups f ON f.id = a.followup_id WHERE a.experiment_animal_id = ? ORDER BY a.created_at", 'i', $enrollmentId);

// Health reports of the whole life of the animal, with every follow-up note.
$reports = $rows(HEALTH_REPORTS_WITH_STATE_SQL . " WHERE r.animal_id = ? ORDER BY r.observed_at, r.id", 'i', $animalId);
$updates = [];
if ($reports) {
    foreach ($rows("SELECT u.* FROM health_report_updates u JOIN health_reports r ON r.id = u.report_id WHERE r.animal_id = ? ORDER BY u.created_at, u.id", 'i', $animalId) as $u) {
        $updates[$u['report_id']][] = $u;
    }
}
$treatments = $rows(
    "SELECT ma.*, mp.name AS product_name, ml.lot_number, pr.prescriber_name, pr.prescription_reference
     FROM medication_administrations ma
     JOIN prescriptions pr ON pr.id = ma.prescription_id
     JOIN medication_products mp ON mp.id = pr.product_id
     JOIN medication_lots ml ON ml.id = ma.medication_lot_id
     WHERE ma.animal_id = ? ORDER BY ma.administered_at",
    'i',
    $animalId
);
$movements = $rows("SELECT mv.*, u.name AS moved_by_name FROM animal_movements mv LEFT JOIN users u ON u.id = mv.moved_by WHERE mv.animal_id = ? ORDER BY mv.moved_at, mv.id", 'i', $animalId);
$otherParticipations = $rows(
    "SELECT ea.id, ea.enrolled_at, ea.removed_at, e.experiment_number, e.title FROM experiment_animals ea JOIN experiments e ON e.id = ea.experiment_id
     WHERE ea.animal_id = ? AND ea.id <> ? ORDER BY ea.enrolled_at",
    'ii',
    $animalId,
    $enrollmentId
);

$baseline = (float) ($en['baseline_weight_g'] ?? 0);
$lossPct = fn($w): ?float => ($baseline > 0 && $w !== null) ? ($baseline - (float) $w) * 100 / $baseline : null;
$fmt = fn(?float $n, int $d = 1): string => $n === null ? '—' : number_format($n, $d, ',', ' ');
$classes = retrospective_severities();
$actionLabels = endpoint_required_actions();
$categories = health_categories();

require 'header.php';
?>
<!doctype html>
<html lang="<?= htmlspecialchars(current_locale()) ?>">

<head>
    <title><?= trh('Record of {animal}', ['animal' => $label]) ?> | <?= htmlspecialchars($labName) ?></title>
    <style>
        .dossier { max-width: 1200px; }
        .dossier h5 { margin-top: 1.5rem; border-bottom: 1px solid #dee2e6; padding-bottom: .25rem; }
        @media print {
            body > *:not(.dossier) { display: none !important; }
            .no-print { display: none !important; }
            .dossier h5 { break-after: avoid; }
        }
    </style>
</head>

<body>
    <main class="container mt-4 content dossier">
        <div class="no-print d-flex justify-content-between flex-wrap gap-2 mb-2">
            <nav class="small" aria-label="<?= trh('Sections') ?>">
                <a href="#participation"><?= trh('Participation') ?></a> ·
                <a href="#followups"><?= trh('Daily follow-ups') ?> (<?= count($followups) ?>)</a> ·
                <a href="#endpoints"><?= trh('Endpoint actions') ?> (<?= count($actions) ?>)</a> ·
                <a href="#health"><?= trh('Health reports') ?> (<?= count($reports) ?>)</a> ·
                <a href="#treatments"><?= trh('Treatments given') ?> (<?= count($treatments) ?>)</a> ·
                <a href="#movements"><?= trh('Cage movements') ?> (<?= count($movements) ?>)</a>
            </nav>
            <div>
                <button class="btn btn-primary btn-sm" onclick="window.print()"><i class="fas fa-print"></i> <?= trh('Print / PDF') ?></button>
                <a class="btn btn-outline-secondary btn-sm" href="animal_view.php?id=<?= $animalId ?>"><i class="fas fa-paw"></i> <?= trh('Animal page') ?></a>
            </div>
        </div>

        <h4 class="mb-1"><i class="fas fa-folder-open"></i> <?= trh('Record of {animal}', ['animal' => $label]) ?> <?= $en['sex'] === 'male' ? '♂' : ($en['sex'] === 'female' ? '♀' : '') ?></h4>
        <p class="text-muted mb-2"><?= trh('Experiment {number}', ['number' => $en['experiment_number']]) ?> — <?= htmlspecialchars($en['experiment_title']) ?> · <?= trh('Data of the participation period are highlighted.') ?></p>

        <div class="row g-3">
            <div class="col-md-6">
                <table class="table table-sm table-bordered mb-0">
                    <tr><th style="width: 40%"><?= trh('Line') ?></th><td><?= htmlspecialchars(trim(($en['line_code'] ?? '') . ' ' . ($en['line_name'] ?? '')) ?: '—') ?><?= $en['species'] ? ' (' . htmlspecialchars($en['species']) . ')' : '' ?></td></tr>
                    <tr><th><?= trh('Date of birth') ?></th><td><?= htmlspecialchars($en['dob'] ?: '—') ?></td></tr>
                    <tr><th><?= trh('Distinctive mark') ?></th><td><?= htmlspecialchars($en['distinctive_mark'] ?: '—') ?></td></tr>
                    <tr><th><?= trh('Genotype') ?></th><td><?= htmlspecialchars($en['genotype'] ?: '—') ?></td></tr>
                    <tr><th><?= trh('Cage') ?></th><td><?= htmlspecialchars($en['cage_id'] ?: '—') ?></td></tr>
                    <tr><th><?= trh('Exit') ?></th><td>
                        <?php if ($en['animal_status'] === 'active') : ?><?= trh('still alive in the facility') ?>
                        <?php else : ?><?= htmlspecialchars(exit_label($en['exit_code'], $en['animal_status'])) ?><?= $en['exit_date'] ? ' — ' . htmlspecialchars($en['exit_date']) : '' ?>
                            <?php if ($en['exit_reason']) : ?><br><small><?= nl2br(htmlspecialchars($en['exit_reason'])) ?></small><?php endif; ?>
                            <?php if ($en['exit_destination']) : ?><br><small><?= trh('Destination') ?> : <?= htmlspecialchars($en['exit_destination']) ?></small><?php endif; ?>
                        <?php endif; ?>
                    </td></tr>
                    <?php if ($en['animal_notes']) : ?><tr><th><?= trh('Notes') ?></th><td><?= nl2br(htmlspecialchars($en['animal_notes'])) ?></td></tr><?php endif; ?>
                </table>
            </div>
            <div class="col-md-6" id="participation">
                <table class="table table-sm table-bordered mb-0">
                    <tr><th style="width: 40%"><?= trh('Trial') ?></th><td><?= htmlspecialchars($en['trial_number'] . ' — ' . $en['trial_title']) ?></td></tr>
                    <tr><th><?= trh('Ethics number') ?></th><td><?= htmlspecialchars($en['iacuc_id'] ?: '—') ?> <?= authorization_badge($auth) ?></td></tr>
                    <tr><th><?= trh('Authorization number') ?></th><td><?= htmlspecialchars($auth['authorization_number'] ?? '—') ?> · <?= htmlspecialchars(authorization_period($auth)) ?></td></tr>
                    <tr><th><?= trh('Protocol') ?></th><td><?= htmlspecialchars($en['protocol_number'] . ' — ' . $en['protocol_title']) ?></td></tr>
                    <tr><th><?= trh('Experiment') ?></th><td><?= htmlspecialchars($en['experiment_number'] . ' — ' . $en['experiment_title']) ?></td></tr>
                    <tr><th><?= trh('Participation') ?></th><td><?= htmlspecialchars($windowFrom) ?> → <?= $en['removed_at'] ? htmlspecialchars(substr($en['removed_at'], 0, 10)) : trh('ongoing') ?>
                        <?php if ($en['removal_reason']) : ?><br><small><?= htmlspecialchars($en['removal_reason']) ?></small><?php endif; ?></td></tr>
                    <tr><th><?= trh('Baseline weight') ?></th><td><?= $baseline > 0 ? htmlspecialchars($fmt($baseline, 2)) . ' g' : '—' ?></td></tr>
                    <tr><th><?= trh('Expected severity of the protocol') ?></th><td><?= $en['expected_severity'] ? '<strong>' . htmlspecialchars($classes[$en['expected_severity']]) . '</strong>' : '<span class="text-warning">' . trh('Not specified') . '</span>' ?></td></tr>
                    <tr><th><?= trh('BEA classification') ?></th><td>
                        <?php if ($en['severity_classification']) : ?><strong><?= htmlspecialchars($classes[$en['severity_classification']]) ?></strong> · <small><?= htmlspecialchars($en['assessed_by_name']) ?>, <?= htmlspecialchars($en['assessment_updated_at'] ?: $en['assessed_at']) ?></small>
                            <br><small><?= nl2br(htmlspecialchars($en['assessment_notes'])) ?></small>
                        <?php else : ?><span class="text-warning"><?= trh('Not classified yet') ?></span><?php endif; ?>
                    </td></tr>
                </table>
            </div>
        </div>
        <?php if ($en['endpoint_criteria']) : ?>
            <div class="small mt-2"><strong><?= trh('Endpoint criteria of the protocol') ?></strong> : <?= nl2br(htmlspecialchars($en['endpoint_criteria'])) ?></div>
        <?php endif; ?>
        <?php if ($otherParticipations) : ?>
            <div class="small mt-2"><strong><?= trh('Other participations of this animal') ?></strong> :
                <?php foreach ($otherParticipations as $op) : ?>
                    <a class="me-2" href="animal_dossier.php?experiment_animal_id=<?= (int) $op['id'] ?>"><?= htmlspecialchars($op['experiment_number']) ?> (<?= htmlspecialchars(substr($op['enrolled_at'], 0, 10)) ?> → <?= htmlspecialchars($op['removed_at'] ? substr($op['removed_at'], 0, 10) : tr('ongoing')) ?>)</a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <h5 id="followups"><i class="fas fa-clipboard-check"></i> <?= trh('Daily follow-ups') ?></h5>
        <?php if (!$followups) : ?>
            <p class="text-muted"><?= trh('No follow-up recorded.') ?></p>
        <?php else : ?>
            <div class="table-responsive">
                <table class="table table-sm table-bordered align-middle">
                    <thead class="table-light">
                        <tr>
                            <th><?= trh('Date') ?></th>
                            <th class="text-end"><?= trh('Weight (g)') ?></th>
                            <th class="text-end"><?= trh('Change vs baseline') ?></th>
                            <th class="text-end"><?= trh('Clinical score') ?></th>
                            <th><?= trh('Score details') ?></th>
                            <th><?= trh('Observations') ?></th>
                            <th><?= trh('By') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($followups as $f) : $loss = $lossPct($f['weight_g']); ?>
                            <tr class="<?= $f['endpoint_reached'] ? 'table-danger' : '' ?>">
                                <td class="text-nowrap"><?= htmlspecialchars($f['observed_at']) ?><?php if ($f['endpoint_reached']) : ?><br><span class="badge bg-danger"><?= trh('Endpoint') ?></span><?php endif; ?></td>
                                <td class="text-end"><?= $f['weight_g'] !== null ? htmlspecialchars($fmt((float) $f['weight_g'], 2)) : '—' ?></td>
                                <td class="text-end"><?= $loss !== null ? htmlspecialchars(($loss > 0 ? '−' : '+') . $fmt(abs($loss))) . ' %' : '—' ?></td>
                                <td class="text-end fw-bold"><?= $f['clinical_score'] ?? '—' ?></td>
                                <td class="small">
                                    <?php foreach ($scores[$f['id']] ?? [] as $s) : ?>
                                        <div><?= htmlspecialchars($s['item_label']) ?> : <strong><?= (int) $s['score'] ?></strong><?= $s['level_description'] ? ' — ' . htmlspecialchars($s['level_description']) : '' ?></div>
                                    <?php endforeach; ?>
                                </td>
                                <td class="small">
                                    <?php foreach ($observations[$f['id']] ?? [] as $o) : ?>
                                        <div><?= htmlspecialchars($o['label']) ?> : <?= htmlspecialchars(observation_value($o)) ?></div>
                                    <?php endforeach; ?>
                                    <?php if ($f['observations']) : ?><div><?= nl2br(htmlspecialchars($f['observations'])) ?></div><?php endif; ?>
                                </td>
                                <td class="small"><?= htmlspecialchars($f['recorded_by_name'] ?? '—') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <h5 id="endpoints"><i class="fas fa-exclamation-triangle"></i> <?= trh('Endpoint actions') ?></h5>
        <?php if (!$actions) : ?>
            <p class="text-muted"><?= trh('No endpoint action.') ?></p>
        <?php else : ?>
            <table class="table table-sm table-bordered">
                <thead class="table-light"><tr><th><?= trh('Raised') ?></th><th><?= trh('Required action') ?></th><th><?= trh('Message') ?></th><th><?= trh('Status') ?></th><th><?= trh('Outcome') ?></th></tr></thead>
                <tbody>
                    <?php foreach ($actions as $a) : ?>
                        <tr>
                            <td class="text-nowrap small"><?= htmlspecialchars(substr($a['created_at'], 0, 16)) ?><?= $a['observed_at'] ? '<br>' . trh('follow-up of {date}', ['date' => $a['observed_at']]) : '' ?></td>
                            <td><?= htmlspecialchars($actionLabels[$a['required_action']] ?? $a['required_action']) ?></td>
                            <td class="small"><?= htmlspecialchars($a['message'] ?? '') ?></td>
                            <td><?= $a['status'] === 'done' ? '<span class="badge bg-success">' . trh('Done') . '</span>' : '<span class="badge bg-danger">' . trh('Pending') . '</span>' ?></td>
                            <td class="small"><?php if ($a['status'] === 'done') : ?><?= htmlspecialchars(substr((string) $a['done_at'], 0, 16)) ?> · <?= htmlspecialchars($a['done_by_name'] ?? '') ?><?= $a['done_note'] ? '<br>' . nl2br(htmlspecialchars($a['done_note'])) : '' ?><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <h5 id="health"><i class="fas fa-notes-medical"></i> <?= trh('Health reports') ?></h5>
        <?php if (!$reports) : ?>
            <p class="text-muted"><?= trh('No health report for this animal.') ?></p>
        <?php else : ?>
            <?php foreach ($reports as $r) : $during = $inWindow($r['observed_at']); ?>
                <div class="card mb-2 <?= $during ? 'border-primary' : '' ?>">
                    <div class="card-header py-1 d-flex justify-content-between flex-wrap gap-2 <?= $during ? 'bg-primary bg-opacity-10' : '' ?>">
                        <span><strong><?= htmlspecialchars($r['observed_at']) ?></strong> · <?= htmlspecialchars($categories[$r['category']] ?? $r['category']) ?> · <?= health_badge($r['severity'], HEALTH_SEVERITIES) ?>
                            <?php if ($during) : ?><span class="badge bg-primary"><?= trh('During the participation') ?></span><?php endif; ?></span>
                        <span><?= trh('Current state') ?> : <?= health_badge($r['current_status'], HEALTH_STATUSES) ?> <?= health_badge($r['current_severity'], HEALTH_SEVERITIES) ?>
                            <a class="no-print ms-1" href="health_report_view.php?id=<?= (int) $r['id'] ?>"><i class="fas fa-external-link-alt"></i></a></span>
                    </div>
                    <div class="card-body py-2 small">
                        <div class="mb-1"><?= nl2br(htmlspecialchars($r['description'])) ?> <span class="text-muted">— <?= htmlspecialchars($r['reported_by_name'] ?? '') ?>, <?= htmlspecialchars(substr($r['reported_at'], 0, 16)) ?></span></div>
                        <?php if (!empty($updates[$r['id']])) : ?>
                            <ul class="mb-0">
                                <?php foreach ($updates[$r['id']] as $u) : ?>
                                    <li><span class="text-nowrap"><?= htmlspecialchars(substr($u['created_at'], 0, 16)) ?></span> <?= health_badge($u['status'], HEALTH_STATUSES) ?> <?= health_badge($u['severity'], HEALTH_SEVERITIES) ?> <?= nl2br(htmlspecialchars($u['note'])) ?> <span class="text-muted">— <?= htmlspecialchars($u['created_by_name'] ?? '') ?></span></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <h5 id="treatments"><i class="fas fa-syringe"></i> <?= trh('Treatments given') ?></h5>
        <?php if (!$treatments) : ?>
            <p class="text-muted"><?= trh('No treatment given.') ?></p>
        <?php else : ?>
            <table class="table table-sm table-bordered">
                <thead class="table-light"><tr><th><?= trh('Date') ?></th><th><?= trh('Medication') ?></th><th><?= trh('Quantity') ?></th><th><?= trh('Administration route') ?></th><th><?= trh('Prescriber') ?></th><th><?= trh('Notes') ?></th><th><?= trh('By') ?></th></tr></thead>
                <tbody>
                    <?php foreach ($treatments as $tm) : ?>
                        <tr class="<?= $inWindow($tm['administered_at']) ? 'table-primary' : '' ?>">
                            <td class="text-nowrap"><?= htmlspecialchars(substr($tm['administered_at'], 0, 16)) ?></td>
                            <td><?= htmlspecialchars($tm['product_name']) ?> <small class="text-muted">(<?= trh('lot {lot}', ['lot' => $tm['lot_number']]) ?>)</small></td>
                            <td><?= htmlspecialchars(rtrim(rtrim((string) $tm['quantity_used'], '0'), '.') . ' ' . $tm['unit']) ?></td>
                            <td><?= htmlspecialchars($tm['route'] ?? '—') ?></td>
                            <td><?= htmlspecialchars($tm['prescriber_name']) ?><?= $tm['prescription_reference'] ? ' · ' . htmlspecialchars($tm['prescription_reference']) : '' ?></td>
                            <td class="small"><?= htmlspecialchars($tm['notes'] ?? '') ?></td>
                            <td class="small"><?= htmlspecialchars($tm['administered_by_name'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <h5 id="movements"><i class="fas fa-exchange-alt"></i> <?= trh('Cage movements') ?></h5>
        <?php if (!$movements) : ?>
            <p class="text-muted"><?= trh('No movement recorded.') ?></p>
        <?php else : ?>
            <table class="table table-sm table-bordered">
                <thead class="table-light"><tr><th><?= trh('Date') ?></th><th><?= trh('From') ?></th><th><?= trh('To') ?></th><th><?= trh('Reason') ?></th><th><?= trh('By') ?></th></tr></thead>
                <tbody>
                    <?php foreach ($movements as $mv) : ?>
                        <tr class="<?= $inWindow($mv['moved_at']) ? 'table-primary' : '' ?>">
                            <td class="text-nowrap"><?= htmlspecialchars(substr($mv['moved_at'], 0, 16)) ?></td>
                            <td><?= htmlspecialchars($mv['from_cage_id'] ?? '—') ?></td>
                            <td><?= htmlspecialchars($mv['to_cage_id']) ?></td>
                            <td class="small"><?= htmlspecialchars($mv['reason'] ?? '') ?></td>
                            <td class="small"><?= htmlspecialchars($mv['moved_by_name'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <p class="text-muted small mt-3"><?= trh('Record printed on {date} by {user}.', ['date' => date('Y-m-d H:i'), 'user' => $_SESSION['name'] ?? $_SESSION['username']]) ?></p>
    </main>
    <?php require 'footer.php'; ?>
</body>

</html>
