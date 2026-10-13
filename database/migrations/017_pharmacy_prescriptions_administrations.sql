SET NAMES utf8mb4;

CREATE TABLE `medication_products` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `presentation` varchar(255) DEFAULT NULL,
  `active_ingredient` varchar(255) DEFAULT NULL,
  `default_unit` varchar(30) NOT NULL DEFAULT 'unit',
  `notes` text DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`), UNIQUE KEY `uq_medication_product_name` (`name`)
);

CREATE TABLE `medication_lots` (
  `id` int NOT NULL AUTO_INCREMENT,
  `product_id` int NOT NULL,
  `lot_number` varchar(100) NOT NULL,
  `expiry_date` date NOT NULL,
  `received_at` date NOT NULL,
  `initial_quantity` decimal(12,3) NOT NULL,
  `remaining_quantity` decimal(12,3) NOT NULL,
  `storage_location` varchar(255) DEFAULT NULL,
  `supplier` varchar(255) DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`), UNIQUE KEY `uq_medication_lot` (`product_id`, `lot_number`),
  KEY `idx_medication_lot_expiry` (`expiry_date`),
  FOREIGN KEY (`product_id`) REFERENCES `medication_products` (`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
);

CREATE TABLE `prescriptions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `animal_id` int NOT NULL,
  `product_id` int NOT NULL,
  `prescription_reference` varchar(100) DEFAULT NULL,
  `prescriber_name` varchar(255) NOT NULL,
  `prescribed_on` date NOT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `dose_amount` decimal(12,3) DEFAULT NULL,
  `dose_unit` varchar(30) DEFAULT NULL,
  `route` varchar(100) DEFAULT NULL,
  `frequency` varchar(100) DEFAULT NULL,
  `instructions` text DEFAULT NULL,
  `status` ENUM('active','completed','cancelled') NOT NULL DEFAULT 'active',
  `recorded_by` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_prescription_animal_status` (`animal_id`, `status`),
  FOREIGN KEY (`animal_id`) REFERENCES `mice` (`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`product_id`) REFERENCES `medication_products` (`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
);

CREATE TABLE `medication_administrations` (
  `id` int NOT NULL AUTO_INCREMENT,
  `prescription_id` int NOT NULL,
  `animal_id` int NOT NULL,
  `medication_lot_id` int NOT NULL,
  `administered_at` datetime NOT NULL,
  `quantity_used` decimal(12,3) NOT NULL,
  `unit` varchar(30) NOT NULL,
  `route` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `administered_by` int DEFAULT NULL,
  `administered_by_name` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_medication_admin_animal` (`animal_id`, `administered_at`),
  FOREIGN KEY (`prescription_id`) REFERENCES `prescriptions` (`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`animal_id`) REFERENCES `mice` (`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`medication_lot_id`) REFERENCES `medication_lots` (`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`administered_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
);

CREATE TABLE `medication_stock_movements` (
  `id` int NOT NULL AUTO_INCREMENT,
  `medication_lot_id` int NOT NULL,
  `movement_type` ENUM('receipt','administration','adjustment') NOT NULL,
  `quantity_delta` decimal(12,3) NOT NULL,
  `reason` text DEFAULT NULL,
  `administration_id` int DEFAULT NULL,
  `performed_by` int DEFAULT NULL,
  `performed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`), KEY `idx_medication_movement_lot` (`medication_lot_id`),
  FOREIGN KEY (`medication_lot_id`) REFERENCES `medication_lots` (`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`administration_id`) REFERENCES `medication_administrations` (`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`performed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
);
