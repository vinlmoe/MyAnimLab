SET NAMES utf8mb4;

-- Individual weight of each animal at its capture from a terrarium (also its
-- baseline weight when it is enrolled in an experiment at once).
ALTER TABLE `terrarium_capture_animals`
  ADD COLUMN `weight_g` decimal(8,2) DEFAULT NULL AFTER `animal_id`;
