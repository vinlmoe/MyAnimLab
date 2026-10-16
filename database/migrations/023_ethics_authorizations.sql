SET NAMES utf8mb4;

-- Project authorizations: the `iacuc` table now holds the ethics number
-- (iacuc_id), the ministerial authorization number, its validity period and
-- the three regulatory PDF documents (authorization request, ethics committee
-- opinion, authorization decision). Trials are checked against the validity
-- period. Documents are stored outside the public upload folder and served
-- only to signed-in users (ethics_file.php).
ALTER TABLE `iacuc`
  ADD COLUMN `authorization_number` varchar(100) DEFAULT NULL AFTER `iacuc_title`,
  ADD COLUMN `valid_from` date DEFAULT NULL AFTER `authorization_number`,
  ADD COLUMN `valid_until` date DEFAULT NULL AFTER `valid_from`,
  ADD COLUMN `request_file` varchar(255) DEFAULT NULL AFTER `file_url`,
  ADD COLUMN `committee_opinion_file` varchar(255) DEFAULT NULL AFTER `request_file`,
  ADD COLUMN `decision_file` varchar(255) DEFAULT NULL AFTER `committee_opinion_file`;
