SET NAMES utf8mb4;

-- A user explicitly designated by the establishment may perform the BEA
-- retrospective classification. This is separate from the technical admin role.
ALTER TABLE `users`
  ADD COLUMN `is_bea_responsible` tinyint(1) NOT NULL DEFAULT 0 AFTER `preferred_locale`;

-- Actual severity classification is entered after an animal leaves an
-- experiment (end of participation or exit/death). One assessment per
-- experiment enrolment keeps the decision attributable and auditable.
CREATE TABLE `experiment_retrospective_assessments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `experiment_animal_id` int NOT NULL,
  `severity_classification` ENUM('non_recovery','mild','moderate','severe') NOT NULL,
  `assessment_notes` text NOT NULL,
  `assessed_by` int NOT NULL,
  `assessed_by_name` varchar(255) NOT NULL,
  `assessed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_retro_experiment_animal` (`experiment_animal_id`),
  KEY `idx_retro_severity` (`severity_classification`),
  FOREIGN KEY (`experiment_animal_id`) REFERENCES `experiment_animals` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`assessed_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
);
