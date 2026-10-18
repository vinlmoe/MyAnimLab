<?php

/**
 * One terrarium: the population (estimate, census curve), its journal
 * (censuses, introductions with their reference, deaths, transfers, notes),
 * group treatments (stock lot debited, prescriber, withdrawal period),
 * health monitoring (observations, environment, screening with the
 * laboratory result) and captures of animals that become identified
 * animals, enrolled in an experiment if chosen.
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
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$userId = $_SESSION['user_id'] ?? null;
$userName = $_SESSION['name'] ?? null;
$canManage = is_bea_user($con);
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$t = load_terrarium($con, $id);
if (!$t) {
    http_response_code(404);
    exit(trh('Terrarium not found.'));
}
$self = 'terrarium_view.php?id=' . $id;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        die(tr('CSRF token validation failed'));
    }
    $action = (string) ($_POST['action'] ?? '');
    $anchor = '';
    try {
        if (in_array($action, ['save', 'archive', 'restore'], true) && !$canManage) {
            throw new Exception(tr('Access restricted to the BEA responsible.'));
        }
        if ($t['status'] !== 'active' && !in_array($action, ['restore', 'screening_result'], true)) {
            throw new Exception(tr('This terrarium is archived.'));
        }
        switch ($action) {
            case 'save':
                terrarium_save($con, $id, $_POST, $userId);
                log_activity($con, 'terrarium_updated', 'terrarium', (string) $id, trim($_POST['code'] ?? ''), $userId);
                $_SESSION['message'] = tr('Terrarium saved.');
                break;
            case 'archive':
            case 'restore':
                $status = $action === 'archive' ? 'archived' : 'active';
                $stmt = $con->prepare("UPDATE terrariums SET status = ?, archived_at = IF(? = 'archived', NOW(), NULL) WHERE id = ?");
                $stmt->bind_param('ssi', $status, $status, $id);
                $stmt->execute();
                $stmt->close();
                log_activity($con, 'terrarium_' . $action, 'terrarium', (string) $id, $t['code'], $userId);
                $_SESSION['message'] = $action === 'archive' ? tr('Terrarium archived.') : tr('Terrarium reactivated.');
                break;
            case 'event':
                $int = fn(string $k): ?int => is_numeric($_POST[$k] ?? null) ? (int) $_POST[$k] : null;
                terrarium_add_event($con, $id, (string) ($_POST['event_type'] ?? ''), (string) ($_POST['event_date'] ?? ''), [
                    'estimate' => $int('estimate'), 'estimate_min' => $int('estimate_min'), 'estimate_max' => $int('estimate_max'),
                    'quantity' => $int('quantity'), 'life_stage' => $_POST['life_stage'] ?? null, 'method' => $_POST['method'] ?? null,
                    'reference' => $_POST['reference'] ?? null, 'note' => $_POST['note'] ?? null,
                ], $userId, $userName);
                $_SESSION['message'] = tr('Recorded in the journal.');
                $anchor = '#journal';
                break;
            case 'treatment':
                $post = $_POST;
                $post['product_id'] = null;
                if ((int) ($post['lot_id'] ?? 0)) {
                    $lotId = (int) $post['lot_id'];
                    $post['product_id'] = (int) ($con->query("SELECT product_id FROM medication_lots WHERE id = $lotId")->fetch_row()[0] ?? 0) ?: null;
                }
                $con->begin_transaction();
                try {
                    record_terrarium_treatment($con, $t, $post, $userId, $userName);
                    $con->commit();
                } catch (Throwable $e) {
                    $con->rollback();
                    throw $e;
                }
                $_SESSION['message'] = tr('Treatment recorded.');
                $anchor = '#treatments';
                break;
            case 'health':
                record_terrarium_health_check($con, $t, $_POST, $userId, $userName);
                $_SESSION['message'] = tr('Health check recorded.');
                $anchor = '#health';
                break;
            case 'screening_result':
                record_terrarium_screening_result($con, $t, (int) ($_POST['check_id'] ?? 0), (string) ($_POST['result'] ?? ''), $_POST['result_detail'] ?? null, $_POST['result_on'] ?? null, $userName);
                $_SESSION['message'] = tr('Screening result recorded.');
                $anchor = '#health';
                break;
            case 'capture':
                $con->begin_transaction();
                try {
                    $r = capture_from_terrarium($con, $t, $_POST, $userId, $userName);
                    $con->commit();
                } catch (Throwable $e) {
                    $con->rollback();
                    throw $e;
                }
                log_activity($con, 'terrarium_capture', 'terrarium', (string) $id, tr('{n} animal(s) captured into cage(s) {cage}', ['n' => count($r['animal_ids']), 'cage' => implode(', ', $r['cage_ids'])]), $userId);
                $_SESSION['message'] = tr('{n} animal(s) captured into cage(s) {cage}.', ['n' => count($r['animal_ids']), 'cage' => implode(', ', $r['cage_ids'])]);
                $anchor = '#captures';
                break;
            default:
                throw new Exception(tr('Unknown action.'));
        }
    } catch (Throwable $e) {
        $_SESSION['message'] = $e instanceof mysqli_sql_exception ? tr('The record could not be saved.') : $e->getMessage();
    }
    header('Location: ' . $self . $anchor);
    exit;
}

$pop = terrarium_population($con, $t);
$health = terrarium_health_summary($con, $t);
$withdrawal = terrarium_withdrawal_until($con, $id);
$events = $con->query("SELECT * FROM terrarium_events WHERE terrarium_id = $id ORDER BY event_date DESC, id DESC")->fetch_all(MYSQLI_ASSOC);
$treatments = $con->query(
    "SELECT tr.*, l.lot_number FROM terrarium_treatments tr LEFT JOIN medication_lots l ON l.id = tr.medication_lot_id WHERE tr.terrarium_id = $id ORDER BY tr.treated_at DESC, tr.id DESC"
)->fetch_all(MYSQLI_ASSOC);
$checks = $con->query("SELECT * FROM terrarium_health_checks WHERE terrarium_id = $id ORDER BY checked_on DESC, id DESC")->fetch_all(MYSQLI_ASSOC);
$captured = $con->query(
    "SELECT ca.event_id, ca.weight_g, m.id, m.animal_uid, m.mouse_id, m.cage_id, m.status, m.sex,
            (SELECT GROUP_CONCAT(e.experiment_number SEPARATOR ', ') FROM experiment_animals ea JOIN experiments e ON e.id = ea.experiment_id WHERE ea.animal_id = m.id) AS experiments
     FROM terrarium_capture_animals ca JOIN terrarium_events ev ON ev.id = ca.event_id JOIN mice m ON m.id = ca.animal_id
     WHERE ev.terrarium_id = $id ORDER BY ca.event_id DESC, m.id"
)->fetch_all(MYSQLI_ASSOC);
$censuses = array_reverse(array_values(array_filter($events, fn($e) => $e['event_type'] === 'census')));
$lots = $con->query(
    'SELECT l.id, l.lot_number, l.remaining_quantity, l.expiry_date, p.name, p.default_unit FROM medication_lots l JOIN medication_products p ON p.id = l.product_id
     WHERE l.remaining_quantity > 0 AND l.expiry_date >= CURDATE() ORDER BY p.name, l.expiry_date'
)->fetch_all(MYSQLI_ASSOC);
$experiments = $con->query("SELECT id, experiment_number, title FROM experiments WHERE status IN ('planned', 'active') ORDER BY experiment_number")->fetch_all(MYSQLI_ASSOC);
$cages = array_column($con->query("SELECT cage_id FROM cages WHERE status = 'active' ORDER BY cage_id")->fetch_all(MYSQLI_ASSOC), 'cage_id');
$lines = $con->query('SELECT id, code, name, species, next_seq FROM animal_lines ORDER BY code')->fetch_all(MYSQLI_ASSOC);
$users = $con->query("SELECT id, name FROM users WHERE status = 'approved' ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$rooms = array_column($con->query("SELECT DISTINCT room FROM cages WHERE room IS NOT NULL AND room <> '' UNION SELECT DISTINCT room FROM terrariums WHERE room IS NOT NULL AND room <> '' ORDER BY room")->fetch_all(MYSQLI_ASSOC), 'room');
$eventTypes = terrarium_event_types();
$checkTypes = terrarium_health_check_types();
$results = terrarium_screening_results();
$kinds = terrarium_treatment_kinds();
$active = $t['status'] === 'active';
$today = date('Y-m-d');
$num = fn($n) => $n === null ? '' : rtrim(rtrim(number_format((float) $n, 3, '.', ''), '0'), '.');

// Census curve (estimate and range).
$chart = '';
if (count($censuses) >= 2) {
    $w = 640;
    $h = 160;
    $pad = 30;
    $times = array_map(fn($c) => strtotime($c['event_date']), $censuses);
    $maxY = max(array_map(fn($c) => (int) ($c['estimate_max'] ?? $c['estimate']), $censuses)) ?: 1;
    $t0 = min($times);
    $span = max(1, max($times) - $t0);
    $x = fn($ts) => round($pad + ($ts - $t0) / $span * ($w - 2 * $pad), 1);
    $y = fn($v) => round($h - $pad + 10 - $v / $maxY * ($h - $pad - 10), 1);
    $points = [];
    $range = '';
    foreach ($censuses as $i => $c) {
        $points[] = $x($times[$i]) . ',' . $y((int) $c['estimate']);
        if ($c['estimate_min'] !== null || $c['estimate_max'] !== null) {
            $range .= '<line x1="' . $x($times[$i]) . '" x2="' . $x($times[$i]) . '" y1="' . $y((int) ($c['estimate_min'] ?? $c['estimate'])) . '" y2="' . $y((int) ($c['estimate_max'] ?? $c['estimate'])) . '" stroke="#adb5bd" stroke-width="6" stroke-linecap="round"/>';
        }
    }
    $chart = '<svg viewBox="0 0 ' . $w . ' ' . $h . '" class="w-100" style="max-height:180px" role="img" aria-label="' . trh('Census curve') . '">'
        . '<line x1="' . $pad . '" x2="' . ($w - $pad) . '" y1="' . ($h - $pad + 10) . '" y2="' . ($h - $pad + 10) . '" stroke="#dee2e6"/>'
        . '<text x="2" y="' . ($y($maxY) + 4) . '" font-size="11" fill="#6c757d">' . $maxY . '</text>'
        . $range . '<polyline fill="none" stroke="#0d6efd" stroke-width="2" points="' . implode(' ', $points) . '"/>';
    foreach ($censuses as $i => $c) {
        $chart .= '<circle cx="' . $x($times[$i]) . '" cy="' . $y((int) $c['estimate']) . '" r="3.5" fill="#0d6efd"><title>' . htmlspecialchars($c['event_date'] . ' : ≈ ' . $c['estimate']) . '</title></circle>';
    }
    $chart .= '<text x="' . $pad . '" y="' . ($h - 2) . '" font-size="11" fill="#6c757d">' . htmlspecialchars($censuses[0]['event_date']) . '</text>'
        . '<text x="' . ($w - $pad) . '" y="' . ($h - 2) . '" font-size="11" fill="#6c757d" text-anchor="end">' . htmlspecialchars(end($censuses)['event_date']) . '</text></svg>';
}

require 'header.php';
?>
<!doctype html>
<html lang="<?= htmlspecialchars(current_locale()) ?>">

<head>
    <title><?= htmlspecialchars($t['code']) ?> — <?= trh('Terrarium') ?> | <?= htmlspecialchars($labName) ?></title>
</head>

<body>
    <main class="container mt-4 content" style="max-width: 1250px">
        <p class="mb-1"><a href="terrarium_dash.php"><i class="fas fa-arrow-left"></i> <?= trh('Terrariums and populations') ?></a></p>
        <h4><i class="fas fa-frog"></i> <?= htmlspecialchars($t['code'] . ' — ' . $t['name']) ?>
            <?php if (!$active) : ?><span class="badge bg-secondary"><?= trh('archived') ?></span><?php endif; ?></h4>
        <p class="text-muted small mb-2">
            <?= htmlspecialchars(terrarium_species($t)) ?><?= $t['line_code'] ? ' · ' . trh('line') . ' ' . htmlspecialchars($t['line_code']) : '' ?>
            <?= $t['room'] ? ' · ' . trh('room') . ' ' . htmlspecialchars($t['room'] . ($t['location'] ? ' / ' . $t['location'] : '')) : '' ?>
            <?= $t['origin'] ? ' · ' . trh('origin') . ' : ' . htmlspecialchars($t['origin']) : '' ?>
            <?= $t['reference'] ? ' · ' . trh('ref.') . ' ' . htmlspecialchars($t['reference']) : '' ?>
            <?= $t['established_on'] ? ' · ' . trh('since {date}', ['date' => $t['established_on']]) : '' ?>
            · <?= trh('responsible') ?> : <?= $t['responsible_name'] ? htmlspecialchars($t['responsible_name']) : trh('administrators') ?>
        </p>
        <?php if ($t['description']) : ?><p class="small"><?= nl2br(htmlspecialchars($t['description'])) ?></p><?php endif; ?>
        <?php include 'message.php'; ?>

        <div class="row g-3 mb-3">
            <div class="col-md-3">
                <div class="card card-body h-100 text-center" id="populationEstimate">
                    <div class="small text-muted"><?= trh('Current estimate') ?></div>
                    <div class="display-6 fw-bold"><?= $pop['estimate'] === null ? '—' : '≈ ' . (int) $pop['estimate'] ?></div>
                    <div class="small text-muted">
                        <?php if ($pop['last_census']) : ?>
                            <?= trh('census of {date}: {n}', ['date' => $pop['last_census']['event_date'], 'n' => (int) $pop['last_census']['estimate']]) ?>
                            <?php $s = $pop['since']; if (array_sum($s)) : ?><br><?= trh('since: +{in} / −{out}', ['in' => $s['introduction'], 'out' => $s['death'] + $s['capture'] + $s['transfer_out']]) ?><?php endif; ?>
                        <?php else : ?><?= trh('no census yet') ?><?php endif; ?>
                    </div>
                    <?php if ($pop['census_due']) : ?><span class="badge mt-1 <?= $pop['overdue'] ? 'bg-warning text-dark' : 'bg-light text-dark' ?>"><?= trh('next census {date}', ['date' => $pop['census_due']]) ?></span><?php endif; ?>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card card-body h-100 text-center">
                    <div class="small text-muted"><?= trh('Health') ?></div>
                    <?php if ($health['alert']) : ?><div class="fs-4 text-danger fw-bold"><?= $health['alert']['result'] === 'positive' ? trh('positive screening') : trh('abnormal') ?></div><div class="small"><?= htmlspecialchars($health['alert']['checked_on']) ?></div>
                    <?php elseif ($health['last']) : ?><div class="fs-4 text-success fw-bold"><?= trh('normal') ?></div><div class="small"><?= trh('last check {date}', ['date' => $health['last']['checked_on']]) ?></div>
                    <?php else : ?><div class="fs-5 text-muted"><?= trh('no check') ?></div><?php endif; ?>
                    <?php if ($health['due']) : ?><span class="badge mt-1 <?= $health['overdue'] ? 'bg-warning text-dark' : 'bg-light text-dark' ?>"><?= trh('next check {date}', ['date' => $health['due']]) ?></span><?php endif; ?>
                    <?php if ($health['pending']) : ?><span class="badge mt-1 bg-info text-dark"><?= trh('{n} result(s) pending', ['n' => $health['pending']]) ?></span><?php endif; ?>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card card-body h-100">
                    <?php if ($withdrawal) : ?><div class="alert alert-dark py-1 small mb-2"><i class="fas fa-ban"></i> <?= trh('Withdrawal period until {date}: no capture for an experiment.', ['date' => $withdrawal]) ?></div><?php endif; ?>
                    <?= $chart ?: '<p class="text-muted small mb-0">' . trh('The census curve appears after two censuses.') . '</p>' ?>
                </div>
            </div>
        </div>

        <?php if ($canManage) : ?>
            <div class="mb-3">
                <?php if ($active) : ?><button class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#editTerrarium"><i class="fas fa-edit"></i> <?= trh('Edit') ?></button><?php endif; ?>
                <form method="POST" class="d-inline" onsubmit="return confirm(<?= htmlspecialchars(json_encode($active ? tr('Archive this terrarium?') : tr('Reactivate this terrarium?'))) ?>)">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <button class="btn btn-sm btn-outline-<?= $active ? 'danger' : 'success' ?>" name="action" value="<?= $active ? 'archive' : 'restore' ?>"><?= $active ? trh('Archive') : trh('Reactivate') ?></button>
                </form>
                <?php if ($active) : ?>
                    <form method="POST" class="card card-body mt-2 collapse" id="editTerrarium">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="id" value="<?= $id ?>">
                        <?php include 'terrarium_form_fields.php'; ?>
                        <div class="mt-2"><button class="btn btn-primary btn-sm" name="action" value="save"><?= trh('Save') ?></button></div>
                    </form>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <ul class="nav nav-tabs" role="tablist">
            <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#journal" type="button"><?= trh('Census and journal') ?></button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#treatments" type="button"><?= trh('Treatments') ?> <span class="badge bg-secondary"><?= count($treatments) ?></span></button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#health" type="button"><?= trh('Health monitoring') ?> <span class="badge bg-<?= $health['alert'] ? 'danger' : 'secondary' ?>"><?= count($checks) ?></span></button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#captures" type="button"><?= trh('Captures') ?> <span class="badge bg-secondary"><?= count($captured) ?></span></button></li>
        </ul>
        <div class="tab-content border border-top-0 p-3 mb-4">
            <!-- Census and journal -->
            <div class="tab-pane fade show active" id="journal">
                <?php if ($active) : ?>
                    <form method="POST" class="row g-2 align-items-end mb-3" id="eventForm">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="id" value="<?= $id ?>">
                        <input type="hidden" name="action" value="event">
                        <div class="col-md-2"><label class="form-label small"><?= trh('Entry') ?></label>
                            <select class="form-select form-select-sm" name="event_type" id="eventType">
                                <?php foreach ($eventTypes as $k => $label) : if ($k === 'capture') { continue; } ?><option value="<?= $k ?>"><?= htmlspecialchars($label) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2"><label class="form-label small"><?= trh('Date') ?></label><input class="form-control form-control-sm" type="date" name="event_date" value="<?= $today ?>" max="<?= $today ?>" required></div>
                        <div class="col-md-1 ev-census"><label class="form-label small"><?= trh('Estimate') ?></label><input class="form-control form-control-sm" type="number" min="0" name="estimate"></div>
                        <div class="col-md-1 ev-census"><label class="form-label small"><?= trh('Low') ?></label><input class="form-control form-control-sm" type="number" min="0" name="estimate_min"></div>
                        <div class="col-md-1 ev-census"><label class="form-label small"><?= trh('High') ?></label><input class="form-control form-control-sm" type="number" min="0" name="estimate_max"></div>
                        <div class="col-md-2 ev-census"><label class="form-label small"><?= trh('Method') ?></label><input class="form-control form-control-sm" name="method" list="censusMethods" placeholder="<?= trh('visual count, sampling…') ?>"></div>
                        <div class="col-md-1 ev-qty d-none"><label class="form-label small"><?= trh('Number of animals') ?></label><input class="form-control form-control-sm" type="number" min="1" name="quantity"></div>
                        <div class="col-md-2 ev-ref d-none"><label class="form-label small"><?= trh('Reference') ?></label><input class="form-control form-control-sm" name="reference" placeholder="<?= trh('batch, supplier, destination…') ?>"></div>
                        <div class="col-md-1 ev-stage"><label class="form-label small"><?= trh('Stage') ?></label><input class="form-control form-control-sm" name="life_stage" list="lifeStages"></div>
                        <div class="col-md-2"><label class="form-label small"><?= trh('Note') ?></label><input class="form-control form-control-sm" name="note"></div>
                        <div class="col-md-auto"><button class="btn btn-sm btn-primary"><?= trh('Add to the journal') ?></button></div>
                    </form>
                    <datalist id="censusMethods"><option value="<?= trh('Visual count') ?>"><option value="<?= trh('Count on a sample area') ?>"><option value="<?= trh('Capture-recapture') ?>"><option value="<?= trh('Estimate by volume / weight') ?>"></datalist>
                    <datalist id="lifeStages"><option value="<?= trh('eggs') ?>"><option value="<?= trh('larvae') ?>"><option value="<?= trh('juveniles') ?>"><option value="<?= trh('adults') ?>"><option value="<?= trh('mixed') ?>"></datalist>
                <?php endif; ?>
                <table class="table table-sm table-striped align-middle small" id="journalTable">
                    <thead><tr><th><?= trh('Date') ?></th><th><?= trh('Entry') ?></th><th class="text-end"><?= trh('Animals') ?></th><th><?= trh('Stage') ?></th><th><?= trh('Method / reference') ?></th><th><?= trh('Note') ?></th><th><?= trh('By') ?></th></tr></thead>
                    <tbody>
                        <?php if (!$events) : ?><tr><td colspan="7" class="text-muted"><?= trh('Nothing recorded yet.') ?></td></tr><?php endif; ?>
                        <?php foreach ($events as $e) : ?>
                            <tr>
                                <td><?= htmlspecialchars($e['event_date']) ?></td>
                                <td><?= htmlspecialchars($eventTypes[$e['event_type']]) ?></td>
                                <td class="text-end">
                                    <?php if ($e['event_type'] === 'census') : ?><strong>≈ <?= (int) $e['estimate'] ?></strong><?= $e['estimate_min'] !== null || $e['estimate_max'] !== null ? ' <span class="text-muted">(' . htmlspecialchars(($e['estimate_min'] ?? '?') . '–' . ($e['estimate_max'] ?? '?')) . ')</span>' : '' ?>
                                    <?php elseif ($e['quantity'] !== null) : ?><?= in_array($e['event_type'], ['introduction'], true) ? '+' : '−' ?><?= (int) $e['quantity'] ?><?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars((string) $e['life_stage']) ?></td>
                                <td><?= htmlspecialchars(trim(($e['method'] ?? '') . ' ' . ($e['reference'] ?? ''))) ?></td>
                                <td><?= nl2br(htmlspecialchars((string) $e['note'])) ?></td>
                                <td><?= htmlspecialchars((string) $e['created_by_name']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Treatments -->
            <div class="tab-pane fade" id="treatments">
                <?php if ($active) : ?>
                    <form method="POST" class="row g-2 align-items-end mb-3" id="treatmentForm">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="id" value="<?= $id ?>">
                        <input type="hidden" name="action" value="treatment">
                        <div class="col-md-2"><label class="form-label small"><?= trh('Kind') ?></label>
                            <select class="form-select form-select-sm" name="kind"><?php foreach ($kinds as $k => $label) : ?><option value="<?= $k ?>"><?= htmlspecialchars($label) ?></option><?php endforeach; ?></select></div>
                        <div class="col-md-2"><label class="form-label small"><?= trh('Date / time') ?></label><input class="form-control form-control-sm" type="datetime-local" name="treated_at" value="<?= date('Y-m-d\TH:i') ?>" required></div>
                        <div class="col-md-4"><label class="form-label small"><?= trh('Medication of the stock (lot)') ?></label>
                            <select class="form-select form-select-sm" name="lot_id">
                                <option value=""><?= trh('— not from the stock —') ?></option>
                                <?php foreach ($lots as $l) : ?><option value="<?= (int) $l['id'] ?>"><?= htmlspecialchars($l['name'] . ' · ' . tr('lot') . ' ' . $l['lot_number'] . ' · ' . $num($l['remaining_quantity']) . ' ' . $l['default_unit'] . ' · exp. ' . $l['expiry_date']) ?></option><?php endforeach; ?>
                            </select></div>
                        <div class="col-md-1"><label class="form-label small"><?= trh('Quantity') ?></label><input class="form-control form-control-sm" name="quantity" inputmode="decimal"></div>
                        <div class="col-md-3"><label class="form-label small"><?= trh('Substance (if not from the stock)') ?></label><input class="form-control form-control-sm" name="substance"></div>
                        <div class="col-md-2"><label class="form-label small"><?= trh('Dose') ?></label><input class="form-control form-control-sm" name="dose" placeholder="<?= trh('e.g. 10 mg/L for 5 days') ?>"></div>
                        <div class="col-md-2"><label class="form-label small"><?= trh('Method') ?></label><input class="form-control form-control-sm" name="method" placeholder="<?= trh('bath, food, water, spray…') ?>"></div>
                        <div class="col-md-3"><label class="form-label small"><?= trh('Reason') ?></label><input class="form-control form-control-sm" name="reason"></div>
                        <div class="col-md-2"><label class="form-label small"><?= trh('Prescriber') ?></label><input class="form-control form-control-sm" name="prescriber_name"></div>
                        <div class="col-md-1"><label class="form-label small"><?= trh('Prescription') ?></label><input class="form-control form-control-sm" name="prescription_reference"></div>
                        <div class="col-md-2"><label class="form-label small"><?= trh('Withdrawal until') ?></label><input class="form-control form-control-sm" type="date" name="withdrawal_until" min="<?= $today ?>"></div>
                        <div class="col-md-auto"><button class="btn btn-sm btn-primary"><?= trh('Record the treatment') ?></button></div>
                    </form>
                <?php endif; ?>
                <table class="table table-sm table-striped align-middle small" id="treatmentTable">
                    <thead><tr><th><?= trh('Date') ?></th><th><?= trh('Kind') ?></th><th><?= trh('Product / substance') ?></th><th><?= trh('Dose / method') ?></th><th><?= trh('Reason') ?></th><th><?= trh('Prescriber') ?></th><th><?= trh('Withdrawal until') ?></th><th><?= trh('By') ?></th></tr></thead>
                    <tbody>
                        <?php if (!$treatments) : ?><tr><td colspan="8" class="text-muted"><?= trh('No treatment.') ?></td></tr><?php endif; ?>
                        <?php foreach ($treatments as $tr) : ?>
                            <tr>
                                <td><?= htmlspecialchars(substr($tr['treated_at'], 0, 16)) ?></td>
                                <td><?= htmlspecialchars($kinds[$tr['kind']] ?? $tr['kind']) ?></td>
                                <td><?= htmlspecialchars((string) $tr['substance']) ?><?= $tr['medication_lot_id'] ? '<br><span class="text-muted">' . trh('{qty} {unit}, lot {lot}', ['qty' => $num($tr['quantity_used']), 'unit' => $tr['unit'], 'lot' => $tr['lot_number']]) . '</span>' : '' ?></td>
                                <td><?= htmlspecialchars(trim(($tr['dose'] ?? '') . ' ' . ($tr['method'] ? '(' . $tr['method'] . ')' : ''))) ?></td>
                                <td><?= htmlspecialchars((string) $tr['reason']) ?></td>
                                <td><?= htmlspecialchars(trim(($tr['prescriber_name'] ?? '') . ' ' . ($tr['prescription_reference'] ?? ''))) ?></td>
                                <td><?= $tr['withdrawal_until'] ? '<span class="badge ' . ($tr['withdrawal_until'] >= $today ? 'bg-dark' : 'bg-light text-dark') . '">' . htmlspecialchars($tr['withdrawal_until']) . '</span>' : '' ?></td>
                                <td><?= htmlspecialchars((string) $tr['performed_by_name']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Health monitoring -->
            <div class="tab-pane fade" id="health">
                <?php if ($active) : ?>
                    <form method="POST" class="row g-2 align-items-end mb-3" id="healthForm">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="id" value="<?= $id ?>">
                        <input type="hidden" name="action" value="health">
                        <div class="col-md-3"><label class="form-label small"><?= trh('Check') ?></label>
                            <select class="form-select form-select-sm" name="check_type" id="checkType"><?php foreach ($checkTypes as $k => $label) : ?><option value="<?= $k ?>"><?= htmlspecialchars($label) ?></option><?php endforeach; ?></select></div>
                        <div class="col-md-2"><label class="form-label small"><?= trh('Date') ?></label><input class="form-control form-control-sm" type="date" name="checked_on" value="<?= $today ?>" max="<?= $today ?>" required></div>
                        <div class="col-md-2"><label class="form-label small"><?= trh('General state') ?></label>
                            <select class="form-select form-select-sm" name="status"><option value="normal"><?= trh('normal') ?></option><option value="abnormal"><?= trh('abnormal') ?></option></select></div>
                        <div class="col-md-3"><label class="form-label small"><?= trh('Signs observed') ?></label><input class="form-control form-control-sm" name="signs" placeholder="<?= trh('lesions, behaviour, mortality…') ?>"></div>
                        <div class="col-md-2"><label class="form-label small"><?= trh('Sick animals seen') ?></label><input class="form-control form-control-sm" type="number" min="0" name="sick_count"></div>
                        <div class="col-md-2"><label class="form-label small"><?= trh('Temperature (°C)') ?></label><input class="form-control form-control-sm" name="temperature_c" inputmode="decimal"></div>
                        <div class="col-md-2"><label class="form-label small"><?= trh('Humidity (%)') ?></label><input class="form-control form-control-sm" type="number" min="0" max="100" name="humidity_pct"></div>
                        <div class="col-md-4"><label class="form-label small"><?= trh('Other parameters') ?></label><input class="form-control form-control-sm" name="parameters" placeholder="<?= trh('pH, nitrites, lighting, substrate…') ?>"></div>
                        <div class="col-12 hc-screening d-none">
                            <div class="row g-2">
                                <div class="col-md-2"><label class="form-label small"><?= trh('Sample') ?></label><input class="form-control form-control-sm" name="sample_type" placeholder="<?= trh('faeces, swab, animals…') ?>"></div>
                                <div class="col-md-1"><label class="form-label small"><?= trh('Animals sampled') ?></label><input class="form-control form-control-sm" type="number" min="0" name="animals_sampled"></div>
                                <div class="col-md-3"><label class="form-label small"><?= trh('Agents screened') ?></label><input class="form-control form-control-sm" name="agents_screened" placeholder="<?= trh('e.g. Batrachochytrium, ranavirus, parasites') ?>"></div>
                                <div class="col-md-2"><label class="form-label small"><?= trh('Laboratory') ?></label><input class="form-control form-control-sm" name="laboratory"></div>
                                <div class="col-md-2"><label class="form-label small"><?= trh('Sample reference') ?></label><input class="form-control form-control-sm" name="sample_reference"></div>
                                <div class="col-md-2"><label class="form-label small"><?= trh('Result') ?></label>
                                    <select class="form-select form-select-sm" name="result"><?php foreach ($results as $k => $label) : ?><option value="<?= $k ?>"><?= htmlspecialchars($label) ?></option><?php endforeach; ?></select></div>
                                <div class="col-md-6"><label class="form-label small"><?= trh('Result detail') ?></label><input class="form-control form-control-sm" name="result_detail"></div>
                            </div>
                        </div>
                        <div class="col-md-6"><label class="form-label small"><?= trh('Actions taken') ?></label><input class="form-control form-control-sm" name="actions" placeholder="<?= trh('isolation, cleaning, treatment, vet called…') ?>"></div>
                        <div class="col-md-auto"><button class="btn btn-sm btn-primary"><?= trh('Record the check') ?></button></div>
                    </form>
                <?php endif; ?>
                <table class="table table-sm table-bordered align-middle small" id="healthTable">
                    <thead class="table-light"><tr><th><?= trh('Date') ?></th><th><?= trh('Check') ?></th><th><?= trh('State') ?></th><th><?= trh('Observations / parameters') ?></th><th><?= trh('Screening') ?></th><th><?= trh('Actions taken') ?></th><th><?= trh('By') ?></th></tr></thead>
                    <tbody>
                        <?php if (!$checks) : ?><tr><td colspan="7" class="text-muted"><?= trh('No health check.') ?></td></tr><?php endif; ?>
                        <?php foreach ($checks as $c) : ?>
                            <tr class="<?= $c['status'] === 'abnormal' ? 'table-danger' : '' ?>">
                                <td><?= htmlspecialchars($c['checked_on']) ?></td>
                                <td><?= htmlspecialchars($checkTypes[$c['check_type']]) ?></td>
                                <td><span class="badge bg-<?= $c['status'] === 'abnormal' ? 'danger' : 'success' ?>"><?= $c['status'] === 'abnormal' ? trh('abnormal') : trh('normal') ?></span></td>
                                <td>
                                    <?= nl2br(htmlspecialchars((string) $c['signs'])) ?>
                                    <?= $c['sick_count'] !== null ? '<br>' . trh('{n} sick animal(s) seen', ['n' => (int) $c['sick_count']]) : '' ?>
                                    <?php $env = array_filter([$c['temperature_c'] !== null ? $num($c['temperature_c']) . ' °C' : null, $c['humidity_pct'] !== null ? (int) $c['humidity_pct'] . ' % HR' : null, $c['parameters']]); ?>
                                    <?= $env ? '<br><span class="text-muted">' . htmlspecialchars(implode(' · ', $env)) . '</span>' : '' ?>
                                </td>
                                <td>
                                    <?php if ($c['check_type'] === 'screening') : ?>
                                        <?= htmlspecialchars($c['sample_type'] . ($c['animals_sampled'] !== null ? ' (' . (int) $c['animals_sampled'] . ')' : '') . ' — ' . $c['agents_screened']) ?>
                                        <?= $c['laboratory'] || $c['sample_reference'] ? '<br><span class="text-muted">' . htmlspecialchars(trim($c['laboratory'] . ' ' . $c['sample_reference'])) . '</span>' : '' ?>
                                        <br><span class="badge bg-<?= ['pending' => 'info text-dark', 'negative' => 'success', 'positive' => 'danger'][$c['result']] ?>"><?= htmlspecialchars($results[$c['result']]) ?></span>
                                        <?= $c['result_on'] && $c['result_on'] !== $c['checked_on'] ? '<small>' . htmlspecialchars($c['result_on']) . '</small>' : '' ?>
                                        <?= $c['result_detail'] ? ' ' . htmlspecialchars($c['result_detail']) : '' ?>
                                        <?php if ($c['result'] === 'pending') : ?>
                                            <form method="POST" class="d-flex flex-wrap gap-1 mt-1 screening-result">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                                <input type="hidden" name="id" value="<?= $id ?>">
                                                <input type="hidden" name="action" value="screening_result">
                                                <input type="hidden" name="check_id" value="<?= (int) $c['id'] ?>">
                                                <select class="form-select form-select-sm w-auto" name="result"><option value="negative"><?= trh('Negative') ?></option><option value="positive"><?= trh('Positive') ?></option></select>
                                                <input class="form-control form-control-sm w-auto" type="date" name="result_on" value="<?= $today ?>" min="<?= htmlspecialchars($c['checked_on']) ?>" max="<?= $today ?>">
                                                <input class="form-control form-control-sm w-auto" name="result_detail" placeholder="<?= trh('Result detail') ?>">
                                                <button class="btn btn-sm btn-outline-primary"><?= trh('Record the result') ?></button>
                                            </form>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <td><?= nl2br(htmlspecialchars((string) $c['actions'])) ?></td>
                                <td><?= htmlspecialchars((string) $c['created_by_name']) ?><?= $c['result_by_name'] && $c['result_by_name'] !== $c['created_by_name'] ? '<br><small class="text-muted">' . trh('result: {name}', ['name' => $c['result_by_name']]) . '</small>' : '' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Captures -->
            <div class="tab-pane fade" id="captures">
                <?php if ($active) : ?>
                    <form method="POST" class="row g-2 align-items-end mb-3" id="captureForm">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="id" value="<?= $id ?>">
                        <input type="hidden" name="action" value="capture">
                        <p class="small text-muted mb-1 col-12"><?= trh('The animals captured become identified animals (origin “captured from a facility population” in the entry register), in a new holding cage or an existing cage, and leave the population estimate.') ?></p>
                        <div class="col-md-2"><label class="form-label small"><?= trh('Date') ?></label><input class="form-control form-control-sm" type="date" name="date" value="<?= $today ?>" max="<?= $today ?>" required></div>
                        <div class="col-md-3"><label class="form-label small"><?= trh('Line') ?> *</label>
                            <select class="form-select form-select-sm" name="line_id" id="captureLine" required>
                                <option value=""><?= trh('Choose…') ?></option>
                                <?php foreach ($lines as $l) : ?><option value="<?= (int) $l['id'] ?>" data-code="<?= htmlspecialchars($l['code']) ?>" data-next="<?= (int) $l['next_seq'] ?>" <?= (int) $t['line_id'] === (int) $l['id'] ? 'selected' : '' ?>><?= htmlspecialchars($l['code'] . ' — ' . $l['name']) ?></option><?php endforeach; ?>
                            </select></div>
                        <div class="col-md-2"><label class="form-label small"><?= trh('Date of birth (estimated)') ?></label><input class="form-control form-control-sm" type="date" name="dob" max="<?= $today ?>"></div>
                        <div class="col-md-1"><label class="form-label small"><?= trh('Stage') ?></label><input class="form-control form-control-sm" name="life_stage" list="lifeStages"></div>
                        <div class="col-md-2"><label class="form-label small"><?= trh('Room of the new cages') ?></label><input class="form-control form-control-sm" name="room" list="terrariumRooms" value="<?= htmlspecialchars((string) $t['room']) ?>"></div>
                        <div class="col-12">
                            <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                                <strong class="small"><?= trh('Distribution by cage') ?></strong>
                                <span class="small text-muted ms-2"><?= trh('Quick fill:') ?></span>
                                <input class="form-control form-control-sm" style="width: 70px" type="number" min="1" max="50" id="fillCages" value="1" aria-label="<?= trh('Number of cages') ?>">
                                <span class="small"><?= trh('cage(s) of') ?></span>
                                <input class="form-control form-control-sm" style="width: 70px" type="number" min="1" max="200" id="fillCount" value="4" aria-label="<?= trh('Animals per cage') ?>">
                                <span class="small"><?= trh('animals') ?></span>
                                <button type="button" class="btn btn-sm btn-outline-secondary" id="fillRows"><?= trh('Fill') ?></button>
                            </div>
                            <table class="table table-sm align-middle mb-1" id="captureRows">
                                <thead><tr><th style="width: 190px"><?= trh('Destination cage') ?></th><th style="width: 110px"><?= trh('Number of animals') ?> *</th><th style="width: 120px"><?= trh('Sex') ?></th><th><?= trh('Identifiers given and individual weight (g)') ?></th><th></th></tr></thead>
                                <tbody>
                                    <tr class="capture-row">
                                        <td><input class="form-control form-control-sm row-cage" name="groups[0][cage]" list="activeCages" placeholder="<?= trh('new cage') ?>"></td>
                                        <td><input class="form-control form-control-sm row-count" type="number" min="1" max="200" name="groups[0][count]" value="4" required></td>
                                        <td><select class="form-select form-select-sm" name="groups[0][sex]"><option value=""><?= trh('unknown') ?></option><option value="male"><?= trh('Male') ?></option><option value="female"><?= trh('Female') ?></option></select></td>
                                        <td><div class="row-weights d-flex flex-wrap gap-1"></div></td>
                                        <td><button type="button" class="btn btn-sm btn-outline-danger row-remove" title="<?= trh('Remove') ?>">&times;</button></td>
                                    </tr>
                                </tbody>
                            </table>
                            <button type="button" class="btn btn-sm btn-outline-primary" id="addRow"><i class="fas fa-plus"></i> <?= trh('Add a cage') ?></button>
                            <span class="small text-muted ms-2" id="captureTotal"></span>
                            <div class="form-text"><?= trh('Leave the cage empty to create a new holding cage; the identifiers follow on automatically from the line. The individual weight is optional; it becomes the baseline weight if the animals are enrolled in an experiment.') ?></div>
                        </div>
                        <div class="col-md-3"><label class="form-label small"><?= trh('Enroll in the experiment') ?></label>
                            <select class="form-select form-select-sm" name="experiment_id">
                                <option value=""><?= trh('— no experiment —') ?></option>
                                <?php foreach ($experiments as $e) : ?><option value="<?= (int) $e['id'] ?>"><?= htmlspecialchars($e['experiment_number'] . ' — ' . $e['title']) ?></option><?php endforeach; ?>
                            </select></div>
                        <div class="col-md-2"><label class="form-label small"><?= trh('Common baseline weight (g)') ?></label><input class="form-control form-control-sm" name="baseline_weight" inputmode="decimal" title="<?= trh('Used for the animals without individual weight') ?>"></div>
                        <div class="col-md-3"><label class="form-label small"><?= trh('Note') ?></label><input class="form-control form-control-sm" name="note"></div>
                        <?php if ($withdrawal) : ?>
                            <div class="col-12"><div class="form-check small"><input class="form-check-input" type="checkbox" name="ignore_withdrawal" value="1" id="ignoreWithdrawal"><label class="form-check-label" for="ignoreWithdrawal"><?= trh('Capture during the withdrawal period (not for an experiment)') ?></label></div></div>
                        <?php endif; ?>
                        <div class="col-md-auto"><button class="btn btn-sm btn-primary"><?= trh('Record the capture') ?></button></div>
                    </form>
                    <datalist id="activeCages"><?php foreach ($cages as $c) : ?><option value="<?= htmlspecialchars($c) ?>"><?php endforeach; ?></datalist>
                    <?php if (!$canManage) : // else printed by the edit form ?><datalist id="terrariumRooms"><?php foreach ($rooms as $r) : ?><option value="<?= htmlspecialchars($r) ?>"><?php endforeach; ?></datalist><?php endif; ?>
                <?php endif; ?>
                <table class="table table-sm table-striped align-middle small" id="capturedTable">
                    <thead><tr><th><?= trh('Capture') ?></th><th><?= trh('Animal') ?></th><th><?= trh('Sex') ?></th><th class="text-end"><?= trh('Weight at capture (g)') ?></th><th><?= trh('Cage') ?></th><th><?= trh('Status') ?></th><th><?= trh('Experiments') ?></th></tr></thead>
                    <tbody>
                        <?php if (!$captured) : ?><tr><td colspan="7" class="text-muted"><?= trh('No animal captured yet.') ?></td></tr><?php endif; ?>
                        <?php foreach ($captured as $a) : ?>
                            <tr>
                                <td>#<?= (int) $a['event_id'] ?></td>
                                <td><a href="animal_view.php?id=<?= (int) $a['id'] ?>"><?= htmlspecialchars($a['animal_uid'] ?: $a['mouse_id'] ?: '#' . $a['id']) ?></a></td>
                                <td><?= $a['sex'] === 'male' ? trh('Male') : ($a['sex'] === 'female' ? trh('Female') : '') ?></td>
                                <td class="text-end"><?= $a['weight_g'] !== null ? htmlspecialchars($num($a['weight_g'])) : '' ?></td>
                                <td><?= htmlspecialchars((string) $a['cage_id']) ?></td>
                                <td><?= htmlspecialchars((string) $a['status']) ?></td>
                                <td><?= htmlspecialchars((string) $a['experiments']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
    <?php require 'footer.php'; ?>
    <script>
        (function () {
            const type = document.getElementById('eventType');
            if (type) {
                const show = () => {
                    const v = type.value;
                    document.querySelectorAll('#eventForm .ev-census').forEach(el => el.classList.toggle('d-none', v !== 'census'));
                    document.querySelectorAll('#eventForm .ev-qty').forEach(el => el.classList.toggle('d-none', v === 'census' || v === 'note'));
                    document.querySelectorAll('#eventForm .ev-ref').forEach(el => el.classList.toggle('d-none', v === 'census' || v === 'note'));
                    document.querySelectorAll('#eventForm .ev-stage').forEach(el => el.classList.toggle('d-none', v === 'note'));
                };
                type.addEventListener('change', show);
                show();
            }
            const check = document.getElementById('checkType');
            if (check) {
                const show = () => document.querySelectorAll('#healthForm .hc-screening').forEach(el => el.classList.toggle('d-none', check.value !== 'screening'));
                check.addEventListener('change', show);
                show();
            }
            // Capture: one row per destination cage, identifiers previewed from the line.
            const rows = document.querySelector('#captureRows tbody');
            if (rows) {
                const line = document.getElementById('captureLine');
                const template = rows.querySelector('.capture-row').cloneNode(true);
                const renumber = () => {
                    let next = Number(line.selectedOptions[0]?.dataset.next || 0);
                    const code = line.selectedOptions[0]?.dataset.code || '';
                    let total = 0;
                    rows.querySelectorAll('.capture-row').forEach((tr, i) => {
                        tr.querySelectorAll('[name^="groups["]').forEach(el => el.name = el.name.replace(/groups\[\d+\]/, 'groups[' + i + ']'));
                        const n = Math.max(0, parseInt(tr.querySelector('.row-count').value, 10) || 0);
                        const uid = k => code ? code + '-' + String(k).padStart(4, '0') : '#' + (k - next + 1);
                        // One weight field per animal, labelled with its future identifier; typed values kept.
                        const box = tr.querySelector('.row-weights');
                        const kept = [...box.querySelectorAll('input')].map(el => el.value);
                        box.innerHTML = '';
                        for (let k = 0; k < Math.min(n, 200); k++) {
                            const cell = document.createElement('div');
                            const label = document.createElement('div');
                            label.className = 'small text-muted';
                            label.textContent = uid(next + k);
                            const input = document.createElement('input');
                            input.className = 'form-control form-control-sm';
                            input.style.width = '82px';
                            input.name = 'groups[' + i + '][weights][]';
                            input.inputMode = 'decimal';
                            input.placeholder = 'g';
                            input.value = kept[k] ?? '';
                            input.setAttribute('aria-label', label.textContent);
                            cell.append(label, input);
                            box.appendChild(cell);
                        }
                        next += n;
                        total += n;
                    });
                    document.getElementById('captureTotal').textContent = <?= json_encode(tr('Total: {n} animal(s)')) ?>.replace('{n}', total);
                };
                const addRow = (count) => {
                    const tr = template.cloneNode(true);
                    tr.querySelector('.row-cage').value = '';
                    tr.querySelector('.row-count').value = count ?? '';
                    tr.querySelector('.row-weights').innerHTML = '';
                    rows.appendChild(tr);
                    return tr;
                };
                document.getElementById('addRow').addEventListener('click', () => { addRow(4); renumber(); });
                document.getElementById('fillRows').addEventListener('click', () => {
                    const cages = Math.min(50, Math.max(1, parseInt(document.getElementById('fillCages').value, 10) || 1));
                    const count = Math.max(1, parseInt(document.getElementById('fillCount').value, 10) || 1);
                    rows.innerHTML = '';
                    for (let i = 0; i < cages; i++) addRow(count);
                    renumber();
                });
                rows.addEventListener('click', e => {
                    if (e.target.closest('.row-remove') && rows.querySelectorAll('.capture-row').length > 1) {
                        e.target.closest('.capture-row').remove();
                        renumber();
                    }
                });
                rows.addEventListener('input', e => { if (!e.target.closest('.row-weights')) renumber(); });
                line.addEventListener('change', renumber);
                renumber();
            }
            // Open the tab named in the address (after a form was sent).
            const openTab = () => {
                const btn = location.hash && window.bootstrap ? document.querySelector('.nav-tabs [data-bs-target="' + location.hash + '"]') : null;
                if (btn) bootstrap.Tab.getOrCreateInstance(btn).show();
            };
            window.addEventListener('hashchange', openTab);
            openTab();
        })();
    </script>
</body>

</html>
