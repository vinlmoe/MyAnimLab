SET NAMES utf8mb4;

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
