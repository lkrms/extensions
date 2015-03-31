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

function createPassword($length = LDAP_PASSWORD_LENGTH)
{
    $chr     = "2345789ABCDEFGHJKLMNPQRSTUVWXYZacdefghijkmnpqrstuvwxyz2345789";
    $chrLen  = strlen($chr);

    do
    {
        $passwd = "";

        for ($i = 0; $i < $length; $i++)
        {
            $passwd .= substr($chr, rand(0, $chrLen - 1), 1);
        }
    }
    while ( ! preg_match('/^(?=.*[A-Z])(?=.*[a-z])(?=.*[0-9]).*$/', $passwd));

    return $passwd;
}

?>