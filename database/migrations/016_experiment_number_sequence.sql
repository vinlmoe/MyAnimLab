SET NAMES utf8mb4;

-- Global, transaction-safe sequence for generated experiment numbers.
CREATE TABLE `experiment_sequence` (
  `id` tinyint NOT NULL DEFAULT 1,
  `next_seq` int NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
);

-- Continue after an existing EXP-xxxxx number if the installation already
-- contains manually entered experiments.
INSERT INTO `experiment_sequence` (`id`, `next_seq`)
SELECT 1, COALESCE(MAX(CAST(SUBSTRING(`experiment_number`, 5) AS UNSIGNED)), 0) + 1
FROM `experiments`
WHERE `experiment_number` REGEXP '^EXP-[0-9]+$';
