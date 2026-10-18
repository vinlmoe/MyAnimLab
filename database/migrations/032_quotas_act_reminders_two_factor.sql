SET NAMES utf8mb4;

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

-- Two-factor authentication (TOTP, RFC 6238): secret, last time step used
-- (no replay), hashed one-time recovery codes.
ALTER TABLE `users`
  ADD COLUMN `totp_secret` varchar(64) DEFAULT NULL,
  ADD COLUMN `totp_enabled` tinyint(1) NOT NULL DEFAULT 0,
  ADD COLUMN `totp_enabled_at` datetime DEFAULT NULL,
  ADD COLUMN `totp_last_step` bigint DEFAULT NULL,
  ADD COLUMN `totp_recovery_codes` text DEFAULT NULL;

-- Morning reminder of the experimental acts of the day (lab time), and the
-- two-factor obligation for administrators and BEA responsibles.
INSERT INTO `settings` (`name`, `value`) VALUES ('acts_reminder_time', '07:30'), ('require_2fa_privileged', '1')
ON DUPLICATE KEY UPDATE `value` = `value`;
