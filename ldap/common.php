<?php

if ( ! defined("LDAP_ROOT"))
{
    define("LDAP_ROOT", dirname(__file__));
}

// load settings
require_once (LDAP_ROOT . "/config.php");

// required for date() calls
date_default_timezone_set(LDAP_TIMEZONE);

// it's probably ironic that this actually returns $_POST variables
function _get($name, $default = "")
{
    if (isset($_POST[$name]))
    {
        return $_POST[$name];
    }
    else
    {
        return $default;
    }
}

?>