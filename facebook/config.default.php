<?php

// obtain these from http://developers.facebook.com/apps
define("FACEBOOK_APP_ID", "XXXX");
define("FACEBOOK_APP_SECRET", "XXXX");

// the duration (in seconds) of query windows when archiving Facebook data
define("FACEBOOK_ARCHIVE_INTERVAL", 60 * 60 * 24 * 30);

// how many of these intervals to query (counting backwards from now, 0 to disable)
define("FACEBOOK_ARCHIVE_MAX_INTERVALS", 0);

// MySQL connection details
define("FACEBOOK_DB_SERVER", "localhost");
define("FACEBOOK_DB_USERNAME", "facebook");
define("FACEBOOK_DB_PASSWORD", "XXXX");
define("FACEBOOK_DB_NAME", "facebook");

// default local timezone
define("FACEBOOK_TIMEZONE", "Australia/Sydney");

?>