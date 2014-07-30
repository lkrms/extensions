#!/usr/bin/php
<?php

if (php_sapi_name() != "cli")
{
    exit ("This script is CLI-only.");
}

// pull in our settings
require_once (dirname(__file__) . "/config.php");

if ($argc != 2 || ! isset($MSM4XX_AP[$apName = $argv[1]]))
{
    exit ("Usage: reconfigure.php <AP_NAME>");
}

$ap         = $MSM4XX_AP[$apName];
$ip         = $ap["ip"];
$mac        = strtolower(str_replace(":", "", $ap["mac"]));
$secret     = $ap["secret"];
$configUrl  = MSM4XX_CONFIG_URL . "?mac={$mac}&secret={$secret}";
$pw         = MSM4XX_ADMIN_PASSWORD;

// NB: this will trigger a reboot
echo "Deploying configuration...\n";
system("SSHPASS=$pw sshpass -e ssh -o StrictHostKeyChecking=no -l admin $ip 'enable; config; no config-update automatic; config-update operation restore; config-update uri \"$configUrl\"; config-update start'");

?>