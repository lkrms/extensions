#!/usr/bin/php
<?php

define("SQUID_ROOT", dirname(__file__));
require_once (SQUID_ROOT . "/common.php");
error_reporting(0);

function writeLog($message, $verbose = false)
{
    global $pid;

    if (( ! $verbose || SQUID_LOG_VERBOSE) && SQUID_LOG_FILE)
    {
        // let echo handle file locking - PHP streams not suited to this
        shell_exec("echo \"[" . date("r") . "] #$pid: $message\" >> \"" . SQUID_LOG_FILE . "\"");
    }
}

function writeReply($reply)
{
    global $count, $time, $requestStart, $requestEnd;

    // reply to Squid
    fwrite(STDOUT, "$reply\n");

    // update metrics
    $requestEnd   = microtime(true);
    $requestTime  = $requestEnd - $requestStart;
    $time        += $requestTime;
    $count++;
    writeLog("Reply: $reply (processed in {$requestTime}s)", true);
}

function cacheResult($ip, $mac, $group, $username, $ttl)
{
    global $mc;

    if ($mc)
    {
        $key = "$ip||$mac||$group";
        $mc->set($key, $username, $ttl);
        writeLog("Cached: $key => " . ($username ? $username : "NOT AUTHORISED") . " (TTL {$ttl}s)", true);
    }
}

function checkCache($ip, $mac, $group)
{
    global $mc;

    if ($mc)
    {
        $key  = "$ip||$mac||$group";
        $un   = $mc->get($key);

        if ($un !== false)
        {
            if ( ! is_null($un))
            {
                writeReply("OK user=$un");
            }
            else
            {
                writeReply("ERR");
            }

            writeLog("Retrieved from cache: $key => " . ($un ? $un : "NOT AUTHORISED"), true);

            return true;
        }
    }

    return false;
}

function cleanUp()
{
    global $ad, $pconn, $pconn2, $mconn;

    if (isset($ad))
    {
        ldap_unbind($ad);
        unset($GLOBALS["ad"]);
    }

    if (isset($pconn))
    {
        pg_close($pconn);
        unset($GLOBALS["pconn"]);
    }

    if (isset($pconn2))
    {
        pg_close($pconn2);
        unset($GLOBALS["pconn2"]);
    }

    if (isset($mconn))
    {
        mysqli_close($mconn);
        unset($GLOBALS["mconn"]);
    }
}

$pid    = getmypid();
$start  = microtime(true);
$count  = 0;
$time   = 0;
$pmcs   = "host=" . SQUID_PM_DB_SERVER . " port=" . SQUID_PM_DB_PORT . " dbname=" . SQUID_PM_DB_NAME . " user=" . SQUID_PM_DB_USERNAME . " password='" . addslashes(SQUID_PM_DB_PASSWORD) . "' connect_timeout=" . SQUID_CONNECT_TIMEOUT;
$pmcs2  = "host=" . SQUID_ALT_PM_DB_SERVER . " port=" . SQUID_ALT_PM_DB_PORT . " dbname=" . SQUID_ALT_PM_DB_NAME . " user=" . SQUID_ALT_PM_DB_USERNAME . " password='" . addslashes(SQUID_ALT_PM_DB_PASSWORD) . "' connect_timeout=" . SQUID_CONNECT_TIMEOUT;

// to minimise login latency, we do our own caching
$mc = new Memcached();

if ( ! $mc->addServer("localhost", 11211))
{
    $mc = null;
    writeLog("WARNING: unable to connect to Memcached server on localhost. Caching has been disabled. This will adversely affect performance.");
}

while ( ! feof(STDIN))
{
    cleanUp();
    $inputStr      = trim(fgets(STDIN));
    $requestStart  = microtime(true);
    $requestEnd    = null;

    if ( ! $inputStr)
    {
        continue;
    }

    writeLog("Request: $inputStr", true);

    // ttl = time to live (if we cache this result)
    $ttl = SQUID_DEFAULT_TTL;

    // get client IP and MAC for starters
    $input  = explode(" ", $inputStr);
    $srcIP  = $input[0];

    // we could do more sanity checks here, but Squid is a trustworthy input source
    if ( ! $srcIP)
    {
        writeReply(SQUID_FAILURE_CODE . " message=\"Invalid input to external_auth. IP address expected.\"");

        continue;
    }

    $arp      = `arp -n $srcIP`;
    $matches  = array();

    if (preg_match("/(([0-9a-f]{1,2}:){5}[0-9a-f]{1,2})/i", $arp, $matches))
    {
        // ensure the MAC address is 17 characters long (OS X hosts don't add leading zeroes)
        $macBytes  = explode(":", strtolower($matches[0]));
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
    }
    else
    {
        writeReply(SQUID_FAILURE_CODE . " message=\"Unable to determine client MAC address.\"");

        continue;
    }

    // is Squid telling us that other authentication has passed?
    if (isset($input[1]) && substr($input[1], 0, 2) == "__")
    {
        writeReply("OK");

        // cache accordingly if so
        $input[1]     = substr($input[1], 2);
        $virtualUser  = $input[1] == "VIRTUAL";
        cacheResult($srcIP, $mac, $virtualUser ? "" : $input[1], $virtualUser ? (defined("SQUID_VIRTUAL_USER") ? SQUID_VIRTUAL_USER : "virtual") : null, $virtualUser ? SQUID_VIRTUAL_TTL : $ttl);

        continue;
    }

    if (checkCache($srcIP, $mac, isset($input[1]) ? $input[1] : ""))
    {
        continue;
    }

    // check for a match in BYOD database (i.e. fastest query first)
    $mconn = mysqli_connect(SQUID_BYOD_DB_SERVER, SQUID_BYOD_DB_USERNAME, SQUID_BYOD_DB_PASSWORD, SQUID_BYOD_DB_NAME);

    if ( ! mysqli_connect_error())
    {
        $rs = mysqli_query($mconn, "select username, TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), expiry_time_utc) as ttl from auth_sessions where mac_address = '$mac' and ip_address = '$srcIP' and expiry_time_utc > UTC_TIMESTAMP()");

        if ($rs && ($row = $rs->fetch_row()))
        {
            // enforce the session expiry time
            $ttl = $row[1] + 0;

            if ($ttl > SQUID_MAX_TTL)
            {
                $ttl = SQUID_MAX_TTL;
            }

            if ( ! isset($input[1]))
            {
                writeReply("OK user=$row[0]");
                cacheResult($srcIP, $mac, "", $row[0], $ttl);

                continue;
            }
            else
            {
                if ( ! isset($SQUID_LDAP_GROUP_DN[$input[1]]))
                {
                    writeReply(SQUID_FAILURE_CODE . " message=\"No matching group DN found for '$input[1]'.\"");
                    cacheResult($srcIP, $mac, $input[1], null, SQUID_MAX_TTL);

                    continue;
                }

                if (($ad = ldap_connect(SQUID_LDAP_SERVER)) !== false && ldap_bind($ad, SQUID_LDAP_USER_DN, SQUID_LDAP_USER_PW))
                {
                    $query  = "(&(sAMAccountName=$row[0])(memberOf:1.2.840.113556.1.4.1941:=" . $SQUID_LDAP_GROUP_DN[$input[1]] . "))";
                    $ls     = ldap_search($ad, SQUID_LDAP_BASE_DN, $query, array("sAMAccountName"), 0, 0, SQUID_CONNECT_TIMEOUT);

                    if ($ls === false || ($r = ldap_get_entries($ad, $ls)) === false)
                    {
                        writeReply(SQUID_FAILURE_CODE . " message=\"Unable to retrieve data from LDAP server.\"");

                        continue;
                    }

                    if (isset($r[0]["samaccountname"][0]))
                    {
                        writeReply("OK user=$row[0]");
                        cacheResult($srcIP, $mac, $input[1], $row[0], $ttl);

                        continue;
                    }
                    else
                    {
                        writeReply("ERR");
                        cacheResult($srcIP, $mac, $input[1], null, $ttl);

                        continue;
                    }
                }
                else
                {
                    writeReply(SQUID_FAILURE_CODE . " message=\"Unable to bind to LDAP server.\"");

                    continue;
                }
            }
        }
    }

    if (SQUID_PROFILE_MANAGER_ENABLED)
    {
        // connect to Profile Manager database
        if (($pconn = pg_connect($pmcs)) === false || pg_prepare($pconn, "get_user_GUID", "SELECT users.guid FROM devices inner join users on devices.user_id = users.id WHERE lower(\"WiFiMAC\") = \$1") === false)
        {
            writeReply(SQUID_FAILURE_CODE . " message=\"Unable to connect to Profile Manager database.\"");

            continue;
        }

        $adServer   = SQUID_LDAP_SERVER;
        $adUserDN   = SQUID_LDAP_USER_DN;
        $adUserPW   = SQUID_LDAP_USER_PW;
        $adBaseDN   = SQUID_LDAP_BASE_DN;
        $adGroupDN  = $SQUID_LDAP_GROUP_DN;

        // check for a matching GUID
        if (($result = pg_execute($pconn, "get_user_GUID", array($mac))) === false)
        {
            writeReply(SQUID_FAILURE_CODE . " message=\"Unable to retrieve data from Profile Manager database.\"");

            continue;
        }

        $guid = pg_fetch_row($result);

        // if no matching GUID was found and an alternate Profile Manager database has been configured, try the same query on it
        if ($guid === false && SQUID_ALT_PROFILE_MANAGER_ENABLED)
        {
            // connect to alternate Profile Manager database
            if (($pconn2 = pg_connect($pmcs2)) === false || pg_prepare($pconn2, "get_user_GUID", "SELECT users.guid FROM devices inner join users on devices.user_id = users.id WHERE lower(\"WiFiMAC\") = \$1") === false)
            {
                writeReply(SQUID_FAILURE_CODE . " message=\"Unable to connect to alternate Profile Manager database.\"");

                continue;
            }

            if (($result = pg_execute($pconn2, "get_user_GUID", array($mac))) === false)
            {
                writeReply(SQUID_FAILURE_CODE . " message=\"Unable to retrieve data from alternate Profile Manager database.\"");

                continue;
            }

            $guid = pg_fetch_row($result);

            // use alternate LDAP credentials
            $adServer   = SQUID_ALT_LDAP_SERVER;
            $adUserDN   = SQUID_ALT_LDAP_USER_DN;
            $adUserPW   = SQUID_ALT_LDAP_USER_PW;
            $adBaseDN   = SQUID_ALT_LDAP_BASE_DN;
            $adGroupDN  = $SQUID_ALT_LDAP_GROUP_DN;
        }

        if ($guid !== false)
        {
            // bind to LDAP server
            if (($ad = ldap_connect($adServer)) === false || ! ldap_bind($ad, $adUserDN, $adUserPW))
            {
                writeReply(SQUID_FAILURE_CODE . " message=\"Unable to bind to LDAP server.\"");

                continue;
            }

            // we have our GUID - now to search for a match in LDAP (but first we'll need to re-format the GUID)
            $guid     = str_replace("-", "", $guid[0]);
            $guid     = str_split($guid, 2);
            $bytes    = array();
            $bytes[]  = $guid[3];
            $bytes[]  = $guid[2];
            $bytes[]  = $guid[1];
            $bytes[]  = $guid[0];
            $bytes[]  = $guid[5];
            $bytes[]  = $guid[4];
            $bytes[]  = $guid[7];
            $bytes[]  = $guid[6];
            $bytes    = array_merge($bytes, array_slice($guid, 8));
            $guid     = "\\" . implode("\\", $bytes);
            $query    = "(objectGUID=$guid)";

            if (isset($input[1]))
            {
                if (isset($adGroupDN[$input[1]]))
                {
                    // this is a special memberOf query that checks membership recursively (may only work on Active Directory)
                    $query = "(&(objectGUID=$guid)(memberOf:1.2.840.113556.1.4.1941:=" . $adGroupDN[$input[1]] . "))";
                }
                else
                {
                    writeReply(SQUID_FAILURE_CODE . " message=\"No matching group DN found for '$input[1]'.\"");
                    cacheResult($srcIP, $mac, $input[1], null, SQUID_MAX_TTL);

                    continue;
                }
            }

            $ls = ldap_search($ad, $adBaseDN, $query, array("sAMAccountName"), 0, 0, SQUID_CONNECT_TIMEOUT);

            if ($ls === false || ($r = ldap_get_entries($ad, $ls)) === false)
            {
                writeReply(SQUID_FAILURE_CODE . " message=\"Unable to retrieve data from LDAP server.\"");

                continue;
            }

            // finally, we have our username!
            if (isset($r[0]["samaccountname"][0]))
            {
                $username = $r[0]["samaccountname"][0];
                writeReply("OK user=$username");
                cacheResult($srcIP, $mac, isset($input[1]) ? $input[1] : "", $username, $ttl);

                continue;
            }
        }
    }

    writeReply("ERR");
    cacheResult($srcIP, $mac, isset($input[1]) ? $input[1] : "", null, 10);
}

writeLog("$count requests processed, average processing time " . ($count ? $time / $count : 0) . "s");

// PRETTY_NESTED_ARRAYS,0

?>