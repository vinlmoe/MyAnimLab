<?php

/**
 * Terrariums holding populations of animals that are not individually
 * identified: current estimate of each population (last census corrected
 * by the journal), census and health check due, health alert, withdrawal
 * period running. The BEA responsible (or an administrator) creates them.
 */

require_once 'session_config.php';
require 'dbcon.php';
require 'animal_lib.php';
require_once 'ethics_lib.php';
require_once 'terrarium_lib.php';

if (!isset($_SESSION['username'])) {
    header('Location: index.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}
$userId = $_SESSION['user_id'] ?? null;
$canManage = is_bea_user($con);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_terrarium'])) {
    if (!csrf_valid()) {
        die(tr('CSRF token validation failed'));
    }
    if (!$canManage) {
        http_response_code(403);
        exit(trh('Access restricted to the BEA responsible.'));
    }
    try {
        $id = terrarium_save($con, null, $_POST, $userId);
        log_activity($con, 'terrarium_created', 'terrarium', (string) $id, trim($_POST['code'] ?? '') . ' — ' . trim($_POST['name'] ?? ''), $userId);
        $_SESSION['message'] = tr('Terrarium created.');
        header('Location: terrarium_view.php?id=' . $id);
        exit;
    } catch (Exception $e) {
        $_SESSION['message'] = error_message($e);
        header('Location: terrarium_dash.php');
        exit;
    }
}

$showArchived = ($_GET['status'] ?? '') === 'archived';
$terrariums = db_rows(
    $con,
    "SELECT t.*, l.code AS line_code, l.name AS line_name, l.species AS line_species, u.name AS responsible_name
     FROM terrariums t LEFT JOIN animal_lines l ON l.id = t.line_id LEFT JOIN users u ON u.id = t.responsible_user_id
     WHERE t.status = ? ORDER BY t.room, t.code",
    's',
    [$showArchived ? 'archived' : 'active']
);
$total = 0;
foreach ($terrariums as &$t) {
    $t['pop'] = terrarium_population($con, $t);
    $t['health'] = terrarium_health_summary($con, $t);
    $t['withdrawal'] = terrarium_withdrawal_until($con, (int) $t['id']);
    $total += (int) $t['pop']['estimate'];
}
unset($t);
$lines = $con->query('SELECT id, code, name, species FROM animal_lines ORDER BY code')->fetch_all(MYSQLI_ASSOC);
$users = $con->query("SELECT id, name FROM users WHERE status = 'approved' ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$rooms = array_column($con->query("SELECT DISTINCT room FROM cages WHERE room IS NOT NULL AND room <> '' UNION SELECT DISTINCT room FROM terrariums WHERE room IS NOT NULL AND room <> '' ORDER BY room")->fetch_all(MYSQLI_ASSOC), 'room');

require 'header.php';
?>
<!doctype html>
<html lang="<?= htmlspecialchars(current_locale()) ?>">

<head>
    <title><?= trh('Terrariums and populations') ?> | <?= htmlspecialchars($labName) ?></title>
</head>

<body>
    <main class="container mt-4 content" style="max-width: 1250px">
        <h4><i class="fas fa-frog"></i> <?= trh('Terrariums and populations') ?></h4>
        <p class="text-muted small"><?= trh('Populations of animals not individually identified, followed by periodic approximate censuses, a journal (introductions, deaths, transfers), treatments and health monitoring. Animals captured for an experiment become identified animals.') ?></p>
        <?php include 'message.php'; ?>

        <div class="d-flex flex-wrap gap-2 mb-3 align-items-center">
            <span class="badge bg-primary fs-6"><?= trh('{n} terrarium(s)', ['n' => count($terrariums)]) ?></span>
            <?php if (!$showArchived) : ?><span class="badge bg-secondary fs-6"><?= trh('about {n} animals', ['n' => $total]) ?></span><?php endif; ?>
            <span class="ms-auto">
                <a class="btn btn-sm btn-outline-secondary" href="terrarium_dash.php<?= $showArchived ? '' : '?status=archived' ?>"><?= $showArchived ? trh('Active terrariums') : trh('Archived terrariums') ?></a>
                <?php if ($canManage && !$showArchived) : ?><button class="btn btn-sm btn-primary" data-bs-toggle="collapse" data-bs-target="#newTerrarium"><i class="fas fa-plus"></i> <?= trh('New terrarium') ?></button><?php endif; ?>
            </span>
        </div>

        <?php if ($canManage) : ?>
            <form method="POST" class="card card-body mb-3 collapse" id="newTerrarium">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <?php $t = null; include 'terrarium_form_fields.php'; ?>
                <div class="mt-2"><button class="btn btn-primary" name="create_terrarium" value="1"><?= trh('Create') ?></button></div>
            </form>
        <?php endif; ?>

        <div class="table-responsive">
            <table class="table table-sm table-bordered align-middle" id="terrariumList">
                <thead class="table-light">
                    <tr>
                        <th><?= trh('Terrarium') ?></th>
                        <th><?= trh('Species / line') ?></th>
                        <th><?= trh('Room') ?></th>
                        <th class="text-end"><?= trh('Estimate') ?></th>
                        <th><?= trh('Last census') ?></th>
                        <th><?= trh('Health') ?></th>
                        <th><?= trh('Responsible') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$terrariums) : ?><tr><td colspan="7" class="text-muted text-center"><?= trh('No terrarium.') ?></td></tr><?php endif; ?>
                    <?php foreach ($terrariums as $t) : $pop = $t['pop']; $h = $t['health']; ?>
                        <tr class="<?= $h['alert'] ? 'table-danger' : ($pop['overdue'] || $h['overdue'] ? 'table-warning' : '') ?>">
                            <td><a class="fw-bold" href="terrarium_view.php?id=<?= (int) $t['id'] ?>"><?= htmlspecialchars($t['code']) ?></a><br><small><?= htmlspecialchars($t['name']) ?></small></td>
                            <td class="small"><?= htmlspecialchars(terrarium_species($t)) ?><?= $t['line_code'] ? '<br><span class="text-muted">' . htmlspecialchars($t['line_code'] . ' — ' . $t['line_name']) . '</span>' : '' ?></td>
                            <td class="small"><?= htmlspecialchars(implode(' / ', array_filter([$t['room'], $t['location']]))) ?></td>
                            <td class="text-end fw-bold"><?= $pop['estimate'] === null ? '—' : '≈ ' . (int) $pop['estimate'] ?></td>
                            <td class="small">
                                <?= $pop['last_census'] ? htmlspecialchars($pop['last_census']['event_date']) : '<span class="text-muted">' . trh('never') . '</span>' ?>
                                <?php if ($pop['overdue']) : ?><br><span class="badge bg-warning text-dark"><?= trh('census due {date}', ['date' => $pop['census_due']]) ?></span><?php endif; ?>
                            </td>
                            <td class="small">
                                <?php if ($h['alert']) : ?><span class="badge bg-danger"><?= $h['alert']['result'] === 'positive' ? trh('positive screening') : trh('abnormal') ?></span>
                                <?php elseif ($h['last']) : ?><span class="badge bg-success"><?= trh('normal') ?></span> <?= htmlspecialchars($h['last']['checked_on']) ?>
                                <?php else : ?><span class="text-muted"><?= trh('no check') ?></span><?php endif; ?>
                                <?php if ($h['overdue']) : ?><br><span class="badge bg-warning text-dark"><?= trh('check due {date}', ['date' => $h['due']]) ?></span><?php endif; ?>
                                <?php if ($h['pending']) : ?><br><span class="badge bg-info text-dark"><?= trh('{n} result(s) pending', ['n' => $h['pending']]) ?></span><?php endif; ?>
                                <?php if ($t['withdrawal']) : ?><br><span class="badge bg-dark"><?= trh('withdrawal until {date}', ['date' => $t['withdrawal']]) ?></span><?php endif; ?>
                            </td>
                            <td class="small"><?= htmlspecialchars((string) $t['responsible_name']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </main>
    <?php require 'footer.php'; ?>
</body>

</html>
