<?php

define('HARVEST_ROOT', dirname(__FILE__));
define('HARVEST_API_ROOT', 'https://api.harvestapp.com');

// composer dependencies
require_once HARVEST_ROOT . '/vendor/autoload.php';

// load configuration options
require_once HARVEST_ROOT . '/config.php';

// register class autoloader
require_once HARVEST_ROOT . '/classes/HarvestApp.php';
spl_autoload_register('HarvestApp::Autoload');

// apply settings, check file access, etc.
HarvestApp::InitApp();
