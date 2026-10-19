<?php

/**
 * Demonstration database, used for training and for the screenshots of the
 * guides (tools/guide_screenshots.js). Creates the database from
 * database/schema.sql, then fills it with a small fictitious animal
 * facility: users, project authorization, cages, breeding with litters,
 * an experiment with follow-ups, planned acts and a correction, health
 * reports, pharmacy with a prescription, a terrarium population, training
 * records (certificates in storage/training).
 *
 *   php tools/demo_data.php --db=myanimlab_demo --user=root --password=...
 *   (--ids=file.json writes the ids of the records shown in the screenshots)
 *
 * The database name must contain "demo": it is dropped and recreated.
 * Every account has the password Demo-2026! (two-factor authentication is
 * not required, to keep the demonstration simple).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Command line only.');
}

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
require_once $root . '/i18n.php';
require_once $root . '/db_lib.php';
require_once $root . '/animal_lib.php';
require_once $root . '/followup_lib.php';
require_once $root . '/corrections_lib.php';
require_once $root . '/terrarium_lib.php';
require_once $root . '/experiment_acts_lib.php';
require_once $root . '/training_lib.php';
require_once __DIR__ . '/migrations_lib.php';

$opt = getopt('', ['db:', 'user:', 'password::', 'host::', 'today::', 'ids::']);
$db = (string) ($opt['db'] ?? 'myanimlab_demo');
if (!str_contains($db, 'demo')) {
    fwrite(STDERR, "The database name must contain \"demo\" (it is dropped and recreated).\n");
    exit(1);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$con = new mysqli((string) ($opt['host'] ?? '127.0.0.1'), (string) ($opt['user'] ?? 'root'), (string) ($opt['password'] ?? ''));
$con->set_charset('utf8mb4');
$con->query("DROP DATABASE IF EXISTS `$db`");
$con->query("CREATE DATABASE `$db` CHARACTER SET utf8mb4");
$con->select_db($db);
run_sql_script($con, (string) file_get_contents($root . '/database/schema.sql'));

$today = (string) ($opt['today'] ?? date('Y-m-d'));
$day = fn(int $offset): string => date('Y-m-d', strtotime("$today $offset days"));
$at = fn(int $offset, string $time = '09:30'): string => $day($offset) . " $time:00";

// ----- laboratory and users -----
foreach ([
    'lab_name' => 'Animalerie de démonstration',
    'timezone' => 'Europe/Paris',
    'weaning_age_days' => '21',
    'require_2fa_privileged' => '0',
    'round_alert_time' => '18:00',
    'acts_reminder_time' => '07:30',
] as $name => $value) {
    db_execute($con, 'INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', 'ss', [$name, $value]);
}
$password = password_hash('Demo-2026!', PASSWORD_DEFAULT);
db_execute($con, "UPDATE users SET name = 'Claire Martin', username = 'claire.martin@demo.fr', position = 'Principal Investigator', initials = 'CM', password = ? WHERE id = 1", 's', [$password]);
$admin = 1;
$user = function (string $name, string $email, string $position, string $initials, int $bea = 0) use ($con, $password): int {
    db_execute($con, "INSERT INTO users (name, username, position, role, password, status, email_verified, initials, is_bea_responsible) VALUES (?, ?, ?, 'user', ?, 'approved', 1, ?, ?)",
        'sssssi', [$name, $email, $position, $password, $initials, $bea]);
    return (int) $con->insert_id;
};
$julien = $user('Julien Bernard', 'julien.bernard@demo.fr', 'Animal Care Technician', 'JB');
$sophie = $user('Sophie Leroy', 'sophie.leroy@demo.fr', 'Research Scientist', 'SL', 1);
$marc = $user('Marc Dubois', 'marc.dubois@demo.fr', 'Principal Investigator', 'MD');
$names = [$admin => 'Claire Martin', $julien => 'Julien Bernard', $sophie => 'Sophie Leroy', $marc => 'Marc Dubois'];

// ----- training records -----
// A small PDF stands for each certificate.
$certDir = training_storage_dir();
if (!is_dir($certDir)) {
    mkdir($certDir, 0750, true);
}
$pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj 2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj 3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
$training = function (int $userId, string $category, ?string $function, string $title, string $organisation, int $offset, ?float $hours, string $status = 'validated', bool $certificate = true, array $extra = []) use ($con, $day, $names, $admin, $certDir, $pdf): int {
    $file = null;
    if ($certificate) {
        $file = bin2hex(random_bytes(16)) . '.pdf';
        file_put_contents("$certDir/$file", $pdf);
    }
    $validated = $status === 'validated';
    db_execute($con, 'INSERT INTO training_records (user_id, category, function_code, diploma_level, title, organisation, start_date, duration_hours, species, supervisor_name, certificate_ref, certificate_file, status, validated_by, validated_by_name, validated_at, created_by, created_by_name)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', 'sssssssdsssssissis',
        [$userId, $category, $function, $extra['level'] ?? null, $title, $organisation, $day($offset), $hours, $extra['species'] ?? null, $extra['supervisor'] ?? null,
            $certificate ? 'ATT-' . substr($day($offset), 0, 4) . '-' . random_int(100, 999) : null, $file, $status, $validated ? $admin : null, $validated ? $names[$admin] : null,
            $validated ? $day($offset + 10) . ' 10:00:00' : null, $userId, $names[$userId]]);
    return (int) $con->insert_id;
};
// Julien: competent for the care of animals, trained to apply procedures and still under supervision.
$training($julien, 'diploma', null, 'BTSA Productions animales', 'Lycée agricole de Melle', -2600, null, 'validated', true, ['level' => 'bac2']);
$training($julien, 'initial', 'care', 'Formation réglementaire — soins aux animaux', 'Centre de formation zootechnique', -2500, 35, 'validated', true, ['species' => 'rongeurs']);
$training($julien, 'competence', 'care', 'Compétence validée — soins aux animaux', 'Animalerie de démonstration', -2300, null, 'validated', true, ['supervisor' => 'Claire Martin']);
$training($julien, 'initial', 'procedures', 'Formation réglementaire — application des procédures', 'Centre de formation zootechnique', -150, 70, 'validated', true, ['species' => 'rat, souris']);
$training($julien, 'continuing', null, 'Anesthésie et analgésie des rongeurs', 'AFSTAL', -1400, 14, 'validated', true);
$training($julien, 'continuing', null, 'Congrès AFSTAL', 'AFSTAL', -700, 7, 'validated', false);
$training($julien, 'continuing', null, 'Atelier enrichissement du milieu', 'GIRCOR', -20, 7, 'pending', true);
// Sophie: designer and experimenter, everything validated.
$training($sophie, 'diploma', null, 'Doctorat en pharmacologie', 'Université de Poitiers', -4000, null, 'validated', true, ['level' => 'phd']);
foreach (['design' => 'conception des procédures et projets', 'procedures' => 'application des procédures'] as $code => $label) {
    $training($sophie, 'initial', $code, "Formation réglementaire — $label", 'Institut Pasteur', -3600, 70, 'validated', true, ['species' => 'rongeurs']);
    $training($sophie, 'competence', $code, "Compétence validée — $label", 'Animalerie de démonstration', -3300, null, 'validated', true, ['supervisor' => 'Claire Martin']);
}
$training($sophie, 'continuing', null, 'Chirurgie aseptique des rongeurs', 'Institut Pasteur', -900, 14, 'validated', true);
$training($sophie, 'continuing', null, 'Points limites et grilles de douleur', 'GIRCOR', -300, 7, 'validated', true);
// Marc: continuing training below the minimum.
$training($marc, 'initial', 'design', 'Formation réglementaire — conception des procédures et projets', 'Université de Bordeaux', -3500, 70, 'validated', true);
$training($marc, 'continuing', null, 'Éthique de l’expérimentation animale', 'GIRCOR', -1500, 7, 'validated', true);

// ----- project authorization -----
$ethics = 'APAFIS#41234-2026011512345678 v2';
db_execute($con, "INSERT INTO iacuc (iacuc_id, iacuc_title, authorization_number, valid_from, valid_until) VALUES (?, 'Évaluation d’un analgésique après chirurgie chez le rat', 'AUT-2026-0042', ?, ?)",
    'sss', [$ethics, $day(-270), $day(1550)]);
db_execute($con, "INSERT INTO iacuc_animal_quotas (iacuc_id, species, max_animals) VALUES (?, 'Rat', 120)", 's', [$ethics]);

$rsd = (int) db_value($con, "SELECT id FROM animal_lines WHERE code = 'RSD'");
$janvier = ['origin_type' => 'supplier', 'origin_detail' => 'Janvier Labs', 'origin_reference' => 'BL-2026-0912', 'entry_date' => $day(-33)];

// ----- holding cages -----
$holding = [];
foreach ([['A1', 'R1', $julien], ['A1', 'R1', $julien], ['A1', 'R2', $sophie]] as [$room, $rack, $owner]) {
    $cage = create_holding_cage($con, $marc, $room, $rack, $day(-60), [$owner], 'Arrivage Janvier Labs', $julien, $names[$julien]);
    foreach (['male', 'male', 'female', 'female'] as $sex) {
        create_animal($con, $cage, $rsd, $sex, $day(-60), null, 'Arrivage Janvier Labs', $julien, $janvier);
    }
    $holding[] = $cage;
}
db_execute($con, 'UPDATE mice SET distinctive_mark = ? WHERE cage_id = ? ORDER BY id LIMIT 1', 'ss', ['Boucle oreille gauche', $holding[0]]);

// ----- experimental cage: 4 rats enrolled -----
$expCage = create_holding_cage($con, $admin, 'B2', 'R1', $day(-75), [$sophie, $julien], 'Arrivage Janvier Labs', $sophie, $names[$sophie]);
db_execute($con, 'UPDATE cages SET is_experimental = 1 WHERE cage_id = ?', 's', [$expCage]);
$expAnimals = [];
foreach (['male', 'male', 'male', 'male'] as $sex) {
    $expAnimals[] = create_animal($con, $expCage, $rsd, $sex, $day(-75), null, 'Arrivage Janvier Labs', $sophie, $janvier + ['entry_date' => $day(-40)]);
}

// ----- breeding cage with litters -----
$breedCage = allocate_cage_id($con);
db_execute($con, "INSERT INTO cages (cage_id, pi_name, quantity, room, rack) VALUES (?, ?, 0, 'A2', 'R3')", 'si', [$breedCage, $marc]);
db_execute($con, "INSERT INTO cage_users (cage_id, user_id) VALUES (?, ?)", 'si', [$breedCage, $julien]);
db_execute($con, "INSERT INTO breeding (cage_id, `cross`) VALUES (?, 'RSD × RSD')", 's', [$breedCage]);
$breedingId = (int) $con->insert_id;
log_cage_lifecycle($con, $breedCage, 'created', 'Mise en accouplement', $julien, $names[$julien]);
$sire = create_animal($con, $breedCage, $rsd, 'male', $day(-150), null, 'Reproducteur', $julien, $janvier + ['entry_date' => $day(-120)]);
$dam1 = create_animal($con, $breedCage, $rsd, 'female', $day(-140), null, 'Reproductrice', $julien, $janvier + ['entry_date' => $day(-120)]);
$dam2 = create_animal($con, $breedCage, $rsd, 'female', $day(-140), null, 'Reproductrice', $julien, $janvier + ['entry_date' => $day(-120)]);
foreach ([[$sire, 'male'], [$dam1, 'female'], [$dam2, 'female']] as [$id, $sex]) {
    db_execute($con, 'INSERT INTO breeding_animals (breeding_id, animal_id, sex) VALUES (?, ?, ?)', 'iis', [$breedingId, $id, $sex]);
}
$litter = function (string $dob, int $alive, int $dead, string $status, ?string $weanedAt = null, ?int $males = null, ?int $females = null) use ($con, $breedCage, $sire, $dam1): int {
    db_execute($con, 'INSERT INTO litters (cage_id, litter_dob, pups_alive, pups_dead, status, expected_weaning_date, weaned_at, weaned_males, weaned_females, sire_id, dam_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', 'ssiisssiiii',
        [$breedCage, $dob, $alive, $dead, $status, date('Y-m-d', strtotime("$dob +21 days")), $weanedAt, $males, $females, $sire, $dam1]);
    return (int) $con->insert_id;
};
$firstLitter = $litter($day(-110), 9, 0, 'weaned', $day(-89), 4, 5);
$overdue = $litter($day(-24), 9, 1, 'nursing');
log_litter_event($con, $overdue, 'birth', $day(-24), 9, null, null, null, $julien, $names[$julien]);
log_litter_event($con, $overdue, 'death', $day(-20), 1, null, null, 'Retrouvé mort au nid', $julien, $names[$julien]);
$recent = $litter($day(-8), 7, 0, 'nursing');
log_litter_event($con, $recent, 'birth', $day(-8), 7, null, null, null, $julien, $names[$julien]);

// ----- clinical grid, trial, protocol, experiment -----
db_execute($con, "INSERT INTO clinical_grids (name, description, created_by) VALUES ('Grille douleur rat', 'Posture, pelage, activité, plaie (0 à 3 par item)', ?)", 'i', [$admin]);
$grid = (int) $con->insert_id;
$items = [];
foreach (['Posture', 'Pelage', 'Activité', 'Plaie opératoire'] as $pos => $label) {
    db_execute($con, 'INSERT INTO clinical_grid_items (grid_id, position, label) VALUES (?, ?, ?)', 'iis', [$grid, $pos + 1, $label]);
    $items[$label] = (int) $con->insert_id;
    foreach (['Normal', 'Léger', 'Modéré', 'Sévère'] as $score => $level) {
        db_execute($con, 'INSERT INTO clinical_grid_levels (item_id, score, description) VALUES (?, ?, ?)', 'iis', [$items[$label], $score, $level]);
    }
}
db_execute($con, "INSERT INTO trials (trial_number, iacuc_id, title, start_date, end_date, status, created_by) VALUES ('ESS-2026-03', ?, 'Analgésie post-opératoire', ?, ?, 'active', ?)", 'sssi', [$ethics, $day(-200), $day(200), $admin]);
$trial = (int) $con->insert_id;
db_execute($con, "INSERT INTO protocols (trial_id, protocol_number, title, start_date, end_date, status, clinical_grid_id, expected_severity) VALUES (?, 'P-2026-03-A', 'Méloxicam après laparotomie', ?, ?, 'active', ?, 'moderate')", 'issi', [$trial, $day(-150), $day(150), $grid]);
$protocol = (int) $con->insert_id;
db_execute($con, "INSERT INTO protocol_endpoint_thresholds (protocol_id, metric, operator, value, action_message, required_action) VALUES (?, 'weight_loss_pct', '>=', 20, 'Perte de poids d’au moins 20 %', 'euthanasia'), (?, 'clinical_score', '>=', 6, 'Score clinique élevé', 'call_vet')", 'ii', [$protocol, $protocol]);
$thresholdVet = (int) db_value($con, "SELECT id FROM protocol_endpoint_thresholds WHERE protocol_id = ? AND required_action = 'call_vet'", 'i', [$protocol]);
db_execute($con, "INSERT INTO experiments (protocol_id, experiment_number, title, start_date, end_date, status) VALUES (?, 'EXP-2026-014', 'Lot 1 — méloxicam 1 mg/kg', ?, ?, 'active')", 'iss', [$protocol, $day(-10), $day(30)]);
$experiment = (int) $con->insert_id;

$enrollments = [];
foreach ($expAnimals as $i => $animalId) {
    db_execute($con, 'INSERT INTO experiment_animals (experiment_id, animal_id, baseline_weight_g, enrolled_at) VALUES (?, ?, ?, ?)', 'iids', [$experiment, $animalId, 320 + 5 * $i, $at(-6, '08:00')]);
    $enrollments[] = (int) $con->insert_id;
}
// Daily follow-ups of the last 5 days (none yet today for the round).
$followup = function (int $enrollment, int $offset, float $weight, array $scores, string $by, string $note = '') use ($con, $items, $grid, $names, $at): int {
    $userId = array_search($by, $names, true);
    $total = array_sum($scores);
    db_execute($con, 'INSERT INTO experiment_followups (experiment_animal_id, observed_at, weight_g, clinical_score, observations, endpoint_reached, recorded_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        'isdisiis', [$enrollment, substr($at($offset), 0, 10), $weight, $total, $note, (int) ($total >= 6), $userId, $at($offset, '10:15')]);
    $id = (int) $con->insert_id;
    foreach ($scores as $label => $score) {
        $levels = ['Normal', 'Léger', 'Modéré', 'Sévère'];
        db_execute($con, 'INSERT INTO experiment_followup_scores (followup_id, grid_item_id, item_label, score, level_description) VALUES (?, ?, ?, ?, ?)', 'iisis', [$id, $items[$label], $label, $score, $levels[$score]]);
    }
    return $id;
};
$weights = [[318, 312, 309, 311, 314], [322, 318, 316, 317, 319], [329, 320, 312, 305, 298], [336, 333, 331, 332, 334]];
foreach ($enrollments as $i => $enrollment) {
    foreach ([-5, -4, -3, -2, -1] as $k => $offset) {
        $high = $i === 2 && $offset === -1;
        $scores = $high ? ['Posture' => 2, 'Pelage' => 2, 'Activité' => 1, 'Plaie opératoire' => 1] : ['Posture' => $k < 2 ? 1 : 0, 'Pelage' => 0, 'Activité' => $k < 1 ? 1 : 0, 'Plaie opératoire' => $k < 3 ? 1 : 0];
        $fid = $followup($enrollment, $offset, $weights[$i][$k], $scores, $i % 2 ? 'Julien Bernard' : 'Sophie Leroy', $high ? 'Prostré, dos voûté' : '');
        if ($high) {
            db_execute($con, "INSERT INTO endpoint_actions (experiment_animal_id, followup_id, threshold_id, required_action, message, measured_value) VALUES (?, ?, ?, 'call_vet', 'Score clinique élevé (score clinique total ≥ 6, mesuré : 6)', 6)", 'iii', [$enrollment, $fid, $thresholdVet]);
            notify_admins($con, 'ACTION REQUISE (Appeler le vétérinaire) : ' . db_value($con, 'SELECT animal_uid FROM mice WHERE id = ?', 'i', [$expAnimals[$i]]), 'Expérience EXP-2026-014 (P-2026-03-A) : score clinique élevé', 'experiment_followup.php?experiment_animal_id=' . $enrollment, 'endpoint_alert');
        }
        if ($i === 0 && $offset === -3) {
            // A corrected entry: the weight was entered for the wrong animal.
            $previous = followup_snapshot($con, $fid);
            $previous['weight_g'] = '331.00';
            record_followup_revision($con, $fid, $previous, 'Poids saisi pour le mauvais animal', $sophie, $names[$sophie]);
            log_activity($con, 'followup_corrected', 'animal', (string) $expAnimals[0], 'Suivi du ' . $day(-3) . ' corrigé : Poids saisi pour le mauvais animal', $sophie);
        }
    }
}

// Act planned on the 4 rats: an analgesic injection yesterday (done), today and tomorrow.
$con->begin_transaction();
[$act] = plan_experiment_act($con, $experiment, [
    'act_type' => 'analgesia', 'label' => 'Méloxicam post-opératoire', 'substance' => 'Méloxicam', 'dose_amount' => 1.0, 'dose_unit' => 'mg/kg',
    'route' => 'SC', 'product_id' => null, 'quantity_per_animal' => null, 'instructions' => 'Injecter avant 10 h, puis contrôler la plaie',
], [['label' => 'Volume injecté', 'value_type' => 'number', 'unit' => 'mL', 'required' => 1]], [$day(-1), $day(0), $day(1)], $enrollments, $sophie, $names[$sophie]);
$con->commit();
db_execute($con, "UPDATE experiment_act_animals SET status = 'done', done_at = ?, done_by = ?, done_by_name = ? WHERE act_id = ? AND planned_date = ?", 'sisis', [$at(-1, '09:10'), $sophie, $names[$sophie], $act, $day(-1)]);

// ----- health report on a holding animal -----
$sick = (int) db_value($con, 'SELECT id FROM mice WHERE cage_id = ? ORDER BY id DESC LIMIT 1', 's', [$holding[1]]);
db_execute($con, "INSERT INTO health_reports (animal_id, animal_uid, cage_id, line_code, observed_at, category, severity, description, reported_by, reported_by_name) VALUES (?, ?, ?, 'RSD', ?, 'skin_fur', 'moderate', 'Alopécie et croûtes sur le dos, grattage fréquent.', ?, ?)",
    'isssis', [$sick, db_value($con, 'SELECT animal_uid FROM mice WHERE id = ?', 'i', [$sick]), $holding[1], $day(-2), $julien, $names[$julien]]);
$report = (int) $con->insert_id;
db_execute($con, "INSERT INTO health_report_updates (report_id, status, severity, note, created_by, created_by_name) VALUES (?, 'open', 'moderate', 'Signalement initial', ?, ?), (?, 'monitoring', 'minor', 'Vu par le vétérinaire : soins locaux, surveillance quotidienne.', ?, ?)",
    'iisiis', [$report, $julien, $names[$julien], $report, $admin, $names[$admin]]);

// ----- an exit corrected afterwards (register) -----
$exited = (int) db_value($con, 'SELECT id FROM mice WHERE cage_id = ? ORDER BY id DESC LIMIT 1', 's', [$holding[2]]);
exit_animal($con, $exited, 'retrouve_mort', $day(-4), 'Retrouvé mort le matin', null, $julien, $names[$julien], false);
correct_animal_exit($con, $exited, 'euthanasie', $day(-4), 'Euthanasie pour raison sanitaire (occlusion)', null, 'Code de sortie erroné : l’animal a été euthanasié', $admin, $names[$admin]);

// ----- daily round: some cages already seen today -----
foreach ([$holding[0], $holding[2]] as $cage) {
    record_cage_check($con, $cage, 'routine', 'ok', 'round', null, null, $julien, $names[$julien]);
}

// ----- pharmacy -----
db_execute($con, "INSERT INTO medication_products (name, default_unit, is_narcotic, concentration, concentration_unit, active_ingredient) VALUES ('Metacam 5 mg/mL', 'mL', 0, 5, 'mg/mL', 'méloxicam'), ('Imalgène 1000', 'mL', 1, 100, 'mg/mL', 'kétamine')");
$meloxicam = (int) db_value($con, "SELECT id FROM medication_products WHERE name LIKE 'Metacam%'");
$ketamine = (int) db_value($con, "SELECT id FROM medication_products WHERE name LIKE 'Imalg%'");
foreach ([[$meloxicam, 'MX2611', 20.0], [$ketamine, 'KT0425', 10.0]] as [$product, $lotNumber, $qty]) {
    db_execute($con, 'INSERT INTO medication_lots (product_id, lot_number, expiry_date, received_at, initial_quantity, remaining_quantity, storage_location) VALUES (?, ?, ?, ?, ?, ?, ?)',
        'isssdds', [$product, $lotNumber, $day(400), $day(-30), $qty, $qty, $product === $ketamine ? 'Coffre stupéfiants' : 'Armoire pharmacie']);
    $lot = (int) $con->insert_id;
    db_execute($con, "INSERT INTO medication_stock_movements (medication_lot_id, movement_type, quantity_delta, reason, performed_by) VALUES (?, 'receipt', ?, 'Réception du lot', ?)", 'idi', [$lot, $qty, $admin]);
}
db_execute($con, "INSERT INTO prescriptions (animal_id, product_id, prescription_reference, prescriber_name, prescribed_on, start_date, end_date, dose_amount, dose_unit, route, frequency, instructions, recorded_by)
    VALUES (?, ?, 'ORD-2026-118', 'Dr Anne Petit', ?, ?, ?, 0.1, 'mL', 'SC', '1 fois par jour', 'Pendant 3 jours', ?)", 'iisssi', [$sick, $meloxicam, $day(-2), $day(-2), $day(1), $admin]);
$prescription = (int) $con->insert_id;
db_execute($con, 'INSERT INTO prescription_animals (prescription_id, animal_id, planned_administrations) VALUES (?, ?, 3)', 'ii', [$prescription, $sick]);

$lotMx = (int) db_value($con, "SELECT id FROM medication_lots WHERE lot_number = 'MX2611'");
foreach ([-2, -1] as $offset) {
    db_execute($con, "INSERT INTO medication_administrations (prescription_id, animal_id, medication_lot_id, administered_at, quantity_used, unit, route, administered_by, administered_by_name) VALUES (?, ?, ?, ?, 0.1, 'mL', 'SC', ?, ?)",
        'iiisis', [$prescription, $sick, $lotMx, $at($offset, '08:45'), $julien, $names[$julien]]);
    db_execute($con, "INSERT INTO medication_stock_movements (medication_lot_id, movement_type, quantity_delta, reason, administration_id, performed_by) VALUES (?, 'administration', -0.1, 'Administration', ?, ?)", 'iii', [$lotMx, (int) $con->insert_id, $julien]);
}
db_execute($con, 'UPDATE medication_lots SET remaining_quantity = remaining_quantity - 0.2, in_use_since = ? WHERE id = ?', 'si', [$day(-2), $lotMx]);

// ----- terrarium: a population of wild voles -----
$voles = (int) db_value($con, "SELECT id FROM animal_lines WHERE code = 'CGN'");
$terrarium = terrarium_save($con, null, [
    'code' => 'T-01', 'name' => 'Campagnols des champs', 'line_id' => $voles, 'room' => 'C1', 'location' => 'Étagère nord',
    'origin' => 'Capture sur le terrain (station de Lusignan)', 'reference' => 'Arrêté préfectoral 2026-17', 'established_on' => $day(-180),
    'census_every_days' => 30, 'health_every_days' => 90, 'responsible_user_id' => $julien, 'description' => 'Population fondatrice pour l’élevage de campagnols',
], $admin);
$t = db_row($con, 'SELECT * FROM terrariums WHERE id = ?', 'i', [$terrarium]);
terrarium_add_event($con, $terrarium, 'introduction', $day(-180), ['quantity' => 24, 'life_stage' => 'adultes', 'reference' => 'Capture 2026-04'], $julien, $names[$julien]);
terrarium_add_event($con, $terrarium, 'census', $day(-40), ['estimate' => 55, 'estimate_min' => 50, 'estimate_max' => 62, 'method' => 'Capture-recapture'], $julien, $names[$julien]);
terrarium_add_event($con, $terrarium, 'death', $day(-21), ['quantity' => 2, 'life_stage' => 'juvéniles', 'note' => 'Trouvés au fond du terrarium'], $julien, $names[$julien]);
terrarium_add_event($con, $terrarium, 'capture', $day(-12), ['quantity' => 6, 'life_stage' => 'adultes', 'note' => 'Pour l’essai ESS-2026-03'], $sophie, $names[$sophie]);
terrarium_add_event($con, $terrarium, 'note', $day(-3), ['note' => 'Litière changée, ajout de foin'], $julien, $names[$julien]);
record_terrarium_treatment($con, $t, ['kind' => 'antiparasitic', 'treated_at' => $day(-6) . ' 10:00', 'substance' => 'Ivermectine', 'dose' => '0,2 mg/kg dans l’eau de boisson',
    'method' => 'Eau de boisson', 'reason' => 'Acariens observés', 'prescriber_name' => 'Dr Anne Petit', 'prescription_reference' => 'ORD-2026-121', 'withdrawal_until' => $day(8)], $julien, $names[$julien]);
record_terrarium_health_check($con, $t, ['check_type' => 'environment', 'checked_on' => $day(-5), 'temperature_c' => '21,5', 'humidity_pct' => 55], $julien, $names[$julien]);
record_terrarium_health_check($con, $t, ['check_type' => 'screening', 'checked_on' => $day(-9), 'sample_type' => 'Fèces (mélange)', 'animals_sampled' => 10,
    'laboratory' => 'Laboratoire vétérinaire départemental', 'sample_reference' => 'LVD-26-5512', 'agents_screened' => 'Hantavirus, leptospires, parasites intestinaux'], $julien, $names[$julien]);

// ----- three generations for the family tree -----
// The first litter, weaned: its 9 pups (F1) in a holding cage; a brother and
// a sister mated, their weaned litter (F2) is inbred (25 %).
$f1Cage = create_holding_cage($con, $marc, 'A2', 'R4', $day(-89), [$julien], 'Sevrage de la portée n°' . $firstLitter, $julien, $names[$julien]);
$f1 = [];
foreach (['male', 'male', 'male', 'male', 'female', 'female', 'female', 'female', 'female'] as $sex) {
    $f1[$sex][] = create_animal($con, $f1Cage, $rsd, $sex, $day(-110), ['litter_id' => $firstLitter, 'sire_id' => $sire, 'dam_id' => $dam1], 'Sevrage', $julien);
}
$f1Pair = allocate_cage_id($con);
db_execute($con, "INSERT INTO cages (cage_id, pi_name, quantity, room, rack) VALUES (?, ?, 0, 'A2', 'R4')", 'si', [$f1Pair, $marc]);
db_execute($con, 'INSERT INTO cage_users (cage_id, user_id) VALUES (?, ?)', 'si', [$f1Pair, $julien]);
db_execute($con, "INSERT INTO breeding (cage_id, `cross`) VALUES (?, 'RSD F1 × F1 (frère × sœur)')", 's', [$f1Pair]);
$f1Breeding = (int) $con->insert_id;
log_cage_lifecycle($con, $f1Pair, 'created', 'Mise en accouplement', $julien, $names[$julien]);
[$f1Male, $f1Female] = [$f1['male'][0], $f1['female'][0]];
foreach ([[$f1Male, 'male'], [$f1Female, 'female']] as [$id, $sex]) {
    move_animal($con, $id, $f1Pair, 'Mise en accouplement', $julien, $names[$julien], false);
    db_execute($con, 'INSERT INTO breeding_animals (breeding_id, animal_id, sex) VALUES (?, ?, ?)', 'iis', [$f1Breeding, $id, $sex]);
}
db_execute($con, "INSERT INTO litters (cage_id, litter_dob, pups_alive, pups_dead, status, expected_weaning_date, weaned_at, weaned_males, weaned_females, sire_id, dam_id) VALUES (?, ?, 4, 0, 'weaned', ?, ?, 2, 2, ?, ?)",
    'ssssii', [$f1Pair, $day(-30), $day(-9), $day(-9), $f1Male, $f1Female]);
$f2Litter = (int) $con->insert_id;
$f2 = [];
foreach (['male', 'male', 'female', 'female'] as $sex) {
    $f2[] = create_animal($con, $f1Cage, $rsd, $sex, $day(-30), ['litter_id' => $f2Litter, 'sire_id' => $f1Male, 'dam_id' => $f1Female], 'Sevrage', $julien);
}

// ----- notifications for the screenshots of the bell -----
create_notification($con, $julien, '1 portée(s) : sevrage en retard', "Sevrage en retard :\n• cage $breedCage", "litter_manage.php?cage_id=$breedCage", 'reminder');

if (!empty($opt['ids'])) {
    $correctedFollowup = (int) db_value($con, 'SELECT followup_id FROM experiment_followup_revisions ORDER BY id LIMIT 1');
    file_put_contents((string) $opt['ids'], json_encode([
        'holding' => $holding, 'experimental' => $expCage, 'breeding' => $breedCage,
        'overdueLitter' => $overdue, 'exitedAnimal' => $exited, 'sickAnimal' => $sick, 'healthReport' => $report,
        'enrollmentAlert' => $enrollments[2], 'enrollmentCorrected' => $enrollments[0], 'correctedFollowup' => $correctedFollowup,
        'experiment' => $experiment, 'protocol' => $protocol, 'trial' => $trial, 'act' => $act,
        'terrarium' => $terrarium, 'recentLitter' => $recent, 'narcotic' => $ketamine, 'julien' => $julien, 'sophie' => $sophie, 'inbred' => $f2[0], 'founder' => $sire,
    ], JSON_PRETTY_PRINT));
}

echo "Demonstration database $db ready: cages " . implode(', ', array_merge($holding, [$expCage, $breedCage])) . ".\n";
echo "Accounts (password Demo-2026!): claire.martin@demo.fr (administrator), julien.bernard@demo.fr, sophie.leroy@demo.fr (BEA), marc.dubois@demo.fr\n";
