SET NAMES utf8mb4;

-- Migration: distinctive mark of an animal (ear punch, tattoo, chip, coat
-- colour, scar...) used to recognise it in its cage. Run after 011.

ALTER TABLE `mice` ADD COLUMN `distinctive_mark` varchar(255) DEFAULT NULL AFTER `dob`;
