<?php

define("MSM4XX_CONFIG_URL", "http://mydomain.local/msm4xx/get_config.php");
define("MSM4XX_FIRMWARE_URL", "http://mydomain.local/msm4xx/firmware/");

// required: model, serial, mac, ip, secret, channel (available channels below)
// required for dual-antenna models: channel2
$MSM4XX_AP = array(
    "WAP1"        => array(
        "model"   => "msm410",
        "serial"  => "SGxxxxxxxx",
        "mac"     => "00:01:02:03:04:05",
        "ip"      => "192.168.0.100",
        "secret"  => "unique_secret_for_this_ap",
        "channel" => 6
    ),
    "WAP2"         => array(
        "model"    => "msm422",
        "serial"   => "SGyyyyyyyy",
        "mac"      => "00:01:02:03:04:06",
        "ip"       => "192.168.0.101",
        "secret"   => "unique_secret_for_this_ap",
        "channel"  => 40,
        "channel2" => 11
    ),
);

$MSM4XX_FREQ = array(
    1   => "2.412GHz",
    6   => "2.437GHz",
    11  => "2.462GHz",
    36  => "5.180GHz",
    40  => "5.200GHz",
    44  => "5.220GHz",
    48  => "5.240GHz",
    52  => "5.260GHz",
    56  => "5.280GHz",
    60  => "5.300GHz",
    64  => "5.320GHz",
    100 => "5.500GHz",
    104 => "5.520GHz",
    108 => "5.540GHz",
    112 => "5.560GHz",
    132 => "5.660GHz",
    136 => "5.680GHz",
    149 => "5.745GHz",
    153 => "5.765GHz",
    157 => "5.785GHz",
    161 => "5.805GHz",
);

?>