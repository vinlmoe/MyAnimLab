<?php
/**
 * Cage dashboard (all categories)
 *
 * One presentation for every cage category: holding, breeding (accouplement)
 * and experimental cages, plus an "all cages" view. Each row shows the cage,
 * its category details (strain/DOB or cross/breeders), room, rack and living
 * animals. Cages can be selected (one, several, or the whole page) to change
 * room/rack as a group or to switch holding <-> experimental category.
 * hc_dash.php, bc_dash.php and ec_dash.php open this page on their category.
 */
session_start();
require 'dbcon.php';
require 'animal_lib.php';

if (!isset($_SESSION['username'])) {
    header('Location: index.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$type = $cageDashType ?? ($_GET['type'] ?? 'holding');
if ($type !== 'all' && !isset(CAGE_KINDS[$type])) {
    $type = 'holding';
}
$dashPage = $type === 'all' ? 'cage_dash.php?type=all' : CAGE_KINDS[$type][1];
$search = trim((string) ($_GET['search'] ?? ''));
$roomFilter = $_GET['room'] ?? '';
$page = max(1, (int) ($_GET['page'] ?? 1));
$limit = 25;

$where = ["c.status = 'active'"];
$types = '';
$params = [];
if ($type !== 'all') {
    $where[] = '(' . CAGE_KIND_SQL . ') = ?';
    $types .= 's';
    $params[] = $type;
}
if ($search !== '') {
    $where[] = "(c.cage_id LIKE ? OR c.room LIKE ? OR c.rack LIKE ?
        OR EXISTS (SELECT 1 FROM mice ms WHERE ms.cage_id = c.cage_id AND ms.status = 'active' AND ms.animal_uid LIKE ?))";
    $like = '%' . $search . '%';
    $types .= 'ssss';
    array_push($params, $like, $like, $like, $like);
}
if ($roomFilter === '__none__') {
    $where[] = "(c.room IS NULL OR c.room = '')";
} elseif ($roomFilter !== '') {
    $where[] = 'c.room = ?';
    $types .= 's';
    $params[] = $roomFilter;
}
$whereSql = implode(' AND ', $where);

$countStmt = $con->prepare("SELECT COUNT(*) FROM cages c WHERE $whereSql");
if ($params) {
    $countStmt->bind_param($types, ...$params);
}
$countStmt->execute();
$total = (int) $countStmt->get_result()->fetch_row()[0];
$countStmt->close();
$pages = max(1, (int) ceil($total / $limit));
$page = min($page, $pages);
$offset = ($page - 1) * $limit;

$userId = (int) ($_SESSION['user_id'] ?? 0);
$sql = "SELECT c.cage_id, c.room, c.rack, " . CAGE_KIND_SQL . " AS kind,
        (SELECT COUNT(*) FROM mice m WHERE m.cage_id = c.cage_id AND m.status = 'active') AS living,
        (SELECT h.dob FROM holding h WHERE h.cage_id = c.cage_id LIMIT 1) AS dob,
        (SELECT COALESCE(s.str_name, h.strain) FROM holding h LEFT JOIN strains s ON s.str_id = h.strain WHERE h.cage_id = c.cage_id LIMIT 1) AS strain,
        (SELECT b.`cross` FROM breeding b WHERE b.cage_id = c.cage_id LIMIT 1) AS cross_name,
        (SELECT GROUP_CONCAT(m.animal_uid ORDER BY ba.sex, m.animal_uid SEPARATOR ', ')
           FROM breeding b JOIN breeding_animals ba ON ba.breeding_id = b.id JOIN mice m ON m.id = ba.animal_id
          WHERE b.cage_id = c.cage_id AND m.status = 'active') AS breeders,
        (SELECT GROUP_CONCAT(DISTINCT al.code ORDER BY al.code SEPARATOR ', ')
           FROM mice m JOIN animal_lines al ON al.id = m.line_id WHERE m.cage_id = c.cage_id AND m.status = 'active') AS line_codes,
        EXISTS (SELECT 1 FROM cage_users cu WHERE cu.cage_id = c.cage_id AND cu.user_id = ?) AS is_mine
    FROM cages c WHERE $whereSql ORDER BY c.cage_id LIMIT $limit OFFSET $offset";
$stmt = $con->prepare($sql);
$stmt->bind_param('i' . $types, $userId, ...$params);
$stmt->execute();
$cages = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$rooms = array_column($con->query("SELECT DISTINCT room FROM cages WHERE status = 'active' AND room IS NOT NULL AND room <> '' ORDER BY room")->fetch_all(MYSQLI_ASSOC), 'room');
$isAdmin = ($_SESSION['role'] ?? '') === 'admin';
$currentUrl = basename($_SERVER['PHP_SELF']) . ($_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : '');
$pageUrl = function (int $p) use ($dashPage, $search, $roomFilter): string {
    $sep = str_contains($dashPage, '?') ? '&' : '?';
    return $dashPage . $sep . http_build_query(array_filter(['search' => $search, 'room' => $roomFilter, 'page' => $p > 1 ? $p : null], fn($v) => $v !== '' && $v !== null));
};
$title = $type === 'all' ? 'Toutes les cages' : CAGE_KINDS[$type][0];
$kindBadge = ['holding' => 'bg-secondary', 'breeding' => 'bg-info text-dark', 'experimental' => 'bg-warning text-dark'];

require 'header.php';
?>
<!doctype html>
<html lang="fr">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($title) ?> | <?= htmlspecialchars($labName) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        .cage-dash { max-width: 1200px; }
        .cage-dash td, .cage-dash th { vertical-align: middle; }
        .bulk-bar { position: sticky; bottom: 0; background: #f8f9fa; border-top: 1px solid #dee2e6; z-index: 5; }
    </style>
</head>

<body>
    <main class="container mt-4 content cage-dash">
        <?php include('message.php'); ?>

        <ul class="nav nav-tabs mb-3">
            <?php foreach (CAGE_KINDS as $k => [$label, $dash]) : ?>
                <li class="nav-item"><a class="nav-link <?= $k === $type ? 'active' : '' ?>" href="<?= $dash ?>"><?= htmlspecialchars($label) ?></a></li>
            <?php endforeach; ?>
            <li class="nav-item"><a class="nav-link <?= $type === 'all' ? 'active' : '' ?>" href="cage_dash.php?type=all">Toutes les cages</a></li>
        </ul>

        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <h4 class="mb-0"><?= htmlspecialchars($title) ?> <small class="text-muted">(<?= $total ?>)</small></h4>
            <div class="d-flex flex-wrap gap-2">
                <?php if ($type === 'holding' || $type === 'experimental') : ?>
                    <a href="hc_addn.php<?= $type === 'experimental' ? '?experimental=1' : '' ?>" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> Nouvelle cage</a>
                    <a href="cage_bulk_create.php<?= $type === 'experimental' ? '?experimental=1' : '' ?>" class="btn btn-outline-primary btn-sm"><i class="fas fa-layer-group"></i> Création en lot</a>
                <?php elseif ($type === 'breeding') : ?>
                    <a href="bc_addn.php" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> Nouvelle cage d’accouplement</a>
                <?php endif; ?>
                <a href="cage_label_select.php" class="btn btn-success btn-sm"><i class="fas fa-print"></i> Étiquettes</a>
                <a href="maintenance.php?from=<?= $type === 'breeding' ? 'bc_dash' : 'hc_dash' ?>" class="btn btn-warning btn-sm"><i class="fas fa-wrench"></i> Maintenance</a>
            </div>
        </div>

        <form method="get" class="row g-2 mb-3">
            <?php if ($type === 'all') : ?><input type="hidden" name="type" value="all"><?php endif; ?>
            <div class="col-md-6">
                <input type="search" name="search" class="form-control" placeholder="Cage, salle, rack ou numéro d’animal" value="<?= htmlspecialchars($search) ?>">
            </div>
            <div class="col-md-4">
                <select name="room" class="form-select" onchange="this.form.submit()">
                    <option value="">Toutes les salles</option>
                    <?php foreach ($rooms as $r) : ?>
                        <option value="<?= htmlspecialchars($r) ?>" <?= $roomFilter === $r ? 'selected' : '' ?>><?= htmlspecialchars($r) ?></option>
                    <?php endforeach; ?>
                    <option value="__none__" <?= $roomFilter === '__none__' ? 'selected' : '' ?>>Sans salle</option>
                </select>
            </div>
            <div class="col-md-2 d-grid"><button class="btn btn-primary" type="submit">Rechercher</button></div>
        </form>

        <form method="post" action="cage_location.php" id="cageGroupForm">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="return" value="<?= htmlspecialchars($currentUrl) ?>">
            <div class="table-responsive">
                <table class="table table-bordered table-hover table-sm">
                    <thead class="table-light">
                        <tr>
                            <th style="width:2rem"><input type="checkbox" class="form-check-input" id="selectAll" title="Tout sélectionner sur la page"></th>
                            <th>Cage</th>
                            <th>Détails</th>
                            <th>Salle</th>
                            <th>Rack</th>
                            <th>Vivants</th>
                            <th style="width:9rem">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($cages as $c) : $view = CAGE_KINDS[$c['kind']][2] . '?id=' . rawurlencode($c['cage_id']); ?>
                            <tr>
                                <td><input type="checkbox" class="form-check-input cage-select" name="cage_ids[]" value="<?= htmlspecialchars($c['cage_id']) ?>"></td>
                                <td>
                                    <a href="<?= htmlspecialchars($view) ?>" class="fw-bold"><?= htmlspecialchars($c['cage_id']) ?></a>
                                    <?php if ($type === 'all') : ?><span class="badge <?= $kindBadge[$c['kind']] ?>"><?= htmlspecialchars(CAGE_KINDS[$c['kind']][0]) ?></span><?php endif; ?>
                                </td>
                                <td class="small">
                                    <?php if ($c['kind'] === 'breeding') : ?>
                                        <?= htmlspecialchars($c['cross_name'] ?? '') ?>
                                        <?php if ($c['breeders']) : ?><br><span class="text-muted">Reproducteurs : <?= htmlspecialchars($c['breeders']) ?></span><?php endif; ?>
                                    <?php else : ?>
                                        <?= htmlspecialchars($c['line_codes'] ?: ($c['strain'] ?? '')) ?>
                                        <?php if ($c['dob']) : ?><br><span class="text-muted">Né(s) le <?= htmlspecialchars(date('d/m/Y', strtotime($c['dob']))) ?></span><?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($c['room'] ?? '') ?: '<span class="text-muted">—</span>' ?></td>
                                <td><?= htmlspecialchars($c['rack'] ?? '') ?: '<span class="text-muted">—</span>' ?></td>
                                <td><?= (int) $c['living'] ?></td>
                                <td class="text-nowrap">
                                    <a href="<?= htmlspecialchars($view) ?>" class="btn btn-primary btn-sm" title="Voir la cage"><i class="fas fa-eye"></i></a>
                                    <a href="manage_tasks.php?id=<?= rawurlencode($c['cage_id']) ?>" class="btn btn-secondary btn-sm" title="Tâches"><i class="fas fa-tasks"></i></a>
                                    <?php if ($isAdmin || $c['is_mine']) : ?>
                                        <a href="cage_retire.php?id=<?= rawurlencode($c['cage_id']) ?>" class="btn btn-danger btn-sm" title="Retirer la cage"><i class="fas fa-trash"></i></a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$cages) : ?>
                            <tr><td colspan="7" class="text-muted">Aucune cage.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($pages > 1) : ?>
                <nav><ul class="pagination justify-content-center">
                    <?php for ($p = 1; $p <= $pages; $p++) : ?>
                        <li class="page-item <?= $p === $page ? 'active' : '' ?>"><a class="page-link" href="<?= htmlspecialchars($pageUrl($p)) ?>"><?= $p ?></a></li>
                    <?php endfor; ?>
                </ul></nav>
            <?php endif; ?>

            <?php if ($cages) : ?>
                <div class="bulk-bar row g-2 align-items-end py-2 px-1">
                    <div class="col-12 small text-muted"><span id="selectedCount">0</span> cage(s) sélectionnée(s)</div>
                    <div class="col-md-3">
                        <label class="form-label mb-0" for="bulkRoom">Nouvelle salle</label>
                        <input type="text" class="form-control form-control-sm" id="bulkRoom" name="room" list="roomList">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label mb-0" for="bulkRack">Rack</label>
                        <input type="text" class="form-control form-control-sm" id="bulkRack" name="rack" placeholder="inchangé">
                    </div>
                    <div class="col-auto">
                        <button type="submit" name="location_action" value="move" class="btn btn-primary btn-sm group-action"><i class="fas fa-door-open"></i> Changer de salle</button>
                    </div>
                    <?php if ($type === 'holding' || $type === 'all') : ?>
                        <div class="col-auto"><button type="submit" name="location_action" value="experimental" class="btn btn-outline-warning btn-sm group-action">Classer en expérimentation</button></div>
                    <?php endif; ?>
                    <?php if ($type === 'experimental' || $type === 'all') : ?>
                        <div class="col-auto"><button type="submit" name="location_action" value="holding" class="btn btn-outline-secondary btn-sm group-action">Classer en hébergement</button></div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <datalist id="roomList">
                <?php foreach ($rooms as $r) : ?><option value="<?= htmlspecialchars($r) ?>"><?php endforeach; ?>
            </datalist>
        </form>
    </main>
    <?php include 'footer.php'; ?>
    <script>
        const boxes = document.querySelectorAll('.cage-select');
        const counter = document.getElementById('selectedCount');
        const refreshCount = () => { if (counter) counter.textContent = document.querySelectorAll('.cage-select:checked').length; };
        boxes.forEach(b => b.addEventListener('change', refreshCount));
        document.getElementById('selectAll').addEventListener('change', e => { boxes.forEach(b => b.checked = e.target.checked); refreshCount(); });
        document.querySelectorAll('.group-action').forEach(btn => btn.addEventListener('click', e => {
            if (!document.querySelectorAll('.cage-select:checked').length) {
                e.preventDefault();
                alert('Sélectionnez au moins une cage.');
            } else if (btn.value === 'move' && !document.getElementById('bulkRoom').value.trim()) {
                e.preventDefault();
                alert('Indiquez la nouvelle salle.');
            }
        }));
    </script>
</body>

</html>
