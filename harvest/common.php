<?php

define('HARVEST_ROOT', dirname(__FILE__));
define('HARVEST_API_ROOT', 'https://api.harvestapp.com');

// load configuration options
require_once (HARVEST_ROOT . '/config.php');

// register class autoloader
require_once (HARVEST_ROOT . '/classes/HarvestApp.php');
spl_autoload_register('HarvestApp::Autoload');

// needed before we can use date functions error-free
date_default_timezone_set(HARVEST_TIMEZONE);

function check_data_file_access($filePath)
{
    $dir = dirname($filePath);

    if ( ! is_dir($dir))
    {
        if ( ! mkdir($dir, 0700, true))
        {
            throw new Exception("Unable to create directory $dir");
        }
    }

    if ( ! is_writable($dir))
    {
        throw new Exception("Unable to write to directory $dir");
    }

    if (file_exists($filePath) && ! is_writable($filePath))
    {
        throw new Exception("Unable to write to file $filePath");
    }
}

function get_data_file_path($accountId)
{
    return dirname(__FILE__) . "/data/{$accountId}.json";
}

function load_data_file($accountId)
{
    global $dataFile;
    $dataFilePath = get_data_file_path($accountId);
    check_data_file_access($dataFilePath);

    if (file_exists($dataFilePath))
    {
        $dataFile = json_decode(file_get_contents($dataFilePath), true);
    }
    else
    {
        $dataFile = array('billedTimes' => array());
    }
}

function save_data_file($accountId)
{
    global $dataFile;
    $dataFilePath = get_data_file_path($accountId);
    file_put_contents($dataFilePath, json_encode($dataFile));
}

// PRETTY_NESTED_ARRAYS,0

?>