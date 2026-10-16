SET NAMES utf8mb4;

-- Entry / exit register: every animal records its date of entry into the
-- facility and its origin (born in the facility, supplier / authorised
-- breeder, other user establishment, other), with the supplier or
-- establishment and a reference (delivery note, transfer document, litter).
-- Exits already record their date, code, reason and destination.
ALTER TABLE `mice`
  ADD COLUMN `entry_date` date DEFAULT NULL AFTER `dob`,
  ADD COLUMN `origin_type` ENUM('internal_birth','supplier','other_facility','other') DEFAULT NULL AFTER `entry_date`,
  ADD COLUMN `origin_detail` varchar(255) DEFAULT NULL AFTER `origin_type`,
  ADD COLUMN `origin_reference` varchar(100) DEFAULT NULL AFTER `origin_detail`,
  ADD KEY `idx_mice_entry_date` (`entry_date`),
  ADD KEY `idx_mice_exit_date` (`exit_date`);

-- Animals weaned from a litter of the facility were born here.
UPDATE `mice` m JOIN `animal_parentage` p ON p.animal_id = m.id AND p.litter_id IS NOT NULL
SET m.origin_type = 'internal_birth',
    m.origin_reference = CONCAT('Portée n°', p.litter_id),
    m.entry_date = COALESCE(m.dob, m.entry_date);

-- Other existing animals: entry date = their first recorded placement (or
-- creation), origin left to complete on the register.
UPDATE `mice` m
SET m.entry_date = COALESCE(
    (SELECT DATE(MIN(mv.moved_at)) FROM `animal_movements` mv WHERE mv.animal_id = m.id),
    (SELECT DATE(MIN(ev.created_at)) FROM `animal_events` ev WHERE ev.animal_id = m.id AND ev.event_type = 'created'),
    m.dob)
WHERE m.entry_date IS NULL;
