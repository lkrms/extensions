#!/usr/bin/php
<?php

define("SQUID_ROOT", dirname(__file__));
require_once (SQUID_ROOT . "/common.php");
error_reporting(0);

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
            writeLog("Retrieved from cache: $key => " . ($un ? $un : "NOT AUTHORISED"), true);

            if ( ! is_null($un))
            {
                writeReply("OK user=$un");
            }
            else
            {
                writeReply("ERR");
            }

            return true;
        }
    }

    return false;
}

function cleanUp()
{
    global $ad, $mconn;

    if (isset($ad))
    {
        ldap_unbind($ad);
        unset($GLOBALS["ad"]);
    }

    if (isset($mconn))
    {
        mysqli_close($mconn);
        unset($GLOBALS["mconn"]);
    }
}

$start  = microtime(true);
$count  = 0;
$time   = 0;

// to minimise login latency, we do our own caching
$mc = null;

if (class_exists("Memcached"))
{
    $mc = new Memcached();

    if ( ! $mc->addServer("localhost", 11211))
    {
        $mc = null;
        writeLog("WARNING: unable to connect to Memcached server on localhost. Caching has been disabled. This will adversely affect performance.");
    }
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
    $onLan  = true;

    // we could do more sanity checks here, but Squid is a trustworthy input source
    if ( ! $srcIP)
    {
        writeReply(SQUID_FAILURE_CODE . " message=\"Invalid input to external_auth. IP address expected.\"");

        continue;
    }

    if (isOnLan($srcIP))
    {
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
    }
    else
    {
        $mac    = null;
        $onLan  = false;
    }

    $port = null;

    // has a port number been passed?
    if (isset($input[1]) && preg_match('/^[0-9]+$/', $input[1]))
    {
        $port = $input[1] + 0;
        array_splice($input, 1, 1);
    }

    if (is_null($mac))
    {
        if (is_null($port))
        {
            writeReply(SQUID_FAILURE_CODE . " message=\"Unable to determine client MAC address or service port.\"");

            continue;
        }
        else
        {
            $mac = $port;
        }
    }

    // is Squid telling us that other authentication has passed?
    if ($onLan && isset($input[1]) && substr($input[1], 0, 2) == "__")
    {
        writeReply("OK");

        // cache accordingly if so
        $input[1]  = substr($input[1], 2);
        $un        = null;

        if ($input[1] == "VIRTUAL")
        {
            $input[1]  = "";
            $un        = defined("SQUID_VIRTUAL_USER") ? SQUID_VIRTUAL_USER : "virtual";
            $ttl       = SQUID_VIRTUAL_TTL;
        }

        cacheResult($srcIP, $mac, $input[1], $un, $ttl);

        continue;
    }

    if (checkCache($srcIP, $mac, isset($input[1]) ? $input[1] : ""))
    {
        continue;
    }

    // check for a match in device / session database
    $mconn = mysqli_connect(SQUID_DB_SERVER, SQUID_DB_USERNAME, SQUID_DB_PASSWORD, SQUID_DB_NAME);

    if ( ! mysqli_connect_error())
    {
        $un            = null;
        $ldapServer    = SQUID_LDAP_SERVER;
        $ldapUser      = SQUID_LDAP_USER_DN;
        $ldapPassword  = SQUID_LDAP_USER_PW;
        $ldapBase      = SQUID_LDAP_BASE_DN;
        $ldapGroups    = $SQUID_LDAP_GROUP_DN;

        // try devices table first
        $servers = array_keys($SQUID_PM_DB);

        if ($onLan && $servers)
        {
            $rs = mysqli_query($mconn, "select username, server_name from user_devices where mac_address = '$mac' and (server_name in ('" . implode("', '", $servers) . "') or server_name is null) order by line_id desc");

            if ($rs && ($row = $rs->fetch_row()))
            {
                $un      = $row[0];
                $server  = $row[1];

                if (isset($SQUID_PM_DB[$server]["LDAP"]))
                {
                    $ldapServer    = $SQUID_PM_DB[$server]["LDAP"]["SERVER"];
                    $ldapUser      = $SQUID_PM_DB[$server]["LDAP"]["USER_DN"];
                    $ldapPassword  = $SQUID_PM_DB[$server]["LDAP"]["USER_PW"];
                    $ldapBase      = $SQUID_PM_DB[$server]["LDAP"]["BASE_DN"];
                    $ldapGroups    = $SQUID_PM_DB[$server]["LDAP"]["GROUP_DN"];
                }
            }
        }

        // next, ad-hoc sessions
        if ($onLan && ! $un)
        {
            $rs = mysqli_query($mconn, "select username, TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), expiry_time_utc) as ttl from auth_sessions where mac_address = '$mac' and ip_address = '$srcIP' and expiry_time_utc > UTC_TIMESTAMP()");

            if ($rs && ($row = $rs->fetch_row()))
            {
                $un = $row[0];

                // enforce the session expiry time
                $ttl = $row[1] + 0;
            }
        }

        // finally, WAN sessions
        if ( ! $onLan && ! $un)
        {
            // TODO: check against active $servers, load alternate LDAP settings
            $rs = mysqli_query($mconn, "select username, TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), expiry_time_utc) as ttl, session_id from wan_sessions where proxy_port = $port and ip_address = '$srcIP' and expiry_time_utc > UTC_TIMESTAMP()");

            if ($rs && ($row = $rs->fetch_row()))
            {
                $un = $row[0];

                // enforce the session expiry time
                $ttl = $row[1] + 0;

                // keep the session alive
                renewWanSession($row[2], $mconn);
            }
        }

        if ( ! $un)
        {
            writeReply("ERR");

            // negative cache TTL is 5 seconds
            cacheResult($srcIP, $mac, isset($input[1]) ? $input[1] : "", null, 5);

            continue;
        }

        if ($ttl > SQUID_MAX_TTL)
        {
            $ttl = SQUID_MAX_TTL;
        }

        $userGroups = getUserGroups($un, true, true, $ldapServer, $ldapUser, $ldapPassword, $ldapBase);

        if ($userGroups === false)
        {
            // this could indicate a disabled account or an LDAP error
            writeReply(SQUID_FAILURE_CODE . " message=\"Unable to retrieve groups for '$un'.\"");
            cacheResult($srcIP, $mac, isset($input[1]) ? $input[1] : "", null, 10);

            continue;
        }

        if ( ! isset($input[1]))
        {
            writeReply("OK user=$un");
            cacheResult($srcIP, $mac, "", $un, $ttl);

            continue;
        }
        else
        {
            if ( ! isset($ldapGroups[$input[1]]))
            {
                writeReply(SQUID_FAILURE_CODE . " message=\"No matching group DN found for '$input[1]'.\"");
                cacheResult($srcIP, $mac, $input[1], null, SQUID_MAX_TTL);

                continue;
            }

            if (in_array($ldapGroups[$input[1]], $userGroups))
            {
                writeReply("OK user=$un");
                cacheResult($srcIP, $mac, $input[1], $un, $ttl);

                continue;
            }
            else
            {
                writeReply("ERR");
                cacheResult($srcIP, $mac, $input[1], null, $ttl);

                continue;
            }
        }
    }
    else
    {
        writeReply(SQUID_FAILURE_CODE . " message=\"Unable to connect to MySQL database.\"");

        continue;
    }
}

writeLog("$count requests processed, average processing time " . ($count ? $time / $count : 0) . "s");

// PRETTY_NESTED_ARRAYS,0

?>