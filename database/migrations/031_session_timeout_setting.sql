SET NAMES utf8mb4;

-- Inactivity (minutes) before a user is signed out, set in Manage Lab and
-- applied by session_config.php on every page (5 to 720, default 30).
INSERT INTO `settings` (`name`, `value`) VALUES ('session_timeout_minutes', '30')
ON DUPLICATE KEY UPDATE `value` = `value`;
