SET NAMES utf8mb4;

-- Expected (prospective) severity of a protocol, as stated in the project
-- authorization: shown to the BEA responsible next to the actual severity
-- during the retrospective review.
ALTER TABLE `protocols`
  ADD COLUMN `expected_severity` ENUM('non_recovery','mild','moderate','severe') DEFAULT NULL AFTER `clinical_grid_id`;
