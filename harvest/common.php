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
