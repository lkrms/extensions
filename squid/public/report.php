<?php

define("SQUID_ROOT", dirname(__file__) . "/..");
require_once (SQUID_ROOT . "/common.php");

if ( ! $isSecure)
{
    exit;
}

if ( ! defined("SQUID_SUPPORT_EMAIL"))
{
    exit ("Unable to report this website. Please contact an administrator.");
}

$lines    = array();
$headers  = array();

// retrieve particulars from the submission
$url         = _post("url");
$incidentId  = _post("incident_id");
$username    = _post("username");
$ipAddress   = _post("ip_address");

if ( ! $url)
{
    exit ("No web address provided.");
}

$lines[] = "URL: $url";

if ($incidentId)
{
    $lines[] = "Incident ID: $incidentId";
}

if ($username)
{
    $lines[] = "Username: $username";

    if ( ! SQUID_LDAP_USERNAME_REGEX || preg_match(SQUID_LDAP_USERNAME_REGEX, $username))
    {
        $headers[] = "From: $username@" . SQUID_EMAIL_DOMAIN;
    }
}

if ($ipAddress)
{
    $lines[] = "IP address: $ipAddress";
}

mail(SQUID_SUPPORT_EMAIL, "Website unblock request (one-click)", implode("\r\n", $lines), implode("\r\n", $headers));

// provide a happy green message :)
$feedback = "<p style='color:#008000'>Thank you for reporting this blocked website. Your request will be reviewed shortly.</p>"

?>
<html>
<head>
    <title><?php echo SQUID_AUTH_TITLE; ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1" />
</head>
<body>
    <?php

print $feedback;

?>
</body>
</html>
