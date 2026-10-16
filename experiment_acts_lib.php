<?php

/**
 * Experimental acts (injection, gavage, bait, anaesthesia...) planned on the
 * animals of an experiment and confirmed during the daily follow-up.
 *
 * experiment_acts holds what is done (substance, dose, route, optional
 * medication of the stock and quantity per animal, information to ask),
 * experiment_act_animals one row per animal and planned date. Confirming a
 * row as done stores the requested values and, for a stock medication,
 * records one administration per animal and debits the lot (no prescription
 * within an experiment). Requires animal_lib.php.
 */

function experiment_act_types(): array
{
    return [
        'injection' => tr('Injection'),
        'gavage' => tr('Gavage'),
        'bait' => tr('Bait'),
        'anaesthesia' => tr('Anaesthesia'),
        'analgesia' => tr('Analgesia'),
        'sampling' => tr('Sampling'),
        'surgery' => tr('Surgery'),
        'food_weighing' => tr('Food weighing'),
        'other' => tr('Other act'),
    ];
}

function experiment_act_statuses(): array
{
    return [
        'planned' => tr('To do'),
        'done' => tr('Act done'),
        'not_done' => tr('Not done'),
        'cancelled' => tr('Cancelled'),
    ];
}

// Trimmed display of a decimal (no trailing zeros).
function act_number(?string $n): string
{
    return $n === null || $n === '' ? '' : rtrim(rtrim(number_format((float) $n, 3, '.', ''), '0'), '.');
}

// One-line summary of what an act gives: substance, dose, route.
function experiment_act_summary(array $act): string
{
    $parts = [];
    if (!empty($act['substance'])) {
        $parts[] = $act['substance'];
    }
    if ($act['dose_amount'] !== null && $act['dose_amount'] !== '') {
        $parts[] = trim(act_number($act['dose_amount']) . ' ' . ($act['dose_unit'] ?? ''));
    }
    if (!empty($act['route'])) {
        $parts[] = $act['route'];
    }
    return implode(' · ', $parts);
}

// Fields to fill at confirmation, by act id.
function experiment_act_fields(mysqli $con, array $actIds): array
{
    $actIds = array_values(array_unique(array_map('intval', $actIds)));
    if (!$actIds) {
        return [];
    }
    $fields = [];
    foreach ($con->query('SELECT * FROM experiment_act_fields WHERE act_id IN (' . implode(',', $actIds) . ') ORDER BY position, id') as $f) {
        $fields[(int) $f['act_id']][(int) $f['id']] = $f;
    }
    return $fields;
}

// Values entered at confirmation, by experiment_act_animals id.
function experiment_act_values(mysqli $con, array $actAnimalIds): array
{
    $actAnimalIds = array_values(array_unique(array_map('intval', $actAnimalIds)));
    if (!$actAnimalIds) {
        return [];
    }
    $values = [];
    foreach ($con->query('SELECT * FROM experiment_act_values WHERE act_animal_id IN (' . implode(',', $actAnimalIds) . ') ORDER BY id') as $v) {
        $values[(int) $v['act_animal_id']][] = $v;
    }
    return $values;
}

const EXPERIMENT_ACT_ROW_SQL = "SELECT aa.*, a.experiment_id, a.act_type, a.label, a.substance, a.dose_amount, a.dose_unit, a.route,
        a.product_id, a.quantity_per_animal, a.instructions, mp.name AS product_name, mp.default_unit AS product_unit,
        ml.lot_number, ea.animal_id, m.animal_uid, m.mouse_id, m.cage_id, m.sex, m.distinctive_mark, e.experiment_number
    FROM experiment_act_animals aa
    JOIN experiment_acts a ON a.id = aa.act_id
    JOIN experiment_animals ea ON ea.id = aa.experiment_animal_id
    JOIN experiments e ON e.id = a.experiment_id
    JOIN mice m ON m.id = ea.animal_id
    LEFT JOIN medication_products mp ON mp.id = a.product_id
    LEFT JOIN medication_lots ml ON ml.id = aa.medication_lot_id";

// Acts of one enrollment: those still to do up to $upTo (overdue included)
// and every confirmed one, ordered by date.
function enrollment_acts(mysqli $con, int $enrollmentId, string $upTo): array
{
    $stmt = $con->prepare(EXPERIMENT_ACT_ROW_SQL . "
        WHERE aa.experiment_animal_id = ? AND (aa.status IN ('done', 'not_done') OR (aa.status = 'planned' AND aa.planned_date <= ?))
        ORDER BY aa.planned_date, a.id");
    $stmt->bind_param('is', $enrollmentId, $upTo);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

// Acts still to do up to $date (overdue included) for the animals of a cage
// that are under an ongoing experiment, grouped by act.
function cage_acts_due(mysqli $con, string $cageId, string $date): array
{
    $stmt = $con->prepare(EXPERIMENT_ACT_ROW_SQL . "
        WHERE m.cage_id = ? AND m.status = 'active' AND ea.removed_at IS NULL AND aa.status = 'planned' AND aa.planned_date <= ?
        ORDER BY a.id, aa.planned_date, m.animal_uid");
    $stmt->bind_param('ss', $cageId, $date);
    $stmt->execute();
    $byAct = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $byAct[(int) $row['act_id']][] = $row;
    }
    $stmt->close();
    return $byAct;
}

// Number of acts still to do up to $date per cage (for the daily round).
function acts_due_by_cage(mysqli $con, string $date): array
{
    $stmt = $con->prepare(
        "SELECT m.cage_id, COUNT(*) AS n FROM experiment_act_animals aa
         JOIN experiment_animals ea ON ea.id = aa.experiment_animal_id
         JOIN mice m ON m.id = ea.animal_id
         WHERE aa.status = 'planned' AND aa.planned_date <= ? AND m.status = 'active' AND ea.removed_at IS NULL AND m.cage_id IS NOT NULL
         GROUP BY m.cage_id"
    );
    $stmt->bind_param('s', $date);
    $stmt->execute();
    $out = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $out[$row['cage_id']] = (int) $row['n'];
    }
    $stmt->close();
    return $out;
}

// Lots that can be used today (in stock, not expired), by product id.
function usable_lots_by_product(mysqli $con): array
{
    $lots = [];
    foreach ($con->query("SELECT l.id, l.product_id, l.lot_number, l.expiry_date, l.remaining_quantity, p.default_unit
                          FROM medication_lots l JOIN medication_products p ON p.id = l.product_id
                          WHERE l.remaining_quantity > 0 AND l.expiry_date >= CURDATE() ORDER BY l.expiry_date, l.id") as $l) {
        $lots[(int) $l['product_id']][] = $l;
    }
    return $lots;
}

// <option>s of the usable lots of a product, the soonest expiring first.
function act_lot_options(array $lotsByProduct, ?int $productId, ?int $selected = null): string
{
    $html = '<option value="">' . trh('Choose the lot…') . '</option>';
    foreach ($lotsByProduct[(int) $productId] ?? [] as $l) {
        $html .= '<option value="' . (int) $l['id'] . '"' . ((int) $l['id'] === $selected ? ' selected' : '') . '>'
            . htmlspecialchars(tr('lot {lot}', ['lot' => $l['lot_number']]) . ' · ' . act_number($l['remaining_quantity']) . ' ' . $l['default_unit'] . ' · exp. ' . $l['expiry_date'])
            . '</option>';
    }
    return $html;
}

/**
 * Plan an act on enrollments of an experiment for the given dates. $act holds
 * act_type, label, substance, dose_amount, dose_unit, route, product_id,
 * quantity_per_animal, instructions; $fields the information to ask (label,
 * value_type, unit, required). Only ongoing enrollments of the experiment are
 * kept. Returns [act id, rows planned]. Must run in a transaction.
 */
function plan_experiment_act(mysqli $con, int $experimentId, array $act, array $fields, array $dates, array $enrollmentIds, ?int $userId, ?string $userName): array
{
    if (!isset(experiment_act_types()[$act['act_type']])) {
        throw new Exception(tr('Choose the type of act.'));
    }
    if ($act['label'] === '') {
        throw new Exception(tr('Give the act a name.'));
    }
    $dates = array_values(array_unique(array_filter($dates, fn($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d))));
    sort($dates);
    if (!$dates) {
        throw new Exception(tr('Choose at least one date.'));
    }
    if (count($dates) > 366) {
        throw new Exception(tr('Too many dates (366 at most).'));
    }
    if ($act['product_id'] !== null) {
        $p = $con->prepare('SELECT id FROM medication_products WHERE id = ? AND active = 1');
        $p->bind_param('i', $act['product_id']);
        $p->execute();
        if ($p->get_result()->num_rows !== 1) {
            throw new Exception(tr('Unknown medication.'));
        }
        $p->close();
        if ($act['quantity_per_animal'] === null || $act['quantity_per_animal'] <= 0) {
            throw new Exception(tr('Give the quantity of medication taken from the stock per animal.'));
        }
    }

    $enrollmentIds = array_values(array_unique(array_filter(array_map('intval', $enrollmentIds))));
    $kept = [];
    if ($enrollmentIds) {
        $stmt = $con->prepare(
            "SELECT ea.id FROM experiment_animals ea JOIN mice m ON m.id = ea.animal_id
             WHERE ea.experiment_id = ? AND ea.removed_at IS NULL AND m.status = 'active' AND ea.id IN (" . implode(',', $enrollmentIds) . ')'
        );
        $stmt->bind_param('i', $experimentId);
        $stmt->execute();
        $kept = array_map('intval', array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'id'));
        $stmt->close();
    }
    if (!$kept) {
        throw new Exception(tr('Tick at least one animal followed in this experiment.'));
    }

    $ins = $con->prepare(
        'INSERT INTO experiment_acts (experiment_id, act_type, label, substance, dose_amount, dose_unit, route, product_id, quantity_per_animal, instructions, created_by, created_by_name)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $ins->bind_param('isssdssidsis', $experimentId, $act['act_type'], $act['label'], $act['substance'], $act['dose_amount'], $act['dose_unit'], $act['route'],
        $act['product_id'], $act['quantity_per_animal'], $act['instructions'], $userId, $userName);
    $ins->execute();
    $actId = (int) $con->insert_id;
    $ins->close();

    $insField = $con->prepare('INSERT INTO experiment_act_fields (act_id, position, label, value_type, unit, required) VALUES (?, ?, ?, ?, ?, ?)');
    foreach (array_values($fields) as $i => $f) {
        $insField->bind_param('iisssi', $actId, $i, $f['label'], $f['value_type'], $f['unit'], $f['required']);
        $insField->execute();
    }
    $insField->close();

    $insRow = $con->prepare('INSERT INTO experiment_act_animals (act_id, experiment_animal_id, planned_date) VALUES (?, ?, ?)');
    $n = 0;
    foreach ($kept as $enrollmentId) {
        foreach ($dates as $d) {
            $insRow->bind_param('iis', $actId, $enrollmentId, $d);
            $insRow->execute();
            $n++;
        }
    }
    $insRow->close();
    return [$actId, $n];
}

/**
 * Confirm one planned act of one animal as 'done' or 'not_done'.
 * $raw: ['status', 'values' => [field id => typed value], 'lot_id', 'quantity',
 * 'note', 'done_at' (Y-m-d H:i:s)]. A required field must be filled when the
 * act is done; a reason is needed when it was not. A medication of the stock
 * is traced as an administration and debited from the lot. Must run in a
 * transaction. Returns the animal id, for the event log.
 */
function confirm_experiment_act(mysqli $con, int $actAnimalId, array $raw, ?int $followupId, ?int $userId, ?string $userName, ?int $onlyEnrollment = null, ?string $onlyCage = null): int
{
    $lock = $con->prepare(EXPERIMENT_ACT_ROW_SQL . ' WHERE aa.id = ? FOR UPDATE');
    $lock->bind_param('i', $actAnimalId);
    $lock->execute();
    $row = $lock->get_result()->fetch_assoc();
    $lock->close();
    $who = $row ? ($row['animal_uid'] ?: $row['mouse_id'] ?: '#' . $row['animal_id']) : '';
    if (!$row || ($onlyEnrollment !== null && (int) $row['experiment_animal_id'] !== $onlyEnrollment) || ($onlyCage !== null && $row['cage_id'] !== $onlyCage)) {
        throw new Exception(tr('Act not found.'));
    }
    if ($row['status'] !== 'planned') {
        throw new Exception(tr('{act}: this act is no longer to do.', ['act' => $row['label'] . ' — ' . $who]));
    }
    $status = $raw['status'] ?? '';
    if (!in_array($status, ['done', 'not_done'], true)) {
        throw new Exception(tr('Unknown act status.'));
    }
    $doneAt = (string) ($raw['done_at'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $doneAt)) {
        throw new Exception(tr('Invalid date or time of the act.'));
    }
    if (substr($doneAt, 0, 10) > date('Y-m-d')) {
        throw new Exception(tr('An act cannot be confirmed in the future.'));
    }
    if (substr($doneAt, 0, 10) < $row['planned_date']) {
        throw new Exception(tr('{act}: planned on {date}, it cannot be confirmed before.', ['act' => $row['label'] . ' — ' . $who, 'date' => $row['planned_date']]));
    }
    $note = trim((string) ($raw['note'] ?? ''));
    $label = $row['label'] . ' — ' . $who;

    $valueRows = [];
    $lotId = null;
    $quantity = null;
    if ($status === 'not_done') {
        if ($note === '') {
            throw new Exception(tr('{act}: give the reason why the act was not done.', ['act' => $label]));
        }
    } else {
        foreach (experiment_act_fields($con, [(int) $row['act_id']])[(int) $row['act_id']] ?? [] as $fieldId => $field) {
            $value = trim((string) ($raw['values'][$fieldId] ?? ''));
            if ($value === '') {
                if ($field['required']) {
                    throw new Exception(tr('{act}: "{field}" is required.', ['act' => $label, 'field' => $field['label']]));
                }
                continue;
            }
            $number = $field['value_type'] === 'number' ? parse_number($value) : null;
            if ($field['value_type'] === 'number' && $number === null) {
                throw new Exception(tr('{act}: "{field}" must be a number.', ['act' => $label, 'field' => $field['label']]));
            }
            $valueRows[] = [$fieldId, $field['label'], $field['value_type'], $field['unit'], $number, $field['value_type'] === 'text' ? $value : null];
        }
        if ($row['product_id'] !== null) {
            $lotId = (int) ($raw['lot_id'] ?? 0);
            $quantity = parse_number((string) ($raw['quantity'] ?? ''));
            if (!$lotId) {
                throw new Exception(tr('{act}: choose the lot of {product} used.', ['act' => $label, 'product' => $row['product_name']]));
            }
            if ($quantity === null || $quantity <= 0) {
                throw new Exception(tr('{act}: give the quantity of {product} used.', ['act' => $label, 'product' => $row['product_name']]));
            }
        }
    }

    $up = $con->prepare("UPDATE experiment_act_animals SET status = ?, done_at = ?, done_by = ?, done_by_name = ?, note = ?, followup_id = ?, medication_lot_id = ?, quantity_used = ? WHERE id = ?");
    $noteValue = $note !== '' ? $note : null;
    $up->bind_param('ssissiidi', $status, $doneAt, $userId, $userName, $noteValue, $followupId, $lotId, $quantity, $actAnimalId);
    $up->execute();
    $up->close();

    $insValue = $con->prepare('INSERT INTO experiment_act_values (act_animal_id, field_id, label, value_type, unit, value_number, value_text) VALUES (?, ?, ?, ?, ?, ?, ?)');
    foreach ($valueRows as [$fieldId, $fLabel, $type, $unit, $number, $text]) {
        $insValue->bind_param('iisssds', $actAnimalId, $fieldId, $fLabel, $type, $unit, $number, $text);
        $insValue->execute();
    }
    $insValue->close();

    if ($lotId) {
        record_act_medication($con, $row, $lotId, $quantity, $doneAt, $note, $userId, $userName);
    }
    return (int) $row['animal_id'];
}

// Trace the medication given during an act (no prescription) and debit the lot.
function record_act_medication(mysqli $con, array $row, int $lotId, float $quantity, string $doneAt, string $note, ?int $userId, ?string $userName): void
{
    $lotStmt = $con->prepare('SELECT product_id, lot_number, remaining_quantity, expiry_date FROM medication_lots WHERE id = ? FOR UPDATE');
    $lotStmt->bind_param('i', $lotId);
    $lotStmt->execute();
    $lot = $lotStmt->get_result()->fetch_assoc();
    $lotStmt->close();
    if (!$lot || (int) $lot['product_id'] !== (int) $row['product_id']) {
        throw new Exception(tr('The lot chosen is not a lot of {product}.', ['product' => $row['product_name']]));
    }
    if ($lot['expiry_date'] < substr($doneAt, 0, 10)) {
        throw new Exception(tr('Lot {lot} is expired: administration blocked.', ['lot' => $lot['lot_number']]));
    }
    if ((float) $lot['remaining_quantity'] < $quantity) {
        throw new Exception(tr('Not enough stock in lot {lot} ({left} {unit} left).', ['lot' => $lot['lot_number'], 'left' => act_number($lot['remaining_quantity']), 'unit' => $row['product_unit']]));
    }
    $notes = trim(tr('Experimental act {number}: {act}', ['number' => $row['experiment_number'], 'act' => $row['label']]) . ($note !== '' ? ' — ' . $note : ''));
    $add = $con->prepare('INSERT INTO medication_administrations (prescription_id, experiment_act_animal_id, animal_id, medication_lot_id, administered_at, quantity_used, unit, route, notes, administered_by, administered_by_name)
                          VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $actAnimalId = (int) $row['id'];
    $animalId = (int) $row['animal_id'];
    $add->bind_param('iiisdsssis', $actAnimalId, $animalId, $lotId, $doneAt, $quantity, $row['product_unit'], $row['route'], $notes, $userId, $userName);
    $add->execute();
    $administrationId = (int) $con->insert_id;
    $add->close();
    $useDate = substr($doneAt, 0, 10);
    $update = $con->prepare('UPDATE medication_lots SET remaining_quantity = remaining_quantity - ?, in_use_since = COALESCE(in_use_since, ?) WHERE id = ?');
    $update->bind_param('dsi', $quantity, $useDate, $lotId);
    $update->execute();
    $update->close();
    $movement = $con->prepare("INSERT INTO medication_stock_movements (medication_lot_id, movement_type, quantity_delta, reason, administration_id, performed_by) VALUES (?, 'administration', ?, ?, ?, ?)");
    $delta = -$quantity;
    $movement->bind_param('idsii', $lotId, $delta, $notes, $administrationId, $userId);
    $movement->execute();
    $movement->close();
}

/**
 * Read the confirmations posted by a form: act_status[id] ('' = later),
 * act_value[id][field], act_lot[id], act_qty[id], act_note[id], act_time[id].
 * Returns [act animal id => raw] for confirm_experiment_act(), dated $date.
 */
function posted_act_confirmations(array $post, string $date, ?int $sharedLot = null, ?string $sharedNote = null, ?string $sharedTime = null): array
{
    $out = [];
    foreach ((array) ($post['act_status'] ?? []) as $id => $status) {
        if ($status === '' || $status === null) {
            continue;
        }
        $time = trim((string) ($post['act_time'][$id] ?? $sharedTime ?? ''));
        if (!preg_match('/^\d{2}:\d{2}$/', $time)) {
            $time = date('H:i');
        }
        $note = trim((string) ($post['act_note'][$id] ?? ''));
        $out[(int) $id] = [
            'status' => (string) $status,
            'values' => (array) ($post['act_value'][$id] ?? []),
            'lot_id' => (int) ($post['act_lot'][$id] ?? $sharedLot ?? 0),
            'quantity' => (string) ($post['act_qty'][$id] ?? ''),
            'note' => $note !== '' ? $note : (string) $sharedNote,
            'done_at' => $date . ' ' . $time . ':00',
        ];
    }
    return $out;
}
