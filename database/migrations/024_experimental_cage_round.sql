SET NAMES utf8mb4;

-- A cage holding animals under experiment is validated on the daily round
-- only once every one of those animals has its experimental follow-up for
-- the day (the "all OK" button is replaced by the cage's grouped follow-up).
-- The rule applies from today: days already recorded are left unchanged.
INSERT INTO `settings` (`name`, `value`) VALUES ('experimental_round_since', CURDATE())
ON DUPLICATE KEY UPDATE `value` = `value`;
