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

// one or more source/target sets
$HARVEST_SYNC_RELATIONSHIPS = array(
    array(

        // source
        'sourceName'      => 'harvest1',    // must match an index in $HARVEST_ACCOUNTS
        'sourceProjectId' => null,          // if null, will query all projects
        'sourceUserId'    => null,          // if null, will look up user authenticated by token

        // target
        'targetName'      => 'harvest2',
        'targetProjectId' => 55555555,
        'targetTaskName'  => 'General Consulting',  // must be active and assigned to the project
        'targetUserId'    => null,                  // if null, will look up user authenticated by token
    )
);

?>