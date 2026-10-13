-- Data below contains accented text: load it as UTF-8 whatever the client default.
SET NAMES utf8mb4;

-- Migration: clinical scoring grids for the daily follow-up, endpoints on
-- grid scores with a required action (euthanasia, call the vet...), and the
-- pending endpoint actions. Run after 008.

ALTER TABLE `protocols` ADD COLUMN `clinical_grid_id` int DEFAULT NULL AFTER `status`;

ALTER TABLE `protocol_endpoint_thresholds`
  MODIFY `metric` ENUM('weight_loss_pct','clinical_score','custom','item_score') NOT NULL,
  ADD COLUMN `grid_item_id` int DEFAULT NULL AFTER `metric`,
  ADD COLUMN `required_action` ENUM('none','euthanasia','call_vet','increase_monitoring','other') NOT NULL DEFAULT 'none' AFTER `action_message`;

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
