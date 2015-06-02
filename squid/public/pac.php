<?php

define("SQUID_ROOT", dirname(__file__) . "/..");
require_once (SQUID_ROOT . "/common.php");

if ( ! $isSecure)
{
    exit;
}

$srcIP = $_SERVER["REMOTE_ADDR"];

// defaults for LAN clients (no authentication performed during PAC request)
$pacFile  = SQUID_ROOT . "/pac.lan.js";
$subs     = array();

if ( ! isOnLan($srcIP))
{
    $guid  = _get("g");
    $sn    = _get("s");

    if ( ! $guid || ! $sn)
    {
        exit ("Invalid request.");
    }

    $conn = new mysqli(SQUID_DB_SERVER, SQUID_DB_USERNAME, SQUID_DB_PASSWORD, SQUID_DB_NAME);

    if (mysqli_connect_error())
    {
        exit ("Unable to connect to database. " . mysqli_connect_error());
    }

    $pacFile = SQUID_ROOT . "/pac.blocked.js";
    getLock();

    // do we already have an authenticated session?
    // TODO: check server_name matches an active server (and retain in wan_sessions)
    $q = $conn->prepare("select user_devices.username, user_devices.serial_number, user_devices.user_guid, wan_sessions.session_id, wan_sessions.proxy_port,
	(select group_concat(distinct proxy_port separator ',') from wan_sessions where ip_address = ? and expiry_time_utc > ADDTIME(UTC_TIMESTAMP(), '0:00:05') group by ip_address) as used_ports
from user_devices
	left join wan_sessions on user_devices.username = wan_sessions.username and user_devices.serial_number = wan_sessions.serial_number and wan_sessions.ip_address = ? and wan_sessions.expiry_time_utc > ADDTIME(UTC_TIMESTAMP(), '0:00:05')
where user_devices.user_guid = ? and user_devices.serial_number = ?");

    if ( ! $q)
    {
        releaseLock();
        exit ("Unable to query the database.");
    }

    $q->bind_param("ssss", $srcIP, $srcIP, $guid, $sn);

    if ( ! $q->execute())
    {
        releaseLock();
        exit ("Unable to query the database.");
    }

    $q->bind_result($username, $serialNumber, $userGuid, $sessionId, $proxyPort, $usedPorts);

    if ($q->fetch())
    {
        $q->close();

        if (is_null($sessionId))
        {
            // no session, but a matching device record was found, so we're ready to authorise a new session
            $usedPorts = explode(",", $usedPorts);

            // first, identify a spare port
            foreach ($SQUID_WAN_PORTS as $port)
            {
                if ( ! in_array($port, $usedPorts))
                {
                    $proxyPort = $port;

                    break;
                }
            }

            if (is_null($proxyPort))
            {
                releaseLock();
                exit ("No spare WAN ports for this IP address.");
            }

            if ($conn->query("insert into wan_sessions (username, serial_number, ip_address, proxy_port, auth_time_utc, expiry_time_utc)
values ('" . $conn->escape_string($username) . "', '" . $conn->escape_string($serialNumber) . "', '$srcIP', $proxyPort, UTC_TIMESTAMP(), ADDTIME(UTC_TIMESTAMP(), '" . SQUID_WAN_SESSION_DURATION . "'))"))
            {
                iptablesAddWanUser($srcIP, $proxyPort);
            }
            else
            {
                releaseLock();
                exit ("Error creating session.");
            }
        }
        else
        {
            renewWanSession($sessionId, $conn);
        }

        releaseLock();

        // check that our user is active, and hand out a custom PAC if required
        $userGroups = getUserGroups($username, true, false);

        // if $userGroups === FALSE, the user is inactive (or we encountered an LDAP error)
        if (is_array($userGroups))
        {
            $pacFile         = SQUID_ROOT . "/pac.wan.js";
            $subs["{PORT}"]  = $proxyPort;

            foreach ($userGroups as $userGroup)
            {
                if (isset($SQUID_CUSTOM_PAC) && is_array($SQUID_CUSTOM_PAC) && array_key_exists($userGroup, $SQUID_CUSTOM_PAC))
                {
                    $pacFile = $SQUID_CUSTOM_PAC[$userGroup];

                    break;
                }
            }
        }
    }
    else
    {
        $q->close();
        releaseLock();
    }
}

if ( ! file_exists($pacFile))
{
    exit ("PAC file not found.");
}

$pac = file_get_contents($pacFile);

if ($subs)
{
    $pac = str_replace(array_keys($subs), array_values($subs), $pac);
}

header("Content-Type: application/x-ns-proxy-autoconfig");
header("Cache-Control: no-cache, must-revalidate");
header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");

// spit it out
print $pac;

?>