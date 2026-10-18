<?php

/**
 * Narcotics register (registre des stupéfiants): for each product flagged as a
 * narcotic, every stock movement of its lots in chronological order —
 * receipts, administrations (treatment or experimental act, with the animal),
 * adjustments, inventories and corrections — with the running balance of the
 * lot and of the product, the person and the reason. Printable, exportable
 * as CSV. Administrators record inventories: the quantity counted for each
 * lot, the difference with the theoretical stock being justified and
 * applied to the stock. Every export and inventory is traced.
 */

require_once 'session_config.php';
require 'dbcon.php';
require 'animal_lib.php';

if (!isset($_SESSION['username'])) {
    header('Location: index.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$userId = (int) ($_SESSION['user_id'] ?? 0);
$isAdmin = ($_SESSION['role'] ?? '') === 'admin';

$products = $con->query('SELECT * FROM medication_products WHERE is_narcotic = 1 ORDER BY name')->fetch_all(MYSQLI_ASSOC);
$productId = (int) ($_GET['product_id'] ?? $_POST['product_id'] ?? ($products[0]['id'] ?? 0));
$product = current(array_filter($products, fn($p) => (int) $p['id'] === $productId)) ?: null;
$validDate = fn(string $d): bool => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
$from = $validDate($_GET['from'] ?? '') ? $_GET['from'] : '';
$to = $validDate($_GET['to'] ?? '') ? $_GET['to'] : '';
$selfUrl = 'narcotics_register.php?' . http_build_query(array_filter(['product_id' => $productId, 'from' => $from, 'to' => $to]));
$num = fn($n): string => $n === null || $n === '' ? '' : rtrim(rtrim(number_format((float) $n, 3, '.', ''), '0'), '.');

// Inventory: the counted quantity of each lot; a difference must be justified.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['inventory'])) {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        die(tr('CSRF token validation failed'));
    }
    if (!$isAdmin || !$product) {
        $_SESSION['message'] = tr('Admin only.');
        header("Location: $selfUrl");
        exit;
    }
    mysqli_begin_transaction($con);
    try {
        $recorded = 0;
        $differences = [];
        foreach ((array) ($_POST['counted'] ?? []) as $lotId => $raw) {
            $raw = trim((string) $raw);
            if ($raw === '') {
                continue;
            }
            $counted = parse_number($raw);
            if ($counted === null || $counted < 0) {
                throw new Exception(tr('Counted quantities must be positive numbers.'));
            }
            $lock = $con->prepare('SELECT lot_number, remaining_quantity FROM medication_lots WHERE id = ? AND product_id = ? FOR UPDATE');
            $lotId = (int) $lotId;
            $lock->bind_param('ii', $lotId, $productId);
            $lock->execute();
            $lot = $lock->get_result()->fetch_assoc();
            $lock->close();
            if (!$lot) {
                throw new Exception(tr('Lot not found.'));
            }
            // The movement brings the register balance (sum of the lot's
            // movements) to the counted quantity, which also resolves a
            // register that did not match the stock.
            $sumStmt = $con->prepare('SELECT COALESCE(SUM(quantity_delta), 0) FROM medication_stock_movements WHERE medication_lot_id = ?');
            $sumStmt->bind_param('i', $lotId);
            $sumStmt->execute();
            $registerBalance = (float) $sumStmt->get_result()->fetch_row()[0];
            $sumStmt->close();
            $delta = round($counted - $registerBalance, 3);
            $stockDelta = round($counted - (float) $lot['remaining_quantity'], 3);
            $why = trim((string) ($_POST['justification'][$lotId] ?? ''));
            if ((abs($delta) >= 0.0005 || abs($stockDelta) >= 0.0005) && $why === '') {
                throw new Exception(tr('Lot {lot}: justify the difference of {delta} {unit}.', ['lot' => $lot['lot_number'], 'delta' => sprintf('%+g', abs($stockDelta) >= 0.0005 ? $stockDelta : $delta), 'unit' => $product['default_unit']]));
            }
            $reason = tr('Inventory: {counted} {unit} counted, {expected} expected', ['counted' => $num($counted), 'unit' => $product['default_unit'], 'expected' => $num($lot['remaining_quantity'])])
                . ($why !== '' ? ' — ' . $why : '');
            $mv = $con->prepare("INSERT INTO medication_stock_movements (medication_lot_id, movement_type, quantity_delta, counted_quantity, reason, performed_by) VALUES (?, 'inventory', ?, ?, ?, ?)");
            $mv->bind_param('iddsi', $lotId, $delta, $counted, $reason, $userId);
            $mv->execute();
            $mv->close();
            if (abs($stockDelta) >= 0.0005) {
                $up = $con->prepare('UPDATE medication_lots SET remaining_quantity = ? WHERE id = ?');
                $up->bind_param('di', $counted, $lotId);
                $up->execute();
                $up->close();
                $differences[] = $lot['lot_number'] . ' ' . sprintf('%+g', $stockDelta);
            } elseif (abs($delta) >= 0.0005) {
                $differences[] = tr('{lot}: register brought back to the stock ({delta})', ['lot' => $lot['lot_number'], 'delta' => sprintf('%+g', $delta)]);
            }
            $recorded++;
        }
        if (!$recorded) {
            throw new Exception(tr('Enter the quantity counted for at least one lot.'));
        }
        log_activity($con, 'narcotic_inventory', 'medication_product', (string) $productId,
            tr('Inventory of {product}: {n} lot(s) counted', ['product' => $product['name'], 'n' => $recorded]) . ($differences ? ' — ' . implode(', ', $differences) : ''), $userId);
        mysqli_commit($con);
        $_SESSION['message'] = tr('Inventory recorded ({n} lot(s)).', ['n' => $recorded]) . ($differences ? ' ' . tr('Differences applied to the stock: {list}.', ['list' => implode(', ', $differences)]) : ' ' . tr('No difference.'));
    } catch (Exception $e) {
        mysqli_rollback($con);
        $_SESSION['message'] = tr('Inventory not recorded: {error}', ['error' => $e->getMessage()]);
    }
    header("Location: $selfUrl#inventory");
    exit;
}

$lots = $movements = [];
if ($product) {
    $stmt = $con->prepare('SELECT * FROM medication_lots WHERE product_id = ? ORDER BY expiry_date, id');
    $stmt->bind_param('i', $productId);
    $stmt->execute();
    $lots = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $stmt = $con->prepare(
        "SELECT mv.*, l.lot_number, u.name AS performed_by_name,
                ma.animal_id, ma.prescription_id, ma.experiment_act_animal_id, ma.cancelled_at, ma.administered_at, ma.administered_by_name,
                m.animal_uid, m.mouse_id, e.experiment_number, xa.label AS act_label, tt.code AS terrarium_code, tt.name AS terrarium_name
         FROM medication_stock_movements mv
         JOIN medication_lots l ON l.id = mv.medication_lot_id
         LEFT JOIN users u ON u.id = mv.performed_by
         LEFT JOIN medication_administrations ma ON ma.id = mv.administration_id
         LEFT JOIN mice m ON m.id = ma.animal_id
         LEFT JOIN experiment_act_animals aa ON aa.id = ma.experiment_act_animal_id
         LEFT JOIN experiment_acts xa ON xa.id = aa.act_id
         LEFT JOIN experiments e ON e.id = xa.experiment_id
         LEFT JOIN terrarium_treatments trt ON trt.id = mv.terrarium_treatment_id
         LEFT JOIN terrariums tt ON tt.id = trt.terrarium_id
         WHERE l.product_id = ? ORDER BY mv.performed_at, mv.id"
    );
    $stmt->bind_param('i', $productId);
    $stmt->execute();
    $movements = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// Running balances over the whole history; the period only filters the rows shown.
$lotBalance = [];
$total = 0.0;
$opening = null;
$rows = [];
foreach ($movements as $mv) {
    $lotId = (int) $mv['medication_lot_id'];
    $day = substr($mv['performed_at'], 0, 10);
    if ($from !== '' && $day < $from) {
        $lotBalance[$lotId] = ($lotBalance[$lotId] ?? 0) + (float) $mv['quantity_delta'];
        $total += (float) $mv['quantity_delta'];
        continue;
    }
    $opening ??= $total;
    if ($to !== '' && $day > $to) {
        break;
    }
    $lotBalance[$lotId] = ($lotBalance[$lotId] ?? 0) + (float) $mv['quantity_delta'];
    $total += (float) $mv['quantity_delta'];
    $mv['lot_balance'] = $lotBalance[$lotId];
    $mv['total_balance'] = $total;
    $rows[] = $mv;
}
$opening ??= $total;
// The register must match the stock: the sum of each lot's movements is its remaining quantity.
$mismatches = [];
foreach ($lots as $l) {
    $sum = array_sum(array_map(fn($mv) => (float) $mv['quantity_delta'], array_filter($movements, fn($mv) => (int) $mv['medication_lot_id'] === (int) $l['id'])));
    if (abs($sum - (float) $l['remaining_quantity']) >= 0.0005) {
        $mismatches[] = tr('lot {lot}: movements {sum}, stock {stock}', ['lot' => $l['lot_number'], 'sum' => $num($sum), 'stock' => $num($l['remaining_quantity'])]);
    }
}

$typeLabels = [
    'receipt' => tr('Receipt'), 'administration' => tr('Administration'), 'adjustment' => tr('Adjustment'),
    'inventory' => tr('Inventory'), 'correction' => tr('Correction'),
];
$purpose = function (array $mv): string {
    if ($mv['terrarium_code']) {
        return tr('population treatment, terrarium {code} ({name})', ['code' => $mv['terrarium_code'], 'name' => $mv['terrarium_name']]);
    }
    if (!$mv['animal_id']) {
        return '';
    }
    $who = $mv['animal_uid'] ?: $mv['mouse_id'] ?: '#' . $mv['animal_id'];
    $what = $mv['experiment_act_animal_id']
        ? tr('experimental act {number}: {act}', ['number' => $mv['experiment_number'], 'act' => $mv['act_label']])
        : tr('prescription #{id}', ['id' => $mv['prescription_id']]);
    return $who . ' — ' . $what . ($mv['cancelled_at'] && $mv['movement_type'] === 'administration' ? ' (' . tr('cancelled') . ')' : '');
};

if ($product && ($_GET['format'] ?? '') === 'csv') {
    log_activity($con, 'export', 'narcotics_register', (string) $productId, tr('Narcotics register of {product} (CSV)', ['product' => $product['name']]), $userId);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="registre_stupefiants_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $product['name']) . '_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    $safe = fn($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'" . $v : $v;
    fputcsv($out, ['date', 'lot', 'movement', 'in', 'out', 'lot_balance', 'product_balance', 'unit', 'animal_purpose', 'reason', 'by'], ';');
    foreach ($rows as $mv) {
        $d = (float) $mv['quantity_delta'];
        fputcsv($out, [$mv['performed_at'], $mv['lot_number'], $mv['movement_type'], $d > 0 ? $num($d) : '', $d < 0 ? $num(-$d) : '',
            $num($mv['lot_balance']), $num($mv['total_balance']), $product['default_unit'],
            $safe($purpose($mv)), $safe((string) $mv['reason']), $safe((string) ($mv['performed_by_name'] ?? $mv['administered_by_name']))], ';');
    }
    fclose($out);
    exit;
}

require 'header.php';
?>
<!doctype html>
<html lang="<?= htmlspecialchars(current_locale()) ?>">

<head>
    <title><?= trh('Narcotics register') ?> | <?= htmlspecialchars($labName) ?></title>
    <style>
        .register table { font-size: .85rem; }
        @media print {
            body > *:not(.register-wrapper) { display: none !important; }
            .no-print { display: none !important; }
        }
    </style>
</head>

<body>
    <main class="container mt-4 content register-wrapper" style="max-width: 1200px">
        <div class="no-print">
            <p><a href="pharmacy_manage.php">&laquo; <?= trh('Pharmacy') ?></a></p>
            <?php include 'message.php'; ?>
        </div>
        <?php if (!$products) : ?>
            <h4><i class="fas fa-book"></i> <?= trh('Narcotics register') ?></h4>
            <div class="alert alert-secondary"><?= trh('No medication is flagged as a narcotic: tick “Narcotic” on the pharmacy page for the products concerned (e.g. ketamine).') ?></div>
        <?php else : ?>
            <form method="get" class="row g-2 align-items-end mb-3 no-print">
                <div class="col-md-4">
                    <label class="form-label" for="product_id"><?= trh('Narcotic') ?></label>
                    <select class="form-select" name="product_id" id="product_id">
                        <?php foreach ($products as $p) : ?><option value="<?= (int) $p['id'] ?>" <?= (int) $p['id'] === $productId ? 'selected' : '' ?>><?= htmlspecialchars($p['name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2"><label class="form-label" for="from"><?= trh('From (date)') ?></label><input type="date" class="form-control" name="from" id="from" value="<?= htmlspecialchars($from) ?>"></div>
                <div class="col-md-2"><label class="form-label" for="to"><?= trh('To (date)') ?></label><input type="date" class="form-control" name="to" id="to" value="<?= htmlspecialchars($to) ?>"></div>
                <div class="col-md-4">
                    <button class="btn btn-primary"><?= trh('Show') ?></button>
                    <button type="button" class="btn btn-outline-dark" onclick="window.print()"><i class="fas fa-print"></i> <?= trh('Print / PDF') ?></button>
                    <a class="btn btn-outline-secondary" href="<?= htmlspecialchars($selfUrl . (str_contains($selfUrl, '?') ? '&' : '?') . 'format=csv') ?>"><i class="fas fa-file-csv"></i> CSV</a>
                </div>
            </form>

            <?php if ($product) : ?>
                <div class="register">
                    <h4><i class="fas fa-book"></i> <?= trh('Narcotics register — {product}', ['product' => $product['name']]) ?></h4>
                    <p class="text-muted mb-2">
                        <?= htmlspecialchars(trim(($product['active_ingredient'] ?? '') . ' ' . ($product['concentration'] ? $num($product['concentration']) . ' ' . $product['concentration_unit'] : ''))) ?>
                        · <?= trh('unit: {unit}', ['unit' => $product['default_unit']]) ?>
                        · <?= trh('period: {from} → {to}', ['from' => $from ?: tr('beginning'), 'to' => $to ?: date('Y-m-d')]) ?>
                        · <?= trh('printed on {date} by {user}', ['date' => date('Y-m-d H:i'), 'user' => $_SESSION['name'] ?? '']) ?> · <?= htmlspecialchars($labName) ?>
                    </p>
                    <?php if ($mismatches) : ?>
                        <div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> <?= trh('The register does not match the stock: {list}. Record an inventory to justify the difference.', ['list' => implode(' ; ', $mismatches)]) ?></div>
                    <?php endif; ?>

                    <table class="table table-sm table-bordered">
                        <thead class="table-light">
                            <tr><th><?= trh('Lot') ?></th><th><?= trh('Expiry') ?></th><th><?= trh('Received') ?></th><th><?= trh('In use since') ?></th><th><?= trh('Stock') ?></th><th><?= trh('Last inventory') ?></th></tr>
                        </thead>
                        <?php foreach ($lots as $l) :
                            $lastInv = current(array_reverse(array_filter($movements, fn($mv) => (int) $mv['medication_lot_id'] === (int) $l['id'] && $mv['movement_type'] === 'inventory'))) ?: null; ?>
                            <tr>
                                <td><?= htmlspecialchars($l['lot_number']) ?></td>
                                <td><?= htmlspecialchars($l['expiry_date']) ?></td>
                                <td><?= htmlspecialchars($l['received_at']) ?></td>
                                <td><?= htmlspecialchars($l['in_use_since'] ?? '—') ?></td>
                                <td><strong><?= htmlspecialchars($num($l['remaining_quantity']) . ' ' . $product['default_unit']) ?></strong></td>
                                <td><?= $lastInv ? htmlspecialchars(substr($lastInv['performed_at'], 0, 16) . ' — ' . ($lastInv['performed_by_name'] ?? '')) : '<span class="text-danger">' . trh('never') . '</span>' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </table>

                    <table class="table table-sm table-bordered" id="registerRows">
                        <thead class="table-light">
                            <tr><th><?= trh('Date') ?></th><th><?= trh('Lot') ?></th><th><?= trh('Movement') ?></th><th class="text-end"><?= trh('In') ?></th><th class="text-end"><?= trh('Out') ?></th><th class="text-end"><?= trh('Lot balance') ?></th><th class="text-end"><?= trh('Total balance') ?></th><th><?= trh('Animal / purpose') ?></th><th><?= trh('Reason') ?></th><th><?= trh('By') ?></th></tr>
                        </thead>
                        <tbody>
                            <tr class="table-secondary"><td colspan="6"><?= trh('Balance at the start of the period') ?></td><td class="text-end"><strong><?= htmlspecialchars($num($opening)) ?></strong></td><td colspan="3"></td></tr>
                            <?php foreach ($rows as $mv) : $d = (float) $mv['quantity_delta']; ?>
                                <tr class="<?= $mv['movement_type'] === 'inventory' ? 'table-info' : ($mv['movement_type'] === 'correction' || $mv['cancelled_at'] ? 'table-warning' : '') ?>">
                                    <td class="text-nowrap"><?= htmlspecialchars(substr($mv['performed_at'], 0, 16)) ?></td>
                                    <td><?= htmlspecialchars($mv['lot_number']) ?></td>
                                    <td><?= htmlspecialchars($typeLabels[$mv['movement_type']] ?? $mv['movement_type']) ?></td>
                                    <td class="text-end"><?= $d > 0 ? htmlspecialchars($num($d)) : '' ?></td>
                                    <td class="text-end"><?= $d < 0 ? htmlspecialchars($num(-$d)) : '' ?></td>
                                    <td class="text-end"><?= htmlspecialchars($num($mv['lot_balance'])) ?></td>
                                    <td class="text-end"><strong><?= htmlspecialchars($num($mv['total_balance'])) ?></strong></td>
                                    <td><?= htmlspecialchars($purpose($mv)) ?></td>
                                    <td class="small"><?= htmlspecialchars($mv['reason'] ?? '') ?></td>
                                    <td class="small"><?= htmlspecialchars($mv['performed_by_name'] ?? $mv['administered_by_name'] ?? '') ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <tr class="table-secondary"><td colspan="6"><?= trh('Balance at the end of the period') ?></td><td class="text-end"><strong><?= htmlspecialchars($num($rows ? end($rows)['total_balance'] : $opening)) ?></strong></td><td colspan="3"></td></tr>
                        </tbody>
                    </table>
                    <p class="small text-muted"><?= trh('Balances are calculated over the whole history of the lots; the period only filters the rows shown.') ?></p>
                </div>

                <?php if ($isAdmin && $lots) : ?>
                    <form method="post" class="card card-body mb-4 no-print" id="inventory">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="product_id" value="<?= $productId ?>">
                        <h5><i class="fas fa-clipboard-list"></i> <?= trh('Record an inventory') ?></h5>
                        <p class="small text-muted"><?= trh('Enter the quantity physically counted for each lot checked. A difference with the theoretical stock must be justified; it is then applied to the stock and written in the register.') ?></p>
                        <table class="table table-sm align-middle">
                            <thead><tr><th><?= trh('Lot') ?></th><th><?= trh('Theoretical stock') ?></th><th><?= trh('Counted') ?> (<?= htmlspecialchars($product['default_unit']) ?>)</th><th><?= trh('Justification of a difference') ?></th></tr></thead>
                            <?php foreach ($lots as $l) : ?>
                                <tr>
                                    <td><?= htmlspecialchars($l['lot_number']) ?> <small class="text-muted">exp. <?= htmlspecialchars($l['expiry_date']) ?></small></td>
                                    <td class="theoretical" data-value="<?= htmlspecialchars($num($l['remaining_quantity'])) ?>"><?= htmlspecialchars($num($l['remaining_quantity'])) ?></td>
                                    <td style="width: 10rem"><input class="form-control form-control-sm counted" name="counted[<?= (int) $l['id'] ?>]" inputmode="decimal" pattern="[0-9]+([.,][0-9]+)?"></td>
                                    <td><input class="form-control form-control-sm justification" name="justification[<?= (int) $l['id'] ?>]" placeholder="<?= trh('e.g. broken vial, dead volume, counting error') ?>"></td>
                                </tr>
                            <?php endforeach; ?>
                        </table>
                        <div><button class="btn btn-dark" name="inventory"><?= trh('Record the inventory') ?></button></div>
                    </form>
                    <script>
                        // A difference makes the justification required.
                        document.querySelectorAll('#inventory .counted').forEach(input => input.addEventListener('input', () => {
                            const row = input.closest('tr');
                            const counted = parseFloat(input.value.replace(',', '.'));
                            const expected = parseFloat(row.querySelector('.theoretical').dataset.value);
                            row.querySelector('.justification').required = !isNaN(counted) && Math.abs(counted - expected) >= 0.0005;
                        }));
                    </script>
                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>
    </main>
    <?php require 'footer.php'; ?>
</body>

</html>
