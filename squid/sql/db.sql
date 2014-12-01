CREATE TABLE IF NOT EXISTS `auth_sessions` (
  `session_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(20) NOT NULL DEFAULT '',
  `mac_address` char(17) NOT NULL DEFAULT '',
  `ip_address` char(45) NOT NULL DEFAULT '',
  `auth_time_utc` datetime NOT NULL,
  `expiry_time_utc` datetime NOT NULL,
  PRIMARY KEY (`session_id`),
  KEY `username` (`username`,`mac_address`,`ip_address`,`expiry_time_utc`)
);

CREATE TABLE IF NOT EXISTS `user_devices` (
  `line_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `server_name` varchar(10) NOT NULL DEFAULT '',
  `mac_address` char(17) NOT NULL DEFAULT '',
  `username` varchar(20) NOT NULL DEFAULT '',
  PRIMARY KEY (`line_id`),
  KEY `server_name` (`server_name`,`mac_address`)
);

ALTER TABLE `user_devices`
  CHANGE COLUMN `server_name` `server_name` varchar(10) DEFAULT NULL;

ALTER TABLE `user_devices`
  ADD COLUMN `auth_time_utc` datetime DEFAULT NULL;

