<?php

/**
 * End-of-day daily round alert.
 *
 * Meant to run from cron every few minutes, like process_reminders.php. Once
 * per day, after the alert time set in Manage Lab (round_alert_time, default
 * 18:00, lab timezone), it lists the cages in service today that nobody has
 * seen yet and alerts every admin: in-app notification + email (queued in
 * the outbox, sent by send_email.php). Nothing is sent when every cage was
 * seen. Use --force to run it now regardless of time or a previous run.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script must be run from the command line (cron).');
}

require __DIR__ . '/dbcon.php';
require __DIR__ . '/animal_lib.php';

$force = in_array('--force', $argv, true);

function setting(mysqli $con, string $name, string $default = ''): string
{
    $stmt = $con->prepare("SELECT value FROM settings WHERE name = ?");
    $stmt->bind_param("s", $name);
    $stmt->execute();
    $value = $stmt->get_result()->fetch_assoc()['value'] ?? '';
    $stmt->close();
    return $value !== '' ? $value : $default;
}

$today = date('Y-m-d');
$alertTime = setting($con, 'round_alert_time', '18:00');
if (!preg_match('/^\d{2}:\d{2}$/', $alertTime)) {
    $alertTime = '18:00';
}

if (!$force) {
    if (date('H:i') < $alertTime) {
        exit(0); // not the end of the day yet
    }
    if (setting($con, 'round_alert_last_date') === $today) {
        exit(0); // already handled today
    }
}

$unseen = unseen_cages_by_room($con, $today);
$total = array_sum(array_map('count', $unseen));
$pendingActions = (int) $con->query("SELECT COUNT(*) FROM endpoint_actions WHERE status = 'pending'")->fetch_row()[0];
$overdueWeanings = (int) $con->query("SELECT COUNT(*) FROM litters WHERE status = 'nursing' AND expected_weaning_date < CURDATE()")->fetch_row()[0];

// Remember the run first so a mail failure never triggers repeated alerts.
$mark = $con->prepare("INSERT INTO settings (name, value) VALUES ('round_alert_last_date', ?) ON DUPLICATE KEY UPDATE value = VALUES(value)");
$mark->bind_param("s", $today);
$mark->execute();
$mark->close();

if ($total === 0 && $pendingActions === 0 && $overdueWeanings === 0) {
    echo "Daily round complete for $today: no alert.\n";
    exit(0);
}

$lines = [];
foreach ($unseen as $room => $cageIds) {
    $lines[] = ($room === '' ? 'No room' : "Room $room") . ': ' . implode(', ', $cageIds);
}
if ($pendingActions) {
    $lines[] = "$pendingActions endpoint action(s) still pending (euthanasia, vet...)";
}
if ($overdueWeanings) {
    $lines[] = "$overdueWeanings litter(s) past their expected weaning date";
}
$title = $total ? "Daily round incomplete: $total cage(s) not seen today" : "End of day: " . implode(', ', array_slice($lines, 0));
$summary = implode(' | ', $lines);

notify_admins($con, $title, $summary, 'daily_round.php?hide_seen=1', 'system');

$siteUrl = setting($con, 'url');
$link = $siteUrl !== '' ? "https://$siteUrl/daily_round.php?hide_seen=1" : 'daily_round.php?hide_seen=1';
$body = "<p>End of day $today: <strong>$total cage(s)</strong> in service not seen, <strong>$pendingActions endpoint action(s)</strong> pending.</p><ul>";
foreach ($lines as $line) {
    $body .= '<li>' . htmlspecialchars($line) . '</li>';
}
$body .= '</ul><p><a href="' . htmlspecialchars($link) . '">Open the cages still to check</a></p>';

$admins = $con->query("SELECT username FROM users WHERE role = 'admin' AND status = 'approved'")->fetch_all(MYSQLI_ASSOC);
$mail = $con->prepare("INSERT INTO outbox (recipient, subject, body) VALUES (?, ?, ?)");
foreach ($admins as $admin) {
    $mail->bind_param("sss", $admin['username'], $title, $body);
    $mail->execute();
}
$mail->close();

log_activity($con, 'round_alert', 'daily_round', $today, $summary);
echo "$title — alerted " . count($admins) . " admin(s).\n";
