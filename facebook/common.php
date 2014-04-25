<?php

if ( ! defined("FACEBOOK_ROOT"))
{
    define("FACEBOOK_ROOT", dirname(__file__));
}

// load settings
require_once (FACEBOOK_ROOT . "/config.php");

// load libraries
require_once (FACEBOOK_ROOT . "/../lib/fb/facebook.php");

// required for date() calls
date_default_timezone_set(FACEBOOK_TIMEZONE);

function dbDateTime($timestamp)
{
    $tz = date_default_timezone_get();
    date_default_timezone_set("UTC");
    $dateTime = date("Y-m-d H:i:s", $timestamp);
    date_default_timezone_set($tz);

    return $dateTime;
}

?>