<?php

/**
 * Daily experimental follow-up of a cage under experiment: every animal of
 * the cage enrolled in an ongoing experiment, on one page. The cage's daily
 * check is validated only once each of them has its follow-up for the day
 * (the "all OK" button of the round is not available for such a cage).
 */
session_start();
require 'dbcon.php';
require 'animal_lib.php';

if (!isset($_SESSION['username'])) {
    header('Location: index.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

$cageId = trim($_GET['cage_id'] ?? '');
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
$complete = $total > 0 && $done === $total;
// The check is recorded on the last follow-up; record it here too when the
// cage became complete otherwise (e.g. an animal left the experiment).
if ($complete && $cage['status'] === 'active') {
    record_experimental_cage_check_if_complete($con, $cageId, $_SESSION['user_id'] ?? null, $_SESSION['name'] ?? null);
}
$selfUrl = 'experiment_cage_followup.php?' . http_build_query(['cage_id' => $cageId]);
$followupUrl = fn(array $en): string => 'experiment_followup.php?' . http_build_query(['experiment_animal_id' => $en['experiment_animal_id'], 'return' => $selfUrl]);
$next = current(array_filter($enrollments, fn($en) => !$en['followup_id'])) ?: null;
$pct = $total ? (int) round($done * 100 / $total) : 0;

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
                                <div class="small text-muted"><?= trh('Every animal under experiment has its follow-up for the day.') ?></div>
                            <?php else : ?>
                                <strong class="text-warning"><i class="fas fa-hourglass-half"></i> <?= trh('Cage not validated yet') ?></strong>
                                <div class="small text-muted"><?= trh('{n} animal(s) still to evaluate today. The cage is validated once every animal has its follow-up.', ['n' => $total - $done]) ?></div>
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
                                        <?= trh('weight {weight} g · score {score}', ['weight' => $en['weight_g'] ?? '—', 'score' => $en['clinical_score'] ?? '—']) ?>
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
    <?php require 'footer.php'; ?>
</body>

</html>
