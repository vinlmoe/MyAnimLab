SET NAMES utf8mb4;

-- Temperature of each room, entered on the daily round: the minimum and
-- maximum of the day, or the current temperature (setting
-- room_temperature_mode: 'min_max' by default, or 'current'). Readings are
-- kept as entered (append-only): the latest one of the day is shown, a new
-- entry corrects it.
CREATE TABLE `room_temperatures` (
  `id` int NOT NULL AUTO_INCREMENT,
  `room` varchar(255) NOT NULL,
  `reading_date` date NOT NULL,
  `mode` ENUM('min_max','current') NOT NULL,
  `temp_min` decimal(4,1) DEFAULT NULL,
  `temp_max` decimal(4,1) DEFAULT NULL,
  `temp_current` decimal(4,1) DEFAULT NULL,
  `recorded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `recorded_by` int DEFAULT NULL,
  `recorded_by_name` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_room_temperatures_date` (`reading_date`, `room`),
  FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
);
CREATE TRIGGER `room_temperatures_no_update` BEFORE UPDATE ON `room_temperatures`
  FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Room temperatures are append-only';
CREATE TRIGGER `room_temperatures_no_delete` BEFORE DELETE ON `room_temperatures`
  FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Room temperatures are append-only';
