<?php
/**
 * Sections shared by every cage page (holding, breeding, experimental), so
 * all cage categories present their location and animals the same way.
 * Requires animal_lib.php and an open session with a CSRF token.
 */

// Room / rack of the cage, with an inline form to move it to another room and,
// for non-breeding cages, a button to switch holding <-> experimental.
function render_cage_location(mysqli $con, string $cageId, string $returnUrl): void
{
    $stmt = $con->prepare("SELECT c.room, c.rack, c.status, " . CAGE_KIND_SQL . " AS kind FROM cages c WHERE c.cage_id = ?");
    $stmt->bind_param("s", $cageId);
    $stmt->execute();
    $cage = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$cage) {
        return;
    }
    $rooms = array_column($con->query("SELECT DISTINCT room FROM cages WHERE room IS NOT NULL AND room <> '' ORDER BY room")->fetch_all(MYSQLI_ASSOC), 'room');
    $active = $cage['status'] === 'active';
    $token = htmlspecialchars($_SESSION['csrf_token'] ?? '');
    ?>
    <div class="card mb-3">
        <div class="card-body d-flex flex-wrap align-items-center gap-3">
            <div><strong>Catégorie :</strong> <?= htmlspecialchars(CAGE_KINDS[$cage['kind']][0]); ?></div>
            <div><strong>Salle :</strong> <?= htmlspecialchars($cage['room'] ?: '—'); ?></div>
            <div><strong>Rack :</strong> <?= htmlspecialchars($cage['rack'] ?: '—'); ?></div>
            <?php if ($active) : ?>
                <form method="post" action="cage_location.php" class="d-flex flex-wrap align-items-center gap-2 ms-md-auto">
                    <input type="hidden" name="csrf_token" value="<?= $token; ?>">
                    <input type="hidden" name="return" value="<?= htmlspecialchars($returnUrl); ?>">
                    <input type="hidden" name="cage_ids[]" value="<?= htmlspecialchars($cageId); ?>">
                    <input type="text" class="form-control form-control-sm" style="width:9rem" name="room" list="cageRoomList" placeholder="Nouvelle salle" aria-label="Nouvelle salle">
                    <input type="text" class="form-control form-control-sm" style="width:7rem" name="rack" placeholder="Rack" aria-label="Rack">
                    <button type="submit" name="location_action" value="move" class="btn btn-outline-primary btn-sm"><i class="fas fa-door-open"></i> Changer de salle</button>
                    <?php if ($cage['kind'] === 'holding') : ?>
                        <button type="submit" name="location_action" value="experimental" class="btn btn-outline-warning btn-sm">Classer en expérimentation</button>
                    <?php elseif ($cage['kind'] === 'experimental') : ?>
                        <button type="submit" name="location_action" value="holding" class="btn btn-outline-secondary btn-sm">Classer en hébergement</button>
                    <?php endif; ?>
                    <datalist id="cageRoomList">
                        <?php foreach ($rooms as $r) : ?><option value="<?= htmlspecialchars($r); ?>"><?php endforeach; ?>
                    </datalist>
                </form>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

// Animals of the cage: living ones by default, exited ones on request
// (?show_exited=1), with their open health notes and quick actions.
function render_cage_animals(mysqli $con, string $cageId, string $viewPage): void
{
    $stmt = $con->prepare("SELECT m.*, al.code AS line_code FROM mice m LEFT JOIN animal_lines al ON al.id = m.line_id
        WHERE m.cage_id = ? ORDER BY m.status <> 'active', m.sex, m.animal_uid");
    $stmt->bind_param("s", $cageId);
    $stmt->execute();
    $mice = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $showExited = !empty($_GET['show_exited']);
    $exitedCount = count(array_filter($mice, fn($m) => $m['status'] !== 'active'));
    if (!$showExited) {
        $mice = array_values(array_filter($mice, fn($m) => $m['status'] === 'active'));
    }

    // Open health notes per animal (a report's state is its latest update).
    $healthNotes = [];
    $ids = array_map('intval', array_column($mice, 'id'));
    if ($ids) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $h = $con->prepare("SELECT r.id, r.animal_id, r.category, r.description, u.status AS current_status, u.severity AS current_severity, u.note AS latest_note
            FROM health_reports r
            JOIN health_report_updates u ON u.id = (SELECT MAX(u2.id) FROM health_report_updates u2 WHERE u2.report_id = r.id)
            WHERE r.animal_id IN ($in) AND u.status <> 'resolved'
            ORDER BY r.animal_id, u.created_at DESC, r.id DESC");
        $h->bind_param(str_repeat('i', count($ids)), ...$ids);
        $h->execute();
        foreach ($h->get_result()->fetch_all(MYSQLI_ASSOC) as $note) {
            $healthNotes[(int) $note['animal_id']][] = $note;
        }
        $h->close();
    }
    $base = $viewPage . '?id=' . rawurlencode($cageId);
    ?>
    <div class="card mt-4">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center">
            <h4 class="mb-0">Animaux</h4>
            <?php if ($exitedCount > 0) : ?>
                <span class="small text-muted">
                    <?php if ($showExited) : ?>
                        Y compris <?= $exitedCount; ?> sorti(s) — <a href="<?= htmlspecialchars($base); ?>">vivants uniquement</a>
                    <?php else : ?>
                        Vivants uniquement — <?= $exitedCount; ?> sorti(s) masqué(s) · <a href="<?= htmlspecialchars($base . '&show_exited=1'); ?>">afficher les sortis</a>
                    <?php endif; ?>
                </span>
            <?php endif; ?>
        </div>
        <div class="table-responsive">
            <table class="table table-bordered table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Animal</th>
                        <th>Sexe</th>
                        <th>Lignée</th>
                        <th>Marque distinctive</th>
                        <th>Notes de santé</th>
                        <th>Notes</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($mice as $m) : $alive = $m['status'] === 'active'; ?>
                        <tr class="<?= $alive ? '' : 'table-secondary'; ?>">
                            <td>
                                <a href="animal_view.php?id=<?= (int) $m['id']; ?>"><?= htmlspecialchars($m['animal_uid'] ?: ($m['mouse_id'] ?: '#' . $m['id'])); ?></a>
                                <?php if (!$alive) : ?><span class="badge bg-secondary"><?= htmlspecialchars(exit_label($m['exit_code'] ?? null, $m['status'])); ?></span><?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars(['male' => 'Mâle', 'female' => 'Femelle'][$m['sex'] ?? ''] ?? '—'); ?></td>
                            <td><?= htmlspecialchars($m['line_code'] ?? ''); ?></td>
                            <td><?= htmlspecialchars($m['distinctive_mark'] ?? ''); ?></td>
                            <td class="small">
                                <?php foreach ($healthNotes[(int) $m['id']] ?? [] as $note) : ?>
                                    <div class="mb-1">
                                        <a href="health_report_view.php?id=<?= (int) $note['id']; ?>" class="fw-bold text-decoration-none"><?= htmlspecialchars(health_categories()[$note['category']] ?? $note['category']); ?></a>
                                        <?= health_badge($note['current_status'], HEALTH_STATUSES); ?>
                                        <?= health_badge($note['current_severity'], HEALTH_SEVERITIES); ?>
                                        <div><?= nl2br(htmlspecialchars($note['latest_note'] ?: $note['description'])); ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </td>
                            <td class="small"><?= htmlspecialchars($m['notes'] ?? ''); ?></td>
                            <td class="text-nowrap">
                                <?php if ($alive) : ?>
                                    <a href="animal_transfer.php?animal_id=<?= (int) $m['id']; ?>" class="btn btn-sm btn-outline-primary" title="Changer de cage"><i class="fas fa-exchange-alt"></i></a>
                                    <a href="health_report.php?<?= htmlspecialchars(http_build_query(['animal_id' => $m['id'], 'via' => 'cage_page', 'return' => $base])); ?>" class="btn btn-sm btn-outline-warning" title="Note de santé"><i class="fas fa-notes-medical"></i></a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$mice) : ?>
                        <tr><td colspan="7" class="text-muted">Aucun animal vivant dans cette cage.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
}

// Breeding cage: litters still nursing (not weaned yet), at the top of the
// cage page, with their age, live pups and expected weaning (overdue in red).
function render_current_litters(mysqli $con, string $cageId): void
{
    $litters = db_rows($con, "SELECT * FROM litters WHERE cage_id = ? AND status = 'nursing' ORDER BY expected_weaning_date IS NULL, expected_weaning_date, litter_dob", 's', [$cageId]);
    $today = date('Y-m-d');
    foreach ($litters as &$l) {
        $l['alive_now'] = litter_alive_now($con, $l); // born alive minus the deaths recorded since
    }
    unset($l);
    $pups = array_sum(array_column($litters, 'alive_now'));
    ?>
    <div class="card mb-3 <?= $litters ? 'border-success' : '' ?>" id="currentLitters">
        <div class="card-body py-2">
            <h5 class="card-title d-flex justify-content-between flex-wrap gap-2 mb-2">
                <span><i class="fas fa-baby"></i> <?= trh('Litters being nursed') ?>
                    <?php if ($litters) : ?><span class="badge bg-success"><?= trh('{n} litter(s), {pups} pup(s) not weaned', ['n' => count($litters), 'pups' => $pups]) ?></span><?php endif; ?></span>
                <a href="litter_manage.php?cage_id=<?= rawurlencode($cageId) ?>" class="btn btn-sm btn-success"><i class="fas fa-plus"></i> <?= trh('Litters: birth → weaning') ?></a>
            </h5>
            <?php if (!$litters) : ?>
                <p class="text-muted mb-0"><?= trh('No litter being nursed in this cage.') ?></p>
            <?php else : ?>
                <p class="small text-muted mb-2"><?= trh('Pups not weaned are not identified yet: they are not counted among the living animals of the cage below.') ?></p>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light"><tr><th><?= trh('Born on') ?></th><th><?= trh('Age') ?></th><th><?= trh('Pups Alive') ?></th><th><?= trh('Weaning expected') ?></th><th></th></tr></thead>
                        <tbody>
                            <?php foreach ($litters as $l) :
                                $due = $l['expected_weaning_date'];
                                $overdue = $due !== null && $due < $today;
                                $age = $l['litter_dob'] ? (int) (new DateTime($l['litter_dob']))->diff(new DateTime($today))->days : null;
                                ?>
                                <tr class="<?= $overdue ? 'table-danger' : ($due === $today ? 'table-warning' : '') ?>">
                                    <td class="text-nowrap"><?= htmlspecialchars($l['litter_dob'] ?? '—') ?></td>
                                    <td class="text-nowrap"><?= $age !== null ? trh('{n} days', ['n' => $age]) : '—' ?></td>
                                    <td><?= (int) $l['alive_now'] ?><?= $l['pups_male'] !== null || $l['pups_female'] !== null ? ' <span class="small text-muted">(' . trh('{m} M, {f} F', ['m' => $l['pups_male'] ?? '?', 'f' => $l['pups_female'] ?? '?']) . ')</span>' : '' ?></td>
                                    <td class="text-nowrap <?= $overdue ? 'text-danger fw-bold' : '' ?>">
                                        <?= htmlspecialchars($due ?? '—') ?>
                                        <?php if ($overdue) : ?><span class="badge bg-danger"><?= trh('overdue') ?></span><?php elseif ($due === $today) : ?><span class="badge bg-warning text-dark"><?= trh('today') ?></span><?php endif; ?>
                                    </td>
                                    <td class="text-nowrap"><a href="litter_manage.php?litter_id=<?= (int) $l['id'] ?>" class="btn btn-sm btn-outline-success"><?= trh('Wean / update') ?></a></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

// Breeding cage: litters already weaned or closed, most recent first, folded.
function render_litter_history(mysqli $con, string $cageId): void
{
    $litters = db_rows($con, "SELECT * FROM litters WHERE cage_id = ? AND status <> 'nursing' ORDER BY litter_dob IS NULL, litter_dob DESC, id DESC", 's', [$cageId]);
    ?>
    <div class="card mt-4" id="litterHistory">
        <div class="card-header"><h4 class="mb-0"><?= trh('Previous litters') ?></h4></div>
        <div class="card-body">
            <?php if (!$litters) : ?>
                <p class="text-muted mb-0"><?= trh('No weaned or closed litter yet.') ?></p>
            <?php else : ?>
                <details>
                    <summary><?= trh('{n} litter(s) weaned or closed', ['n' => count($litters)]) ?></summary>
                    <div class="table-responsive mt-2">
                        <table class="table table-sm table-bordered">
                            <thead class="table-light"><tr><th><?= trh('Litter DOB') ?></th><th><?= trh('Status') ?></th><th><?= trh('Pups Alive') ?></th><th><?= trh('Pups Dead') ?></th><th><?= trh('Weaned') ?></th><th><?= trh('Remarks') ?></th></tr></thead>
                            <tbody>
                                <?php foreach ($litters as $l) : ?>
                                    <tr>
                                        <td class="text-nowrap"><a href="litter_manage.php?litter_id=<?= (int) $l['id'] ?>"><?= htmlspecialchars($l['litter_dob'] ?? '—') ?></a></td>
                                        <td><?= trh((string) $l['status']) ?><?= $l['status'] === 'closed' && $l['closed_reason'] ? ' <span class="small text-muted">— ' . htmlspecialchars($l['closed_reason']) . '</span>' : '' ?></td>
                                        <td><?= (int) $l['pups_alive'] ?></td>
                                        <td><?= (int) $l['pups_dead'] ?></td>
                                        <td class="text-nowrap"><?= $l['weaned_at'] ? htmlspecialchars($l['weaned_at']) . ' — ' . trh('{m} M, {f} F', ['m' => (int) $l['weaned_males'], 'f' => (int) $l['weaned_females']]) : '—' ?></td>
                                        <td class="small"><?= htmlspecialchars($l['remarks'] ?? '') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </details>
            <?php endif; ?>
        </div>
    </div>
    <?php
}
