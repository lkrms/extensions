<?php

// what to call the authentication portal
define("SQUID_AUTH_TITLE", "My Squid Authenticator");

// where to direct users if something breaks
define("SQUID_SUPPORT_URL", "http://helpdesk.mydomain.local/");

// where to send users if the authentication portal can't find a usable referer
define("SQUID_DEFAULT_REDIRECT", "http://www.mydomain.com/");

// how many seconds to wait for MySQL / Profile Manager / LDAP connections
define("SQUID_CONNECT_TIMEOUT", 4);

// how many seconds to cache credentials for (unless an explicit session expiry has been set in BYOD database)
define("SQUID_DEFAULT_TTL", 300);
define("SQUID_MAX_TTL", 300);

// some requests can trigger a temporary "authenticated" state, e.g. to allow iCloud backups to proceed
define("SQUID_VIRTUAL_USER", "virtual_user");
define("SQUID_VIRTUAL_TTL", 3600);

// LDAP settings (credentials are used when checking group membership)
define("SQUID_LDAP_SERVER", "DC01");
define("SQUID_LDAP_USER_DN", "CN=Squid,OU=Users,DC=mydomain,DC=local");
define("SQUID_LDAP_USER_PW", "PASSWORD");
define("SQUID_LDAP_BASE_DN", "OU=Users,DC=mydomain,DC=local");

// regular expressions for username/password validation (prior to LDAP binding) - ignored if empty
define("SQUID_LDAP_USERNAME_REGEX", '/^[a-z]+[\\.a-z]+$/i');
define("SQUID_LDAP_PASSWORD_REGEX", '/^.{6,}$/');

// appended to usernames before authentication is attempted
define("SQUID_LDAP_USERNAME_APPEND", "@mydomain.local");

// map short group names (as passed to our Squid external_acl_type) to LDAP DNs
$SQUID_LDAP_GROUP_DN = array(
    "my_group" => "CN=My Group,OU=Groups,DC=mydomain,DC=local"
);

// database credentials (MySQL)
define("SQUID_DB_SERVER", "localhost");
define("SQUID_DB_NAME", "squid");
define("SQUID_DB_USERNAME", "squid");
define("SQUID_DB_PASSWORD", "PASSWORD");
define("SQUID_DEFAULT_SESSION_DURATION", "01:00");

// any number of Profile Manager instances may be defined here
$SQUID_PM_DB = array(

    // keys must be globally unique and <=10 characters in length
    "PM_OSX001" => array(

        // PostgreSQL credentials (read-only permission is fine)
        "SERVER"   => "OSX001",
        "PORT"     => "5432",
        "NAME"     => "devicemgr_v2m0",    // device_management under OS X Server 2.0
        "USERNAME" => "squid",
        "PASSWORD" => "PASSWORD",

        // optional; provide if this Profile Manager's records should be checked against a particular LDAP server
        "LDAP"         => array(
            "SERVER"   => SQUID_LDAP_SERVER,
            "USER_DN"  => SQUID_LDAP_USER_DN,
            "USER_PW"  => SQUID_LDAP_USER_PW,
            "BASE_DN"  => SQUID_LDAP_BASE_DN,
            "GROUP_DN" => $SQUID_LDAP_GROUP_DN
        )
    )
);

// where to log stuff and things
define("SQUID_LOG_FILE", "/var/log/squid3/external_acl.log");
define("SQUID_LOG_VERBOSE", true);

// ERR until Squid 3.4, BH on Squid 3.4+
define("SQUID_FAILURE_CODE", "ERR");

// timezone for this instance of Squid
define("SQUID_TIMEZONE", "Australia/Sydney");

// there's a good chance arp won't be in Apache's PATH
define("SQUID_ARP_PATH", "/usr/sbin/arp");

?>