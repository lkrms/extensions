<?php

require_once (dirname(__FILE__) . '/common.php');

function GetTimeEntryHash($timeEntry, $fromTarget = true)
{
    $includedKeys = array(
        'spent_date',
        'hours',
        'started_time',
        'ended_time',
    );

    $toHash = '';

    foreach ($includedKeys as $key)
    {
        $toHash .= $timeEntry[$key] . '|';
    }

    $notes = $timeEntry['notes'];

    if ($fromTarget)
    {
        $notesLines = explode("\n", $notes);
        array_shift($notesLines);
        $notes = implode("\n", $notesLines);
    }

    $toHash .= $notes;

    return md5($toHash);
}

// only inspect the last two weeks' time entries
$fromDate = date('Y-m-d', strtotime('-2 weeks'));

foreach ($HARVEST_SYNC_RELATIONSHIPS as $syncData)
{
    $sourceAccount  = $HARVEST_ACCOUNTS[$syncData['sourceName']];
    $targetAccount  = $HARVEST_ACCOUNTS[$syncData['targetName']];
    $sourceHeaders  = CurlerHeader::GetHarvestApiHeaders($sourceAccount['accountId'], $sourceAccount['token']);
    $targetHeaders  = CurlerHeader::GetHarvestApiHeaders($targetAccount['accountId'], $targetAccount['token']);

    // 1. retrieve tasks associated with project in target
    $query = array(
        'is_active' => 'true',
    );

    $curl          = new Curler(HARVEST_API_ROOT . "/v2/projects/{$syncData['targetProjectId']}/task_assignments", $targetHeaders);
    $targetTasks   = $curl->GetAllHarvest('task_assignments', $query);
    $targetTaskId  = null;

    foreach ($targetTasks as $targetTask)
    {
        if ($targetTask['task']['name'] == $syncData['targetTaskName'])
        {
            $targetTaskId = $targetTask['task']['id'];

            break;
        }
    }

    if ( ! $targetTaskId)
    {
        throw new Exception("No active task assigned to project {$syncData['targetProjectId']} with name '{$syncData['targetTaskName']}'");
    }

    // 2. fetch all relevant time entries from the target
    $query = array(
        'project_id' => $syncData['targetProjectId'],
        'is_running' => 'false',
        'from'       => $fromDate,
    );

    if (isset($syncData['targetUserId']) && ! is_null($syncData['targetUserId']))
    {
        $query['user_id'] = $syncData['targetUserId'];
    }

    $curl         = new Curler(HARVEST_API_ROOT . '/v2/time_entries', $targetHeaders);
    $targetTimes  = $curl->GetAllHarvest('time_entries', $query);

    // 3. build a look-up table for comparison
    $lookupTimes  = array();
    $newFromDate  = $fromDate;

    foreach ($targetTimes as $targetTime)
    {
        // as soon as we hit a locked entry, we know we've already invoiced to this date
        if ($targetTime['is_locked'])
        {
            $newFromDate = date('Y-m-d', strtotime($targetTime['spent_date'] . ' +1 day'));

            // provide feedback
            echo "Found a locked time entry in the target project on {$targetTime['spent_date']}, searching in source from $newFromDate\n";

            break;
        }

        $lookupTimes[GetTimeEntryHash($targetTime)] = $targetTime;
    }

    // release some memory
    $targetTimes = null;

    // 4. fetch all relevant time entries from the source
    $query = array(
        'is_running' => 'false',
        'from'       => $newFromDate,
    );

    if (isset($syncData['sourceUserId']) && ! is_null($syncData['sourceUserId']))
    {
        $query['user_id'] = $syncData['sourceUserId'];
    }
    else
    {
        $curl              = new Curler(HARVEST_API_ROOT . '/v2/users/me', $sourceHeaders);
        $me                = $curl->GetJson();
        $query['user_id']  = $me['id'];
        echo "No source user ID provided, using {$me['id']} ({$me['first_name']} {$me['last_name']} <{$me['email']}>)\n";
    }

    if (isset($syncData['sourceProjectId']) && ! is_null($syncData['sourceProjectId']))
    {
        $query['project_id'] = $syncData['sourceProjectId'];
    }

    $curl         = new Curler(HARVEST_API_ROOT . '/v2/time_entries', $sourceHeaders);
    $sourceTimes  = $curl->GetAllHarvest('time_entries', $query);

    // 5. compare results and build table of entries to add to target
    $newTimes = array();

    foreach ($sourceTimes as $sourceTime)
    {
        $sourceHash = GetTimeEntryHash($sourceTime, false);

        if (isset($lookupTimes[$sourceHash]))
        {
            // this entry is in sync -- no action required
            unset($lookupTimes[$sourceHash]);
            echo "$sourceHash: in sync\n";

            continue;
        }

        $newTimes[$sourceHash] = $sourceTime;
    }

    // 6. delete unmatched target entries
    foreach ($lookupTimes as $hash => $targetTime)
    {
        $curl    = new Curler(HARVEST_API_ROOT . "/v2/time_entries/{$targetTime['id']}", $targetHeaders);
        $result  = $curl->Delete();
        echo "$hash: deleted\n";
    }

    // 7. create new target entries
    foreach ($newTimes as $hash => $sourceTime)
    {
        // create time entry in target
        $data = array(
            'project_id'       => $syncData['targetProjectId'],
            'task_id'          => $targetTaskId,
            'spent_date'       => $sourceTime['spent_date'],
            'timer_started_at' => $sourceTime['timer_started_at'],
            'hours'            => $sourceTime['hours'],
            'started_time'     => $sourceTime['started_time'],
            'ended_time'       => $sourceTime['ended_time'],
            'notes'            => '## ' . $sourceTime['client']['name'] . ' - ' . $sourceTime['project']['name'] . ' - ' . $sourceTime['task']['name'] . " ##\n" . $sourceTime['notes'],
        );

        if (isset($syncData['targetUserId']) && ! is_null($syncData['targetUserId']))
        {
            $data['user_id'] = $syncData['targetUserId'];
        }

        $curl    = new Curler(HARVEST_API_ROOT . '/v2/time_entries', $targetHeaders);
        $result  = $curl->PostJson($data);
        echo "$hash: added to target with id {$result['id']}\n";
    }
}

?>