<?php

use Lkrms\Curler;

class HarvestApp
{
    private static $LogPath;

    private static $CurrentLog = '';

    private static $ExistingInvoices;

    /**
     * @var string
     */
    private static $DefaultAccount;

    /**
     * @var HarvestCredentials
     */
    private static $DefaultAccountCredentials;

    /**
     * @var array
     */
    private static $DefaultCompany;

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

            if ( ! isset($dataFile['billedExpenses']))
            {
                $dataFile['billedExpenses'] = array();
            }
        }
    }

    public static function SaveDataFile($accountId, $dataFile)
    {
        $dataFilePath = self::GetDataFilePath($accountId);
        self::CheckFileAccess($dataFilePath);
        file_put_contents($dataFilePath, json_encode($dataFile));
    }

    /**
     * @return string
     */
    public static function GetDefaultAccount()
    {
        global $HARVEST_ACCOUNTS;

        if (is_null(self::$DefaultAccount))
        {
            if (empty($HARVEST_ACCOUNTS))
            {
                throw new Exception('No Harvest accounts defined');
            }

            self::$DefaultAccount = array_keys($HARVEST_ACCOUNTS)[0];
        }

        return self::$DefaultAccount;
    }

    /**
     * @return HarvestCredentials
     */
    public static function GetDefaultAccountCredentials()
    {
        if (is_null(self::$DefaultAccountCredentials))
        {
            self::$DefaultAccountCredentials = HarvestCredentials::FromName(self::GetDefaultAccount());
        }

        return self::$DefaultAccountCredentials;
    }

    public static function GetDefaultCompany()
    {
        if (is_null(self::$DefaultCompany))
        {
            $headers  = self::GetDefaultAccountCredentials()->GetHeaders();
            $curl     = new Curler\Curler(HARVEST_API_ROOT . '/v2/company', $headers);

            // fetch all of our particulars
            self::$DefaultCompany = $curl->GetJson();
        }

        return self::$DefaultCompany;
    }

    public static function FormatCurrency($value, $currency = HARVEST_DEFAULT_CURRENCY)
    {
        return ( new NumberFormatter(HARVEST_LOCALE, NumberFormatter::CURRENCY))->formatCurrency($value, $currency);
    }

    public static function FillTemplate($template, array $values)
    {
        foreach ($values as $var => $val)
        {
            $template = str_replace("[[{$var}]]", $val, $template);
        }

        return $template;
    }

    public static function GetInvoiceId($number, $today, $headers)
    {
        if (is_null(self::$ExistingInvoices))
        {
            $query = array(
                'from' => date('Y-m-d', strtotime('-1 month', $today)),
            );

            $curl                    = new Curler\Curler(HARVEST_API_ROOT . '/v2/invoices', $headers);
            self::$ExistingInvoices  = $curl->GetAllLinkedByEntity('invoices', $query);
        }

        foreach (self::$ExistingInvoices as $invoice)
        {
            if (strcasecmp(trim($invoice['number']), $number) === 0)
            {
                return $invoice['id'];
            }
        }

        return null;
    }

    public static function RaiseInvoices( array $invoiceSettings, array $recurringInvoiceSettings, $today = null)
    {
        if (is_null($today))
        {
            $today = time();
        }

        $yesterday     = strtotime('-1 day', $today);
        $yesterdayYmd  = date('Y-m-d', $yesterday);
        $nextMonth     = strtotime('+1 month', $today);

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
            $account          = HarvestCredentials::FromName($accountName);
            $headers          = $account->GetHeaders();
            $fetchUnbillable  = isset($invData['fetchUnbillable']) ? $invData['fetchUnbillable'] : true;

            // Harvest doesn't allow us to mark time entries as billed via the API, so we have to keep track ourselves
            HarvestApp::LoadDataFile($account->GetAccountId(), $dataFile);

            // 1. retrieve all uninvoiced time entries
            $query = array(
                'is_running' => 'false',
                'is_billed'  => 'false',
                'to'         => $yesterdayYmd,
            );

            $curl   = new Curler\Curler(HARVEST_API_ROOT . '/v2/time_entries', $headers);
            $times  = $curl->GetAllLinkedByEntity('time_entries', $query);

            // 2. collate them by client
            $clientTimes          = array();
            $clientExpenses       = array();
            $clientProjects       = array();
            $clientTotals         = array();
            $clientExpenseTotals  = array();
            $clientHours          = array();
            $clientBillableHours  = array();

            foreach ($times as $time)
            {
                if ( ! $fetchUnbillable && ! $time['billable'])
                {
                    continue;
                }

                $clientId   = $time['client']['id'];
                $projectId  = $time['project']['id'];

                if (in_array($clientId, $invData['excludeClients']) || in_array($time['id'], $dataFile['billedTimes']))
                {
                    continue;
                }

                if ( ! isset($clientTimes[$clientId]))
                {
                    $clientTimes[$clientId]          = array();
                    $clientExpenses[$clientId]       = array();
                    $clientProjects[$clientId]       = array();
                    $clientTotals[$clientId]         = 0;
                    $clientExpenseTotals[$clientId]  = 0;
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

            // 3. retrieve all uninvoiced expenses
            $query = array(
                'is_billed' => 'false',
                'to'        => $yesterdayYmd,
            );

            $curl      = new Curler\Curler(HARVEST_API_ROOT . '/v2/expenses', $headers);
            $expenses  = $curl->GetAllLinkedByEntity('expenses', $query);

            // 4. collate them by client
            foreach ($expenses as $expense)
            {
                if ( ! $fetchUnbillable && ! $expense['billable'])
                {
                    continue;
                }

                $clientId   = $expense['client']['id'];
                $projectId  = $expense['project']['id'];

                if (in_array($clientId, $invData['excludeClients']) || in_array($expense['id'], $dataFile['billedExpenses']))
                {
                    continue;
                }

                if ( ! isset($clientTimes[$clientId]))
                {
                    $clientTimes[$clientId]          = array();
                    $clientExpenses[$clientId]       = array();
                    $clientProjects[$clientId]       = array();
                    $clientTotals[$clientId]         = 0;
                    $clientExpenseTotals[$clientId]  = 0;
                    $clientHours[$clientId]          = 0;
                    $clientBillableHours[$clientId]  = 0;
                }

                $clientExpenses[$clientId][] = $expense;

                if ( ! in_array($projectId, $clientProjects[$clientId]))
                {
                    $clientProjects[$clientId][] = $projectId;
                }

                $clientExpenseTotals[$clientId] += $expense['total_cost'];
            }

            // 5. iterate through each client
            foreach ($clientTimes as $clientId => $invoiceTimes)
            {
                $invoiceExpenses      = $clientExpenses[$clientId];
                $clientName           = isset($invoiceTimes[0]['client']['name']) ? $invoiceTimes[0]['client']['name'] : $invoiceExpenses[0]['client']['name'];
                $expensesTotal        = $clientExpenseTotals[$clientId];
                $total                = $clientTotals[$clientId] + $expensesTotal;
                $totalHours           = $clientHours[$clientId];
                $totalBillableHours   = $clientBillableHours[$clientId];
                $prettyTotal          = HarvestApp::FormatCurrency($total);
                $prettyExpensesTotal  = HarvestApp::FormatCurrency($expensesTotal);

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

                $skipMessage = "Skipping $prettyTotal ($totalHours hours" . ($fetchUnbillable ? ", $totalBillableHours billable" : '') . "; $prettyExpensesTotal expenses) for $clientName";

                // do we invoice this client today?
                $invoiceOnEntries = 0;

                foreach ($invoiceOn as $filter => $value)
                {
                    if (is_null($value))
                    {
                        continue;
                    }

                    $invoiceOnEntries++;

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
                                HarvestApp::Log("$skipMessage (not due to be invoiced today - wrong dayOfWeek, expecting " . implode(',', $value) . ")");
                                $unbilledTotal += $total;

                                continue 3;
                            }

                            break;

                        case 'weekNumber':

                            if ( ! in_array(date('W', $today) + 0, $value))
                            {
                                HarvestApp::Log("$skipMessage (not due to be invoiced today - wrong weekNumber)");
                                $unbilledTotal += $total;

                                continue 3;
                            }

                            break;

                        case 'dayOfMonth':

                            // negative numbers are counted from the end of the month
                            if ( ! in_array(date('j', $today) + 0, $value) && ! in_array( - (date('t', $today) - date('j', $today) + 1), $value))
                            {
                                HarvestApp::Log("$skipMessage (not due to be invoiced today - wrong dayOfMonth, expecting " . implode(',', $value) . ")");
                                $unbilledTotal += $total;

                                continue 3;
                            }

                            break;

                        case 'weekOfMonth':

                            if ( ! in_array(ceil(date('j', $today) / 7), $value) && ! in_array( - ceil((date('t', $today) - date('j', $today) + 1) / 7), $value))
                            {
                                HarvestApp::Log("$skipMessage (not due to be invoiced today - wrong weekOfMonth, expecting " . implode(',', $value) . ")");
                                $unbilledTotal += $total;

                                continue 3;
                            }

                            break;

                        case 'exactDate':

                            $isToday = false;

                            foreach ($value as $exactDate)
                            {
                                if (date('Y-m-d', strtotime($exactDate)) == date('Y-m-d', $today))
                                {
                                    $isToday = true;

                                    break;
                                }
                            }

                            if ( ! $isToday)
                            {
                                HarvestApp::Log("$skipMessage (not due to be invoiced today - wrong exactDate)");
                                $unbilledTotal += $total;

                                continue 3;
                            }

                            break;

                        default:

                            throw new Exception("Unknown 'invoiceOn' entry '$filter'");
                    }
                }

                if ( ! $invoiceOnEntries)
                {
                    HarvestApp::Log("$skipMessage (will never be invoiced - 'invoiceOn' has no configured entries)");
                    $unbilledTotal += $total;

                    continue;
                }

                // yes, we do! build it out
                foreach ($invoiceBatches as $batch)
                {
                    $batchTotal               = 0;
                    $batchExpensesTotal       = 0;
                    $batchTotalHours          = 0;
                    $batchTotalBillableHours  = 0;
                    $lineItems                = array();
                    $markAsBilled             = array();
                    $markExpensesAsBilled     = array();

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
                            $finalShow[]  = implode(' - ', $show);
                            $show         = array();
                        }

                        if (in_array('people', $showData))
                        {
                            $finalShow[] = "({$t['user']['name']})";
                        }

                        $groupBy = null;

                        if ($finalShow)
                        {
                            // `project_id` is needed for each line item whether project is shown or not,
                            // and we can't group different `billable_rate`s together for obvious reasons,
                            // so we include them in our 'group by' identifier
                            $groupBy = implode(',', [
                                $t['project']['id'],
                                $t['billable_rate'],
                                implode(' ', $finalShow)
                            ]);
                        }

                        if (in_array('notes', $showData) && $t['notes'])
                        {
                            $show[] = $t['notes'];
                        }

                        $sortKey = date('YmdHis', strtotime("{$t['spent_date']} {$t['started_time']}")) . '-t-' . $t['id'];

                        // attempt to store with a meaningfully sortable key
                        $lineItems[$sortKey] = array(
                            'group_by'    => $groupBy,
                            'project_id'  => $t['project']['id'],
                            'kind'        => $invData['itemKind'],
                            'description' => $show,
                            'quantity'    => $t['hours'],
                            'unit_price'  => $t['billable_rate'],
                        );

                        $markAsBilled[] = $t;
                    }

                    foreach ($invoiceExpenses as $e)
                    {
                        // skip this entry if it doesn't relate to a project we're invoicing for
                        if ( ! in_array($e['project']['id'], $batch))
                        {
                            continue;
                        }

                        if ( ! $includeUnbillable && ! $e['billable'])
                        {
                            continue;
                        }

                        // keep running totals
                        $batchTotal         += $e['total_cost'];
                        $batchExpensesTotal += $e['total_cost'];

                        // generate pretty line item descriptions
                        $show       = array();
                        $finalShow  = array();

                        if (in_array('date', $showData))
                        {
                            $show[] = date($invData['dateFormat'], strtotime($e['spent_date']));
                        }

                        if ($show)
                        {
                            $finalShow[]  = '[' . implode(' ', $show) . ']';
                            $show         = array();
                        }

                        if (in_array('project', $showData))
                        {
                            $show[] = $e['project']['name'];
                        }

                        if (in_array('task', $showData))
                        {
                            $show[] = $e['expense_category']['name'];
                        }

                        if ($show)
                        {
                            $finalShow[]  = implode(' - ', $show);
                            $show         = array();
                        }

                        if (in_array('people', $showData))
                        {
                            $finalShow[] = "({$e['user']['name']})";
                        }

                        if ($finalShow)
                        {
                            $show[] = implode(' ', $finalShow);
                        }

                        if (in_array('notes', $showData) && $e['notes'])
                        {
                            $show[] = $e['notes'];
                        }

                        $sortKey              = date('YmdHis', strtotime("{$e['spent_date']} 00:00:00")) . '-e-' . $e['id'];
                        $lineItems[$sortKey]  = array(
                            'group_by'    => null,
                            'project_id'  => $e['project']['id'],
                            'kind'        => $invData['expenseItemKind'],
                            'description' => $show,
                            'quantity'    => 1,
                            'unit_price'  => $e['total_cost'],
                        );

                        $markExpensesAsBilled[] = $e;
                    }

                    // skip if this would be a below-minimum invoice
                    if ($batchTotal < $invoiceMinimum || ! $batchTotal)
                    {
                        HarvestApp::Log("Skipping batch ($batchTotalHours hours" . ($fetchUnbillable ? ", $batchTotalBillableHours billable" : '') . "; $batchExpensesTotal expenses) for $clientName (minimum invoice value not reached)");

                        continue;
                    }

                    ksort($lineItems);

                    // group line items together
                    $groupedLineItems  = array();
                    $groupKeys         = array();

                    foreach ($lineItems as $sortKey => $lineItem)
                    {
                        $groupBy = $lineItem['group_by'];
                        unset($lineItem['group_by']);

                        if ($groupBy)
                        {
                            if (isset($groupKeys[$groupBy]))
                            {
                                $key                  = $groupKeys[$groupBy];
                                $item                 = & $groupedLineItems[$key];
                                $item['quantity']    += $lineItem['quantity'];
                                $item['description']  = array_merge($item['description'], $lineItem['description']);

                                continue;
                            }
                            else
                            {
                                $groupKeys[$groupBy] = $sortKey;
                            }
                        }
                        else
                        {
                            $lineItem['description'] = implode("\n", $lineItem['description']);
                        }

                        $groupedLineItems[$sortKey] = $lineItem;
                    }

                    // reformat grouped descriptions
                    foreach ($groupKeys as $groupKey => $key)
                    {
                        // remove `project_id` and `billable_rate` to get the group's main description
                        list (, , $firstLine) = explode(',', $groupKey, 3);

                        // combine any `notes` appearing below identical headings
                        $item  = & $groupedLineItems[$key];
                        $desc  = array(

                            // for notes that aren't associated with a heading
                            '' => array()
                        );

                        foreach ($item['description'] as $d)
                        {
                            $da       = preg_split('/\r\n|\r|\n/', $d, - 1, PREG_SPLIT_NO_EMPTY);
                            $heading  = '';

                            foreach ($da as $dl)
                            {
                                $dl = trim($dl);

                                if (preg_match(HARVEST_HEADING_REGEX, $dl))
                                {
                                    $heading = $dl;

                                    if (isset($desc[$heading]))
                                    {
                                        continue;
                                    }

                                    $desc[$heading] = [];
                                }

                                $desc[$heading][] = $dl;
                            }
                        }

                        foreach ($desc as & $d)
                        {
                            $d = implode("\n", array_unique($d));
                        }

                        if ( ! $desc[''])
                        {
                            unset($desc['']);
                        }

                        array_unshift($desc, $firstLine);
                        $item['description'] = implode("\n", $desc);
                    }

                    while ( ! is_null(self::GetInvoiceId($number = 'H-' . date('ymd', $today) . sprintf('%02d', $i), $today, $headers)))
                    {
                        $i++;
                    }

                    // assemble invoice data for Harvest
                    $data = array(
                        'client_id'  => $clientId,
                        'number'     => $number,
                        'notes'      => $invData['notes'],
                        'issue_date' => date('Y-m-d'),
                        'line_items' => array_values($groupedLineItems),
                    );

                    if (in_array($daysToPay, [
                        15,
                        30,
                        45,
                        60,
                    ]))
                    {
                        $data['payment_term'] = "net $daysToPay";
                    }
                    else
                    {
                        $data['due_date'] = date('Y-m-d', time() + ($daysToPay * 24 * 60 * 60));
                    }

                    // create a new invoice
                    $curl     = new Curler\Curler(HARVEST_API_ROOT . '/v2/invoices', $headers);
                    $invoice  = $curl->PostJson($data);

                    // success! prepare the data for substituting into templates
                    $invoiceData = array(
                        'id'          => $invoice['id'],
                        'number'      => $invoice['number'],
                        'amount'      => HarvestApp::FormatCurrency($invoice['amount'], $invoice['currency']),
                        'dueAmount'   => HarvestApp::FormatCurrency($invoice['due_amount'], $invoice['currency']),
                        'issueDate'   => date($invData['dateFormat'], strtotime($invoice['issue_date'])),
                        'dueDate'     => date($invData['dateFormat'], strtotime($invoice['due_date'])),
                        'companyName' => $account->CompanyName,
                        'clientName'  => $clientName,
                    );

                    $invoicedTotal += $invoice['amount'];
                    HarvestApp::Log("Invoice {$invoiceData['number']} created for $clientName with id {$invoiceData['id']} ({$invoiceData['amount']} - $batchTotalHours hours" . ($fetchUnbillable ? ", $batchTotalBillableHours billable" : '') . "; $batchExpensesTotal expenses)");

                    foreach ($markAsBilled as $t)
                    {
                        $dataFile['billedTimes'][] = $t['id'];
                    }

                    foreach ($markExpensesAsBilled as $e)
                    {
                        $dataFile['billedExpenses'][] = $e['id'];
                    }

                    // save after every invoice
                    HarvestApp::SaveDataFile($account->GetAccountId(), $dataFile);

                    if ($sendEmail)
                    {
                        $curl      = new Curler\Curler(HARVEST_API_ROOT . '/v2/contacts', $headers);
                        $contacts  = $curl->GetAllLinkedByEntity('contacts', array(
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

                            $curl    = new Curler\Curler(HARVEST_API_ROOT . "/v2/invoices/{$invoiceData['id']}/messages", $headers);
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

        foreach ($recurringInvoiceSettings as $accountName => $invData)
        {
            $account  = HarvestCredentials::FromName($accountName);
            $headers  = $account->GetHeaders();

            // iterate through each recurring invoice
            foreach ($invData['invoices'] as $recurringId => $recurring)
            {
                $clientId = $recurring['clientId'];

                // retrieve name of client
                $curl        = new Curler\Curler(HARVEST_API_ROOT . "/v2/clients/$clientId", $headers);
                $client      = $curl->GetJson();
                $clientName  = $client['name'];

                // these settings are overridable per-invoice
                $invoiceOn  = $invData['invoiceOn'];
                $daysToPay  = $invData['daysToPay'];
                $sendEmail  = $invData['sendEmail'];

                if (isset($recurring['invoiceOn']))
                {
                    $invoiceOn = $recurring['invoiceOn'];
                }

                if (isset($recurring['daysToPay']))
                {
                    $daysToPay = $recurring['daysToPay'];
                }

                if (isset($recurring['sendEmail']))
                {
                    $sendEmail = $recurring['sendEmail'];
                }

                $skipMessage = "Skipping recurring invoice #$recurringId for $clientName";

                // do we issue this invoice today?
                $invoiceOnEntries = 0;

                foreach ($invoiceOn as $filter => $value)
                {
                    if (is_null($value))
                    {
                        continue;
                    }

                    $invoiceOnEntries++;

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
                                HarvestApp::Log("$skipMessage (not due to be invoiced today - wrong dayOfWeek, expecting " . implode(',', $value) . ")");

                                continue 3;
                            }

                            break;

                        case 'weekNumber':

                            if ( ! in_array(date('W', $today) + 0, $value))
                            {
                                HarvestApp::Log("$skipMessage (not due to be invoiced today - wrong weekNumber)");

                                continue 3;
                            }

                            break;

                        case 'dayOfMonth':

                            // negative numbers are counted from the end of the month
                            if ( ! in_array(date('j', $today) + 0, $value) && ! in_array( - (date('t', $today) - date('j', $today) + 1), $value))
                            {
                                HarvestApp::Log("$skipMessage (not due to be invoiced today - wrong dayOfMonth, expecting " . implode(',', $value) . ")");

                                continue 3;
                            }

                            break;

                        case 'weekOfMonth':

                            if ( ! in_array(ceil(date('j', $today) / 7), $value) && ! in_array( - ceil((date('t', $today) - date('j', $today) + 1) / 7), $value))
                            {
                                HarvestApp::Log("$skipMessage (not due to be invoiced today - wrong weekOfMonth, expecting " . implode(',', $value) . ")");

                                continue 3;
                            }

                            break;

                        case 'exactDate':

                            $isToday = false;

                            foreach ($value as $exactDate)
                            {
                                if (date('Y-m-d', strtotime($exactDate)) == date('Y-m-d', $today))
                                {
                                    $isToday = true;

                                    break;
                                }
                            }

                            if ( ! $isToday)
                            {
                                HarvestApp::Log("$skipMessage (not due to be invoiced today - wrong exactDate)");

                                continue 3;
                            }

                            break;

                        default:

                            throw new Exception("Unknown 'invoiceOn' entry '$filter'");
                    }
                }

                if ( ! $invoiceOnEntries)
                {
                    HarvestApp::Log("$skipMessage (will never be invoiced - 'invoiceOn' has no configured entries)");

                    continue;
                }

                // yes, we do! build it out
                $invoiceTotal  = 0;
                $lineItems     = $recurring['lineItems'];

                // these values are available for substitution in line item descriptions
                $invoiceData = array(
                    'thisMonthName'      => date('F', $today),
                    'nextMonthName'      => date('F', $nextMonth),
                    'thisMonthNameShort' => date('M', $today),
                    'nextMonthNameShort' => date('M', $nextMonth),
                    'thisMonthYear'      => date('Y', $today),
                    'nextMonthYear'      => date('Y', $nextMonth),
                    'companyName'        => $account->CompanyName,
                    'clientName'         => $clientName,
                );

                foreach ($lineItems as & $lineItem)
                {
                    if (isset($lineItem['description']))
                    {
                        $lineItem['description'] = HarvestApp::FillTemplate($lineItem['description'], $invoiceData);
                    }

                    $invoiceTotal += (isset($lineItem['quantity']) ? $lineItem['quantity'] : 1) * $lineItem['unit_price'];
                }

                // don't leave any stray references around
                unset($lineItem);

                // skip if this would be a zero-value invoice
                if ( ! $invoiceTotal)
                {
                    HarvestApp::Log("$skipMessage (minimum invoice value not reached)");

                    continue;
                }

                while ( ! is_null(self::GetInvoiceId($number = 'H-' . date('ymd', $today) . sprintf('%02d', $i), $today, $headers)))
                {
                    $i++;
                }

                // assemble invoice data for Harvest
                $data = array(
                    'client_id'  => $clientId,
                    'number'     => $number,
                    'notes'      => $invData['notes'],
                    'issue_date' => date('Y-m-d'),
                    'line_items' => $lineItems,
                );

                if (in_array($daysToPay, [
                    15,
                    30,
                    45,
                    60,
                ]))
                {
                    $data['payment_term'] = "net $daysToPay";
                }
                else
                {
                    $data['due_date'] = date('Y-m-d', time() + ($daysToPay * 24 * 60 * 60));
                }

                // create a new invoice
                $curl     = new Curler\Curler(HARVEST_API_ROOT . '/v2/invoices', $headers);
                $invoice  = $curl->PostJson($data);

                // success! prepare the data for substituting into templates
                $invoiceData = array_merge($invoiceData, array(
                    'id'        => $invoice['id'],
                    'number'    => $invoice['number'],
                    'amount'    => HarvestApp::FormatCurrency($invoice['amount'], $invoice['currency']),
                    'dueAmount' => HarvestApp::FormatCurrency($invoice['due_amount'], $invoice['currency']),
                    'issueDate' => date($invData['dateFormat'], strtotime($invoice['issue_date'])),
                    'dueDate'   => date($invData['dateFormat'], strtotime($invoice['due_date'])),
                ));
                $invoicedTotal += $invoice['amount'];
                HarvestApp::Log("Invoice {$invoiceData['number']} created for $clientName with id {$invoiceData['id']} ({$invoiceData['amount']} - recurring invoice #$recurringId)");

                if ($sendEmail)
                {
                    $curl      = new Curler\Curler(HARVEST_API_ROOT . '/v2/contacts', $headers);
                    $contacts  = $curl->GetAllLinkedByEntity('contacts', array(
                        'client_id' => $clientId
                    ));
                    $allowedContacts = null;

                    if (isset($recurring['contacts']))
                    {
                        $allowedContacts = $recurring['contacts'];

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

                        $curl    = new Curler\Curler(HARVEST_API_ROOT . "/v2/invoices/{$invoiceData['id']}/messages", $headers);
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
            mail(HARVEST_REPORT_EMAIL, 'Harvest invoicing report for ' . date('j M', $today), self::GetCurrentLog(), 'From: ' . HARVEST_REPORT_FROM_EMAIL . "\r\nContent-Type: text/plain; charset=utf-8");
        }
    }

    public static function SendContractorReminders( array $reminderSettings, $today = null)
    {
        if (is_null($today))
        {
            $today = time();
        }

        foreach ($reminderSettings as $accountName => $reminderData)
        {
            $account  = HarvestCredentials::FromName($accountName);
            $headers  = $account->GetHeaders();

            // is this reminder due today?
            foreach ($reminderData['remindOn'] as $filter => $value)
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
                            continue 3;
                        }

                        break;

                    case 'weekNumber':

                        if ( ! in_array(date('W', $today) + 0, $value))
                        {
                            continue 3;
                        }

                        break;

                    case 'dayOfMonth':

                        // negative numbers are counted from the end of the month
                        if ( ! in_array(date('j', $today) + 0, $value) && ! in_array( - (date('t', $today) - date('j', $today) + 1), $value))
                        {
                            continue 3;
                        }

                        break;

                    case 'weekOfMonth':

                        if ( ! in_array(ceil(date('j', $today) / 7), $value) && ! in_array( - ceil((date('t', $today) - date('j', $today) + 1) / 7), $value))
                        {
                            continue 3;
                        }

                        break;

                    case 'exactDate':

                        $isToday = false;

                        foreach ($value as $exactDate)
                        {
                            if (date('Y-m-d', strtotime($exactDate)) == date('Y-m-d', $today))
                            {
                                $isToday = true;

                                break;
                            }
                        }

                        if ( ! $isToday)
                        {
                            continue 3;
                        }

                        break;

                    default:

                        throw new Exception("Unknown 'invoiceOn' entry '$filter'");
                }
            }

            // yes, it is!
            $start     = strtotime($reminderData['remindRange']['start'], $today);
            $end       = strtotime($reminderData['remindRange']['end'], $today);
            $startYmd  = date('Y-m-d', $start);
            $endYmd    = date('Y-m-d', $end);

            // first, identify our contractors
            $query = array(
                'is_active' => 'true',
            );

            $curl         = new Curler\Curler(HARVEST_API_ROOT . '/v2/users', $headers);
            $users        = $curl->GetAllLinkedByEntity('users', $query);
            $contractors  = array_filter($users,

            function ($u)
            {
                return $u['is_contractor'];
            }

            );

            // then, gather their timesheets
            foreach ($contractors as $contractor)
            {
                $hours  = 0;
                $query  = array(
                    'user_id'    => $contractor['id'],
                    'is_running' => 'false',
                    'from'       => $startYmd,
                    'to'         => $endYmd,
                );

                $curl   = new Curler\Curler(HARVEST_API_ROOT . '/v2/time_entries', $headers);
                $times  = $curl->GetAllLinkedByEntity('time_entries', $query);

                foreach ($times as $time)
                {
                    $hours += $time['hours'];
                }

                if ($hours)
                {
                    $data = [
                        'contractorFirstName' => $contractor['first_name'],
                        'contractorLastName'  => $contractor['last_name'],
                        'companyName'         => $account->CompanyName,
                        'startDate'           => date($reminderData['dateFormat'], $start),
                        'endDate'             => date($reminderData['dateFormat'], $end),
                        'startDate_short'     => date('j M', $start),
                        'endDate_short'       => date('j M', $end),
                        'days'                => round(($end - $start) / (60 * 60 * 24)) + 1,    // rounded to allow for DST
                        'billableHours'       => $hours,
                        'invoiceTotal'        => HarvestApp::FormatCurrency($contractor['cost_rate'] * $hours),
                    ];

                    $subject  = self::FillTemplate($reminderData['emailSubject'], $data);
                    $message  = self::FillTemplate($reminderData['emailBody'], $data);
                    mail("$contractor[first_name] $contractor[last_name] <$contractor[email]>", $subject, $message, 'From: ' . HARVEST_REPORT_FROM_EMAIL . "\r\nCc: " . HARVEST_REPORT_EMAIL . "\r\nContent-Type: text/plain; charset=utf-8");
                }
            }
        }
    }
}

