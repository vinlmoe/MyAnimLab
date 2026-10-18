<?php

/**
 * Find an animal (recherche d'animal).
 *
 * One place to locate any animal by its number (animal_uid / tag), cage,
 * line, sex or status, and act on it directly: open its record, transfer it,
 * report a health issue, sacrifice it. An exact animal number match jumps
 * straight to the animal's record.
 */

require_once 'session_config.php';
require 'dbcon.php';
require 'animal_lib.php';

if (!isset($_SESSION['username'])) {
    $currentUrl = urlencode($_SERVER['REQUEST_URI']);
    header("Location: index.php?redirect=$currentUrl");
    exit;
}

$q = trim($_GET['q'] ?? '');
$lineId = (int) ($_GET['line_id'] ?? 0);
$cageId = trim($_GET['cage_id'] ?? '');
$sex = $_GET['sex'] ?? '';
$status = $_GET['status'] ?? 'active';
$healthOnly = !empty($_GET['health_open']);

// Exact animal number (or tag) typed in the quick search: open the record directly.
if ($q !== '' && empty($_GET['list'])) {
    $exact = $con->prepare("SELECT id FROM mice WHERE animal_uid = ? OR mouse_id = ?");
    $exact->bind_param("ss", $q, $q);
    $exact->execute();
    $matches = $exact->get_result()->fetch_all(MYSQLI_ASSOC);
    $exact->close();
    if (count($matches) === 1) {
        header("Location: animal_view.php?id=" . (int) $matches[0]['id']);
        exit;
    }
}

$where = [];
$types = '';
$params = [];

if ($q !== '') {
    $where[] = "(m.animal_uid LIKE ? OR m.mouse_id LIKE ? OR m.cage_id LIKE ? OR m.genotype LIKE ? OR al.name LIKE ? OR m.distinctive_mark LIKE ?)";
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like, $like, $like);
    $types .= 'ssssss';
}
if ($lineId) {
    $where[] = "m.line_id = ?";
    $params[] = $lineId;
    $types .= 'i';
}
if ($cageId !== '') {
    $where[] = "m.cage_id = ?";
    $params[] = $cageId;
    $types .= 's';
}
if (in_array($sex, ['male', 'female'], true)) {
    $where[] = "m.sex = ?";
    $params[] = $sex;
    $types .= 's';
}
if (in_array($status, ['active', 'sacrificed', 'dead', 'transferred_out', 'archived'], true)) {
    $where[] = "m.status = ?";
    $params[] = $status;
    $types .= 's';
}
// Living animals breaking the housing rule (no cage, or a retired cage):
// legacy data only, the database now prevents new cases.
$housingAnomaly = !empty($_GET['housing_anomaly']);
if ($housingAnomaly) {
    $where[] = "m.status = 'active' AND (c.cage_id IS NULL OR c.status <> 'active')";
}
if ($healthOnly) {
    $where[] = "m.id IN (SELECT s.animal_id FROM (" . HEALTH_REPORTS_WITH_STATE_SQL . ") s WHERE s.current_status <> 'resolved')";
}

$limit = 200;
$sql = "SELECT m.id, m.animal_uid, m.mouse_id, m.sex, m.genotype, m.distinctive_mark, m.status, m.cage_id,
               al.code AS line_code, al.name AS line_name,
               c.room, c.rack,
               (SELECT COUNT(*) FROM holding h WHERE h.cage_id = m.cage_id) AS is_holding
        FROM mice m
        LEFT JOIN animal_lines al ON al.id = m.line_id
        LEFT JOIN cages c ON c.cage_id = m.cage_id"
    . ($where ? " WHERE " . implode(' AND ', $where) : '')
    . " ORDER BY al.code, m.animal_uid LIMIT $limit";

$stmt = $con->prepare($sql);
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$animals = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$healthCounts = open_health_counts($con, array_column($animals, 'id'));

$lines = $con->query("SELECT id, code, name FROM animal_lines ORDER BY code")->fetch_all(MYSQLI_ASSOC);
$cages = $con->query("SELECT cage_id FROM cages WHERE status = 'active' ORDER BY cage_id")->fetch_all(MYSQLI_ASSOC);

require 'header.php';
?>

<!doctype html>
<html lang="<?= htmlspecialchars(current_locale()) ?>">

<head>
    <title><?= trh('Find Animal') ?> | <?php echo htmlspecialchars($labName); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .container {
            max-width: 1200px;
        }

        .actions .btn {
            margin: 1px;
        }
    </style>
</head>

<body>
    <div class="container mt-4 content">
        <h4><i class="fas fa-search"></i> <?= trh('Find an Animal') ?></h4>
        <p class="text-muted"><?= trh('Search by animal number, tag, cage, line or genotype. Typing an exact animal number (e.g. RSD-0002) opens its record directly.') ?></p>

        <?php include('message.php'); ?>

        <form method="GET" class="card card-body mb-3">
            <input type="hidden" name="list" value="1">
            <div class="row g-2 align-items-end">
                <div class="col-md-4">
                    <label class="form-label" for="q"><?= trh('Number / tag / cage / genotype / mark') ?></label>
                    <input type="text" class="form-control" id="q" name="q" value="<?= htmlspecialchars($q); ?>" placeholder="<?= trh('e.g. RSD-0002, P-00012, WT, ear punch') ?>" autofocus>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="line_id"><?= trh('Line') ?></label>
                    <select class="form-select" id="line_id" name="line_id">
                        <option value=""><?= trh('All lines') ?></option>
                        <?php foreach ($lines as $l) : ?>
                            <option value="<?= (int) $l['id']; ?>" <?= $lineId === (int) $l['id'] ? 'selected' : ''; ?>><?= htmlspecialchars($l['code'] . ' - ' . $l['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="cage_id"><?= trh('Cage') ?></label>
                    <select class="form-select" id="cage_id" name="cage_id">
                        <option value=""><?= trh('All cages') ?></option>
                        <?php foreach ($cages as $c) : ?>
                            <option value="<?= htmlspecialchars($c['cage_id']); ?>" <?= $cageId === $c['cage_id'] ? 'selected' : ''; ?>><?= htmlspecialchars($c['cage_id']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-1">
                    <label class="form-label" for="sex"><?= trh('Sex') ?></label>
                    <select class="form-select" id="sex" name="sex">
                        <option value=""><?= trh('All') ?></option>
                        <option value="male" <?= $sex === 'male' ? 'selected' : ''; ?>><?= trh('Male') ?></option>
                        <option value="female" <?= $sex === 'female' ? 'selected' : ''; ?>><?= trh('Female') ?></option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="status"><?= trh('Status') ?></label>
                    <select class="form-select" id="status" name="status">
                        <option value="all" <?= $status === 'all' ? 'selected' : ''; ?>><?= trh('All') ?></option>
                        <?php foreach (['active', 'sacrificed', 'dead', 'transferred_out', 'archived'] as $s) : ?>
                            <option value="<?= $s; ?>" <?= $status === $s ? 'selected' : ''; ?>><?= htmlspecialchars(ucfirst(tr(str_replace('_', ' ', $s)))); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-1">
                    <button type="submit" class="btn btn-primary w-100"><i class="fas fa-search"></i> <?= trh('Search') ?></button>
                </div>
            </div>
            <div class="form-check mt-2">
                <input class="form-check-input" type="checkbox" id="health_open" name="health_open" value="1" <?= $healthOnly ? 'checked' : ''; ?>>
                <label class="form-check-label" for="health_open"><?= trh('Only animals with an unresolved health issue') ?></label>
            </div>
            <div class="form-check">
                <input class="form-check-input" type="checkbox" id="housing_anomaly" name="housing_anomaly" value="1" <?= $housingAnomaly ? 'checked' : ''; ?>>
                <label class="form-check-label" for="housing_anomaly"><?= trh('Housing anomalies: living animals without an active cage') ?></label>
            </div>
        </form>

        <p class="text-muted mb-2">
            <?= trh('{n} animal(s)', ['n' => count($animals)]); ?><?= count($animals) >= $limit ? ' — ' . trh('showing the first {limit}, refine your search', ['limit' => $limit]) : ''; ?>
            · <a href="animal_search.php?list=1"><?= trh('Reset filters') ?></a>
        </p>

        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle">
                <thead class="table-light">
                    <tr>
                        <th><?= trh('Animal') ?></th>
                        <th><?= trh('Line') ?></th>
                        <th><?= trh('Sex') ?></th>
                        <th><?= trh('Cage') ?></th>
                        <th><?= trh('Location') ?></th>
                        <th><?= trh('Genotype') ?></th>
                        <th><?= trh('Status') ?></th>
                        <th><?= trh('Health') ?></th>
                        <th class="text-end"><?= trh('Actions') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($animals)) : ?>
                        <tr>
                            <td colspan="9" class="text-center text-muted"><?= trh('No animal matches these criteria.') ?></td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($animals as $a) :
                        $aid = (int) $a['id'];
                        $label = $a['animal_uid'] ?? $a['mouse_id'] ?? "#$aid";
                        $cageView = $a['is_holding'] ? 'hc_view.php' : 'bc_view.php';
                        $openIssues = $healthCounts[$aid] ?? 0;
                    ?>
                        <tr>
                            <td>
                                <a href="animal_view.php?id=<?= $aid; ?>" class="fw-bold"><?= htmlspecialchars($label); ?></a>
                                <?php if ($a['mouse_id'] && $a['animal_uid']) : ?>
                                    <br><small class="text-muted"><?= trh('tag {tag}', ['tag' => $a['mouse_id']]); ?></small>
                                <?php endif; ?>
                                <?php if (!empty($a['distinctive_mark'])) : ?>
                                    <br><small class="text-muted mark"><?= trh('mark: {mark}', ['mark' => $a['distinctive_mark']]); ?></small>
                                <?php endif; ?>
                            </td>
                            <td title="<?= htmlspecialchars($a['line_name'] ?? ''); ?>"><?= htmlspecialchars($a['line_code'] ?? '-'); ?></td>
                            <td><?= $a['sex'] === 'male' ? '♂' : ($a['sex'] === 'female' ? '♀' : '-'); ?></td>
                            <td>
                                <?php if ($a['cage_id']) : ?>
                                    <a href="<?= $cageView; ?>?id=<?= rawurlencode($a['cage_id']); ?>"><?= htmlspecialchars($a['cage_id']); ?></a>
                                <?php else : ?>-<?php endif; ?>
                            </td>
                            <td><small><?= htmlspecialchars(trim(($a['room'] ?? '') . ' ' . ($a['rack'] ?? '')) ?: '-'); ?></small></td>
                            <td><?= htmlspecialchars($a['genotype'] ?? ''); ?></td>
                            <td><?= trh(str_replace('_', ' ', $a['status'])); ?></td>
                            <td>
                                <?php if ($openIssues) : ?>
                                    <a href="health_registry.php?animal=<?= rawurlencode($label); ?>" class="badge bg-danger text-decoration-none"><?= trh('{n} open', ['n' => $openIssues]); ?></a>
                                <?php else : ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end actions">
                                <a href="animal_view.php?id=<?= $aid; ?>" class="btn btn-sm btn-outline-secondary" title="<?= trh('Open record') ?>"><i class="fas fa-eye"></i> <?= trh('Record') ?></a>
                                <?php if ($a['status'] === 'active') : ?>
                                    <a href="animal_transfer.php?animal_id=<?= $aid; ?>" class="btn btn-sm btn-outline-primary" title="<?= trh('Transfer to another cage') ?>"><i class="fas fa-exchange-alt"></i> <?= trh('Transfer') ?></a>
                                    <a href="health_report.php?animal_id=<?= $aid; ?>" class="btn btn-sm btn-outline-warning" title="<?= trh('Report a health issue') ?>"><i class="fas fa-notes-medical"></i> <?= trh('Health') ?></a>
                                    <a href="animal_exit.php?id=<?= $aid; ?>" class="btn btn-sm btn-outline-danger" title="<?= trh('Exit: sacrifice, death or export') ?>"><i class="fas fa-sign-out-alt"></i> <?= trh('Exit') ?></a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <br>
    <?php include 'footer.php'; ?>
</body>

</html>
