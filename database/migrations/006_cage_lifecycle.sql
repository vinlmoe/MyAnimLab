-- Migration: cage creation / retirement dates, so the daily round only
-- expects a cage to be seen on days it existed. Run after 005.
-- As for 004/005, creating triggers may require the SUPER privilege (or
-- log_bin_trust_function_creators = 1) when MySQL binary logging is enabled.

ALTER TABLE `cages`
  ADD COLUMN `created_at` timestamp NULL DEFAULT NULL AFTER `rack`,
  ADD COLUMN `retired_at` timestamp NULL DEFAULT NULL AFTER `created_at`;

-- New cages get their creation time automatically from now on.
ALTER TABLE `cages` MODIFY `created_at` timestamp NULL DEFAULT current_timestamp();

ALTER TABLE `cage_checks`
  MODIFY `check_type` ENUM('routine','experimental','health_report','creation') NOT NULL;

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

-- Backfill existing cages from the best available history. Cages with no
-- trace keep created_at NULL, meaning "existed before records began".
UPDATE `cages` c SET c.created_at = LEAST(
    COALESCE((SELECT MIN(a.created_at) FROM activity_log a WHERE a.entity_type = 'cage' AND a.action = 'create' AND a.entity_id = c.cage_id), '2038-01-01'),
    COALESCE((SELECT MIN(m.moved_at) FROM animal_movements m WHERE m.to_cage_id = c.cage_id), '2038-01-01')
);
UPDATE `cages` SET created_at = NULL WHERE created_at = '2038-01-01 00:00:00';

UPDATE `cages` c SET c.retired_at = COALESCE(
    (SELECT MAX(a.created_at) FROM activity_log a WHERE a.entity_type = 'cage' AND a.action = 'archive' AND a.entity_id = c.cage_id),
    CURRENT_TIMESTAMP
) WHERE c.status = 'archived';

INSERT INTO `cage_lifecycle` (cage_id, event, event_at, reason)
SELECT cage_id, 'created', created_at, 'Backfilled by migration 006' FROM `cages` WHERE created_at IS NOT NULL;
INSERT INTO `cage_lifecycle` (cage_id, event, event_at, reason)
SELECT cage_id, 'retired', retired_at, 'Backfilled by migration 006' FROM `cages` WHERE retired_at IS NOT NULL;
