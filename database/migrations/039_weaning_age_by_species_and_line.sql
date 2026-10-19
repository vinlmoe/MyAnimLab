SET NAMES utf8mb4;

-- Weaning age by species and by line. The expected weaning date of a litter
-- is its date of birth plus the weaning age of its line, else of the line's
-- species, else of the laboratory (setting weaning_age_days, 21 days).
ALTER TABLE `animal_lines` ADD COLUMN `weaning_age_days` int DEFAULT NULL AFTER `notes`;

CREATE TABLE `species_weaning_ages` (
  `species` varchar(100) NOT NULL,
  `weaning_age_days` int NOT NULL,
  PRIMARY KEY (`species`)
);
