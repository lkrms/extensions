<?php

define("OST_CLI", php_sapi_name() == "cli");
define("OST_HEADING_SIZE", 16);
define("OST_SUBHEADING_SIZE", 12);
define("OST_SECTION_HEADING_SIZE", 10);
define("OST_TABLE_HEADING_SIZE", 8);
define("OST_TABLE_TEXT_SIZE", 8);
define("OST_TABLE_PADDING", 2);
define("OST_HEADING_LEADING", 1.2);
define("OST_TEXT_LEADING", 1.2);

// this has nothing to do with any installed instance of osTicket; we don't have any osTicket code dependencies
if ( ! defined("OST_ROOT"))
{
    define("OST_ROOT", dirname(__file__));
}

// load settings
require_once (OST_ROOT . "/config.php");

// load libraries
require_once (OST_ROOT . "/../lib/fpdf/fpdf.php");

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
        $this->SetFont("", "I", OST_SUBHEADING_SIZE);
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
        $w   = array(15, 15, 20, 90, 20, 15, 15);
        $h1  = OST_TABLE_HEADING_SIZE / $this->k * OST_TEXT_LEADING;
        $h2  = OST_TABLE_TEXT_SIZE / $this->k * OST_TEXT_LEADING;

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
                $this->Cell($w[0], $h1, ucfirst(OST_TICKET_SINGULAR));
                $this->Cell($w[1], $h1, "Due");
                $this->Cell($w[2], $h1, "Priority", 0, 0, "C");
                $this->Cell($w[3], $h1, "Subject");
                $this->Cell($w[4], $h1, "From");
                $this->Cell($w[5], $h1, "Created");
                $this->Cell($w[6], $h1, "Response");
                $this->Ln();
                $this->SetY($this->GetY() + OST_TABLE_PADDING);
                $addHeader = false;
            }

            // fill with our priority colour wherever desired
            $priorityRGB = $this->GetRGB($ticket["priority_color"], array(255, 255, 255));
            $this->SetFillColor($priorityRGB[0], $priorityRGB[1], $priorityRGB[2]);
            $maxY = 0;
            $this->SetFont("", "", OST_TABLE_TEXT_SIZE);
            $this->Cell($w[0], $h2, $ticket["ticketID"], 0, 0, "L", false, $ostUrl . $ticket["ticket_id"]);
            $this->Cell($w[1], $h2, SmartDate($ticket["duedate"]));
            $this->Cell($w[2], $h2, $ticket["priority_desc"], 0, 0, "C", true);
            $this->DoTicketTableMultiCell($w[3], $h2, $ticket["subject"], $maxY);
            $this->Cell($w[4], $h2, ShortName($ticket["name"]));
            $this->Cell($w[5], $h2, SmartDate($ticket["created"]));
            $this->Cell($w[6], $h2, SmartDate($ticket["lastresponse"]));
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

function SmartDate($dateString)
{
    if ( ! $dateString || $dateString == "0000-00-00 00:00:00")
    {
        return "";
    }

    $date = strtotime($dateString);

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

// PRETTY_NESTED_ARRAYS,0

?>