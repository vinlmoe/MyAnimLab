SET NAMES utf8mb4;

-- Migration: written / numeric observations in the experimental daily
-- follow-up (protocol-defined fields and free ad-hoc observations), and
-- endpoints on numeric observation fields. Run after 009.

ALTER TABLE `protocol_endpoint_thresholds`
  MODIFY `metric` ENUM('weight_loss_pct','clinical_score','custom','item_score','observation') NOT NULL,
  ADD COLUMN `observation_field_id` int DEFAULT NULL AFTER `grid_item_id`;

-- Additional observations of the experimental daily follow-up, written or
-- numeric. A protocol can define its own fields (e.g. "Temperature", °C,
-- number; "Wound aspect", text), entered at every follow-up; numeric fields
-- can carry endpoints. Free ad-hoc observations can also be added to any
-- entry. Values keep a snapshot of the label/unit for exact reports. A field
-- is never deleted, only deactivated (active = 0), so its values stay linked.
CREATE TABLE `protocol_observation_fields` (
  `id` int NOT NULL AUTO_INCREMENT,
  `protocol_id` int NOT NULL,
  `position` int NOT NULL DEFAULT 0,
  `label` varchar(255) NOT NULL,
  `value_type` ENUM('number','text') NOT NULL,
  `unit` varchar(50) DEFAULT NULL,
  `required` tinyint(1) NOT NULL DEFAULT 0,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`protocol_id`) REFERENCES `protocols` (`id`) ON DELETE CASCADE
);

CREATE TABLE `experiment_followup_observations` (
  `id` int NOT NULL AUTO_INCREMENT,
  `followup_id` int NOT NULL,
  `field_id` int DEFAULT NULL,
  `label` varchar(255) NOT NULL,
  `value_type` ENUM('number','text') NOT NULL,
  `unit` varchar(50) DEFAULT NULL,
  `value_number` decimal(14,4) DEFAULT NULL,
  `value_text` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_followup_obs` (`followup_id`),
  FOREIGN KEY (`followup_id`) REFERENCES `experiment_followups` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`field_id`) REFERENCES `protocol_observation_fields` (`id`) ON DELETE SET NULL
);
