-- Migration: animal exits (sacrificed / dead / exported) and the housing
-- invariant "every living animal is in an active cage". Run after 006.
-- Run with the mysql command-line client (uses DELIMITER). With binary
-- logging enabled, creating triggers may require the SUPER privilege (or
-- log_bin_trust_function_creators = 1).

ALTER TABLE `mice`
  MODIFY `status` ENUM('active','sacrificed','dead','transferred_out','archived') NOT NULL DEFAULT 'active',
  ADD COLUMN `exit_destination` varchar(255) DEFAULT NULL AFTER `exit_reason`;

-- Existing living animals that break the invariant (no cage, or a retired
-- cage) are left untouched: list them with the query below and resolve
-- them in the app (Find Animal > "Housing anomalies": move or exit).
--   SELECT m.id, m.animal_uid, m.cage_id FROM mice m
--   LEFT JOIN cages c ON c.cage_id = m.cage_id
--   WHERE m.status = 'active' AND (c.cage_id IS NULL OR c.status <> 'active');

-- Housing invariant: every living (active) animal is in an active cage.
-- Enforced in the database so no code path can break it: an active animal
-- cannot be placed without a cage or into a retired cage, and a cage cannot
-- be retired while it still houses living animals (each must first be
-- given a fate: exit or move to another cage, see cage_retire.php).
DELIMITER $$
CREATE TRIGGER `mice_active_needs_cage_insert` BEFORE INSERT ON `mice` FOR EACH ROW
BEGIN
  IF NEW.status = 'active' AND (NEW.cage_id IS NULL
      OR NOT EXISTS (SELECT 1 FROM cages c WHERE c.cage_id = NEW.cage_id AND c.status = 'active')) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A living animal must be housed in an active cage';
  END IF;
END$$
CREATE TRIGGER `mice_active_needs_cage_update` BEFORE UPDATE ON `mice` FOR EACH ROW
BEGIN
  IF NEW.status = 'active' AND (NOT (NEW.cage_id <=> OLD.cage_id) OR NEW.status <> OLD.status)
     AND (NEW.cage_id IS NULL
      OR NOT EXISTS (SELECT 1 FROM cages c WHERE c.cage_id = NEW.cage_id AND c.status = 'active')) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A living animal must be housed in an active cage';
  END IF;
END$$
CREATE TRIGGER `cages_no_retire_with_animals` BEFORE UPDATE ON `cages` FOR EACH ROW
BEGIN
  IF OLD.status = 'active' AND NEW.status <> 'active'
     AND EXISTS (SELECT 1 FROM mice m WHERE m.cage_id = OLD.cage_id AND m.status = 'active') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Cage still houses living animals: give each one a fate first';
  END IF;
END$$
DELIMITER ;
