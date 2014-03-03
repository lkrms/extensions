
/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;


# Dump of table product
# ------------------------------------------------------------

CREATE TABLE `product` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;


# Dump of table product_group
# ------------------------------------------------------------

CREATE TABLE `product_group` (
  `product_id` int(11) unsigned NOT NULL,
  `group_dn` varchar(200) NOT NULL DEFAULT '',
  PRIMARY KEY (`product_id`,`group_dn`),
  KEY `group_dn` (`group_dn`),
  CONSTRAINT `product_group_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `product` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `product_group_ibfk_2` FOREIGN KEY (`group_dn`) REFERENCES `user_group` (`dn`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=latin1;


# Dump of table user_group
# ------------------------------------------------------------

CREATE TABLE `user_group` (
  `dn` varchar(200) NOT NULL DEFAULT '',
  `name` varchar(50) NOT NULL DEFAULT '',
  PRIMARY KEY (`dn`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;


# Dump of table vpp_code
# ------------------------------------------------------------

CREATE TABLE `vpp_code` (
  `code` varchar(20) NOT NULL DEFAULT '',
  `product_id` int(10) unsigned NOT NULL,
  `assigned_username` varchar(50) DEFAULT NULL,
  `assigned_time` datetime DEFAULT NULL,
  PRIMARY KEY (`code`),
  KEY `product_id` (`product_id`),
  CONSTRAINT `vpp_code_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `product` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=latin1;


/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;
/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
