<?php

/**
 * Corrections of regulatory records made after the fact: the entry of an
 * animal into the facility and its exit (entry / exit register).
 *
 * A correction is made by an administrator with a reason; the values it
 * replaces are kept, next to the new ones, in record_corrections
 * (append-only), shown on the animal's record and in its BEA file.
 * Requires animal_lib.php.
 */

// Labels of the corrected values, by record.
function correction_fields(): array
{
    return [
        'entry' => [
            'origin_type' => tr('Origin'),
            'origin_detail' => tr('Supplier / establishment'),
            'origin_reference' => tr('Reference'),
            'entry_date' => tr('Entry date'),
        ],
        'exit' => [
            'exit_code' => tr('Exit code'),
            'exit_date' => tr('Exit date'),
            'exit_reason' => tr('Reason / circumstances'),
            'exit_destination' => tr('Destination facility'),
        ],
    ];
}

function correction_records(): array
{
    return ['entry' => tr('Entry into the facility'), 'exit' => tr('Exit from the facility')];
}

/** Keep a correction: the values replaced, the new ones and the reason. */
function record_correction(mysqli $con, string $entityType, int $entityId, string $record, array $previous, array $new, string $reason, ?int $userId, ?string $userName): void
{
    $prev = json_encode($previous, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $next = json_encode($new, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    db_execute(
        $con,
        "INSERT INTO record_corrections (entity_type, entity_id, record, previous_values, new_values, reason, corrected_by, corrected_by_name) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
        'sissssis',
        [$entityType, $entityId, $record, $prev, $next, $reason, $userId, $userName]
    );
}

/** Corrections of a record, oldest first, with decoded values. */
function record_corrections(mysqli $con, string $entityType, int $entityId): array
{
    $rows = db_rows($con, "SELECT * FROM record_corrections WHERE entity_type = ? AND entity_id = ? ORDER BY corrected_at, id", 'si', [$entityType, $entityId]);
    foreach ($rows as &$row) {
        $row['previous'] = json_decode((string) $row['previous_values'], true) ?: [];
        $row['new'] = json_decode((string) $row['new_values'], true) ?: [];
    }
    return $rows;
}

/** Number of corrections of each animal of a list, by animal id. */
function animal_correction_counts(mysqli $con, array $animalIds): array
{
    $ids = array_values(array_unique(array_map('intval', $animalIds)));
    if (!$ids) {
        return [];
    }
    $rows = db_rows($con, "SELECT entity_id, COUNT(*) AS n FROM record_corrections WHERE entity_type = 'animal' AND entity_id IN (" . db_placeholders(count($ids)) . ") GROUP BY entity_id", str_repeat('i', count($ids)), $ids);
    return array_map('intval', array_column($rows, 'n', 'entity_id'));
}

/** "Field: before → after" for each value a correction changed. */
function correction_changes_text(array $correction): string
{
    $labels = correction_fields()[$correction['record']] ?? [];
    $show = function (string $field, $value): string {
        if ($value === null || $value === '') {
            return '—';
        }
        return match ($field) {
            'origin_type' => animal_origins()[$value] ?? (string) $value,
            'exit_code' => ANIMAL_EXIT_CODES[$value][0] ?? (string) $value,
            default => (string) $value,
        };
    };
    $parts = [];
    foreach ($labels as $field => $label) {
        $before = $correction['previous'][$field] ?? null;
        $after = $correction['new'][$field] ?? null;
        if ((string) $before !== (string) $after) {
            $parts[] = $label . ' : ' . $show($field, $before) . ' → ' . $show($field, $after);
        }
    }
    return implode(' · ', $parts);
}

/** The values of $row for the fields of a record. */
function correction_values(string $record, array $row): array
{
    $values = [];
    foreach (array_keys(correction_fields()[$record]) as $field) {
        $values[$field] = isset($row[$field]) && $row[$field] !== '' ? (string) $row[$field] : null;
    }
    return $values;
}

/**
 * Correct the recorded entry of an animal (origin, supplier, reference,
 * date). $animal is its row; $origin comes from origin_from_input().
 * Throws when the reason is missing or nothing changes.
 */
function correct_animal_entry(mysqli $con, array $animal, array $origin, string $reason, ?int $userId, ?string $userName): void
{
    if (trim($reason) === '') {
        throw new Exception(tr('Give the reason for the correction.'));
    }
    $previous = correction_values('entry', $animal);
    $new = correction_values('entry', $origin);
    if ($previous === $new) {
        throw new Exception(tr('Nothing was changed.'));
    }
    set_animal_origin($con, (int) $animal['id'], $origin, $userId);
    record_correction($con, 'animal', (int) $animal['id'], 'entry', $previous, $new, trim($reason), $userId, $userName);
    log_activity($con, 'register_corrected', 'animal', (string) $animal['id'], tr('Entry corrected: {reason}', ['reason' => trim($reason)]), $userId);
}

/**
 * Correct the recorded exit of an animal that has left the facility: exit
 * code (and so its status), date, circumstances, destination. The
 * enrollments closed by the exit follow its date and code.
 * Throws when the reason is missing, a value is invalid or nothing changes.
 */
function correct_animal_exit(mysqli $con, int $animalId, string $code, string $date, string $exitReason, ?string $destination, string $reason, ?int $userId, ?string $userName): void
{
    if (trim($reason) === '') {
        throw new Exception(tr('Give the reason for the correction.'));
    }
    if (!isset(ANIMAL_EXIT_CODES[$code])) {
        throw new Exception(tr('Choose an exit code.'));
    }
    $destination = $code === 'export' ? trim((string) $destination) : '';
    if ($code === 'export' && $destination === '') {
        throw new Exception(tr('An export needs the destination facility.'));
    }
    if (trim($exitReason) === '') {
        throw new Exception(tr('Give the circumstances of the exit.'));
    }
    $animal = db_row($con, "SELECT * FROM mice WHERE id = ? FOR UPDATE", 'i', [$animalId]);
    if (!$animal) {
        throw new Exception(tr('Animal not found.'));
    }
    if ($animal['status'] === 'active') {
        throw new Exception(tr('This animal is still in the facility: record its exit instead.'));
    }
    $parsed = DateTime::createFromFormat('Y-m-d', $date);
    if (!$parsed || $parsed->format('Y-m-d') !== $date || $date > date('Y-m-d')) {
        throw new Exception(tr('The exit date is not valid.'));
    }
    foreach (['dob' => tr('The exit date cannot be before the date of birth.'), 'entry_date' => tr('The exit date cannot be before the entry date.')] as $field => $message) {
        if (!empty($animal[$field]) && $date < $animal[$field]) {
            throw new Exception($message);
        }
    }

    $previous = correction_values('exit', $animal);
    $new = correction_values('exit', ['exit_code' => $code, 'exit_date' => $date, 'exit_reason' => trim($exitReason), 'exit_destination' => $destination]);
    if ($previous === $new) {
        throw new Exception(tr('Nothing was changed.'));
    }
    [$label, $status] = ANIMAL_EXIT_CODES[$code];
    $destinationValue = $destination !== '' ? $destination : null;
    $trimmedReason = trim($exitReason);
    db_execute(
        $con,
        "UPDATE mice SET status = ?, exit_code = ?, exit_date = ?, exit_reason = ?, exit_destination = ? WHERE id = ?",
        'sssssi',
        [$status, $code, $date, $trimmedReason, $destinationValue, $animalId]
    );
    // Enrollments the exit closed (on its date, with its label) follow it.
    if ($previous['exit_date'] !== null) {
        $oldLabel = ANIMAL_EXIT_CODES[$previous['exit_code']][0] ?? null;
        db_execute(
            $con,
            "UPDATE experiment_animals SET removed_at = ?, removal_reason = ? WHERE animal_id = ? AND removed_at = ? AND removal_reason <=> ?",
            'ssiss',
            ["$date 00:00:00", $label, $animalId, $previous['exit_date'] . ' 00:00:00', $oldLabel]
        );
    }
    record_correction($con, 'animal', $animalId, 'exit', $previous, $new, trim($reason), $userId, $userName);
    log_animal_event($con, $animalId, 'status_change', ['exit_corrected' => $new, 'previous' => $previous, 'reason' => trim($reason)], $userId);
    log_activity($con, 'register_corrected', 'animal', (string) $animalId, tr('Exit corrected: {reason}', ['reason' => trim($reason)]), $userId);
}
