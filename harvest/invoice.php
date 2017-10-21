<?php

// invoice.php - designed to run once daily
//
//
require_once (dirname(__FILE__) . '/common.php');

// keep count of invoices raised today
$i = 1;

foreach ($HARVEST_INVOICES as $accountName => $invData)
{
    $account  = HarvestCredentials::FromName($accountName);
    $headers  = $account->GetHeaders();

    // Harvest doesn't allow us to mark time entries as billed via the API, so we have to keep track ourselves
    load_data_file($account->GetAccountId());

    // 1. retrieve all uninvoiced time entries
    $query = array(
        'is_running' => 'false',
        'is_billed'  => 'false',
    );

    $curl   = new Curler(HARVEST_API_ROOT . '/v2/time_entries', $headers);
    $times  = $curl->GetAllHarvest('time_entries', $query);

    // 2. collate them by client
    $clientTimes = array();

    foreach ($times as $time)
    {
        $clientId = $time['client']['id'];

        if (in_array($clientId, $invData['excludeClients']) || in_array($time['id'], $dataFile['billedTimes']))
        {
            continue;
        }

        if ( ! isset($clientTimes[$clientId]))
        {
            $clientTimes[$clientId] = array();
        }

        $clientTimes[$clientId][] = $time;
    }

    // 3. iterate through each client
    foreach ($clientTimes as $clientId => $invoiceTimes)
    {
        $clientName = $invoiceTimes[0]['client']['name'];

        // these settings are overridable per-client
        $includeUnbillable  = $invData['includeUnbillable'];
        $invoiceOn          = $invData['invoiceOn'];
        $daysToPay          = $invData['daysToPay'];

        if (isset($invData['customClients'][$clientId]['includeUnbillable']))
        {
            $includeUnbillable = $invData['customClients'][$clientId]['includeUnbillable'];
        }

        if (isset($invData['customClients'][$clientId]['invoiceOn']))
        {
            $invoiceOn = $invData['customClients'][$clientId]['invoiceOn'];
        }

        if (isset($invData['customClients'][$clientId]['daysToPay']))
        {
            $daysToPay = $invData['customClients'][$clientId]['daysToPay'];
        }

        // do we invoice this client today?
        $today = time();

        foreach ($invoiceOn as $filter => $value)
        {
            if (is_null($value))
            {
                continue;
            }

            if ( ! is_array($value))
            {
                $value = array(
                    $value
                );
            }

            switch ($filter)
            {
                case 'dayOfWeek':

                    if ( ! in_array(date('w', $today) + 0, $value))
                    {
                        echo "Skipping $clientName (not due to be invoiced today - wrong dayOfWeek)\n";

                        continue 3;
                    }

                    break;

                case 'dayOfMonth':

                    // negative numbers are counted from the end of the month
                    if ( ! in_array(date('j', $today) + 0, $value) && ! in_array( - (date('t', $today) - date('j', $today) + 1), $value))
                    {
                        echo "Skipping $clientName (not due to be invoiced today - wrong dayOfMonth)\n";

                        continue 3;
                    }

                    break;

                case 'weekOfMonth':

                    if ( ! in_array(ceil(date('j', $today) / 7), $value) && ! in_array( - ceil((date('t', $today) - date('j', $today) + 1) / 7), $value))
                    {
                        echo "Skipping $clientName (not due to be invoiced today - wrong weekOfMonth)\n";

                        continue 3;
                    }

                    break;

                default:

                    throw new Exception("Unknown 'invoiceOn' entry '$filter'");
            }
        }

        // yes, we do! build it out
        $lineItems     = array();
        $markAsBilled  = array();

        foreach ($invoiceTimes as $t)
        {
            if ( ! $includeUnbillable && ! $t['billable'])
            {
                continue;
            }

            $lineItems[] = array(
                'project_id'  => $t['project']['id'],
                'kind'        => $invData['itemKind'],
                'description' => $t['notes'],
                'quantity'    => $t['hours'],
                'unit_price'  => $t['billable_rate'],
            );

            $markAsBilled[] = $t;
        }

        $data = array(
            'client_id'  => $clientId,
            'number'     => 'H-' . date('ymd', $today) . sprintf('%02d', $i),
            'notes'      => $invData['notes'],
            'issue_date' => date('Y-m-d', $today),
            'due_date'   => date('Y-m-d', $today + ($daysToPay * 24 * 60 * 60)),
            'line_items' => $lineItems,
        );

        // create a new invoice
        $curl    = new Curler(HARVEST_API_ROOT . '/v2/invoices', $headers);
        $result  = $curl->PostJson($data);
        echo "Invoice {$data['number']} created for $clientName with id {$result['id']}\n";

        foreach ($markAsBilled as $t)
        {
            $dataFile['billedTimes'][] = $t['id'];
        }

        // save after every invoice
        save_data_file($account->GetAccountId());
        $i++;
    }
}

?>