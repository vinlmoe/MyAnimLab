SET NAMES utf8mb4;

-- A cage becomes an experimental cage as soon as one of its living animals is
-- in an ongoing experiment (it keeps its cage number). Classify the cages
-- already in that situation; the application does it automatically from now on
-- (enrollment, and transfer of an enrolled animal). Breeding cages are kept.
UPDATE `cages` c
SET c.`is_experimental` = 1
WHERE c.`status` = 'active'
  AND NOT EXISTS (SELECT 1 FROM `breeding` b WHERE b.`cage_id` = c.`cage_id`)
  AND EXISTS (SELECT 1 FROM `mice` m
              JOIN `experiment_animals` ea ON ea.`animal_id` = m.`id` AND ea.`removed_at` IS NULL
              WHERE m.`cage_id` = c.`cage_id` AND m.`status` = 'active');
