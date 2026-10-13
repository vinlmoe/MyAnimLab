SET NAMES utf8mb4;

-- Experimental cages: holding cages dedicated to animals under experiment,
-- listed in their own category (Cages d'expérimentation).
ALTER TABLE `cages`
  ADD COLUMN `is_experimental` tinyint(1) NOT NULL DEFAULT 0 AFTER `status`;
