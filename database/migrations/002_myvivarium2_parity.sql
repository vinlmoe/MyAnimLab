-- Migration: notifications, cage lifecycle (archive/restore + room/rack),
-- generic activity log, and the sacrificed status — closing gaps identified
-- against robinson-vidva/MyVivarium-2. Run after 001_traceability.sql.

-- ---------------------------------------------------------------------------
-- 1. Cages gain a lifecycle (active/archived) and a physical location
-- ---------------------------------------------------------------------------
ALTER TABLE `cages`
  ADD COLUMN `status` ENUM('active','archived') NOT NULL DEFAULT 'active' AFTER `remarks`,
  ADD COLUMN `room` varchar(255) DEFAULT NULL AFTER `status`,
  ADD COLUMN `rack` varchar(255) DEFAULT NULL AFTER `room`;

-- ---------------------------------------------------------------------------
-- 2. mice.status: rename 'deceased' to 'sacrificed' to match lab vocabulary
--    (skip this step if 001_traceability.sql was applied after this rename
--    was already merged into schema.sql / 001's own ALTER statement).
-- ---------------------------------------------------------------------------
ALTER TABLE `mice`
  MODIFY `status` ENUM('active','sacrificed','transferred_out','archived') NOT NULL DEFAULT 'active';
UPDATE `mice` SET `status` = 'sacrificed' WHERE `status` = 'deceased';

-- ---------------------------------------------------------------------------
-- 3. In-app notifications (makes endpoint/point-limite alerts actually visible)
-- ---------------------------------------------------------------------------
CREATE TABLE `notifications` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `title` varchar(255) NOT NULL,
  `message` text DEFAULT NULL,
  `link` varchar(255) DEFAULT NULL,
  `type` ENUM('reminder','task','system','endpoint_alert') NOT NULL DEFAULT 'system',
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_notifications_user` (`user_id`, `is_read`, `created_at`),
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
);

-- ---------------------------------------------------------------------------
-- 4. Generic activity/audit log (covers every entity, not just animals)
-- ---------------------------------------------------------------------------
CREATE TABLE `activity_log` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int DEFAULT NULL,
  `action` varchar(50) NOT NULL,
  `entity_type` varchar(50) NOT NULL,
  `entity_id` varchar(255) DEFAULT NULL,
  `details` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_activity_log_user` (`user_id`),
  KEY `idx_activity_log_entity` (`entity_type`, `entity_id`),
  KEY `idx_activity_log_created` (`created_at`),
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
);
