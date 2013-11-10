<?php

define("OST_CLI", php_sapi_name() == "cli");
define("OST_HEADING_SIZE", 18);
define("OST_SUBHEADING_SIZE", 14);
define("OST_SECTION_HEADING_SIZE", 12);
define("OST_TABLE_HEADING_SIZE", 9);
define("OST_TABLE_TEXT_SIZE", 9);
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

    function TicketTable($tickets)
    {
        // optimised for A4 portrait
        $w   = array(20, 20, 75, 35, 20, 20);
        $h1  = (OST_TABLE_HEADING_SIZE / $this->k * OST_TEXT_LEADING) + OST_TABLE_PADDING;
        $h2  = OST_TABLE_TEXT_SIZE / $this->k * OST_TEXT_LEADING;

        // add header row
        $this->SetFont("", "B", OST_TABLE_HEADING_SIZE);
        $this->Cell($w[0], $h1, ucfirst(OST_TICKET_SINGULAR));
        $this->Cell($w[1], $h1, "Due");
        $this->Cell($w[2], $h1, "Subject");
        $this->Cell($w[3], $h1, "From");
        $this->Cell($w[4], $h1, "Created");
        $this->Cell($w[5], $h1, "Response");
        $this->Ln();

        foreach ($tickets as $ticket)
        {
            $maxY = 0;
            $this->SetFont("", "", OST_TABLE_TEXT_SIZE);
            $this->Cell($w[0], $h2, $ticket["ticketID"]);
            $this->Cell($w[1], $h2, SmartDate($ticket["duedate"]));
            $this->DoTicketTableMultiCell($w[2], $h2, $ticket["subject"], $maxY);
            $this->DoTicketTableMultiCell($w[3], $h2, $ticket["name"], $maxY);
            $this->Cell($w[4], $h2, SmartDate($ticket["created"]));
            $this->Cell($w[5], $h2, SmartDate($ticket["lastresponse"]));
            $this->Ln();

            // make sure we clear our highest cell, and add some breathing room before the next row
            $y = $this->GetY();
            $this->SetY(($maxY > $y ? $maxY : $y) + OST_TABLE_PADDING);
        }
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

// PRETTY_NESTED_ARRAYS,0

?>