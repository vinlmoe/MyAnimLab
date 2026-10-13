-- Migration: daily cage checks (every active cage seen at least once a day).
-- Run after 004_health_registry.sql. As for 004, creating the triggers may
-- require the SUPER privilege (or log_bin_trust_function_creators = 1) when
-- MySQL binary logging is enabled.

-- Daily cage checks (visite journalière). Every active cage must be seen at
-- least once a day, either by the routine round (room by room or by scanning
-- the cage QR code) or automatically through the experimental daily follow-up
-- or a health report. Append-only, like the health registry: no FK to cages
-- so the history survives a cage's removal, and triggers reject UPDATE/DELETE.
CREATE TABLE `cage_checks` (
  `id` int NOT NULL AUTO_INCREMENT,
  `cage_id` varchar(255) NOT NULL,
  `check_date` date NOT NULL,
  `check_type` ENUM('routine','experimental','health_report') NOT NULL,
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
