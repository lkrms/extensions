<?php

define("LDAP_SERVER", "DC01");
define("LDAP_DOMAIN", "mydomain.local");
define("LDAP_BASE_DN", "OU=Users,DC=mydomain,DC=local");

// these credentials are used for privileged operations (e.g. password resets)
define("LDAP_ADMIN_USER_DN", "CN=Account Operator,OU=Users,DC=mydomain,DC=local");
define("LDAP_ADMIN_USER_PW", "PASSWORD");

// regular expressions for username/password validation (prior to LDAP binding) - ignored if empty
define("LDAP_USERNAME_REGEX", '/^[a-z]+[\\.a-z]+$/i');
define("LDAP_PASSWORD_REGEX", '/^.{6,}$/');

// displayed to users as a "good password" (but not permitted for use)
define("LDAP_EXAMPLE_PASSWORD", "radishChicago8");

// timezone for this LDAP server
define("LDAP_TIMEZONE", "Australia/Sydney");

?>