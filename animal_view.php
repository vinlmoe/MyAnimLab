<?php

/**
 * Animal record (fiche animal).
 *
 * Displays an animal's identity, current cage, full cage movement history,
 * parentage, and experimentation history. Stays accessible after the animal
 * has died or left the facility (status becomes sacrificed/transferred_out/archived,
 * the row itself is never deleted).
 */

session_start();
require 'dbcon.php';
require 'animal_lib.php';

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
    echo "<div class='container mt-4'><div class='alert alert-danger'>Animal not found.</div></div>";
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
    "SELECT ea.*, e.experiment_number, e.title AS experiment_title, p.protocol_number, t.trial_number
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
    'transferred_out' => 'secondary',
    'archived' => 'secondary',
][$animal['status']] ?? 'secondary';
?>

<!doctype html>
<html lang="en">

<head>
    <title>Animal <?= htmlspecialchars($animal['animal_uid'] ?? $animal['mouse_id'] ?? ('#' . $animal['id'])); ?> | <?php echo htmlspecialchars($labName); ?></title>
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
            Animal <?= htmlspecialchars($animal['animal_uid'] ?? $animal['mouse_id'] ?? ('#' . $animal['id'])); ?>
            <span class="badge bg-<?= $statusBadge; ?>"><?= htmlspecialchars($animal['status']); ?></span>
            <?php if ($openHealthIssues) : ?>
                <a href="#health" class="badge bg-danger text-decoration-none"><?= $openHealthIssues; ?> open health issue(s)</a>
            <?php endif; ?>
            <?php if ($animal['status'] === 'active') : ?>
                <a href="animal_transfer.php?animal_id=<?= (int) $animal['id']; ?>" class="btn btn-sm btn-outline-primary">Transfer</a>
                <a href="health_report.php?animal_id=<?= (int) $animal['id']; ?>" class="btn btn-sm btn-outline-warning">Report Health Issue</a>
                <a href="animal_sacrifice.php?id=<?= (int) $animal['id']; ?>" class="btn btn-sm btn-outline-danger">Sacrifice</a>
            <?php endif; ?>
            <?php if (($_SESSION['role'] ?? '') === 'admin') : ?>
                <a href="animal_hard_delete.php?id=<?= (int) $animal['id']; ?>" class="btn btn-sm btn-outline-dark">Permanently Delete (admin)</a>
            <?php endif; ?>
        </h4>

        <div class="card">
            <div class="card-body">
                <h5 class="card-title">Identity</h5>
                <p><strong>Line:</strong> <?= htmlspecialchars($animal['line_code'] ? "{$animal['line_code']} - {$animal['line_name']} ({$animal['species']})" : 'N/A'); ?></p>
                <p><strong>Sex:</strong> <?= htmlspecialchars($animal['sex'] ?? 'N/A'); ?></p>
                <p><strong>Tag / mouse_id:</strong> <?= htmlspecialchars($animal['mouse_id'] ?? 'N/A'); ?></p>
                <p><strong>Genotype:</strong> <?= htmlspecialchars($animal['genotype'] ?? 'N/A'); ?></p>
                <p><strong>Current cage:</strong>
                    <?php if ($animal['cage_id']) : ?>
                        <?= htmlspecialchars($animal['cage_id']); ?>
                        <a href="animal_search.php?list=1&amp;status=all&amp;cage_id=<?= rawurlencode($animal['cage_id']); ?>" class="small">(cage mates)</a>
                    <?php else : ?>N/A<?php endif; ?>
                </p>
                <?php if ($animal['status'] !== 'active') : ?>
                    <p><strong>Exit date:</strong> <?= htmlspecialchars($animal['exit_date'] ?? 'N/A'); ?></p>
                    <p><strong>Exit reason:</strong> <?= htmlspecialchars($animal['exit_reason'] ?? 'N/A'); ?></p>
                <?php endif; ?>
                <p><strong>Notes:</strong> <?= nl2br(htmlspecialchars($animal['notes'] ?? '')); ?></p>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <h5 class="card-title">Parentage</h5>
                <?php if ($parentage) : ?>
                    <p><strong>Sire:</strong> <?= htmlspecialchars($parentage['sire_uid'] ?? 'Unknown/external'); ?></p>
                    <p><strong>Dam:</strong> <?= htmlspecialchars($parentage['dam_uid'] ?? 'Unknown/external'); ?></p>
                <?php else : ?>
                    <p class="text-muted">No recorded parentage (external origin).</p>
                <?php endif; ?>

                <?php if ($offspring->num_rows > 0) : ?>
                    <p><strong>Offspring:</strong></p>
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
                <h5 class="card-title">Cage History</h5>
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>From</th>
                            <th>To</th>
                            <th>Moved by</th>
                            <th>Reason</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($m = $movements->fetch_assoc()) : ?>
                            <tr>
                                <td><?= htmlspecialchars($m['moved_at']); ?></td>
                                <td><?= htmlspecialchars($m['from_cage_id'] ?? '(created)'); ?></td>
                                <td><?= htmlspecialchars($m['to_cage_id']); ?></td>
                                <td><?= htmlspecialchars($m['moved_by_name'] ?? 'N/A'); ?></td>
                                <td><?= htmlspecialchars($m['reason'] ?? ''); ?></td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <h5 class="card-title">Experimentation</h5>
                <?php if ($experiments->num_rows === 0) : ?>
                    <p class="text-muted">Not enrolled in any experiment.</p>
                <?php else : ?>
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Trial</th>
                                <th>Protocol</th>
                                <th>Experiment</th>
                                <th>Enrolled</th>
                                <th>Removed</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($e = $experiments->fetch_assoc()) : ?>
                                <tr>
                                    <td><?= htmlspecialchars($e['trial_number']); ?></td>
                                    <td><?= htmlspecialchars($e['protocol_number']); ?></td>
                                    <td><a href="experiment_followup.php?experiment_animal_id=<?= (int) $e['id']; ?>"><?= htmlspecialchars($e['experiment_number']); ?></a></td>
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
                <h5 class="card-title">Health Reports</h5>
                <?php if (empty($healthReports)) : ?>
                    <p class="text-muted">No health issue reported.</p>
                <?php else : ?>
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Observed</th>
                                <th>Category</th>
                                <th>Severity</th>
                                <th>Status</th>
                                <th>Cage</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($healthReports as $hr) : ?>
                                <tr>
                                    <td><?= (int) $hr['id']; ?></td>
                                    <td><?= htmlspecialchars($hr['observed_at']); ?></td>
                                    <td><?= htmlspecialchars(HEALTH_CATEGORIES[$hr['category']] ?? $hr['category']); ?></td>
                                    <td><?= health_badge($hr['current_severity'], HEALTH_SEVERITIES); ?></td>
                                    <td><?= health_badge($hr['current_status'], HEALTH_STATUSES); ?></td>
                                    <td><?= htmlspecialchars($hr['cage_id'] ?? '-'); ?></td>
                                    <td><a href="health_report_view.php?id=<?= (int) $hr['id']; ?>" class="btn btn-sm btn-outline-primary">Follow-up</a></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <h5 class="card-title">Event Log</h5>
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Event</th>
                            <th>Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($ev = $events->fetch_assoc()) : ?>
                            <tr>
                                <td><?= htmlspecialchars($ev['created_at']); ?></td>
                                <td><?= htmlspecialchars($ev['event_type']); ?></td>
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
