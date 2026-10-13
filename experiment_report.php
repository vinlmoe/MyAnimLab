<?php

/**
 * Experiment report (rapport d'expérimentation).
 *
 * Printable report of one experiment (print or save as PDF from the browser):
 * trial / protocol / experiment details, the clinical scoring grid and the
 * endpoints with their required actions, then for every enrolled animal its
 * daily follow-up (weight, loss %, each grid item score, total, observations),
 * the endpoint actions raised and how they were resolved, and its exit.
 * ?format=csv exports the follow-up rows (one per animal and day, one column
 * per grid item) for analysis in a spreadsheet.
 */

session_start();
require 'dbcon.php';
require 'animal_lib.php';

if (!isset($_SESSION['username'])) {
    $currentUrl = urlencode($_SERVER['REQUEST_URI']);
    header("Location: index.php?redirect=$currentUrl");
    exit;
}

$experimentId = (int) ($_GET['experiment_id'] ?? 0);
$stmt = $con->prepare(
    "SELECT e.*, p.id AS protocol_id, p.protocol_number, p.title AS protocol_title, p.methodology, p.endpoint_criteria, p.clinical_grid_id,
            t.id AS trial_id, t.trial_number, t.title AS trial_title, t.iacuc_id
     FROM experiments e JOIN protocols p ON p.id = e.protocol_id JOIN trials t ON t.id = p.trial_id
     WHERE e.id = ?"
);
$stmt->bind_param("i", $experimentId);
$stmt->execute();
$exp = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$exp) {
    require 'header.php';
    echo "<div class='container mt-4'><div class='alert alert-danger'>Experiment not found.</div></div>";
    require 'footer.php';
    exit;
}

$grid = $exp['clinical_grid_id'] ? load_clinical_grid($con, (int) $exp['clinical_grid_id']) : null;
$thresholds = $con->query("SELECT * FROM protocol_endpoint_thresholds WHERE protocol_id = " . (int) $exp['protocol_id'])->fetch_all(MYSQLI_ASSOC);
$obsFields = protocol_observation_fields($con, (int) $exp['protocol_id'], true);

$animals = $con->prepare(
    "SELECT ea.*, m.animal_uid, m.sex, m.genotype, m.status, m.cage_id, m.exit_code, m.exit_date, m.exit_reason, m.exit_destination,
            al.code AS line_code
     FROM experiment_animals ea JOIN mice m ON m.id = ea.animal_id LEFT JOIN animal_lines al ON al.id = m.line_id
     WHERE ea.experiment_id = ? ORDER BY m.animal_uid"
);
$animals->bind_param("i", $experimentId);
$animals->execute();
$animals = $animals->get_result()->fetch_all(MYSQLI_ASSOC);

// Follow-ups, item scores and actions of all enrolled animals.
$followups = $scores = $actions = [];
$itemLabels = $grid ? array_column($grid['items'], 'label') : [];
if ($animals) {
    $eaIds = implode(',', array_map('intval', array_column($animals, 'id')));
    foreach ($con->query("SELECT f.*, u.name AS recorded_by_name FROM experiment_followups f LEFT JOIN users u ON u.id = f.recorded_by WHERE f.experiment_animal_id IN ($eaIds) ORDER BY f.observed_at") as $f) {
        $followups[$f['experiment_animal_id']][] = $f;
    }
    foreach ($con->query("SELECT s.* FROM experiment_followup_scores s JOIN experiment_followups f ON f.id = s.followup_id WHERE f.experiment_animal_id IN ($eaIds) ORDER BY s.id") as $s) {
        $scores[$s['followup_id']][$s['item_label']] = $s;
        if (!in_array($s['item_label'], $itemLabels, true)) {
            $itemLabels[] = $s['item_label']; // item of an earlier grid version
        }
    }
    foreach ($con->query("SELECT * FROM endpoint_actions WHERE experiment_animal_id IN ($eaIds) ORDER BY created_at") as $a) {
        $actions[$a['experiment_animal_id']][] = $a;
    }
}

// Additional observations: protocol fields as columns (label snapshot with
// unit), free observations grouped in one column.
$obsColumns = array_map(fn($f) => $f['label'] . ($f['unit'] ? " ({$f['unit']})" : ''), array_filter($obsFields, fn($f) => $f['active']));
$obsByFollowup = [];
if ($animals) {
    foreach ($con->query("SELECT o.* FROM experiment_followup_observations o JOIN experiment_followups f ON f.id = o.followup_id WHERE f.experiment_animal_id IN ($eaIds) ORDER BY o.id") as $o) {
        if ($o['field_id'] !== null) {
            $col = $o['label'] . ($o['unit'] ? " ({$o['unit']})" : '');
            $obsByFollowup[$o['followup_id']]['fields'][$col] = $o;
            if (!in_array($col, $obsColumns, true)) {
                $obsColumns[] = $col;
            }
        } else {
            $obsByFollowup[$o['followup_id']]['free'][] = $o;
        }
    }
}
$freeObsText = fn($fid) => implode('; ', array_map(fn($o) => $o['label'] . ': ' . observation_value($o), $obsByFollowup[$fid]['free'] ?? []));
$obsCell = function ($fid, $col) use ($obsByFollowup) {
    $o = $obsByFollowup[$fid]['fields'][$col] ?? null;
    if (!$o) {
        return '';
    }
    return $o['value_type'] === 'number' ? rtrim(rtrim(number_format((float) $o['value_number'], 4, '.', ''), '0'), '.') : (string) $o['value_text'];
};

$lossPct = fn($ea, $f) => ($f['weight_g'] !== null && !empty($ea['baseline_weight_g']))
    ? round(($ea['baseline_weight_g'] - $f['weight_g']) / $ea['baseline_weight_g'] * 100, 1) : null;

log_activity($con, 'export', 'experiment', (string) $experimentId, ($_GET['format'] ?? 'html') . ' report', $_SESSION['user_id'] ?? null);

if (($_GET['format'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="experiment_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $exp['experiment_number']) . '_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, array_merge(['experiment', 'animal', 'line', 'sex', 'baseline_weight_g', 'date', 'weight_g', 'weight_loss_pct'], $itemLabels, ['clinical_score_total'], $obsColumns, ['other_observations', 'observations', 'endpoint_reached', 'recorded_by']));
    $safe = fn($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'" . $v : $v;
    foreach ($animals as $ea) {
        foreach ($followups[$ea['id']] ?? [] as $f) {
            $row = [$exp['experiment_number'], $ea['animal_uid'], $ea['line_code'], $ea['sex'], $ea['baseline_weight_g'], $f['observed_at'], $f['weight_g'], $lossPct($ea, $f)];
            foreach ($itemLabels as $label) {
                $row[] = $scores[$f['id']][$label]['score'] ?? '';
            }
            $row[] = $f['clinical_score'];
            foreach ($obsColumns as $col) {
                $row[] = $obsCell($f['id'], $col);
            }
            array_push($row, $freeObsText($f['id']), $f['observations'], $f['endpoint_reached'] ? 'yes' : 'no', $f['recorded_by_name']);
            fputcsv($out, array_map($safe, $row));
        }
    }
    fclose($out);
    exit;
}

require 'header.php';
?>

<!doctype html>
<html lang="en">

<head>
    <title>Report <?= htmlspecialchars($exp['experiment_number']); ?> | <?php echo htmlspecialchars($labName); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .container {
            max-width: 1200px;
        }

        .report table {
            font-size: 0.85rem;
        }

        .report h5 {
            margin-top: 1.5rem;
            border-bottom: 2px solid #333;
        }

        /* Print only the report itself (header, menus and buttons hidden). */
        @media print {
            body>*:not(.report-wrapper) {
                display: none !important;
            }

            .no-print {
                display: none !important;
            }

            .animal-section {
                page-break-inside: avoid;
            }
        }
    </style>
</head>

<body>
    <div class="container mt-4 content report-wrapper">
        <div class="no-print mb-3">
            <a href="trial_manage.php">Essais</a> <span class="text-muted">/</span>
            <a href="trial_report.php?trial_id=<?= (int) $exp['trial_id']; ?>">Rapport de l’essai</a> <span class="text-muted">/</span>
            <a href="protocol_report.php?protocol_id=<?= (int) $exp['protocol_id']; ?>">Rapport du plan</a> <span class="text-muted">/</span>
            <a href="experiment_manage.php?protocol_id=<?= (int) $exp['protocol_id']; ?>">Expérimentations</a>
            <button type="button" class="btn btn-primary btn-sm ms-3" onclick="window.print()"><i class="fas fa-print"></i> Print / Save as PDF</button>
            <a href="experiment_report.php?experiment_id=<?= $experimentId; ?>&amp;format=csv" class="btn btn-outline-secondary btn-sm"><i class="fas fa-file-csv"></i> Download data (CSV)</a>
        </div>

        <div class="report">
            <h4>Experiment report — <?= htmlspecialchars($exp['experiment_number']); ?>: <?= htmlspecialchars($exp['title']); ?></h4>
            <p class="text-muted mb-1">Generated on <?= date('Y-m-d H:i'); ?> by <?= htmlspecialchars($_SESSION['name'] ?? ''); ?> · <?= htmlspecialchars($labName); ?></p>

            <h5>Context</h5>
            <table class="table table-sm table-bordered">
                <tr><th style="width: 25%;">Trial</th><td><?= htmlspecialchars($exp['trial_number'] . ' — ' . $exp['trial_title']); ?><?= $exp['iacuc_id'] ? ' (authorisation ' . htmlspecialchars($exp['iacuc_id']) . ')' : ''; ?></td></tr>
                <tr><th>Protocol</th><td><?= htmlspecialchars($exp['protocol_number'] . ' — ' . $exp['protocol_title']); ?></td></tr>
                <tr><th>Experiment period</th><td><?= htmlspecialchars(($exp['start_date'] ?? '?') . ' → ' . ($exp['end_date'] ?? '…')); ?></td></tr>
                <tr><th>Methodology</th><td><?= nl2br(htmlspecialchars($exp['methodology'] ?? '')); ?></td></tr>
                <tr><th>Animals enrolled</th><td><?= count($animals); ?></td></tr>
            </table>

            <h5>Clinical scoring grid</h5>
            <?php if ($grid) : ?>
                <p class="mb-1"><strong><?= htmlspecialchars($grid['name']); ?></strong> — <?= htmlspecialchars($grid['description'] ?? ''); ?> (max total <?= (int) $grid['max_total']; ?>)</p>
                <table class="table table-sm table-bordered" id="gridDefinition">
                    <thead class="table-light">
                        <tr><th>Item</th><th>Levels</th></tr>
                    </thead>
                    <?php foreach ($grid['items'] as $item) : ?>
                        <tr>
                            <td class="fw-bold"><?= htmlspecialchars($item['label']); ?></td>
                            <td><?= implode(' · ', array_map(fn($l) => '<strong>' . (int) $l['score'] . '</strong> ' . htmlspecialchars($l['description']), $item['levels'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            <?php else : ?>
                <p class="text-muted">No grid: free clinical score.</p>
            <?php endif; ?>

            <h5>Additional observations</h5>
            <?php if ($obsFields) : ?>
                <table class="table table-sm table-bordered" id="obsDefinition">
                    <thead class="table-light"><tr><th>Observation</th><th>Type</th><th>Required</th></tr></thead>
                    <?php foreach ($obsFields as $of) : ?>
                        <tr><td><?= htmlspecialchars($of['label']); ?><?= $of['active'] ? '' : ' <em>(no longer asked)</em>'; ?></td><td><?= $of['value_type'] === 'number' ? 'Number' . ($of['unit'] ? ' (' . htmlspecialchars($of['unit']) . ')' : '') : 'Text'; ?></td><td><?= $of['required'] ? 'Yes' : 'No'; ?></td></tr>
                    <?php endforeach; ?>
                </table>
            <?php else : ?>
                <p class="text-muted">No protocol-defined observation (free observations are listed per entry).</p>
            <?php endif; ?>

            <h5>Endpoints (points limites)</h5>
            <?php if ($exp['endpoint_criteria']) : ?><p><?= nl2br(htmlspecialchars($exp['endpoint_criteria'])); ?></p><?php endif; ?>
            <table class="table table-sm table-bordered" id="endpointDefinition">
                <thead class="table-light">
                    <tr><th>Condition</th><th>Required action</th><th>Message</th></tr>
                </thead>
                <?php foreach ($thresholds as $t) : ?>
                    <tr>
                        <td><?= htmlspecialchars(threshold_label($t, threshold_target_label($t, $grid, $obsFields))); ?></td>
                        <td><?= htmlspecialchars(ENDPOINT_REQUIRED_ACTIONS[$t['required_action']] ?? $t['required_action']); ?></td>
                        <td><?= htmlspecialchars($t['action_message'] ?? ''); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$thresholds) : ?><tr><td colspan="3" class="text-muted">No quantified endpoint.</td></tr><?php endif; ?>
            </table>

            <h5>Summary</h5>
            <table class="table table-sm table-bordered" id="animalSummary">
                <thead class="table-light">
                    <tr><th>Animal</th><th>Line</th><th>Sex</th><th>Enrolled</th><th>Follow-ups</th><th>Max score</th><th>Max loss %</th><th>Endpoint actions</th><th>Outcome</th></tr>
                </thead>
                <?php foreach ($animals as $ea) :
                    $fs = $followups[$ea['id']] ?? [];
                    $maxScore = $fs ? max(array_map(fn($f) => (int) $f['clinical_score'], $fs)) : null;
                    $losses = array_filter(array_map(fn($f) => $lossPct($ea, $f), $fs), fn($v) => $v !== null);
                ?>
                    <tr>
                        <td><?= htmlspecialchars($ea['animal_uid']); ?></td>
                        <td><?= htmlspecialchars($ea['line_code'] ?? ''); ?></td>
                        <td><?= htmlspecialchars($ea['sex'] ?? ''); ?></td>
                        <td><?= htmlspecialchars(substr($ea['enrolled_at'], 0, 10)); ?></td>
                        <td><?= count($fs); ?></td>
                        <td><?= $maxScore ?? '-'; ?></td>
                        <td><?= $losses ? max($losses) : '-'; ?></td>
                        <td><?= count($actions[$ea['id']] ?? []); ?></td>
                        <td><?= $ea['status'] === 'active' ? 'Alive (cage ' . htmlspecialchars($ea['cage_id']) . ')' : htmlspecialchars(exit_label($ea['exit_code'], $ea['status']) . ' — ' . $ea['exit_date']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>

            <?php foreach ($animals as $ea) :
                $fs = $followups[$ea['id']] ?? []; ?>
                <div class="animal-section" data-animal="<?= htmlspecialchars($ea['animal_uid']); ?>">
                    <h5><?= htmlspecialchars($ea['animal_uid']); ?> <small class="text-muted">— <?= htmlspecialchars(trim(($ea['line_code'] ?? '') . ' ' . ($ea['sex'] ?? ''))); ?>, baseline <?= htmlspecialchars($ea['baseline_weight_g'] ?? 'N/A'); ?> g</small></h5>
                    <table class="table table-sm table-bordered followup-table">
                        <thead class="table-light">
                            <tr>
                                <th>Date</th>
                                <th>Weight (g)</th>
                                <th>Loss %</th>
                                <?php foreach ($itemLabels as $label) : ?><th><?= htmlspecialchars($label); ?></th><?php endforeach; ?>
                                <th>Total</th>
                                <?php foreach ($obsColumns as $col) : ?><th><?= htmlspecialchars($col); ?></th><?php endforeach; ?>
                                <th>Other observations</th>
                                <th>Observations</th>
                                <th>Endpoint</th>
                                <th>By</th>
                            </tr>
                        </thead>
                        <?php foreach ($fs as $f) : ?>
                            <tr class="<?= $f['endpoint_reached'] ? 'table-danger' : ''; ?>">
                                <td><?= htmlspecialchars($f['observed_at']); ?></td>
                                <td><?= htmlspecialchars($f['weight_g'] ?? '-'); ?></td>
                                <td><?= $lossPct($ea, $f) ?? '-'; ?></td>
                                <?php foreach ($itemLabels as $label) : $s = $scores[$f['id']][$label] ?? null; ?>
                                    <td title="<?= htmlspecialchars($s['level_description'] ?? ''); ?>"><?= $s ? (int) $s['score'] : '-'; ?></td>
                                <?php endforeach; ?>
                                <td><strong><?= htmlspecialchars($f['clinical_score'] ?? '-'); ?></strong></td>
                                <?php foreach ($obsColumns as $col) : $v = $obsCell($f['id'], $col); ?><td><?= $v === '' ? '-' : htmlspecialchars($v); ?></td><?php endforeach; ?>
                                <td><?= htmlspecialchars($freeObsText($f['id'])); ?></td>
                                <td><?= htmlspecialchars($f['observations'] ?? ''); ?></td>
                                <td><?= $f['endpoint_reached'] ? 'YES' : ''; ?></td>
                                <td><?= htmlspecialchars($f['recorded_by_name'] ?? ''); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$fs) : ?><tr><td colspan="<?= 8 + count($itemLabels) + count($obsColumns); ?>" class="text-muted">No follow-up recorded.</td></tr><?php endif; ?>
                    </table>

                    <?php if (!empty($actions[$ea['id']])) : ?>
                        <table class="table table-sm table-bordered actions-table">
                            <thead class="table-light">
                                <tr><th>Endpoint raised</th><th>Required action</th><th>Reason</th><th>Resolution</th></tr>
                            </thead>
                            <?php foreach ($actions[$ea['id']] as $a) : ?>
                                <tr>
                                    <td><?= htmlspecialchars($a['created_at']); ?></td>
                                    <td><?= htmlspecialchars(ENDPOINT_REQUIRED_ACTIONS[$a['required_action']]); ?></td>
                                    <td><?= htmlspecialchars($a['message'] ?? ''); ?></td>
                                    <td><?= $a['status'] === 'done' ? htmlspecialchars($a['done_at'] . ' — ' . ($a['done_by_name'] ?? '') . ': ' . ($a['done_note'] ?? '')) : '<strong class="text-danger">PENDING</strong>'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </table>
                    <?php endif; ?>

                    <?php if ($ea['status'] !== 'active') : ?>
                        <p><strong>Exit:</strong> <?= htmlspecialchars(exit_label($ea['exit_code'], $ea['status'])); ?> on <?= htmlspecialchars($ea['exit_date'] ?? ''); ?><?= $ea['exit_reason'] ? ' — ' . htmlspecialchars($ea['exit_reason']) : ''; ?><?= $ea['exit_destination'] ? ' → ' . htmlspecialchars($ea['exit_destination']) : ''; ?></p>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <br>
    <?php include 'footer.php'; ?>
</body>

</html>
