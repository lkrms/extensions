#!/usr/bin/env php
<?php

// sync.php - designed to run every 5 minutes or so
//
//
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
    $sourceAccount  = HarvestCredentials::FromName($syncData['sourceName']);
    $targetAccount  = HarvestCredentials::FromName($syncData['targetName']);
    $sourceHeaders  = $sourceAccount->GetHeaders();
    $targetHeaders  = $targetAccount->GetHeaders();

    // Harvest doesn't allow us to mark time entries as billed via the API, so we have to keep track ourselves
    HarvestApp::LoadDataFile($targetAccount->GetAccountId(), $dataFile);

    // 1. retrieve tasks associated with project in target
    $query = array(
        'is_active' => 'true',
    );

    $curl          = new Curler(HARVEST_API_ROOT . "/v2/projects/{$syncData['targetProjectId']}/task_assignments", $targetHeaders);
    $targetTasks   = $curl->GetAllLinkedByEntity('task_assignments', $query);
    $targetTaskId  = null;

    foreach ($targetTasks as $targetTask)
    {
        if (strcasecmp(trim($targetTask['task']['name']), trim($syncData['targetTaskName'])) == 0)
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
    else
    {
        $query['user_id'] = $targetAccount->UserId;
        HarvestApp::Log("No target user ID provided, using {$targetAccount->UserId} ({$targetAccount->FullName} <{$targetAccount->Email}>)");
    }

    $curl         = new Curler(HARVEST_API_ROOT . '/v2/time_entries', $targetHeaders);
    $targetTimes  = $curl->GetAllLinkedByEntity('time_entries', $query);

    // 3. build a look-up table for comparison
    $lookupTimes  = array();
    $newFromDate  = $fromDate;

    // important: Harvest returns data sorted in reverse chronological order
    foreach ($targetTimes as $targetTime)
    {
        // as soon as we hit a locked entry, we know we've already invoiced to this date
        if ($targetTime['is_locked'] || in_array($targetTime['id'], $dataFile['billedTimes']))
        {
            $newFromDate = date('Y-m-d', strtotime($targetTime['spent_date'] . ' +1 day'));

            // provide feedback
            HarvestApp::Log("Found a locked time entry in the target project on {$targetTime['spent_date']}, searching in source from $newFromDate");

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
        $query['user_id'] = $sourceAccount->UserId;
        HarvestApp::Log("No source user ID provided, using {$sourceAccount->UserId} ({$sourceAccount->FullName} <{$sourceAccount->Email}>)");
    }

    if (isset($syncData['sourceProjectId']) && ! is_null($syncData['sourceProjectId']))
    {
        $query['project_id'] = $syncData['sourceProjectId'];
    }

    $curl         = new Curler(HARVEST_API_ROOT . '/v2/time_entries', $sourceHeaders);
    $sourceTimes  = $curl->GetAllLinkedByEntity('time_entries', $query);

    // 5. compare results and build table of entries to add to target
    $newTimes = array();

    foreach ($sourceTimes as $sourceTime)
    {
        $sourceHash = GetTimeEntryHash($sourceTime, false);

        if (isset($lookupTimes[$sourceHash]))
        {
            // this entry is in sync -- no action required
            unset($lookupTimes[$sourceHash]);
            HarvestApp::Log("$sourceHash: in sync");

            continue;
        }

        $newTimes[$sourceHash] = $sourceTime;
    }

    // 6. delete unmatched target entries
    foreach ($lookupTimes as $hash => $targetTime)
    {
        $curl    = new Curler(HARVEST_API_ROOT . "/v2/time_entries/{$targetTime['id']}", $targetHeaders);
        $result  = $curl->Delete();
        HarvestApp::Log("$hash: deleted");
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
        HarvestApp::Log("$hash: added to target with id {$result['id']}");
    }
}

