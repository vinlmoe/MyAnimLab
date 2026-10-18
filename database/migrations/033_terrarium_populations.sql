SET NAMES utf8mb4;

-- Terrariums (or tanks, enclosures) holding populations of animals that are
-- not individually identified. The population is followed by periodic
-- approximate censuses and a journal (introductions with their reference,
-- deaths, transfers, captures); group treatments are recorded (with the
-- medication lot debited and a withdrawal period); animals captured become
-- identified animals (origin "population capture") and can be enrolled in
-- an experiment at once.
CREATE TABLE `terrariums` (
  `id` int NOT NULL AUTO_INCREMENT,
  `code` varchar(30) NOT NULL,
  `name` varchar(255) NOT NULL,
  `line_id` int DEFAULT NULL,
  `species` varchar(100) DEFAULT NULL,
  `room` varchar(100) DEFAULT NULL,
  `location` varchar(100) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `origin` varchar(255) DEFAULT NULL,
  `reference` varchar(100) DEFAULT NULL,
  `established_on` date DEFAULT NULL,
  `census_every_days` int NOT NULL DEFAULT 30,
  `health_every_days` int NOT NULL DEFAULT 90,
  `responsible_user_id` int DEFAULT NULL,
  `status` ENUM('active','archived') NOT NULL DEFAULT 'active',
  `archived_at` datetime DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_terrarium_code` (`code`),
  FOREIGN KEY (`line_id`) REFERENCES `animal_lines` (`id`) ON DELETE SET NULL,
  FOREIGN KEY (`responsible_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
);

-- Journal of a population. A census gives an estimate (and optionally a
-- range); the other events give a number of animals that entered or left.
CREATE TABLE `terrarium_events` (
  `id` int NOT NULL AUTO_INCREMENT,
  `terrarium_id` int NOT NULL,
  `event_type` ENUM('census','introduction','death','capture','transfer_out','note') NOT NULL,
  `event_date` date NOT NULL,
  `estimate` int DEFAULT NULL,
  `estimate_min` int DEFAULT NULL,
  `estimate_max` int DEFAULT NULL,
  `quantity` int DEFAULT NULL,
  `life_stage` varchar(50) DEFAULT NULL,
  `method` varchar(100) DEFAULT NULL,
  `reference` varchar(255) DEFAULT NULL,
  `note` text DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `created_by_name` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_terrarium_event` (`terrarium_id`, `event_date`),
  FOREIGN KEY (`terrarium_id`) REFERENCES `terrariums` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
);

-- Animals created by a capture (identified from then on).
CREATE TABLE `terrarium_capture_animals` (
  `event_id` int NOT NULL,
  `animal_id` int NOT NULL,
  PRIMARY KEY (`event_id`, `animal_id`),
  UNIQUE KEY `uq_capture_animal` (`animal_id`),
  FOREIGN KEY (`event_id`) REFERENCES `terrarium_events` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`animal_id`) REFERENCES `mice` (`id`) ON DELETE CASCADE
);

-- Treatments of a whole population: medication of the stock (lot debited)
-- or another treatment, prescriber and prescription reference, and the date
-- until which animals must not be used (withdrawal period).
CREATE TABLE `terrarium_treatments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `terrarium_id` int NOT NULL,
  `treated_at` datetime NOT NULL,
  `kind` varchar(30) NOT NULL,
  `product_id` int DEFAULT NULL,
  `medication_lot_id` int DEFAULT NULL,
  `quantity_used` decimal(12,3) DEFAULT NULL,
  `unit` varchar(30) DEFAULT NULL,
  `substance` varchar(255) DEFAULT NULL,
  `dose` varchar(100) DEFAULT NULL,
  `method` varchar(100) DEFAULT NULL,
  `reason` text DEFAULT NULL,
  `prescriber_name` varchar(255) DEFAULT NULL,
  `prescription_reference` varchar(100) DEFAULT NULL,
  `withdrawal_until` date DEFAULT NULL,
  `performed_by` int DEFAULT NULL,
  `performed_by_name` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_terrarium_treatment` (`terrarium_id`, `treated_at`),
  FOREIGN KEY (`terrarium_id`) REFERENCES `terrariums` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`product_id`) REFERENCES `medication_products` (`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`medication_lot_id`) REFERENCES `medication_lots` (`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`performed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
);

-- Health monitoring of a population: observation of the terrarium,
-- environment parameters, or screening on samples (sent to a laboratory,
-- result recorded when it comes back). Kept as a journal: a check is never
-- changed, except to record the result of a pending screening.
CREATE TABLE `terrarium_health_checks` (
  `id` int NOT NULL AUTO_INCREMENT,
  `terrarium_id` int NOT NULL,
  `checked_on` date NOT NULL,
  `check_type` ENUM('observation','environment','screening') NOT NULL,
  `status` ENUM('normal','abnormal') NOT NULL DEFAULT 'normal',
  `signs` text DEFAULT NULL,
  `sick_count` int DEFAULT NULL,
  `temperature_c` decimal(5,1) DEFAULT NULL,
  `humidity_pct` int DEFAULT NULL,
  `parameters` varchar(255) DEFAULT NULL,
  `sample_type` varchar(100) DEFAULT NULL,
  `animals_sampled` int DEFAULT NULL,
  `laboratory` varchar(255) DEFAULT NULL,
  `sample_reference` varchar(100) DEFAULT NULL,
  `agents_screened` varchar(255) DEFAULT NULL,
  `result` ENUM('pending','negative','positive') DEFAULT NULL,
  `result_detail` text DEFAULT NULL,
  `result_on` date DEFAULT NULL,
  `result_by_name` varchar(255) DEFAULT NULL,
  `actions` text DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `created_by_name` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_terrarium_health` (`terrarium_id`, `checked_on`),
  FOREIGN KEY (`terrarium_id`) REFERENCES `terrariums` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
);

-- A stock movement may come from a population treatment (no individual animal).
ALTER TABLE `medication_stock_movements`
  ADD COLUMN `terrarium_treatment_id` int DEFAULT NULL AFTER `administration_id`,
  ADD CONSTRAINT `fk_movement_terrarium_treatment` FOREIGN KEY (`terrarium_treatment_id`) REFERENCES `terrarium_treatments` (`id`) ON DELETE RESTRICT;

-- New origin of the entry register: captured from a population of the facility.
ALTER TABLE `mice`
  MODIFY `origin_type` ENUM('internal_birth','supplier','other_facility','other','population_capture') DEFAULT NULL;
