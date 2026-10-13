-- Data below contains accented text: load it as UTF-8 whatever the client default.
SET NAMES utf8mb4;

-- Table for storing IACUC (Institutional Animal Care and Use Committee) records
CREATE TABLE `iacuc` (
  `iacuc_id` varchar(255) NOT NULL,
  `iacuc_title` varchar(255) NOT NULL,
  `file_url` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`iacuc_id`)
);

-- Table for storing user information
CREATE TABLE `users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL, 
  `username` varchar(255) NOT NULL,
  `position` varchar(255) NOT NULL,
  `role` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `status` varchar(255) NOT NULL,
  `reset_token` varchar(255) DEFAULT NULL,
  `reset_token_expiration` datetime DEFAULT NULL,
  `login_attempts` int DEFAULT 0,
  `account_locked` datetime DEFAULT NULL,
  `email_verified` tinyint DEFAULT 0,
  `email_token` varchar(255) DEFAULT NULL,
  `initials` varchar(5) DEFAULT NULL,
  `preferred_locale` varchar(5) NOT NULL DEFAULT 'fr',
  `is_bea_responsible` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
);

-- Table for storing cage information
CREATE TABLE `cages` (
  `cage_id` varchar(255) NOT NULL UNIQUE,
  `pi_name` int DEFAULT NULL,
  `quantity` int DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `status` ENUM('active','archived') NOT NULL DEFAULT 'active',
  `is_experimental` tinyint(1) NOT NULL DEFAULT 0,
  `room` varchar(255) DEFAULT NULL,
  `rack` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `retired_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`cage_id`),
  FOREIGN KEY (`pi_name`) REFERENCES `users` (`id`) ON DELETE SET NULL
);

-- Junction table for associating cages with IACUC records
CREATE TABLE `cage_iacuc` (
  `cage_id` varchar(255) NOT NULL,
  `iacuc_id` varchar(255) NOT NULL,
  PRIMARY KEY (`cage_id`, `iacuc_id`),
  FOREIGN KEY (`cage_id`) REFERENCES `cages` (`cage_id`) ON UPDATE CASCADE,
  FOREIGN KEY (`iacuc_id`) REFERENCES `iacuc` (`iacuc_id`) ON DELETE CASCADE
);

-- Junction table for associating cages with users
CREATE TABLE `cage_users` (
  `cage_id` varchar(255) NOT NULL,
  `user_id` int NOT NULL,
  PRIMARY KEY (`cage_id`, `user_id`),
  FOREIGN KEY (`cage_id`) REFERENCES `cages` (`cage_id`) ON UPDATE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
);

-- Table for storing strain information
CREATE TABLE `strains` (
  `id` int NOT NULL AUTO_INCREMENT,
  `str_id` varchar(255) NOT NULL,
  `str_name` varchar(255) NOT NULL,
  `str_aka` varchar(255) DEFAULT NULL,
  `str_url` varchar(255) DEFAULT NULL,
  `str_rrid` varchar(255) DEFAULT NULL,
  `str_notes` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_strains_str_id` (`str_id`)
);

-- Table for storing holding information related to cages and strains
CREATE TABLE `holding` (
  `id` int NOT NULL AUTO_INCREMENT,
  `cage_id` varchar(255) NOT NULL,
  `strain` varchar(255) DEFAULT NULL,
  `dob` date NOT NULL,
  `sex` enum('male', 'female') DEFAULT NULL,
  `parent_cg` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`cage_id`) REFERENCES `cages` (`cage_id`) ON UPDATE CASCADE,
  FOREIGN KEY (`strain`) REFERENCES `strains` (`str_id`) ON DELETE SET NULL
);

-- Table for storing breeding information
CREATE TABLE `breeding` (
  `id` int NOT NULL AUTO_INCREMENT,
  `cage_id` varchar(255) NOT NULL,
  `cross` varchar(255) NOT NULL,
  `male_id` varchar(255) DEFAULT NULL,
  `female_id` varchar(255) DEFAULT NULL,
  `male_dob` date DEFAULT NULL,
  `female_dob` date DEFAULT NULL,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`cage_id`) REFERENCES `cages` (`cage_id`) ON UPDATE CASCADE
);

-- Table for storing litter information
CREATE TABLE `litters` (
  `id` int NOT NULL AUTO_INCREMENT,
  `cage_id` varchar(255) NOT NULL,
  `dom` date DEFAULT NULL,
  `litter_dob` date DEFAULT NULL,
  `pups_alive` int NOT NULL,
  `pups_dead` int NOT NULL,
  `pups_male` int DEFAULT NULL,
  `pups_female` int DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `sire_id` int DEFAULT NULL,
  `dam_id` int DEFAULT NULL,
  `status` ENUM('nursing','weaned','closed') NOT NULL DEFAULT 'nursing',
  `expected_weaning_date` date DEFAULT NULL,
  `weaned_at` date DEFAULT NULL,
  `weaned_males` int DEFAULT NULL,
  `weaned_females` int DEFAULT NULL,
  `closed_reason` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`cage_id`) REFERENCES `cages` (`cage_id`) ON UPDATE CASCADE
);

-- Table for storing file information related to cages
CREATE TABLE `files` (
  `id` int NOT NULL AUTO_INCREMENT,
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `cage_id` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`cage_id`) REFERENCES `cages` (`cage_id`) ON UPDATE CASCADE
);

-- Table for storing notes related to cages and users
CREATE TABLE `notes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `cage_id` varchar(255) DEFAULT NULL,
  `note_text` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `user_id` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`cage_id`) REFERENCES `cages` (`cage_id`) ON UPDATE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
);

-- Table for storing animal lines (lineage code + species), used to build
-- unique animal IDs such as RSD-0002 (Rat Sprague Dawley) or RY139-0023 (Rat Y139)
CREATE TABLE `animal_lines` (
  `id` int NOT NULL AUTO_INCREMENT,
  `code` varchar(20) NOT NULL UNIQUE,
  `species` varchar(100) NOT NULL,
  `name` varchar(255) NOT NULL,
  `notes` text DEFAULT NULL,
  `next_seq` int NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
);

-- Singleton counter used to auto-generate every cage_id (P-00001, P-00002, ...).
-- Cage IDs are never entered manually; allocate_cage_id() in animal_lib.php
-- locks this row (SELECT ... FOR UPDATE) before incrementing it.
CREATE TABLE `cage_sequence` (
  `id` tinyint NOT NULL DEFAULT 1,
  `next_seq` int NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
);

INSERT INTO `cage_sequence` (`id`, `next_seq`) VALUES (1, 1);

-- Singleton counter for automatically allocated experiment IDs (EXP-00001,
-- EXP-00002, ...). Allocation is locked inside a transaction.
CREATE TABLE `experiment_sequence` (
  `id` tinyint NOT NULL DEFAULT 1,
  `next_seq` int NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
);

INSERT INTO `experiment_sequence` (`id`, `next_seq`) VALUES (1, 1);

-- Table for storing mouse/animal information related to cages
CREATE TABLE `mice` (
  `id` int NOT NULL AUTO_INCREMENT,
  `cage_id` varchar(255) DEFAULT NULL,
  `line_id` int DEFAULT NULL,
  `animal_uid` varchar(50) DEFAULT NULL UNIQUE,
  `mouse_id` varchar(255) DEFAULT NULL,
  `sex` ENUM('male','female') DEFAULT NULL,
  `dob` date DEFAULT NULL,
  `distinctive_mark` varchar(255) DEFAULT NULL,
  `genotype` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` ENUM('active','sacrificed','dead','transferred_out','archived') NOT NULL DEFAULT 'active',
  `exit_date` date DEFAULT NULL,
  `exit_reason` text DEFAULT NULL,
  `exit_destination` varchar(255) DEFAULT NULL,
  `exit_code` varchar(40) DEFAULT NULL,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`cage_id`) REFERENCES `cages` (`cage_id`) ON UPDATE CASCADE ON DELETE SET NULL,
  FOREIGN KEY (`line_id`) REFERENCES `animal_lines` (`id`) ON DELETE SET NULL
);

-- Table for storing the history of cage-to-cage transfers for each animal
CREATE TABLE `animal_movements` (
  `id` int NOT NULL AUTO_INCREMENT,
  `animal_id` int NOT NULL,
  `from_cage_id` varchar(255) DEFAULT NULL,
  `to_cage_id` varchar(255) NOT NULL,
  `moved_by` int DEFAULT NULL,
  `moved_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `reason` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`animal_id`) REFERENCES `mice` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`from_cage_id`) REFERENCES `cages` (`cage_id`) ON UPDATE CASCADE ON DELETE SET NULL,
  FOREIGN KEY (`to_cage_id`) REFERENCES `cages` (`cage_id`) ON UPDATE CASCADE,
  FOREIGN KEY (`moved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
);

-- Junction table linking a breeding cage to the (possibly multiple) male and
-- female breeders housed in it
CREATE TABLE `breeding_animals` (
  `id` int NOT NULL AUTO_INCREMENT,
  `breeding_id` int NOT NULL,
  `animal_id` int NOT NULL,
  `sex` ENUM('male','female') NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_breeding_animal` (`breeding_id`, `animal_id`),
  FOREIGN KEY (`breeding_id`) REFERENCES `breeding` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`animal_id`) REFERENCES `mice` (`id`) ON DELETE CASCADE
);

-- Table for storing formal parentage (sire/dam/litter) for each animal
CREATE TABLE `animal_parentage` (
  `id` int NOT NULL AUTO_INCREMENT,
  `animal_id` int NOT NULL,
  `litter_id` int DEFAULT NULL,
  `sire_id` int DEFAULT NULL,
  `dam_id` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_parentage_animal` (`animal_id`),
  FOREIGN KEY (`animal_id`) REFERENCES `mice` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`litter_id`) REFERENCES `litters` (`id`) ON DELETE SET NULL,
  FOREIGN KEY (`sire_id`) REFERENCES `mice` (`id`) ON DELETE SET NULL,
  FOREIGN KEY (`dam_id`) REFERENCES `mice` (`id`) ON DELETE SET NULL
);

-- Generic audit trail: chronological events for each animal (created,
-- transferred, status changes, experiment enrollment, endpoint alerts, notes)
CREATE TABLE `animal_events` (
  `id` int NOT NULL AUTO_INCREMENT,
  `animal_id` int NOT NULL,
  `event_type` ENUM('created','transferred','status_change','experiment_start','experiment_end','endpoint_alert','note','health_report') NOT NULL,
  `payload` JSON DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_animal_events_animal` (`animal_id`),
  FOREIGN KEY (`animal_id`) REFERENCES `mice` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
);

-- Planned or completed use of an animal (the equivalent of JCMS MouseUsage).
CREATE TABLE `animal_use_schedule` (
  `id` int NOT NULL AUTO_INCREMENT,
  `animal_id` int NOT NULL,
  `use_label` varchar(255) NOT NULL,
  `projected_date` date DEFAULT NULL,
  `actual_date` date DEFAULT NULL,
  `completed` tinyint(1) NOT NULL DEFAULT 0,
  `notes` text DEFAULT NULL,
  `source_system` varchar(30) DEFAULT NULL,
  `source_key` varchar(64) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_use_source` (`source_system`, `source_key`),
  KEY `idx_use_schedule_animal_date` (`animal_id`, `projected_date`),
  FOREIGN KEY (`animal_id`) REFERENCES `mice` (`id`) ON DELETE CASCADE
);

-- Chronological sanitary level of a room, independent of individual reports.
CREATE TABLE `room_health_history` (
  `id` int NOT NULL AUTO_INCREMENT,
  `room` varchar(255) NOT NULL,
  `health_level` varchar(100) NOT NULL,
  `start_date` date DEFAULT NULL,
  `source_system` varchar(30) DEFAULT NULL,
  `source_key` varchar(64) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_room_health_source` (`source_system`, `source_key`)
);

-- Immutable correspondence tables make a JCMS import repeatable and auditable.
CREATE TABLE `jcms_import_runs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `source_file` varchar(255) NOT NULL,
  `started_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `finished_at` timestamp NULL DEFAULT NULL,
  `status` enum('running','completed','failed') NOT NULL DEFAULT 'running',
  `summary` json DEFAULT NULL,
  PRIMARY KEY (`id`)
);

CREATE TABLE `jcms_import_map` (
  `id` int NOT NULL AUTO_INCREMENT,
  `run_id` int NOT NULL,
  `source_table` varchar(80) NOT NULL,
  `source_key` varchar(64) NOT NULL,
  `target_table` varchar(80) NOT NULL,
  `target_key` varchar(80) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_jcms_source` (`source_table`, `source_key`, `target_table`),
  KEY `idx_jcms_target` (`target_table`, `target_key`),
  FOREIGN KEY (`run_id`) REFERENCES `jcms_import_runs` (`id`) ON DELETE CASCADE
);

-- Experimentation module: trial (essai) -> protocol(s) -> experiment(s)
CREATE TABLE `trials` (
  `id` int NOT NULL AUTO_INCREMENT,
  `trial_number` varchar(50) NOT NULL UNIQUE,
  `iacuc_id` varchar(255) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `status` ENUM('planned','active','completed','cancelled') NOT NULL DEFAULT 'planned',
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  FOREIGN KEY (`iacuc_id`) REFERENCES `iacuc` (`iacuc_id`) ON DELETE SET NULL,
  FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
);

CREATE TABLE `protocols` (
  `id` int NOT NULL AUTO_INCREMENT,
  `trial_id` int NOT NULL,
  `protocol_number` varchar(50) NOT NULL UNIQUE,
  `title` varchar(255) NOT NULL,
  `methodology` text DEFAULT NULL,
  `endpoint_criteria` text DEFAULT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `status` ENUM('planned','active','completed','cancelled') NOT NULL DEFAULT 'planned',
  `clinical_grid_id` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`trial_id`) REFERENCES `trials` (`id`) ON DELETE CASCADE
);

CREATE TABLE `protocol_endpoint_thresholds` (
  `id` int NOT NULL AUTO_INCREMENT,
  `protocol_id` int NOT NULL,
  `metric` ENUM('weight_loss_pct','clinical_score','custom','item_score','observation') NOT NULL,
  `grid_item_id` int DEFAULT NULL,
  `observation_field_id` int DEFAULT NULL,
  `operator` ENUM('>','>=','<','<=') NOT NULL,
  `value` decimal(10,2) NOT NULL,
  `action_message` varchar(255) DEFAULT NULL,
  `required_action` ENUM('none','euthanasia','call_vet','increase_monitoring','other') NOT NULL DEFAULT 'none',
  PRIMARY KEY (`id`),
  FOREIGN KEY (`protocol_id`) REFERENCES `protocols` (`id`) ON DELETE CASCADE
);

CREATE TABLE `experiments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `protocol_id` int NOT NULL,
  `experiment_number` varchar(50) NOT NULL UNIQUE,
  `title` varchar(255) NOT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `status` ENUM('planned','active','completed','cancelled') NOT NULL DEFAULT 'planned',
  PRIMARY KEY (`id`),
  FOREIGN KEY (`protocol_id`) REFERENCES `protocols` (`id`) ON DELETE CASCADE
);

CREATE TABLE `experiment_animals` (
  `id` int NOT NULL AUTO_INCREMENT,
  `experiment_id` int NOT NULL,
  `animal_id` int NOT NULL,
  `baseline_weight_g` decimal(8,2) DEFAULT NULL,
  `enrolled_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `removed_at` timestamp NULL DEFAULT NULL,
  `removal_reason` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_experiment_animal` (`experiment_id`, `animal_id`),
  FOREIGN KEY (`experiment_id`) REFERENCES `experiments` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`animal_id`) REFERENCES `mice` (`id`) ON DELETE CASCADE
);

CREATE TABLE `experiment_retrospective_assessments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `experiment_animal_id` int NOT NULL,
  `severity_classification` ENUM('non_recovery','mild','moderate','severe') NOT NULL,
  `assessment_notes` text NOT NULL,
  `assessed_by` int NOT NULL,
  `assessed_by_name` varchar(255) NOT NULL,
  `assessed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_retro_experiment_animal` (`experiment_animal_id`),
  FOREIGN KEY (`experiment_animal_id`) REFERENCES `experiment_animals` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`assessed_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
);

CREATE TABLE `experiment_followups` (
  `id` int NOT NULL AUTO_INCREMENT,
  `experiment_animal_id` int NOT NULL,
  `observed_at` date NOT NULL,
  `weight_g` decimal(8,2) DEFAULT NULL,
  `clinical_score` int DEFAULT NULL,
  `observations` text DEFAULT NULL,
  `endpoint_reached` tinyint(1) NOT NULL DEFAULT 0,
  `recorded_by` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_followup_day` (`experiment_animal_id`, `observed_at`),
  FOREIGN KEY (`experiment_animal_id`) REFERENCES `experiment_animals` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
);

-- Sanitary (health) registry. Append-only by design: a report and every
-- follow-up entry are permanent. The current status/severity of a report is
-- its latest health_report_updates row, so nothing ever needs an UPDATE.
-- animal_uid / cage_id / line_code are snapshots taken at report time and
-- there is deliberately no FK to mice/users, so the registry survives an
-- animal's permanent deletion and a user's removal.
CREATE TABLE `health_reports` (
  `id` int NOT NULL AUTO_INCREMENT,
  `animal_id` int NOT NULL,
  `animal_uid` varchar(50) DEFAULT NULL,
  `cage_id` varchar(255) DEFAULT NULL,
  `line_code` varchar(20) DEFAULT NULL,
  `observed_at` date NOT NULL,
  `category` varchar(50) NOT NULL,
  `severity` ENUM('minor','moderate','severe','critical') NOT NULL,
  `description` text NOT NULL,
  `reported_by` int DEFAULT NULL,
  `reported_by_name` varchar(255) DEFAULT NULL,
  `reported_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_health_reports_animal` (`animal_id`),
  KEY `idx_health_reports_cage` (`cage_id`)
);

CREATE TABLE `health_report_updates` (
  `id` int NOT NULL AUTO_INCREMENT,
  `report_id` int NOT NULL,
  `status` ENUM('open','monitoring','resolved') NOT NULL,
  `severity` ENUM('minor','moderate','severe','critical') NOT NULL,
  `note` text NOT NULL,
  `created_by` int DEFAULT NULL,
  `created_by_name` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_health_updates_report` (`report_id`),
  FOREIGN KEY (`report_id`) REFERENCES `health_reports` (`id`) ON DELETE RESTRICT
);

-- Database-level protection: no UPDATE or DELETE, whatever the code path.
CREATE TRIGGER `health_reports_no_update` BEFORE UPDATE ON `health_reports`
  FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Health registry is append-only';
CREATE TRIGGER `health_reports_no_delete` BEFORE DELETE ON `health_reports`
  FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Health registry is append-only';
CREATE TRIGGER `health_report_updates_no_update` BEFORE UPDATE ON `health_report_updates`
  FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Health registry is append-only';
CREATE TRIGGER `health_report_updates_no_delete` BEFORE DELETE ON `health_report_updates`
  FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Health registry is append-only';

-- Daily cage checks (visite journalière). Every active cage must be seen at
-- least once a day, either by the routine round (room by room or by scanning
-- the cage QR code) or automatically through the experimental daily follow-up
-- or a health report. Append-only, like the health registry: no FK to cages
-- so the history survives a cage's removal, and triggers reject UPDATE/DELETE.
CREATE TABLE `cage_checks` (
  `id` int NOT NULL AUTO_INCREMENT,
  `cage_id` varchar(255) NOT NULL,
  `check_date` date NOT NULL,
  `check_type` ENUM('routine','experimental','health_report','creation') NOT NULL,
  `result` ENUM('ok','issue') NOT NULL,
  `method` ENUM('round','qr','cage_page','auto') NOT NULL,
  `note` text DEFAULT NULL,
  `health_report_id` int DEFAULT NULL,
  `checked_by` int DEFAULT NULL,
  `checked_by_name` varchar(255) DEFAULT NULL,
  `checked_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_cage_checks_date_cage` (`check_date`, `cage_id`)
);

CREATE TRIGGER `cage_checks_no_update` BEFORE UPDATE ON `cage_checks`
  FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Cage check log is append-only';
CREATE TRIGGER `cage_checks_no_delete` BEFORE DELETE ON `cage_checks`
  FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Cage check log is append-only';

-- Cage lifecycle: when each cage entered and left service. The daily round
-- only expects a cage to be seen on days it actually existed. A cage can be
-- retired (archived) and later restored, so every transition is kept in an
-- append-only log; cages.created_at / retired_at hold the current values.
CREATE TABLE `cage_lifecycle` (
  `id` int NOT NULL AUTO_INCREMENT,
  `cage_id` varchar(255) NOT NULL,
  `event` ENUM('created','retired','restored') NOT NULL,
  `event_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `reason` text DEFAULT NULL,
  `user_id` int DEFAULT NULL,
  `user_name` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_cage_lifecycle_cage` (`cage_id`, `event_at`)
);

CREATE TRIGGER `cage_lifecycle_no_update` BEFORE UPDATE ON `cage_lifecycle`
  FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Cage lifecycle log is append-only';
CREATE TRIGGER `cage_lifecycle_no_delete` BEFORE DELETE ON `cage_lifecycle`
  FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Cage lifecycle log is append-only';

-- Housing invariant: every living (active) animal is in an active cage.
-- Enforced in the database so no code path can break it: an active animal
-- cannot be placed without a cage or into a retired cage, and a cage cannot
-- be retired while it still houses living animals (each must first be
-- given a fate: exit or move to another cage, see cage_retire.php).
DELIMITER $$
CREATE TRIGGER `mice_active_needs_cage_insert` BEFORE INSERT ON `mice` FOR EACH ROW
BEGIN
  IF NEW.status = 'active' AND (NEW.cage_id IS NULL
      OR NOT EXISTS (SELECT 1 FROM cages c WHERE c.cage_id = NEW.cage_id AND c.status = 'active')) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A living animal must be housed in an active cage';
  END IF;
END$$
CREATE TRIGGER `mice_active_needs_cage_update` BEFORE UPDATE ON `mice` FOR EACH ROW
BEGIN
  IF NEW.status = 'active' AND (NOT (NEW.cage_id <=> OLD.cage_id) OR NEW.status <> OLD.status)
     AND (NEW.cage_id IS NULL
      OR NOT EXISTS (SELECT 1 FROM cages c WHERE c.cage_id = NEW.cage_id AND c.status = 'active')) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A living animal must be housed in an active cage';
  END IF;
END$$
CREATE TRIGGER `cages_no_retire_with_animals` BEFORE UPDATE ON `cages` FOR EACH ROW
BEGIN
  IF OLD.status = 'active' AND NEW.status <> 'active'
     AND EXISTS (SELECT 1 FROM mice m WHERE m.cage_id = OLD.cage_id AND m.status = 'active') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Cage still houses living animals: give each one a fate first';
  END IF;
END$$
DELIMITER ;

-- Clinical scoring grids (grilles d'évaluation clinique): a reusable library.
-- A grid has items (criteria such as posture or respiration), each scored on
-- levels (0 = normal ... 3 = severe). A protocol uses one grid for the daily
-- follow-up of its animals. A grid becomes read-only once scores reference
-- it; changes are made on a duplicate so recorded follow-ups stay exact.
CREATE TABLE `clinical_grids` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
);

CREATE TABLE `clinical_grid_items` (
  `id` int NOT NULL AUTO_INCREMENT,
  `grid_id` int NOT NULL,
  `position` int NOT NULL DEFAULT 0,
  `label` varchar(255) NOT NULL,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`grid_id`) REFERENCES `clinical_grids` (`id`) ON DELETE CASCADE
);

CREATE TABLE `clinical_grid_levels` (
  `id` int NOT NULL AUTO_INCREMENT,
  `item_id` int NOT NULL,
  `score` int NOT NULL,
  `description` varchar(255) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_grid_level` (`item_id`, `score`),
  FOREIGN KEY (`item_id`) REFERENCES `clinical_grid_items` (`id`) ON DELETE CASCADE
);

-- Item scores of each daily follow-up, with label/description snapshots so
-- reports stay exact whatever happens to the grid later.
CREATE TABLE `experiment_followup_scores` (
  `id` int NOT NULL AUTO_INCREMENT,
  `followup_id` int NOT NULL,
  `grid_item_id` int DEFAULT NULL,
  `item_label` varchar(255) NOT NULL,
  `score` int NOT NULL,
  `level_description` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_followup_item` (`followup_id`, `grid_item_id`),
  FOREIGN KEY (`followup_id`) REFERENCES `experiment_followups` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`grid_item_id`) REFERENCES `clinical_grid_items` (`id`) ON DELETE SET NULL
);

-- Action the experimenter must take when an endpoint (point limite) is
-- reached: pending until done (euthanasia is completed by the animal's exit,
-- other actions by a written note such as the vet's decision).
CREATE TABLE `endpoint_actions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `experiment_animal_id` int NOT NULL,
  `followup_id` int DEFAULT NULL,
  `threshold_id` int DEFAULT NULL,
  `required_action` ENUM('euthanasia','call_vet','increase_monitoring','other') NOT NULL,
  `message` varchar(512) DEFAULT NULL,
  `measured_value` decimal(10,2) DEFAULT NULL,
  `status` ENUM('pending','done') NOT NULL DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `done_at` timestamp NULL DEFAULT NULL,
  `done_by` int DEFAULT NULL,
  `done_by_name` varchar(255) DEFAULT NULL,
  `done_note` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_endpoint_actions_status` (`status`),
  FOREIGN KEY (`experiment_animal_id`) REFERENCES `experiment_animals` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`followup_id`) REFERENCES `experiment_followups` (`id`) ON DELETE SET NULL,
  FOREIGN KEY (`threshold_id`) REFERENCES `protocol_endpoint_thresholds` (`id`) ON DELETE SET NULL
);

-- Starter grid (generic rodent welfare score sheet), editable via a duplicate.
INSERT INTO `clinical_grids` (`id`, `name`, `description`) VALUES
  (1, 'Grille générique rongeur', 'Évaluation clinique quotidienne : 0 = normal, 3 = atteinte sévère.');
INSERT INTO `clinical_grid_items` (`id`, `grid_id`, `position`, `label`) VALUES
  (1, 1, 1, 'Apparence / pelage'),
  (2, 1, 2, 'Posture'),
  (3, 1, 3, 'Comportement / activité'),
  (4, 1, 4, 'Respiration');
INSERT INTO `clinical_grid_levels` (`item_id`, `score`, `description`) VALUES
  (1, 0, 'Normal'), (1, 1, 'Pelage terne, toilettage réduit'), (1, 2, 'Poils hérissés, écoulements'), (1, 3, 'Pelage très dégradé, plaies'),
  (2, 0, 'Normale'), (2, 1, 'Dos légèrement voûté'), (2, 2, 'Dos voûté marqué'), (2, 3, 'Prostration'),
  (3, 0, 'Normal, actif'), (3, 1, 'Activité réduite'), (3, 2, 'Isolé, peu réactif'), (3, 3, 'Inerte, ne réagit pas à la stimulation'),
  (4, 0, 'Normale'), (4, 1, 'Légèrement modifiée'), (4, 2, 'Accélérée ou abdominale'), (4, 3, 'Détresse respiratoire');

-- Additional observations of the experimental daily follow-up, written or
-- numeric. A protocol can define its own fields (e.g. "Temperature", °C,
-- number; "Wound aspect", text), entered at every follow-up; numeric fields
-- can carry endpoints. Free ad-hoc observations can also be added to any
-- entry. Values keep a snapshot of the label/unit for exact reports. A field
-- is never deleted, only deactivated (active = 0), so its values stay linked.
CREATE TABLE `protocol_observation_fields` (
  `id` int NOT NULL AUTO_INCREMENT,
  `protocol_id` int NOT NULL,
  `position` int NOT NULL DEFAULT 0,
  `label` varchar(255) NOT NULL,
  `value_type` ENUM('number','text') NOT NULL,
  `unit` varchar(50) DEFAULT NULL,
  `required` tinyint(1) NOT NULL DEFAULT 0,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`protocol_id`) REFERENCES `protocols` (`id`) ON DELETE CASCADE
);

CREATE TABLE `experiment_followup_observations` (
  `id` int NOT NULL AUTO_INCREMENT,
  `followup_id` int NOT NULL,
  `field_id` int DEFAULT NULL,
  `label` varchar(255) NOT NULL,
  `value_type` ENUM('number','text') NOT NULL,
  `unit` varchar(50) DEFAULT NULL,
  `value_number` decimal(14,4) DEFAULT NULL,
  `value_text` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_followup_obs` (`followup_id`),
  FOREIGN KEY (`followup_id`) REFERENCES `experiment_followups` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`field_id`) REFERENCES `protocol_observation_fields` (`id`) ON DELETE SET NULL
);

-- Litter lifecycle (portée): birth -> nursing (deaths, sexing) -> weaning.
-- At weaning the pups become identified animals (line UID, sex, date of
-- birth, parentage sire/dam/litter) in new or existing holding cages.
-- litter_events is the litter's journal. A weaned or closed litter can no
-- longer be edited or deleted.
CREATE TABLE `litter_events` (
  `id` int NOT NULL AUTO_INCREMENT,
  `litter_id` int NOT NULL,
  `event` ENUM('birth','death','sexing','weaning','closed','note') NOT NULL,
  `event_date` date NOT NULL,
  `count` int DEFAULT NULL,
  `males` int DEFAULT NULL,
  `females` int DEFAULT NULL,
  `note` text DEFAULT NULL,
  `user_id` int DEFAULT NULL,
  `user_name` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_litter_events_litter` (`litter_id`),
  FOREIGN KEY (`litter_id`) REFERENCES `litters` (`id`) ON DELETE CASCADE
);

-- Table for storing tasks information
CREATE TABLE `tasks` (
  `id` int NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `description` text NOT NULL,
  `assigned_by` int DEFAULT NULL,
  `assigned_to` varchar(50) NOT NULL,
  `status` enum('Pending','In Progress','Completed') NOT NULL DEFAULT 'Pending',
  `completion_date` date DEFAULT NULL,
  `cage_id` varchar(255) DEFAULT NULL,
  `creation_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  FOREIGN KEY (`cage_id`) REFERENCES `cages` (`cage_id`) ON UPDATE CASCADE,
  FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
);

-- Table for storing outbox email information
CREATE TABLE `outbox` (
  `id` int NOT NULL AUTO_INCREMENT,
  `recipient` varchar(255) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `body` text NOT NULL,
  `status` enum('pending','sent','failed') NOT NULL DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `scheduled_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `sent_at` timestamp NULL DEFAULT NULL,
  `error_message` text DEFAULT NULL,
  `task_id` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_outbox_recipient` (`recipient`),
  FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE SET NULL
);

-- Table for storing reminders
CREATE TABLE `reminders` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT NOT NULL,
  `assigned_by` INT NOT NULL,
  `assigned_to` VARCHAR(255) NOT NULL,
  `recurrence_type` ENUM('daily', 'weekly', 'monthly') NOT NULL,
  `day_of_week` ENUM('Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday') DEFAULT NULL,
  `day_of_month` INT DEFAULT NULL,
  `time_of_day` TIME NOT NULL,
  `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  `cage_id` VARCHAR(255) DEFAULT NULL,
  `creation_date` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `last_task_created` DATETIME NULL,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`cage_id`) REFERENCES `cages` (`cage_id`) ON UPDATE CASCADE,
  FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
);

-- Table for storing maintenance logs
CREATE TABLE `maintenance` (
  `id` int NOT NULL AUTO_INCREMENT,
  `cage_id` varchar(255) NOT NULL,
  `user_id` int NOT NULL,
  `comments` text DEFAULT NULL,
  `timestamp` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  FOREIGN KEY (`cage_id`) REFERENCES `cages` (`cage_id`) ON UPDATE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
);

-- Table for in-app notifications (e.g. endpoint/point-limite alerts)
CREATE TABLE `notifications` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `title` varchar(255) NOT NULL,
  `message` text DEFAULT NULL,
  `link` varchar(255) DEFAULT NULL,
  `type` ENUM('reminder','task','system','endpoint_alert','health_alert') NOT NULL DEFAULT 'system',
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_notifications_user` (`user_id`, `is_read`, `created_at`),
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
);

-- Generic activity/audit log, covering every entity type (not just animals)
CREATE TABLE `activity_log` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int DEFAULT NULL,
  `action` varchar(50) NOT NULL,
  `entity_type` varchar(50) NOT NULL,
  `entity_id` varchar(255) DEFAULT NULL,
  `details` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_activity_log_user` (`user_id`),
  KEY `idx_activity_log_entity` (`entity_type`, `entity_id`),
  KEY `idx_activity_log_created` (`created_at`),
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
);

-- Pharmacy: products, traceable lots, prescriptions and administrations.
CREATE TABLE `medication_products` (
  `id` int NOT NULL AUTO_INCREMENT, `name` varchar(255) NOT NULL,
  `presentation` varchar(255) DEFAULT NULL, `active_ingredient` varchar(255) DEFAULT NULL,
  `default_unit` varchar(30) NOT NULL DEFAULT 'unit', `notes` text DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1, PRIMARY KEY (`id`), UNIQUE KEY `uq_medication_product_name` (`name`)
);
CREATE TABLE `medication_lots` (
  `id` int NOT NULL AUTO_INCREMENT, `product_id` int NOT NULL, `lot_number` varchar(100) NOT NULL,
  `expiry_date` date NOT NULL, `received_at` date NOT NULL, `in_use_since` date DEFAULT NULL, `initial_quantity` decimal(12,3) NOT NULL,
  `remaining_quantity` decimal(12,3) NOT NULL, `storage_location` varchar(255) DEFAULT NULL,
  `supplier` varchar(255) DEFAULT NULL, `created_by` int DEFAULT NULL, `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`), UNIQUE KEY `uq_medication_lot` (`product_id`,`lot_number`),
  FOREIGN KEY (`product_id`) REFERENCES `medication_products` (`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
);
CREATE TABLE `prescriptions` (
  `id` int NOT NULL AUTO_INCREMENT, `animal_id` int NOT NULL, `product_id` int NOT NULL,
  `prescription_reference` varchar(100) DEFAULT NULL, `prescriber_name` varchar(255) NOT NULL,
  `prescribed_on` date NOT NULL, `start_date` date DEFAULT NULL, `end_date` date DEFAULT NULL,
  `dose_amount` decimal(12,3) DEFAULT NULL, `dose_unit` varchar(30) DEFAULT NULL,
  `route` varchar(100) DEFAULT NULL, `frequency` varchar(100) DEFAULT NULL, `instructions` text DEFAULT NULL,
  `status` ENUM('active','completed','cancelled') NOT NULL DEFAULT 'active', `recorded_by` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(), PRIMARY KEY (`id`),
  FOREIGN KEY (`animal_id`) REFERENCES `mice` (`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`product_id`) REFERENCES `medication_products` (`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
);
CREATE TABLE `prescription_animals` (
  `id` int NOT NULL AUTO_INCREMENT, `prescription_id` int NOT NULL, `animal_id` int NOT NULL,
  `planned_administrations` int NOT NULL DEFAULT 1, `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`), UNIQUE KEY `uq_prescription_animal` (`prescription_id`,`animal_id`),
  KEY `idx_prescription_animal_animal` (`animal_id`),
  FOREIGN KEY (`prescription_id`) REFERENCES `prescriptions` (`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`animal_id`) REFERENCES `mice` (`id`) ON DELETE RESTRICT
);
CREATE TABLE `medication_administrations` (
  `id` int NOT NULL AUTO_INCREMENT, `prescription_id` int NOT NULL, `animal_id` int NOT NULL,
  `medication_lot_id` int NOT NULL, `administered_at` datetime NOT NULL, `quantity_used` decimal(12,3) NOT NULL,
  `unit` varchar(30) NOT NULL, `route` varchar(100) DEFAULT NULL, `notes` text DEFAULT NULL,
  `administered_by` int DEFAULT NULL, `administered_by_name` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(), PRIMARY KEY (`id`),
  FOREIGN KEY (`prescription_id`) REFERENCES `prescriptions` (`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`animal_id`) REFERENCES `mice` (`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`medication_lot_id`) REFERENCES `medication_lots` (`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`administered_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
);
CREATE TABLE `medication_stock_movements` (
  `id` int NOT NULL AUTO_INCREMENT, `medication_lot_id` int NOT NULL,
  `movement_type` ENUM('receipt','administration','adjustment') NOT NULL, `quantity_delta` decimal(12,3) NOT NULL,
  `reason` text DEFAULT NULL, `administration_id` int DEFAULT NULL, `performed_by` int DEFAULT NULL,
  `performed_at` timestamp NOT NULL DEFAULT current_timestamp(), PRIMARY KEY (`id`),
  FOREIGN KEY (`medication_lot_id`) REFERENCES `medication_lots` (`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`administration_id`) REFERENCES `medication_administrations` (`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`performed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
);

-- Table for storing system settings
CREATE TABLE `settings` (
  `name` varchar(255) NOT NULL,
  `value` varchar(255) NOT NULL,
  PRIMARY KEY (`name`)
);

-- Insert initial data into the users table
INSERT INTO `users` (`name`, `username`, `position`, `role`, `password`, `status`, `reset_token`, `reset_token_expiration`, `login_attempts`, `account_locked`, `email_verified`, `email_token`, `initials`, `is_bea_responsible`)
VALUES ('Temporary Admin', 'admin@myvivarium.online', 'Principal Investigator', 'admin', '$2y$10$roVlhpjZsRFXY.m9JtRB/OAaN2dp50O7D2J5idI2MsUotxMyrHRZ6', 'approved', NULL, NULL, 0, NULL, 1, NULL, 'TAN', 1);

-- Insert initial data into the strains table
INSERT INTO `strains` (`str_id`, `str_name`, `str_aka`, `str_url`, `str_rrid`)
VALUES ('035561', 'STOCK Tc(HSA21,CAG-EGFP)1Yakaz/J', 'B6D2F1 TcMAC21', 'https://www.jax.org/strain/035561', 'IMSR_JAX:035561');

-- Insert initial data into the animal_lines table
INSERT INTO `animal_lines` (`code`, `species`, `name`) VALUES
  ('RSD', 'Rat', 'Rat Sprague Dawley'),
  ('RY139', 'Rat', 'Rat Y139'),
  ('RN', 'Rat noir (Rattus rattus)', 'Rat noir'),
  ('CGN', 'Campagnol', 'Campagnol');
