<?php
/**
 * One-way JCMS (MDB CSV export) importer. Run inside the MyVivarium web
 * container after tools/jcms_export.sh has produced the CSV directory:
 *   php tools/import_jcms.php --apply /tmp/jcms-export JCMS-fr8b.mdb
 * Genotyping is intentionally not read.
 */

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "Ligne de commande uniquement\n"); exit(1); }
$apply = in_array('--apply', $argv, true);
$args = array_values(array_filter($argv, static fn($a) => $a !== '--apply'));
if (count($args) < 3) { fwrite(STDERR, "Usage : php tools/import_jcms.php --apply <dossier-csv> <nom-de-la-source>\n"); exit(1); }
$directory = rtrim($args[1], '/');
$sourceName = $args[2];
if (!$apply) { fwrite(STDERR, "Écriture refusée sans --apply.\n"); exit(1); }

require dirname(__DIR__) . '/dbcon.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function rows(string $directory, string $table): array {
    $path = "$directory/$table.csv";
    if (!is_file($path)) return [];
    $handle = fopen($path, 'r');
    $header = fgetcsv($handle);
    if (!$header) return [];
    $result = [];
    while (($row = fgetcsv($handle)) !== false) {
        $row = array_pad($row, count($header), null);
        $result[] = array_combine($header, array_slice($row, 0, count($header)));
    }
    fclose($handle);
    return $result;
}
function date_only(?string $value): ?string {
    if (!$value) return null;
    $time = strtotime($value);
    return $time === false ? null : date('Y-m-d', $time);
}
function source_map(mysqli $con, int $runId, string $sourceTable, $sourceKey, string $targetTable, $targetKey): void {
    $stmt = $con->prepare('INSERT INTO jcms_import_map (run_id, source_table, source_key, target_table, target_key) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE run_id = VALUES(run_id), target_key = VALUES(target_key)');
    $key = (string) $sourceKey; $target = (string) $targetKey;
    $stmt->bind_param('issss', $runId, $sourceTable, $key, $targetTable, $target);
    $stmt->execute(); $stmt->close();
}
function first_value(array $row, array $keys): ?string {
    foreach ($keys as $key) if (isset($row[$key]) && $row[$key] !== '') return $row[$key];
    return null;
}

$tables = [];
foreach (['Strain1','Room1','Container2','ContainerHistory2','cv_ContainerStatus2','Mouse1','Mating1','MatingUnitLink1','Litter1','ExpPlan1','ExpTest1','ExpPlanMouseMap1','ExpTestPlanMap1','ExpData1','MouseUsage1','HealthLevelHistory3','cv_HealthLevel3'] as $table) {
    $tables[$table] = rows($directory, $table);
}
if (!$tables['Mouse1'] || !$tables['Container2']) { fwrite(STDERR, "L’export CSV est incomplet (Mouse1 et Container2 sont obligatoires).\n"); exit(1); }

$summary = [];
$con->begin_transaction();
try {
    $run = $con->prepare("INSERT INTO jcms_import_runs (source_file, status) VALUES (?, 'running')");
    $run->bind_param('s', $sourceName); $run->execute(); $runId = $con->insert_id; $run->close();

    // 1. Strains and one traceable animal line per source strain.
    $lineByStrain = [];
    foreach ($tables['Strain1'] as $row) {
        $key = $row['_strain_key']; if ($key === '') continue;
        $strId = 'JCMS-' . $key; $name = $row['strainName'] ?: ('Souche JCMS ' . $key);
        $aka = $row['nickname'] ?: null; $notes = $row['comment'] ?: null;
        $stmt = $con->prepare('INSERT INTO strains (str_id, str_name, str_aka, str_notes) VALUES (?, ?, ?, ?)');
        $stmt->bind_param('ssss', $strId, $name, $aka, $notes);
        try { $stmt->execute(); } catch (mysqli_sql_exception $e) { /* resume: use matching source ID */ }
        $stmt->close();
        $code = 'JCMS' . $key;
        $find = $con->prepare('SELECT id FROM animal_lines WHERE code = ?'); $find->bind_param('s', $code); $find->execute();
        $line = $find->get_result()->fetch_assoc(); $find->close();
        if (!$line) {
            $species = 'Rodent'; $insert = $con->prepare('INSERT INTO animal_lines (code, species, name, notes) VALUES (?, ?, ?, ?)');
            $insert->bind_param('ssss', $code, $species, $name, $notes); $insert->execute(); $line = ['id' => $con->insert_id]; $insert->close();
        }
        $lineByStrain[$key] = (int) $line['id'];
        source_map($con, $runId, 'Strain1', $key, 'animal_lines', $line['id']);
    }
    $summary['strains'] = count($lineByStrain);

    // 2. Cages. Only cages occupied by an alive JCMS animal are activated.
    $activePens = [];
    foreach ($tables['Mouse1'] as $row) if (($row['lifeStatus'] ?? '') === 'A' && ($row['_pen_key'] ?? '') !== '') $activePens[$row['_pen_key']] = true;
    $roomByKey = []; foreach ($tables['Room1'] as $row) $roomByKey[$row['_room_key']] = $row['roomName'] ?: null;
    $latestHistory = [];
    foreach ($tables['ContainerHistory2'] as $row) {
        $key = $row['_container_key']; if (!isset($latestHistory[$key]) || strcmp($row['actionDate'] ?? '', $latestHistory[$key]['actionDate'] ?? '') > 0) $latestHistory[$key] = $row;
    }
    $cageByContainer = [];
    foreach ($tables['Container2'] as $row) {
        $key = $row['_container_key']; $cageId = 'JCMS-C-' . ($row['containerID'] ?: $key);
        $history = $latestHistory[$key] ?? []; $room = $roomByKey[$history['_room_key'] ?? ''] ?? null;
        $status = isset($activePens[$key]) ? 'active' : 'archived'; $remarks = trim(($row['containerName'] ?? '') . ' ' . ($row['comment'] ?? '')) ?: null;
        $insert = $con->prepare('INSERT INTO cages (cage_id, quantity, remarks, status, room, retired_at) VALUES (?, 0, ?, ?, ?, IF(? = "archived", NOW(), NULL))');
        $insert->bind_param('sssss', $cageId, $remarks, $status, $room, $status);
        try { $insert->execute(); } catch (mysqli_sql_exception $e) { /* resume */ }
        $insert->close();
        $cageByContainer[$key] = $cageId;
        source_map($con, $runId, 'Container2', $key, 'cages', $cageId);
    }
    $summary['cages'] = count($cageByContainer);

    // 3. Animals and their initial placement. Original JCMS IDs remain in mouse_id;
    // deterministic JCMS-M IDs avoid collisions with existing local identifiers.
    $mouseByKey = []; $mouseRows = [];
    foreach ($tables['Mouse1'] as $row) {
        $key = $row['_mouse_key']; if ($key === '') continue;
        $cageId = $cageByContainer[$row['_pen_key'] ?? ''] ?? null; $life = $row['lifeStatus'] ?? '';
        $status = match ($life) { 'A' => 'active', 'D' => 'dead', 'E', 'K' => 'sacrificed', 'S' => 'transferred_out', default => 'archived' };
        if ($status === 'active' && !$cageId) $status = 'archived';
        $uid = 'JCMS-M' . $key; $mouseId = $row['ID'] ?: null; $sex = ($row['sex'] ?? '') === 'M' ? 'male' : (($row['sex'] ?? '') === 'F' ? 'female' : null);
        $dob = date_only($row['birthDate'] ?? null); $exit = date_only($row['exitDate'] ?? null); $lineId = $lineByStrain[$row['_strain_key'] ?? ''] ?? null;
        $notes = trim(($row['comment'] ?? '') . ($row['codNotes'] ? "\nJCMS exit note: " . $row['codNotes'] : '')) ?: null;
        $insert = $con->prepare('INSERT INTO mice (cage_id, line_id, animal_uid, mouse_id, sex, dob, genotype, notes, status, exit_date, exit_reason) VALUES (?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?)');
        $exitReason = $row['cod'] ?: null; $insert->bind_param('sissssssss', $cageId, $lineId, $uid, $mouseId, $sex, $dob, $notes, $status, $exit, $exitReason);
        try { $insert->execute(); $mouseIdTarget = $con->insert_id; } catch (mysqli_sql_exception $e) {
            $find = $con->prepare('SELECT id FROM mice WHERE animal_uid = ?'); $find->bind_param('s', $uid); $find->execute(); $mouseIdTarget = (int) $find->get_result()->fetch_assoc()['id']; $find->close();
        }
        $insert->close(); $mouseByKey[$key] = $mouseIdTarget; $mouseRows[$key] = $row;
        if ($cageId) { $move = $con->prepare('INSERT INTO animal_movements (animal_id, from_cage_id, to_cage_id, reason) VALUES (?, NULL, ?, "Importé depuis JCMS")'); $move->bind_param('is', $mouseIdTarget, $cageId); $move->execute(); $move->close(); }
        source_map($con, $runId, 'Mouse1', $key, 'mice', $mouseIdTarget);
    }
    $summary['animals'] = count($mouseByKey);

    // 4. Historical matings and litters, linked only to imported animals.
    $matingByKey = [];
    foreach ($tables['Mating1'] as $row) {
        $key = $row['_mating_key']; $sire = $mouseByKey[$row['_sire_key'] ?? ''] ?? null; $dam = $mouseByKey[$row['_dam1_key'] ?? ''] ?? ($mouseByKey[$row['_dam2_key'] ?? ''] ?? null);
        $sourceAnimal = $mouseRows[$row['_sire_key'] ?? ''] ?? ($mouseRows[$row['_dam1_key'] ?? ''] ?? []); $cageId = $cageByContainer[$sourceAnimal['_pen_key'] ?? ''] ?? null;
        if (!$cageId) continue;
        $cross = 'Accouplement JCMS ' . ($row['matingID'] ?: $key); $insert = $con->prepare('INSERT INTO breeding (cage_id, `cross`) VALUES (?, ?)'); $insert->bind_param('ss', $cageId, $cross); $insert->execute(); $breedingId = $con->insert_id; $insert->close();
        foreach ([[$sire, 'male'], [$dam, 'female']] as [$animalId, $sex]) if ($animalId) { $link = $con->prepare('INSERT IGNORE INTO breeding_animals (breeding_id, animal_id, sex) VALUES (?, ?, ?)'); $link->bind_param('iis', $breedingId, $animalId, $sex); $link->execute(); $link->close(); }
        $matingByKey[$key] = ['id' => $breedingId, 'cage_id' => $cageId, 'sire' => $sire, 'dam' => $dam]; source_map($con, $runId, 'Mating1', $key, 'breeding', $breedingId);
    }
    foreach ($tables['Litter1'] as $row) {
        $mating = $matingByKey[$row['_mating_key'] ?? ''] ?? null; if (!$mating) continue;
        $birth = date_only($row['birthDate'] ?? null); $weaned = date_only($row['weanDate'] ?? null); $alive = (int) ($row['totalBorn'] ?: 0) - (int) ($row['numberBornDead'] ?: 0); $dead = (int) ($row['numberBornDead'] ?: 0);
        $status = $weaned ? 'weaned' : 'nursing'; $notes = $row['comment'] ?: null;
        $insert = $con->prepare('INSERT INTO litters (cage_id, litter_dob, pups_alive, pups_dead, pups_male, pups_female, remarks, sire_id, dam_id, status, weaned_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $males = $row['numMale'] !== '' ? (int) $row['numMale'] : null; $females = $row['numFemale'] !== '' ? (int) $row['numFemale'] : null;
        $insert->bind_param('ssiiiisiiss', $mating['cage_id'], $birth, $alive, $dead, $males, $females, $notes, $mating['sire'], $mating['dam'], $status, $weaned); $insert->execute(); $litterId = $con->insert_id; $insert->close();
        source_map($con, $runId, 'Litter1', $row['_litter_key'], 'litters', $litterId);
    }

    // 5. Plans/tests/data become trials/protocols/experiments/follow-ups.
    $protocolByPlan = []; foreach ($tables['ExpPlan1'] as $row) {
        $key = $row['_expPlan_key']; $title = $row['expPlanName'] ?: ('Plan JCMS ' . $key); $status = in_array($row['expStatus'], ['active','completed']) ? $row['expStatus'] : 'planned';
        $number = 'JCMS-PLAN-' . $key; $trial = $con->prepare('INSERT INTO trials (trial_number, title, description, status) VALUES (?, ?, ?, ?)'); $trial->bind_param('ssss', $number, $title, $row['expNotes'], $status); $trial->execute(); $trialId = $con->insert_id; $trial->close();
        $protocolNumber = 'JCMS-PROTO-' . $key; $protocol = $con->prepare('INSERT INTO protocols (trial_id, protocol_number, title, methodology, status) VALUES (?, ?, ?, ?, ?)'); $protocol->bind_param('issss', $trialId, $protocolNumber, $title, $row['fieldOfStudy'], $status); $protocol->execute(); $protocolByPlan[$key] = $con->insert_id; $protocol->close(); source_map($con, $runId, 'ExpPlan1', $key, 'protocols', $protocolByPlan[$key]);
    }
    $experimentByTest = []; foreach ($tables['ExpTest1'] as $row) {
        $key = $row['_expTest_key']; $protocolId = $protocolByPlan[$row['_expPlan_key'] ?? ''] ?? null; if (!$protocolId) continue; $status = $row['testStatus'] === 'active' ? 'active' : ($row['testStatus'] === 'done' ? 'completed' : 'planned'); $number = 'JCMS-TEST-' . $key; $title = $row['testName'] ?: $number; $start = date_only($row['proposedTestDate'] ?? null);
        $insert = $con->prepare('INSERT INTO experiments (protocol_id, experiment_number, title, start_date, status) VALUES (?, ?, ?, ?, ?)'); $insert->bind_param('issss', $protocolId, $number, $title, $start, $status); $insert->execute(); $experimentByTest[$key] = $con->insert_id; $insert->close(); source_map($con, $runId, 'ExpTest1', $key, 'experiments', $experimentByTest[$key]);
    }
    $planMouseToAnimal = []; foreach ($tables['ExpPlanMouseMap1'] as $row) $planMouseToAnimal[$row['_expPlanMouseMap_key']] = $mouseByKey[$row['_mouse_key'] ?? ''] ?? null;
    $experimentAnimal = []; foreach ($tables['ExpTestPlanMap1'] as $row) { $experimentId = $experimentByTest[$row['_expTest_key'] ?? ''] ?? null; $animalId = $planMouseToAnimal[$row['_expPlanMouseMap_key'] ?? ''] ?? null; if (!$experimentId || !$animalId) continue; $insert = $con->prepare('INSERT IGNORE INTO experiment_animals (experiment_id, animal_id) VALUES (?, ?)'); $insert->bind_param('ii', $experimentId, $animalId); $insert->execute(); $find = $con->prepare('SELECT id FROM experiment_animals WHERE experiment_id = ? AND animal_id = ?'); $find->bind_param('ii', $experimentId, $animalId); $find->execute(); $experimentAnimal[$row['_expTestPlan_key']] = $find->get_result()->fetch_assoc()['id']; $find->close(); $insert->close(); }
    foreach ($tables['ExpData1'] as $row) { $experimentId = $experimentByTest[$row['_expTest_key'] ?? ''] ?? null; $animalId = $mouseByKey[$row['_specimen_key'] ?? ''] ?? null; if (!$experimentId || !$animalId) continue; $find = $con->prepare('SELECT id FROM experiment_animals WHERE experiment_id = ? AND animal_id = ?'); $find->bind_param('ii', $experimentId, $animalId); $find->execute(); $joined = $find->get_result()->fetch_assoc(); $find->close(); if (!$joined) continue; $values = []; for ($i=1;$i<=30;$i++) if (($row['d'.$i] ?? '') !== '') $values[] = 'd'.$i.': '.$row['d'.$i]; $date = date_only($row['expDate'] ?? null) ?: date('Y-m-d'); $note = implode("\n", $values); $insert = $con->prepare('INSERT INTO experiment_followups (experiment_animal_id, observed_at, observations) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE observations = CONCAT_WS("\\n", observations, VALUES(observations))'); $insert->bind_param('iss', $joined['id'], $date, $note); $insert->execute(); $insert->close(); }

    // 6. Planned animal uses and room health-level history.
    foreach ($tables['MouseUsage1'] as $row) { $animalId = $mouseByKey[$row['_mouse_key'] ?? ''] ?? null; if (!$animalId) continue; $label = $row['use'] ?: 'Utilisation planifiée JCMS'; $projected = date_only($row['projectedDate'] ?? null); $actual = date_only($row['actualDate'] ?? null); $done = (int) (($row['done'] ?? 0) != 0); $notes = $row['comment'] ?: null; $key = $row['_usage_key']; $insert = $con->prepare("INSERT INTO animal_use_schedule (animal_id, use_label, projected_date, actual_date, completed, notes, source_system, source_key) VALUES (?, ?, ?, ?, ?, ?, 'JCMS', ?) ON DUPLICATE KEY UPDATE animal_id = VALUES(animal_id)"); $insert->bind_param('isssiss', $animalId, $label, $projected, $actual, $done, $notes, $key); $insert->execute(); $insert->close(); }
    $levels = []; foreach ($tables['cv_HealthLevel3'] as $row) $levels[$row['_healthLevel_key']] = trim(($row['healthLevel'] ?? '') . ' ' . ($row['description'] ?? ''));
    foreach ($tables['HealthLevelHistory3'] as $row) { $room = $roomByKey[$row['_room_key'] ?? ''] ?? null; if (!$room) continue; $level = $levels[$row['_healthLevel_key'] ?? ''] ?? 'Unknown'; $date = date_only($row['startDate'] ?? null); $key = $row['_healthLevelHistory_key']; $insert = $con->prepare("INSERT INTO room_health_history (room, health_level, start_date, source_system, source_key) VALUES (?, ?, ?, 'JCMS', ?) ON DUPLICATE KEY UPDATE room = VALUES(room)"); $insert->bind_param('ssss', $room, $level, $date, $key); $insert->execute(); $insert->close(); }

    foreach ($cageByContainer as $cageId) { $update = $con->prepare("UPDATE cages SET quantity = (SELECT COUNT(*) FROM mice WHERE cage_id = ? AND status = 'active') WHERE cage_id = ?"); $update->bind_param('ss', $cageId, $cageId); $update->execute(); $update->close(); }
    $summary += ['matings' => count($matingByKey), 'litters' => count($tables['Litter1']), 'plans' => count($protocolByPlan), 'experiments' => count($experimentByTest), 'uses' => count($tables['MouseUsage1'])];
    $json = json_encode($summary); $finish = $con->prepare("UPDATE jcms_import_runs SET status = 'completed', finished_at = NOW(), summary = ? WHERE id = ?"); $finish->bind_param('si', $json, $runId); $finish->execute(); $finish->close();
    $con->commit(); echo "Import JCMS terminé : " . json_encode($summary, JSON_PRETTY_PRINT) . "\n";
} catch (Throwable $e) { $con->rollback(); fwrite(STDERR, "Échec de l’import : {$e->getMessage()}\n"); exit(1); }
