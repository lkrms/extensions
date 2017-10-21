<?php

// "Personal Access Tokens" from https://id.getharvest.com/developers (indexed by name)
$HARVEST_ACCOUNTS = array(
    'harvest1' => array(
        'accountId' => 999999,
        'token'     => '== paste token here ==',
    ),
    'harvest2' => array(
        'accountId' => 999001,
        'token'     => '== paste token here ==',
    ),
);

// indexed by name (must match an index in $HARVEST_ACCOUNTS)
$HARVEST_INVOICES = array(
    'harvest1' => array(
        'excludeClients' => array(          // add numeric IDs here to completely skip particular clients
        ),
        'customClients' => array(           // index by client ID, elements should be arrays with 'showData', 'invoiceOn', 'includeUnbillable', and/or 'daysToPay'
        ),
        'showData' => array(
            'project',
            'task',
            'people',
            'date',
            'time',
            'notes',
        ),
        'invoiceOn' => array(               // all non-null entries must match for invoice to be raised
            'dayOfWeek'   => 5,             // 0-6, Sunday-Saturday
            'dayOfMonth'  => null,          // negative numbers are measured from end of month
            'weekOfMonth' => null,          // e.g. for last Friday of the month, use -1 here and 5 for dayOfWeek
        ),
        'includeUnbillable' => true,
        'daysToPay'         => 7,
        'dateFormat'        => 'd/m/Y',
        'itemKind'          => 'Service',    // must match the name of a service item type under Invoices > Configure > Item Types in Harvest
        'notes'             => "We accept payment by direct deposit, cheque, VISA, or MasterCard.\n\nIf paying by direct deposit or cheque, please provide your invoice number with your payment.",
    ),
);

// one or more source/target sets
$HARVEST_SYNC_RELATIONSHIPS = array(
    array(

        // source
        'sourceName'      => 'harvest1',    // must match an index in $HARVEST_ACCOUNTS
        'sourceProjectId' => null,    // if null, will query all projects
        'sourceUserId'    => null,    // if null, will look up user authenticated by token

        // target
        'targetName'      => 'harvest2',
        'targetProjectId' => 55555555,
        'targetTaskName'  => 'General Consulting',    // must be active and assigned to the project
        'targetUserId'    => null,    // if null, will look up user authenticated by token
    )
);

define('HARVEST_TIMEZONE', 'Australia/Sydney');

?>