SET NAMES utf8mb4;

-- Persist each user's interface language. French is the installation default.
ALTER TABLE `users`
  ADD COLUMN `preferred_locale` varchar(5) NOT NULL DEFAULT 'fr' AFTER `initials`;
