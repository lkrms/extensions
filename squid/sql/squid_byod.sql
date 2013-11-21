CREATE TABLE `auth_sessions` (
  `session_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(20) NOT NULL DEFAULT '',
  `mac_address` char(17) NOT NULL DEFAULT '',
  `ip_address` char(45) NOT NULL DEFAULT '',
  `auth_time_utc` datetime NOT NULL,
  `expiry_time_utc` datetime NOT NULL,
  PRIMARY KEY (`session_id`),
  KEY `username` (`username`,`mac_address`,`ip_address`,`expiry_time_utc`)
);
