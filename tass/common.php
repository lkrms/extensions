<?php

if ( ! defined("TASS_ROOT"))
{
    define("TASS_ROOT", dirname(__file__));
}

// for PEAR
ini_set("include_path", TASS_ROOT . "/../lib/php");

// load settings
require_once (TASS_ROOT . "/config.php");

// load libraries
require_once (TASS_ROOT . "/../lib/fpdf/fpdf.php");
require_once (TASS_ROOT . "/../lib/php/Mail.php");
require_once (TASS_ROOT . "/../lib/php/Mail/mime.php");

// required for date() calls
date_default_timezone_set(TASS_TIMEZONE);

?>