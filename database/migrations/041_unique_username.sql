SET NAMES utf8mb4;

-- The login identifier (users.username, an e-mail address) must be unique.
-- Without this constraint, a second account could be created with the same
-- address (profile change or registration), and every password / role /
-- status update keyed on the username would then touch both rows — an
-- account-takeover path (security review S2).
--
-- Defensive de-duplication first, so the migration never fails on an existing
-- database that already contains duplicates: the lowest id (the original
-- account) keeps the address; any later duplicate is suffixed with ".dup<id>"
-- so it can no longer be used to sign in or receive a reset for that address.
-- Such renamed accounts are anomalies to review by hand.
UPDATE `users` u
JOIN (
    SELECT `username`, MIN(`id`) AS keep_id
    FROM `users`
    GROUP BY `username`
    HAVING COUNT(*) > 1
) d ON u.`username` = d.`username` AND u.`id` <> d.keep_id
SET u.`username` = CONCAT(u.`username`, '.dup', u.`id`);

ALTER TABLE `users` ADD UNIQUE KEY `uq_users_username` (`username`);
