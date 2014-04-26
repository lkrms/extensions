<?php

define("FACEBOOK_ROOT", dirname(__file__) . "/..");
require_once (FACEBOOK_ROOT . "/common.php");

if (isset($_GET["gid"]))
{
    $gid = $_GET["gid"];

    if ($gid + 0 == $gid && is_int($gid + 0))
    {
        downloadPhotos($gid);
    }
}

?>