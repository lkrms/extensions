<?php

define("OST_CLI", php_sapi_name() == "cli");
define("OST_HEADING_SIZE", 14);
define("OST_SUBHEADING_SIZE", 8.5);
define("OST_SECTION_HEADING_SIZE", 10);
define("OST_TABLE_HEADING_SIZE", 6);
define("OST_TABLE_TEXT_SIZE", 8.5);
define("OST_TABLE_PADDING", 2);
define("OST_HEADING_LEADING", 1.2);
define("OST_TEXT_LEADING", 1.2);

// this has nothing to do with any installed instance of osTicket; we don't have any osTicket code dependencies
if ( ! defined("OST_ROOT"))
{
    define("OST_ROOT", dirname(__file__));
}

// for PEAR
ini_set("include_path", OST_ROOT . "/../lib/php");

// load settings
require_once (OST_ROOT . "/config.php");

// load libraries
require_once (OST_ROOT . "/../lib/fpdf/fpdf.php");
require_once (OST_ROOT . "/../lib/php/Mail.php");
require_once (OST_ROOT . "/../lib/php/Mail/mime.php");

// required for date() calls
date_default_timezone_set(OST_TIMEZONE);

class OSTPDF extends FPDF
{
    private $inTicketTable = false;

    private $breakTicketTable = false;

    function OSTPDF($orientation = "P", $unit = "mm", $size = "A4")
    {
        parent::__construct($orientation, $unit, $size);

        // consistent typography across all custom osTicket PDFs
        $this->SetFont("Arial");
    }

    function Heading($text)
    {
        $this->SetFont("", "B", OST_HEADING_SIZE);
        $this->Write(OST_HEADING_SIZE / $this->k * OST_HEADING_LEADING, $text);
    }

    function Subheading($text)
    {
        $this->SetFont("", "", OST_SUBHEADING_SIZE);
        $this->Write(OST_HEADING_SIZE / $this->k * OST_HEADING_LEADING, $text);
    }

    function SectionHeading($text)
    {
        $this->SetFont("", "B", OST_SECTION_HEADING_SIZE);
        $this->Write(OST_HEADING_SIZE / $this->k * OST_HEADING_LEADING, $text);
    }

    function AcceptPageBreak()
    {
        if ( ! $this->inTicketTable)
        {
            return parent::AcceptPageBreak();
        }
        else
        {
            $this->breakTicketTable = true;

            return false;
        }
    }

    function GetRGB($webColor, $default = null)
    {
        $webColor  = strtolower(trim($webColor));
        $matches   = array();

        if (preg_match('/^#?([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/', $webColor, $matches))
        {
            return array(hexdec($matches[1]), hexdec($matches[2]), hexdec($matches[3]));
        }
        else
        {
            return is_null($default) ? array(0, 0, 0) : $default;
        }
    }

    function TicketTable($tickets, $ostUrl)
    {
        $this->inTicketTable     = true;
        $this->breakTicketTable  = false;
        $addHeader               = true;

        // optimised for A4 portrait
        $w  = array(15, 15, 20, 90, 20, 15, 15);
        $h  = OST_TABLE_TEXT_SIZE / $this->k * OST_TEXT_LEADING;

        foreach ($tickets as $ticket)
        {
            if ($this->breakTicketTable)
            {
                $this->breakTicketTable = false;
                $this->AddPage();
                $addHeader = true;
            }

            if ($addHeader)
            {
                // add header row
                $this->SetFont("", "B", OST_TABLE_HEADING_SIZE);
                $this->Cell($w[0], $h, strtoupper(OST_TICKET_SINGULAR));
                $this->Cell($w[1], $h, strtoupper("Due"));
                $this->Cell($w[2], $h, strtoupper("Priority"), 0, 0, "C");
                $this->Cell($w[3], $h, strtoupper("Subject"));
                $this->Cell($w[4], $h, strtoupper("From"));
                $this->Cell($w[5], $h, strtoupper("Created"));
                $this->Cell($w[6], $h, strtoupper("Response"));
                $this->Ln();
                $this->SetY($this->GetY() + OST_TABLE_PADDING);
                $addHeader = false;
            }

            // fill with our priority colour wherever desired
            $priorityRGB = $this->GetRGB($ticket["priority_color"], array(255, 255, 255));
            $this->SetFillColor($priorityRGB[0], $priorityRGB[1], $priorityRGB[2]);
            $maxY = 0;
            $this->SetFont("", "", OST_TABLE_TEXT_SIZE);
            $this->SetTextColor(0, 0, 128);
            $this->Cell($w[0], $h, $ticket["ticketID"], 0, 0, "L", false, $ostUrl . $ticket["ticket_id"]);
            $this->SetTextColor(0, 0, 0);
            $this->Cell($w[1], $h, SmartDate($ticket["duedate"]));
            $this->SetFont("", $ticket["priority_urgency"] < 3 ? "B" : "", OST_TABLE_TEXT_SIZE);
            $this->Cell($w[2], $h, $ticket["priority_desc"], 0, 0, "C", true);
            $this->SetFont("", "", OST_TABLE_TEXT_SIZE);
            $this->DoTicketTableMultiCell($w[3], $h, $ticket["subject"], $maxY);
            $this->Cell($w[4], $h, ShortName($ticket["name"]));
            $this->Cell($w[5], $h, SmartDate($ticket["created"]));
            $this->Cell($w[6], $h, SmartDate($ticket["lastresponse"]));
            $this->Ln();

            // make sure we clear our highest cell, and add some breathing room before the next row
            $y = $this->GetY();
            $this->SetY(($maxY > $y ? $maxY : $y) + OST_TABLE_PADDING);
        }

        $this->inTicketTable = false;
    }

    private function DoTicketTableMultiCell($w, $h, $text, & $maxY)
    {
        $x  = $this->GetX();
        $y  = $this->GetY();
        $this->MultiCell($w - OST_TABLE_PADDING, $h, $text, 0, "L");
        $newY  = $this->GetY();
        $maxY  = $newY > $maxY ? $newY : $maxY;
        $this->SetXY($x + $w, $y);
    }
}

function SmartDate($dateString, $short = true)
{
    if ( ! $dateString || $dateString == "0000-00-00 00:00:00" || $dateString == "0000-00-00")
    {
        return "";
    }

    // remove time from consideration
    $date   = strtotime(date("Y-m-d", strtotime($dateString)));
    $today  = strtotime(date("Y-m-d"));

    // diff is in seconds
    $diff  = $today - $date;
    $past  = true;

    if ($diff < 0)
    {
        $past  = false;
        $diff  = abs($diff);
    }

    $days   = round($diff / 86400);
    $weeks  = floor($days / 7);

    if ($days == 0)
    {
        return "Today";
    }
    elseif ($days == 1)
    {
        return $past ? ($short ? "1d ago" : "Yesterday") : ($short ? "1d" : "Tomorrow");
    }
    elseif ($days < 7)
    {
        return $days . ($short ? "d" : " days") . ($past ? " ago" : "");
    }
    elseif ($weeks < 10)
    {
        return $weeks . ($short ? "w" : " weeks") . ($past ? " ago" : "");
    }

    return date(OST_GENERAL_DATE_FORMAT, $date);
}

function ShortName($fullName)
{
    $names = explode(" ", $fullName);

    for ($i = 0; $i < count($names) - 1; $i++)
    {
        $names[$i] = substr($names[$i], 0, 1) . ".";
    }

    return implode(" ", $names);
}

function UTCDateTime($dateString, $addSeconds = 0)
{
    // work in the local time zone for starters
    $ts  = strtotime($dateString) += $addSeconds;
    $tz  = date_default_timezone_get();
    date_default_timezone_set("UTC");
    $utc = date('Ymd\THis\Z', $ts);
    date_default_timezone_set($tz);

    return $utc;
}

function _get($name, $default = "")
{
    if (isset($_GET[$name]))
    {
        return $_GET[$name];
    }
    else
    {
        return $default;
    }
}

// PRETTY_NESTED_ARRAYS,0

?>