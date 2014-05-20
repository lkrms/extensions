<?php

// where to send result notifications
define("NOTIFY_EMAIL", "root@localhost");
define("ERROR_EMAIL", NOTIFY_EMAIL);
define("NOTIFY_EMAIL_FROM", "tass@localhost");

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

// courses can be created per-term or per-year
define("CANVAS_ALL_YEAR_COURSES", true);

// course IDs are [YEAR][SUFFIX]C[SUBJECT_CODE|CLASS_CODE] (using T1 here can assist when transitioning to or from all-year courses)
define("CANVAS_ALL_YEAR_COURSE_ID_SUFFIX", "T1");

?>