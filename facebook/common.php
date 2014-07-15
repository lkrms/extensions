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

function getExt($url)
{
    return pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION);
}

function getGraphPage($fb, $pageUrl)
{
    $url   = parse_url(urldecode($pageUrl));
    $path  = explode("/", $url["path"]);

    if ($path[0] == "")
    {
        array_shift($path);
    }

    if (preg_match('/^v[0-9]+\.[0-9]+$/', $path[0]))
    {
        array_shift($path);
    }

    $path    = "/" . implode("/", $path);
    $query   = explode("&", $url["query"]);
    $params  = array();

    foreach ($query as $pair)
    {
        list ($key, $value)  = explode("=", $pair);
        $params[$key]        = $value;
    }

    return $fb->api($path, $params);
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

    if ($db && $rows = mysqli_query($db, "select distinct photo_id, coalesce(src_big, src) as src, concat(gid, '/', photo_id, '.', coalesce(src_big_ext, src_ext)) as local_path from photos where gid = $gid and download_path is null"))
    {
        $dbUpdate = mysqli_prepare($db, "update photos set download_path = ? where gid = ? and photo_id = ?");
        mysqli_stmt_bind_param($dbUpdate, "sis", $_download_path, $_gid, $_photo_id);
        $_gid = $gid + 0;

        while ($row = mysqli_fetch_row($rows))
        {
            $filePath = FACEBOOK_GROUP_ARCHIVE_ROOT . '/' . $row["2"];

            if ((file_exists($filePath) && touch($filePath)) || copy($row["1"], $filePath))
            {
                $_download_path  = $row["2"];
                $_photo_id       = $row["0"];
                mysqli_stmt_execute($dbUpdate);
            }
        }
    }
}

?>