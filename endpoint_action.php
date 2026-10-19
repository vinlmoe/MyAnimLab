<?php

/**
 * Carrying out the endpoint actions (points limites) of an animal enrolled
 * in an experiment. Saving a daily follow-up that raises an action leads
 * here: the action is carried out at once (euthanasia: the animal's exit;
 * other actions: what was done), or a comment says why it is not done yet
 * and what is planned. The action stays pending, with its comments, until it
 * is done.
 */
require_once 'session_config.php';
require 'dbcon.php';
require 'animal_lib.php';
require_once 'followup_lib.php';

if (!isset($_SESSION['username'])) {
    header('Location: index.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

$currentUserId = $_SESSION['user_id'] ?? null;
$currentUserName = $_SESSION['name'] ?? null;
$experimentAnimalId = (int) ($_GET['experiment_animal_id'] ?? $_POST['experiment_animal_id'] ?? 0);
$followupId = (int) ($_GET['followup_id'] ?? $_POST['followup_id'] ?? 0);
$returnUrl = safe_local_return($_GET['return'] ?? $_POST['return'] ?? null, ['endpoint_action.php']);

$ea = db_row(
    $con,
    "SELECT ea.*, m.id AS animal_id, m.animal_uid, m.status AS animal_status, m.cage_id, m.distinctive_mark,
            e.experiment_number, e.title AS experiment_title, p.id AS protocol_id, p.protocol_number
     FROM experiment_animals ea
     JOIN mice m ON m.id = ea.animal_id
     JOIN experiments e ON e.id = ea.experiment_id
     JOIN protocols p ON p.id = e.protocol_id
     WHERE ea.id = ?",
    'i',
    [$experimentAnimalId]
);
if (!$ea) {
    $_SESSION['message'] = tr('Enrollment not found.');
    header('Location: home.php');
    exit;
}

$followupUrl = 'experiment_followup.php?experiment_animal_id=' . $experimentAnimalId;
$backUrl = $returnUrl ?? $followupUrl;
$selfUrl = 'endpoint_action.php?' . http_build_query(array_filter(['experiment_animal_id' => $experimentAnimalId, 'followup_id' => $followupId ?: null, 'return' => $returnUrl]));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) {
        die(tr('CSRF token validation failed'));
    }
    $actionId = (int) ($_POST['action_id'] ?? 0);
    $text = trim((string) ($_POST['text'] ?? ''));
    if (isset($_POST['complete_action'])) {
        if ($text === '') {
            $_SESSION['message'] = tr('Describe what was done (e.g. vet called, decision taken).');
        } elseif (complete_endpoint_action($con, $actionId, $experimentAnimalId, $text, $currentUserId, $currentUserName)) {
            log_activity($con, 'endpoint_action_done', 'animal', (string) $ea['animal_id'], tr('Action #{id}: {note}', ['id' => $actionId, 'note' => $text]), $currentUserId);
            $_SESSION['message'] = tr('Action recorded as done.');
        } else {
            $_SESSION['message'] = tr('This action cannot be completed here.');
        }
    } elseif (isset($_POST['comment_action'])) {
        if ($text === '') {
            $_SESSION['message'] = tr('Say why the action is not done at once and what is planned.');
        } elseif (comment_endpoint_action($con, $actionId, $experimentAnimalId, $text, $currentUserId, $currentUserName)) {
            log_activity($con, 'endpoint_action_commented', 'animal', (string) $ea['animal_id'], tr('Action #{id} not done at once: {comment}', ['id' => $actionId, 'comment' => $text]), $currentUserId);
            $_SESSION['message'] = tr('Comment recorded: the action stays pending.');
        } else {
            $_SESSION['message'] = tr('This action is no longer pending.');
        }
    }
    // Back to the page left once every action of this page is done or commented.
    $open = db_value(
        $con,
        "SELECT COUNT(*) FROM endpoint_actions a WHERE a.experiment_animal_id = ? AND a.status = 'pending'
           AND NOT EXISTS (SELECT 1 FROM endpoint_action_comments c WHERE c.action_id = a.id)",
        'i',
        [$experimentAnimalId]
    );
    header('Location: ' . ((int) $open ? $selfUrl : $backUrl));
    exit;
}

$actions = db_rows($con, "SELECT a.*, f.observed_at FROM endpoint_actions a LEFT JOIN experiment_followups f ON f.id = a.followup_id
    WHERE a.experiment_animal_id = ? AND a.status = 'pending' ORDER BY a.required_action = 'euthanasia' DESC, a.created_at", 'i', [$experimentAnimalId]);
$comments = endpoint_action_comments($con, array_column($actions, 'id'));
$actionLabels = endpoint_required_actions();
$alive = $ea['animal_status'] === 'active';

require 'header.php';
?>
<!doctype html>
<html lang="<?= htmlspecialchars(current_locale()) ?>">

<head>
    <title><?= trh('Endpoint actions of {animal}', ['animal' => $ea['animal_uid']]) ?> | <?= htmlspecialchars($labName) ?></title>
</head>

<body>
    <main class="container mt-4 content" style="max-width: 1000px">
        <h4 class="mb-1"><i class="fas fa-exclamation-triangle text-danger"></i> <?= trh('Endpoint actions of {animal}', ['animal' => $ea['animal_uid']]) ?>
            <?php if ($ea['distinctive_mark']) : ?><small class="text-muted">[<?= htmlspecialchars($ea['distinctive_mark']) ?>]</small><?php endif; ?></h4>
        <p class="text-muted">
            <?= htmlspecialchars($ea['experiment_number'] . ' — ' . $ea['experiment_title']) ?> · <?= htmlspecialchars($ea['protocol_number']) ?>
            · <?= trh('cage {cage}', ['cage' => $ea['cage_id'] ?? '—']) ?>
        </p>

        <?php include('message.php'); ?>

        <?php if (!$actions) : ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= trh('No endpoint action to handle.') ?></div>
        <?php else : ?>
            <p><?= trh('Carry out each action now, or say why it is not done at once and what is planned: it stays pending, with your comment, until it is done.') ?></p>
        <?php endif; ?>

        <?php foreach ($actions as $a) :
            $isEuthanasia = $a['required_action'] === 'euthanasia';
            $isNew = $followupId && (int) $a['followup_id'] === $followupId; ?>
            <div class="card mb-3 <?= $isEuthanasia ? 'border-danger' : 'border-warning' ?>" id="endpointAction<?= (int) $a['id'] ?>">
                <div class="card-header <?= $isEuthanasia ? 'bg-danger text-white' : 'bg-warning' ?>">
                    <strong><?= trh('ACTION REQUIRED: {actions}', ['actions' => $actionLabels[$a['required_action']] ?? $a['required_action']]) ?></strong>
                    <?php if ($isNew) : ?><span class="badge bg-light text-dark"><?= trh('raised by this follow-up') ?></span><?php endif; ?>
                </div>
                <div class="card-body">
                    <p class="mb-1"><?= htmlspecialchars($a['message'] ?? '') ?></p>
                    <p class="small text-muted"><?= trh('Raised on {date}', ['date' => substr($a['created_at'], 0, 16)]) ?><?= $a['observed_at'] ? ' · ' . trh('follow-up of {date}', ['date' => $a['observed_at']]) : '' ?></p>

                    <?php if (!empty($comments[(int) $a['id']])) : ?>
                        <h6><?= trh('Not done at once') ?></h6>
                        <ul class="small">
                            <?php foreach ($comments[(int) $a['id']] as $c) : ?>
                                <li><?= nl2br(htmlspecialchars($c['comment'])) ?> <span class="text-muted">— <?= htmlspecialchars(($c['created_by_name'] ?? '') . ', ' . substr($c['created_at'], 0, 16)) ?></span></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <h6 class="text-success"><i class="fas fa-check"></i> <?= trh('Done now') ?></h6>
                            <?php if ($isEuthanasia) : ?>
                                <?php if ($alive) : ?>
                                    <a class="btn btn-danger" href="animal_exit.php?<?= htmlspecialchars(http_build_query(['id' => $ea['animal_id'], 'code' => 'point_limite', 'return' => $backUrl])) ?>"><i class="fas fa-sign-out-alt"></i> <?= trh('Record the euthanasia (exit: endpoint reached)') ?></a>
                                    <div class="small text-muted mt-1"><?= trh('Closed automatically when the animal’s exit is recorded.') ?></div>
                                <?php endif; ?>
                            <?php else : ?>
                                <form method="POST">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                    <input type="hidden" name="experiment_animal_id" value="<?= $experimentAnimalId ?>">
                                    <input type="hidden" name="followup_id" value="<?= $followupId ?>">
                                    <input type="hidden" name="return" value="<?= htmlspecialchars($returnUrl ?? '') ?>">
                                    <input type="hidden" name="action_id" value="<?= (int) $a['id'] ?>">
                                    <textarea class="form-control mb-2" name="text" rows="2" required placeholder="<?= trh('What was done (e.g. Dr X called at 10:30 — analgesia, re-check tonight)') ?>"></textarea>
                                    <button type="submit" name="complete_action" class="btn btn-success"><i class="fas fa-check"></i> <?= trh('Mark as done') ?></button>
                                </form>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6">
                            <h6 class="text-warning"><i class="fas fa-clock"></i> <?= trh('Not done at once') ?></h6>
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                <input type="hidden" name="experiment_animal_id" value="<?= $experimentAnimalId ?>">
                                <input type="hidden" name="followup_id" value="<?= $followupId ?>">
                                <input type="hidden" name="return" value="<?= htmlspecialchars($returnUrl ?? '') ?>">
                                <input type="hidden" name="action_id" value="<?= (int) $a['id'] ?>">
                                <textarea class="form-control mb-2" name="text" rows="2" required placeholder="<?= trh('Why, and what is planned (e.g. vet unavailable until 14:00, animal isolated and re-checked at 12:00)') ?>"></textarea>
                                <button type="submit" name="comment_action" class="btn btn-outline-warning"><i class="fas fa-comment"></i> <?= trh('Record the comment') ?></button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>

        <div class="d-flex gap-3">
            <a href="<?= htmlspecialchars($backUrl) ?>">&laquo; <?= trh('Back') ?></a>
            <a href="<?= htmlspecialchars($followupUrl) ?>" class="text-muted"><?= trh('Daily Follow-up') ?></a>
        </div>
    </main>
    <?php require 'footer.php'; ?>
</body>

</html>
