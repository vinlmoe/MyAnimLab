SET NAMES utf8mb4;

-- Corrections of regulatory records made after the fact (entry of an animal
-- into the facility, its exit): the reason is required and the values
-- replaced are kept next to the new ones. Append-only.
CREATE TABLE `record_corrections` (
  `id` int NOT NULL AUTO_INCREMENT,
  `entity_type` varchar(50) NOT NULL,
  `entity_id` int NOT NULL,
  `record` varchar(50) NOT NULL,
  `previous_values` JSON NOT NULL,
  `new_values` JSON NOT NULL,
  `reason` text NOT NULL,
  `corrected_by` int DEFAULT NULL,
  `corrected_by_name` varchar(255) DEFAULT NULL,
  `corrected_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_record_corrections_entity` (`entity_type`, `entity_id`),
  FOREIGN KEY (`corrected_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
);
CREATE TRIGGER `record_corrections_no_update` BEFORE UPDATE ON `record_corrections`
  FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Record corrections are append-only';
CREATE TRIGGER `record_corrections_no_delete` BEFORE DELETE ON `record_corrections`
  FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Record corrections are append-only';

-- Prescriptions and their animals are never changed nor removed once
-- recorded (only their status may evolve). An administration is never
-- removed; it can only be cancelled once (cancellation fields), the
-- administration itself staying as recorded.
DELIMITER $$
CREATE TRIGGER `prescriptions_no_update` BEFORE UPDATE ON `prescriptions` FOR EACH ROW
BEGIN
  IF NOT (NEW.animal_id <=> OLD.animal_id AND NEW.product_id <=> OLD.product_id
      AND NEW.prescription_reference <=> OLD.prescription_reference AND NEW.prescriber_name <=> OLD.prescriber_name
      AND NEW.prescribed_on <=> OLD.prescribed_on AND NEW.start_date <=> OLD.start_date AND NEW.end_date <=> OLD.end_date
      AND NEW.dose_amount <=> OLD.dose_amount AND NEW.dose_unit <=> OLD.dose_unit AND NEW.route <=> OLD.route
      AND NEW.frequency <=> OLD.frequency AND NEW.instructions <=> OLD.instructions
      AND NEW.recorded_by <=> OLD.recorded_by AND NEW.created_at <=> OLD.created_at) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Prescriptions are append-only (only the status may change)';
  END IF;
END$$
CREATE TRIGGER `medication_administrations_no_update` BEFORE UPDATE ON `medication_administrations` FOR EACH ROW
BEGIN
  IF NOT (NEW.prescription_id <=> OLD.prescription_id AND NEW.experiment_act_animal_id <=> OLD.experiment_act_animal_id
      AND NEW.animal_id <=> OLD.animal_id AND NEW.medication_lot_id <=> OLD.medication_lot_id
      AND NEW.administered_at <=> OLD.administered_at AND NEW.quantity_used <=> OLD.quantity_used
      AND NEW.unit <=> OLD.unit AND NEW.route <=> OLD.route AND NEW.notes <=> OLD.notes
      AND NEW.administered_by <=> OLD.administered_by AND NEW.administered_by_name <=> OLD.administered_by_name
      AND NEW.created_at <=> OLD.created_at)
     OR (OLD.cancelled_at IS NOT NULL AND NOT (NEW.cancelled_at <=> OLD.cancelled_at
      AND NEW.cancelled_by_name <=> OLD.cancelled_by_name AND NEW.cancel_reason <=> OLD.cancel_reason)) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Administrations are append-only (they can only be cancelled once)';
  END IF;
END$$
DELIMITER ;
CREATE TRIGGER `prescriptions_no_delete` BEFORE DELETE ON `prescriptions`
  FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Prescriptions are append-only';
CREATE TRIGGER `prescription_animals_no_update` BEFORE UPDATE ON `prescription_animals`
  FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Prescriptions are append-only';
CREATE TRIGGER `prescription_animals_no_delete` BEFORE DELETE ON `prescription_animals`
  FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Prescriptions are append-only';
CREATE TRIGGER `medication_administrations_no_delete` BEFORE DELETE ON `medication_administrations`
  FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Administrations are append-only';
