<?php

/**
 * Animal record (fiche animal).
 *
 * Displays an animal's identity, current cage, full cage movement history,
 * parentage, and experimentation history. Stays accessible after the animal
 * has died or left the facility (status becomes sacrificed/transferred_out/archived,
 * the row itself is never deleted).
 */

require_once 'session_config.php';
require 'dbcon.php';
require 'animal_lib.php';
require_once 'corrections_lib.php';

if (!isset($_SESSION['username'])) {
    $currentUrl = urlencode($_SERVER['REQUEST_URI']);
    header("Location: index.php?redirect=$currentUrl");
    exit;
}

require 'header.php';

$animalId = (int) ($_GET['id'] ?? 0);

$stmt = $con->prepare(
    "SELECT m.*, al.code AS line_code, al.name AS line_name, al.species
     FROM mice m
     LEFT JOIN animal_lines al ON al.id = m.line_id
     WHERE m.id = ?"
);
$stmt->bind_param("i", $animalId);
$stmt->execute();
$animal = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$animal) {
    echo "<div class='container mt-4'><div class='alert alert-danger'>" . trh('Animal not found.') . "</div></div>";
    require 'footer.php';
    exit;
}

$movements = $con->prepare(
    "SELECT am.*, u.name AS moved_by_name
     FROM animal_movements am
     LEFT JOIN users u ON u.id = am.moved_by
     WHERE am.animal_id = ? ORDER BY am.moved_at ASC"
);
$movements->bind_param("i", $animalId);
$movements->execute();
$movements = $movements->get_result();

$parentage = $con->prepare(
    "SELECT ap.*, sire.animal_uid AS sire_uid, dam.animal_uid AS dam_uid
     FROM animal_parentage ap
     LEFT JOIN mice sire ON sire.id = ap.sire_id
     LEFT JOIN mice dam ON dam.id = ap.dam_id
     WHERE ap.animal_id = ?"
);
$parentage->bind_param("i", $animalId);
$parentage->execute();
$parentage = $parentage->get_result()->fetch_assoc();

$offspring = $con->prepare(
    "SELECT m.animal_uid, m.id FROM animal_parentage ap
     JOIN mice m ON m.id = ap.animal_id
     WHERE ap.sire_id = ? OR ap.dam_id = ?"
);
$offspring->bind_param("ii", $animalId, $animalId);
$offspring->execute();
$offspring = $offspring->get_result();

$experiments = $con->prepare(
    "SELECT ea.*, e.experiment_number, e.title AS experiment_title, p.id AS protocol_id, p.protocol_number, t.trial_number
     FROM experiment_animals ea
     JOIN experiments e ON e.id = ea.experiment_id
     JOIN protocols p ON p.id = e.protocol_id
     JOIN trials t ON t.id = p.trial_id
     WHERE ea.animal_id = ?
     ORDER BY ea.enrolled_at DESC"
);
$experiments->bind_param("i", $animalId);
$experiments->execute();
$experiments = $experiments->get_result();

$healthReports = $con->prepare(HEALTH_REPORTS_WITH_STATE_SQL . " WHERE r.animal_id = ? ORDER BY r.id DESC");
$healthReports->bind_param("i", $animalId);
$healthReports->execute();
$healthReports = $healthReports->get_result()->fetch_all(MYSQLI_ASSOC);
$openHealthIssues = count(array_filter($healthReports, fn($r) => $r['current_status'] !== 'resolved'));

$events = $con->prepare("SELECT * FROM animal_events WHERE animal_id = ? ORDER BY created_at DESC LIMIT 50");
$events->bind_param("i", $animalId);
$events->execute();
$events = $events->get_result();

$statusBadge = [
    'active' => 'success',
    'sacrificed' => 'dark',
    'dead' => 'dark',
    'transferred_out' => 'secondary',
    'archived' => 'secondary',
][$animal['status']] ?? 'secondary';
?>

<!doctype html>
<html lang="<?= htmlspecialchars(current_locale()) ?>">

<head>
    <title><?= trh('Animal {uid}', ['uid' => $animal['animal_uid'] ?? $animal['mouse_id'] ?? ('#' . $animal['id'])]); ?> | <?php echo htmlspecialchars($labName); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .container {
            max-width: 900px;
        }

        .card {
            margin-bottom: 20px;
        }
    </style>
</head>

<body>
    <div class="container mt-4 content">
        <h4>
            <?= trh('Animal {uid}', ['uid' => $animal['animal_uid'] ?? $animal['mouse_id'] ?? ('#' . $animal['id'])]); ?>
            <span class="badge bg-<?= $statusBadge; ?>"><?= trh(str_replace('_', ' ', $animal['status'])); ?></span>
            <?php if ($openHealthIssues) : ?>
                <a href="#health" class="badge bg-danger text-decoration-none"><?= trh('{n} open health issue(s)', ['n' => $openHealthIssues]); ?></a>
            <?php endif; ?>
            <?php if ($animal['status'] === 'active') : ?>
                <a href="animal_transfer.php?animal_id=<?= (int) $animal['id']; ?>" class="btn btn-sm btn-outline-primary"><?= trh('Transfer') ?></a>
                <a href="health_report.php?animal_id=<?= (int) $animal['id']; ?>" class="btn btn-sm btn-outline-warning"><?= trh('Report Health Issue') ?></a>
                <a href="animal_exit.php?id=<?= (int) $animal['id']; ?>" class="btn btn-sm btn-outline-danger"><?= trh('Exit (sacrifice / death / export)') ?></a>
            <?php endif; ?>
            <?php // Deletion is only offered for an animal created by mistake (no regulatory history). ?>
            <?php if (($_SESSION['role'] ?? '') === 'admin' && !animal_regulatory_history($con, (int) $animal['id'])) : ?>
                <a href="animal_hard_delete.php?id=<?= (int) $animal['id']; ?>" class="btn btn-sm btn-outline-dark"><?= trh('Permanently Delete (admin)') ?></a>
            <?php endif; ?>
        </h4>

        <?php include('message.php'); ?>

        <div class="card">
            <div class="card-body">
                <h5 class="card-title"><?= trh('Identity') ?></h5>
                <p><strong><?= trh('Line:') ?></strong> <?= htmlspecialchars($animal['line_code'] ? "{$animal['line_code']} - {$animal['line_name']} ({$animal['species']})" : tr('N/A')); ?></p>
                <p><strong><?= trh('Sex:') ?></strong> <?= trh($animal['sex'] ?? 'N/A'); ?></p>
                <p><strong><?= trh('Date of birth:') ?></strong> <?= htmlspecialchars($animal['dob'] ?? tr('N/A')); ?></p>
                <p><strong><?= trh('Tag / mouse_id:') ?></strong> <?= htmlspecialchars($animal['mouse_id'] ?? tr('N/A')); ?></p>
                <p><strong><?= trh('Genotype:') ?></strong> <?= htmlspecialchars($animal['genotype'] ?? tr('N/A')); ?></p>
                <form method="POST" action="animal_mark.php" class="row g-2 align-items-center mb-3" id="markForm">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <input type="hidden" name="animal_id" value="<?= (int) $animal['id']; ?>">
                    <div class="col-auto"><label for="distinctive_mark" class="col-form-label fw-bold"><?= trh('Distinctive mark:') ?></label></div>
                    <div class="col">
                        <input type="text" class="form-control" id="distinctive_mark" name="distinctive_mark" maxlength="255" list="markSuggestions"
                            value="<?= htmlspecialchars($animal['distinctive_mark'] ?? ''); ?>" placeholder="<?= trh('e.g. left ear punch, tail mark 2 stripes, chip 250269..., white spot on head') ?>">
                        <datalist id="markSuggestions">
                            <option value="<?= trh('Right ear punch') ?>"><option value="<?= trh('Left ear punch') ?>"><option value="<?= trh('Both ears punched') ?>">
                            <option value="<?= trh('Tail mark: 1 stripe') ?>"><option value="<?= trh('Tail mark: 2 stripes') ?>"><option value="<?= trh('Tattoo: ') ?>">
                            <option value="<?= trh('RFID chip: ') ?>"><option value="<?= trh('Coat colour: ') ?>"><option value="<?= trh('Scar: ') ?>">
                        </datalist>
                    </div>
                    <div class="col-auto"><button type="submit" class="btn btn-outline-primary btn-sm"><?= trh('Save mark') ?></button></div>
                </form>
                <p><strong><?= trh('Current cage:') ?></strong>
                    <?php if ($animal['cage_id']) : ?>
                        <?= htmlspecialchars($animal['cage_id']); ?>
                        <a href="animal_search.php?list=1&amp;status=all&amp;cage_id=<?= rawurlencode($animal['cage_id']); ?>" class="small">(<?= trh('cage mates') ?>)</a>
                    <?php else : ?><?= trh('N/A') ?><?php endif; ?>
                </p>
                <?php if ($animal['status'] !== 'active') : ?>
                    <p><strong><?= trh('Exit date:') ?></strong> <?= htmlspecialchars($animal['exit_date'] ?? tr('N/A')); ?></p>
                    <p><strong><?= trh('Exit code:') ?></strong> <?= htmlspecialchars(exit_label($animal['exit_code'] ?? null, $animal['status'])); ?></p>
                    <p><strong><?= trh('Exit reason:') ?></strong> <?= htmlspecialchars($animal['exit_reason'] ?? tr('N/A')); ?></p>
                    <?php if (!empty($animal['exit_destination'])) : ?>
                        <p><strong><?= trh('Exported to:') ?></strong> <?= htmlspecialchars($animal['exit_destination']); ?></p>
                    <?php endif; ?>
                <?php endif; ?>
                <p><strong><?= trh('Notes:') ?></strong> <?= nl2br(htmlspecialchars($animal['notes'] ?? '')); ?></p>
            </div>
        </div>

        <?php
        $isAdmin = ($_SESSION['role'] ?? '') === 'admin';
        $originText = origin_label($animal);
        $canEditOrigin = !$animal['origin_type'] || $isAdmin;
        $registerCorrections = record_corrections($con, 'animal', (int) $animal['id']);
        ?>
        <?php
        $capture = null;
        if ($animal['origin_type'] === 'population_capture') {
            $cs = $con->prepare('SELECT ca.weight_g, ev.event_date, ev.terrarium_id, t.code FROM terrarium_capture_animals ca JOIN terrarium_events ev ON ev.id = ca.event_id JOIN terrariums t ON t.id = ev.terrarium_id WHERE ca.animal_id = ?');
            $cs->bind_param('i', $animal['id']);
            $cs->execute();
            $capture = $cs->get_result()->fetch_assoc();
            $cs->close();
        }
        ?>
        <div class="card <?= $originText ? '' : 'border-warning'; ?>" id="register">
            <div class="card-body">
                <h5 class="card-title d-flex justify-content-between flex-wrap gap-2"><span><i class="fas fa-book"></i> <?= trh('Entry / exit register') ?></span>
                    <a class="btn btn-sm btn-outline-secondary" href="animal_register.php"><?= trh('Open the register') ?></a></h5>
                <div class="row">
                    <div class="col-md-6">
                        <p class="mb-1"><strong><?= trh('Entry date:') ?></strong> <?= htmlspecialchars($animal['entry_date'] ?? '—'); ?></p>
                        <p class="mb-1"><strong><?= trh('Origin:') ?></strong>
                            <?php if ($originText) : ?><?= htmlspecialchars($originText); ?>
                            <?php else : ?><span class="badge bg-warning text-dark"><?= trh('To complete') ?></span><?php endif; ?></p>
                        <?php if ($capture) : ?>
                            <p class="mb-1"><strong><?= trh('Capture:') ?></strong> <a href="terrarium_view.php?id=<?= (int) $capture['terrarium_id'] ?>#captures"><?= htmlspecialchars($capture['code']) ?></a>, <?= htmlspecialchars($capture['event_date']) ?><?= $capture['weight_g'] !== null ? ' — ' . trh('weight at capture {w} g', ['w' => rtrim(rtrim(number_format((float) $capture['weight_g'], 2, '.', ''), '0'), '.')]) : '' ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-6">
                        <?php if ($animal['status'] !== 'active') : ?>
                            <p class="mb-1"><strong><?= trh('Exit date:') ?></strong> <?= htmlspecialchars($animal['exit_date'] ?? '—'); ?></p>
                            <p class="mb-1"><strong><?= trh('Exit code:') ?></strong> <?= htmlspecialchars(exit_label($animal['exit_code'] ?? null, $animal['status'])); ?><?= !empty($animal['exit_destination']) ? ' → ' . htmlspecialchars($animal['exit_destination']) : ''; ?></p>
                        <?php else : ?>
                            <p class="mb-1 text-muted"><?= trh('Still in the facility.') ?></p>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if ($canEditOrigin) : ?>
                    <details class="mt-2" <?= $originText ? '' : 'open'; ?>>
                        <summary class="small"><?= $originText ? trh('Correct the entry (administrator)') : trh('Complete the entry into the facility') ?></summary>
                        <form method="POST" action="animal_origin.php" class="mt-2">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']); ?>">
                            <input type="hidden" name="animal_id" value="<?= (int) $animal['id']; ?>">
                            <?= animal_origin_fields($animal, true); ?>
                            <?php if ($originText) : ?>
                                <div class="mb-2">
                                    <label class="form-label" for="entry_correction_reason"><?= trh('Reason for the correction') ?> *</label>
                                    <input type="text" class="form-control" id="entry_correction_reason" name="correction_reason" maxlength="1000" required placeholder="<?= trh('e.g. wrong delivery note, date entered by mistake') ?>">
                                </div>
                            <?php endif; ?>
                            <button type="submit" class="btn btn-sm btn-primary"><?= trh('Save the entry') ?></button>
                        </form>
                    </details>
                <?php endif; ?>
                <?php if ($isAdmin && $animal['status'] !== 'active') : ?>
                    <details class="mt-2">
                        <summary class="small"><?= trh('Correct the exit (administrator)') ?></summary>
                        <form method="POST" action="animal_exit.php" class="mt-2" id="exitCorrectionForm">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()); ?>">
                            <input type="hidden" name="animal_id" value="<?= (int) $animal['id']; ?>">
                            <input type="hidden" name="correct_exit" value="1">
                            <div class="row g-2">
                                <div class="col-md-6">
                                    <label class="form-label" for="corr_exit_code"><?= trh('Exit code') ?></label>
                                    <select class="form-select" id="corr_exit_code" name="exit_code" required>
                                        <?php foreach (ANIMAL_EXIT_CODES as $key => [$text]) : ?>
                                            <option value="<?= $key; ?>" <?= $key === ($animal['exit_code'] ?? '') ? 'selected' : ''; ?>><?= htmlspecialchars($text); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="corr_exit_date"><?= trh('Exit date') ?></label>
                                    <input type="date" class="form-control" id="corr_exit_date" name="exit_date" required max="<?= date('Y-m-d'); ?>" value="<?= htmlspecialchars($animal['exit_date'] ?? ''); ?>">
                                </div>
                                <div class="col-12">
                                    <label class="form-label" for="corr_destination"><?= trh('Destination facility (for an export)') ?></label>
                                    <input type="text" class="form-control" id="corr_destination" name="destination" value="<?= htmlspecialchars($animal['exit_destination'] ?? ''); ?>">
                                </div>
                                <div class="col-12">
                                    <label class="form-label" for="corr_exit_reason"><?= trh('Reason / circumstances') ?></label>
                                    <textarea class="form-control" id="corr_exit_reason" name="reason" required><?= htmlspecialchars($animal['exit_reason'] ?? ''); ?></textarea>
                                </div>
                                <div class="col-12">
                                    <label class="form-label" for="exit_correction_reason"><?= trh('Reason for the correction') ?> *</label>
                                    <input type="text" class="form-control" id="exit_correction_reason" name="correction_reason" maxlength="1000" required placeholder="<?= trh('e.g. wrong exit code, date entered by mistake') ?>">
                                </div>
                            </div>
                            <button type="submit" class="btn btn-sm btn-primary mt-2"><?= trh('Save the corrected exit') ?></button>
                        </form>
                    </details>
                <?php endif; ?>
                <?php if ($registerCorrections) : ?>
                    <h6 class="mt-3"><?= trh('Corrections of the register') ?></h6>
                    <ul class="list-group list-group-flush small" id="registerCorrections">
                        <?php foreach ($registerCorrections as $c) : ?>
                            <li class="list-group-item px-0">
                                <strong><?= htmlspecialchars(substr((string) $c['corrected_at'], 0, 16) . ' — ' . ($c['corrected_by_name'] ?? '') . ' — ' . (correction_records()[$c['record']] ?? $c['record'])) ?></strong> :
                                <?= htmlspecialchars($c['reason']) ?>
                                <div class="text-muted"><?= htmlspecialchars(correction_changes_text($c)) ?></div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <h5 class="card-title d-flex justify-content-between align-items-center"><?= trh('Parentage') ?>
                    <a class="btn btn-sm btn-outline-primary" href="animal_pedigree.php?id=<?= (int) $animal['id']; ?>"><i class="fas fa-sitemap"></i> <?= trh('Family tree') ?></a></h5>
                <?php if ($parentage) : ?>
                    <p><strong><?= trh('Sire:') ?></strong> <?= htmlspecialchars($parentage['sire_uid'] ?? tr('Unknown/external')); ?></p>
                    <p><strong><?= trh('Dam:') ?></strong> <?= htmlspecialchars($parentage['dam_uid'] ?? tr('Unknown/external')); ?></p>
                    <?php if (!empty($parentage['litter_id'])) : ?>
                        <p><strong><?= trh('Litter:') ?></strong> <a href="litter_manage.php?litter_id=<?= (int) $parentage['litter_id']; ?>">#<?= (int) $parentage['litter_id']; ?></a></p>
                    <?php endif; ?>
                <?php else : ?>
                    <p class="text-muted"><?= trh('No recorded parentage (external origin).') ?></p>
                <?php endif; ?>

                <?php if ($offspring->num_rows > 0) : ?>
                    <p><strong><?= trh('Offspring:') ?></strong></p>
                    <ul>
                        <?php while ($o = $offspring->fetch_assoc()) : ?>
                            <li><a href="animal_view.php?id=<?= (int) $o['id']; ?>"><?= htmlspecialchars($o['animal_uid']); ?></a></li>
                        <?php endwhile; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <h5 class="card-title"><?= trh('Cage History') ?></h5>
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th><?= trh('Date') ?></th>
                            <th><?= trh('From') ?></th>
                            <th><?= trh('To') ?></th>
                            <th><?= trh('Moved by') ?></th>
                            <th><?= trh('Reason') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($m = $movements->fetch_assoc()) : ?>
                            <tr>
                                <td><?= htmlspecialchars($m['moved_at']); ?></td>
                                <td><?= htmlspecialchars($m['from_cage_id'] ?? '(' . tr('created') . ')'); ?></td>
                                <td><?= htmlspecialchars($m['to_cage_id']); ?></td>
                                <td><?= htmlspecialchars($m['moved_by_name'] ?? tr('N/A')); ?></td>
                                <td><?= htmlspecialchars($m['reason'] ?? ''); ?></td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <h5 class="card-title"><?= trh('Experimentation') ?></h5>
                <?php if ($experiments->num_rows === 0) : ?>
                    <p class="text-muted"><?= trh('Not enrolled in any experiment.') ?></p>
                <?php else : ?>
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th><?= trh('Trial') ?></th>
                                <th><?= trh('Protocol') ?></th>
                                <th><?= trh('Experiment') ?></th>
                                <th><?= trh('Enrolled') ?></th>
                                <th><?= trh('Removed') ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($e = $experiments->fetch_assoc()) : ?>
                                <tr>
                                    <td><?= htmlspecialchars($e['trial_number']); ?></td>
                                    <td><?= htmlspecialchars($e['protocol_number']); ?></td>
                                    <td><a href="experiment_manage.php?protocol_id=<?= (int) $e['protocol_id']; ?>"><?= htmlspecialchars($e['experiment_number']); ?></a></td>
                                    <td><?= htmlspecialchars($e['enrolled_at']); ?></td>
                                    <td><?= htmlspecialchars($e['removed_at'] ?? '-'); ?></td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <div class="card" id="health">
            <div class="card-body">
                <h5 class="card-title"><?= trh('Health Reports') ?></h5>
                <?php if (empty($healthReports)) : ?>
                    <p class="text-muted"><?= trh('No health issue reported.') ?></p>
                <?php else : ?>
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th><?= trh('Observed') ?></th>
                                <th><?= trh('Category') ?></th>
                                <th><?= trh('Severity') ?></th>
                                <th><?= trh('Status') ?></th>
                                <th><?= trh('Cage') ?></th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($healthReports as $hr) : ?>
                                <tr>
                                    <td><?= (int) $hr['id']; ?></td>
                                    <td><?= htmlspecialchars($hr['observed_at']); ?></td>
                                    <td><?= htmlspecialchars(health_categories()[$hr['category']] ?? $hr['category']); ?></td>
                                    <td><?= health_badge($hr['current_severity'], HEALTH_SEVERITIES); ?></td>
                                    <td><?= health_badge($hr['current_status'], HEALTH_STATUSES); ?></td>
                                    <td><?= htmlspecialchars($hr['cage_id'] ?? '-'); ?></td>
                                    <td><a href="health_report_view.php?id=<?= (int) $hr['id']; ?>" class="btn btn-sm btn-outline-primary"><?= trh('Follow-up') ?></a></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <h5 class="card-title"><?= trh('Event Log') ?></h5>
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th><?= trh('Date') ?></th>
                            <th><?= trh('Event') ?></th>
                            <th><?= trh('Details') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($ev = $events->fetch_assoc()) : ?>
                            <tr>
                                <td><?= htmlspecialchars($ev['created_at']); ?></td>
                                <td><?= trh(str_replace('_', ' ', $ev['event_type'])); ?></td>
                                <td><code><?= htmlspecialchars($ev['payload'] ?? ''); ?></code></td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <br>
    <?php include 'footer.php'; ?>
</body>

</html>
