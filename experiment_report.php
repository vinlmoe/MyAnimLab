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
 * per grid item) for analysis in a spreadsheet. The experimental acts are
 * listed too (definition, then per animal), and ?format=acts_csv exports them
 * (one row per act, animal and planned date, with the values entered).
 */

require_once 'session_config.php';
require 'dbcon.php';
require 'animal_lib.php';
require 'experiment_acts_lib.php';

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
    echo "<div class='container mt-4'><div class='alert alert-danger'>" . trh('Experiment not found.') . "</div></div>";
    require 'footer.php';
    exit;
}

$grid = $exp['clinical_grid_id'] ? load_clinical_grid($con, (int) $exp['clinical_grid_id']) : null;
$thresholds = db_rows($con, "SELECT * FROM protocol_endpoint_thresholds WHERE protocol_id = ?", 'i', [(int) $exp['protocol_id']]);
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
    $eaIds = array_map('intval', array_column($animals, 'id'));
    $eaIn = db_placeholders(count($eaIds));
    $eaTypes = str_repeat('i', count($eaIds));
    foreach (db_rows($con, "SELECT f.*, u.name AS recorded_by_name FROM experiment_followups f LEFT JOIN users u ON u.id = f.recorded_by WHERE f.experiment_animal_id IN ($eaIn) ORDER BY f.observed_at", $eaTypes, $eaIds) as $f) {
        $followups[$f['experiment_animal_id']][] = $f;
    }
    foreach (db_rows($con, "SELECT s.* FROM experiment_followup_scores s JOIN experiment_followups f ON f.id = s.followup_id WHERE f.experiment_animal_id IN ($eaIn) ORDER BY s.id", $eaTypes, $eaIds) as $s) {
        $scores[$s['followup_id']][$s['item_label']] = $s;
        if (!in_array($s['item_label'], $itemLabels, true)) {
            $itemLabels[] = $s['item_label']; // item of an earlier grid version
        }
    }
    foreach (db_rows($con, "SELECT * FROM endpoint_actions WHERE experiment_animal_id IN ($eaIn) ORDER BY created_at", $eaTypes, $eaIds) as $a) {
        $actions[$a['experiment_animal_id']][] = $a;
    }
}

// Additional observations: protocol fields as columns (label snapshot with
// unit), free observations grouped in one column.
$obsColumns = array_map(fn($f) => $f['label'] . ($f['unit'] ? " ({$f['unit']})" : ''), array_filter($obsFields, fn($f) => $f['active']));
$obsByFollowup = [];
if ($animals) {
    foreach (db_rows($con, "SELECT o.* FROM experiment_followup_observations o JOIN experiment_followups f ON f.id = o.followup_id WHERE f.experiment_animal_id IN ($eaIn) ORDER BY o.id", $eaTypes, $eaIds) as $o) {
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

// Experimental acts: definitions, then one row per animal and planned date.
$stmt = $con->prepare(
    "SELECT a.*, mp.name AS product_name, mp.default_unit AS product_unit FROM experiment_acts a
     LEFT JOIN medication_products mp ON mp.id = a.product_id WHERE a.experiment_id = ? ORDER BY a.id"
);
$stmt->bind_param('i', $experimentId);
$stmt->execute();
$expActs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$actFieldsByAct = experiment_act_fields($con, array_column($expActs, 'id'));
$stmt = $con->prepare(EXPERIMENT_ACT_ROW_SQL . ' WHERE a.experiment_id = ? ORDER BY m.animal_uid, aa.planned_date, a.id');
$stmt->bind_param('i', $experimentId);
$stmt->execute();
$actRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$actValues = experiment_act_values($con, array_column($actRows, 'id'));
$actCorrections = experiment_act_corrections($con, array_column($actRows, 'id'));
$actsByEnrollment = $actCounts = [];
foreach ($actRows as $r) {
    $actsByEnrollment[(int) $r['experiment_animal_id']][] = $r;
    $actCounts[(int) $r['act_id']][$r['status']] = ($actCounts[(int) $r['act_id']][$r['status']] ?? 0) + 1;
    $actCounts[(int) $r['act_id']]['dates'][$r['planned_date']] = true;
}
$actValuesText = fn(int $id): string => implode('; ', array_map(fn($v) => $v['label'] . ': ' . observation_value($v), $actValues[$id] ?? []));
$actStatus = experiment_act_statuses();

$lossPct = fn($ea, $f) => ($f['weight_g'] !== null && !empty($ea['baseline_weight_g']))
    ? round(($ea['baseline_weight_g'] - $f['weight_g']) / $ea['baseline_weight_g'] * 100, 1) : null;

log_activity($con, 'export', 'experiment', (string) $experimentId, tr('{format} report', ['format' => $_GET['format'] ?? 'html']), $_SESSION['user_id'] ?? null);

if (($_GET['format'] ?? '') === 'acts_csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="experiment_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $exp['experiment_number']) . '_acts_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['experiment', 'animal', 'cage', 'act_type', 'act', 'substance', 'dose', 'dose_unit', 'route', 'planned_date', 'status', 'done_at', 'done_by', 'supervised_by',
        'medication', 'lot', 'quantity_used', 'unit', 'values', 'note']);
    $safe = fn($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'" . $v : $v;
    foreach ($actRows as $r) {
        fputcsv($out, array_map($safe, [
            $exp['experiment_number'], $r['animal_uid'] ?: $r['mouse_id'], $r['cage_id'], $r['act_type'], $r['label'], $r['substance'],
            act_number($r['dose_amount']), $r['dose_unit'], $r['route'], $r['planned_date'], $r['status'],
            $r['status'] === 'planned' ? '' : $r['done_at'], $r['done_by_name'], $r['supervised_by_name'],
            $r['medication_lot_id'] ? $r['product_name'] : '', $r['lot_number'], act_number($r['quantity_used']), $r['medication_lot_id'] ? $r['product_unit'] : '',
            $actValuesText((int) $r['id']), $r['note'],
        ]));
    }
    fclose($out);
    exit;
}

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
<html lang="<?= htmlspecialchars(current_locale()) ?>">

<head>
    <title><?= trh('Report {number}', ['number' => $exp['experiment_number']]); ?> | <?php echo htmlspecialchars($labName); ?></title>
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
            <button type="button" class="btn btn-primary btn-sm ms-3" onclick="window.print()"><i class="fas fa-print"></i> <?= trh('Print / Save as PDF') ?></button>
            <a href="experiment_report.php?experiment_id=<?= $experimentId; ?>&amp;format=csv" class="btn btn-outline-secondary btn-sm"><i class="fas fa-file-csv"></i> <?= trh('Download data (CSV)') ?></a>
            <?php if ($expActs) : ?><a href="experiment_report.php?experiment_id=<?= $experimentId; ?>&amp;format=acts_csv" class="btn btn-outline-secondary btn-sm"><i class="fas fa-file-csv"></i> <?= trh('Download the acts (CSV)') ?></a><?php endif; ?>
        </div>

        <div class="report">
            <h4><?= trh('Experiment report — {number}: {title}', ['number' => $exp['experiment_number'], 'title' => $exp['title']]); ?></h4>
            <p class="text-muted mb-1"><?= trh('Generated on {date} by {name}', ['date' => date('Y-m-d H:i'), 'name' => $_SESSION['name'] ?? '']); ?> · <?= htmlspecialchars($labName); ?></p>

            <h5><?= trh('Context') ?></h5>
            <table class="table table-sm table-bordered">
                <tr><th style="width: 25%;"><?= trh('Trial') ?></th><td><?= htmlspecialchars($exp['trial_number'] . ' — ' . $exp['trial_title']); ?><?= $exp['iacuc_id'] ? ' (' . trh('authorisation {id}', ['id' => $exp['iacuc_id']]) . ')' : ''; ?></td></tr>
                <tr><th><?= trh('Protocol') ?></th><td><?= htmlspecialchars($exp['protocol_number'] . ' — ' . $exp['protocol_title']); ?></td></tr>
                <tr><th><?= trh('Experiment period') ?></th><td><?= htmlspecialchars(($exp['start_date'] ?? '?') . ' → ' . ($exp['end_date'] ?? '…')); ?></td></tr>
                <tr><th><?= trh('Methodology') ?></th><td><?= nl2br(htmlspecialchars($exp['methodology'] ?? '')); ?></td></tr>
                <tr><th><?= trh('Animals enrolled') ?></th><td><?= count($animals); ?></td></tr>
            </table>

            <h5><?= trh('Clinical scoring grid') ?></h5>
            <?php if ($grid) : ?>
                <p class="mb-1"><strong><?= htmlspecialchars($grid['name']); ?></strong> — <?= htmlspecialchars($grid['description'] ?? ''); ?> (<?= trh('max total {n}', ['n' => (int) $grid['max_total']]); ?>)</p>
                <table class="table table-sm table-bordered" id="gridDefinition">
                    <thead class="table-light">
                        <tr><th><?= trh('Item') ?></th><th><?= trh('Levels') ?></th></tr>
                    </thead>
                    <?php foreach ($grid['items'] as $item) : ?>
                        <tr>
                            <td class="fw-bold"><?= htmlspecialchars($item['label']); ?></td>
                            <td><?= implode(' · ', array_map(fn($l) => '<strong>' . (int) $l['score'] . '</strong> ' . htmlspecialchars($l['description']), $item['levels'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            <?php else : ?>
                <p class="text-muted"><?= trh('No grid: free clinical score.') ?></p>
            <?php endif; ?>

            <h5><?= trh('Additional observations') ?></h5>
            <?php if ($obsFields) : ?>
                <table class="table table-sm table-bordered" id="obsDefinition">
                    <thead class="table-light"><tr><th><?= trh('Observation') ?></th><th><?= trh('Type') ?></th><th><?= trh('Required') ?></th></tr></thead>
                    <?php foreach ($obsFields as $of) : ?>
                        <tr><td><?= htmlspecialchars($of['label']); ?><?= $of['active'] ? '' : ' <em>(' . trh('no longer asked') . ')</em>'; ?></td><td><?= $of['value_type'] === 'number' ? trh('Number value') . ($of['unit'] ? ' (' . htmlspecialchars($of['unit']) . ')' : '') : trh('Text'); ?></td><td><?= $of['required'] ? trh('Yes') : trh('No'); ?></td></tr>
                    <?php endforeach; ?>
                </table>
            <?php else : ?>
                <p class="text-muted"><?= trh('No protocol-defined observation (free observations are listed per entry).') ?></p>
            <?php endif; ?>

            <h5><?= trh('Endpoints') ?></h5>
            <?php if ($exp['endpoint_criteria']) : ?><p><?= nl2br(htmlspecialchars($exp['endpoint_criteria'])); ?></p><?php endif; ?>
            <table class="table table-sm table-bordered" id="endpointDefinition">
                <thead class="table-light">
                    <tr><th><?= trh('Condition') ?></th><th><?= trh('Required action') ?></th><th><?= trh('Message') ?></th></tr>
                </thead>
                <?php foreach ($thresholds as $t) : ?>
                    <tr>
                        <td><?= htmlspecialchars(threshold_label($t, threshold_target_label($t, $grid, $obsFields))); ?></td>
                        <td><?= htmlspecialchars(endpoint_required_actions()[$t['required_action']] ?? $t['required_action']); ?></td>
                        <td><?= htmlspecialchars($t['action_message'] ?? ''); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$thresholds) : ?><tr><td colspan="3" class="text-muted"><?= trh('No quantified endpoint.') ?></td></tr><?php endif; ?>
            </table>

            <h5><?= trh('Experimental acts') ?></h5>
            <?php if ($expActs) : ?>
                <table class="table table-sm table-bordered" id="actDefinition">
                    <thead class="table-light">
                        <tr><th><?= trh('Act') ?></th><th><?= trh('Substance, dose, route') ?></th><th><?= trh('Medication of the stock') ?></th><th><?= trh('Information asked') ?></th><th><?= trh('Dates') ?></th><th><?= trh('Status') ?></th></tr>
                    </thead>
                    <?php foreach ($expActs as $act) : $c = $actCounts[(int) $act['id']] ?? []; ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($act['label']); ?></strong><br><small><?= htmlspecialchars(experiment_act_types()[$act['act_type']] ?? $act['act_type']); ?></small><?php if ($act['instructions']) : ?><br><small class="text-muted"><?= htmlspecialchars($act['instructions']); ?></small><?php endif; ?></td>
                            <td><?= htmlspecialchars(experiment_act_summary($act) ?: '—'); ?></td>
                            <td><?= $act['product_id'] ? trh('{product}, {qty} {unit} per animal', ['product' => $act['product_name'], 'qty' => act_number($act['quantity_per_animal']), 'unit' => $act['product_unit']]) : '—'; ?></td>
                            <td><?= htmlspecialchars(implode(', ', array_map(fn($f) => $f['label'] . ($f['unit'] ? ' (' . $f['unit'] . ')' : '') . ($f['required'] ? ' *' : ''), $actFieldsByAct[(int) $act['id']] ?? [])) ?: '—'); ?></td>
                            <td><?= htmlspecialchars(implode(', ', array_map(fn($d) => date('d/m/Y', strtotime($d)), array_keys($c['dates'] ?? [])))); ?></td>
                            <td class="text-nowrap"><?php foreach (['done', 'not_done', 'planned', 'cancelled'] as $st) : if (!empty($c[$st])) : ?><?= htmlspecialchars($actStatus[$st]); ?> : <?= (int) $c[$st]; ?><br><?php endif; endforeach; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            <?php else : ?>
                <p class="text-muted"><?= trh('No experimental act planned.') ?></p>
            <?php endif; ?>

            <h5><?= trh('Summary') ?></h5>
            <table class="table table-sm table-bordered" id="animalSummary">
                <thead class="table-light">
                    <tr><th><?= trh('Animal') ?></th><th><?= trh('Line') ?></th><th><?= trh('Sex') ?></th><th><?= trh('Enrolled') ?></th><th><?= trh('Follow-ups') ?></th><th><?= trh('Max score') ?></th><th><?= trh('Max loss %') ?></th><th><?= trh('Endpoint actions') ?></th><th><?= trh('Experimental acts done') ?></th><th><?= trh('Outcome') ?></th></tr>
                </thead>
                <?php foreach ($animals as $ea) :
                    $fs = $followups[$ea['id']] ?? [];
                    $maxScore = $fs ? max(array_map(fn($f) => (int) $f['clinical_score'], $fs)) : null;
                    $losses = array_filter(array_map(fn($f) => $lossPct($ea, $f), $fs), fn($v) => $v !== null);
                ?>
                    <tr>
                        <td><?= htmlspecialchars($ea['animal_uid']); ?></td>
                        <td><?= htmlspecialchars($ea['line_code'] ?? ''); ?></td>
                        <td><?= trh((string) ($ea['sex'] ?? '')); ?></td>
                        <td><?= htmlspecialchars(substr($ea['enrolled_at'], 0, 10)); ?></td>
                        <td><?= count($fs); ?></td>
                        <td><?= $maxScore ?? '-'; ?></td>
                        <td><?= $losses ? max($losses) : '-'; ?></td>
                        <td><?= count($actions[$ea['id']] ?? []); ?></td>
                        <td><?= count(array_filter($actsByEnrollment[(int) $ea['id']] ?? [], fn($r) => $r['status'] === 'done')); ?><?php $nd = count(array_filter($actsByEnrollment[(int) $ea['id']] ?? [], fn($r) => $r['status'] === 'not_done')); ?><?= $nd ? ' <span class="text-danger">(' . trh('{n} not done', ['n' => $nd]) . ')</span>' : ''; ?></td>
                        <td><?= $ea['status'] === 'active' ? trh('Alive (cage {cage})', ['cage' => $ea['cage_id']]) : htmlspecialchars(exit_label($ea['exit_code'], $ea['status']) . ' — ' . $ea['exit_date']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>

            <?php foreach ($animals as $ea) :
                $fs = $followups[$ea['id']] ?? []; ?>
                <div class="animal-section" data-animal="<?= htmlspecialchars($ea['animal_uid']); ?>">
                    <h5><?= htmlspecialchars($ea['animal_uid']); ?> <small class="text-muted">— <?= htmlspecialchars(trim(($ea['line_code'] ?? '') . ' ' . tr((string) ($ea['sex'] ?? '')))); ?>, <?= trh('baseline {weight} g', ['weight' => $ea['baseline_weight_g'] ?? tr('N/A')]); ?></small></h5>
                    <table class="table table-sm table-bordered followup-table">
                        <thead class="table-light">
                            <tr>
                                <th><?= trh('Date') ?></th>
                                <th><?= trh('Weight (g)') ?></th>
                                <th><?= trh('Loss %') ?></th>
                                <?php foreach ($itemLabels as $label) : ?><th><?= htmlspecialchars($label); ?></th><?php endforeach; ?>
                                <th><?= trh('Total') ?></th>
                                <?php foreach ($obsColumns as $col) : ?><th><?= htmlspecialchars($col); ?></th><?php endforeach; ?>
                                <th><?= trh('Other observations') ?></th>
                                <th><?= trh('Observations') ?></th>
                                <th><?= trh('Endpoint') ?></th>
                                <th><?= trh('By') ?></th>
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
                                <td><?= $f['endpoint_reached'] ? trh('YES') : ''; ?></td>
                                <td><?= htmlspecialchars($f['recorded_by_name'] ?? ''); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$fs) : ?><tr><td colspan="<?= 8 + count($itemLabels) + count($obsColumns); ?>" class="text-muted"><?= trh('No follow-up recorded.') ?></td></tr><?php endif; ?>
                    </table>

                    <?php if (!empty($actions[$ea['id']])) : ?>
                        <table class="table table-sm table-bordered actions-table">
                            <thead class="table-light">
                                <tr><th><?= trh('Endpoint raised') ?></th><th><?= trh('Required action') ?></th><th><?= trh('Reason') ?></th><th><?= trh('Resolution') ?></th></tr>
                            </thead>
                            <?php foreach ($actions[$ea['id']] as $a) : ?>
                                <tr>
                                    <td><?= htmlspecialchars($a['created_at']); ?></td>
                                    <td><?= htmlspecialchars(endpoint_required_actions()[$a['required_action']]); ?></td>
                                    <td><?= htmlspecialchars($a['message'] ?? ''); ?></td>
                                    <td><?= $a['status'] === 'done' ? htmlspecialchars($a['done_at'] . ' — ' . ($a['done_by_name'] ?? '') . ': ' . ($a['done_note'] ?? '')) : '<strong class="text-danger">' . trh('PENDING') . '</strong>'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </table>
                    <?php endif; ?>

                    <?php if (!empty($actsByEnrollment[(int) $ea['id']])) : ?>
                        <table class="table table-sm table-bordered acts-table">
                            <thead class="table-light">
                                <tr><th><?= trh('Planned') ?></th><th><?= trh('Act') ?></th><th><?= trh('Status') ?></th><th><?= trh('Done on') ?></th><th><?= trh('Medication') ?></th><th><?= trh('Information') ?></th><th><?= trh('By') ?></th></tr>
                            </thead>
                            <?php foreach ($actsByEnrollment[(int) $ea['id']] as $r) : ?>
                                <tr class="<?= $r['status'] === 'not_done' ? 'table-danger' : ($r['status'] === 'cancelled' ? 'text-muted' : ''); ?>">
                                    <td class="text-nowrap"><?= htmlspecialchars($r['planned_date']); ?></td>
                                    <td><?= htmlspecialchars($r['label']); ?><?php if ($summary = experiment_act_summary($r)) : ?> <small class="text-muted">(<?= htmlspecialchars($summary); ?>)</small><?php endif; ?></td>
                                    <td><?= htmlspecialchars($actStatus[$r['status']]); ?></td>
                                    <td class="text-nowrap"><?= $r['status'] === 'planned' ? '' : htmlspecialchars(substr((string) $r['done_at'], 0, 16)); ?></td>
                                    <td><?= $r['medication_lot_id'] ? trh('{qty} {unit}, lot {lot}', ['qty' => act_number($r['quantity_used']), 'unit' => $r['product_unit'], 'lot' => $r['lot_number']]) : ''; ?></td>
                                    <td><?= htmlspecialchars($actValuesText((int) $r['id'])); ?><?= $r['note'] ? ' <em>' . htmlspecialchars($r['note']) . '</em>' : ''; ?>
                                        <?php foreach ($actCorrections[(int) $r['id']] ?? [] as $c) : ?><div class="small text-warning-emphasis"><i class="fas fa-undo"></i> <?= htmlspecialchars(act_correction_text($c)); ?></div><?php endforeach; ?></td>
                                    <td><?= htmlspecialchars(act_done_by_text($r)); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </table>
                    <?php endif; ?>

                    <?php if ($ea['status'] !== 'active') : ?>
                        <p><strong><?= trh('Exit:') ?></strong> <?= trh('{label} on {date}', ['label' => exit_label($ea['exit_code'], $ea['status']), 'date' => $ea['exit_date'] ?? '']); ?><?= $ea['exit_reason'] ? ' — ' . htmlspecialchars($ea['exit_reason']) : ''; ?><?= $ea['exit_destination'] ? ' → ' . htmlspecialchars($ea['exit_destination']) : ''; ?></p>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <br>
    <?php include 'footer.php'; ?>
</body>

</html>
