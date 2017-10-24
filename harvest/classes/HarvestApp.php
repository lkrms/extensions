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

        // keep count of invoices raised today
        $i = 1;

        // so we can provide a dollar figure for our unbilled hours
        $unbilledTotal = 0;

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
                'to'         => date('Y-m-d', strtotime('-1 day', $today)),
            );

            $curl   = new Curler(HARVEST_API_ROOT . '/v2/time_entries', $headers);
            $times  = $curl->GetAllHarvest('time_entries', $query);

            // 2. collate them by client
            $clientTimes   = array();
            $clientTotals  = array();

            foreach ($times as $time)
            {
                $clientId = $time['client']['id'];

                if (in_array($clientId, $invData['excludeClients']) || in_array($time['id'], $dataFile['billedTimes']))
                {
                    continue;
                }

                if ( ! isset($clientTimes[$clientId]))
                {
                    $clientTimes[$clientId]   = array();
                    $clientTotals[$clientId]  = 0;
                }

                $clientTimes[$clientId][]  = $time;
                $clientTotals[$clientId]  += $time['billable_rate'] ? round($time['hours'] * $time['billable_rate'], 2, PHP_ROUND_HALF_UP) : 0;
            }

            // 3. iterate through each client
            foreach ($clientTimes as $clientId => $invoiceTimes)
            {
                $clientName = $invoiceTimes[0]['client']['name'];

                // skip if invoice total would be $0 (no billable time)
                if ( ! $clientTotals[$clientId])
                {
                    continue;
                }

                $total        = $clientTotals[$clientId];
                $prettyTotal  = HarvestApp::FormatCurrency($total);

                // these settings are overridable per-client
                $showData           = $invData['showData'];
                $invoiceOn          = $invData['invoiceOn'];
                $includeUnbillable  = $invData['includeUnbillable'];
                $daysToPay          = $invData['daysToPay'];
                $sendEmail          = $invData['sendEmail'];

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

                if (isset($invData['customClients'][$clientId]['daysToPay']))
                {
                    $daysToPay = $invData['customClients'][$clientId]['daysToPay'];
                }

                if (isset($invData['customClients'][$clientId]['sendEmail']))
                {
                    $sendEmail = $invData['customClients'][$clientId]['sendEmail'];
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
                                HarvestApp::Log("Skipping $prettyTotal for $clientName (not due to be invoiced today - wrong dayOfWeek, expecting " . implode(',', $value) . ")");
                                $unbilledTotal += $total;

                                continue 3;
                            }

                            break;

                        case 'dayOfMonth':

                            // negative numbers are counted from the end of the month
                            if ( ! in_array(date('j', $today) + 0, $value) && ! in_array( - (date('t', $today) - date('j', $today) + 1), $value))
                            {
                                HarvestApp::Log("Skipping $prettyTotal for $clientName (not due to be invoiced today - wrong dayOfMonth, expecting " . implode(',', $value) . ")");
                                $unbilledTotal += $total;

                                continue 3;
                            }

                            break;

                        case 'weekOfMonth':

                            if ( ! in_array(ceil(date('j', $today) / 7), $value) && ! in_array( - ceil((date('t', $today) - date('j', $today) + 1) / 7), $value))
                            {
                                HarvestApp::Log("Skipping $prettyTotal for $clientName (not due to be invoiced today - wrong weekOfMonth, expecting " . implode(',', $value) . ")");
                                $unbilledTotal += $total;

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

                HarvestApp::Log("Invoice {$invoiceData['number']} created for $clientName with id {$invoiceData['id']} ({$invoiceData['amount']})");

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
                    $recipients = array();

                    foreach ($contacts as $contact)
                    {
                        if ($contact['email'])
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

