<?php

/**
 * Terrariums (tanks, enclosures) holding populations of animals that are not
 * individually identified.
 *
 * The size of a population is known from periodic approximate censuses; the
 * current estimate is the last census corrected by what was recorded since
 * (introductions, deaths, captures, transfers). Group treatments debit the
 * medication lot used (stock movement linked to the treatment) and may set a
 * withdrawal period during which no animal is captured for an experiment.
 * A capture creates identified animals (origin "population capture", in a
 * new or existing cage) and can enroll them in an experiment at once, with
 * the checks of a normal enrollment (authorization, animals authorized).
 * Health monitoring (observations, environment, screening on samples) is
 * kept as a journal; an abnormal check or a positive result is notified.
 * Requires animal_lib.php and ethics_lib.php.
 */

function terrarium_event_types(): array
{
    return [
        'census' => tr('Census'),
        'introduction' => tr('Introduction'),
        'death' => tr('Deaths observed'),
        'capture' => tr('Capture'),
        'transfer_out' => tr('Transfer out'),
        'note' => tr('Note'),
    ];
}

function terrarium_treatment_kinds(): array
{
    return [
        'antiparasitic' => tr('Antiparasitic'),
        'antibiotic' => tr('Antibiotic'),
        'antifungal' => tr('Antifungal'),
        'vitamin' => tr('Vitamins / supplement'),
        'disinfection' => tr('Disinfection of the terrarium'),
        'other' => tr('Other treatment'),
    ];
}

function load_terrarium(mysqli $con, int $id): ?array
{
    $stmt = $con->prepare(
        'SELECT t.*, l.code AS line_code, l.name AS line_name, l.species AS line_species, u.name AS responsible_name
         FROM terrariums t LEFT JOIN animal_lines l ON l.id = t.line_id LEFT JOIN users u ON u.id = t.responsible_user_id WHERE t.id = ?'
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $t = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $t ?: null;
}

/**
 * Create ($id null) or update a terrarium from a form: code, name, line_id,
 * species, room, location, description, origin, reference, established_on,
 * census_every_days, health_every_days, responsible_user_id.
 */
function terrarium_save(mysqli $con, ?int $id, array $d, ?int $userId): int
{
    $f = fn(string $k): ?string => trim((string) ($d[$k] ?? '')) ?: null;
    $code = $f('code');
    $name = $f('name');
    if ($code === null || $name === null) {
        throw new Exception(tr('Give the code and the name of the terrarium.'));
    }
    $lineId = (int) ($d['line_id'] ?? 0) ?: null;
    $species = $f('species');
    if ($lineId === null && $species === null) {
        throw new Exception(tr('Give the species or the line of the population.'));
    }
    $established = $f('established_on');
    if ($established !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $established)) {
        throw new Exception(tr('Give a valid date, not in the future.'));
    }
    $census = (int) ($d['census_every_days'] ?? 30);
    $health = (int) ($d['health_every_days'] ?? 90);
    if ($census < 0 || $census > 730 || $health < 0 || $health > 730) {
        throw new Exception(tr('The intervals are a number of days (0 to 730, 0 = no reminder).'));
    }
    $responsible = (int) ($d['responsible_user_id'] ?? 0) ?: null;
    $dup = $con->prepare('SELECT id FROM terrariums WHERE code = ? AND id <> ?');
    $other = $id ?? 0;
    $dup->bind_param('si', $code, $other);
    $dup->execute();
    $exists = $dup->get_result()->num_rows > 0;
    $dup->close();
    if ($exists) {
        throw new Exception(tr('The code {code} is already used.', ['code' => $code]));
    }
    $room = $f('room');
    $location = $f('location');
    $description = $f('description');
    $origin = $f('origin');
    $reference = $f('reference');
    if ($id === null) {
        $stmt = $con->prepare(
            'INSERT INTO terrariums (code, name, line_id, species, room, location, description, origin, reference, established_on, census_every_days, health_every_days, responsible_user_id, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('ssisssssssiiii', $code, $name, $lineId, $species, $room, $location, $description, $origin, $reference, $established, $census, $health, $responsible, $userId);
        $stmt->execute();
        $id = (int) $con->insert_id;
    } else {
        $stmt = $con->prepare(
            'UPDATE terrariums SET code = ?, name = ?, line_id = ?, species = ?, room = ?, location = ?, description = ?, origin = ?, reference = ?, established_on = ?,
                census_every_days = ?, health_every_days = ?, responsible_user_id = ? WHERE id = ?'
        );
        $stmt->bind_param('ssisssssssiiii', $code, $name, $lineId, $species, $room, $location, $description, $origin, $reference, $established, $census, $health, $responsible, $id);
        $stmt->execute();
    }
    $stmt->close();
    return $id;
}

// Species of a terrarium: its line's species, else the species typed.
function terrarium_species(array $t): string
{
    return (string) ($t['line_species'] ?? '') ?: (string) ($t['species'] ?? '');
}

/**
 * Current estimate of a population: ['estimate' (int|null), 'last_census'
 * (row|null), 'since' => [type => number], 'census_due' (date|null),
 * 'overdue' (bool)].
 */
function terrarium_population(mysqli $con, array $t, ?string $today = null): array
{
    $today ??= date('Y-m-d');
    $id = (int) $t['id'];
    $last = db_row($con, "SELECT * FROM terrarium_events WHERE terrarium_id = ? AND event_type = 'census' ORDER BY event_date DESC, id DESC LIMIT 1", 'i', [$id]);
    $since = ['introduction' => 0, 'death' => 0, 'capture' => 0, 'transfer_out' => 0];
    $where = "terrarium_id = ? AND event_type IN ('introduction', 'death', 'capture', 'transfer_out')";
    $types = 'i';
    $params = [$id];
    if ($last) {
        $where .= ' AND (event_date > ? OR (event_date = ? AND id > ?))';
        $types .= 'ssi';
        array_push($params, $last['event_date'], $last['event_date'], (int) $last['id']);
    }
    foreach (db_rows($con, "SELECT event_type, COALESCE(SUM(quantity), 0) AS n FROM terrarium_events WHERE $where GROUP BY event_type", $types, $params) as $r) {
        $since[$r['event_type']] = (int) $r['n'];
    }
    $estimate = $last
        ? max(0, (int) $last['estimate'] + $since['introduction'] - $since['death'] - $since['capture'] - $since['transfer_out'])
        : ($since['introduction'] ? max(0, $since['introduction'] - $since['death'] - $since['capture'] - $since['transfer_out']) : null);
    $from = $last['event_date'] ?? ($t['established_on'] ?: substr((string) $t['created_at'], 0, 10));
    $due = $t['status'] === 'active' && (int) $t['census_every_days'] > 0 ? date('Y-m-d', strtotime($from . ' +' . (int) $t['census_every_days'] . ' days')) : null;
    return ['estimate' => $estimate, 'last_census' => $last, 'since' => $since, 'census_due' => $due, 'overdue' => $due !== null && $due < $today];
}

// Latest end of a withdrawal period still running on $date, or null.
function terrarium_withdrawal_until(mysqli $con, int $terrariumId, ?string $date = null): ?string
{
    $date ??= date('Y-m-d');
    $stmt = $con->prepare('SELECT MAX(withdrawal_until) FROM terrarium_treatments WHERE terrarium_id = ? AND withdrawal_until >= ?');
    $stmt->bind_param('is', $terrariumId, $date);
    $stmt->execute();
    $until = $stmt->get_result()->fetch_row()[0] ?? null;
    $stmt->close();
    return $until;
}

function terrarium_add_event(mysqli $con, int $terrariumId, string $type, string $date, array $data, ?int $userId, ?string $userName): int
{
    if (!isset(terrarium_event_types()[$type])) {
        throw new Exception(tr('Unknown event.'));
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date > date('Y-m-d')) {
        throw new Exception(tr('Give a valid date, not in the future.'));
    }
    $estimate = $data['estimate'] ?? null;
    $min = $data['estimate_min'] ?? null;
    $max = $data['estimate_max'] ?? null;
    $quantity = $data['quantity'] ?? null;
    if ($type === 'census') {
        if ($estimate === null || $estimate < 0) {
            throw new Exception(tr('Give the estimated number of animals.'));
        }
        if (($min !== null && $min > $estimate) || ($max !== null && $max < $estimate)) {
            throw new Exception(tr('The estimate must lie between the low and high values.'));
        }
        $quantity = null;
    } elseif ($type !== 'note') {
        if ($quantity === null || $quantity <= 0) {
            throw new Exception(tr('Give the number of animals.'));
        }
        $estimate = $min = $max = null;
    } else {
        $estimate = $min = $max = $quantity = null;
        if (trim((string) ($data['note'] ?? '')) === '') {
            throw new Exception(tr('Write the note.'));
        }
    }
    $stage = trim((string) ($data['life_stage'] ?? '')) ?: null;
    $method = trim((string) ($data['method'] ?? '')) ?: null;
    $reference = trim((string) ($data['reference'] ?? '')) ?: null;
    $note = trim((string) ($data['note'] ?? '')) ?: null;
    $stmt = $con->prepare(
        'INSERT INTO terrarium_events (terrarium_id, event_type, event_date, estimate, estimate_min, estimate_max, quantity, life_stage, method, reference, note, created_by, created_by_name)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('issiiiissssis', $terrariumId, $type, $date, $estimate, $min, $max, $quantity, $stage, $method, $reference, $note, $userId, $userName);
    $stmt->execute();
    $id = (int) $con->insert_id;
    $stmt->close();
    return $id;
}

/**
 * Record a treatment of the whole population. $d: kind, treated_at
 * (Y-m-d H:i), product_id, lot_id, quantity, substance, dose, method,
 * reason, prescriber_name, prescription_reference, withdrawal_until.
 * A medication of the stock debits its lot. Must run in a transaction.
 */
function record_terrarium_treatment(mysqli $con, array $t, array $d, ?int $userId, ?string $userName): int
{
    if (!isset(terrarium_treatment_kinds()[$d['kind'] ?? ''])) {
        throw new Exception(tr('Choose the kind of treatment.'));
    }
    $at = (string) ($d['treated_at'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}$/', $at) || substr($at, 0, 10) > date('Y-m-d')) {
        throw new Exception(tr('Give a valid date and time, not in the future.'));
    }
    $at = str_replace('T', ' ', $at) . ':00';
    $productId = (int) ($d['product_id'] ?? 0) ?: null;
    $lotId = null;
    $quantity = null;
    $unit = null;
    $productName = null;
    if ($productId) {
        $lotId = (int) ($d['lot_id'] ?? 0);
        $quantity = parse_number((string) ($d['quantity'] ?? ''));
        if (!$lotId || $quantity === null || $quantity <= 0) {
            throw new Exception(tr('Choose the lot and give the quantity used.'));
        }
        $lock = $con->prepare('SELECT l.*, p.name, p.default_unit FROM medication_lots l JOIN medication_products p ON p.id = l.product_id WHERE l.id = ? FOR UPDATE');
        $lock->bind_param('i', $lotId);
        $lock->execute();
        $lot = $lock->get_result()->fetch_assoc();
        $lock->close();
        if (!$lot || (int) $lot['product_id'] !== $productId) {
            throw new Exception(tr('The lot chosen is not a lot of this medication.'));
        }
        if ($lot['expiry_date'] < substr($at, 0, 10)) {
            throw new Exception(tr('Lot {lot} is expired: administration blocked.', ['lot' => $lot['lot_number']]));
        }
        if ((float) $lot['remaining_quantity'] < $quantity) {
            throw new Exception(tr('Not enough stock in lot {lot} ({left} {unit} left).', ['lot' => $lot['lot_number'], 'left' => rtrim(rtrim((string) $lot['remaining_quantity'], '0'), '.'), 'unit' => $lot['default_unit']]));
        }
        $unit = $lot['default_unit'];
        $productName = $lot['name'];
    }
    $withdrawal = trim((string) ($d['withdrawal_until'] ?? '')) ?: null;
    if ($withdrawal !== null && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $withdrawal) || $withdrawal < substr($at, 0, 10))) {
        throw new Exception(tr('The end of the withdrawal period must be after the treatment.'));
    }
    $f = fn(string $k): ?string => trim((string) ($d[$k] ?? '')) ?: null;
    $substance = $f('substance') ?? $productName;
    if ($substance === null && $f('reason') === null) {
        throw new Exception(tr('Give the product or substance, or the reason of the treatment.'));
    }
    $stmt = $con->prepare(
        'INSERT INTO terrarium_treatments (terrarium_id, treated_at, kind, product_id, medication_lot_id, quantity_used, unit, substance, dose, method, reason,
            prescriber_name, prescription_reference, withdrawal_until, performed_by, performed_by_name)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $tid = (int) $t['id'];
    $kind = $d['kind'];
    $dose = $f('dose');
    $method = $f('method');
    $reason = $f('reason');
    $prescriber = $f('prescriber_name');
    $prescription = $f('prescription_reference');
    $stmt->bind_param('issiidssssssssis', $tid, $at, $kind, $productId, $lotId, $quantity, $unit, $substance, $dose, $method, $reason, $prescriber, $prescription, $withdrawal, $userId, $userName);
    $stmt->execute();
    $treatmentId = (int) $con->insert_id;
    $stmt->close();

    if ($productId) {
        $useDate = substr($at, 0, 10);
        $up = $con->prepare('UPDATE medication_lots SET remaining_quantity = remaining_quantity - ?, in_use_since = COALESCE(in_use_since, ?) WHERE id = ?');
        $up->bind_param('dsi', $quantity, $useDate, $lotId);
        $up->execute();
        $up->close();
        $why = tr('Treatment of terrarium {code}: {what}', ['code' => $t['code'], 'what' => $substance . ($reason ? ' — ' . $reason : '')]);
        $delta = -$quantity;
        $mv = $con->prepare("INSERT INTO medication_stock_movements (medication_lot_id, movement_type, quantity_delta, reason, terrarium_treatment_id, performed_by) VALUES (?, 'administration', ?, ?, ?, ?)");
        $mv->bind_param('idsii', $lotId, $delta, $why, $treatmentId, $userId);
        $mv->execute();
        $mv->close();
    }
    return $treatmentId;
}

/**
 * Capture animals from a population: they become identified animals of the
 * line (identifiers numbered automatically from the line), spread over one
 * or more cages, and are enrolled in the experiment given, if any.
 * $c: date, line_id, dob, room, experiment_id, baseline_weight, life_stage,
 * note, ignore_withdrawal, and groups: a list of ['cage' => '' or 'new' for
 * a new holding cage, or an active cage id; 'count'; 'sex'; 'weights' (g,
 * one per animal, optional)] (or count, cage and sex directly for a single
 * cage). The weight at capture is the baseline weight of an enrollment. Must run in a transaction.
 * Returns ['event_id', 'animal_ids', 'cage_id' (first), 'cage_ids'].
 */
function capture_from_terrarium(mysqli $con, array $t, array $c, ?int $userId, ?string $userName): array
{
    if ($t['status'] !== 'active') {
        throw new Exception(tr('This terrarium is archived.'));
    }
    $groups = [];
    foreach ($c['groups'] ?? [['cage' => $c['cage'] ?? 'new', 'count' => $c['count'] ?? 0, 'sex' => $c['sex'] ?? null]] as $g) {
        $n = (int) ($g['count'] ?? 0);
        $cage = trim((string) ($g['cage'] ?? ''));
        if ($n === 0 && $cage === '') {
            continue; // empty row of the form
        }
        if ($n < 1) {
            throw new Exception(tr('Give the number of animals for each cage.'));
        }
        $weights = [];
        foreach (array_slice(array_values((array) ($g['weights'] ?? [])), 0, $n) as $raw) {
            if (trim((string) $raw) === '') {
                $weights[] = null;
                continue;
            }
            $w = parse_number((string) $raw);
            if ($w === null || $w <= 0 || $w >= 1000000) {
                throw new Exception(tr('Weight not valid: {value}.', ['value' => $raw]));
            }
            $weights[] = $w;
        }
        $groups[] = ['cage' => $cage === '' ? 'new' : $cage, 'count' => $n, 'weights' => $weights,
            'sex' => in_array($g['sex'] ?? '', ['male', 'female'], true) ? $g['sex'] : null];
    }
    $count = array_sum(array_column($groups, 'count'));
    if ($count < 1 || $count > 200) {
        throw new Exception(tr('Capture between 1 and 200 animals at a time.'));
    }
    $date = (string) ($c['date'] ?? date('Y-m-d'));
    $lineId = (int) ($c['line_id'] ?? 0) ?: (int) $t['line_id'];
    if (!$lineId) {
        throw new Exception(tr('Choose the line the captured animals belong to (their identifiers come from it).'));
    }
    $dob = trim((string) ($c['dob'] ?? '')) ?: null;
    $experimentId = (int) ($c['experiment_id'] ?? 0) ?: null;

    $withdrawal = terrarium_withdrawal_until($con, (int) $t['id'], $date);
    if ($withdrawal !== null) {
        if ($experimentId) {
            throw new Exception(tr('Withdrawal period after a treatment until {date}: no animal can be included in an experiment before.', ['date' => $withdrawal]));
        }
        if (empty($c['ignore_withdrawal'])) {
            throw new Exception(tr('Withdrawal period after a treatment until {date}: confirm the capture anyway (tick the box) if it is not for an experiment.', ['date' => $withdrawal]));
        }
    }

    // The experiment: open, under a valid authorization, within the animals authorized.
    $exp = null;
    if ($experimentId) {
        $stmt = $con->prepare(
            "SELECT e.*, p.id AS protocol_id, t.trial_number, t.start_date, t.end_date, t.iacuc_id FROM experiments e
             JOIN protocols p ON p.id = e.protocol_id JOIN trials t ON t.id = p.trial_id WHERE e.id = ? AND e.status IN ('planned', 'active')"
        );
        $stmt->bind_param('i', $experimentId);
        $stmt->execute();
        $exp = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$exp) {
            throw new Exception(tr('Experiment not found or closed.'));
        }
        $block = trial_enrollment_block($exp, load_authorization($con, $exp['iacuc_id']));
        if ($block) {
            throw new Exception($block);
        }
    }

    // Destination cages.
    $why = tr('Capture in terrarium {code}', ['code' => $t['code']]);
    $room = trim((string) ($c['room'] ?? '')) ?: ($t['room'] ?: null);
    $check = $con->prepare("SELECT cage_id FROM cages WHERE cage_id = ? AND status = 'active'");
    foreach ($groups as &$g) {
        if ($g['cage'] === 'new') {
            $g['cage_id'] = create_holding_cage($con, null, $room, null, $dob, $userId ? [$userId] : [], $why, $userId, $userName);
        } else {
            $check->bind_param('s', $g['cage']);
            $check->execute();
            $g['cage_id'] = $check->get_result()->fetch_row()[0] ?? null;
            if (!$g['cage_id']) {
                throw new Exception(tr('Destination cage {cage} not found or retired.', ['cage' => $g['cage']]));
            }
        }
    }
    unset($g);
    $check->close();
    $cageIds = array_values(array_unique(array_column($groups, 'cage_id')));

    $eventId = terrarium_add_event($con, (int) $t['id'], 'capture', $date, [
        'quantity' => $count, 'life_stage' => $c['life_stage'] ?? null,
        'note' => trim(($exp ? tr('For experiment {number}.', ['number' => $exp['experiment_number']]) . ' ' : '') . (string) ($c['note'] ?? ''))
            . ($withdrawal !== null ? ' ' . tr('(captured during the withdrawal period until {date})', ['date' => $withdrawal]) : ''),
        'reference' => tr('Cage {cage}', ['cage' => implode(', ', $cageIds)]),
    ], $userId, $userName);
    $origin = [
        'origin_type' => 'population_capture',
        'origin_detail' => tr('Terrarium {code} — {name}', ['code' => $t['code'], 'name' => $t['name']]),
        'origin_reference' => tr('Capture #{id}', ['id' => $eventId]),
        'entry_date' => $date,
    ];
    $animalIds = [];
    $weightOf = [];
    $link = $con->prepare('INSERT INTO terrarium_capture_animals (event_id, animal_id, weight_g) VALUES (?, ?, ?)');
    foreach ($groups as $g) {
        for ($i = 0; $i < $g['count']; $i++) {
            $animalId = create_animal($con, $g['cage_id'], $lineId, $g['sex'], $dob, null, $why, $userId, $origin);
            $weight = $g['weights'][$i] ?? null;
            $link->bind_param('iid', $eventId, $animalId, $weight);
            $link->execute();
            $animalIds[] = $animalId;
            $weightOf[$animalId] = $weight;
        }
    }
    $link->close();

    if ($exp) {
        $quotaError = quota_enrollment_error($con, $exp['iacuc_id'], $animalIds);
        if ($quotaError) {
            throw new Exception($quotaError);
        }
        $baseline = parse_number((string) ($c['baseline_weight'] ?? ''));
        $ins = $con->prepare('INSERT INTO experiment_animals (experiment_id, animal_id, baseline_weight_g) VALUES (?, ?, ?)');
        foreach ($animalIds as $animalId) {
            // The weight at capture is the baseline; else the common baseline given.
            $animalBaseline = $weightOf[$animalId] ?? $baseline;
            $ins->bind_param('iid', $experimentId, $animalId, $animalBaseline);
            $ins->execute();
            log_animal_event($con, $animalId, 'experiment_start', ['experiment_id' => $experimentId, 'capture_event_id' => $eventId], $userId);
        }
        $ins->close();
        mark_cages_experimental_for_animals($con, $animalIds, $userId);
        quota_send_alerts($con, $exp['iacuc_id']);
    }
    return ['event_id' => $eventId, 'animal_ids' => $animalIds, 'cage_id' => $cageIds[0], 'cage_ids' => $cageIds];
}

function terrarium_health_check_types(): array
{
    return [
        'observation' => tr('Health observation'),
        'environment' => tr('Environment parameters'),
        'screening' => tr('Screening on samples'),
    ];
}

function terrarium_screening_results(): array
{
    return ['pending' => tr('Pending'), 'negative' => tr('Negative'), 'positive' => tr('Positive')];
}

/**
 * Health state of a population: ['last' (row|null), 'due' (date|null),
 * 'overdue' (bool), 'pending' (screenings awaiting their result),
 * 'alert' (latest observation or screening result, when abnormal)].
 */
function terrarium_health_summary(mysqli $con, array $t, ?string $today = null): array
{
    $today ??= date('Y-m-d');
    $id = (int) $t['id'];
    $last = db_row($con, "SELECT * FROM terrarium_health_checks WHERE terrarium_id = ? ORDER BY checked_on DESC, id DESC LIMIT 1", 'i', [$id]);
    $pending = (int) db_value($con, "SELECT COUNT(*) FROM terrarium_health_checks WHERE terrarium_id = ? AND result = 'pending'", 'i', [$id]);
    $from = $last['checked_on'] ?? ($t['established_on'] ?: substr((string) $t['created_at'], 0, 10));
    $every = (int) ($t['health_every_days'] ?? 0);
    $due = $t['status'] === 'active' && $every > 0 ? date('Y-m-d', strtotime($from . ' +' . $every . ' days')) : null;
    // The state comes from the latest observation or screening with its result
    // (an environment check or a pending screening does not clear an alert).
    $state = db_row(
        $con,
        "SELECT * FROM terrarium_health_checks WHERE terrarium_id = ? AND (check_type = 'observation' OR (check_type = 'screening' AND result <> 'pending'))
         ORDER BY checked_on DESC, id DESC LIMIT 1",
        'i',
        [$id]
    );
    $alert = $state && $state['status'] === 'abnormal' ? $state : null;
    return ['last' => $last, 'due' => $due, 'overdue' => $due !== null && $due < $today, 'pending' => $pending, 'alert' => $alert];
}

// Responsible of the terrarium, else the administrators.
function terrarium_recipients(mysqli $con, array $t): array
{
    if (!empty($t['responsible_user_id'])) {
        return [(int) $t['responsible_user_id']];
    }
    return array_map('intval', array_column($con->query("SELECT id FROM users WHERE status = 'approved' AND role = 'admin'")->fetch_all(MYSQLI_ASSOC), 'id'));
}

/**
 * Record a health check. $d: check_type, checked_on, status, signs,
 * sick_count, temperature_c, humidity_pct, parameters, sample_type,
 * animals_sampled, laboratory, sample_reference, agents_screened, result,
 * result_detail, actions. An abnormal check or a positive result is notified
 * to the responsible (or the administrators).
 */
function record_terrarium_health_check(mysqli $con, array $t, array $d, ?int $userId, ?string $userName): int
{
    $type = (string) ($d['check_type'] ?? '');
    if (!isset(terrarium_health_check_types()[$type])) {
        throw new Exception(tr('Choose the kind of check.'));
    }
    $date = (string) ($d['checked_on'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date > date('Y-m-d')) {
        throw new Exception(tr('Give a valid date, not in the future.'));
    }
    $f = fn(string $k): ?string => trim((string) ($d[$k] ?? '')) ?: null;
    $int = fn(string $k): ?int => is_numeric($d[$k] ?? null) ? (int) $d[$k] : null;
    $status = ($d['status'] ?? '') === 'abnormal' ? 'abnormal' : 'normal';
    $signs = $f('signs');
    if ($status === 'abnormal' && $signs === null) {
        throw new Exception(tr('Describe the signs observed.'));
    }
    $sick = $int('sick_count');
    $animalsSampled = $int('animals_sampled');
    if (($sick !== null && $sick < 0) || ($animalsSampled !== null && $animalsSampled < 0)) {
        throw new Exception(tr('Give the number of animals.'));
    }
    $temperature = parse_number((string) ($d['temperature_c'] ?? ''));
    $humidity = $int('humidity_pct');
    if ($humidity !== null && ($humidity < 0 || $humidity > 100)) {
        throw new Exception(tr('The humidity is a percentage (0 to 100).'));
    }
    if ($type === 'environment' && $temperature === null && $humidity === null && $f('parameters') === null) {
        throw new Exception(tr('Give at least one parameter measured.'));
    }
    $sampleType = $laboratory = $sampleRef = $agents = $result = $resultDetail = $resultOn = $resultBy = null;
    if ($type === 'screening') {
        $sampleType = $f('sample_type');
        $agents = $f('agents_screened');
        if ($sampleType === null || $agents === null) {
            throw new Exception(tr('Give the sample taken and the agents screened.'));
        }
        $laboratory = $f('laboratory');
        $sampleRef = $f('sample_reference');
        $result = isset(terrarium_screening_results()[$d['result'] ?? '']) ? $d['result'] : 'pending';
        $resultDetail = $f('result_detail');
        if ($result !== 'pending') {
            $resultOn = $date;
            $resultBy = $userName;
        }
        if ($result === 'positive') {
            $status = 'abnormal';
        }
    }
    $parameters = $f('parameters');
    $actions = $f('actions');
    $tid = (int) $t['id'];
    $stmt = $con->prepare(
        'INSERT INTO terrarium_health_checks (terrarium_id, checked_on, check_type, status, signs, sick_count, temperature_c, humidity_pct, parameters,
            sample_type, animals_sampled, laboratory, sample_reference, agents_screened, result, result_detail, result_on, result_by_name, actions, created_by, created_by_name)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('issssidississssssssis', $tid, $date, $type, $status, $signs, $sick, $temperature, $humidity, $parameters,
        $sampleType, $animalsSampled, $laboratory, $sampleRef, $agents, $result, $resultDetail, $resultOn, $resultBy, $actions, $userId, $userName);
    $stmt->execute();
    $id = (int) $con->insert_id;
    $stmt->close();
    if ($status === 'abnormal') {
        terrarium_health_notify($con, $t, $result === 'positive'
            ? tr('Positive screening ({agents}): {detail}', ['agents' => $agents, 'detail' => $resultDetail ?? ''])
            : tr('Abnormal health check: {signs}', ['signs' => $signs]));
    }
    return $id;
}

/** Record the result of a pending screening (once). */
function record_terrarium_screening_result(mysqli $con, array $t, int $checkId, string $result, ?string $detail, ?string $date, ?string $userName): void
{
    if (!in_array($result, ['negative', 'positive'], true)) {
        throw new Exception(tr('Choose the result.'));
    }
    $date = $date ?: date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date > date('Y-m-d')) {
        throw new Exception(tr('Give a valid date, not in the future.'));
    }
    $detail = trim((string) $detail) ?: null;
    $tid = (int) $t['id'];
    $stmt = $con->prepare(
        "UPDATE terrarium_health_checks SET result = ?, result_detail = ?, result_on = ?, result_by_name = ?, status = IF(? = 'positive', 'abnormal', status)
         WHERE id = ? AND terrarium_id = ? AND result = 'pending' AND checked_on <= ?"
    );
    $stmt->bind_param('sssssiis', $result, $detail, $date, $userName, $result, $checkId, $tid, $date);
    $stmt->execute();
    $changed = $stmt->affected_rows;
    $stmt->close();
    if ($changed !== 1) {
        throw new Exception(tr('This screening has no pending result (or the date is before the sampling).'));
    }
    if ($result === 'positive') {
        $agents = (string) db_value($con, 'SELECT agents_screened FROM terrarium_health_checks WHERE id = ?', 'i', [$checkId]);
        terrarium_health_notify($con, $t, tr('Positive screening ({agents}): {detail}', ['agents' => $agents, 'detail' => $detail ?? '']));
    }
}

function terrarium_health_notify(mysqli $con, array $t, string $message): void
{
    foreach (terrarium_recipients($con, $t) as $userId) {
        create_notification($con, $userId, tr('Health alert: terrarium {code}', ['code' => $t['code']]), $message, 'terrarium_view.php?id=' . (int) $t['id'] . '#health', 'reminder');
    }
}

// Identifier the next animal of a line will get (e.g. RN-0003).
function line_next_uid(array $line): string
{
    return $line['code'] . '-' . str_pad((string) $line['next_seq'], 4, '0', STR_PAD_LEFT);
}

/**
 * Once a day (process_reminders.php): remind the census and the health check
 * of each population when overdue to its responsible (or the
 * administrators), at most once a week per terrarium and subject.
 */
function terrarium_send_reminders(mysqli $con, ?string $today = null): int
{
    $today ??= date('Y-m-d');
    $last = (string) ($con->query("SELECT value FROM settings WHERE name = 'terrarium_reminders_last'")->fetch_row()[0] ?? '');
    if ($last === $today) {
        return 0;
    }
    $set = $con->prepare("INSERT INTO settings (name, value) VALUES ('terrarium_reminders_last', ?) ON DUPLICATE KEY UPDATE value = VALUES(value)");
    $set->bind_param('s', $today);
    $set->execute();
    $set->close();
    $sent = 0;
    foreach ($con->query("SELECT t.* FROM terrariums t WHERE t.status = 'active'")->fetch_all(MYSQLI_ASSOC) as $t) {
        $pop = terrarium_population($con, $t, $today);
        $health = terrarium_health_summary($con, $t, $today);
        $due = [];
        if ($pop['overdue']) {
            $due[] = ['terrarium_view.php?id=' . (int) $t['id'], tr('Census overdue: terrarium {code}', ['code' => $t['code']]),
                tr('The census of {name} was due on {date}.', ['name' => $t['name'], 'date' => $pop['census_due']])];
        }
        if ($health['overdue']) {
            $due[] = ['terrarium_view.php?id=' . (int) $t['id'] . '#health', tr('Health check overdue: terrarium {code}', ['code' => $t['code']]),
                tr('The health check of {name} was due on {date}.', ['name' => $t['name'], 'date' => $health['due']])];
        }
        if (!$due) {
            continue;
        }
        foreach (terrarium_recipients($con, $t) as $userId) {
            foreach ($due as [$link, $title, $message]) {
                $recent = $con->prepare('SELECT 1 FROM notifications WHERE user_id = ? AND link = ? AND title = ? AND created_at > NOW() - INTERVAL 7 DAY');
                $recent->bind_param('iss', $userId, $link, $title);
                $recent->execute();
                $already = $recent->get_result()->num_rows > 0;
                $recent->close();
                if (!$already) {
                    create_notification($con, $userId, $title, $message, $link, 'reminder');
                    $sent++;
                }
            }
        }
    }
    return $sent;
}
