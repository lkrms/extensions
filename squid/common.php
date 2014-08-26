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

?>