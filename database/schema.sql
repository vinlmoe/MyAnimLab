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
  PRIMARY KEY (`id`)
);

-- Table for storing cage information
CREATE TABLE `cages` (
  `cage_id` varchar(255) NOT NULL UNIQUE,
  `pi_name` int DEFAULT NULL,
  `quantity` int DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `status` ENUM('active','archived') NOT NULL DEFAULT 'active',
  `room` varchar(255) DEFAULT NULL,
  `rack` varchar(255) DEFAULT NULL,
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
  `dom` date NOT NULL,
  `litter_dob` date DEFAULT NULL,
  `pups_alive` int NOT NULL,
  `pups_dead` int NOT NULL,
  `pups_male` int DEFAULT NULL,
  `pups_female` int DEFAULT NULL,
  `remarks` text DEFAULT NULL,
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

-- Table for storing mouse/animal information related to cages
CREATE TABLE `mice` (
  `id` int NOT NULL AUTO_INCREMENT,
  `cage_id` varchar(255) DEFAULT NULL,
  `line_id` int DEFAULT NULL,
  `animal_uid` varchar(50) DEFAULT NULL UNIQUE,
  `mouse_id` varchar(255) DEFAULT NULL,
  `sex` ENUM('male','female') DEFAULT NULL,
  `genotype` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` ENUM('active','sacrificed','transferred_out','archived') NOT NULL DEFAULT 'active',
  `exit_date` date DEFAULT NULL,
  `exit_reason` text DEFAULT NULL,
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
  `event_type` ENUM('created','transferred','status_change','experiment_start','experiment_end','endpoint_alert','note') NOT NULL,
  `payload` JSON DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_animal_events_animal` (`animal_id`),
  FOREIGN KEY (`animal_id`) REFERENCES `mice` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
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
  `type` ENUM('reminder','task','system','endpoint_alert') NOT NULL DEFAULT 'system',
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

-- Table for storing system settings
CREATE TABLE `settings` (
  `name` varchar(255) NOT NULL,
  `value` varchar(255) NOT NULL,
  PRIMARY KEY (`name`)
);

-- Insert initial data into the users table
INSERT INTO `users` (`name`, `username`, `position`, `role`, `password`, `status`, `reset_token`, `reset_token_expiration`, `login_attempts`, `account_locked`, `email_verified`, `email_token`, `initials`)
VALUES ('Temporary Admin', 'admin@myvivarium.online', 'Principal Investigator', 'admin', '$2y$10$roVlhpjZsRFXY.m9JtRB/OAaN2dp50O7D2J5idI2MsUotxMyrHRZ6', 'approved', NULL, NULL, 0, NULL, 1, NULL, 'TAN');

-- Insert initial data into the strains table
INSERT INTO `strains` (`str_id`, `str_name`, `str_aka`, `str_url`, `str_rrid`)
VALUES ('035561', 'STOCK Tc(HSA21,CAG-EGFP)1Yakaz/J', 'B6D2F1 TcMAC21', 'https://www.jax.org/strain/035561', 'IMSR_JAX:035561');

-- Insert initial data into the animal_lines table
INSERT INTO `animal_lines` (`code`, `species`, `name`) VALUES
  ('RSD', 'Rat', 'Rat Sprague Dawley'),
  ('RY139', 'Rat', 'Rat Y139'),
  ('RN', 'Rat noir (Rattus rattus)', 'Rat noir'),
  ('CGN', 'Campagnol', 'Campagnol');
