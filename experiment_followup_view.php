<?php

/**
 * Read-only view of one daily experimental follow-up: for each item of the
 * clinical grid, the level chosen among the whole scale, with the weight,
 * observations, endpoint and the actions it raised. Previous / next day of
 * the same participation are one click away.
 */
require_once 'session_config.php';
require 'dbcon.php';
require 'animal_lib.php';
require 'experiment_acts_lib.php';
require_once 'followup_lib.php';

if (!isset($_SESSION['username'])) {
    header('Location: index.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

$followupId = (int) ($_GET['id'] ?? 0);
$returnUrl = safe_local_return($_GET['return'] ?? null, ['experiment_followup_view.php']);
$stmt = $con->prepare(
    "SELECT f.*, u.name AS recorded_by_name, ea.baseline_weight_g, ea.removed_at, ea.enrolled_at, ea.id AS experiment_animal_id,
            m.id AS animal_id, m.animal_uid, m.mouse_id, m.sex, m.distinctive_mark, m.cage_id,
            e.experiment_number, e.title AS experiment_title, p.id AS protocol_id, p.protocol_number, p.clinical_grid_id
     FROM experiment_followups f
     JOIN experiment_animals ea ON ea.id = f.experiment_animal_id
     JOIN mice m ON m.id = ea.animal_id
     JOIN experiments e ON e.id = ea.experiment_id
     JOIN protocols p ON p.id = e.protocol_id
     LEFT JOIN users u ON u.id = f.recorded_by
     WHERE f.id = ?"
);
$stmt->bind_param('i', $followupId);
$stmt->execute();
$f = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$f) {
    require 'header.php';
    echo "<div class='container mt-4'><div class='alert alert-danger'>" . trh('Follow-up not found.') . "</div></div>";
    require 'footer.php';
    exit;
}

$fetchAll = function (string $sql, int $id) use ($con): array {
    $stmt = $con->prepare($sql);
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
};

// Recorded scores (label and level snapshots) with the full scale of their
// grid item. Grids are frozen once used, so the scale is the one scored.
$scores = $fetchAll("SELECT * FROM experiment_followup_scores WHERE followup_id = ? ORDER BY id", $followupId);
$levelsByItem = [];
$itemIds = array_values(array_filter(array_map(fn($s) => (int) $s['grid_item_id'], $scores)));
if ($itemIds) {
    foreach (db_rows($con, "SELECT item_id, score, description FROM clinical_grid_levels WHERE item_id IN (" . db_placeholders(count($itemIds)) . ") ORDER BY item_id, score", str_repeat('i', count($itemIds)), $itemIds) as $level) {
        $levelsByItem[(int) $level['item_id']][] = $level;
    }
}
$itemMax = fn(array $s): ?int => !empty($levelsByItem[(int) $s['grid_item_id']]) ? max(array_column($levelsByItem[(int) $s['grid_item_id']], 'score')) : null;
$gridMax = array_sum(array_map(fn($s) => (int) $itemMax($s), $scores));
$observations = $fetchAll("SELECT * FROM experiment_followup_observations WHERE followup_id = ? ORDER BY field_id IS NULL, id", $followupId);
$actions = $fetchAll("SELECT * FROM endpoint_actions WHERE followup_id = ? ORDER BY id", $followupId);
// Experimental acts confirmed that day for this participation.
$dayActs = array_values(array_filter(
    enrollment_acts($con, (int) $f['experiment_animal_id'], $f['observed_at']),
    fn($a) => $a['status'] !== 'planned' && substr((string) $a['done_at'], 0, 10) === $f['observed_at']
));
$dayActValues = experiment_act_values($con, array_column($dayActs, 'id'));

// Previous / next follow-ups of the same participation.
$neighbours = $fetchAll("SELECT id, observed_at FROM experiment_followups WHERE experiment_animal_id = ? ORDER BY observed_at", (int) $f['experiment_animal_id']);
$position = array_search($followupId, array_map('intval', array_column($neighbours, 'id')), true);
$previous = $position > 0 ? $neighbours[$position - 1] : null;
$next = $neighbours[$position + 1] ?? null;
$viewUrl = fn(array $n): string => 'experiment_followup_view.php?' . http_build_query(array_filter(['id' => $n['id'], 'return' => $returnUrl]));

$label = $f['animal_uid'] ?: $f['mouse_id'] ?: ('#' . $f['animal_id']);
$baseline = (float) ($f['baseline_weight_g'] ?? 0);
$change = ($baseline > 0 && $f['weight_g'] !== null) ? ((float) $f['weight_g'] - $baseline) * 100 / $baseline : null;
$actionLabels = endpoint_required_actions();
$editUrl = 'experiment_followup.php?' . http_build_query(['experiment_animal_id' => $f['experiment_animal_id'], 'date' => $f['observed_at'], 'return' => 'experiment_manage.php?protocol_id=' . $f['protocol_id']]);
$backUrl = $returnUrl ?? 'experiment_manage.php?protocol_id=' . (int) $f['protocol_id'];
$levelClass = function (int $score, ?int $max): string {
    if ($score === 0) {
        return 'success';
    }
    $ratio = $max ? $score / $max : 1;
    return $ratio <= 1 / 3 ? 'warning' : ($ratio <= 2 / 3 ? 'orange' : 'danger');
};

require 'header.php';
?>
<!doctype html>
<html lang="<?= htmlspecialchars(current_locale()) ?>">

<head>
    <title><?= trh('Follow-up of {animal} on {date}', ['animal' => $label, 'date' => $f['observed_at']]) ?> | <?= htmlspecialchars($labName) ?></title>
    <style>
        .scale { display: flex; flex-wrap: wrap; gap: .25rem; }
        .scale .level { border: 1px solid #dee2e6; border-radius: .25rem; padding: .15rem .45rem; font-size: .8rem; color: #6c757d; background: #fff; }
        .scale .level.chosen { color: #000; font-weight: 600; border-width: 2px; }
        .scale .level.chosen.success { background: #d1e7dd; border-color: #198754; }
        .scale .level.chosen.warning { background: #fff3cd; border-color: #ffc107; }
        .scale .level.chosen.orange { background: #ffd8a8; border-color: #fd7e14; }
        .scale .level.chosen.danger { background: #f5c2c7; border-color: #dc3545; }
        @media print { body > *:not(main) { display: none !important; } .no-print { display: none !important; } }
    </style>
</head>

<body>
    <main class="container mt-4 content" style="max-width: 1000px">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2 no-print">
            <a class="btn btn-sm btn-outline-secondary" href="<?= htmlspecialchars($backUrl) ?>"><i class="fas fa-arrow-left"></i> <?= trh('Back') ?></a>
            <div class="btn-group btn-group-sm">
                <a class="btn btn-outline-primary <?= $previous ? '' : 'disabled' ?>" href="<?= $previous ? htmlspecialchars($viewUrl($previous)) : '#' ?>"><i class="fas fa-chevron-left"></i> <?= $previous ? htmlspecialchars($previous['observed_at']) : trh('Previous day') ?></a>
                <span class="btn btn-primary disabled"><?= htmlspecialchars($f['observed_at']) ?></span>
                <a class="btn btn-outline-primary <?= $next ? '' : 'disabled' ?>" href="<?= $next ? htmlspecialchars($viewUrl($next)) : '#' ?>"><?= $next ? htmlspecialchars($next['observed_at']) : trh('Next day') ?> <i class="fas fa-chevron-right"></i></a>
            </div>
            <div>
                <button class="btn btn-sm btn-outline-dark" onclick="window.print()"><i class="fas fa-print"></i> <?= trh('Print') ?></button>
                <?php if (!$f['removed_at']) : ?><a class="btn btn-sm btn-outline-primary" href="<?= htmlspecialchars($editUrl) ?>"><i class="fas fa-pen"></i> <?= trh('Edit') ?></a><?php endif; ?>
            </div>
        </div>

        <h4 class="mb-1"><i class="fas fa-clipboard-check"></i> <?= trh('Follow-up of {animal} on {date}', ['animal' => $label, 'date' => $f['observed_at']]) ?> <?= $f['sex'] === 'male' ? '♂' : ($f['sex'] === 'female' ? '♀' : '') ?></h4>
        <p class="text-muted mb-3">
            <?= htmlspecialchars($f['experiment_number'] . ' — ' . $f['experiment_title']) ?> · <?= htmlspecialchars($f['protocol_number']) ?>
            <?= $f['distinctive_mark'] ? ' · ' . htmlspecialchars($f['distinctive_mark']) : '' ?> ·
            <?= trh('entered by {user} on {date}', ['user' => $f['recorded_by_name'] ?? '—', 'date' => substr($f['created_at'], 0, 16)]) ?>
            <span class="badge bg-secondary"><?= trh('Read only') ?></span>
        </p>

        <div class="row g-2 mb-3 text-center">
            <div class="col-6 col-md-3"><div class="border rounded p-2"><div class="small text-muted"><?= trh('Clinical score') ?></div><div class="fs-4 fw-bold"><?= $f['clinical_score'] ?? '—' ?><?= $gridMax ? '<span class="fs-6 text-muted"> / ' . $gridMax . '</span>' : '' ?></div></div></div>
            <div class="col-6 col-md-3"><div class="border rounded p-2"><div class="small text-muted"><?= trh('Weight (g)') ?></div><div class="fs-4 fw-bold"><?= $f['weight_g'] !== null ? htmlspecialchars(rtrim(rtrim((string) $f['weight_g'], '0'), '.')) : '—' ?></div></div></div>
            <div class="col-6 col-md-3"><div class="border rounded p-2"><div class="small text-muted"><?= trh('Change vs baseline') ?></div><div class="fs-4 fw-bold <?= $change !== null && $change < 0 ? 'text-danger' : '' ?>"><?= $change !== null ? htmlspecialchars(($change < 0 ? '−' : '+') . number_format(abs($change), 1, ',', ' ') . ' %') : '—' ?></div><?php if ($baseline > 0) : ?><div class="small text-muted"><?= trh('baseline {weight} g', ['weight' => rtrim(rtrim((string) $f['baseline_weight_g'], '0'), '.')]) ?></div><?php endif; ?></div></div>
            <div class="col-6 col-md-3"><div class="border rounded p-2 <?= $f['endpoint_reached'] ? 'border-danger bg-danger bg-opacity-10' : '' ?>"><div class="small text-muted"><?= trh('Endpoint') ?></div><div class="fs-4 fw-bold <?= $f['endpoint_reached'] ? 'text-danger' : '' ?>"><?= $f['endpoint_reached'] ? '<i class="fas fa-exclamation-triangle"></i> ' . trh('Reached') : trh('No') ?></div></div></div>
        </div>

        <h5><?= trh('Clinical grid — level chosen for each item') ?></h5>
        <?php if (!$scores) : ?>
            <p class="text-muted"><?= trh('No grid item scored for this follow-up (free clinical score).') ?></p>
        <?php else : ?>
            <table class="table table-bordered align-middle" id="gridDetail">
                <thead class="table-light">
                    <tr>
                        <th style="width: 22%"><?= trh('Item') ?></th>
                        <th style="width: 8%" class="text-center"><?= trh('Score') ?></th>
                        <th><?= trh('Level observed (within the item scale)') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($scores as $s) :
                        $score = (int) $s['score'];
                        $max = $itemMax($s);
                        $levels = $levelsByItem[(int) $s['grid_item_id']] ?? []; ?>
                        <tr>
                            <td class="fw-bold"><?= htmlspecialchars($s['item_label']) ?></td>
                            <td class="text-center fs-5 fw-bold"><?= $score ?><?= $max !== null ? '<span class="small text-muted fw-normal">/' . $max . '</span>' : '' ?></td>
                            <td>
                                <?php if ($s['level_description'] && !$levels) : ?><div class="mb-1"><?= htmlspecialchars($s['level_description']) ?></div><?php endif; ?>
                                <?php if ($levels) : ?>
                                    <div class="scale">
                                        <?php foreach ($levels as $level) : $chosen = (int) $level['score'] === $score; ?>
                                            <span class="level <?= $chosen ? 'chosen ' . $levelClass($score, $max) : '' ?>"><?= (int) $level['score'] ?> — <?= htmlspecialchars($level['description']) ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <?php if ($dayActs) : ?>
            <h5><?= trh('Experimental acts of the day') ?></h5>
            <table class="table table-sm table-bordered">
                <tbody>
                    <?php foreach ($dayActs as $xa) : ?>
                        <tr class="<?= $xa['status'] === 'not_done' ? 'table-danger' : '' ?>">
                            <th style="width: 30%"><?= htmlspecialchars($xa['label']) ?><?php if ($summary = experiment_act_summary($xa)) : ?><br><small class="text-muted fw-normal"><?= htmlspecialchars($summary) ?></small><?php endif; ?></th>
                            <td>
                                <strong><?= htmlspecialchars(experiment_act_statuses()[$xa['status']]) ?></strong> — <?= htmlspecialchars(substr((string) $xa['done_at'], 11, 5) . ' · ' . act_done_by_text($xa)) ?>
                                <?php if ($xa['medication_lot_id']) : ?><br><?= trh('{qty} {unit}, lot {lot}', ['qty' => act_number($xa['quantity_used']), 'unit' => $xa['product_unit'], 'lot' => $xa['lot_number']]) ?><?php endif; ?>
                                <?php foreach ($dayActValues[(int) $xa['id']] ?? [] as $v) : ?><br><?= htmlspecialchars($v['label'] . ' : ' . observation_value($v)) ?><?php endforeach; ?>
                                <?php if ($xa['note']) : ?><br><em><?= htmlspecialchars($xa['note']) ?></em><?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <?php if ($observations || $f['observations']) : ?>
            <h5><?= trh('Observations') ?></h5>
            <table class="table table-sm table-bordered">
                <tbody>
                    <?php foreach ($observations as $o) : ?>
                        <tr><th style="width: 30%"><?= htmlspecialchars($o['label']) ?><?= $o['field_id'] === null ? ' <small class="text-muted">(' . trh('free') . ')</small>' : '' ?></th><td><?= htmlspecialchars(observation_value($o)) ?></td></tr>
                    <?php endforeach; ?>
                    <?php if ($f['observations']) : ?><tr><th style="width: 30%"><?= trh('Comment') ?></th><td><?= nl2br(htmlspecialchars($f['observations'])) ?></td></tr><?php endif; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <?php if ($revisions = followup_revisions($con, $followupId)) : ?>
            <h5><?= trh('Corrections of this follow-up') ?></h5>
            <p class="small text-muted"><?= trh('The values shown above are the current ones; each correction keeps the values it replaced.') ?></p>
            <ul class="list-group mb-3" id="followupRevisions">
                <?php foreach ($revisions as $r) : ?>
                    <li class="list-group-item">
                        <strong><?= htmlspecialchars(substr((string) $r['revised_at'], 0, 16) . ' — ' . ($r['revised_by_name'] ?? '')) ?></strong> :
                        <?= htmlspecialchars($r['reason']) ?>
                        <div class="small text-muted"><?= trh('Replaced values: {values}', ['values' => followup_revision_summary($r['previous'])]) ?></div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php if ($actions) : ?>
            <h5><?= trh('Endpoint actions raised by this follow-up') ?></h5>
            <ul class="list-group mb-3">
                <?php foreach ($actions as $a) : ?>
                    <li class="list-group-item">
                        <span class="badge bg-<?= $a['status'] === 'pending' ? 'danger' : 'success' ?>"><?= trh($a['status'] === 'pending' ? 'Pending' : 'Done') ?></span>
                        <strong><?= htmlspecialchars($actionLabels[$a['required_action']] ?? $a['required_action']) ?></strong> — <?= htmlspecialchars($a['message'] ?? '') ?>
                        <?php if ($a['status'] === 'done') : ?><div class="small text-muted"><?= htmlspecialchars(substr((string) $a['done_at'], 0, 16) . ' · ' . ($a['done_by_name'] ?? '')) ?><?= $a['done_note'] ? ' — ' . htmlspecialchars($a['done_note']) : '' ?></div><?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </main>
    <?php require 'footer.php'; ?>
</body>

</html>
