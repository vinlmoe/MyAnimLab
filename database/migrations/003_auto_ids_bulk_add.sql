-- Migration: fully automatic cage IDs (P-00001, P-00002, ...), per-animal sex
-- so a cage can hold a mix of males/females created in bulk, and dropping the
-- free-text "Parent Cage" requirement. Run after 001_traceability.sql and
-- 002_myvivarium2_parity.sql.

-- ---------------------------------------------------------------------------
-- 1. Cage ID auto-generation counter
-- ---------------------------------------------------------------------------
CREATE TABLE `cage_sequence` (
  `id` tinyint NOT NULL DEFAULT 1,
  `next_seq` int NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
);

-- Seed the counter past any existing numeric-looking cage IDs so newly
-- generated P-00001-style IDs can't collide with legacy manually-entered
-- ones. Safe to run even if `cages` is empty (COALESCE falls back to 1).
INSERT INTO `cage_sequence` (`id`, `next_seq`)
SELECT 1, COALESCE(MAX(CAST(SUBSTRING(cage_id, 3) AS UNSIGNED)), 0) + 1
FROM `cages` WHERE cage_id REGEXP '^P-[0-9]+$';

INSERT IGNORE INTO `cage_sequence` (`id`, `next_seq`) VALUES (1, 1);

-- ---------------------------------------------------------------------------
-- 2. mice.sex: a cage can now contain a mix of males/females created in one
--    bulk operation, so sex must live on the animal, not the cage.
-- ---------------------------------------------------------------------------
ALTER TABLE `mice`
  ADD COLUMN `sex` ENUM('male','female') DEFAULT NULL AFTER `mouse_id`,
  MODIFY `mouse_id` varchar(255) DEFAULT NULL,
  MODIFY `genotype` varchar(255) DEFAULT NULL;

-- ---------------------------------------------------------------------------
-- 3. Parent Cage is no longer collected manually; the column survives for
--    historical rows but is no longer required.
-- ---------------------------------------------------------------------------
ALTER TABLE `holding` MODIFY `parent_cg` varchar(255) DEFAULT NULL;

-- ---------------------------------------------------------------------------
-- 4. breeding.male_id/female_id/male_dob/female_dob are no longer collected
--    as free text either — breeders are now real `mice` rows linked via
--    `breeding_animals` (see 001_traceability.sql). Columns survive for
--    historical rows but are no longer required.
-- ---------------------------------------------------------------------------
ALTER TABLE `breeding`
  MODIFY `male_id` varchar(255) DEFAULT NULL,
  MODIFY `female_id` varchar(255) DEFAULT NULL,
  MODIFY `male_dob` date DEFAULT NULL,
  MODIFY `female_dob` date DEFAULT NULL;
