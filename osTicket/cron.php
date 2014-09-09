<?php

define("OST_ROOT", dirname(__file__));
require_once (OST_ROOT . "/common.php");

if ( ! OST_CLI)
{
    exit ("This script is CLI-only.");
}

$db = new mysqli(DBHOST, DBUSER, DBPASS, DBNAME);

// this syntax is compatible with buggy versions of PHP
if (mysqli_connect_error())
{
    exit ("Unable to connect to osTicket database: " . mysqli_connect_error());
}

if (is_array($OST_CRON_QUERIES))
{
    foreach ($OST_CRON_QUERIES as $query)
    {
        $db->query($query);
    }
}

$db->close();

?>