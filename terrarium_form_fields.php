<?php

/**
 * Fields of the terrarium form (creation in terrarium_dash.php, edition in
 * terrarium_view.php). Expects $t (row or null), $lines, $users, $rooms.
 */

if (!isset($lines, $users, $rooms)) {
    http_response_code(404);
    exit;
}
$v = fn(string $k, $default = '') => htmlspecialchars((string) ($t[$k] ?? $default));
?>
<div class="row g-2">
    <div class="col-md-2"><label class="form-label small"><?= trh('Code') ?> *</label><input class="form-control form-control-sm" name="code" maxlength="30" required value="<?= $v('code') ?>"></div>
    <div class="col-md-4"><label class="form-label small"><?= trh('Name') ?> *</label><input class="form-control form-control-sm" name="name" maxlength="255" required value="<?= $v('name') ?>"></div>
    <div class="col-md-3"><label class="form-label small"><?= trh('Line') ?></label>
        <select class="form-select form-select-sm" name="line_id">
            <option value=""><?= trh('— none —') ?></option>
            <?php foreach ($lines as $l) : ?><option value="<?= (int) $l['id'] ?>" <?= (int) ($t['line_id'] ?? 0) === (int) $l['id'] ? 'selected' : '' ?>><?= htmlspecialchars($l['code'] . ' — ' . $l['name'] . ' (' . $l['species'] . ')') ?></option><?php endforeach; ?>
        </select>
        <div class="form-text"><?= trh('Captured animals take their identifiers from the line.') ?></div>
    </div>
    <div class="col-md-3"><label class="form-label small"><?= trh('Species (if no line)') ?></label><input class="form-control form-control-sm" name="species" maxlength="100" value="<?= $v('species') ?>"></div>
    <div class="col-md-2"><label class="form-label small"><?= trh('Room') ?></label><input class="form-control form-control-sm" name="room" list="terrariumRooms" maxlength="100" value="<?= $v('room') ?>"></div>
    <div class="col-md-2"><label class="form-label small"><?= trh('Location') ?></label><input class="form-control form-control-sm" name="location" maxlength="100" value="<?= $v('location') ?>"></div>
    <div class="col-md-3"><label class="form-label small"><?= trh('Origin of the population') ?></label><input class="form-control form-control-sm" name="origin" maxlength="255" value="<?= $v('origin') ?>"></div>
    <div class="col-md-2"><label class="form-label small"><?= trh('Reference (supplier, permit…)') ?></label><input class="form-control form-control-sm" name="reference" maxlength="100" value="<?= $v('reference') ?>"></div>
    <div class="col-md-3"><label class="form-label small"><?= trh('Established on') ?></label><input class="form-control form-control-sm" type="date" name="established_on" value="<?= $v('established_on') ?>"></div>
    <div class="col-md-2"><label class="form-label small"><?= trh('Census every (days)') ?></label><input class="form-control form-control-sm" type="number" min="0" max="730" name="census_every_days" value="<?= $v('census_every_days', 30) ?>"></div>
    <div class="col-md-2"><label class="form-label small"><?= trh('Health check every (days)') ?></label><input class="form-control form-control-sm" type="number" min="0" max="730" name="health_every_days" value="<?= $v('health_every_days', 90) ?>"></div>
    <div class="col-md-3"><label class="form-label small"><?= trh('Responsible') ?></label>
        <select class="form-select form-select-sm" name="responsible_user_id">
            <option value=""><?= trh('— administrators —') ?></option>
            <?php foreach ($users as $u) : ?><option value="<?= (int) $u['id'] ?>" <?= (int) ($t['responsible_user_id'] ?? 0) === (int) $u['id'] ? 'selected' : '' ?>><?= htmlspecialchars($u['name']) ?></option><?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-5"><label class="form-label small"><?= trh('Description') ?></label><input class="form-control form-control-sm" name="description" value="<?= $v('description') ?>"></div>
</div>
<datalist id="terrariumRooms"><?php foreach ($rooms as $r) : ?><option value="<?= htmlspecialchars($r) ?>"><?php endforeach; ?></datalist>
