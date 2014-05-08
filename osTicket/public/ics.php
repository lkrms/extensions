<?php

define("OST_ROOT", dirname(__file__) . "/..");
require_once (OST_ROOT . "/common.php");

if ( ! OST_CLI && ( ! defined("OST_ICS_TOKEN") || ! _get("token") || _get("token") != OST_ICS_TOKEN))
{
    exit ("Invalid access token.");
}

function AddCalendarEvent( array $r, array & $arr)
{
    global $ostSettings;

    // see http://www.kanzaki.com/docs/ical/vevent.html (or RFC 2445 itself)
    $arr[] = "BEGIN:VEVENT";

    // required for Outlook 2002-2003
    $arr[]  = "UID:" . uniqid() . "-" . $ostSettings["default_email"];
    $arr[]  = "DTSTAMP:" . UTCDateTime($r["created"]);

    // if no time is specified, create an all-day event
    if (substr($r["duedate"], - 8) == "00:00:00")
    {
        // use the DATE data type
        $arr[] = "DTSTART:" . date("Ymd", strtotime($r["duedate"]));
    }
    else
    {
        $arr[]  = "DTSTART:" . UTCDateTime($r["duedate"]);
        $arr[]  = "DTEND:" . UTCDateTime($r["duedate"], OST_ICS_EVENT_DURATION * 60);
    }

    $arr[]  = "SUMMARY:" . $r["subject"];
    $arr[]  = "DESCRIPTION:Requested by $r[name] in " . OST_TICKET_SINGULAR . " #$r[ticketID]. Currently assigned to $r[firstname] $r[lastname]. More: $ostSettings[helpdesk_url]scp/tickets.php?id=$r[ticket_id]";
    $arr[]  = "END:VEVENT";
}

function StringToInt($str, $err = "Invalid integer.")
{
    if ($str + 0 != $str || ! is_int($str + 0))
    {
        exit ($err);
    }

    return $str + 0;
}

$db = new mysqli(DBHOST, DBUSER, DBPASS, DBNAME);

// this syntax is compatible with buggy versions of PHP
if (mysqli_connect_error())
{
    exit ("Unable to connect to osTicket database: " . mysqli_connect_error());
}

// load settings from osTicket
$rs = $db->query("
select
    (select `value` from ost_config where `key` = 'helpdesk_url') as helpdesk_url,
    (select email from ost_email where email_id = (select `value` from ost_config where `key` = 'default_email_id')) as default_email
");
$ostSettings = $rs->fetch_assoc();
$rs->close();

// build out our iCalendar object
$ics    = array();
$ics[]  = "BEGIN:VCALENDAR";
$ics[]  = "VERSION:2.0";

// add some ticket filtering criteria if requested
$sql = "";

if ( ! OST_CLI && $userId = _get("user"))
{
    $sql = "and ost_ticket.staff_id = " . StringToInt($userId, "Invalid user ID.");
}
elseif ( ! OST_CLI && $deptId = _get("dept"))
{
    $sql = "and ost_ticket.dept_id = " . StringToInt($deptId, "Invalid department ID.");
}

// we only care about open tickets with a due date
$rs = $db->query("
select ost_staff.firstname,
    ost_staff.lastname,
    ost_staff.email,
    ost_ticket.ticket_id,
    ost_ticket.number as ticketID,
    ost_user.name,
    ost_ticket__cdata.subject,
    ost_ticket.duedate,
    ost_ticket.created,
    ost_ticket.updated
from ost_ticket
    inner join ost_ticket__cdata on ost_ticket.ticket_id = ost_ticket__cdata.ticket_id
    inner join ost_user on ost_ticket.user_id = ost_user.id
    inner join ost_staff on ost_ticket.staff_id = ost_staff.staff_id
where ost_ticket.status <> 'closed'
    and ost_ticket.duedate is not null
    $sql");

while ($r = $rs->fetch_assoc())
{
    AddCalendarEvent($r, $ics);
}

$rs->close();
$ics[] = "END:VCALENDAR";

// the spec stipulates \r\n newlines
$file = implode("\r\n", $ics);

// ensure data is properly delivered as an .ics file, without caching
header("Content-Type: text/calendar");
header("Content-Disposition: attachment; filename=osTicket.ics");
header("Content-Length: " . strlen($file));
header("Expires: 0");
header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
header("Pragma: public");
echo $file;

?>