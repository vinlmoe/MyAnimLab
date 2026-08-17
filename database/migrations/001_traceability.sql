-- Migration: animal traceability (lines, unique IDs, transfers, parentage, audit log,
-- multi-breeder cages, and the trial > protocol > experiment module).
-- Run once against an existing MyAnimLab database (after schema.sql).

-- ---------------------------------------------------------------------------
-- 1. Animal lines (lineage code + species) used to build unique animal IDs
--    such as RSD-0002 (Rat Sprague Dawley) or RY139-0023 (Rat Y139).
-- ---------------------------------------------------------------------------
CREATE TABLE `animal_lines` (
  `id` int NOT NULL AUTO_INCREMENT,
  `code` varchar(20) NOT NULL UNIQUE,
  `species` varchar(100) NOT NULL,
  `name` varchar(255) NOT NULL,
  `notes` text DEFAULT NULL,
  `next_seq` int NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
);

INSERT INTO `animal_lines` (`code`, `species`, `name`) VALUES
  ('RSD', 'Rat', 'Rat Sprague Dawley'),
  ('RY139', 'Rat', 'Rat Y139'),
  ('RN', 'Rat noir (Rattus rattus)', 'Rat noir'),
  ('CGN', 'Campagnol', 'Campagnol');

-- ---------------------------------------------------------------------------
-- 2. Detach animal identity from the cage: unique animal_uid + lineage + status
-- ---------------------------------------------------------------------------

-- mice.cage_id must become nullable with ON DELETE SET NULL so an archived
-- animal survives its cage being removed (drop the pre-existing FK first;
-- its auto-generated name may differ per install - check with
-- `SHOW CREATE TABLE mice` and adjust the constraint name below if needed).
ALTER TABLE `mice` DROP FOREIGN KEY `mice_ibfk_1`;
ALTER TABLE `mice` MODIFY `cage_id` varchar(255) DEFAULT NULL;
ALTER TABLE `mice` ADD CONSTRAINT `fk_mice_cage` FOREIGN KEY (`cage_id`) REFERENCES `cages` (`cage_id`) ON UPDATE CASCADE ON DELETE SET NULL;

ALTER TABLE `mice`
  ADD COLUMN `line_id` int DEFAULT NULL AFTER `cage_id`,
  ADD COLUMN `animal_uid` varchar(50) DEFAULT NULL AFTER `line_id`,
  ADD COLUMN `status` ENUM('active','sacrificed','transferred_out','archived') NOT NULL DEFAULT 'active' AFTER `notes`,
  ADD COLUMN `exit_date` date DEFAULT NULL AFTER `status`,
  ADD COLUMN `exit_reason` text DEFAULT NULL AFTER `exit_date`,
  ADD CONSTRAINT `fk_mice_line` FOREIGN KEY (`line_id`) REFERENCES `animal_lines` (`id`) ON DELETE SET NULL;

-- animal_uid is enforced unique once every existing row has been backfilled
-- (see notes at the bottom of this file for the backfill procedure).
ALTER TABLE `mice` ADD UNIQUE KEY `uq_mice_animal_uid` (`animal_uid`);

-- ---------------------------------------------------------------------------
-- 3. Cage transfer history (source of truth for "which cages has this animal
--    passed through")
-- ---------------------------------------------------------------------------
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

-- ---------------------------------------------------------------------------
-- 4. Multiple breeders per breeding cage + formal parentage (replaces the
--    free-text parent_cg / single male_id / female_id columns)
-- ---------------------------------------------------------------------------
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

-- ---------------------------------------------------------------------------
-- 5. Generic audit trail (chronological view per animal: created, transferred,
--    status changes, experiment enrollment, endpoint alerts, notes)
-- ---------------------------------------------------------------------------
CREATE TABLE `animal_events` (
  `id` int NOT NULL AUTO_INCREMENT,
  `animal_id` int NOT NULL,
  `event_type` ENUM('created','transferred','status_change','experiment_start','experiment_end','endpoint_alert','note') NOT NULL,
  `payload` JSON DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_animal_events_animal` (`animal_id`),
  FOREIGN KEY (`animal_id`) REFERENCES `mice` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
);

-- ---------------------------------------------------------------------------
-- 6. Experimentation module: trial (essai) -> protocol(s) -> experiment(s)
-- ---------------------------------------------------------------------------
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
  PRIMARY KEY (`id`),
  FOREIGN KEY (`trial_id`) REFERENCES `trials` (`id`) ON DELETE CASCADE
);

CREATE TABLE `protocol_endpoint_thresholds` (
  `id` int NOT NULL AUTO_INCREMENT,
  `protocol_id` int NOT NULL,
  `metric` ENUM('weight_loss_pct','clinical_score','custom') NOT NULL,
  `operator` ENUM('>','>=','<','<=') NOT NULL,
  `value` decimal(10,2) NOT NULL,
  `action_message` varchar(255) DEFAULT NULL,
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

-- ---------------------------------------------------------------------------
-- Notes on rollout
-- ---------------------------------------------------------------------------
-- Backfill for existing `mice` rows before the UNIQUE constraint on
-- animal_uid can be enforced on a non-empty table:
--
--   UPDATE mice SET animal_uid = CONCAT('LEGACY-', LPAD(id, 6, '0'))
--   WHERE animal_uid IS NULL;
--
-- New animals must go through the application flow, which assigns
-- animal_uid from an animal_lines.code + animal_lines.next_seq (see
-- animal_lines_manage.php / hc_addn.php / bc_addn.php).
