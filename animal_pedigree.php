<?php

/**
 * Family tree of an animal (arbre généalogique): ancestors over 2 to 4
 * generations, descendants by litter over two generations, ancestors met
 * more than once and the inbreeding coefficient computed on the known
 * pedigree (pedigree_lib.php). Each animal opens its own tree. Printable.
 */

require_once 'session_config.php';
require 'dbcon.php';
require 'animal_lib.php';
require_once 'pedigree_lib.php';

if (!isset($_SESSION['username'])) {
    header('Location: index.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

$animalId = (int) ($_GET['id'] ?? 0);
$generations = max(2, min(4, (int) ($_GET['g'] ?? 3)));

$parents = pedigree_parent_map($con, [$animalId], PEDIGREE_MAX_GENERATIONS);
$tree = pedigree_ancestor_tree($parents, $animalId, $generations);
$repeated = pedigree_repeated_ancestors(pedigree_ancestor_tree($parents, $animalId, PEDIGREE_MAX_GENERATIONS));
$inbreeding = pedigree_inbreeding($parents, $animalId);
$descendants = pedigree_descendants($con, $animalId, 2);
$animals = pedigree_animals($con, array_merge([$animalId], array_keys($parents), pedigree_descendant_ids($descendants)));
$animal = $animals[$animalId] ?? null;

require 'header.php';
if (!$animal) {
    echo "<div class='container mt-4'><div class='alert alert-danger'>" . trh('Animal not found.') . "</div></div>";
    require 'footer.php';
    exit;
}

// Known generations above the animal.
$depth = function (array $node) use (&$depth): int {
    $d = 0;
    foreach (['sire', 'dam'] as $side) {
        if ($node[$side] && $node[$side]['id'] !== null) {
            $d = max($d, 1 + $depth($node[$side]));
        }
    }
    return $d;
};
$knownGenerations = $depth(pedigree_ancestor_tree($parents, $animalId, PEDIGREE_MAX_GENERATIONS));
$label = fn(array $a): string => (string) ($a['animal_uid'] ?: $a['mouse_id'] ?: '#' . $a['id']);
$colors = ['#fde2e4', '#e2ece9', '#fff1c1', '#dfe7fd', '#e8dff5', '#fce1c8'];
$repeatColor = [];
foreach (array_keys($repeated) as $i => $id) {
    $repeatColor[$id] = $colors[$i % count($colors)];
}

/** Card of one animal in the tree; $role: 'Sire' / 'Dam' / ''. */
$card = function (?int $id, string $role = '', bool $root = false) use ($animals, $label, $repeated, $repeatColor, $generations): string {
    if ($id === null || !isset($animals[$id])) {
        return '<div class="ped-card ped-unknown">' . ($role ? '<div class="ped-role">' . htmlspecialchars(tr($role)) . '</div>' : '')
            . '<span class="text-muted">' . trh('Unknown / external origin') . '</span></div>';
    }
    $a = $animals[$id];
    $sex = $a['sex'] === 'male' ? '♂' : ($a['sex'] === 'female' ? '♀' : '');
    $active = $a['status'] === 'active';
    $style = isset($repeatColor[$id]) ? ' style="background:' . $repeatColor[$id] . '"' : '';
    return '<div class="ped-card' . ($root ? ' ped-root' : '') . ($active ? '' : ' ped-exited') . '"' . $style . '>'
        . ($role ? '<div class="ped-role">' . htmlspecialchars(tr($role)) . '</div>' : '')
        . '<a class="ped-uid" href="animal_pedigree.php?id=' . $id . '&amp;g=' . $generations . '">' . htmlspecialchars($label($a)) . '</a> ' . $sex
        . (isset($repeated[$id]) ? ' <span class="badge bg-dark" title="' . trh('Ancestor met several times: inbreeding') . '">×' . $repeated[$id] . '</span>' : '')
        . '<div class="ped-meta">' . htmlspecialchars(trim(($a['line_code'] ?? '') . ($a['dob'] ? ' · ' . tr('born {date}', ['date' => $a['dob']]) : ''), ' ·')) . '</div>'
        . '<div class="ped-meta">' . ($active
            ? htmlspecialchars(tr('cage {cage}', ['cage' => $a['cage_id'] ?? '—']))
            : '<span class="badge bg-secondary">' . trh('left the facility') . '</span> ' . htmlspecialchars((string) $a['exit_date']))
        . ' · <a href="animal_view.php?id=' . $id . '">' . trh('record') . '</a></div>'
        . '</div>';
};

// Ancestors, as nested blocks: the animal, then its sire above its dam.
$branch = function (array $node, string $role = '', bool $root = false) use (&$branch, $card): string {
    $html = '<div class="ped-branch">' . $card($node['id'], $role, $root);
    if ($node['sire'] !== null || $node['dam'] !== null) {
        $html .= '<div class="ped-parents">'
            . '<div class="ped-parent">' . $branch($node['sire'] ?? ['id' => null, 'sire' => null, 'dam' => null], 'Sire') . '</div>'
            . '<div class="ped-parent">' . $branch($node['dam'] ?? ['id' => null, 'sire' => null, 'dam' => null], 'Dam') . '</div>'
            . '</div>';
    }
    return $html . '</div>';
};

// Descendants by litter.
$offspring = function (array $groups) use (&$offspring, $card, $animals, $label): string {
    $html = '';
    foreach ($groups as $g) {
        $title = $g['litter']
            ? tr('Litter #{id}, born {date}', ['id' => $g['litter']['id'], 'date' => $g['litter']['dob'] ?? '—'])
            : tr('Offspring recorded without a litter');
        $partner = $g['partner'] && isset($animals[$g['partner']]) ? ' — ' . tr('with {partner}', ['partner' => $label($animals[$g['partner']])]) : '';
        $html .= '<div class="ped-litter"><div class="ped-litter-title">' . ($g['litter'] ? '<a href="litter_manage.php?litter_id=' . (int) $g['litter']['id'] . '">' . htmlspecialchars($title) . '</a>' : htmlspecialchars($title))
            . htmlspecialchars($partner) . ' <span class="text-muted">(' . htmlspecialchars(tr('{n} animal(s)', ['n' => count($g['children'])])) . ')</span></div>'
            . '<div class="ped-children">';
        foreach ($g['children'] as $c) {
            $html .= $card($c['id']);
        }
        $html .= '</div>';
        // Then, below the litter, the descendants of each of its animals; a
        // litter of two of them (siblings mated) is shown once.
        $shown = [];
        foreach ($g['children'] as $c) {
            $c['descendants'] = array_values(array_filter($c['descendants'], function ($d) use (&$shown) {
                $key = $d['litter']['id'] ?? null;
                if ($key !== null && isset($shown[$key])) {
                    return false;
                }
                return $shown[$key ?? uniqid()] = true;
            }));
            if ($c['descendants']) {
                $html .= '<div class="ped-grandchildren"><div class="ped-sub-title">'
                    . htmlspecialchars(tr('Descendants of {animal}', ['animal' => isset($animals[$c['id']]) ? $label($animals[$c['id']]) : '#' . $c['id']]))
                    . '</div>' . $offspring($c['descendants']) . '</div>';
            }
        }
        $html .= '</div>';
    }
    return $html;
};
$pct = rtrim(rtrim(number_format($inbreeding * 100, 2, ',', ''), '0'), ',');
?>
<!doctype html>
<html lang="<?= htmlspecialchars(current_locale()) ?>">
<head>
    <title><?= trh('Family tree') ?> — <?= htmlspecialchars($label($animal)) ?> | <?= htmlspecialchars($labName) ?></title>
    <style>
        .ped-scroll { overflow-x: auto; padding-bottom: .5rem; }
        .ped-branch { display: flex; align-items: center; }
        .ped-parents { display: flex; flex-direction: column; margin-left: 28px; position: relative; }
        .ped-parent { position: relative; padding: 6px 0 6px 18px; }
        /* Connectors: a vertical line between sire and dam, a stub to each. */
        .ped-parent::before { content: ""; position: absolute; left: 0; width: 18px; top: 50%; border-top: 2px solid #9fb7c6; }
        .ped-parent:first-child::after { content: ""; position: absolute; left: 0; top: 50%; bottom: 0; border-left: 2px solid #9fb7c6; }
        .ped-parent:last-child::after { content: ""; position: absolute; left: 0; top: 0; height: 50%; border-left: 2px solid #9fb7c6; }
        .ped-parents::before { content: ""; position: absolute; left: -28px; width: 28px; top: 50%; border-top: 2px solid #9fb7c6; }
        .ped-card { min-width: 168px; max-width: 210px; border: 1px solid #c9d6df; border-radius: 8px; padding: 6px 9px; background: #fff; font-size: .82rem; line-height: 1.3; }
        .ped-root { border: 2px solid #17a398; box-shadow: 0 2px 8px rgba(23, 163, 152, .25); }
        .ped-exited { background: #f4f5f6; }
        .ped-unknown { border-style: dashed; background: #fafafa; }
        .ped-role { font-size: .7rem; text-transform: uppercase; letter-spacing: .04em; color: #6c7a86; }
        .ped-uid { font-weight: 600; font-size: .95rem; }
        .ped-meta { color: #55626d; }
        .ped-litter { border-left: 3px solid #17a398; padding: 4px 0 4px 12px; margin: 10px 0; }
        .ped-litter-title { font-weight: 600; margin-bottom: 6px; }
        .ped-children { display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-start; }
        .ped-grandchildren { margin: 10px 0 0 24px; }
        .ped-sub-title { font-size: .85rem; color: #55626d; }
        @media print {
            body > *:not(.ped-wrapper) { display: none !important; }
            .no-print { display: none !important; }
            .ped-scroll { overflow: visible; }
        }
    </style>
</head>
<body>
    <main class="container-fluid mt-4 content ped-wrapper" style="max-width: 1400px">
        <div class="d-flex justify-content-between flex-wrap gap-2 align-items-start">
            <div>
                <h4><i class="fas fa-sitemap"></i> <?= trh('Family tree') ?> — <?= htmlspecialchars($label($animal)) ?></h4>
                <p class="text-muted mb-2"><?= htmlspecialchars(trim(($animal['line_code'] ?? '') . ($animal['dob'] ? ' · ' . tr('born {date}', ['date' => $animal['dob']]) : ''), ' ·')) ?></p>
            </div>
            <div class="no-print d-flex gap-2 align-items-center">
                <form method="GET" class="d-flex gap-1 align-items-center">
                    <input type="hidden" name="id" value="<?= $animalId ?>">
                    <label class="small text-muted" for="g"><?= trh('Generations') ?></label>
                    <select class="form-select form-select-sm" id="g" name="g" onchange="this.form.submit()" style="width: auto">
                        <?php foreach ([2, 3, 4] as $n) : ?><option value="<?= $n ?>" <?= $n === $generations ? 'selected' : '' ?>><?= $n ?></option><?php endforeach; ?>
                    </select>
                </form>
                <a class="btn btn-outline-secondary btn-sm" href="animal_view.php?id=<?= $animalId ?>"><?= trh('Animal record') ?></a>
                <button class="btn btn-outline-dark btn-sm" onclick="window.print()"><i class="fas fa-print"></i> <?= trh('Print / PDF') ?></button>
            </div>
        </div>

        <div class="alert <?= $inbreeding > 0 ? 'alert-warning' : 'alert-light border' ?> py-2" id="inbreeding">
            <strong><?= trh('Inbreeding coefficient:') ?> <?= $pct ?> %</strong>
            — <?= $knownGenerations
                ? trh('computed on {n} known generation(s); an unknown parent counts as unrelated, so it is a minimum.', ['n' => min($knownGenerations, PEDIGREE_MAX_GENERATIONS)])
                : trh('no known parent: the coefficient cannot be assessed.') ?>
            <?php if ($repeated) : ?>
                <br><span class="small"><?= trh('Ancestors met several times (same colour in the tree):') ?>
                <?php foreach ($repeated as $id => $n) : ?>
                    <span class="badge text-dark" style="background: <?= $repeatColor[$id] ?>"><?= htmlspecialchars(isset($animals[$id]) ? $label($animals[$id]) : '#' . $id) ?> ×<?= $n ?></span>
                <?php endforeach; ?></span>
            <?php endif; ?>
        </div>

        <h5><?= trh('Ancestry') ?></h5>
        <div class="ped-scroll mb-4" id="ancestry"><?= $branch($tree, '', true) ?></div>

        <h5><?= trh('Descendants') ?></h5>
        <div id="descendants">
            <?php if ($descendants) : ?>
                <?= $offspring($descendants) ?>
            <?php else : ?>
                <p class="text-muted"><?= trh('No offspring recorded.') ?></p>
            <?php endif; ?>
        </div>
        <p class="small text-muted mt-3"><?= trh('Built from the parentage recorded at weaning (sire and dam of each litter). Animals bought or imported have no recorded parents.') ?></p>
    </main>
    <?php require 'footer.php'; ?>
</body>
</html>
