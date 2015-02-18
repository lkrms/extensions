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

function _post($name, $default = "")
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

function _get($name, $default = "")
{
    if (isset($_GET[$name]))
    {
        return $_GET[$name];
    }
    else
    {
        return $default;
    }
}

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

// ensures the given MAC address is lowercase and 17 characters long (OS X hosts don't add leading zeroes)
function sanitiseMac($mac)
{
    $macBytes  = explode(":", strtolower($mac));
    $mac       = "";

    foreach ($macBytes as $macByte)
    {
        if ($mac)
        {
            $mac .= ":";
        }

        if (strlen($macByte) == 2)
        {
            $mac .= $macByte;
        }
        else
        {
            $mac .= "0$macByte";
        }
    }

    return $mac;
}

function isOnLan($ip)
{
    global $SQUID_LAN_SUBNETS;

    // convert dotted quad to integer
    $ip = ip2long($ip);

    foreach ($SQUID_LAN_SUBNETS as $subnet)
    {
        list ($network, $mask)  = explode("/", $subnet);
        $network                = ip2long($network);
        $mask                   = ip2long($mask);

        if (($ip & $mask) == $network)
        {
            return true;
        }
    }

    return false;
}

function getUserGroups($username, $checkEnabled = true, $globalAd = true, $ldapServer = SQUID_LDAP_SERVER, $ldapUser = SQUID_LDAP_USER_DN, $ldapPassword = SQUID_LDAP_USER_PW, $ldapBase = SQUID_LDAP_BASE_DN)
{
    // see cleanUp function in external_auth.php
    if ($globalAd)
    {
        global $ad;
    }

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

function iptablesUpdate()
{
    global $iptablesConn;

    // update proxy-enforced and no-proxy chains
    iptablesUpdateChain(true);
    iptablesUpdateChain(false);

    // this function needs to be safe within a long-running process, so cleanup is important
    $iptablesConn->close();
    unset($GLOBALS["iptablesConn"]);
}

function iptablesUpdateChain($proxyEnforced)
{
    $iptMacs   = iptablesGetMacs($proxyEnforced);
    $macs      = iptablesGetDbMacs($proxyEnforced);
    $toAdd     = array();
    $toDelete  = array();

    // pass 1: identify MACs to remove from chain
    foreach ($iptMacs as $mac)
    {
        if ( ! in_array($mac, $macs))
        {
            $toDelete[] = $mac;
        }
    }

    // pass 2: identify MACs to add to chain
    foreach ($macs as $mac)
    {
        if ( ! in_array($mac, $iptMacs))
        {
            $toAdd[] = $mac;
        }
        else
        {
            // also check for duplicates in chain
            $count = count(array_keys($iptMacs, $mac));

            while ($count > 1)
            {
                $toDelete[] = $mac;
                $count--;
            }
        }
    }

    foreach ($toAdd as $mac)
    {
        iptablesAddUserDevice($mac, $proxyEnforced);
    }

    foreach ($toDelete as $mac)
    {
        iptablesRemoveUserDevice($mac, $proxyEnforced);
    }
}

function iptablesGetDbMacs($proxyEnforced = true)
{
    global $iptablesConn;

    if ( ! isset($iptablesConn))
    {
        $iptablesConn = new mysqli(SQUID_DB_SERVER, SQUID_DB_USERNAME, SQUID_DB_PASSWORD, SQUID_DB_NAME);

        if (mysqli_connect_error())
        {
            exit ("Unable to connect to database. " . mysqli_connect_error());
        }
    }

    $noProxy  = $proxyEnforced ? 'N' : 'Y';
    $rs       = $iptablesConn->query("select mac_address from auth_sessions where expiry_time_utc > UTC_TIMESTAMP() and no_proxy = '$noProxy'
union
select mac_address from user_devices where server_name is null and no_proxy = '$noProxy'");

    if ( ! $rs)
    {
        exit ("Unable to query the database.");
    }

    $macs = array();

    while ($row = $rs->fetch_row())
    {
        $macs[] = sanitiseMac(trim($row[0]));
    }

    $rs->close();

    return $macs;
}

function iptablesGetMacs($proxyEnforced = true)
{
    $chain  = $proxyEnforced ? SQUID_IPTABLES_USER_DEVICES_CHAIN : SQUID_IPTABLES_NO_PROXY_CHAIN;
    $rules  = explode("\n", shell_exec(SQUID_IPTABLES_PATH . " -t filter -L $chain -n --line-numbers | egrep '^[0-9]+'"));
    $macs   = array();

    foreach ($rules as $rule)
    {
        if (preg_match("/(([0-9a-f]{1,2}:){5}[0-9a-f]{1,2})/i", $rule, $matches))
        {
            $macs[] = sanitiseMac($matches[0]);
        }
    }

    return $macs;
}

function iptablesAddUserDevice($mac, $proxyEnforced = true, $preSanitised = false)
{
    if ( ! $preSanitised)
    {
        $mac = sanitiseMac($mac);
    }

    $chain = $proxyEnforced ? SQUID_IPTABLES_USER_DEVICES_CHAIN : SQUID_IPTABLES_NO_PROXY_CHAIN;

    // attempt deletion to prevent duplication
    shell_exec(SQUID_IPTABLES_PATH . " -t filter -D $chain -m mac --mac-source $mac -j ACCEPT");
    shell_exec(SQUID_IPTABLES_PATH . " -t filter -A $chain -m mac --mac-source $mac -j ACCEPT");
}

function iptablesRemoveUserDevice($mac, $proxyEnforced = true, $preSanitised = false)
{
    if ( ! $preSanitised)
    {
        $mac = sanitiseMac($mac);
    }

    $chain = $proxyEnforced ? SQUID_IPTABLES_USER_DEVICES_CHAIN : SQUID_IPTABLES_NO_PROXY_CHAIN;

    // as above
    shell_exec(SQUID_IPTABLES_PATH . " -t filter -D $chain -m mac --mac-source $mac -j ACCEPT");
}

$isCli     = PHP_SAPI == "cli";
$isPost    = false;
$isSecure  = false;

if ( ! $isCli)
{
    $isPost    = $_SERVER["REQUEST_METHOD"] == "POST";
    $isSecure  = ! empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] != "off";
}

// PRETTY_NESTED_ARRAYS,0

?>