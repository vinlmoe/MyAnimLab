SET NAMES utf8mb4;

-- Narcotics (stupéfiants) and weight-based dosing of the pharmacy products:
-- a product can be flagged as a narcotic (special register, inventories
-- with justified differences) and carry its concentration (e.g. 100 mg/mL),
-- used to suggest the volume of an experimental act from the animal's
-- weight and the dose by weight (e.g. 75 mg/kg).
ALTER TABLE `medication_products`
  ADD COLUMN `is_narcotic` tinyint(1) NOT NULL DEFAULT 0 AFTER `default_unit`,
  ADD COLUMN `concentration` decimal(12,4) DEFAULT NULL AFTER `is_narcotic`,
  ADD COLUMN `concentration_unit` varchar(30) DEFAULT NULL AFTER `concentration`;

-- Stock movements: an inventory (counted quantity, difference justified) and
-- the re-credit of a cancelled administration. counted_quantity keeps the
-- quantity found by an inventory.
ALTER TABLE `medication_stock_movements`
  MODIFY `movement_type` ENUM('receipt','administration','adjustment','inventory','correction') NOT NULL,
  ADD COLUMN `counted_quantity` decimal(12,3) DEFAULT NULL AFTER `quantity_delta`;

-- An administration made during an experimental act can be cancelled when
-- its confirmation is corrected: it stays in the registers, marked
-- cancelled with the reason, and the quantity goes back to the lot. The act
-- can then be confirmed again, so one act row may carry several
-- administrations (only one not cancelled).
ALTER TABLE `medication_administrations`
  DROP FOREIGN KEY `fk_medication_admin_act_animal`,
  DROP INDEX `uq_medication_admin_act_animal`;
ALTER TABLE `medication_administrations`
  ADD KEY `idx_medication_admin_act_animal` (`experiment_act_animal_id`),
  ADD CONSTRAINT `fk_medication_admin_act_animal` FOREIGN KEY (`experiment_act_animal_id`) REFERENCES `experiment_act_animals` (`id`) ON DELETE RESTRICT,
  ADD COLUMN `cancelled_at` datetime DEFAULT NULL,
  ADD COLUMN `cancelled_by_name` varchar(255) DEFAULT NULL,
  ADD COLUMN `cancel_reason` text DEFAULT NULL;

-- Corrections of experimental act confirmations: what was recorded (status,
-- time, person, lot, quantity, values, note) is kept with the reason of the
-- correction; the act goes back to "to do".
CREATE TABLE `experiment_act_corrections` (
  `id` int NOT NULL AUTO_INCREMENT,
  `act_animal_id` int NOT NULL,
  `previous_status` varchar(20) NOT NULL,
  `previous_done_at` datetime DEFAULT NULL,
  `previous_done_by_name` varchar(255) DEFAULT NULL,
  `previous_lot_id` int DEFAULT NULL,
  `previous_quantity` decimal(12,3) DEFAULT NULL,
  `previous_values` text DEFAULT NULL,
  `previous_note` text DEFAULT NULL,
  `reason` text NOT NULL,
  `corrected_by` int DEFAULT NULL,
  `corrected_by_name` varchar(255) DEFAULT NULL,
  `corrected_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_act_correction_act_animal` (`act_animal_id`),
  FOREIGN KEY (`act_animal_id`) REFERENCES `experiment_act_animals` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`previous_lot_id`) REFERENCES `medication_lots` (`id`) ON DELETE SET NULL,
  FOREIGN KEY (`corrected_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
);
