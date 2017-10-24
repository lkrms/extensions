<?php

require_once (dirname(__FILE__) . '/common.php');

// don't continue unless called via CLI
HarvestApp::RequireCLI();

// the first parameter, if specified, is the date to treat as "today" for all purposes except invoice issued date (and due date)
$today = time();

if ($argc > 1)
{
    $today = strtotime($argv[1]);

    if ($today === false || $today == -1)
    {
        throw new Exception("Error: unable to parse '$argv[1]' into a timestamp");
    }
}

HarvestApp::RaiseInvoices($HARVEST_INVOICES, $today);

?>