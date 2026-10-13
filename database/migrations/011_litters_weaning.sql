SET NAMES utf8mb4;

-- Migration: litter lifecycle from birth to weaning (pups become animals
-- with parentage), weaning reminders, animals' date of birth. Run after 010.

ALTER TABLE `litters`
  MODIFY `dom` date DEFAULT NULL,
  ADD COLUMN `sire_id` int DEFAULT NULL,
  ADD COLUMN `dam_id` int DEFAULT NULL,
  ADD COLUMN `status` ENUM('nursing','weaned','closed') NOT NULL DEFAULT 'nursing',
  ADD COLUMN `expected_weaning_date` date DEFAULT NULL,
  ADD COLUMN `weaned_at` date DEFAULT NULL,
  ADD COLUMN `weaned_males` int DEFAULT NULL,
  ADD COLUMN `weaned_females` int DEFAULT NULL,
  ADD COLUMN `closed_reason` text DEFAULT NULL;

ALTER TABLE `mice` ADD COLUMN `dob` date DEFAULT NULL AFTER `sex`;

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

-- Existing litters: recent ones (born in the last 30 days) are still nursing
-- and get an expected weaning date (21 days); older ones are closed as legacy.
UPDATE `litters` SET status = 'closed', closed_reason = 'Recorded before weaning tracking'
  WHERE litter_dob IS NULL OR litter_dob < CURDATE() - INTERVAL 30 DAY;
UPDATE `litters` SET expected_weaning_date = litter_dob + INTERVAL 21 DAY WHERE status = 'nursing';

-- Animals' date of birth from their holding cage when known.
UPDATE `mice` m JOIN `holding` h ON h.cage_id = m.cage_id SET m.dob = h.dob WHERE m.dob IS NULL;
