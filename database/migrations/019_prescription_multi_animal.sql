SET NAMES utf8mb4;

-- One prescription can cover several animals.  The legacy animal_id on
-- prescriptions remains the first animal for compatibility with old reports.
CREATE TABLE `prescription_animals` (
  `id` int NOT NULL AUTO_INCREMENT,
  `prescription_id` int NOT NULL,
  `animal_id` int NOT NULL,
  `planned_administrations` int NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_prescription_animal` (`prescription_id`, `animal_id`),
  KEY `idx_prescription_animal_animal` (`animal_id`),
  FOREIGN KEY (`prescription_id`) REFERENCES `prescriptions` (`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`animal_id`) REFERENCES `mice` (`id`) ON DELETE RESTRICT
);

-- Preserve all pre-existing, single-animal prescriptions in the new model.
INSERT INTO `prescription_animals` (`prescription_id`, `animal_id`, `planned_administrations`)
SELECT `id`, `animal_id`, 1 FROM `prescriptions`;
