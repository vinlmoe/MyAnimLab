<?php

/**
 * Daily experimental follow-up of a cage under experiment: every animal of
 * the cage enrolled in an ongoing experiment, on one page. The cage's daily
 * check is validated only once each of them has its follow-up for the day
 * (the "all OK" button of the round is not available for such a cage).
 * The experimental acts due today (or overdue) for the animals of the cage
 * can be confirmed here for the whole cage at once; the cage is validated
 * only once they are all confirmed (done or not done) too.
 */
session_start();
require 'dbcon.php';
require 'animal_lib.php';
require 'experiment_acts_lib.php';

if (!isset($_SESSION['username'])) {
    header('Location: index.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$cageId = trim($_GET['cage_id'] ?? $_POST['cage_id'] ?? '');
$today = date('Y-m-d');
$cageStmt = $con->prepare('SELECT cage_id, room, rack, status FROM cages WHERE cage_id = ?');
$cageStmt->bind_param('s', $cageId);
$cageStmt->execute();
$cage = $cageStmt->get_result()->fetch_assoc();
$cageStmt->close();

if (!$cage) {
    $_SESSION['message'] = tr('Cage not found.');
    header('Location: qr_scan.php');
    exit;
}

$selfUrl = 'experiment_cage_followup.php?' . http_build_query(['cage_id' => $cageId]);

// Acts of the cage confirmed together: one status per animal, the lot, time
// and note shared.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_acts'])) {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        die(tr('CSRF token validation failed'));
    }
    $userId = $_SESSION['user_id'] ?? null;
    $userName = $_SESSION['name'] ?? null;
    $sharedLot = (int) ($_POST['shared_lot'] ?? 0) ?: null;
    $confirmations = posted_act_confirmations($_POST, $today, $sharedLot, trim((string) ($_POST['shared_note'] ?? '')), (string) ($_POST['shared_time'] ?? ''));
    mysqli_begin_transaction($con);
    try {
        if (!$confirmations) {
            throw new Exception(tr('Tick at least one animal as done or not done.'));
        }
        $animals = [];
        foreach ($confirmations as $actAnimalId => $raw) {
            $animals[$actAnimalId] = [confirm_experiment_act($con, $actAnimalId, $raw, null, $userId, $userName, null, $cageId), $raw['status']];
        }
        foreach ($animals as $actAnimalId => [$animalId, $status]) {
            log_animal_event($con, $animalId, 'experiment_act', ['act_animal_id' => $actAnimalId, 'status' => $status, 'cage_id' => $cageId], $userId);
        }
        record_experimental_cage_check_if_complete($con, $cageId, $userId, $userName);
        mysqli_commit($con);
        $_SESSION['message'] = tr('{n} act(s) confirmed.', ['n' => count($confirmations)]);
    } catch (Exception $e) {
        mysqli_rollback($con);
        $_SESSION['message'] = tr('Acts not confirmed: {error}', ['error' => $e->getMessage()]);
    }
    header("Location: $selfUrl#cageActs");
    exit;
}

$enrollStmt = $con->prepare(
    "SELECT ea.id AS experiment_animal_id, m.id AS animal_id, m.animal_uid, m.mouse_id, m.sex, m.distinctive_mark,
            e.experiment_number, e.title AS experiment_title, p.protocol_number, p.id AS protocol_id,
            f.id AS followup_id, f.weight_g, f.clinical_score, f.endpoint_reached,
            (SELECT COUNT(*) FROM endpoint_actions a WHERE a.experiment_animal_id = ea.id AND a.status = 'pending') AS pending_actions
     FROM experiment_animals ea
     JOIN mice m ON m.id = ea.animal_id
     JOIN experiments e ON e.id = ea.experiment_id
     JOIN protocols p ON p.id = e.protocol_id
     LEFT JOIN experiment_followups f ON f.experiment_animal_id = ea.id AND f.observed_at = ?
     WHERE m.cage_id = ? AND m.status = 'active' AND ea.removed_at IS NULL
       AND e.status IN ('planned', 'active')
     ORDER BY f.id IS NOT NULL, e.experiment_number, m.animal_uid"
);
$enrollStmt->bind_param('ss', $today, $cageId);
$enrollStmt->execute();
$enrollments = $enrollStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$enrollStmt->close();

$total = count($enrollments);
$done = count(array_filter($enrollments, fn($en) => $en['followup_id']));
$actsDue = cage_acts_due($con, $cageId, $today);
$actWeights = enrollment_latest_weights($con, array_merge([], ...array_map(fn($rows) => array_column($rows, 'experiment_animal_id'), array_values($actsDue))));
$actsPending = array_sum(array_map('count', $actsDue));
$complete = $total > 0 && $done === $total && $actsPending === 0;
// The check is recorded on the last follow-up or act; record it here too when
// the cage became complete otherwise (e.g. an animal left the experiment).
if ($complete && $cage['status'] === 'active') {
    record_experimental_cage_check_if_complete($con, $cageId, $_SESSION['user_id'] ?? null, $_SESSION['name'] ?? null);
}
$followupUrl = fn(array $en): string => 'experiment_followup.php?' . http_build_query(['experiment_animal_id' => $en['experiment_animal_id'], 'return' => $selfUrl]);
$next = current(array_filter($enrollments, fn($en) => !$en['followup_id'])) ?: null;
$pct = $total ? (int) round($done * 100 / $total) : 0;

$actFields = experiment_act_fields($con, array_keys($actsDue));
$lotsByProduct = $actsDue ? usable_lots_by_product($con) : [];

require 'header.php';
?>
<!doctype html>
<html lang="<?= htmlspecialchars(current_locale()) ?>">

<head>
    <title><?= trh('Experimental follow-up of cage {cage}', ['cage' => $cageId]) ?> | <?= htmlspecialchars($labName) ?></title>
</head>

<body>
    <main class="container mt-4 content" style="max-width: 1100px">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
            <div>
                <h4 class="mb-1"><i class="fas fa-flask"></i> <?= trh('Experimental follow-up of cage {cage}', ['cage' => $cageId]) ?></h4>
                <p class="text-muted mb-2"><?= htmlspecialchars($today) ?> · <?= htmlspecialchars(($cage['room'] ?? '') !== '' ? tr('Room {room}', ['room' => $cage['room']]) : tr('No room assigned')) ?> · <?= trh('Rack') ?> <?= htmlspecialchars($cage['rack'] ?: '—') ?></p>
            </div>
            <div class="text-nowrap">
                <a class="btn btn-outline-primary btn-sm" href="medication_administration.php?cage_id=<?= rawurlencode($cageId) ?>"><i class="fas fa-syringe"></i> <?= trh('Cage administrations') ?></a>
                <a class="btn btn-outline-secondary btn-sm" href="hc_view.php?id=<?= rawurlencode($cageId) ?>"><i class="fas fa-box"></i> <?= trh('Cage record') ?></a>
            </div>
        </div>

        <?php include 'message.php'; ?>

        <?php if (!$enrollments) : ?>
            <div class="alert alert-secondary"><?= trh('No animal of this cage is currently under experiment.') ?></div>
            <a class="btn btn-outline-secondary" href="cage_check.php?<?= htmlspecialchars(http_build_query(['id' => $cageId, 'via' => 'qr'])) ?>"><?= trh('Daily check of the cage') ?></a>
        <?php else : ?>
            <div class="card mb-3 <?= $complete ? 'border-success' : 'border-warning'; ?>" id="cageValidation">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <div>
                            <?php if ($complete) : ?>
                                <strong class="text-success"><i class="fas fa-check-circle"></i> <?= trh('Cage validated for today') ?></strong>
                                <div class="small text-muted"><?= trh('Every animal under experiment has its follow-up for the day and every act due is confirmed.') ?></div>
                            <?php else : ?>
                                <strong class="text-warning"><i class="fas fa-hourglass-half"></i> <?= trh('Cage not validated yet') ?></strong>
                                <div class="small text-muted">
                                    <?php if ($done < $total) : ?><?= trh('{n} animal(s) still to evaluate today.', ['n' => $total - $done]) ?><?php endif; ?>
                                    <?php if ($actsPending) : ?><a href="#cageActs" class="text-danger"><?= trh('{n} experimental act(s) to confirm', ['n' => $actsPending]) ?></a>.<?php endif; ?>
                                    <?= trh('The cage is validated once every animal has its follow-up and every act due is confirmed (done or not done).') ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <?php if ($next) : ?>
                            <a class="btn btn-primary" href="<?= htmlspecialchars($followupUrl($next)) ?>"><i class="fas fa-play"></i> <?= trh('Evaluate {animal}', ['animal' => $next['animal_uid'] ?: $next['mouse_id'] ?: ('#' . $next['animal_id'])]) ?></a>
                        <?php endif; ?>
                    </div>
                    <div class="progress mt-2" style="height: 1.25rem;" title="<?= trh('{done}/{total} animal(s) evaluated', ['done' => $done, 'total' => $total]) ?>">
                        <div class="progress-bar <?= $complete ? 'bg-success' : 'bg-warning text-dark'; ?>" role="progressbar" style="width: <?= max($pct, 8) ?>%;" aria-valuenow="<?= $done ?>" aria-valuemin="0" aria-valuemax="<?= $total ?>"><?= trh('{done}/{total} animal(s) evaluated', ['done' => $done, 'total' => $total]) ?></div>
                    </div>
                </div>
            </div>

            <?php if ($actsDue) : ?>
                <h5 id="cageActs"><i class="fas fa-syringe"></i> <?= trh('Experimental acts to confirm') ?></h5>
                <?php foreach ($actsDue as $actId => $rows) :
                    $act = $rows[0];
                    $fields = $actFields[$actId] ?? []; ?>
                    <form method="POST" class="card mb-3 border-primary act-form">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="cage_id" value="<?= htmlspecialchars($cageId) ?>">
                        <div class="card-header d-flex justify-content-between flex-wrap gap-2">
                            <div>
                                <span class="badge bg-primary"><?= htmlspecialchars(experiment_act_types()[$act['act_type']] ?? $act['act_type']) ?></span>
                                <strong><?= htmlspecialchars($act['label']) ?></strong>
                                <?php if ($summary = experiment_act_summary($act)) : ?><span class="text-muted">— <?= htmlspecialchars($summary) ?></span><?php endif; ?>
                                <span class="small text-muted">· <?= htmlspecialchars($act['experiment_number']) ?></span>
                                <?php if ($act['instructions']) : ?><div class="small"><i class="fas fa-info-circle"></i> <?= htmlspecialchars($act['instructions']) ?></div><?php endif; ?>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-success all-done"><i class="fas fa-check-double"></i> <?= trh('Tick all as done') ?></button>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-sm align-middle mb-2">
                                    <thead class="table-light">
                                        <tr>
                                            <th><?= trh('Animal') ?></th>
                                            <th><?= trh('Status') ?></th>
                                            <?php foreach ($fields as $f) : ?><th><?= htmlspecialchars($f['label']) ?><?= $f['unit'] ? ' (' . htmlspecialchars($f['unit']) . ')' : '' ?><?= $f['required'] ? ' *' : '' ?></th><?php endforeach; ?>
                                            <?php if ($act['product_id']) : ?><th><?= trh('Quantity ({unit})', ['unit' => $act['product_unit']]) ?> *</th><?php endif; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($rows as $r) : $rid = (int) $r['id']; ?>
                                            <tr class="act-line">
                                                <td>
                                                    <strong><?= htmlspecialchars($r['animal_uid'] ?: $r['mouse_id'] ?: ('#' . $r['animal_id'])) ?></strong>
                                                    <?php if ($r['distinctive_mark']) : ?><small class="text-muted">[<?= htmlspecialchars($r['distinctive_mark']) ?>]</small><?php endif; ?>
                                                    <?php if ($r['planned_date'] < $today) : ?><span class="badge bg-warning text-dark"><?= trh('planned on {date}', ['date' => $r['planned_date']]) ?></span><?php endif; ?>
                                                </td>
                                                <td class="text-nowrap">
                                                    <div class="btn-group btn-group-sm" role="group">
                                                        <input type="radio" class="btn-check act-status" name="act_status[<?= $rid ?>]" id="c<?= $rid ?>_later" value="" checked>
                                                        <label class="btn btn-outline-secondary" for="c<?= $rid ?>_later"><?= trh('Later') ?></label>
                                                        <input type="radio" class="btn-check act-status" name="act_status[<?= $rid ?>]" id="c<?= $rid ?>_done" value="done">
                                                        <label class="btn btn-outline-success" for="c<?= $rid ?>_done"><i class="fas fa-check"></i> <?= trh('Act done') ?></label>
                                                        <input type="radio" class="btn-check act-status" name="act_status[<?= $rid ?>]" id="c<?= $rid ?>_not" value="not_done">
                                                        <label class="btn btn-outline-danger" for="c<?= $rid ?>_not"><?= trh('Not done') ?></label>
                                                    </div>
                                                </td>
                                                <?php foreach ($fields as $fieldId => $f) : ?>
                                                    <td><input type="text" class="form-control form-control-sm act-input" name="act_value[<?= $rid ?>][<?= (int) $fieldId ?>]" <?= $f['value_type'] === 'number' ? 'inputmode="decimal" pattern="-?[0-9]+([.,][0-9]+)?"' : '' ?> data-required="<?= (int) $f['required'] ?>"></td>
                                                <?php endforeach; ?>
                                                <?php if ($act['product_id']) : ?>
                                                    <?php $w = $actWeights[(int) $r['experiment_animal_id']] ?? null;
                                                    $suggested = act_suggested_quantity($r, $w['weight'] ?? null); ?>
                                                    <td style="width: 9rem">
                                                        <input type="text" class="form-control form-control-sm act-input" name="act_qty[<?= $rid ?>]" inputmode="decimal" pattern="[0-9]+([.,][0-9]+)?" value="<?= htmlspecialchars($suggested !== null ? act_number((string) $suggested) : act_number($act['quantity_per_animal'])) ?>" data-required="1">
                                                        <?php if ($suggested !== null) : ?><small class="text-success" title="<?= htmlspecialchars(act_quantity_per_gram($r)[1]) ?>"><i class="fas fa-weight"></i> <?= trh('for {weight} g ({date})', ['weight' => act_number((string) $w['weight']), 'date' => $w['date'] ?? tr('baseline')]) ?></small>
                                                        <?php elseif (act_quantity_per_gram($r)) : ?><small class="text-warning"><?= trh('weight unknown') ?></small><?php endif; ?>
                                                    </td>
                                                <?php endif; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="row g-2 align-items-end">
                                <div class="col-md-2">
                                    <label class="form-label small mb-0"><?= trh('Time') ?></label>
                                    <input type="time" class="form-control form-control-sm" name="shared_time" value="<?= date('H:i') ?>">
                                </div>
                                <?php if ($act['product_id']) : ?>
                                    <div class="col-md-4">
                                        <label class="form-label small mb-0"><?= trh('Lot of {product}', ['product' => $act['product_name']]) ?> *</label>
                                        <select class="form-select form-select-sm" name="shared_lot"><?= act_lot_options($lotsByProduct, (int) $act['product_id'], isset($lotsByProduct[(int) $act['product_id']]) ? (int) $lotsByProduct[(int) $act['product_id']][0]['id'] : null) ?></select>
                                    </div>
                                <?php endif; ?>
                                <div class="col">
                                    <label class="form-label small mb-0"><?= trh('Note (required for an act not done: the reason)') ?></label>
                                    <input type="text" class="form-control form-control-sm shared-note" name="shared_note">
                                </div>
                                <div class="col-md-2"><button class="btn btn-success btn-sm w-100" name="confirm_acts"><i class="fas fa-check"></i> <?= trh('Confirm') ?></button></div>
                            </div>
                        </div>
                    </form>
                <?php endforeach; ?>
            <?php endif; ?>

            <div class="table-responsive">
                <table class="table table-bordered align-middle" id="cageAnimals">
                    <thead class="table-light">
                        <tr>
                            <th><?= trh('Animal') ?></th>
                            <th><?= trh('Experiment / protocol') ?></th>
                            <th><?= trh('Today’s follow-up') ?></th>
                            <th class="text-end"><?= trh('Action') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($enrollments as $en) : $label = $en['animal_uid'] ?: $en['mouse_id'] ?: ('#' . $en['animal_id']); ?>
                            <tr class="<?= $en['endpoint_reached'] ? 'table-danger' : ($en['followup_id'] ? 'table-success' : '') ?>">
                                <td>
                                    <a href="animal_view.php?id=<?= (int) $en['animal_id'] ?>" class="fw-bold"><?= htmlspecialchars($label) ?></a> <?= $en['sex'] === 'male' ? '♂' : ($en['sex'] === 'female' ? '♀' : '') ?>
                                    <?php if ($en['distinctive_mark']) : ?><br><small class="text-muted"><?= htmlspecialchars($en['distinctive_mark']) ?></small><?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($en['experiment_number']) ?> — <?= htmlspecialchars($en['experiment_title']) ?><br><small class="text-muted"><?= htmlspecialchars($en['protocol_number']) ?></small></td>
                                <td>
                                    <?php if ($en['followup_id']) : ?>
                                        <span class="badge bg-success"><?= trh('Entered') ?></span>
                                        <a href="<?= htmlspecialchars('experiment_followup_view.php?' . http_build_query(['id' => $en['followup_id'], 'return' => $selfUrl])) ?>" title="<?= trh('Read the full evaluation') ?>"><?= trh('weight {weight} g · score {score}', ['weight' => $en['weight_g'] ?? '—', 'score' => $en['clinical_score'] ?? '—']) ?></a>
                                        <?php if ($en['endpoint_reached']) : ?><span class="badge bg-danger"><?= trh('Endpoint') ?></span><?php endif; ?>
                                    <?php else : ?>
                                        <span class="badge bg-warning text-dark"><?= trh('To enter') ?></span>
                                    <?php endif; ?>
                                    <?php if ($en['pending_actions']) : ?>
                                        <div><a class="badge bg-danger text-decoration-none" href="experiment_manage.php?protocol_id=<?= (int) $en['protocol_id'] ?>#endpointManagement"><i class="fas fa-exclamation-triangle"></i> <?= trh('{n} endpoint action(s) pending', ['n' => (int) $en['pending_actions']]) ?></a></div>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end"><a class="btn btn-<?= $en['followup_id'] ? 'outline-primary' : 'primary' ?> btn-sm" href="<?= htmlspecialchars($followupUrl($en)) ?>"><?= $en['followup_id'] ? trh('Edit') : trh('Enter') ?></a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        <a href="<?= htmlspecialchars('daily_round.php?' . http_build_query(['room' => $cage['room'] ?: '__none'])) ?>" class="btn btn-outline-secondary"><i class="fas fa-arrow-left"></i> <?= trh('Back to the daily round') ?></a>
    </main>
    <script>
        // Acts of the cage: inputs of an animal enabled once it is ticked; a
        // reason is needed when one is not done.
        document.querySelectorAll('.act-form').forEach(form => {
            const sync = () => {
                let notDone = false;
                form.querySelectorAll('.act-line').forEach(line => {
                    const status = line.querySelector('.act-status:checked').value;
                    notDone = notDone || status === 'not_done';
                    line.querySelectorAll('.act-input').forEach(i => {
                        i.disabled = status !== 'done';
                        i.required = status === 'done' && i.dataset.required === '1';
                    });
                });
                const lot = form.querySelector('[name="shared_lot"]');
                if (lot) lot.required = [...form.querySelectorAll('.act-status:checked')].some(r => r.value === 'done');
                form.querySelector('.shared-note').required = notDone;
            };
            form.querySelectorAll('.act-status').forEach(r => r.addEventListener('change', sync));
            form.querySelector('.all-done').addEventListener('click', () => {
                form.querySelectorAll('.act-status[value="done"]').forEach(r => r.checked = true);
                sync();
            });
            sync();
        });
    </script>
    <?php require 'footer.php'; ?>
</body>

</html>
