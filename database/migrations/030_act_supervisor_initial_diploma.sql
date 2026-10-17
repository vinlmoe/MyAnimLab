SET NAMES utf8mb4;

-- A person not yet competent to apply procedures (not trained, or trained
-- and still under supervision) confirms an experimental act only with the
-- name of the competent person supervising them, kept with the act.
ALTER TABLE `experiment_act_animals`
  ADD COLUMN `supervised_by` int DEFAULT NULL AFTER `done_by_name`,
  ADD COLUMN `supervised_by_name` varchar(255) DEFAULT NULL AFTER `supervised_by`,
  ADD CONSTRAINT `fk_act_animal_supervisor` FOREIGN KEY (`supervised_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

-- The training record also holds the initial diploma (title and speciality,
-- level, institution, date obtained, copy).
ALTER TABLE `training_records`
  MODIFY `category` ENUM('diploma','initial','competence','continuing','other') NOT NULL,
  ADD COLUMN `diploma_level` varchar(30) DEFAULT NULL AFTER `function_code`;
