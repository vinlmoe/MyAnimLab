SET NAMES utf8mb4;
ALTER TABLE `medication_lots` ADD COLUMN `in_use_since` date DEFAULT NULL AFTER `received_at`;
