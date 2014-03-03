<?php

// mysql only, sorry
$dbHost       = "localhost";
$dbDatabase   = "vpp";
$dbUser       = "vpp";
$dbPassword   = "PASSWORD";
$ldapServer   = "192.168.0.1";
$domain       = "mydomain.com";
$baseDn       = "OU=Users,DC=mydomain,DC=com";
$vppUrl       = "https://buy.itunes.apple.com/WebObjects/MZFinance.woa/wa/freeProductCodeWizard?code=";
$notifyEmail  = "root@localhost";
$emailDomain  = "mydomain.com";
$emailFrom    = "ICT Staff <ict@${emailDomain}>";
$companyName  = "My Own Company";
$helpUrl      = "http://helpdesk.mydomain.com";

// used to validate usernames and passwords prior to use
$usernameRegex  = '/^[a-z]+[\\.a-z]+$/i';
$passwordRegex  = '/^.{6,}$/';

?>