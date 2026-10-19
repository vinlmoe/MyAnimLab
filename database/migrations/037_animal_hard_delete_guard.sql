SET NAMES utf8mb4;

-- An animal with a regulatory history cannot be deleted, even by the
-- administrator's hard delete (animal_hard_delete.php): its rows would be
-- removed by the foreign key cascades, which do not fire the append-only
-- triggers. Only an animal created by mistake (no exit, experiment, health
-- report, treatment, breeding, offspring, transfer, correction or capture)
-- can be deleted. The same rule is checked first by
-- animal_regulatory_history() (animal_lib.php), which explains the refusal.
DELIMITER $$
CREATE TRIGGER `mice_no_delete_with_history` BEFORE DELETE ON `mice` FOR EACH ROW
BEGIN
  IF OLD.status <> 'active'
     OR EXISTS (SELECT 1 FROM experiment_animals WHERE animal_id = OLD.id)
     OR EXISTS (SELECT 1 FROM health_reports WHERE animal_id = OLD.id)
     OR EXISTS (SELECT 1 FROM prescription_animals WHERE animal_id = OLD.id)
     OR EXISTS (SELECT 1 FROM medication_administrations WHERE animal_id = OLD.id)
     OR EXISTS (SELECT 1 FROM breeding_animals WHERE animal_id = OLD.id)
     OR EXISTS (SELECT 1 FROM litters WHERE sire_id = OLD.id OR dam_id = OLD.id)
     OR EXISTS (SELECT 1 FROM animal_parentage WHERE sire_id = OLD.id OR dam_id = OLD.id OR (animal_id = OLD.id AND litter_id IS NOT NULL))
     OR EXISTS (SELECT 1 FROM animal_movements WHERE animal_id = OLD.id AND from_cage_id IS NOT NULL)
     OR EXISTS (SELECT 1 FROM record_corrections WHERE entity_type = 'animal' AND entity_id = OLD.id)
     OR EXISTS (SELECT 1 FROM terrarium_capture_animals WHERE animal_id = OLD.id)
     OR EXISTS (SELECT 1 FROM animal_events WHERE animal_id = OLD.id AND event_type NOT IN ('created', 'note')) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Animal with a regulatory history: record an exit instead of deleting it';
  END IF;
END$$
DELIMITER ;
