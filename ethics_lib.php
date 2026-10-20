<?php

/**
 * Project authorizations (ethics number, authorization number, validity
 * period and regulatory PDF documents), shared by the authorization, trial,
 * protocol and experiment pages. Requires $con (mysqli) and i18n.php.
 */

// Regulatory documents of an authorization: key => [label, column].
const ETHICS_DOCUMENTS = [
    'request' => ['Authorization request', 'request_file'],
    'opinion' => ['Ethics committee opinion', 'committee_opinion_file'],
    'decision' => ['Authorization decision', 'decision_file'],
];

const ETHICS_MAX_PDF_BYTES = 20 * 1024 * 1024;

// Days before the end of validity from which an authorization is flagged.
const ETHICS_EXPIRY_WARNING_DAYS = 60;

// Private folder of the documents: never linked directly, always served
// through ethics_file.php to signed-in users.
function ethics_storage_dir(): string
{
    return __DIR__ . '/storage/ethics';
}

function load_authorization(mysqli $con, ?string $ethicsNumber): ?array
{
    if ($ethicsNumber === null || $ethicsNumber === '') {
        return null;
    }
    $stmt = $con->prepare("SELECT * FROM iacuc WHERE iacuc_id = ?");
    $stmt->bind_param("s", $ethicsNumber);
    $stmt->execute();
    $auth = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $auth ?: null;
}

// Validity on $date (today by default): none, undated, not_yet, valid,
// expiring (valid but ending soon) or expired.
function authorization_status(?array $auth, ?string $date = null): string
{
    if (!$auth) {
        return 'none';
    }
    if (empty($auth['valid_from']) || empty($auth['valid_until'])) {
        return 'undated';
    }
    $date = $date ?? date('Y-m-d');
    if ($date < $auth['valid_from']) {
        return 'not_yet';
    }
    if ($date > $auth['valid_until']) {
        return 'expired';
    }
    $warnFrom = date('Y-m-d', strtotime($auth['valid_until'] . ' -' . ETHICS_EXPIRY_WARNING_DAYS . ' days'));
    return $date >= $warnFrom ? 'expiring' : 'valid';
}

// Bootstrap badge describing an authorization's validity today.
function authorization_badge(?array $auth): string
{
    [$color, $label] = [
        'none' => ['secondary', 'No authorization'],
        'undated' => ['warning text-dark', 'Validity dates missing'],
        'not_yet' => ['info text-dark', 'Not yet valid'],
        'valid' => ['success', 'Valid'],
        'expiring' => ['warning text-dark', 'Expires soon'],
        'expired' => ['danger', 'Expired'],
    ][authorization_status($auth)];
    return '<span class="badge bg-' . $color . '">' . trh($label) . '</span>';
}

// "from → to" of an authorization's validity, or a dash.
function authorization_period(?array $auth): string
{
    if (!$auth || (empty($auth['valid_from']) && empty($auth['valid_until']))) {
        return '—';
    }
    return ($auth['valid_from'] ?: '?') . ' → ' . ($auth['valid_until'] ?: '?');
}

// Check a trial period against its authorization. Returns the error message,
// or null when the period is allowed.
function trial_period_error(?array $auth, ?string $start, ?string $end): ?string
{
    if ($start && $end && $start > $end) {
        return tr('The trial cannot end before it starts.');
    }
    if (!$auth) {
        return null;
    }
    if (empty($auth['valid_from']) || empty($auth['valid_until'])) {
        return tr('Authorization {number} has no validity dates: complete them before linking a trial.', ['number' => $auth['iacuc_id']]);
    }
    if (!$start || !$end) {
        return tr('A trial under an authorization needs a start and an end date.');
    }
    if ($start < $auth['valid_from'] || $end > $auth['valid_until']) {
        return tr('The trial ({start} → {end}) must lie within the validity of authorization {number} ({from} → {until}).', [
            'start' => $start, 'end' => $end, 'number' => $auth['iacuc_id'],
            'from' => $auth['valid_from'], 'until' => $auth['valid_until'],
        ]);
    }
    return null;
}

// Check the period of a protocol or experiment against the period of its
// parent (trial or protocol). Returns the error message, or null.
function protocol_period_error(array $parent, ?string $start, ?string $end): ?string
{
    if ($start && $end && $start > $end) {
        return tr('The end date cannot be before the start date.');
    }
    $from = $parent['start_date'] ?? null;
    $until = $parent['end_date'] ?? null;
    if (($start && $from && $start < $from) || ($end && $until && $end > $until) || ($start && $until && $start > $until)) {
        return tr('The dates must lie within the period of {parent} ({from} → {until}).', [
            'parent' => $parent['protocol_number'] ?? $parent['trial_number'] ?? '',
            'from' => $from ?: '?', 'until' => $until ?: '?',
        ]);
    }
    return null;
}

// Why new animals cannot be enrolled under this trial today, or null.
function trial_enrollment_block(?array $trial, ?array $auth, ?string $date = null): ?string
{
    $date = $date ?? date('Y-m-d');
    if ($auth) {
        $status = authorization_status($auth, $date);
        if ($status === 'expired') {
            return tr('Authorization {number} expired on {date}: no animal can be enrolled any more.', ['number' => $auth['iacuc_id'], 'date' => $auth['valid_until']]);
        }
        if ($status === 'not_yet') {
            return tr('Authorization {number} is valid only from {date}.', ['number' => $auth['iacuc_id'], 'date' => $auth['valid_from']]);
        }
    }
    if ($trial && !empty($trial['end_date']) && $date > $trial['end_date']) {
        return tr('Trial {number} ended on {date}.', ['number' => $trial['trial_number'], 'date' => $trial['end_date']]);
    }
    return null;
}

// Store one uploaded PDF in the private folder. Returns the stored file name,
// null when nothing was uploaded, or throws with a readable message.
function store_ethics_pdf(array $file): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception(tr('The file "{name}" could not be uploaded.', ['name' => $file['name']]));
    }
    if ($file['size'] > ETHICS_MAX_PDF_BYTES) {
        throw new Exception(tr('"{name}" exceeds the 20 MB limit.', ['name' => $file['name']]));
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if ($mime !== 'application/pdf' || strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'pdf') {
        throw new Exception(tr('"{name}" is not a PDF file.', ['name' => $file['name']]));
    }
    $dir = ethics_storage_dir();
    if (!is_dir($dir) && !mkdir($dir, 0750, true)) {
        throw new Exception(tr('Failed to create directory.'));
    }
    if (!is_file($dir . '/.htaccess')) {
        file_put_contents($dir . '/.htaccess', "Require all denied\n");
    }
    $stored = bin2hex(random_bytes(16)) . '.pdf';
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $stored)) {
        throw new Exception(tr('Error uploading file.'));
    }
    return $stored;
}

// Link to download one document of an authorization.
function ethics_document_url(string $ethicsNumber, string $doc): string
{
    return 'ethics_file.php?' . http_build_query(['id' => $ethicsNumber, 'doc' => $doc]);
}

// ----- Animals authorized per species -----

// Percentages at which the administrators and BEA responsibles are alerted.
const QUOTA_ALERT_LEVELS = [80, 100];

// Species of the animals (by their line), normalised; '' when unknown.
function animal_species(mysqli $con, array $animalIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $animalIds))));
    if (!$ids) {
        return [];
    }
    $out = array_fill_keys($ids, '');
    foreach (db_rows($con, 'SELECT m.id, l.species FROM mice m LEFT JOIN animal_lines l ON l.id = m.line_id WHERE m.id IN (' . db_placeholders(count($ids)) . ')', str_repeat('i', count($ids)), $ids) as $r) {
        $out[(int) $r['id']] = mb_strtolower(trim((string) $r['species']));
    }
    return $out;
}

/**
 * Quotas of an authorization with their use: [species key => ['species',
 * 'max', 'used', 'pct', 'alert_level', 'id']]. An animal is used once it is
 * enrolled in any experiment of a trial under the authorization.
 */
function authorization_quotas(mysqli $con, ?string $ethicsNumber): array
{
    if ($ethicsNumber === null || $ethicsNumber === '') {
        return [];
    }
    $stmt = $con->prepare('SELECT * FROM iacuc_animal_quotas WHERE iacuc_id = ? ORDER BY species');
    $stmt->bind_param('s', $ethicsNumber);
    $stmt->execute();
    $quotas = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $q) {
        $quotas[mb_strtolower(trim($q['species']))] = ['id' => (int) $q['id'], 'species' => $q['species'], 'max' => (int) $q['max_animals'], 'used' => 0, 'alert_level' => (int) $q['alert_level']];
    }
    $stmt->close();
    if (!$quotas) {
        return [];
    }
    $stmt = $con->prepare(
        "SELECT LOWER(TRIM(COALESCE(l.species, ''))) AS species, COUNT(DISTINCT ea.animal_id) AS n
         FROM experiment_animals ea
         JOIN experiments e ON e.id = ea.experiment_id
         JOIN protocols p ON p.id = e.protocol_id
         JOIN trials t ON t.id = p.trial_id
         JOIN mice m ON m.id = ea.animal_id
         LEFT JOIN animal_lines l ON l.id = m.line_id
         WHERE t.iacuc_id = ? GROUP BY LOWER(TRIM(COALESCE(l.species, '')))"
    );
    $stmt->bind_param('s', $ethicsNumber);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        if (isset($quotas[$r['species']])) {
            $quotas[$r['species']]['used'] = (int) $r['n'];
        }
    }
    $stmt->close();
    foreach ($quotas as &$q) {
        $q['pct'] = $q['max'] > 0 ? (int) floor($q['used'] * 100 / $q['max']) : 100;
    }
    unset($q);
    return $quotas;
}

/**
 * Error message when enrolling these animals under the authorization would
 * exceed its quotas, or null. Animals already used by the authorization do
 * not count again. Without any quota recorded, nothing is checked.
 */
function quota_enrollment_error(mysqli $con, ?string $ethicsNumber, array $animalIds): ?string
{
    $quotas = authorization_quotas($con, $ethicsNumber);
    if (!$quotas) {
        return null;
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', $animalIds))));
    if (!$ids) {
        return null;
    }
    // Animals already used by the authorization.
    $stmt = $con->prepare(
        'SELECT DISTINCT ea.animal_id FROM experiment_animals ea JOIN experiments e ON e.id = ea.experiment_id
         JOIN protocols p ON p.id = e.protocol_id JOIN trials t ON t.id = p.trial_id
         WHERE t.iacuc_id = ? AND ea.animal_id IN (' . implode(',', $ids) . ')'
    );
    $stmt->bind_param('s', $ethicsNumber);
    $stmt->execute();
    $already = array_map('intval', array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'animal_id'));
    $stmt->close();
    $new = array_diff($ids, $already);
    $bySpecies = [];
    foreach (animal_species($con, $new) as $animalId => $species) {
        $bySpecies[$species][] = $animalId;
    }
    $errors = [];
    foreach ($bySpecies as $species => $animals) {
        if ($species === '') {
            $errors[] = tr('{n} animal(s) without line (species unknown): give them a line first.', ['n' => count($animals)]);
        } elseif (!isset($quotas[$species])) {
            $errors[] = tr('species "{species}" is not authorized by this project ({n} animal(s)).', ['species' => $species, 'n' => count($animals)]);
        } elseif ($quotas[$species]['used'] + count($animals) > $quotas[$species]['max']) {
            $q = $quotas[$species];
            $errors[] = tr('{species}: {used} of {max} authorized animals already used, {n} more requested.', ['species' => $q['species'], 'used' => $q['used'], 'max' => $q['max'], 'n' => count($animals)]);
        }
    }
    return $errors ? tr('Authorization {number}: {errors}', ['number' => $ethicsNumber, 'errors' => implode(' ', $errors)]) : null;
}

// Alert the administrators and BEA responsibles once when a quota reaches 80 % and 100 %.
function quota_send_alerts(mysqli $con, ?string $ethicsNumber): void
{
    foreach (authorization_quotas($con, $ethicsNumber) as $q) {
        $level = 0;
        foreach (QUOTA_ALERT_LEVELS as $l) {
            if ($q['pct'] >= $l) {
                $level = $l;
            }
        }
        if ($level <= $q['alert_level']) {
            if ($level < $q['alert_level']) {
                // Below again (e.g. quota raised): alert again next time.
                db_execute($con, 'UPDATE iacuc_animal_quotas SET alert_level = ? WHERE id = ?', 'ii', [(int) $level, (int) $q['id']]);
            }
            continue;
        }
        db_execute($con, 'UPDATE iacuc_animal_quotas SET alert_level = ? WHERE id = ?', 'ii', [(int) $level, (int) $q['id']]);
        $title = $level >= 100
            ? tr('Authorization {number}: quota of {species} reached', ['number' => $ethicsNumber, 'species' => $q['species']])
            : tr('Authorization {number}: {pct} % of the {species} used', ['number' => $ethicsNumber, 'species' => $q['species'], 'pct' => $q['pct']]);
        $message = tr('{used} of {max} authorized animals used.', ['used' => $q['used'], 'max' => $q['max']]);
        foreach ($con->query("SELECT id FROM users WHERE status = 'approved' AND (role = 'admin' OR is_bea_responsible = 1)") as $u) {
            create_notification($con, (int) $u['id'], $title, $message, 'manage_iacuc.php', 'system');
        }
    }
}

// Compact display of the use of the quotas (badges).
function quota_badges(array $quotas): string
{
    $html = [];
    foreach ($quotas as $q) {
        $cls = $q['pct'] >= 100 ? 'danger' : ($q['pct'] >= 80 ? 'warning text-dark' : 'success');
        $html[] = '<span class="badge bg-' . $cls . '" title="' . trh('Animals used / authorized') . '">' . htmlspecialchars($q['species']) . ' : ' . $q['used'] . ' / ' . $q['max'] . '</span>';
    }
    return implode(' ', $html);
}
