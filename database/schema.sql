-- Data below contains accented text: load it as UTF-8 whatever the client default.
SET NAMES utf8mb4;

-- Table for storing IACUC (Institutional Animal Care and Use Committee) records
-- Project authorizations: iacuc_id is the ethics number (e.g. APAFIS#12345),
-- with the authorization number, its validity period (trials must fit in it)
-- and the regulatory PDF documents, served only to signed-in users.
CREATE TABLE `iacuc` (
  `iacuc_id` varchar(255) NOT NULL,
  `iacuc_title` varchar(255) NOT NULL,
  `authorization_number` varchar(100) DEFAULT NULL,
  `valid_from` date DEFAULT NULL,
  `valid_until` date DEFAULT NULL,
  `file_url` varchar(255) DEFAULT NULL,
  `request_file` varchar(255) DEFAULT NULL,
  `committee_opinion_file` varchar(255) DEFAULT NULL,
  `decision_file` varchar(255) DEFAULT NULL,
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
  -- Two-factor authentication (TOTP): secret, last step used, hashed recovery codes.
  `totp_secret` varchar(64) DEFAULT NULL,
  `totp_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `totp_enabled_at` datetime DEFAULT NULL,
  `totp_last_step` bigint DEFAULT NULL,
  `totp_recovery_codes` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  -- The login identifier (an e-mail address) must be unique (migration 041).
  UNIQUE KEY `uq_users_username` (`username`)
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
-- Number of animals authorized per species by a project authorization.
-- An animal counts once per authorization, from its first enrollment in an
-- experiment of a trial under it (reuse inside the project is not counted
-- twice). alert_level remembers the last alert sent (80 or 100 %).
CREATE TABLE `iacuc_animal_quotas` (
  `id` int NOT NULL AUTO_INCREMENT,
  `iacuc_id` varchar(255) NOT NULL,
  `species` varchar(100) NOT NULL,
  `max_animals` int NOT NULL,
  `alert_level` tinyint NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_iacuc_species` (`iacuc_id`, `species`),
  FOREIGN KEY (`iacuc_id`) REFERENCES `iacuc` (`iacuc_id`) ON UPDATE CASCADE ON DELETE CASCADE
);

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
  `weaning_age_days` int DEFAULT NULL,
  `next_seq` int NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
);

-- Weaning age of a species, used by its lines that have none of their own
-- (weaning_age_days() in animal_lib.php; else the laboratory setting).
CREATE TABLE `species_weaning_ages` (
  `species` varchar(100) NOT NULL,
  `weaning_age_days` int NOT NULL,
  PRIMARY KEY (`species`)
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
  -- Entry / exit register: date of entry into the facility and origin.
  `entry_date` date DEFAULT NULL,
  `origin_type` ENUM('internal_birth','supplier','other_facility','other','population_capture') DEFAULT NULL,
  `origin_detail` varchar(255) DEFAULT NULL,
  `origin_reference` varchar(100) DEFAULT NULL,
  `distinctive_mark` varchar(255) DEFAULT NULL,
  `genotype` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` ENUM('active','sacrificed','dead','transferred_out','archived') NOT NULL DEFAULT 'active',
  `exit_date` date DEFAULT NULL,
  `exit_reason` text DEFAULT NULL,
  `exit_destination` varchar(255) DEFAULT NULL,
  `exit_code` varchar(40) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_mice_entry_date` (`entry_date`),
  KEY `idx_mice_exit_date` (`exit_date`),
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
  `event_type` ENUM('created','transferred','status_change','experiment_start','experiment_end','endpoint_alert','endpoint_withdrawn','note','health_report','medication_administration','experiment_act') NOT NULL,
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
  -- Expected (prospective) severity, compared with the actual severity of
  -- the BEA retrospective review.
  `expected_severity` ENUM('non_recovery','mild','moderate','severe') DEFAULT NULL,
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
-- other actions by a written note such as the vet's decision), or withdrawn
-- when a correction of the follow-up no longer reaches the endpoint.
CREATE TABLE `endpoint_actions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `experiment_animal_id` int NOT NULL,
  `followup_id` int DEFAULT NULL,
  `threshold_id` int DEFAULT NULL,
  `required_action` ENUM('euthanasia','call_vet','increase_monitoring','other') NOT NULL,
  `message` varchar(512) DEFAULT NULL,
  `measured_value` decimal(10,2) DEFAULT NULL,
  `status` ENUM('pending','done','withdrawn') NOT NULL DEFAULT 'pending',
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
  `default_unit` varchar(30) NOT NULL DEFAULT 'unit', `is_narcotic` tinyint(1) NOT NULL DEFAULT 0,
  `concentration` decimal(12,4) DEFAULT NULL, `concentration_unit` varchar(30) DEFAULT NULL, `notes` text DEFAULT NULL,
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
  `id` int NOT NULL AUTO_INCREMENT, `prescription_id` int DEFAULT NULL, `experiment_act_animal_id` int DEFAULT NULL, `animal_id` int NOT NULL,
  `medication_lot_id` int NOT NULL, `administered_at` datetime NOT NULL, `quantity_used` decimal(12,3) NOT NULL,
  `unit` varchar(30) NOT NULL, `route` varchar(100) DEFAULT NULL, `notes` text DEFAULT NULL,
  `administered_by` int DEFAULT NULL, `administered_by_name` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `cancelled_at` datetime DEFAULT NULL, `cancelled_by_name` varchar(255) DEFAULT NULL, `cancel_reason` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`prescription_id`) REFERENCES `prescriptions` (`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`animal_id`) REFERENCES `mice` (`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`medication_lot_id`) REFERENCES `medication_lots` (`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`administered_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
);
CREATE TABLE `medication_stock_movements` (
  `id` int NOT NULL AUTO_INCREMENT, `medication_lot_id` int NOT NULL,
  `movement_type` ENUM('receipt','administration','adjustment','inventory','correction') NOT NULL, `quantity_delta` decimal(12,3) NOT NULL,
  `counted_quantity` decimal(12,3) DEFAULT NULL,
  `reason` text DEFAULT NULL, `administration_id` int DEFAULT NULL, `terrarium_treatment_id` int DEFAULT NULL, `performed_by` int DEFAULT NULL,
  `performed_at` timestamp NOT NULL DEFAULT current_timestamp(), PRIMARY KEY (`id`),
  FOREIGN KEY (`medication_lot_id`) REFERENCES `medication_lots` (`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`administration_id`) REFERENCES `medication_administrations` (`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`performed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
);

-- Experimental acts (injection, gavage, bait, anaesthesia, sampling...)
-- planned on all or part of the animals of an experiment, on chosen dates.
-- An act can name a substance with its dose, and be linked to a medication
-- of the pharmacy stock (no prescription needed within an experiment): the
-- lot used is then debited when the act is confirmed. An act can also ask
-- for information at confirmation (e.g. food weighed, volume given).
-- Each planned (animal, date) is confirmed during the daily follow-up as
-- done or not done.
CREATE TABLE `experiment_acts` (
  `id` int NOT NULL AUTO_INCREMENT,
  `experiment_id` int NOT NULL,
  `act_type` varchar(30) NOT NULL,
  `label` varchar(255) NOT NULL,
  `substance` varchar(255) DEFAULT NULL,
  `dose_amount` decimal(12,3) DEFAULT NULL,
  `dose_unit` varchar(30) DEFAULT NULL,
  `route` varchar(100) DEFAULT NULL,
  `product_id` int DEFAULT NULL,
  `quantity_per_animal` decimal(12,3) DEFAULT NULL,
  `instructions` text DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `created_by_name` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_experiment_act_experiment` (`experiment_id`),
  FOREIGN KEY (`experiment_id`) REFERENCES `experiments` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`product_id`) REFERENCES `medication_products` (`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
);

-- Information asked when the act is confirmed (same model as the protocol's
-- observation fields).
CREATE TABLE `experiment_act_fields` (
  `id` int NOT NULL AUTO_INCREMENT,
  `act_id` int NOT NULL,
  `position` int NOT NULL DEFAULT 0,
  `label` varchar(255) NOT NULL,
  `value_type` ENUM('number','text') NOT NULL,
  `unit` varchar(50) DEFAULT NULL,
  `required` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`act_id`) REFERENCES `experiment_acts` (`id`) ON DELETE CASCADE
);

-- One row per animal and planned date.
CREATE TABLE `experiment_act_animals` (
  `id` int NOT NULL AUTO_INCREMENT,
  `act_id` int NOT NULL,
  `experiment_animal_id` int NOT NULL,
  `planned_date` date NOT NULL,
  `status` ENUM('planned','done','not_done','cancelled') NOT NULL DEFAULT 'planned',
  `done_at` datetime DEFAULT NULL,
  `done_by` int DEFAULT NULL,
  `done_by_name` varchar(255) DEFAULT NULL,
  `supervised_by` int DEFAULT NULL,
  `supervised_by_name` varchar(255) DEFAULT NULL,
  `note` text DEFAULT NULL,
  `followup_id` int DEFAULT NULL,
  `medication_lot_id` int DEFAULT NULL,
  `quantity_used` decimal(12,3) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_act_animal_date` (`act_id`, `experiment_animal_id`, `planned_date`),
  KEY `idx_act_animal_enrollment` (`experiment_animal_id`, `planned_date`),
  FOREIGN KEY (`act_id`) REFERENCES `experiment_acts` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`experiment_animal_id`) REFERENCES `experiment_animals` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`done_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_act_animal_supervisor` FOREIGN KEY (`supervised_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  FOREIGN KEY (`followup_id`) REFERENCES `experiment_followups` (`id`) ON DELETE SET NULL,
  FOREIGN KEY (`medication_lot_id`) REFERENCES `medication_lots` (`id`) ON DELETE RESTRICT
);

-- Values entered at confirmation; label / unit are snapshots.
CREATE TABLE `experiment_act_values` (
  `id` int NOT NULL AUTO_INCREMENT,
  `act_animal_id` int NOT NULL,
  `field_id` int DEFAULT NULL,
  `label` varchar(255) NOT NULL,
  `value_type` ENUM('number','text') NOT NULL,
  `unit` varchar(50) DEFAULT NULL,
  `value_number` decimal(14,4) DEFAULT NULL,
  `value_text` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_act_value_act_animal` (`act_animal_id`),
  FOREIGN KEY (`act_animal_id`) REFERENCES `experiment_act_animals` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`field_id`) REFERENCES `experiment_act_fields` (`id`) ON DELETE SET NULL
);

-- A medication given during an experimental act is traced like a treatment
-- (one administration per animal, stock debited) but without a prescription.
ALTER TABLE `medication_administrations`
  ADD KEY `idx_medication_admin_act_animal` (`experiment_act_animal_id`),
  ADD CONSTRAINT `fk_medication_admin_act_animal` FOREIGN KEY (`experiment_act_animal_id`) REFERENCES `experiment_act_animals` (`id`) ON DELETE RESTRICT;

-- Corrections of experimental act confirmations: what was recorded (status,
-- time, person, lot, quantity, values, note) is kept with the reason of the
-- correction; the act goes back to "to do".
CREATE TABLE `experiment_act_corrections` (
  `id` int NOT NULL AUTO_INCREMENT,
  `act_animal_id` int NOT NULL,
  `previous_status` varchar(20) NOT NULL,
  `previous_done_at` datetime DEFAULT NULL,
  `previous_done_by_name` varchar(255) DEFAULT NULL,
  `previous_lot_id` int DEFAULT NULL,
  `previous_quantity` decimal(12,3) DEFAULT NULL,
  `previous_values` text DEFAULT NULL,
  `previous_note` text DEFAULT NULL,
  `reason` text NOT NULL,
  `corrected_by` int DEFAULT NULL,
  `corrected_by_name` varchar(255) DEFAULT NULL,
  `corrected_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_act_correction_act_animal` (`act_animal_id`),
  FOREIGN KEY (`act_animal_id`) REFERENCES `experiment_act_animals` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`previous_lot_id`) REFERENCES `medication_lots` (`id`) ON DELETE SET NULL,
  FOREIGN KEY (`corrected_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
);

-- Personal training record (livret de formation / de compétences) of every
-- person working with the animals, following Directive 2010/63/EU
-- (art. 23-24) and its French transposition (arrêté du 1er février 2013):
--  - initial regulatory training per function: design of procedures and
--    projects, application of procedures, care of the animals, killing;
--  - competence validated after a supervised period (tutorat);
--  - continuing training, whose minimum over a rolling period is checked
--    (by default 3 days over 6 years, adjustable in the settings).
-- An entry declared by the person is counted once validated by an
-- administrator or a BEA responsible; a certificate (PDF) can be attached.
CREATE TABLE `training_records` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `category` ENUM('diploma','initial','competence','continuing','other') NOT NULL,
  `function_code` varchar(30) DEFAULT NULL,
  `diploma_level` varchar(30) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `organisation` varchar(255) DEFAULT NULL,
  `start_date` date NOT NULL,
  `end_date` date DEFAULT NULL,
  `duration_hours` decimal(6,2) DEFAULT NULL,
  `species` varchar(255) DEFAULT NULL,
  `supervisor_name` varchar(255) DEFAULT NULL,
  `certificate_ref` varchar(100) DEFAULT NULL,
  `certificate_file` varchar(64) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` ENUM('pending','validated','rejected') NOT NULL DEFAULT 'pending',
  `validated_by` int DEFAULT NULL,
  `validated_by_name` varchar(255) DEFAULT NULL,
  `validated_at` datetime DEFAULT NULL,
  `review_note` text DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `created_by_name` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_training_user` (`user_id`, `category`, `status`, `start_date`),
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`validated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
);

-- Terrariums (or tanks, enclosures) holding populations of animals that are
-- not individually identified. The population is followed by periodic
-- approximate censuses and a journal (introductions with their reference,
-- deaths, transfers, captures); group treatments are recorded (with the
-- medication lot debited and a withdrawal period); animals captured become
-- identified animals (origin "population capture") and can be enrolled in
-- an experiment at once.
CREATE TABLE `terrariums` (
  `id` int NOT NULL AUTO_INCREMENT,
  `code` varchar(30) NOT NULL,
  `name` varchar(255) NOT NULL,
  `line_id` int DEFAULT NULL,
  `species` varchar(100) DEFAULT NULL,
  `room` varchar(100) DEFAULT NULL,
  `location` varchar(100) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `origin` varchar(255) DEFAULT NULL,
  `reference` varchar(100) DEFAULT NULL,
  `established_on` date DEFAULT NULL,
  `census_every_days` int NOT NULL DEFAULT 30,
  `health_every_days` int NOT NULL DEFAULT 90,
  `responsible_user_id` int DEFAULT NULL,
  `status` ENUM('active','archived') NOT NULL DEFAULT 'active',
  `archived_at` datetime DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_terrarium_code` (`code`),
  FOREIGN KEY (`line_id`) REFERENCES `animal_lines` (`id`) ON DELETE SET NULL,
  FOREIGN KEY (`responsible_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
);

-- Journal of a population. A census gives an estimate (and optionally a
-- range); the other events give a number of animals that entered or left.
CREATE TABLE `terrarium_events` (
  `id` int NOT NULL AUTO_INCREMENT,
  `terrarium_id` int NOT NULL,
  `event_type` ENUM('census','introduction','death','capture','transfer_out','note') NOT NULL,
  `event_date` date NOT NULL,
  `estimate` int DEFAULT NULL,
  `estimate_min` int DEFAULT NULL,
  `estimate_max` int DEFAULT NULL,
  `quantity` int DEFAULT NULL,
  `life_stage` varchar(50) DEFAULT NULL,
  `method` varchar(100) DEFAULT NULL,
  `reference` varchar(255) DEFAULT NULL,
  `note` text DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `created_by_name` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_terrarium_event` (`terrarium_id`, `event_date`),
  FOREIGN KEY (`terrarium_id`) REFERENCES `terrariums` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
);

-- Animals created by a capture (identified from then on), with their
-- individual weight at capture.
CREATE TABLE `terrarium_capture_animals` (
  `event_id` int NOT NULL,
  `animal_id` int NOT NULL,
  `weight_g` decimal(8,2) DEFAULT NULL,
  PRIMARY KEY (`event_id`, `animal_id`),
  UNIQUE KEY `uq_capture_animal` (`animal_id`),
  FOREIGN KEY (`event_id`) REFERENCES `terrarium_events` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`animal_id`) REFERENCES `mice` (`id`) ON DELETE CASCADE
);

-- Treatments of a whole population: medication of the stock (lot debited)
-- or another treatment, prescriber and prescription reference, and the date
-- until which animals must not be used (withdrawal period).
CREATE TABLE `terrarium_treatments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `terrarium_id` int NOT NULL,
  `treated_at` datetime NOT NULL,
  `kind` varchar(30) NOT NULL,
  `product_id` int DEFAULT NULL,
  `medication_lot_id` int DEFAULT NULL,
  `quantity_used` decimal(12,3) DEFAULT NULL,
  `unit` varchar(30) DEFAULT NULL,
  `substance` varchar(255) DEFAULT NULL,
  `dose` varchar(100) DEFAULT NULL,
  `method` varchar(100) DEFAULT NULL,
  `reason` text DEFAULT NULL,
  `prescriber_name` varchar(255) DEFAULT NULL,
  `prescription_reference` varchar(100) DEFAULT NULL,
  `withdrawal_until` date DEFAULT NULL,
  `performed_by` int DEFAULT NULL,
  `performed_by_name` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_terrarium_treatment` (`terrarium_id`, `treated_at`),
  FOREIGN KEY (`terrarium_id`) REFERENCES `terrariums` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`product_id`) REFERENCES `medication_products` (`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`medication_lot_id`) REFERENCES `medication_lots` (`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`performed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
);

-- Health monitoring of a population: observation of the terrarium,
-- environment parameters, or screening on samples (sent to a laboratory,
-- result recorded when it comes back). Kept as a journal: a check is never
-- changed, except to record the result of a pending screening.
CREATE TABLE `terrarium_health_checks` (
  `id` int NOT NULL AUTO_INCREMENT,
  `terrarium_id` int NOT NULL,
  `checked_on` date NOT NULL,
  `check_type` ENUM('observation','environment','screening') NOT NULL,
  `status` ENUM('normal','abnormal') NOT NULL DEFAULT 'normal',
  `signs` text DEFAULT NULL,
  `sick_count` int DEFAULT NULL,
  `temperature_c` decimal(5,1) DEFAULT NULL,
  `humidity_pct` int DEFAULT NULL,
  `parameters` varchar(255) DEFAULT NULL,
  `sample_type` varchar(100) DEFAULT NULL,
  `animals_sampled` int DEFAULT NULL,
  `laboratory` varchar(255) DEFAULT NULL,
  `sample_reference` varchar(100) DEFAULT NULL,
  `agents_screened` varchar(255) DEFAULT NULL,
  `result` ENUM('pending','negative','positive') DEFAULT NULL,
  `result_detail` text DEFAULT NULL,
  `result_on` date DEFAULT NULL,
  `result_by_name` varchar(255) DEFAULT NULL,
  `actions` text DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `created_by_name` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_terrarium_health` (`terrarium_id`, `checked_on`),
  FOREIGN KEY (`terrarium_id`) REFERENCES `terrariums` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
);

-- A stock movement may come from a population treatment (no individual animal).
ALTER TABLE `medication_stock_movements`
  ADD CONSTRAINT `fk_movement_terrarium_treatment` FOREIGN KEY (`terrarium_treatment_id`) REFERENCES `terrarium_treatments` (`id`) ON DELETE RESTRICT;

-- Table for storing system settings
CREATE TABLE `settings` (
  `name` varchar(255) NOT NULL,
  `value` varchar(255) NOT NULL,
  PRIMARY KEY (`name`)
);

-- Cages under experiment need every animal's follow-up to be validated on the
-- daily round, from installation day on.
INSERT INTO `settings` (`name`, `value`) VALUES ('experimental_round_since', CURDATE());

-- Continuing training requirement of the training records (days over years).
INSERT INTO `settings` (`name`, `value`) VALUES ('training_cpd_days', '3'), ('training_cpd_years', '6'), ('training_hours_per_day', '7');

-- Inactivity (minutes) before sign-out, set in Manage Lab.
INSERT INTO `settings` (`name`, `value`) VALUES ('session_timeout_minutes', '30');

-- Morning reminder of the acts of the day; two-factor obligation for administrators and BEA responsibles.
INSERT INTO `settings` (`name`, `value`) VALUES ('acts_reminder_time', '07:30'), ('require_2fa_privileged', '1');

-- Corrections of a daily experimental follow-up: re-saving a day that was
-- already entered requires a reason, and the values it replaces (weight,
-- scores, observations, author) are kept here.
CREATE TABLE `experiment_followup_revisions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `followup_id` int NOT NULL,
  `previous_values` JSON NOT NULL,
  `reason` text NOT NULL,
  `revised_by` int DEFAULT NULL,
  `revised_by_name` varchar(255) DEFAULT NULL,
  `revised_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_followup_revision_followup` (`followup_id`),
  FOREIGN KEY (`followup_id`) REFERENCES `experiment_followups` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`revised_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
);

-- Audit and traceability tables are append-only, whatever the code path
-- (like the health registry, cage checks and cage lifecycle). Rows removed
-- with their parent by a foreign key cascade are not concerned. Creating
-- triggers needs an administrator account of the database (tools/migrate.php).
CREATE TRIGGER `experiment_followup_revisions_no_update` BEFORE UPDATE ON `experiment_followup_revisions`
  FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Follow-up revisions are append-only';
CREATE TRIGGER `experiment_followup_revisions_no_delete` BEFORE DELETE ON `experiment_followup_revisions`
  FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Follow-up revisions are append-only';
CREATE TRIGGER `activity_log_no_update` BEFORE UPDATE ON `activity_log`
  FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Activity log is append-only';
CREATE TRIGGER `activity_log_no_delete` BEFORE DELETE ON `activity_log`
  FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Activity log is append-only';
CREATE TRIGGER `animal_events_no_update` BEFORE UPDATE ON `animal_events`
  FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Animal events are append-only';
CREATE TRIGGER `animal_events_no_delete` BEFORE DELETE ON `animal_events`
  FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Animal events are append-only';
CREATE TRIGGER `animal_movements_no_update` BEFORE UPDATE ON `animal_movements`
  FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Animal movements are append-only';
CREATE TRIGGER `animal_movements_no_delete` BEFORE DELETE ON `animal_movements`
  FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Animal movements are append-only';
CREATE TRIGGER `experiment_act_corrections_no_update` BEFORE UPDATE ON `experiment_act_corrections`
  FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Act corrections are append-only';
CREATE TRIGGER `experiment_act_corrections_no_delete` BEFORE DELETE ON `experiment_act_corrections`
  FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Act corrections are append-only';
CREATE TRIGGER `litter_events_no_update` BEFORE UPDATE ON `litter_events`
  FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Litter events are append-only';
CREATE TRIGGER `litter_events_no_delete` BEFORE DELETE ON `litter_events`
  FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Litter events are append-only';
CREATE TRIGGER `terrarium_events_no_update` BEFORE UPDATE ON `terrarium_events`
  FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Terrarium events are append-only';
CREATE TRIGGER `terrarium_events_no_delete` BEFORE DELETE ON `terrarium_events`
  FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Terrarium events are append-only';
CREATE TRIGGER `medication_stock_movements_no_update` BEFORE UPDATE ON `medication_stock_movements`
  FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Stock movements are append-only';
CREATE TRIGGER `medication_stock_movements_no_delete` BEFORE DELETE ON `medication_stock_movements`
  FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Stock movements are append-only';

-- Corrections of regulatory records made after the fact (entry of an animal
-- into the facility, its exit): the reason is required and the values
-- replaced are kept next to the new ones. Append-only.
CREATE TABLE `record_corrections` (
  `id` int NOT NULL AUTO_INCREMENT,
  `entity_type` varchar(50) NOT NULL,
  `entity_id` int NOT NULL,
  `record` varchar(50) NOT NULL,
  `previous_values` JSON NOT NULL,
  `new_values` JSON NOT NULL,
  `reason` text NOT NULL,
  `corrected_by` int DEFAULT NULL,
  `corrected_by_name` varchar(255) DEFAULT NULL,
  `corrected_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_record_corrections_entity` (`entity_type`, `entity_id`),
  FOREIGN KEY (`corrected_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
);
CREATE TRIGGER `record_corrections_no_update` BEFORE UPDATE ON `record_corrections`
  FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Record corrections are append-only';
CREATE TRIGGER `record_corrections_no_delete` BEFORE DELETE ON `record_corrections`
  FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Record corrections are append-only';

-- Prescriptions and their animals are never changed nor removed once
-- recorded (only their status may evolve). An administration is never
-- removed; it can only be cancelled once (cancellation fields), the
-- administration itself staying as recorded.
DELIMITER $$
CREATE TRIGGER `prescriptions_no_update` BEFORE UPDATE ON `prescriptions` FOR EACH ROW
BEGIN
  IF NOT (NEW.animal_id <=> OLD.animal_id AND NEW.product_id <=> OLD.product_id
      AND NEW.prescription_reference <=> OLD.prescription_reference AND NEW.prescriber_name <=> OLD.prescriber_name
      AND NEW.prescribed_on <=> OLD.prescribed_on AND NEW.start_date <=> OLD.start_date AND NEW.end_date <=> OLD.end_date
      AND NEW.dose_amount <=> OLD.dose_amount AND NEW.dose_unit <=> OLD.dose_unit AND NEW.route <=> OLD.route
      AND NEW.frequency <=> OLD.frequency AND NEW.instructions <=> OLD.instructions
      AND NEW.recorded_by <=> OLD.recorded_by AND NEW.created_at <=> OLD.created_at) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Prescriptions are append-only (only the status may change)';
  END IF;
END$$
CREATE TRIGGER `medication_administrations_no_update` BEFORE UPDATE ON `medication_administrations` FOR EACH ROW
BEGIN
  IF NOT (NEW.prescription_id <=> OLD.prescription_id AND NEW.experiment_act_animal_id <=> OLD.experiment_act_animal_id
      AND NEW.animal_id <=> OLD.animal_id AND NEW.medication_lot_id <=> OLD.medication_lot_id
      AND NEW.administered_at <=> OLD.administered_at AND NEW.quantity_used <=> OLD.quantity_used
      AND NEW.unit <=> OLD.unit AND NEW.route <=> OLD.route AND NEW.notes <=> OLD.notes
      AND NEW.administered_by <=> OLD.administered_by AND NEW.administered_by_name <=> OLD.administered_by_name
      AND NEW.created_at <=> OLD.created_at)
     OR (OLD.cancelled_at IS NOT NULL AND NOT (NEW.cancelled_at <=> OLD.cancelled_at
      AND NEW.cancelled_by_name <=> OLD.cancelled_by_name AND NEW.cancel_reason <=> OLD.cancel_reason)) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Administrations are append-only (they can only be cancelled once)';
  END IF;
END$$
DELIMITER ;
CREATE TRIGGER `prescriptions_no_delete` BEFORE DELETE ON `prescriptions`
  FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Prescriptions are append-only';
CREATE TRIGGER `prescription_animals_no_update` BEFORE UPDATE ON `prescription_animals`
  FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Prescriptions are append-only';
CREATE TRIGGER `prescription_animals_no_delete` BEFORE DELETE ON `prescription_animals`
  FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Prescriptions are append-only';
CREATE TRIGGER `medication_administrations_no_delete` BEFORE DELETE ON `medication_administrations`
  FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Administrations are append-only';

-- Insert initial data into the users table
INSERT INTO `users` (`name`, `username`, `position`, `role`, `password`, `status`, `reset_token`, `reset_token_expiration`, `login_attempts`, `account_locked`, `email_verified`, `email_token`, `initials`, `is_bea_responsible`)
VALUES ('Administrateur temporaire', 'admin@myvivarium.online', 'Principal Investigator', 'admin', '$2y$10$roVlhpjZsRFXY.m9JtRB/OAaN2dp50O7D2J5idI2MsUotxMyrHRZ6', 'approved', NULL, NULL, 0, NULL, 1, NULL, 'TAN', 1);

-- Insert initial data into the strains table
INSERT INTO `strains` (`str_id`, `str_name`, `str_aka`, `str_url`, `str_rrid`)
VALUES ('035561', 'STOCK Tc(HSA21,CAG-EGFP)1Yakaz/J', 'B6D2F1 TcMAC21', 'https://www.jax.org/strain/035561', 'IMSR_JAX:035561');

-- Insert initial data into the animal_lines table
INSERT INTO `animal_lines` (`code`, `species`, `name`) VALUES
  ('RSD', 'Rat', 'Rat Sprague Dawley'),
  ('RY139', 'Rat', 'Rat Y139'),
  ('RN', 'Rat noir (Rattus rattus)', 'Rat noir'),
  ('CGN', 'Campagnol', 'Campagnol');

-- An animal with a regulatory history cannot be deleted, even by the
-- administrator's hard delete (animal_hard_delete.php): its rows would be
-- removed by the foreign key cascades, which do not fire the append-only
-- triggers. Only an animal created by mistake (no exit, experiment, health
-- report, treatment, breeding, offspring, transfer, correction or capture)
-- can be deleted. The same rule is checked first by
-- animal_regulatory_history() (animal_lib.php), which explains the refusal.
DELIMITER $$
CREATE TRIGGER `mice_no_delete_with_history` BEFORE DELETE ON `mice` FOR EACH ROW
BEGIN
  IF OLD.status <> 'active'
     OR EXISTS (SELECT 1 FROM experiment_animals WHERE animal_id = OLD.id)
     OR EXISTS (SELECT 1 FROM health_reports WHERE animal_id = OLD.id)
     OR EXISTS (SELECT 1 FROM prescription_animals WHERE animal_id = OLD.id)
     OR EXISTS (SELECT 1 FROM medication_administrations WHERE animal_id = OLD.id)
     OR EXISTS (SELECT 1 FROM breeding_animals WHERE animal_id = OLD.id)
     OR EXISTS (SELECT 1 FROM litters WHERE sire_id = OLD.id OR dam_id = OLD.id)
     OR EXISTS (SELECT 1 FROM animal_parentage WHERE sire_id = OLD.id OR dam_id = OLD.id OR (animal_id = OLD.id AND litter_id IS NOT NULL))
     OR EXISTS (SELECT 1 FROM animal_movements WHERE animal_id = OLD.id AND from_cage_id IS NOT NULL)
     OR EXISTS (SELECT 1 FROM record_corrections WHERE entity_type = 'animal' AND entity_id = OLD.id)
     OR EXISTS (SELECT 1 FROM terrarium_capture_animals WHERE animal_id = OLD.id)
     OR EXISTS (SELECT 1 FROM animal_events WHERE animal_id = OLD.id AND event_type NOT IN ('created', 'note')) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Animal with a regulatory history: record an exit instead of deleting it';
  END IF;
END$$
DELIMITER ;

-- Comments on an endpoint action not carried out at once (why, what is
-- planned), append-only.
CREATE TABLE `endpoint_action_comments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `action_id` int NOT NULL,
  `comment` text NOT NULL,
  `created_by` int DEFAULT NULL,
  `created_by_name` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_endpoint_action_comments_action` (`action_id`),
  FOREIGN KEY (`action_id`) REFERENCES `endpoint_actions` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
);
CREATE TRIGGER `endpoint_action_comments_no_update` BEFORE UPDATE ON `endpoint_action_comments`
  FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Endpoint action comments are append-only';
CREATE TRIGGER `endpoint_action_comments_no_delete` BEFORE DELETE ON `endpoint_action_comments`
  FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Endpoint action comments are append-only';

-- Temperature of each room, entered on the daily round: the minimum and
-- maximum of the day, or the current temperature (setting
-- room_temperature_mode: 'min_max' by default, or 'current'). Readings are
-- kept as entered (append-only): the latest one of the day is shown, a new
-- entry corrects it.
CREATE TABLE `room_temperatures` (
  `id` int NOT NULL AUTO_INCREMENT,
  `room` varchar(255) NOT NULL,
  `reading_date` date NOT NULL,
  `mode` ENUM('min_max','current') NOT NULL,
  `temp_min` decimal(4,1) DEFAULT NULL,
  `temp_max` decimal(4,1) DEFAULT NULL,
  `temp_current` decimal(4,1) DEFAULT NULL,
  `recorded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `recorded_by` int DEFAULT NULL,
  `recorded_by_name` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_room_temperatures_date` (`reading_date`, `room`),
  FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
);
CREATE TRIGGER `room_temperatures_no_update` BEFORE UPDATE ON `room_temperatures`
  FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Room temperatures are append-only';
CREATE TRIGGER `room_temperatures_no_delete` BEFORE DELETE ON `room_temperatures`
  FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Room temperatures are append-only';

-- Migrations already included in this schema (tools/migrate.php applies the
-- later ones). Keep this list in step with database/migrations.
CREATE TABLE `schema_migrations` (
  `version` varchar(255) NOT NULL,
  `applied_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`version`)
);
INSERT INTO `schema_migrations` (`version`) VALUES
  ('001_traceability.sql'),
  ('002_myvivarium2_parity.sql'),
  ('003_auto_ids_bulk_add.sql'),
  ('004_health_registry.sql'),
  ('005_daily_cage_checks.sql'),
  ('006_cage_lifecycle.sql'),
  ('007_animal_fate_on_cage_retirement.sql'),
  ('008_exit_codes.sql'),
  ('009_clinical_grids_endpoint_actions.sql'),
  ('010_followup_observations.sql'),
  ('011_litters_weaning.sql'),
  ('012_distinctive_mark.sql'),
  ('013_jcms_import_and_scheduling.sql'),
  ('014_localization.sql'),
  ('015_experiment_cage_followup_and_retro.sql'),
  ('016_experiment_number_sequence.sql'),
  ('017_pharmacy_prescriptions_administrations.sql'),
  ('018_medication_lot_in_use_date.sql'),
  ('019_prescription_multi_animal.sql'),
  ('020_admins_bea_responsible.sql'),
  ('021_experimental_cages.sql'),
  ('022_experimental_cages_from_enrollments.sql'),
  ('023_ethics_authorizations.sql'),
  ('024_experimental_cage_round.sql'),
  ('025_protocol_expected_severity.sql'),
  ('026_animal_entry_register.sql'),
  ('027_experiment_acts.sql'),
  ('028_narcotics_corrections_weight_dose.sql'),
  ('029_training_records.sql'),
  ('030_act_supervisor_initial_diploma.sql'),
  ('031_session_timeout_setting.sql'),
  ('032_quotas_act_reminders_two_factor.sql'),
  ('033_terrarium_populations.sql'),
  ('034_terrarium_capture_weight.sql'),
  ('035_followup_revisions_append_only_logs.sql'),
  ('036_register_corrections_prescriptions_protected.sql'),
  ('037_animal_hard_delete_guard.sql'),
  ('038_endpoint_withdrawal_and_comments.sql'),
  ('039_weaning_age_by_species_and_line.sql'),
  ('040_room_temperatures.sql'),
  ('041_unique_username.sql');
