<?php

if ( ! defined("FACEBOOK_ROOT"))
{
    define("FACEBOOK_ROOT", dirname(__file__));
}

define("FACEBOOK_GROUP_ARCHIVE_ROOT", FACEBOOK_ROOT . "/.groupArchive");

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

function getExt($url)
{
    return pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION);
}

function downloadPhotos($gid)
{
    if ( ! file_exists(FACEBOOK_GROUP_ARCHIVE_ROOT . '/' . $gid))
    {
        if ( ! mkdir(FACEBOOK_GROUP_ARCHIVE_ROOT . '/' . $gid, 0777, true))
        {
            error_log("Unable to create download directory: " . FACEBOOK_GROUP_ARCHIVE_ROOT . '/' . $gid);

            return;
        }
    }

    $db = mysqli_connect(FACEBOOK_DB_SERVER, FACEBOOK_DB_USERNAME, FACEBOOK_DB_PASSWORD, FACEBOOK_DB_NAME);

    if ($db && $rows = mysqli_query($db, "select photo_id, attached_to, attached_type, coalesce(src_big, src) as src, concat(gid, '/', photo_id, '_', attached_to, '_', attached_type, '.', coalesce(src_big_ext, src_ext)) as local_path from photos where gid = $gid and download_path is null"))
    {
        $dbUpdate = mysqli_prepare($db, "update photos set download_path = ? where gid = ? and photo_id = ? and attached_to = ? and attached_type = ?");
        mysqli_stmt_bind_param($dbUpdate, "sisss", $_download_path, $_gid, $_photo_id, $_attached_to, $_attached_type);
        $_gid = $gid + 0;

        while ($row = mysqli_fetch_row($rows))
        {
            if (file_exists(FACEBOOK_GROUP_ARCHIVE_ROOT . '/' . $row["4"]) || copy($row["3"], FACEBOOK_GROUP_ARCHIVE_ROOT . '/' . $row["4"]))
            {
                $_download_path  = $row["4"];
                $_photo_id       = $row["0"];
                $_attached_to    = $row["1"];
                $_attached_type  = $row["2"];
                mysqli_stmt_execute($dbUpdate);
            }
        }
    }
}

?>