SET NAMES utf8mb4;

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
  MODIFY `prescription_id` int DEFAULT NULL,
  ADD COLUMN `experiment_act_animal_id` int DEFAULT NULL AFTER `prescription_id`,
  ADD UNIQUE KEY `uq_medication_admin_act_animal` (`experiment_act_animal_id`),
  ADD CONSTRAINT `fk_medication_admin_act_animal` FOREIGN KEY (`experiment_act_animal_id`) REFERENCES `experiment_act_animals` (`id`) ON DELETE RESTRICT;

-- Animal journal: experimental acts confirmed, and medications given (the
-- latter was logged by the administrations page without being allowed).
ALTER TABLE `animal_events`
  MODIFY `event_type` ENUM('created','transferred','status_change','experiment_start','experiment_end','endpoint_alert','note','health_report','medication_administration','experiment_act') NOT NULL;
