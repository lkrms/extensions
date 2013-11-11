<?php

define("OST_ROOT", dirname(__file__));
require_once (OST_ROOT . "/common.php");

if ( ! OST_CLI)
{
    exit ("This script is CLI-only.");
}

function SplitRecords(mysqli_result $rs, array & $arr)
{
    global $staffEmails;

    while ($r = $rs->fetch_assoc())
    {
        $email = $r["email"];

        if ( ! isset($staffEmails[$email]))
        {
            $staffEmails[$email] = array("firstname" => $r["firstname"], "lastname" => $r["lastname"]);
        }

        // save a little memory
        unset($r["email"]);
        unset($r["firstname"]);
        unset($r["lastname"]);

        if ( ! isset($arr[$email]))
        {
            $arr[$email] = array();
        }

        $arr[$email][] = $r;
    }

    $rs->close();
}

function AddJobSheetSection(OSTPDF & $pdf, $heading, array & $tickets)
{
    $pdf->SectionHeading("\n$heading\n");
    $pdf->TicketTable($tickets);
}

$db = new mysqli(DBHOST, DBUSER, DBPASS, DBNAME);

// this syntax is compatible with buggy versions of PHP
if (mysqli_connect_error())
{
    exit ("Unable to connect to osTicket database: " . mysqli_connect_error());
}

$staffEmails = array();

// these are populated per-user before rendering starts, so memory usage may be an issue for very large departments and/or ticket counts
$unassigned  = array();
$today       = array();
$upcoming    = array();
$pending     = array();

// first, the unassigned tickets (all staff in relevant departments are notified, except for inactive/onvacation/assigned_only staff)
SplitRecords($db->query("
select ost_staff.firstname,
    ost_staff.lastname,
    ost_staff.email,
    ost_ticket.ticket_id,
    ost_ticket.ticketID,
    ost_ticket.name,
    ost_ticket.subject,
    ost_ticket.duedate,
    ost_ticket.lastmessage,
    ost_ticket.lastresponse,
    ost_ticket.created,
    coalesce(ost_ticket_priority.priority_desc, (select priority_desc from ost_ticket_priority where priority_id = (select `value` from ost_config where `key` = 'default_priority_id'))) as priority_desc,
    coalesce(ost_ticket_priority.priority_color, (select priority_color from ost_ticket_priority where priority_id = (select `value` from ost_config where `key` = 'default_priority_id'))) as priority_color,
    coalesce(ost_ticket_priority.priority_urgency, (select priority_urgency from ost_ticket_priority where priority_id = (select `value` from ost_config where `key` = 'default_priority_id'))) as priority_urgency,
    ost_help_topic.topic
from ost_ticket
    left join ost_ticket_priority on ost_ticket.priority_id = ost_ticket_priority.priority_id
    left join ost_help_topic on ost_ticket.topic_id = ost_help_topic.topic_id
    inner join ost_staff on ost_ticket.dept_id = ost_staff.dept_id
where ost_ticket.status <> 'closed'
    and ost_staff.isactive = 1
    and ost_staff.onvacation = 0
    and ost_staff.assigned_only = 0
    and ost_ticket.staff_id = 0
order by email, priority_urgency, created
"), $unassigned);

// today's tickets (including any overdue ones)
SplitRecords($db->query("
select ost_staff.firstname,
    ost_staff.lastname,
    ost_staff.email,
    ost_ticket.ticket_id,
    ost_ticket.ticketID,
    ost_ticket.name,
    ost_ticket.subject,
    ost_ticket.duedate,
    ost_ticket.lastmessage,
    ost_ticket.lastresponse,
    ost_ticket.created,
    coalesce(ost_ticket_priority.priority_desc, (select priority_desc from ost_ticket_priority where priority_id = (select `value` from ost_config where `key` = 'default_priority_id'))) as priority_desc,
    coalesce(ost_ticket_priority.priority_color, (select priority_color from ost_ticket_priority where priority_id = (select `value` from ost_config where `key` = 'default_priority_id'))) as priority_color,
    coalesce(ost_ticket_priority.priority_urgency, (select priority_urgency from ost_ticket_priority where priority_id = (select `value` from ost_config where `key` = 'default_priority_id'))) as priority_urgency,
    ost_help_topic.topic
from ost_ticket
    left join ost_ticket_priority on ost_ticket.priority_id = ost_ticket_priority.priority_id
    left join ost_help_topic on ost_ticket.topic_id = ost_help_topic.topic_id
    inner join ost_staff on ost_ticket.staff_id = ost_staff.staff_id
where ost_ticket.status <> 'closed'
    and ost_staff.isactive = 1
    and ost_staff.onvacation = 0
    and (ost_ticket.duedate <= now()
    or ost_ticket.isoverdue <> 0)
order by email, duedate desc, priority_urgency, created
"), $today);

// upcoming tickets
SplitRecords($db->query("
select ost_staff.firstname,
    ost_staff.lastname,
    ost_staff.email,
    ost_ticket.ticket_id,
    ost_ticket.ticketID,
    ost_ticket.name,
    ost_ticket.subject,
    ost_ticket.duedate,
    ost_ticket.lastmessage,
    ost_ticket.lastresponse,
    ost_ticket.created,
    coalesce(ost_ticket_priority.priority_desc, (select priority_desc from ost_ticket_priority where priority_id = (select `value` from ost_config where `key` = 'default_priority_id'))) as priority_desc,
    coalesce(ost_ticket_priority.priority_color, (select priority_color from ost_ticket_priority where priority_id = (select `value` from ost_config where `key` = 'default_priority_id'))) as priority_color,
    coalesce(ost_ticket_priority.priority_urgency, (select priority_urgency from ost_ticket_priority where priority_id = (select `value` from ost_config where `key` = 'default_priority_id'))) as priority_urgency,
    ost_help_topic.topic
from ost_ticket
    left join ost_ticket_priority on ost_ticket.priority_id = ost_ticket_priority.priority_id
    left join ost_help_topic on ost_ticket.topic_id = ost_help_topic.topic_id
    inner join ost_staff on ost_ticket.staff_id = ost_staff.staff_id
where ost_ticket.status <> 'closed'
    and ost_staff.isactive = 1
    and ost_staff.onvacation = 0
    and ost_ticket.duedate > now()
    and ost_ticket.duedate < adddate(curdate(), " . (OST_UPCOMING_DAYS + 1) . ")
    and ost_ticket.isoverdue = 0
order by email, duedate, priority_urgency, created
"), $upcoming);

// finally, every other unclosed ticket in the system
SplitRecords($db->query("
select ost_staff.firstname,
    ost_staff.lastname,
    ost_staff.email,
    ost_ticket.ticket_id,
    ost_ticket.ticketID,
    ost_ticket.name,
    ost_ticket.subject,
    ost_ticket.duedate,
    ost_ticket.lastmessage,
    ost_ticket.lastresponse,
    ost_ticket.created,
    coalesce(ost_ticket_priority.priority_desc, (select priority_desc from ost_ticket_priority where priority_id = (select `value` from ost_config where `key` = 'default_priority_id'))) as priority_desc,
    coalesce(ost_ticket_priority.priority_color, (select priority_color from ost_ticket_priority where priority_id = (select `value` from ost_config where `key` = 'default_priority_id'))) as priority_color,
    coalesce(ost_ticket_priority.priority_urgency, (select priority_urgency from ost_ticket_priority where priority_id = (select `value` from ost_config where `key` = 'default_priority_id'))) as priority_urgency,
    ost_help_topic.topic
from ost_ticket
    left join ost_ticket_priority on ost_ticket.priority_id = ost_ticket_priority.priority_id
    left join ost_help_topic on ost_ticket.topic_id = ost_help_topic.topic_id
    inner join ost_staff on ost_ticket.staff_id = ost_staff.staff_id
where ost_ticket.status <> 'closed'
    and ost_staff.isactive = 1
    and ost_staff.onvacation = 0
    and (ost_ticket.duedate is null
    or ost_ticket.duedate >= adddate(curdate(), " . (OST_UPCOMING_DAYS + 1) . "))
    and ost_ticket.isoverdue = 0
order by email, duedate, priority_urgency, lastresponse
"), $pending);

// load settings from osTicket
$rs = $db->query("
select
    (select `value` from ost_config where `key` = 'helpdesk_title') as helpdesk_title,
    (select `value` from ost_config where `key` = 'helpdesk_url') as helpdesk_url
");
$ostSettings = $rs->fetch_assoc();
$rs->close();

// won't be needing this anymore
$db->close();

foreach ($staffEmails as $email => $names)
{
    echo "Preparing PDF for $names[firstname] $names[lastname] <$email>...\n";

    // create a customised report for each staff member
    $pdf = new OSTPDF();
    $pdf->AddPage();
    $pdf->Heading("$ostSettings[helpdesk_title] job sheet for $names[firstname] $names[lastname]\n");
    $pdf->Subheading(date(OST_HEADING_DATE_FORMAT) . "\n");

    if (isset($unassigned[$email]))
    {
        $c = count($unassigned[$email]);
        AddJobSheetSection($pdf, "New " . ($c > 1 ? OST_TICKET_PLURAL : OST_TICKET_SINGULAR) . " (not yet assigned)", $unassigned[$email]);
    }

    if (isset($today[$email]))
    {
        $c = count($today[$email]);
        AddJobSheetSection($pdf, "Your " . ($c > 1 ? OST_TICKET_PLURAL : OST_TICKET_SINGULAR) . " for today", $today[$email]);
    }

    if (isset($upcoming[$email]))
    {
        AddJobSheetSection($pdf, "Due in the next " . OST_UPCOMING_DAYS . " days", $upcoming[$email]);
    }

    if (isset($pending[$email]))
    {
        $c = count($pending[$email]);
        AddJobSheetSection($pdf, ucfirst($c > 1 ? OST_TICKET_PLURAL : OST_TICKET_SINGULAR) . " assigned to you", $pending[$email]);
    }

    $pdf->Output(OST_ROOT . "/.tmp/$email.pdf", "F");
}

// PRETTY_NESTED_ARRAYS,0

?>