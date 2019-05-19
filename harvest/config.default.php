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
        'customClients' => array(           // index by client ID, elements should be arrays with 'showData', 'invoiceOn', 'includeUnbillable', 'invoiceMinimum', 'daysToPay', 'sendEmail', and/or 'projectContacts' (mapping from project ID to one or more contact ID's)
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
            'exactDate'   => null,          // an array of dates that can be reliably parsed by strtotime (yyyy-mm-dd recommended)
        ),
        'fetchUnbillable'   => true,        // retrieve unbillable time entries? (for daily email, or to add to invoices)
        'includeUnbillable' => true,        // display unbillable line items on invoices (if fetched)?
        'invoiceMinimum'    => 0,           // invoices will not be raised until they exceed this value
        'daysToPay'         => 7,
        'sendEmail'         => true,
        'dateFormat'        => 'd/m/Y',
        'itemKind'          => 'Service',   // must match the name of a service item type under Invoices > Configure > Item Types in Harvest
        'expenseItemKind'   => 'Product',
        'notes'             => "We accept payment by direct deposit, cheque, VISA, or MasterCard.\n\nIf paying by direct deposit or cheque, please provide your invoice number with your payment.",
        'emailSubject'      => "Invoice [[number]] from [[companyName]] for [[clientName]]",
        'emailBody'         => "Hi [[clientName]],\r\n\r\nHere's invoice [[number]] for [[amount]].\r\n\r\nThe amount outstanding of [[dueAmount]] is due on [[dueDate]].\r\n\r\nThe detailed invoice is attached as a PDF.\r\n\r\nIf you have any questions, please let us know.\r\n\r\nThanks,\r\n[[companyName]]",
    ),
);

// indexed by name (must match an index in $HARVEST_ACCOUNTS)
$HARVEST_RECURRING_INVOICES = array(
    'harvest1' => array(
        'invoices' => array(                            // each array must contain 'clientId' and 'lineItems'; 'contacts', 'invoiceOn', 'daysToPay', and 'sendEmail' are optional and override the defaults
            array(
                'clientId'  => 5000000,
                'lineItems' => array(                   // for each item, 'kind' and 'unit_price' are required; 'project_id', 'description', 'quantity', 'taxed', and 'taxed2' are also available
                    array(
                        'kind'        => 'Service',     // must match the name of a service item type under Invoices > Configure > Item Types in Harvest
                        'description' => "Monthly retainer: [[nextMonthName]] [[nextMonthYear]]\n- maximum 2-hour response time during business hours (9am-5pm Mon-Fri)\n- consulting tasks and projects will be quoted and billed separately as required",
                        'quantity'    => 1,
                        'unit_price'  => 120,
                    ),
                ),
                'contacts'  => array(                   // if contact ID's are not provided, ALL contacts with email addresses will receive the invoice
                    4000000,
                    4000001,
                ),
            )
        ),
        'invoiceOn' => array(           // all non-null entries must match for invoice to be raised
            'dayOfWeek'   => 3,         // 0-6, Sunday-Saturday
            'dayOfMonth'  => null,      // negative numbers are measured from end of month
            'weekOfMonth' => -2,        // e.g. for last Friday of the month, use -1 here and 5 for dayOfWeek
            'exactDate'   => null,      // an array of dates that can be reliably parsed by strtotime (yyyy-mm-dd recommended)
        ),
        'daysToPay'    => 7,
        'sendEmail'    => true,
        'dateFormat'   => 'd/m/Y',
        'notes'        => "We accept payment by direct deposit, cheque, VISA, or MasterCard.\n\nIf paying by direct deposit or cheque, please provide your invoice number with your payment.",
        'emailSubject' => "Invoice [[number]] from [[companyName]] for [[clientName]]",
        'emailBody'    => "Hi [[clientName]],\r\n\r\nHere's invoice [[number]] for [[amount]].\r\n\r\nThe amount outstanding of [[dueAmount]] is due on [[dueDate]].\r\n\r\nThe detailed invoice is attached as a PDF.\r\n\r\nIf you have any questions, please let us know.\r\n\r\nThanks,\r\n[[companyName]]",
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

define('HARVEST_REPORT_FROM_EMAIL', 'Automation Services <automagical@mydomain.com>');
define('HARVEST_REPORT_EMAIL', 'accounts@mydomain.com');
define('HARVEST_TIMEZONE', 'Australia/Sydney');

?>