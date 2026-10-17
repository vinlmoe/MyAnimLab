<?php

/**
 * Experimental acts of an experiment (injection, gavage, bait, anaesthesia...):
 * plan an act on all the animals or a group of them (ticked in a table
 * sorted by cage) on chosen dates, with the substance, its dose and route,
 * an optional medication of the stock debited at each act (no prescription
 * within an experiment) and the information to ask when it is done. Each
 * act is then confirmed on its day during the daily follow-up; this page
 * shows, per act, the status of every animal on every date.
 */

session_start();
require 'dbcon.php';
require 'animal_lib.php';
require 'experiment_acts_lib.php';
require_once 'training_lib.php';

if (!isset($_SESSION['username'])) {
    header('Location: index.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$currentUserId = $_SESSION['user_id'] ?? null;
$currentUserName = $_SESSION['name'] ?? null;
$experimentId = (int) ($_GET['experiment_id'] ?? $_POST['experiment_id'] ?? 0);

$stmt = $con->prepare(
    "SELECT e.*, p.protocol_number, p.title AS protocol_title, p.trial_id, t.trial_number
     FROM experiments e JOIN protocols p ON p.id = e.protocol_id JOIN trials t ON t.id = p.trial_id WHERE e.id = ?"
);
$stmt->bind_param('i', $experimentId);
$stmt->execute();
$experiment = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$experiment) {
    require 'header.php';
    echo "<div class='container mt-4'><div class='alert alert-danger'>" . trh('Experiment not found.') . "</div></div>";
    require 'footer.php';
    exit;
}

$protocolId = (int) $experiment['protocol_id'];
$selfUrl = 'experiment_acts.php?experiment_id=' . $experimentId;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        die(tr('CSRF token validation failed'));
    }

    if (isset($_POST['plan_act'])) {
        $productId = (int) ($_POST['product_id'] ?? 0) ?: null;
        $act = [
            'act_type' => (string) ($_POST['act_type'] ?? ''),
            'label' => trim((string) ($_POST['label'] ?? '')),
            'substance' => trim((string) ($_POST['substance'] ?? '')) ?: null,
            'dose_amount' => parse_number($_POST['dose_amount'] ?? ''),
            'dose_unit' => trim((string) ($_POST['dose_unit'] ?? '')) ?: null,
            'route' => trim((string) ($_POST['route'] ?? '')) ?: null,
            'product_id' => $productId,
            'quantity_per_animal' => $productId ? parse_number($_POST['quantity_per_animal'] ?? '') : null,
            'instructions' => trim((string) ($_POST['instructions'] ?? '')) ?: null,
        ];
        if ($act['label'] === '' && isset(experiment_act_types()[$act['act_type']])) {
            $act['label'] = experiment_act_types()[$act['act_type']];
        }
        $fields = [];
        foreach ((array) ($_POST['field_label'] ?? []) as $i => $label) {
            $label = trim((string) $label);
            if ($label === '') {
                continue;
            }
            $type = ($_POST['field_type'][$i] ?? '') === 'number' ? 'number' : 'text';
            $fields[] = [
                'label' => mb_substr($label, 0, 255),
                'value_type' => $type,
                'unit' => $type === 'number' ? (trim((string) ($_POST['field_unit'][$i] ?? '')) ?: null) : null,
                'required' => !empty($_POST['field_required'][$i]) ? 1 : 0,
            ];
        }
        mysqli_begin_transaction($con);
        try {
            [$actId, $n] = plan_experiment_act($con, $experimentId, $act, $fields, (array) ($_POST['dates'] ?? []), (array) ($_POST['enrollment_ids'] ?? []), $currentUserId, $currentUserName);
            log_activity($con, 'experiment_act_planned', 'experiment', (string) $experimentId, $act['label'] . ' — ' . tr('{n} act(s) planned', ['n' => $n]), $currentUserId);
            mysqli_commit($con);
            $_SESSION['message'] = tr('Act "{act}" planned: {n} act(s) to confirm during the daily follow-up.', ['act' => $act['label'], 'n' => $n]);
            header("Location: $selfUrl#act$actId");
            exit;
        } catch (Exception $e) {
            mysqli_rollback($con);
            $_SESSION['message'] = tr('Act not planned: {error}', ['error' => $e->getMessage()]);
        }
    } elseif (isset($_POST['cancel_act'])) {
        // Cancel what is still to do of an act, on one date or on every date.
        $actId = (int) ($_POST['act_id'] ?? 0);
        $date = (string) ($_POST['cancel_date'] ?? '');
        $sql = "UPDATE experiment_act_animals aa JOIN experiment_acts a ON a.id = aa.act_id
                SET aa.status = 'cancelled', aa.done_at = NOW(), aa.done_by = ?, aa.done_by_name = ?
                WHERE aa.act_id = ? AND a.experiment_id = ? AND aa.status = 'planned'";
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $stmt = $con->prepare($sql . ' AND aa.planned_date = ?');
            $stmt->bind_param('isiis', $currentUserId, $currentUserName, $actId, $experimentId, $date);
        } else {
            $stmt = $con->prepare($sql);
            $stmt->bind_param('isii', $currentUserId, $currentUserName, $actId, $experimentId);
        }
        $stmt->execute();
        $n = $stmt->affected_rows;
        $stmt->close();
        if ($n) {
            log_activity($con, 'experiment_act_cancelled', 'experiment', (string) $experimentId, tr('Act #{id}: {n} act(s) cancelled', ['id' => $actId, 'n' => $n]) . ($date ? " ($date)" : ''), $currentUserId);
        }
        $_SESSION['message'] = tr('{n} act(s) still to do cancelled.', ['n' => $n]);
        header("Location: $selfUrl#act$actId");
        exit;
    }
    header("Location: $selfUrl");
    exit;
}

// Ongoing enrollments, sorted by cage, for the selection table.
$stmt = $con->prepare(
    "SELECT ea.id, ea.animal_id, m.animal_uid, m.mouse_id, m.cage_id, m.sex, m.distinctive_mark, l.code AS line_code
     FROM experiment_animals ea JOIN mice m ON m.id = ea.animal_id LEFT JOIN animal_lines l ON l.id = m.line_id
     WHERE ea.experiment_id = ? AND ea.removed_at IS NULL AND m.status = 'active'
     ORDER BY m.cage_id IS NULL, m.cage_id, m.animal_uid"
);
$stmt->bind_param('i', $experimentId);
$stmt->execute();
$ongoingByCage = [];
foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $en) {
    $ongoingByCage[$en['cage_id'] ?? ''][] = $en;
}
$stmt->close();
$nOngoing = array_sum(array_map('count', $ongoingByCage));

// Planned acts with the status of every animal on every date.
$stmt = $con->prepare(
    "SELECT a.*, mp.name AS product_name, mp.default_unit AS product_unit, mp.is_narcotic, mp.concentration, mp.concentration_unit FROM experiment_acts a
     LEFT JOIN medication_products mp ON mp.id = a.product_id WHERE a.experiment_id = ? ORDER BY a.id DESC"
);
$stmt->bind_param('i', $experimentId);
$stmt->execute();
$acts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$fieldsByAct = experiment_act_fields($con, array_column($acts, 'id'));
$rowsByAct = [];
if ($acts) {
    $stmt = $con->prepare(EXPERIMENT_ACT_ROW_SQL . ' WHERE a.experiment_id = ? ORDER BY m.cage_id, m.animal_uid, aa.planned_date');
    $stmt->bind_param('i', $experimentId);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $rowsByAct[(int) $r['act_id']][] = $r;
    }
    $stmt->close();
}
$allRowIds = [];
foreach ($rowsByAct as $rows) {
    foreach ($rows as $r) {
        if ($r['status'] === 'done') {
            $allRowIds[] = (int) $r['id'];
        }
    }
}
$valuesByRow = experiment_act_values($con, $allRowIds);

$products = $con->query('SELECT id, name, default_unit, active_ingredient, is_narcotic, concentration, concentration_unit FROM medication_products WHERE active = 1 ORDER BY name')->fetch_all(MYSQLI_ASSOC);
$today = date('Y-m-d');
$statusLabels = experiment_act_statuses();
$statusCell = [
    'planned' => ['', '○'],
    'done' => ['table-success', '✓'],
    'not_done' => ['table-danger', '✗'],
    'cancelled' => ['text-muted', '—'],
];
$animalLabel = fn(array $r): string => $r['animal_uid'] ?: ($r['mouse_id'] ?: '#' . $r['animal_id']);

require 'header.php';
?>
<!doctype html>
<html lang="<?= htmlspecialchars(current_locale()) ?>">

<head>
    <title><?= trh('Experimental acts') ?> — <?= htmlspecialchars($experiment['experiment_number']) ?> | <?= htmlspecialchars($labName) ?></title>
    <style>
        .act-matrix { font-size: .8rem; }
        .act-matrix th, .act-matrix td { text-align: center; white-space: nowrap; padding: .2rem .35rem; }
        .act-matrix .sticky-col { position: sticky; left: 0; background: #fff; z-index: 1; text-align: left; }
        .act-matrix thead .sticky-col { background: #f8f9fa; }
        .act-matrix td.overdue { background: #fff3cd; }
        .act-matrix th.today { background: #cfe2ff; }
        .cage-row th { background: #f1f3f5; }
    </style>
</head>

<body>
    <main class="container mt-4 content" style="max-width: 1200px">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-2">
                <li class="breadcrumb-item"><a href="trial_manage.php"><i class="fas fa-flask"></i> <?= trh('Trials') ?></a></li>
                <li class="breadcrumb-item"><a href="protocol_manage.php?trial_id=<?= (int) $experiment['trial_id'] ?>"><?= htmlspecialchars($experiment['trial_number']) ?></a></li>
                <li class="breadcrumb-item"><a href="experiment_manage.php?protocol_id=<?= $protocolId ?>#experiment<?= $experimentId ?>"><?= htmlspecialchars($experiment['protocol_number']) ?></a></li>
                <li class="breadcrumb-item active" aria-current="page"><?= htmlspecialchars($experiment['experiment_number']) ?></li>
            </ol>
        </nav>
        <h4><i class="fas fa-syringe"></i> <?= trh('Experimental acts') ?> — <?= htmlspecialchars($experiment['experiment_number'] . ' · ' . $experiment['title']) ?></h4>
        <p class="text-muted small"><?= trh('Plan the acts of the experiment (injection, gavage, bait, anaesthesia...) on all the animals or a group of them. On the planned day, each act is confirmed by ticking it during the daily follow-up of the animal or of its cage, with the information it asks for. A medication of the stock is debited from the lot chosen at confirmation; no prescription is needed within an experiment.') ?></p>

        <?php include 'message.php'; ?>
        <?= training_warning_banner($con) ?>

        <div class="d-flex justify-content-between align-items-center mb-2">
            <h5 class="mb-0"><?= trh('Planned acts') ?> <span class="badge bg-secondary"><?= count($acts) ?></span></h5>
            <?php if ($nOngoing) : ?>
                <button class="btn btn-primary btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#planAct"><i class="fas fa-plus"></i> <?= trh('Plan an act') ?></button>
            <?php endif; ?>
        </div>

        <?php if (!$nOngoing) : ?>
            <div class="alert alert-secondary"><?= trh('No animal is followed in this experiment: enroll animals before planning acts.') ?></div>
        <?php else : ?>
            <div class="collapse <?= !$acts ? 'show' : '' ?>" id="planAct">
                <form method="POST" class="card card-body mb-4 border-primary" id="planActForm">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="experiment_id" value="<?= $experimentId ?>">

                    <h6>1. <?= trh('Act') ?></h6>
                    <div class="row g-2 mb-3">
                        <div class="col-md-3">
                            <label class="form-label" for="act_type"><?= trh('Type of act') ?> *</label>
                            <select class="form-select" name="act_type" id="act_type" required>
                                <option value=""><?= trh('Choose…') ?></option>
                                <?php foreach (experiment_act_types() as $code => $label) : ?>
                                    <option value="<?= $code ?>"><?= htmlspecialchars($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label" for="act_label"><?= trh('Name of the act') ?></label>
                            <input class="form-control" name="label" id="act_label" maxlength="255" placeholder="<?= trh('e.g. LPS injection D0 (default: the type of act)') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="act_route"><?= trh('Administration route') ?></label>
                            <input class="form-control" name="route" id="act_route" list="routeList" maxlength="100" placeholder="<?= trh('e.g. IP, SC, PO') ?>">
                            <datalist id="routeList">
                                <?php foreach (['IP', 'SC', 'IV', 'IM', 'PO', 'ID', tr('Intranasal'), tr('Inhalation'), tr('Topical'), tr('In food'), tr('In drinking water')] as $r) : ?><option value="<?= htmlspecialchars($r) ?>"><?php endforeach; ?>
                            </datalist>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label" for="act_substance"><?= trh('Substance / molecule') ?></label>
                            <input class="form-control" name="substance" id="act_substance" maxlength="255" placeholder="<?= trh('e.g. Ketamine / xylazine, LPS, tamoxifen') ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label" for="dose_amount"><?= trh('Dose') ?></label>
                            <input class="form-control" name="dose_amount" id="dose_amount" inputmode="decimal" pattern="[0-9]+([.,][0-9]+)?">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label" for="dose_unit"><?= trh('Dose unit') ?></label>
                            <input class="form-control" name="dose_unit" id="dose_unit" list="doseUnitList" maxlength="30" placeholder="mg/kg">
                            <datalist id="doseUnitList">
                                <?php foreach (['mg/kg', 'µg/kg', 'mL/kg', 'mg/animal', 'µg/animal', 'µL/animal', 'mL/animal', 'g', '%'] as $u) : ?><option value="<?= htmlspecialchars($u) ?>"><?php endforeach; ?>
                            </datalist>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="product_id"><?= trh('Medication of the stock') ?></label>
                            <select class="form-select" name="product_id" id="product_id">
                                <option value="" data-unit=""><?= trh('None (not taken from the stock)') ?></option>
                                <?php foreach ($products as $p) : ?>
                                    <option value="<?= (int) $p['id'] ?>" data-unit="<?= htmlspecialchars($p['default_unit']) ?>" data-conc="<?= htmlspecialchars(act_number($p['concentration'])) ?>" data-conc-unit="<?= htmlspecialchars($p['concentration_unit'] ?? '') ?>"><?= htmlspecialchars($p['name'] . ($p['active_ingredient'] ? ' (' . $p['active_ingredient'] . ')' : '') . ($p['concentration'] ? ' — ' . act_number($p['concentration']) . ' ' . $p['concentration_unit'] : '') . ($p['is_narcotic'] ? ' — ' . tr('narcotic') : '')) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 d-none" id="quantityBox">
                            <label class="form-label" for="quantity_per_animal"><?= trh('Quantity taken from the stock per animal') ?> <span id="quantityStar">*</span></label>
                            <div class="input-group">
                                <input class="form-control" name="quantity_per_animal" id="quantity_per_animal" inputmode="decimal" pattern="[0-9]+([.,][0-9]+)?">
                                <span class="input-group-text" id="quantityUnit"></span>
                            </div>
                            <div class="form-text" id="quantityHelp"><?= trh('Suggested at confirmation, where it can be adjusted to the animal.') ?></div>
                            <div class="form-text text-success d-none" id="weightDoseHelp"><i class="fas fa-weight"></i> <?= str_replace('{formula}', '<span id="weightFormula"></span>', trh('Dose by weight: the volume of each animal is calculated at confirmation from its latest weight ({formula}). A fixed quantity is optional (used when the weight is unknown).', ['formula' => '{formula}'])) ?></div>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label" for="instructions"><?= trh('Instructions') ?></label>
                            <textarea class="form-control" name="instructions" id="instructions" rows="1" placeholder="<?= trh('e.g. fasting 6 h before, inject before 10:00') ?>"></textarea>
                        </div>
                    </div>

                    <h6>2. <?= trh('Information to ask when the act is done') ?> <small class="text-muted">(<?= trh('optional') ?>)</small></h6>
                    <div id="actFields"></div>
                    <div class="mb-3">
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="addActField"><i class="fas fa-plus"></i> <?= trh('Add a piece of information') ?></button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-preset="<?= htmlspecialchars(json_encode(['label' => tr('Food given'), 'type' => 'number', 'unit' => 'g'])) ?>">+ <?= trh('Food given (g)') ?></button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-preset="<?= htmlspecialchars(json_encode(['label' => tr('Food left'), 'type' => 'number', 'unit' => 'g'])) ?>">+ <?= trh('Food left (g)') ?></button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-preset="<?= htmlspecialchars(json_encode(['label' => tr('Volume given'), 'type' => 'number', 'unit' => 'mL'])) ?>">+ <?= trh('Volume given (mL)') ?></button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-preset="<?= htmlspecialchars(json_encode(['label' => tr('Weight before the act'), 'type' => 'number', 'unit' => 'g'])) ?>">+ <?= trh('Weight before the act (g)') ?></button>
                    </div>
                    <template id="actFieldTpl">
                        <div class="row g-2 mb-2 act-field">
                            <div class="col-md-5"><input class="form-control form-control-sm" name="field_label[]" maxlength="255" placeholder="<?= trh('Label (e.g. Food left)') ?>"></div>
                            <div class="col-md-2">
                                <select class="form-select form-select-sm field-type" name="field_type[]">
                                    <option value="number"><?= trh('Number value') ?></option>
                                    <option value="text"><?= trh('Text') ?></option>
                                </select>
                            </div>
                            <div class="col-md-2"><input class="form-control form-control-sm field-unit" name="field_unit[]" maxlength="50" placeholder="<?= trh('Unit') ?>"></div>
                            <div class="col-md-2 form-check pt-1 ps-4"><input class="form-check-input field-required" type="checkbox" value="1"><label class="form-check-label small"><?= trh('Required') ?></label><input type="hidden" name="field_required[]" value="0"></div>
                            <div class="col-md-1"><button type="button" class="btn btn-sm btn-outline-danger remove-field" title="<?= trh('Remove') ?>">&times;</button></div>
                        </div>
                    </template>

                    <h6>3. <?= trh('Dates') ?> *</h6>
                    <div class="row g-2 align-items-end mb-2">
                        <div class="col-auto">
                            <label class="form-label small" for="repeatFrom"><?= trh('From (date)') ?></label>
                            <input type="date" class="form-control form-control-sm" id="repeatFrom" value="<?= $today ?>">
                        </div>
                        <div class="col-auto">
                            <label class="form-label small" for="repeatTo"><?= trh('To (date)') ?></label>
                            <input type="date" class="form-control form-control-sm" id="repeatTo">
                        </div>
                        <div class="col-auto">
                            <label class="form-label small" for="repeatEvery"><?= trh('every (days)') ?></label>
                            <input type="number" min="1" max="365" class="form-control form-control-sm" id="repeatEvery" value="1" style="width: 6rem">
                        </div>
                        <div class="col-auto"><button type="button" class="btn btn-sm btn-outline-primary" id="addDates"><i class="fas fa-calendar-plus"></i> <?= trh('Add these dates') ?></button></div>
                        <div class="col-auto"><button type="button" class="btn btn-sm btn-outline-secondary" id="clearDates"><?= trh('Clear the dates') ?></button></div>
                    </div>
                    <div id="dateList" class="d-flex flex-wrap gap-1 mb-1"></div>
                    <p class="small text-muted mb-3" id="dateCount"></p>

                    <h6>4. <?= trh('Animals') ?> * <small class="text-muted" id="animalCount"></small></h6>
                    <div class="table-responsive mb-3" style="max-height: 28rem;">
                        <table class="table table-sm table-bordered align-middle mb-0" id="animalTable">
                            <thead class="table-light">
                                <tr>
                                    <th style="width: 3rem"><input class="form-check-input" type="checkbox" id="checkAll" title="<?= trh('All the animals') ?>"></th>
                                    <th><?= trh('Animal') ?> — <label for="checkAll" class="fw-normal"><?= trh('all the animals of the experiment ({n})', ['n' => $nOngoing]) ?></label></th>
                                    <th><?= trh('Line') ?></th>
                                    <th><?= trh('Distinctive mark') ?></th>
                                </tr>
                            </thead>
                            <?php foreach ($ongoingByCage as $cage => $animals) : $cageKey = 'c' . md5((string) $cage); ?>
                                <tbody>
                                    <tr class="cage-row">
                                        <th><input class="form-check-input check-cage" type="checkbox" data-cage="<?= $cageKey ?>" id="cage_<?= $cageKey ?>"></th>
                                        <th colspan="3"><label for="cage_<?= $cageKey ?>"><i class="fas fa-box"></i> <?= $cage !== '' ? trh('Cage {cage}', ['cage' => $cage]) : trh('No cage') ?> <span class="badge bg-secondary"><?= count($animals) ?></span></label></th>
                                    </tr>
                                    <?php foreach ($animals as $a) : ?>
                                        <tr>
                                            <td><input class="form-check-input check-animal" type="checkbox" name="enrollment_ids[]" value="<?= (int) $a['id'] ?>" data-cage="<?= $cageKey ?>" id="en<?= (int) $a['id'] ?>"></td>
                                            <td><label for="en<?= (int) $a['id'] ?>"><?= htmlspecialchars($animalLabel($a)) ?></label> <?= $a['sex'] === 'male' ? '♂' : ($a['sex'] === 'female' ? '♀' : '') ?></td>
                                            <td><?= htmlspecialchars($a['line_code'] ?? '') ?></td>
                                            <td class="small text-muted"><?= htmlspecialchars($a['distinctive_mark'] ?? '') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            <?php endforeach; ?>
                        </table>
                    </div>
                    <div><button type="submit" name="plan_act" class="btn btn-primary" id="planSubmit"><i class="fas fa-calendar-check"></i> <?= trh('Plan the act') ?></button></div>
                </form>
            </div>
        <?php endif; ?>

        <?php foreach ($acts as $act) :
            $actId = (int) $act['id'];
            $rows = $rowsByAct[$actId] ?? [];
            $counts = array_count_values(array_column($rows, 'status')) + ['planned' => 0, 'done' => 0, 'not_done' => 0, 'cancelled' => 0];
            $dates = array_values(array_unique(array_column($rows, 'planned_date')));
            sort($dates);
            $byAnimal = [];
            foreach ($rows as $r) {
                $byAnimal[(int) $r['experiment_animal_id']]['animal'] = $r;
                $byAnimal[(int) $r['experiment_animal_id']]['dates'][$r['planned_date']] = $r;
            }
            $overdue = count(array_filter($rows, fn($r) => $r['status'] === 'planned' && $r['planned_date'] < $today));
            $plannedDates = array_values(array_unique(array_column(array_filter($rows, fn($r) => $r['status'] === 'planned'), 'planned_date')));
            sort($plannedDates);
            $fields = $fieldsByAct[$actId] ?? []; ?>
            <div class="card mb-3" id="act<?= $actId ?>">
                <div class="card-header d-flex justify-content-between align-items-start flex-wrap gap-2">
                    <div>
                        <span class="badge bg-primary"><?= htmlspecialchars(experiment_act_types()[$act['act_type']] ?? $act['act_type']) ?></span>
                        <strong><?= htmlspecialchars($act['label']) ?></strong>
                        <?php if ($summary = experiment_act_summary($act)) : ?><span class="text-muted">— <?= htmlspecialchars($summary) ?></span><?php endif; ?>
                        <div class="small text-muted">
                            <?php if ($act['product_id']) : $perGram = act_quantity_per_gram($act); ?><i class="fas fa-pills"></i> <?= $perGram
                                ? trh('Stock: {product}, volume by weight ({formula})', ['product' => $act['product_name'], 'formula' => $perGram[1]])
                                : trh('Stock: {product}, {qty} {unit} per animal', ['product' => $act['product_name'], 'qty' => act_number($act['quantity_per_animal']), 'unit' => $act['product_unit']]) ?><?= $act['is_narcotic'] ? ' <span class="badge bg-dark">' . trh('narcotic') . '</span>' : '' ?> · <?php endif; ?>
                            <?php if ($fields) : ?><?= trh('Asks: {fields}', ['fields' => implode(', ', array_map(fn($f) => $f['label'] . ($f['unit'] ? ' (' . $f['unit'] . ')' : '') . ($f['required'] ? ' *' : ''), $fields))]) ?> · <?php endif; ?>
                            <?= trh('planned by {user} on {date}', ['user' => $act['created_by_name'] ?? '—', 'date' => substr($act['created_at'], 0, 10)]) ?>
                        </div>
                        <?php if ($act['instructions']) : ?><div class="small"><i class="fas fa-info-circle"></i> <?= nl2br(htmlspecialchars($act['instructions'])) ?></div><?php endif; ?>
                    </div>
                    <div class="text-end small">
                        <span class="badge bg-success"><?= trh('{n} done', ['n' => $counts['done']]) ?></span>
                        <span class="badge bg-secondary"><?= trh('{n} to do', ['n' => $counts['planned']]) ?></span>
                        <?php if ($overdue) : ?><span class="badge bg-warning text-dark"><?= trh('{n} overdue', ['n' => $overdue]) ?></span><?php endif; ?>
                        <?php if ($counts['not_done']) : ?><span class="badge bg-danger"><?= trh('{n} not done', ['n' => $counts['not_done']]) ?></span><?php endif; ?>
                        <?php if ($counts['cancelled']) : ?><span class="badge bg-light text-dark"><?= trh('{n} cancelled', ['n' => $counts['cancelled']]) ?></span><?php endif; ?>
                        <?php if ($plannedDates) : ?>
                            <form method="POST" class="d-flex gap-1 mt-1 justify-content-end" onsubmit="return confirm(<?= htmlspecialchars(trj('Cancel the acts still to do?')) ?>)">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                <input type="hidden" name="experiment_id" value="<?= $experimentId ?>">
                                <input type="hidden" name="act_id" value="<?= $actId ?>">
                                <select name="cancel_date" class="form-select form-select-sm" style="width: auto">
                                    <option value=""><?= trh('every date still to do') ?></option>
                                    <?php foreach ($plannedDates as $d) : ?><option value="<?= $d ?>"><?= htmlspecialchars($d) ?></option><?php endforeach; ?>
                                </select>
                                <button class="btn btn-sm btn-outline-danger" name="cancel_act"><?= trh('Cancel') ?></button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered act-matrix mb-1">
                            <thead class="table-light">
                                <tr>
                                    <th class="sticky-col"><?= trh('Animal') ?></th>
                                    <th><?= trh('Cage') ?></th>
                                    <?php foreach ($dates as $d) : ?><th class="<?= $d === $today ? 'today' : '' ?>" title="<?= htmlspecialchars($d) ?>"><?= htmlspecialchars(date('d/m', strtotime($d))) ?></th><?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($byAnimal as $enId => $info) : $a = $info['animal']; ?>
                                    <tr>
                                        <td class="sticky-col"><a href="experiment_followup.php?<?= htmlspecialchars(http_build_query(['experiment_animal_id' => $enId, 'return' => $selfUrl])) ?>"><?= htmlspecialchars($animalLabel($a)) ?></a></td>
                                        <td><?= htmlspecialchars($a['cage_id'] ?? '—') ?></td>
                                        <?php foreach ($dates as $d) :
                                            $r = $info['dates'][$d] ?? null;
                                            if (!$r) : ?><td></td><?php continue; endif;
                                            [$cls, $sym] = $statusCell[$r['status']];
                                            if ($r['status'] === 'planned' && $d < $today) {
                                                $cls = 'overdue';
                                            }
                                            $tip = [$d . ' — ' . $statusLabels[$r['status']]];
                                            if ($r['done_at'] && $r['status'] !== 'planned') {
                                                $tip[] = substr($r['done_at'], 0, 16) . ' — ' . act_done_by_text($r);
                                            }
                                            if ($r['medication_lot_id']) {
                                                $tip[] = tr('{qty} {unit}, lot {lot}', ['qty' => act_number($r['quantity_used']), 'unit' => $r['product_unit'], 'lot' => $r['lot_number']]);
                                            }
                                            foreach ($valuesByRow[(int) $r['id']] ?? [] as $v) {
                                                $tip[] = $v['label'] . ' : ' . observation_value($v);
                                            }
                                            if ($r['note']) {
                                                $tip[] = $r['note'];
                                            } ?>
                                            <td class="<?= $cls ?>" title="<?= htmlspecialchars(implode("\n", $tip)) ?>"><?= $sym ?></td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <p class="small text-muted mb-0">✓ <?= trh('Act done') ?> · ✗ <?= trh('Not done') ?> · ○ <?= trh('To do') ?> · <span class="px-1" style="background:#fff3cd">○</span> <?= trh('overdue') ?> · — <?= trh('Cancelled') ?>. <?= trh('Hover a cell for the details (time, person, lot, values entered).') ?></p>
                </div>
            </div>
        <?php endforeach; ?>

        <a class="btn btn-outline-secondary" href="experiment_manage.php?protocol_id=<?= $protocolId ?>#experiment<?= $experimentId ?>"><i class="fas fa-arrow-left"></i> <?= trh('Back to the experiment') ?></a>
    </main>

    <script>
        (function() {
            const form = document.getElementById('planActForm');
            if (!form) return;

            // Default name from the type of act.
            const type = document.getElementById('act_type');
            const label = document.getElementById('act_label');
            type.addEventListener('change', () => {
                label.placeholder = type.value ? type.options[type.selectedIndex].text : '';
                if (type.value === 'food_weighing' && !document.querySelector('#actFields .act-field')) {
                    addField({label: <?= trj('Food given') ?>, type: 'number', unit: 'g'});
                    addField({label: <?= trj('Food left') ?>, type: 'number', unit: 'g'});
                }
            });

            // Quantity taken from the stock only for a medication.
            const product = document.getElementById('product_id');
            const qtyBox = document.getElementById('quantityBox');
            const qty = document.getElementById('quantity_per_animal');
            const doseAmount = document.getElementById('dose_amount');
            const doseUnit = document.getElementById('dose_unit');
            // Same rule as act_quantity_per_gram(): X mass/kg with a product in mL of known concentration, or X mL/kg.
            const byWeight = () => {
                const o = product.options[product.selectedIndex];
                const unit = (doseUnit.value || '').replace(/\s/g, '').toLowerCase();
                const dose = parseFloat((doseAmount.value || '').replace(',', '.'));
                if (!product.value || !(dose > 0) || (o.dataset.unit || '').toLowerCase() !== 'ml') return null;
                if (unit === 'ml/kg') return dose + ' mL/kg × ' + <?= trj('weight') ?>;
                const conc = (o.dataset.concUnit || '').replace(/\s/g, '').toLowerCase();
                if (/^(g|mg|µg|ug|mcg|ng)\/kg$/.test(unit) && o.dataset.conc && /^(g|mg|µg|ug|mcg|ng)\/ml$/.test(conc)) {
                    return dose + ' ' + doseUnit.value + ' × ' + <?= trj('weight') ?> + ' ÷ ' + o.dataset.conc + ' ' + o.dataset.concUnit;
                }
                return null;
            };
            const syncProduct = () => {
                const on = product.value !== '';
                const formula = byWeight();
                qtyBox.classList.toggle('d-none', !on);
                qty.required = on && !formula;
                document.getElementById('quantityStar').hidden = !!formula;
                document.getElementById('weightDoseHelp').classList.toggle('d-none', !formula);
                document.getElementById('quantityHelp').classList.toggle('d-none', !!formula);
                document.getElementById('weightFormula').textContent = formula || '';
                document.getElementById('quantityUnit').textContent = product.options[product.selectedIndex].dataset.unit || '';
            };
            product.addEventListener('change', syncProduct);
            doseAmount.addEventListener('input', syncProduct);
            doseUnit.addEventListener('input', syncProduct);
            syncProduct();

            // Information to ask.
            const box = document.getElementById('actFields');
            const tpl = document.getElementById('actFieldTpl');
            function addField(values) {
                const row = tpl.content.firstElementChild.cloneNode(true);
                const t = row.querySelector('.field-type');
                const unit = row.querySelector('.field-unit');
                const req = row.querySelector('.field-required');
                const reqHidden = row.querySelector('[name="field_required[]"]');
                if (values) {
                    row.querySelector('[name="field_label[]"]').value = values.label;
                    t.value = values.type;
                    unit.value = values.unit || '';
                }
                const sync = () => unit.style.visibility = t.value === 'number' ? 'visible' : 'hidden';
                t.addEventListener('change', sync);
                sync();
                req.addEventListener('change', () => reqHidden.value = req.checked ? '1' : '0');
                row.querySelector('.remove-field').addEventListener('click', () => row.remove());
                box.appendChild(row);
                if (!values) row.querySelector('input').focus();
            }
            document.getElementById('addActField').addEventListener('click', () => addField(null));
            document.querySelectorAll('[data-preset]').forEach(b => b.addEventListener('click', () => addField(JSON.parse(b.dataset.preset))));

            // Dates: generated from a period and kept as removable chips.
            const dateList = document.getElementById('dateList');
            const dateCount = document.getElementById('dateCount');
            const dates = new Set();
            const fmt = d => d.toISOString().slice(0, 10);
            const render = () => {
                dateList.innerHTML = '';
                [...dates].sort().forEach(d => {
                    const chip = document.createElement('span');
                    chip.className = 'badge bg-info text-dark';
                    chip.innerHTML = '<input type="hidden" name="dates[]" value="' + d + '">' + d.split('-').reverse().join('/') + ' <a href="#" class="text-dark text-decoration-none ms-1" aria-label="×">×</a>';
                    chip.querySelector('a').addEventListener('click', e => { e.preventDefault(); dates.delete(d); render(); });
                    dateList.appendChild(chip);
                });
                dateCount.textContent = dates.size ? <?= trj('{n} date(s)') ?>.replace('{n}', dates.size) : <?= trj('No date yet: choose a period (or the same day twice) and add it.') ?>;
                updateCount();
            };
            document.getElementById('addDates').addEventListener('click', () => {
                const from = document.getElementById('repeatFrom').value;
                const to = document.getElementById('repeatTo').value || from;
                const every = Math.max(1, parseInt(document.getElementById('repeatEvery').value, 10) || 1);
                if (!from) return;
                const end = new Date(to + 'T00:00:00Z');
                for (let d = new Date(from + 'T00:00:00Z'), n = 0; d <= end && n < 366; d.setUTCDate(d.getUTCDate() + every), n++) {
                    dates.add(fmt(d));
                }
                render();
            });
            document.getElementById('clearDates').addEventListener('click', () => { dates.clear(); render(); });

            // Animals: all, by cage, one by one.
            const animals = [...form.querySelectorAll('.check-animal')];
            const all = document.getElementById('checkAll');
            const cages = [...form.querySelectorAll('.check-cage')];
            const count = document.getElementById('animalCount');
            function updateCount() {
                const n = animals.filter(a => a.checked).length;
                cages.forEach(c => {
                    const mine = animals.filter(a => a.dataset.cage === c.dataset.cage);
                    const on = mine.filter(a => a.checked).length;
                    c.checked = on === mine.length;
                    c.indeterminate = on > 0 && on < mine.length;
                });
                all.checked = n === animals.length;
                all.indeterminate = n > 0 && n < animals.length;
                count.textContent = '(' + <?= trj('{n} animal(s) ticked') ?>.replace('{n}', n) + ')';
                const total = n * dates.size;
                document.getElementById('planSubmit').lastChild.textContent = ' ' + (total ? <?= trj('Plan the act ({n} act(s))') ?>.replace('{n}', total) : <?= trj('Plan the act') ?>);
            }
            all.addEventListener('change', () => { animals.forEach(a => a.checked = all.checked); updateCount(); });
            cages.forEach(c => c.addEventListener('change', () => {
                animals.filter(a => a.dataset.cage === c.dataset.cage).forEach(a => a.checked = c.checked);
                updateCount();
            }));
            animals.forEach(a => a.addEventListener('change', updateCount));
            form.addEventListener('submit', e => {
                if (!dates.size || !animals.some(a => a.checked)) {
                    e.preventDefault();
                    alert(<?= trj('Choose at least one date and tick at least one animal.') ?>);
                }
            });
            render();
        })();
    </script>
    <?php require 'footer.php'; ?>
</body>

</html>
