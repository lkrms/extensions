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
    exit ("Usage: commission.php <AP_NAME>");
}

$ap           = $MSM4XX_AP[$apName];
$ip           = $ap["ip"];
$mac          = strtolower(str_replace(":", "", $ap["mac"]));
$secret       = $ap["secret"];
$configUrl    = MSM4XX_CONFIG_URL . "?mac={$mac}&secret={$secret}";
$firmwareUrl  = MSM4XX_FIRMWARE_URL . $ap["model"] . ".cim";
$pw = MSM4XX_ADMIN_PASSWORD;

// let's assume the AP was factory reset 0.2 seconds ago ;)
echo "Waiting 120 seconds (in case the AP hasn't booted yet)...";
sleep(120);

// factory-reset APs boot in managed mode
echo "Switching operational mode (to autonomous)...";
system("SSHPASS=admin sshpass -e ssh -o StrictHostKeyChecking=no -l admin $ip 'enable; switch operational mode'");

// switching to autonomous mode can take more than 2 minutes
echo "Waiting 180 seconds (for the AP to boot in autonomous mode)...";
sleep(180);

// TODO: add a firmware version check (currently upgrades regardless)
echo "Setting admin password and upgrading firmware...";
system("SSHPASS=admin sshpass -e ssh -o StrictHostKeyChecking=no -l admin $ip 'enable; config; username admin $pw; no firmware-update automatic; firmware-update uri \"$firmwareUrl\"; firmware-update start'");

// it takes less than two minutes to install firmware and reboot
echo "Waiting 120 seconds (for the AP to boot with new firmware)...";
sleep(120);

// finally, configure the AP
echo "Deploying configuration...";
system("SSHPASS=$pw sshpass -e ssh -o StrictHostKeyChecking=no -l admin $ip 'enable; config; no config-update automatic; config-update operation restore; config-update uri \"$configUrl\"; config-update start'");

?>