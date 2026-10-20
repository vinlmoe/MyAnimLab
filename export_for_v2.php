<?php
/**
 * V1 Admin Export → V2
 *
 * Admin-only. Streams a JSON dump of every table V2's admin importer
 * needs. Read-only against V1. Secrets are never exported (password hashes,
 * reset and e-mail tokens, two-factor secrets, e-mail bodies that may carry
 * a reset link): users set a new password on V2. Each export is logged.
 */

require_once 'session_config.php';
require 'dbcon.php';
require_once 'animal_lib.php';

if (!isset($_SESSION['username'])) {
    $currentUrl = urlencode($_SERVER['REQUEST_URI']);
    header("Location: index.php?redirect=$currentUrl");
    exit;
}
if (($_SESSION['role'] ?? '') !== 'admin') {
    $_SESSION['message'] = tr('Admin only.');
    header('Location: home.php');
    exit;
}

$tables = [
    'users', 'iacuc', 'strains', 'settings',
    'cages', 'cage_users', 'cage_iacuc',
    'holding', 'mice', 'breeding', 'litters',
    'files', 'notes', 'tasks', 'maintenance',
    'reminders', 'notifications', 'outbox',
    'activity_log',
];

// Columns left out of the export, by table.
const EXPORT_EXCLUDED_COLUMNS = [
    'users' => ['password', 'reset_token', 'reset_token_expiration', 'email_token', 'totp_secret', 'totp_last_step', 'totp_recovery_codes'],
    'outbox' => ['body'],
];

$payload = [
    'exported_at' => gmdate('c'),
    'source_db'   => $_ENV['DB_DATABASE'] ?? null,
    'excluded_columns' => EXPORT_EXCLUDED_COLUMNS,
    'tables'      => [],
];

foreach ($tables as $t) {
    $check = $con->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
    $check->bind_param("s", $t);
    $check->execute();
    if ($check->get_result()->num_rows === 0) {
        $check->close();
        $payload['tables'][$t] = [];
        continue;
    }
    $check->close();

    // $t comes from the constant list above (identifiers cannot be bound).
    $rows = [];
    $res = $con->query("SELECT * FROM `$t`");
    $excluded = array_flip(EXPORT_EXCLUDED_COLUMNS[$t] ?? []);
    while ($row = $res->fetch_assoc()) $rows[] = array_diff_key($row, $excluded);
    $res->close();
    $payload['tables'][$t] = $rows;
}

$json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
if ($json === false) {
    http_response_code(500);
    echo htmlspecialchars(tr('JSON encode failed: {error}', ['error' => json_last_error_msg()]));
    exit;
}

$filename = 'v1_export_' . date('Ymd_His') . '.json';

log_activity($con, 'export', 'v1_data', null, tr('Exported V1 data for V2 migration ({file})', ['file' => $filename]), (int) $_SESSION['user_id']);

header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($json));
echo $json;
