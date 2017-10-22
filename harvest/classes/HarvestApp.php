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
}

