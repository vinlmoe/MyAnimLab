<?php

/**
 * Corrections of the daily experimental follow-ups.
 *
 * A day is entered once per animal; saving that day again is a correction:
 * it requires a reason, and the values it replaces are kept in
 * experiment_followup_revisions (append-only), so that what was recorded
 * first, by whom, and why it was changed can always be shown.
 */

/** Values of a follow-up as they are now: entry, grid scores, observations. */
function followup_snapshot(mysqli $con, int $followupId): array
{
    $stmt = $con->prepare(
        "SELECT f.observed_at, f.weight_g, f.clinical_score, f.observations, f.endpoint_reached, f.recorded_by, f.created_at,
                u.name AS recorded_by_name,
                -- the values were entered at the last correction, or at creation
                COALESCE((SELECT MAX(r.revised_at) FROM experiment_followup_revisions r WHERE r.followup_id = f.id), f.created_at) AS entered_at
         FROM experiment_followups f LEFT JOIN users u ON u.id = f.recorded_by WHERE f.id = ?"
    );
    $stmt->bind_param("i", $followupId);
    $stmt->execute();
    $snapshot = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    $stmt = $con->prepare("SELECT item_label, score, level_description FROM experiment_followup_scores WHERE followup_id = ? ORDER BY id");
    $stmt->bind_param("i", $followupId);
    $stmt->execute();
    $snapshot['scores'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $stmt = $con->prepare("SELECT label, value_type, unit, value_number, value_text FROM experiment_followup_observations WHERE followup_id = ? ORDER BY id");
    $stmt->bind_param("i", $followupId);
    $stmt->execute();
    $snapshot['observations_list'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $snapshot;
}

/** Keep the values replaced by a correction, with its reason. */
function record_followup_revision(mysqli $con, int $followupId, array $previous, string $reason, ?int $userId, ?string $userName): void
{
    $json = json_encode($previous, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $stmt = $con->prepare("INSERT INTO experiment_followup_revisions (followup_id, previous_values, reason, revised_by, revised_by_name) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("issis", $followupId, $json, $reason, $userId, $userName);
    $stmt->execute();
    $stmt->close();
}

/** Corrections of a follow-up, oldest first, with their decoded previous values. */
function followup_revisions(mysqli $con, int $followupId): array
{
    $stmt = $con->prepare("SELECT * FROM experiment_followup_revisions WHERE followup_id = ? ORDER BY revised_at, id");
    $stmt->bind_param("i", $followupId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    foreach ($rows as &$row) {
        $row['previous'] = json_decode((string) $row['previous_values'], true) ?: [];
    }
    return $rows;
}

/** Number of corrections of each follow-up of a list, by follow-up id. */
function followup_revision_counts(mysqli $con, array $followupIds): array
{
    $ids = array_values(array_unique(array_map('intval', $followupIds)));
    if (!$ids) {
        return [];
    }
    $stmt = $con->prepare("SELECT followup_id, COUNT(*) AS n FROM experiment_followup_revisions WHERE followup_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ") GROUP BY followup_id");
    $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
    $stmt->execute();
    $counts = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'n', 'followup_id');
    $stmt->close();
    return array_map('intval', $counts);
}

/** One line summing up the values a correction replaced. */
function followup_revision_summary(array $previous): string
{
    $number = fn($n): string => rtrim(rtrim(number_format((float) $n, 4, '.', ''), '0'), '.');
    $parts = [];
    if (($previous['weight_g'] ?? null) !== null) {
        $parts[] = tr('weight {value} g', ['value' => $number($previous['weight_g'])]);
    }
    if (($previous['clinical_score'] ?? null) !== null) {
        $parts[] = tr('clinical score {value}', ['value' => (int) $previous['clinical_score']]);
    }
    foreach ($previous['scores'] ?? [] as $s) {
        $parts[] = $s['item_label'] . ' ' . (int) $s['score'];
    }
    foreach ($previous['observations_list'] ?? [] as $o) {
        $value = $o['value_type'] === 'number' ? $number($o['value_number']) . ($o['unit'] ? ' ' . $o['unit'] : '') : (string) $o['value_text'];
        $parts[] = $o['label'] . ' : ' . $value;
    }
    if (($previous['observations'] ?? '') !== '') {
        $parts[] = tr('comment: {text}', ['text' => $previous['observations']]);
    }
    if (!empty($previous['endpoint_reached'])) {
        $parts[] = tr('endpoint reached');
    }
    $by = ($previous['recorded_by_name'] ?? '') !== '' ? $previous['recorded_by_name'] : '—';
    return ($parts ? implode(' · ', $parts) : tr('no value')) . ' (' . tr('entered by {user} on {at}', ['user' => $by, 'at' => substr((string) ($previous['entered_at'] ?? $previous['created_at'] ?? ''), 0, 16)]) . ')';
}
