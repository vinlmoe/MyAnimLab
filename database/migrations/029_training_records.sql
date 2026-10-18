SET NAMES utf8mb4;

-- Personal training record (livret de formation / de compétences) of every
-- person working with the animals, following Directive 2010/63/EU
-- (art. 23-24) and its French transposition (arrêté du 1er février 2013):
--  - initial regulatory training per function: design of procedures and
--    projects, application of procedures, care of the animals, killing;
--  - competence validated after a supervised period (tutorat);
--  - continuing training, whose minimum over a rolling period is checked
--    (by default 3 days over 6 years, adjustable in the settings).
-- An entry declared by the person is counted once validated by an
-- administrator or a BEA responsible; a certificate (PDF) can be attached.
CREATE TABLE `training_records` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `category` ENUM('initial','competence','continuing','other') NOT NULL,
  `function_code` varchar(30) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `organisation` varchar(255) DEFAULT NULL,
  `start_date` date NOT NULL,
  `end_date` date DEFAULT NULL,
  `duration_hours` decimal(6,2) DEFAULT NULL,
  `species` varchar(255) DEFAULT NULL,
  `supervisor_name` varchar(255) DEFAULT NULL,
  `certificate_ref` varchar(100) DEFAULT NULL,
  `certificate_file` varchar(64) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` ENUM('pending','validated','rejected') NOT NULL DEFAULT 'pending',
  `validated_by` int DEFAULT NULL,
  `validated_by_name` varchar(255) DEFAULT NULL,
  `validated_at` datetime DEFAULT NULL,
  `review_note` text DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `created_by_name` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_training_user` (`user_id`, `category`, `status`, `start_date`),
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`validated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
);

-- Continuing training requirement: minimum days over a rolling number of
-- years, and the hours counted as one day.
INSERT INTO `settings` (`name`, `value`) VALUES
  ('training_cpd_days', '3'), ('training_cpd_years', '6'), ('training_hours_per_day', '7')
ON DUPLICATE KEY UPDATE `value` = `value`;
