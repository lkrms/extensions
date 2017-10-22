<?php

class HarvestApp
{
    public static function Autoload($className)
    {
        // don't attempt to autoload namespaced classes
        if (strpos($className, '\\') === false)
        {
            $path = HARVEST_ROOT . '/classes/' . $className . '.php';

            if (file_exists($path))
            {
                require_once ($path);
            }
        }
    }

    private static function GetDataFilePath($accountId)
    {
        if ($accountId)
        {
            return HARVEST_ROOT . "/data/account-{$accountId}.json";
        }
        else
        {
            return HARVEST_ROOT . '/data/app.json';
        }
    }

    private static function CheckFileAccess($filePath)
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

    public static function LoadDataFile($accountId, & $dataFile)
    {
        $dataFilePath = self::GetDataFilePath($accountId);
        self::CheckFileAccess($dataFilePath);

        if (file_exists($dataFilePath))
        {
            $dataFile = json_decode(file_get_contents($dataFilePath), true);
        }
        else
        {
            $dataFile = array();
        }

        if ($accountId)
        {
            if ( ! isset($dataFile['billedTimes']))
            {
                $dataFile['billedTimes'] = array();
            }
        }
    }

    public static function SaveDataFile($accountId, $dataFile)
    {
        $dataFilePath = self::GetDataFilePath($accountId);
        file_put_contents($dataFilePath, json_encode($dataFile));
    }
}

