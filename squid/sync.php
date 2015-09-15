#!/usr/bin/php
<?php

define("SQUID_ROOT", dirname(__file__));
require_once (SQUID_ROOT . "/common.php");

function getPgConnectionString($server, $port, $name, $username, $password)
{
    return "host=$server port=$port dbname=$name user=$username password='" . addslashes($password) . "' connect_timeout=" . SQUID_CONNECT_TIMEOUT;
}

function processRecord($mac, $un, $sn, $guid)
{
    global $macs, $toDelete, $toAdd;

    if (in_array($mac, $macs))
    {
        // Profile Manager databases are defined in descending order of priority
        return;
    }

    $macs[]  = $mac;
    $lineId  = array_search( array($mac, $un, $sn, $guid), $toDelete);

    if ($lineId === false)
    {
        $toAdd[] = array($mac, $un, $sn, $guid);
    }
    else
    {
        unset($toDelete[$lineId]);
    }
}

function processMACRecord($mac, $auth_neg)
{
    global $macs, $toDelete, $toAdd;

    if (in_array($mac, $macs))
    {
        // device databases are defined in descending order of priority
        return;
    }

    $macs[]  = $mac;
    $lineId  = array_search( array($mac, $auth_neg), $toDelete);

    if ($lineId === false)
    {
        $toAdd[] = array($mac, $auth_neg);
    }
    else
    {
        unset($toDelete[$lineId]);
    }
}

$conn = new mysqli(SQUID_DB_SERVER, SQUID_DB_USERNAME, SQUID_DB_PASSWORD, SQUID_DB_NAME);

if (mysqli_connect_error())
{
    exit ("Unable to connect to database: " . mysqli_connect_error());
}

$macs     = array();
$deleted  = 0;
$added    = 0;

// if true, auth_negotiate_macs will be re-written
$macAddressesChanged = false;

foreach ($SQUID_PM_DB as $pmId => $pmDb)
{
    if (isset($pmDb["NO_SYNC"]) && $pmDb["NO_SYNC"])
    {
        continue;
    }

    // retrieve cached device records
    $rs = mysqli_query($conn, "SELECT line_id, mac_address, username, serial_number, user_guid FROM user_devices WHERE server_name = '" . mysqli_real_escape_string($conn, $pmId) . "'");

    if ($rs === false)
    {
        // TODO: something more decisive here
        continue;
    }

    $toDelete  = array();
    $toAdd     = array();

    while ($row = mysqli_fetch_row($rs))
    {
        $toDelete[$row[0]] = array(strtolower(trim($row[1])), trim($row[2]), trim($row[3]), trim($row[4]));
    }

    mysqli_free_result($rs);

    // retrieve latest device records
    $pconn = pg_connect(getPgConnectionString($pmDb["SERVER"], $pmDb["PORT"], $pmDb["NAME"], $pmDb["USERNAME"], $pmDb["PASSWORD"]));

    if ($pconn === false)
    {
        // TODO: add log entry / email notification here
        continue;
    }

    $targetTypes = array("ios", "mac");

    if (isset($pmDb["TARGET_TYPES"]))
    {
        $targetTypes = $pmDb["TARGET_TYPES"];
    }

    // limit ourselves to devices with recent checkins that aren't placeholders
    $prs = pg_query($pconn, "SELECT devices.\"WiFiMAC\", devices.\"EthernetMAC\", devices.\"ProductName\", users.short_name, devices.\"SerialNumber\", users.guid
FROM devices
	INNER JOIN users ON devices.user_id = users.id
WHERE devices.mdm_target_type IN ('" . implode("', '", $targetTypes) . "')
	AND devices.token IS NOT NULL
	AND devices.last_checkin_time >= NOW() AT TIME ZONE 'UTC' - INTERVAL '3 months'");

    if ($prs === false)
    {
        // TODO: add log entry / email notification here
        continue;
    }

    while ($row = pg_fetch_row($prs))
    {
        $mac   = strtolower(trim($row[0]));
        $mac2  = strtolower(trim($row[1]));
        $pn    = strtolower(trim($row[2]));
        $un    = trim($row[3]);
        $sn    = trim($row[4]);
        $guid  = trim($row[5]);

        if ($mac)
        {
            processRecord($mac, $un, $sn, $guid);
        }

        if ($mac2 && preg_match("/^(macmini|imac)/", $pn))
        {
            processRecord($mac2, $un, $sn, $guid);
        }
    }

    // delete invalid device records from cache
    $q = mysqli_prepare($conn, "DELETE FROM user_devices WHERE line_id = ?");
    mysqli_stmt_bind_param($q, "i", $lineId);

    // $toDelete is keyed on line_id
    $lineIds = array_keys($toDelete);

    foreach ($lineIds as $lineId)
    {
        if (mysqli_stmt_execute($q) === false)
        {
            exit ("Unable to delete cached device record: " . mysqli_error());
        }

        $deleted++;
    }

    // add new device records to cache
    $q = mysqli_prepare($conn, "INSERT INTO user_devices (server_name, mac_address, username, no_proxy, serial_number, user_guid) VALUES (?, ?, ?, ?, ?, ?)");
    mysqli_stmt_bind_param($q, "ssssss", $pmId, $mac, $un, $noProxy, $sn, $guid);

    foreach ($toAdd as $device)
    {
        list ($mac, $un, $sn, $guid) = $device;

        // TODO: allow for alternate LDAP particulars here
        $groups   = getUserGroups($un, false, false);
        $noProxy  = 'N';

        if (is_array($groups))
        {
            foreach ($groups as $group)
            {
                if (isset($SQUID_LDAP_GROUP_PERMISSIONS[$group]['ALLOW_NO_PROXY']) && $SQUID_LDAP_GROUP_PERMISSIONS[$group]['ALLOW_NO_PROXY'])
                {
                    $noProxy = 'Y';

                    break;
                }
            }
        }

        if (mysqli_stmt_execute($q) === false)
        {
            exit ("Unable to create cached device record: " . mysqli_error());
        }

        $added++;
    }

    // PART 2
    //
    // retrieve cached device records
    $rs = mysqli_query($conn, "SELECT line_id, mac_address, auth_negotiate FROM mac_addresses WHERE server_name = '" . mysqli_real_escape_string($conn, $pmId) . "'");

    if ($rs === false)
    {
        // TODO: something more decisive here
        continue;
    }

    $toDelete  = array();
    $toAdd     = array();

    while ($row = mysqli_fetch_row($rs))
    {
        $toDelete[$row[0]] = array(strtolower(trim($row[1])), strtoupper(trim($row[2])));
    }

    mysqli_free_result($rs);

    // limit ourselves to unassigned Macs
    $prs = pg_query($pconn, "SELECT mdm_target_type, \"WiFiMAC\", \"EthernetMAC\"
FROM devices
WHERE mdm_target_type IN ('mac', 'ios')
    AND token IS NOT NULL
    AND user_id IS NULL");

    if ($prs === false)
    {
        // TODO: add log entry / email notification here
        continue;
    }

    while ($row = pg_fetch_row($prs))
    {
        $type  = strtolower(trim($row[0]));
        $mac   = strtolower(trim($row[1]));
        $mac2  = strtolower(trim($row[2]));
        $neg   = "Y";

        if ($type == "ios")
        {
            $neg = "N";
        }

        if ($mac)
        {
            processMACRecord($mac, $neg);
        }

        if ($mac2)
        {
            processMACRecord($mac2, $neg);
        }
    }

    // delete invalid device records from cache
    $q = mysqli_prepare($conn, "DELETE FROM mac_addresses WHERE line_id = ?");
    mysqli_stmt_bind_param($q, "i", $lineId);

    // $toDelete is keyed on line_id
    $lineIds = array_keys($toDelete);

    foreach ($lineIds as $lineId)
    {
        if (mysqli_stmt_execute($q) === false)
        {
            exit ("Unable to delete cached device record: " . mysqli_error());
        }

        $deleted++;
        $macAddressesChanged = true;
    }

    // add new device records to cache
    $q = mysqli_prepare($conn, "INSERT INTO mac_addresses (server_name, mac_address, auth_negotiate) VALUES (?, ?, ?)");
    mysqli_stmt_bind_param($q, "sss", $pmId, $mac, $auth_neg);

    foreach ($toAdd as $device)
    {
        list ($mac, $auth_neg) = $device;

        if (mysqli_stmt_execute($q) === false)
        {
            exit ("Unable to create cached device record: " . mysqli_error());
        }

        $added++;
        $macAddressesChanged = true;
    }
}

writeLog("Profile Manager sync completed. Added: $added; deleted: $deleted");

// now, sync device records from FOG
if ( ! is_array($SQUID_FOG_DB))
{
    $SQUID_FOG_DB = array();
}

$macs     = array();
$deleted  = 0;
$added    = 0;

foreach ($SQUID_FOG_DB as $fogId => $fogDb)
{
    // retrieve cached device records
    $rs = mysqli_query($conn, "SELECT line_id, mac_address, auth_negotiate FROM mac_addresses WHERE server_name = '" . mysqli_real_escape_string($conn, $fogId) . "'");

    if ($rs === false)
    {
        // TODO: something more decisive here
        continue;
    }

    $toDelete  = array();
    $toAdd     = array();

    while ($row = mysqli_fetch_row($rs))
    {
        $toDelete[$row[0]] = array(strtolower(trim($row[1])), strtoupper(trim($row[2])));
    }

    mysqli_free_result($rs);

    // retrieve latest device records
    $mconn = new mysqli($fogDb["SERVER"], $fogDb["USERNAME"], $fogDb["PASSWORD"], $fogDb["NAME"], $fogDb["PORT"]);

    if (mysqli_connect_error())
    {
        // TODO: add log entry / email notification here
        continue;
    }

    $mrs = mysqli_query($mconn, "select hostMAC from hosts where hostUseAD = 1
union
select hmMAC from hostMAC where hmHostID in (select hostID from hosts where hostUseAD = 1)");

    if ($mrs === false)
    {
        // TODO: add log entry / email notification here
        mysqli_close($mconn);

        continue;
    }

    while ($row = mysqli_fetch_row($mrs))
    {
        $mac = strtolower(trim($row[0]));

        if ($mac)
        {
            processMACRecord($mac, "Y");
        }
    }

    mysqli_free_result($mrs);
    mysqli_close($mconn);

    // delete invalid device records from cache
    $q = mysqli_prepare($conn, "DELETE FROM mac_addresses WHERE line_id = ?");
    mysqli_stmt_bind_param($q, "i", $lineId);

    // $toDelete is keyed on line_id
    $lineIds = array_keys($toDelete);

    foreach ($lineIds as $lineId)
    {
        if (mysqli_stmt_execute($q) === false)
        {
            exit ("Unable to delete cached device record: " . mysqli_error());
        }

        $deleted++;
    }

    // add new device records to cache
    $q = mysqli_prepare($conn, "INSERT INTO mac_addresses (server_name, mac_address, auth_negotiate) VALUES (?, ?, ?)");
    mysqli_stmt_bind_param($q, "sss", $fogId, $mac, $auth_neg);

    foreach ($toAdd as $device)
    {
        list ($mac, $auth_neg) = $device;

        if (mysqli_stmt_execute($q) === false)
        {
            exit ("Unable to create cached device record: " . mysqli_error());
        }

        $added++;
    }
}

writeLog("FOG sync completed. Added: $added; deleted: $deleted");

if ($macAddressesChanged || ($added + $deleted > 0))
{
    $rs = mysqli_query($conn, "SELECT mac_address FROM mac_addresses WHERE auth_negotiate = 'Y' ORDER BY mac_address");

    if ($rs === false)
    {
        // TODO: something more decisive here
        exit;
    }

    $macs = array();

    while ($row = mysqli_fetch_row($rs))
    {
        $macs[] = strtolower(trim($row[0]));
    }

    mysqli_free_result($rs);

    // generate up-to-date MAC database for Squid
    file_put_contents(SQUID_ROOT . "/auth_negotiate_macs", implode("\n", $macs));
    $rs = mysqli_query($conn, "SELECT mac_address FROM mac_addresses WHERE auth_negotiate <> 'Y' ORDER BY mac_address");

    if ($rs === false)
    {
        // TODO: something more decisive here
        exit;
    }

    $macs = array();

    while ($row = mysqli_fetch_row($rs))
    {
        $macs[] = strtolower(trim($row[0]));
    }

    mysqli_free_result($rs);

    // generate up-to-date MAC database for Squid
    file_put_contents(SQUID_ROOT . "/other_macs", implode("\n", $macs));

    // TODO: reload Squid here
}

// clean up any session/device records that have been supplanted by Profile Manager or FOG records
mysqli_multi_query($conn, "DELETE FROM auth_sessions WHERE expiry_time_utc > UTC_TIMESTAMP() AND mac_address IN (SELECT mac_address FROM mac_addresses UNION SELECT mac_address FROM user_devices WHERE server_name IS NOT NULL);
CREATE TEMPORARY TABLE temp_user_devices SELECT line_id FROM user_devices WHERE server_name IS NULL AND mac_address IN (SELECT mac_address FROM mac_addresses UNION SELECT mac_address FROM user_devices WHERE server_name IS NOT NULL);
DELETE FROM user_devices WHERE line_id IN (SELECT line_id FROM temp_user_devices);
DROP TEMPORARY TABLE temp_user_devices");

// clean up all of the iptables chains we administer
getLock();
iptablesUpdate();
releaseLock();

// PRETTY_NESTED_ARRAYS,0

?>