<?php

if ( ! defined("SQUID_ROOT"))
{
    define("SQUID_ROOT", dirname(__file__));
}

// for PEAR
ini_set("include_path", SQUID_ROOT . "/../lib/php");

// load settings
require_once (SQUID_ROOT . "/config.php");

// load libraries
require_once (SQUID_ROOT . "/../lib/fpdf/fpdf.php");
require_once (SQUID_ROOT . "/../lib/php/Mail.php");
require_once (SQUID_ROOT . "/../lib/php/Mail/mime.php");

// required for date() calls
date_default_timezone_set(SQUID_TIMEZONE);

function writeLog($message, $verbose = false)
{
    global $pid;

    if (( ! $verbose || SQUID_LOG_VERBOSE) && SQUID_LOG_FILE)
    {
        if ( ! isset($pid))
        {
            $pid = getmypid();
        }

        // let echo handle file locking - PHP streams not suited to this
        shell_exec("echo \"[" . date("r") . "] #$pid: $message\" >> \"" . SQUID_LOG_FILE . "\"");
    }
}

function getUserGroups($username, $checkEnabled = true, $ldapServer = SQUID_LDAP_SERVER, $ldapUser = SQUID_LDAP_USER_DN, $ldapPassword = SQUID_LDAP_USER_PW, $ldapBase = SQUID_LDAP_BASE_DN)
{
    global $ad;

    if (($ad = ldap_connect($ldapServer)) === false)
    {
        return false;
    }

    ldap_set_option($ad, LDAP_OPT_REFERRALS, false);
    ldap_set_option($ad, LDAP_OPT_PROTOCOL_VERSION, 3);

    if ( ! ldap_bind($ad, $ldapUser, $ldapPassword))
    {
        return false;
    }

    if ($checkEnabled)
    {
        $q = "(&(sAMAccountName=$username)(!(useraccountcontrol:1.2.840.113556.1.4.803:=2)))";
    }
    else
    {
        $q = "(sAMAccountName=$username)";
    }

    // first, look up the user's DN
    $ls = ldap_search($ad, $ldapBase, $q, array("dn"), 0, 0, SQUID_CONNECT_TIMEOUT);

    if ($ls === false || ($r = ldap_get_entries($ad, $ls)) === false || ! isset($r[0]["dn"]))
    {
        return false;
    }

    $dn = $r[0]["dn"];

    // next, find the user's groups recursively, limiting results to security groups
    $q   = "(&(member:1.2.840.113556.1.4.1941:=$dn)(groupType:1.2.840.113556.1.4.803:=2147483648))";
    $ls  = ldap_search($ad, $ldapBase, $q, array("dn"), 0, 0, SQUID_CONNECT_TIMEOUT);

    if ($ls === false || ($r = ldap_get_entries($ad, $ls)) === false)
    {
        return false;
    }

    $groups = array();

    for ($i = 0; $i < $r["count"]; $i++)
    {
        $groups[] = $r[$i]["dn"];
    }

    return $groups;
}

// PRETTY_NESTED_ARRAYS,0

?>