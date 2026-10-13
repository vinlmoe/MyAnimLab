-- Migration: animal health (sanitary) reports with an append-only follow-up
-- registry. Run after 003_auto_ids_bulk_add.sql.
-- Note: with binary logging enabled, MySQL may require the SUPER privilege
-- (or log_bin_trust_function_creators = 1) to create the triggers below.

ALTER TABLE `animal_events`
  MODIFY `event_type` ENUM('created','transferred','status_change','experiment_start','experiment_end','endpoint_alert','note','health_report') NOT NULL;

ALTER TABLE `notifications`
  MODIFY `type` ENUM('reminder','task','system','endpoint_alert','health_alert') NOT NULL DEFAULT 'system';

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
