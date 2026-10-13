-- JCMS import support, animal-use planning, and room-level health history.

CREATE TABLE `animal_use_schedule` (
  `id` int NOT NULL AUTO_INCREMENT,
  `animal_id` int NOT NULL,
  `use_label` varchar(255) NOT NULL,
  `projected_date` date DEFAULT NULL,
  `actual_date` date DEFAULT NULL,
  `completed` tinyint(1) NOT NULL DEFAULT 0,
  `notes` text DEFAULT NULL,
  `source_system` varchar(30) DEFAULT NULL,
  `source_key` varchar(64) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_use_source` (`source_system`, `source_key`),
  KEY `idx_use_schedule_animal_date` (`animal_id`, `projected_date`),
  FOREIGN KEY (`animal_id`) REFERENCES `mice` (`id`) ON DELETE CASCADE
);

CREATE TABLE `room_health_history` (
  `id` int NOT NULL AUTO_INCREMENT,
  `room` varchar(255) NOT NULL,
  `health_level` varchar(100) NOT NULL,
  `start_date` date DEFAULT NULL,
  `source_system` varchar(30) DEFAULT NULL,
  `source_key` varchar(64) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_room_health_source` (`source_system`, `source_key`)
);

CREATE TABLE `jcms_import_runs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `source_file` varchar(255) NOT NULL,
  `started_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `finished_at` timestamp NULL DEFAULT NULL,
  `status` enum('running','completed','failed') NOT NULL DEFAULT 'running',
  `summary` json DEFAULT NULL,
  PRIMARY KEY (`id`)
);

CREATE TABLE `jcms_import_map` (
  `id` int NOT NULL AUTO_INCREMENT,
  `run_id` int NOT NULL,
  `source_table` varchar(80) NOT NULL,
  `source_key` varchar(64) NOT NULL,
  `target_table` varchar(80) NOT NULL,
  `target_key` varchar(80) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_jcms_source` (`source_table`, `source_key`, `target_table`),
  KEY `idx_jcms_target` (`target_table`, `target_key`),
  FOREIGN KEY (`run_id`) REFERENCES `jcms_import_runs` (`id`) ON DELETE CASCADE
);
