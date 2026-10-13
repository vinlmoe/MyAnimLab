SET NAMES utf8mb4;

-- Administrators are BEA responsible by default.  The role can still be
-- removed per user from the User Management page.
UPDATE `users` SET `is_bea_responsible` = 1 WHERE `role` = 'admin';
