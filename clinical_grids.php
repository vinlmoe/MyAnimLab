<?php

/**
 * Clinical scoring grids (grilles d'évaluation clinique).
 *
 * Library of reusable grids used by protocols for the daily follow-up.
 * Without ?id: list, create and duplicate grids. With ?id: view a grid and,
 * while it is not used yet, edit its name, items and scored levels. Once a
 * follow-up or an endpoint uses it, it is read-only: duplicate it to change.
 */

session_start();
require 'dbcon.php';
require 'animal_lib.php';

if (!isset($_SESSION['username'])) {
    $currentUrl = urlencode($_SERVER['REQUEST_URI']);
    header("Location: index.php?redirect=$currentUrl");
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$currentUserId = $_SESSION['user_id'] ?? null;
$gridId = (int) ($_GET['id'] ?? $_POST['grid_id'] ?? 0);

// Parse "score = description" lines (also accepts "score: description").
function parse_levels(string $text): array
{
    $levels = [];
    foreach (preg_split('/\R/', $text) as $line) {
        if (preg_match('/^\s*(\d+)\s*[=:\-]\s*(.+?)\s*$/u', $line, $m)) {
            $levels[(int) $m[1]] = $m[2];
        }
    }
    ksort($levels);
    return $levels;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die('CSRF token validation failed');
    }
    $action = $_POST['action'] ?? '';
    $back = "clinical_grids.php" . ($gridId ? "?id=$gridId" : '');

    try {
        if ($action === 'create') {
            $name = trim($_POST['name'] ?? '');
            if ($name === '') {
                throw new Exception("Give the grid a name.");
            }
            $desc = trim($_POST['description'] ?? '') ?: null;
            $stmt = $con->prepare("INSERT INTO clinical_grids (name, description, created_by) VALUES (?, ?, ?)");
            $stmt->bind_param("ssi", $name, $desc, $currentUserId);
            $stmt->execute();
            $back = "clinical_grids.php?id=" . $con->insert_id;
            $_SESSION['message'] = "Grid created: now add its items.";
        } elseif ($action === 'duplicate') {
            $src = load_clinical_grid($con, $gridId) ?? throw new Exception("Grid not found.");
            mysqli_begin_transaction($con);
            $name = $src['name'] . ' (copy)';
            $stmt = $con->prepare("INSERT INTO clinical_grids (name, description, created_by) VALUES (?, ?, ?)");
            $stmt->bind_param("ssi", $name, $src['description'], $currentUserId);
            $stmt->execute();
            $newId = $con->insert_id;
            foreach ($src['items'] as $item) {
                $ins = $con->prepare("INSERT INTO clinical_grid_items (grid_id, position, label) VALUES (?, ?, ?)");
                $ins->bind_param("iis", $newId, $item['position'], $item['label']);
                $ins->execute();
                $newItem = $con->insert_id;
                foreach ($item['levels'] as $lv) {
                    $insL = $con->prepare("INSERT INTO clinical_grid_levels (item_id, score, description) VALUES (?, ?, ?)");
                    $insL->bind_param("iis", $newItem, $lv['score'], $lv['description']);
                    $insL->execute();
                }
            }
            mysqli_commit($con);
            log_activity($con, 'duplicate', 'clinical_grid', (string) $newId, "Copy of grid #$gridId", $currentUserId);
            $back = "clinical_grids.php?id=$newId";
            $_SESSION['message'] = "Grid duplicated: the copy can be edited.";
        } elseif (in_array($action, ['rename', 'add_item', 'delete_item'], true)) {
            if (clinical_grid_locked($con, $gridId)) {
                throw new Exception("This grid is already used and is read-only. Duplicate it to make changes.");
            }
            if ($action === 'rename') {
                $name = trim($_POST['name'] ?? '');
                $desc = trim($_POST['description'] ?? '') ?: null;
                if ($name === '') {
                    throw new Exception("Give the grid a name.");
                }
                $stmt = $con->prepare("UPDATE clinical_grids SET name = ?, description = ? WHERE id = ?");
                $stmt->bind_param("ssi", $name, $desc, $gridId);
                $stmt->execute();
                $_SESSION['message'] = "Grid saved.";
            } elseif ($action === 'add_item') {
                $label = trim($_POST['label'] ?? '');
                $levels = parse_levels($_POST['levels'] ?? '');
                if ($label === '' || count($levels) < 2) {
                    throw new Exception("An item needs a label and at least two levels, one per line, e.g. \"0 = Normal\".");
                }
                mysqli_begin_transaction($con);
                $pos = (int) $con->query("SELECT COALESCE(MAX(position), 0) + 1 FROM clinical_grid_items WHERE grid_id = $gridId")->fetch_row()[0];
                $stmt = $con->prepare("INSERT INTO clinical_grid_items (grid_id, position, label) VALUES (?, ?, ?)");
                $stmt->bind_param("iis", $gridId, $pos, $label);
                $stmt->execute();
                $itemId = $con->insert_id;
                foreach ($levels as $score => $text) {
                    $insL = $con->prepare("INSERT INTO clinical_grid_levels (item_id, score, description) VALUES (?, ?, ?)");
                    $insL->bind_param("iis", $itemId, $score, $text);
                    $insL->execute();
                }
                mysqli_commit($con);
                $_SESSION['message'] = "Item \"$label\" added.";
            } else {
                $itemId = (int) ($_POST['item_id'] ?? 0);
                $stmt = $con->prepare("DELETE FROM clinical_grid_items WHERE id = ? AND grid_id = ?");
                $stmt->bind_param("ii", $itemId, $gridId);
                $stmt->execute();
                $_SESSION['message'] = "Item removed.";
            }
        }
    } catch (Exception $e) {
        mysqli_rollback($con);
        $_SESSION['message'] = $e->getMessage();
    }
    header("Location: $back");
    exit;
}

$grid = $gridId ? load_clinical_grid($con, $gridId) : null;
$locked = $grid ? clinical_grid_locked($con, $gridId) : false;
$grids = $con->query(
    "SELECT g.*, (SELECT COUNT(*) FROM clinical_grid_items i WHERE i.grid_id = g.id) AS n_items,
            (SELECT GROUP_CONCAT(protocol_number SEPARATOR ', ') FROM protocols p WHERE p.clinical_grid_id = g.id) AS protocols
     FROM clinical_grids g ORDER BY g.name"
)->fetch_all(MYSQLI_ASSOC);

require 'header.php';
?>

<!doctype html>
<html lang="en">

<head>
    <title>Clinical Grids | <?php echo htmlspecialchars($labName); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .container {
            max-width: 1000px;
        }
    </style>
</head>

<body>
    <div class="container mt-4 content">
        <h4><i class="fas fa-table"></i> Clinical scoring grids</h4>
        <p class="text-muted">Grids used for the daily follow-up of experimental animals. Assign a grid to a protocol on its page, then define endpoints on the total score or on one item.</p>

        <?php include('message.php'); ?>

        <?php if ($grid) : ?>
            <a href="clinical_grids.php">&laquo; All grids</a>
            <div class="card my-3">
                <div class="card-body">
                    <h5><?= htmlspecialchars($grid['name']); ?>
                        <?php if ($locked) : ?><span class="badge bg-secondary">in use — read-only</span><?php endif; ?>
                        <small class="text-muted">max total <?= (int) $grid['max_total']; ?></small>
                    </h5>
                    <p class="text-muted"><?= nl2br(htmlspecialchars($grid['description'] ?? '')); ?></p>

                    <table class="table table-sm table-bordered">
                        <thead class="table-light">
                            <tr>
                                <th>Item</th>
                                <th>Levels</th>
                                <?php if (!$locked) : ?><th></th><?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($grid['items'] as $item) : ?>
                                <tr>
                                    <td class="fw-bold"><?= htmlspecialchars($item['label']); ?></td>
                                    <td>
                                        <?php foreach ($item['levels'] as $lv) : ?>
                                            <div><span class="badge bg-light text-dark border"><?= (int) $lv['score']; ?></span> <?= htmlspecialchars($lv['description']); ?></div>
                                        <?php endforeach; ?>
                                    </td>
                                    <?php if (!$locked) : ?>
                                        <td>
                                            <form method="POST" onsubmit="return confirm('Remove this item?');">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                <input type="hidden" name="grid_id" value="<?= $gridId; ?>">
                                                <input type="hidden" name="item_id" value="<?= (int) $item['id']; ?>">
                                                <button name="action" value="delete_item" class="btn btn-sm btn-outline-danger">Remove</button>
                                            </form>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$grid['items']) : ?>
                                <tr><td colspan="3" class="text-muted">No item yet.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>

                    <?php if (!$locked) : ?>
                        <h6 class="mt-3">Add an item</h6>
                        <form method="POST" class="row g-2">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']); ?>">
                            <input type="hidden" name="grid_id" value="<?= $gridId; ?>">
                            <div class="col-md-4">
                                <input type="text" class="form-control" name="label" id="itemLabel" placeholder="Item, e.g. Posture" required>
                            </div>
                            <div class="col-md-6">
                                <textarea class="form-control" name="levels" id="itemLevels" rows="4" required placeholder="One level per line:&#10;0 = Normal&#10;1 = Slightly hunched&#10;2 = Hunched&#10;3 = Prostrate"></textarea>
                            </div>
                            <div class="col-md-2">
                                <button name="action" value="add_item" class="btn btn-primary w-100">Add item</button>
                            </div>
                        </form>

                        <h6 class="mt-3">Name and description</h6>
                        <form method="POST" class="row g-2">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']); ?>">
                            <input type="hidden" name="grid_id" value="<?= $gridId; ?>">
                            <div class="col-md-4"><input type="text" class="form-control" name="name" value="<?= htmlspecialchars($grid['name']); ?>" required></div>
                            <div class="col-md-6"><input type="text" class="form-control" name="description" value="<?= htmlspecialchars($grid['description'] ?? ''); ?>"></div>
                            <div class="col-md-2"><button name="action" value="rename" class="btn btn-outline-primary w-100">Save</button></div>
                        </form>
                    <?php endif; ?>

                    <form method="POST" class="mt-3">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <input type="hidden" name="grid_id" value="<?= $gridId; ?>">
                        <button name="action" value="duplicate" class="btn btn-outline-secondary btn-sm">Duplicate this grid</button>
                    </form>
                </div>
            </div>
        <?php else : ?>
            <table class="table table-sm align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Grid</th>
                        <th>Items</th>
                        <th>Used by protocols</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($grids as $g) : ?>
                        <tr>
                            <td><a href="clinical_grids.php?id=<?= (int) $g['id']; ?>" class="fw-bold"><?= htmlspecialchars($g['name']); ?></a><br><small class="text-muted"><?= htmlspecialchars($g['description'] ?? ''); ?></small></td>
                            <td><?= (int) $g['n_items']; ?></td>
                            <td><?= htmlspecialchars($g['protocols'] ?? '-'); ?></td>
                            <td><a href="clinical_grids.php?id=<?= (int) $g['id']; ?>" class="btn btn-sm btn-outline-primary">Open</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div class="card card-body">
                <h6>New grid</h6>
                <form method="POST" class="row g-2">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <div class="col-md-4"><input type="text" class="form-control" name="name" id="newGridName" placeholder="Grid name" required></div>
                    <div class="col-md-6"><input type="text" class="form-control" name="description" placeholder="Description (scale, usage...)"></div>
                    <div class="col-md-2"><button name="action" value="create" class="btn btn-primary w-100">Create</button></div>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <br>
    <?php include 'footer.php'; ?>
</body>

</html>
