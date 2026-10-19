<?php

/**
 * Family tree of an animal from the recorded parentage (animal_parentage:
 * sire and dam of each animal born in the facility): ancestors over a few
 * generations, descendants grouped by litter, ancestors met more than once
 * and the inbreeding coefficient (Wright), computed with the kinship
 * (coancestry) recursion on the known pedigree: an unknown parent counts as
 * unrelated, so the value is a minimum. The dilution rate of an ancestor in
 * a descendant is its expected share of the descendant's genome: 1/2 per
 * generation, added over every path linking them. Requires db_lib.php.
 */

const PEDIGREE_MAX_GENERATIONS = 6;

// Display data of animals by id: uid, sex, dob, line, status, cage, mark.
function pedigree_animals(mysqli $con, array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) {
        return [];
    }
    $rows = db_rows(
        $con,
        'SELECT m.id, m.animal_uid, m.mouse_id, m.sex, m.dob, m.status, m.exit_date, m.cage_id, m.genotype, m.distinctive_mark, m.origin_type,
                al.code AS line_code
         FROM mice m LEFT JOIN animal_lines al ON al.id = m.line_id WHERE m.id IN (' . db_placeholders(count($ids)) . ')',
        str_repeat('i', count($ids)),
        $ids
    );
    $out = [];
    foreach ($rows as $r) {
        $out[(int) $r['id']] = $r;
    }
    return $out;
}

/**
 * Known parents of the animals and of their ancestors, over $generations:
 * [id => ['sire' => ?int, 'dam' => ?int, 'litter' => ?int]].
 */
function pedigree_parent_map(mysqli $con, array $ids, int $generations): array
{
    $map = [];
    $level = array_values(array_unique(array_filter(array_map('intval', $ids))));
    for ($g = 0; $g < $generations && $level; $g++) {
        $todo = array_values(array_diff($level, array_keys($map)));
        foreach ($todo as $id) {
            $map[$id] = ['sire' => null, 'dam' => null, 'litter' => null];
        }
        if (!$todo) {
            break;
        }
        $next = [];
        foreach (db_rows($con, 'SELECT animal_id, sire_id, dam_id, litter_id FROM animal_parentage WHERE animal_id IN (' . db_placeholders(count($todo)) . ')', str_repeat('i', count($todo)), $todo) as $r) {
            $sire = $r['sire_id'] !== null ? (int) $r['sire_id'] : null;
            $dam = $r['dam_id'] !== null ? (int) $r['dam_id'] : null;
            $map[(int) $r['animal_id']] = ['sire' => $sire, 'dam' => $dam, 'litter' => $r['litter_id'] !== null ? (int) $r['litter_id'] : null];
            array_push($next, ...array_filter([$sire, $dam]));
        }
        $level = $next;
    }
    return $map;
}

/**
 * Ancestor tree: ['id' => ?int, 'sire' => node|null, 'dam' => node|null],
 * down to $generations (1 = the parents).
 */
function pedigree_ancestor_tree(array $parents, ?int $id, int $generations): array
{
    $node = ['id' => $id, 'sire' => null, 'dam' => null];
    if ($id !== null && $generations > 0) {
        $node['sire'] = pedigree_ancestor_tree($parents, $parents[$id]['sire'] ?? null, $generations - 1);
        $node['dam'] = pedigree_ancestor_tree($parents, $parents[$id]['dam'] ?? null, $generations - 1);
    }
    return $node;
}

// Ids of the known ancestors in a tree (with repetitions), the root excluded.
function pedigree_tree_ancestors(array $node): array
{
    $ids = [];
    foreach (['sire', 'dam'] as $side) {
        if ($node[$side] && $node[$side]['id'] !== null) {
            $ids[] = $node[$side]['id'];
            array_push($ids, ...pedigree_tree_ancestors($node[$side]));
        }
    }
    return $ids;
}

/** Ancestors appearing more than once in the tree: [id => times]. */
function pedigree_repeated_ancestors(array $tree): array
{
    return array_filter(array_count_values(pedigree_tree_ancestors($tree)), fn($n) => $n > 1);
}

/**
 * Inbreeding coefficient of $id (0 to 1) from the known pedigree in
 * $parents: the kinship of its sire and dam. Kinship f(a, b): f(a, a) =
 * (1 + F(a)) / 2; otherwise the one of the two that is not an ancestor of the
 * other is replaced by its parents: f(a, b) = (f(a, sire b) + f(a, dam b)) / 2.
 */
function pedigree_inbreeding(array $parents, int $id): float
{
    $memo = [];
    $ancestors = [];
    $ancestorsOf = function (int $x) use (&$ancestorsOf, &$ancestors, $parents): array {
        if (!isset($ancestors[$x])) {
            $ancestors[$x] = [];
            foreach ([$parents[$x]['sire'] ?? null, $parents[$x]['dam'] ?? null] as $p) {
                if ($p !== null) {
                    $ancestors[$x][$p] = true;
                    $ancestors[$x] += $ancestorsOf($p);
                }
            }
        }
        return $ancestors[$x];
    };
    $kinship = function (?int $a, ?int $b) use (&$kinship, &$memo, $parents, $ancestorsOf): float {
        if ($a === null || $b === null) {
            return 0.0;
        }
        if ($a === $b) {
            return (1 + $kinship($parents[$a]['sire'] ?? null, $parents[$a]['dam'] ?? null)) / 2;
        }
        $key = min($a, $b) . '-' . max($a, $b);
        if (!isset($memo[$key])) {
            // Expand the one that is not an ancestor of the other.
            [$keep, $expand] = isset($ancestorsOf($b)[$a]) ? [$a, $b] : [$b, $a];
            $memo[$key] = ($kinship($keep, $parents[$expand]['sire'] ?? null) + $kinship($keep, $parents[$expand]['dam'] ?? null)) / 2;
        }
        return $memo[$key];
    };
    return $kinship($parents[$id]['sire'] ?? null, $parents[$id]['dam'] ?? null);
}

/**
 * Descendants of $id over $generations, by litter (or by partner for
 * offspring recorded without a litter): [['litter' => row|null,
 * 'partner' => ?int, 'children' => [['id' => int, 'descendants' => [...]]]]].
 */
function pedigree_descendants(mysqli $con, int $id, int $generations): array
{
    if ($generations <= 0) {
        return [];
    }
    $rows = db_rows(
        $con,
        'SELECT ap.animal_id, ap.litter_id, ap.sire_id, ap.dam_id, l.litter_dob
         FROM animal_parentage ap LEFT JOIN litters l ON l.id = ap.litter_id
         WHERE ap.sire_id = ? OR ap.dam_id = ?
         ORDER BY l.litter_dob, ap.litter_id, ap.animal_id',
        'ii',
        [$id, $id]
    );
    $groups = [];
    foreach ($rows as $r) {
        $partner = (int) $r['sire_id'] === $id ? $r['dam_id'] : $r['sire_id'];
        $key = $r['litter_id'] !== null ? 'L' . $r['litter_id'] : 'P' . ($partner ?? 0);
        $groups[$key] ??= ['litter' => $r['litter_id'] !== null ? ['id' => (int) $r['litter_id'], 'dob' => $r['litter_dob']] : null,
            'partner' => $partner !== null ? (int) $partner : null, 'children' => []];
        $groups[$key]['children'][] = ['id' => (int) $r['animal_id'], 'descendants' => pedigree_descendants($con, (int) $r['animal_id'], $generations - 1)];
    }
    return array_values($groups);
}

// Ids of every animal of a descendants list (children and partners).
function pedigree_descendant_ids(array $groups): array
{
    $ids = [];
    foreach ($groups as $g) {
        if ($g['partner']) {
            $ids[] = $g['partner'];
        }
        foreach ($g['children'] as $c) {
            $ids[] = $c['id'];
            array_push($ids, ...pedigree_descendant_ids($c['descendants']));
        }
    }
    return $ids;
}

/**
 * Expected share of each known ancestor in the genome of $id (dilution
 * rate): [ancestor id => ['share' => float, 'paths' => int, 'generations' =>
 * [n, ...]]], largest share first. A parent gives 1/2, each further
 * generation halves it; an ancestor reached by several paths (inbreeding)
 * adds the shares of each path.
 */
function pedigree_ancestor_shares(array $parents, int $id): array
{
    $shares = [];
    $walk = function (int $x, int $n) use (&$walk, &$shares, $parents): void {
        foreach ([$parents[$x]['sire'] ?? null, $parents[$x]['dam'] ?? null] as $p) {
            if ($p === null) {
                continue;
            }
            $shares[$p] ??= ['share' => 0.0, 'paths' => 0, 'generations' => []];
            $shares[$p]['share'] += 0.5 ** $n;
            $shares[$p]['paths']++;
            $shares[$p]['generations'][$n] = $n;
            $walk($p, $n + 1);
        }
    };
    $walk($id, 1);
    uasort($shares, fn($a, $b) => [$b['share'], min($a['generations'])] <=> [$a['share'], min($b['generations'])]);
    foreach ($shares as &$s) {
        $s['generations'] = array_values($s['generations']);
        sort($s['generations']);
    }
    return $shares;
}

// Dilution rate of $ancestor in $descendant (0 when not an ancestor).
function pedigree_dilution(array $parents, int $ancestor, int $descendant): float
{
    return pedigree_ancestor_shares($parents, $descendant)[$ancestor]['share'] ?? 0.0;
}

// A share as a fraction of a power of two ("3/8"), or "" when not exact.
function pedigree_fraction(float $share): string
{
    for ($d = 1; $d <= 1024; $d *= 2) {
        $n = $share * $d;
        if (abs($n - round($n)) < 1e-9) {
            return (int) round($n) . '/' . $d;
        }
    }
    return '';
}

// A share as a percentage: "25 %", "12,5 %", "0,39 %".
function pedigree_percent(float $share): string
{
    return rtrim(rtrim(number_format($share * 100, 2, ',', ''), '0'), ',') . ' %';
}
