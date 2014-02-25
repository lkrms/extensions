<?php

// TASS database credentials
define("TASS_DB_SERVER", "TASS01");
define("TASS_DB_USERNAME", "read_only_user");
define("TASS_DB_PASSWORD", "PASSWORD");    // NOTE: must be <= 30 characters
define("TASS_DB_NAME", "tass");

// timezone for this instance of TASS
define("TASS_TIMEZONE", "Australia/Sydney");

// Canvas URL (no trailing slash)
define("CANVAS_URL", "https://canvas.mydomain.com");

// OAuth token for a Canvas user authorised to import SIS data
define("CANVAS_TOKEN", "TOKEN");

// Canvas account ID for SIS imports
define("CANVAS_ACCOUNT_ID", 1);

?>