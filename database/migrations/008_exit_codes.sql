-- Migration: exit codes (motif de sortie) recorded with every animal exit.
-- Run after 007. Existing exits keep exit_code NULL (shown by their status).
-- Codes: mam_population, mam_experimentation, euthanasie, point_limite,
-- retrouve_mort, retrouve_mort_experimentation, export (see animal_lib.php).

ALTER TABLE `mice`
  ADD COLUMN `exit_code` varchar(40) DEFAULT NULL AFTER `exit_destination`;
