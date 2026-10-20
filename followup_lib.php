<?php

/**
 * Corrections of the daily experimental follow-ups.
 *
 * A day is entered once per animal; saving that day again is a correction:
 * it requires a reason, and the values it replaces are kept in
 * experiment_followup_revisions (append-only), so that what was recorded
 * first, by whom, and why it was changed can always be shown.
 *
 * Endpoint actions (points limites) raised by a follow-up are withdrawn when
 * a correction of that follow-up no longer reaches their endpoint. An action
 * not carried out at once is commented (endpoint_action.php): why, and what
 * is planned.
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

/**
 * Withdraw the pending actions a follow-up had raised whose endpoint the
 * corrected values no longer reach ($breachedThresholdIds: the endpoints
 * still reached). An action is kept while a later follow-up of the same
 * participation still reaches an endpoint: the animal may still need it.
 * Returns the withdrawn and the kept actions.
 */
function withdraw_endpoint_actions_no_longer_reached(mysqli $con, int $followupId, array $breachedThresholdIds, string $reason, ?int $userId, ?string $userName): array
{
    $actions = db_rows(
        $con,
        "SELECT a.*, f.observed_at,
                EXISTS (SELECT 1 FROM experiment_followups later
                        WHERE later.experiment_animal_id = f.experiment_animal_id AND later.observed_at > f.observed_at AND later.endpoint_reached = 1) AS reached_later
         FROM endpoint_actions a JOIN experiment_followups f ON f.id = a.followup_id
         WHERE a.followup_id = ? AND a.status = 'pending' AND a.threshold_id IS NOT NULL",
        'i',
        [$followupId]
    );
    $breached = array_map('intval', $breachedThresholdIds);
    $result = ['withdrawn' => [], 'kept' => []];
    foreach ($actions as $a) {
        if (in_array((int) $a['threshold_id'], $breached, true)) {
            continue;
        }
        if ($a['reached_later']) {
            $result['kept'][] = $a;
            continue;
        }
        $note = tr('Endpoint no longer reached after the correction of the follow-up of {date}: {reason}', ['date' => $a['observed_at'], 'reason' => $reason]);
        if (db_execute($con, "UPDATE endpoint_actions SET status = 'withdrawn', done_at = NOW(), done_by = ?, done_by_name = ?, done_note = ? WHERE id = ? AND status = 'pending'", 'issi', [$userId, $userName, $note, (int) $a['id']]) === 1) {
            $result['withdrawn'][] = $a;
        }
    }
    return $result;
}

/** Record a pending action other than euthanasia as done, with what was done. */
function complete_endpoint_action(mysqli $con, int $actionId, int $experimentAnimalId, string $note, ?int $userId, ?string $userName): bool
{
    return db_execute(
        $con,
        "UPDATE endpoint_actions SET status = 'done', done_at = NOW(), done_by = ?, done_by_name = ?, done_note = ?
         WHERE id = ? AND experiment_animal_id = ? AND status = 'pending' AND required_action <> 'euthanasia'",
        'issii',
        [$userId, $userName, $note, $actionId, $experimentAnimalId]
    ) === 1;
}

/** Comment a pending action not carried out at once (why, what is planned). */
function comment_endpoint_action(mysqli $con, int $actionId, int $experimentAnimalId, string $comment, ?int $userId, ?string $userName): bool
{
    if (!db_value($con, "SELECT 1 FROM endpoint_actions WHERE id = ? AND experiment_animal_id = ? AND status = 'pending'", 'ii', [$actionId, $experimentAnimalId])) {
        return false;
    }
    db_execute($con, "INSERT INTO endpoint_action_comments (action_id, comment, created_by, created_by_name) VALUES (?, ?, ?, ?)", 'isis', [$actionId, $comment, $userId, $userName]);
    return true;
}

/** Comments of a list of endpoint actions, oldest first, by action id. */
function endpoint_action_comments(mysqli $con, array $actionIds): array
{
    $ids = array_values(array_unique(array_map('intval', $actionIds)));
    if (!$ids) {
        return [];
    }
    $byAction = [];
    foreach (db_rows($con, "SELECT * FROM endpoint_action_comments WHERE action_id IN (" . db_placeholders(count($ids)) . ") ORDER BY created_at, id", str_repeat('i', count($ids)), $ids) as $c) {
        $byAction[(int) $c['action_id']][] = $c;
    }
    return $byAction;
}
