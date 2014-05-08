<?php

// If running on the same host as osTicket itself, you can copy and paste these settings from <include/ost-config.php>.
// Alternatively, create a dedicated read-only MySQL user and provide its credentials here.
define("DBHOST", "localhost");
define("DBNAME", "osticket");
define("DBUSER", "osticket");
define("DBPASS", "PASSWORD");

// Timezone for this instance of osTicket.
define("OST_TIMEZONE", "Australia/Sydney");

// Date formats.
define("OST_HEADING_DATE_FORMAT", "l, j F Y");
define("OST_GENERAL_DATE_FORMAT", "j-M");

// How many days into the future to display under "upcoming tickets".
define("OST_UPCOMING_DAYS", 5);

// e.g. you might want to call it a "job" rather than a "ticket".
define("OST_TICKET_SINGULAR", "ticket");
define("OST_TICKET_PLURAL", "tickets");

// a secret token that must be provided when retrieving iCalendar data
define("OST_ICS_TOKEN", "");

?>