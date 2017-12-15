<?php

class HarvestApp
{
    private static $LogPath;

    private static $CurrentLog = '';

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

    public static function RequireCLI()
    {
        if (PHP_SAPI != 'cli')
        {
            throw new Exception('Error: command not issued via command line');
        }
    }

    public static function InitApp()
    {
        // ensure we can write to a log file
        self::$LogPath = HARVEST_ROOT . '/log/app.log';
        self::CheckFileAccess(self::$LogPath);

        // needed before we can use date functions error-free
        date_default_timezone_set(HARVEST_TIMEZONE);
    }

    public static function Log($message)
    {
        $message           = '[' . date('r') . '] ' . $message . PHP_EOL;
        self::$CurrentLog .= $message;
        file_put_contents(self::$LogPath, $message, FILE_APPEND);
    }

    public static function GetCurrentLog()
    {
        return self::$CurrentLog;
    }

    private static function GetDataFilePath($accountId)
    {
        if ($accountId)
        {
            return HARVEST_ROOT . "/data/account-{$accountId}.json";
        }
        else
        {
            return HARVEST_ROOT . '/data/app.json';
        }
    }

    private static function CheckFileAccess($filePath)
    {
        $dir = dirname($filePath);

        if ( ! is_dir($dir))
        {
            if ( ! mkdir($dir, 0700, true))
            {
                throw new Exception("Unable to create directory $dir");
            }
        }

        if ( ! is_writable($dir))
        {
            throw new Exception("Unable to write to directory $dir");
        }

        if (file_exists($filePath) && ! is_writable($filePath))
        {
            throw new Exception("Unable to write to file $filePath");
        }
    }

    public static function LoadDataFile($accountId, & $dataFile)
    {
        $dataFilePath = self::GetDataFilePath($accountId);
        self::CheckFileAccess($dataFilePath);

        if (file_exists($dataFilePath))
        {
            $dataFile = json_decode(file_get_contents($dataFilePath), true);
        }
        else
        {
            $dataFile = array();
        }

        if ($accountId)
        {
            if ( ! isset($dataFile['billedTimes']))
            {
                $dataFile['billedTimes'] = array();
            }
        }
    }

    public static function SaveDataFile($accountId, $dataFile)
    {
        $dataFilePath = self::GetDataFilePath($accountId);
        file_put_contents($dataFilePath, json_encode($dataFile));
    }

    public static function FormatCurrency($value)
    {
        return number_format($value, 2);
    }

    public static function FillTemplate($template, array $values)
    {
        foreach ($values as $var => $val)
        {
            $template = str_replace("[[{$var}]]", $val, $template);
        }

        return $template;
    }

    public static function RaiseInvoices( array $invoiceSettings, $today = null)
    {
        if (is_null($today))
        {
            $today = time();
        }

        $yesterday     = strtotime('-1 day', $today);
        $yesterdayYmd  = date('Y-m-d', $yesterday);

        // keep count of invoices raised today
        $i = 1;

        // so we can provide a dollar figure for our unbilled hours
        $unbilledTotal = 0;

        // and invoices issued today
        $invoicedTotal = 0;

        // and stats about yesterday
        $billableYesterday       = 0;
        $billableHoursYesterday  = 0;

        foreach ($invoiceSettings as $accountName => $invData)
        {
            $account  = HarvestCredentials::FromName($accountName);
            $headers  = $account->GetHeaders();

            // Harvest doesn't allow us to mark time entries as billed via the API, so we have to keep track ourselves
            HarvestApp::LoadDataFile($account->GetAccountId(), $dataFile);

            // 1. retrieve all uninvoiced time entries
            $query = array(
                'is_running' => 'false',
                'is_billed'  => 'false',
                'to'         => $yesterdayYmd,
            );

            $curl   = new Curler(HARVEST_API_ROOT . '/v2/time_entries', $headers);
            $times  = $curl->GetAllHarvest('time_entries', $query);

            // 2. collate them by client
            $clientTimes          = array();
            $clientProjects       = array();
            $clientTotals         = array();
            $clientHours          = array();
            $clientBillableHours  = array();

            foreach ($times as $time)
            {
                $clientId   = $time['client']['id'];
                $projectId  = $time['project']['id'];

                if (in_array($clientId, $invData['excludeClients']) || in_array($time['id'], $dataFile['billedTimes']))
                {
                    continue;
                }

                if ( ! isset($clientTimes[$clientId]))
                {
                    $clientTimes[$clientId]          = array();
                    $clientProjects[$clientId]       = array();
                    $clientTotals[$clientId]         = 0;
                    $clientHours[$clientId]          = 0;
                    $clientBillableHours[$clientId]  = 0;
                }

                $clientTimes[$clientId][] = $time;

                if ( ! in_array($projectId, $clientProjects[$clientId]))
                {
                    $clientProjects[$clientId][] = $projectId;
                }

                $clientTotals[$clientId]        += $time['billable_rate'] ? round($time['hours'] * $time['billable_rate'], 2, PHP_ROUND_HALF_UP) : 0;
                $clientHours[$clientId]         += $time['hours'];
                $clientBillableHours[$clientId] += $time['billable'] ? $time['hours'] : 0;

                if (date('Y-m-d', strtotime($time['spent_date'])) == $yesterdayYmd)
                {
                    $billableYesterday      += $time['billable_rate'] ? round($time['hours'] * $time['billable_rate'], 2, PHP_ROUND_HALF_UP) : 0;
                    $billableHoursYesterday += $time['billable'] ? $time['hours'] : 0;
                }
            }

            // 3. iterate through each client
            foreach ($clientTimes as $clientId => $invoiceTimes)
            {
                $clientName          = $invoiceTimes[0]['client']['name'];
                $total               = $clientTotals[$clientId];
                $totalHours          = $clientHours[$clientId];
                $totalBillableHours  = $clientBillableHours[$clientId];
                $prettyTotal         = HarvestApp::FormatCurrency($total);

                // these settings are overridable per-client
                $showData           = $invData['showData'];
                $invoiceOn          = $invData['invoiceOn'];
                $includeUnbillable  = $invData['includeUnbillable'];
                $invoiceMinimum     = $invData['invoiceMinimum'];
                $daysToPay          = $invData['daysToPay'];
                $sendEmail          = $invData['sendEmail'];

                // by default, generate one invoice that covers all projects for this client
                $invoiceBatches = array(
                    $clientProjects[$clientId],
                );

                if (isset($invData['customClients'][$clientId]['showData']))
                {
                    $showData = $invData['customClients'][$clientId]['showData'];
                }

                if (isset($invData['customClients'][$clientId]['invoiceOn']))
                {
                    $invoiceOn = $invData['customClients'][$clientId]['invoiceOn'];
                }

                if (isset($invData['customClients'][$clientId]['includeUnbillable']))
                {
                    $includeUnbillable = $invData['customClients'][$clientId]['includeUnbillable'];
                }

                if (isset($invData['customClients'][$clientId]['invoiceMinimum']))
                {
                    $invoiceMinimum = $invData['customClients'][$clientId]['invoiceMinimum'];
                }

                if (isset($invData['customClients'][$clientId]['daysToPay']))
                {
                    $daysToPay = $invData['customClients'][$clientId]['daysToPay'];
                }

                if (isset($invData['customClients'][$clientId]['sendEmail']))
                {
                    $sendEmail = $invData['customClients'][$clientId]['sendEmail'];
                }

                if (isset($invData['customClients'][$clientId]['projectContacts']))
                {
                    $invoiceBatches  = array();
                    $lastBatch       = $clientProjects[$clientId];

                    foreach ($invData['customClients'][$clientId]['projectContacts'] as $projectId => $contacts)
                    {
                        if (in_array($projectId, $lastBatch))
                        {
                            $invoiceBatches[] = array(
                                $projectId
                            );

                            // remove this project from our last batch (which catches any left-overs)
                            $k = array_search($projectId, $lastBatch);
                            unset($lastBatch[$k]);
                        }
                    }

                    if ($lastBatch)
                    {
                        $invoiceBatches[] = $lastBatch;
                    }
                }

                // do we invoice this client today?
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
                                HarvestApp::Log("Skipping $prettyTotal ($totalHours hours, $totalBillableHours billable) for $clientName (not due to be invoiced today - wrong dayOfWeek, expecting " . implode(',', $value) . ")");
                                $unbilledTotal += $total;

                                continue 3;
                            }

                            break;

                        case 'dayOfMonth':

                            // negative numbers are counted from the end of the month
                            if ( ! in_array(date('j', $today) + 0, $value) && ! in_array( - (date('t', $today) - date('j', $today) + 1), $value))
                            {
                                HarvestApp::Log("Skipping $prettyTotal ($totalHours hours, $totalBillableHours billable) for $clientName (not due to be invoiced today - wrong dayOfMonth, expecting " . implode(',', $value) . ")");
                                $unbilledTotal += $total;

                                continue 3;
                            }

                            break;

                        case 'weekOfMonth':

                            if ( ! in_array(ceil(date('j', $today) / 7), $value) && ! in_array( - ceil((date('t', $today) - date('j', $today) + 1) / 7), $value))
                            {
                                HarvestApp::Log("Skipping $prettyTotal ($totalHours hours, $totalBillableHours billable) for $clientName (not due to be invoiced today - wrong weekOfMonth, expecting " . implode(',', $value) . ")");
                                $unbilledTotal += $total;

                                continue 3;
                            }

                            break;

                        default:

                            throw new Exception("Unknown 'invoiceOn' entry '$filter'");
                    }
                }

                // yes, we do! build it out
                foreach ($invoiceBatches as $batch)
                {
                    $batchTotal               = 0;
                    $batchTotalHours          = 0;
                    $batchTotalBillableHours  = 0;
                    $lineItems                = array();
                    $markAsBilled             = array();

                    foreach ($invoiceTimes as $t)
                    {
                        // skip this entry if it doesn't relate to a project we're invoicing for
                        if ( ! in_array($t['project']['id'], $batch))
                        {
                            continue;
                        }

                        // keep running totals
                        $batchTotal              += $t['billable_rate'] ? round($t['hours'] * $t['billable_rate'], 2, PHP_ROUND_HALF_UP) : 0;
                        $batchTotalHours         += $t['hours'];
                        $batchTotalBillableHours += $t['billable'] ? $t['hours'] : 0;

                        if ( ! $includeUnbillable && ! $t['billable'])
                        {
                            continue;
                        }

                        // generate pretty line item descriptions
                        $show       = array();
                        $finalShow  = array();

                        if (in_array('date', $showData))
                        {
                            $show[] = date($invData['dateFormat'], strtotime($t['spent_date']));
                        }

                        if (in_array('time', $showData) && $t['started_time'] && $t['ended_time'])
                        {
                            $show[] = "{$t['started_time']} - {$t['ended_time']}";
                        }

                        if ($show)
                        {
                            $finalShow[]  = '[' . implode(' ', $show) . ']';
                            $show         = array();
                        }

                        if (in_array('project', $showData))
                        {
                            $show[] = $t['project']['name'];
                        }

                        if (in_array('task', $showData))
                        {
                            $show[] = $t['task']['name'];
                        }

                        if ($show)
                        {
                            $finalShow[] = implode(' - ', $show);
                        }

                        if (in_array('people', $showData))
                        {
                            $finalShow[] = "({$t['user']['name']})";
                        }

                        $show = '';

                        if ($finalShow)
                        {
                            $show = implode(' ', $finalShow);
                        }

                        if (in_array('notes', $showData) && $t['notes'])
                        {
                            $show .= ($show ? "\n" : '') . $t['notes'];
                        }

                        $sortKey = date('YmdHis', strtotime("{$t['spent_date']} {$t['started_time']}")) . '-' . $t['id'];

                        // attempt to store with a meaningfully sortable key
                        $lineItems[$sortKey] = array(
                            'project_id'  => $t['project']['id'],
                            'kind'        => $invData['itemKind'],
                            'description' => $show,
                            'quantity'    => $t['hours'],
                            'unit_price'  => $t['billable_rate'],
                        );

                        $markAsBilled[] = $t;
                    }

                    // skip if this would be a below-minimum invoice
                    if ($batchTotal < $invoiceMinimum)
                    {
                        HarvestApp::Log("Skipping batch ($batchTotalHours hours, $batchTotalBillableHours billable) for $clientName (minimum invoice value not reached)");

                        continue;
                    }

                    ksort($lineItems);

                    // assemble invoice data for Harvest
                    $data = array(
                        'client_id'  => $clientId,
                        'number'     => 'H-' . date('ymd', $today) . sprintf('%02d', $i),
                        'notes'      => $invData['notes'],
                        'issue_date' => date('Y-m-d'),
                        'due_date'   => date('Y-m-d', time() + ($daysToPay * 24 * 60 * 60)),
                        'line_items' => array_values($lineItems),
                    );

                    // create a new invoice
                    $curl     = new Curler(HARVEST_API_ROOT . '/v2/invoices', $headers);
                    $invoice  = $curl->PostJson($data);

                    // success! prepare the data for substituting into templates
                    $invoiceData = array(
                        'id'          => $invoice['id'],
                        'number'      => $invoice['number'],
                        'amount'      => HarvestApp::FormatCurrency($invoice['amount']) . ' ' . $invoice['currency'],
                        'dueAmount'   => HarvestApp::FormatCurrency($invoice['due_amount']) . ' ' . $invoice['currency'],
                        'issueDate'   => date($invData['dateFormat'], strtotime($invoice['issue_date'])),
                        'dueDate'     => date($invData['dateFormat'], strtotime($invoice['due_date'])),
                        'companyName' => $account->CompanyName,
                        'clientName'  => $clientName,
                    );

                    $invoicedTotal += $invoice['amount'];
                    HarvestApp::Log("Invoice {$invoiceData['number']} created for $clientName with id {$invoiceData['id']} ({$invoiceData['amount']} - $batchTotalHours hours, $batchTotalBillableHours billable)");

                    foreach ($markAsBilled as $t)
                    {
                        $dataFile['billedTimes'][] = $t['id'];
                    }

                    // save after every invoice
                    HarvestApp::SaveDataFile($account->GetAccountId(), $dataFile);

                    if ($sendEmail)
                    {
                        $curl      = new Curler(HARVEST_API_ROOT . '/v2/contacts', $headers);
                        $contacts  = $curl->GetAllHarvest('contacts', array(
                            'client_id' => $clientId
                        ));
                        $allowedContacts = null;

                        if (count($batch) == 1 && isset($invData['customClients'][$clientId]['projectContacts'][$batch[0]]))
                        {
                            $allowedContacts = $invData['customClients'][$clientId]['projectContacts'][$batch[0]];

                            if ( ! is_array($allowedContacts))
                            {
                                $allowedContacts = array(
                                    $allowedContacts
                                );
                            }
                        }

                        $recipients = array();

                        foreach ($contacts as $contact)
                        {
                            if ($contact['email'] && (is_null($allowedContacts) || in_array($contact['id'], $allowedContacts)))
                            {
                                $recipients[] = array(
                                    'name'  => trim("{$contact['first_name']} {$contact['last_name']}"),
                                    'email' => $contact['email']
                                );
                            }
                        }

                        if ($recipients)
                        {
                            // send invoice to client
                            $emailSubject  = HarvestApp::FillTemplate($invData['emailSubject'], $invoiceData);
                            $emailBody     = HarvestApp::FillTemplate($invData['emailBody'], $invoiceData);
                            $messageData   = array(
                                'recipients' => $recipients,
                                'subject'    => $emailSubject,
                                'body'       => $emailBody,
                                'include_link_to_client_invoice' => true,
                                'attach_pdf'                     => true,
                                'send_me_a_copy'                 => true,
                            );

                            $curl    = new Curler(HARVEST_API_ROOT . "/v2/invoices/{$invoiceData['id']}/messages", $headers);
                            $result  = $curl->PostJson($messageData);
                            HarvestApp::Log("Emailed invoice {$invoiceData['number']} to $clientName with message id {$result['id']}");
                        }
                        else
                        {
                            HarvestApp::Log("Unable to email invoice {$invoiceData['number']} to $clientName (no suitable contacts)");
                        }
                    }

                    $i++;
                }
            }
        }

        if ($invoicedTotal)
        {
            HarvestApp::Log('Total amount invoiced: ' . self::FormatCurrency($invoicedTotal));
        }

        HarvestApp::Log('Billable amount yesterday: ' . self::FormatCurrency($billableYesterday) . " ($billableHoursYesterday hours)");

        if ($billableHoursYesterday)
        {
            HarvestApp::Log('Average hourly rate yesterday: ' . self::FormatCurrency($billableYesterday / $billableHoursYesterday));
        }

        if ($unbilledTotal)
        {
            HarvestApp::Log('Total amount not yet billed: ' . self::FormatCurrency($unbilledTotal));
        }

        if (defined('HARVEST_REPORT_EMAIL'))
        {
            mail(HARVEST_REPORT_EMAIL, 'Harvest invoicing report for ' . date('j M', $today), self::GetCurrentLog(), 'From: ' . HARVEST_REPORT_FROM_EMAIL);
        }
    }
}

