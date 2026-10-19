SET NAMES utf8mb4;

-- Endpoint actions (points limites): a correction of the follow-up that no
-- longer reaches the endpoint withdraws the pending action it had raised
-- (status 'withdrawn', with the correction's reason in done_note), and the
-- endpoint withdrawal is kept in the animal's history. An action not carried
-- out at once is commented (why, what is planned): the comments are kept,
-- append-only.
ALTER TABLE `endpoint_actions`
  MODIFY `status` ENUM('pending','done','withdrawn') NOT NULL DEFAULT 'pending';

ALTER TABLE `animal_events`
  MODIFY `event_type` ENUM('created','transferred','status_change','experiment_start','experiment_end','endpoint_alert','endpoint_withdrawn','note','health_report','medication_administration','experiment_act') NOT NULL;

CREATE TABLE `endpoint_action_comments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `action_id` int NOT NULL,
  `comment` text NOT NULL,
  `created_by` int DEFAULT NULL,
  `created_by_name` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_endpoint_action_comments_action` (`action_id`),
  FOREIGN KEY (`action_id`) REFERENCES `endpoint_actions` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
);
CREATE TRIGGER `endpoint_action_comments_no_update` BEFORE UPDATE ON `endpoint_action_comments`
  FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Endpoint action comments are append-only';
CREATE TRIGGER `endpoint_action_comments_no_delete` BEFORE DELETE ON `endpoint_action_comments`
  FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Endpoint action comments are append-only';
