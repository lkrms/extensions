#!/usr/bin/php
<?php

define("SQUID_ROOT", dirname(__file__));
require_once (SQUID_ROOT . "/common.php");

function getPgConnectionString($server, $port, $name, $username, $password)
{
    return "host=$server port=$port dbname=$name user=$username password='" . addslashes($password) . "' connect_timeout=" . SQUID_CONNECT_TIMEOUT;
}

function processRecord($mac, $un)
{
    global $macs, $toDelete, $toAdd;

    if (in_array($mac, $macs))
    {
        // Profile Manager databases are defined in descending order of priority
        return;
    }

    $macs[]  = $mac;
    $lineId  = array_search( array($mac, $un), $toDelete);

    if ($lineId === false)
    {
        $toAdd[] = array($mac, $un);
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

foreach ($SQUID_PM_DB as $pmId => $pmDb)
{
    if (isset($pmDb["NO_SYNC"]) && $pmDb["NO_SYNC"])
    {
        continue;
    }

    // retrieve cached device records
    $rs = mysqli_query($conn, "SELECT line_id, mac_address, username FROM user_devices WHERE server_name = '" . mysqli_real_escape_string($conn, $pmId) . "'");

    if ($rs === false)
    {
        // TODO: something more decisive here
        continue;
    }

    $toDelete  = array();
    $toAdd     = array();

    while ($row = mysqli_fetch_row($rs))
    {
        $toDelete[$row[0]] = array(strtolower(trim($row[1])), trim($row[2]));
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
    $prs = pg_query($pconn, "SELECT devices.\"WiFiMAC\", devices.\"EthernetMAC\", devices.\"ProductName\", users.short_name
FROM devices
	INNER JOIN users ON devices.user_id = users.id
WHERE devices.mdm_target_type IN ('" . implode("', '", $targetTypes) . "')
	AND devices.token IS NOT NULL
	AND devices.last_checkin_time >= NOW() AT TIME ZONE 'UTC' - INTERVAL '5 days'");

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

        if ($mac)
        {
            processRecord($mac, $un);
        }

        if ($mac2 && preg_match("/^(macmini|imac)/", $pn))
        {
            processRecord($mac2, $un);
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
    // delete invalid device records from cache
    $q = mysqli_prepare($conn, "INSERT INTO user_devices (server_name, mac_address, username) VALUES (?, ?, ?)");
    mysqli_stmt_bind_param($q, "sss", $pmId, $mac, $un);

    foreach ($toAdd as $device)
    {
        list ($mac, $un) = $device;

        if (mysqli_stmt_execute($q) === false)
        {
            exit ("Unable to create cached device record: " . mysqli_error());
        }

        $added++;
    }
}

writeLog("Added: $added; deleted: $deleted");

// PRETTY_NESTED_ARRAYS,0

?>