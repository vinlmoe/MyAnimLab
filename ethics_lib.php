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
