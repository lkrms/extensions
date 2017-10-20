<?php

// one or more source/target sets
$HARVEST_SYNC_RELATIONSHIPS = array(
    array(

        // source
        'sourceAccountId' => 999999,
        'sourceProjectId' => null,    // may be null
        'sourceUserId'    => 8888888,    // may be null
        'sourceToken'     => '== paste token here ==',

        // target
        'targetAccountId' => 999999,
        'targetProjectId' => 55555555,
        'targetTaskName'  => 'General Consulting',    // must be active and assigned to the project
        'targetUserId'    => null,    // may be null (defaults to user authenticated by token)
        'targetToken'     => '== paste token here ==',
    )
);

?>