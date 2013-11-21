<?php

// pull in our settings
require_once (dirname(__file__) . "/../config.php");

// insist on valid input
if ( ! isset($_GET["mac"]) || ! preg_match("/^[0-9a-f]{12}$/i", $_GET["mac"]) || ! isset($_GET["secret"]))
{
    throw new Exception("Invalid request.");
}

$mac     = strtolower($_GET["mac"]);
$secret  = $_GET["secret"];

foreach ($MSM4XX_AP as $name => $config)
{
    // hopefully we'll find a match
    if (isset($config["mac"]) && $mac == strtolower(str_replace(":", "", $config["mac"])) && isset($config["secret"]) && $secret == $config["secret"])
    {
        if ( ! isset($config["serial"]) || ! isset($config["channel"]) || ! isset($MSM4XX_FREQ[$config["channel"]]))
        {
            break;
        }

        $substitutions = array(
    "{:NAME:}"          => $name,
    "{:SERIAL:}"        => $config["serial"],
    "{:FREQUENCY:}"     => $MSM4XX_FREQ[$config["channel"]],
    "{:PHY_TYPE:}"      => $config["channel"] <= 13 ? "ieee802.11n-2ghz-bg-compatible" : "ieee802.11n-5ghz-a-compatible",
    "{:CONFIG_TIME:}"   => sprintf("%02d:%02d", rand(2, 3), rand(0, 59)),
    "{:CONFIG_URL:}"    => MSM4XX_CONFIG_URL . "?mac={$mac}&secret={$secret}",
    "{:FIRMWARE_TIME:}" => sprintf("%02d:%02d", rand(0, 1), rand(0, 59)),
    "{:FIRMWARE_URL:}"  => MSM4XX_FIRMWARE_URL . $config["model"] . ".cim",
    "{:CHANNEL_TIME:}"  => sprintf("%02d:%02d", rand(4, 5), rand(0, 59)),
);

        // if this is a 2-radio WAP, add the second channel frequency
        if (isset($config["channel2"]))
        {
            $substitutions["{:FREQUENCY2:}"]  = $MSM4XX_FREQ[$config["channel2"]];
            $substitutions["{:PHY_TYPE2:}"]   = $config["channel2"] <= 13 ? "ieee802.11bg" : "ieee802.11a";
        }

        // load our template file
        $templatePath = dirname(__file__) . "/../templates/$config[model].template.php";

        if ( ! file_exists($templatePath))
        {
            throw new Exception("No matching template.");
        }

        $file = file_get_contents($templatePath);

        // strip out the PHP code at the top
        $file = substr($file, strpos($file, "%version"));

        // substitute variables
        $file = str_replace(array_keys($substitutions), array_values($substitutions), $file);

        // deliver the file
        header("Content-Type: text/plain");
        header("Content-Disposition: attachment; filename=config_$config[serial].cfg");
        header("Content-Length: " . strlen($file));
        header("Expires: 0");
        header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
        header("Pragma: public");
        print ($file);
        ob_flush();
        flush();
        exit;
    }
}

throw new Exception("No matching configuration found.");

?>