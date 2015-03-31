<?php

define("LDAP_ROOT", dirname(__file__) . "/..");
require_once (LDAP_ROOT . "/common.php");

// die silently if this isn't a secure request
if ($_SERVER["SERVER_NAME"] != "localhost" && empty($_SERVER["HTTPS"]))
{
    exit;
}

$errors      = array();
$feedback    = "";
$ldap_error  = "";
$ldap_errno  = null;
$un          = _get("username");
$pw          = _get("password");
$tun         = _get("target_username");

if ($_SERVER["REQUEST_METHOD"] == "POST")
{
    // before we do anything
    if (LDAP_USERNAME_REGEX)
    {
        if ( ! preg_match(LDAP_USERNAME_REGEX, $un))
        {
            $errors[] = "Invalid username &ldquo;{$un}&rdquo;.";
        }

        if ( ! preg_match(LDAP_USERNAME_REGEX, $tun))
        {
            $errors[] = "Invalid username &ldquo;{$tun}&rdquo;.";
        }
    }

    if (LDAP_PASSWORD_REGEX && ! preg_match(LDAP_PASSWORD_REGEX, _get("password")))
    {
        $errors[]  = "Invalid password.";
        $pw        = "";
    }

    if ( ! $errors)
    {
        $ad = @ldap_connect("ldaps://" . LDAP_SERVER);

        if ($ad === false)
        {
            $errors[]    = "Unable to establish secure connection with LDAP server.";
            $ldap_error  = ldap_error($ad);
            $ldap_errno  = ldap_errno($ad);
        }
        else
        {
            if (@ldap_bind($ad, $un . '@' . LDAP_DOMAIN, $pw))
            {
                // authentication succeeded; now to look up our target user
                if ( ! $ls = @ldap_search($ad, LDAP_BASE_DN, "(sAMAccountName=$tun)", array("displayName", "mail")))
                {
                    $errors[]    = "Unable to request data from LDAP server.";
                    $ldap_error  = ldap_error($ad);
                    $ldap_errno  = ldap_errno($ad);
                    @ldap_unbind($ad);
                }
                else
                {
                    $le = @ldap_get_entries($ad, $ls);

                    if ( ! $le)
                    {
                        $errors[]    = "Unable to retrieve data from LDAP server.";
                        $ldap_error  = ldap_error($ad);
                        $ldap_errno  = ldap_errno($ad);
                        @ldap_unbind($ad);
                    }
                    else
                    {
                        $dn           = $le[0]["dn"];
                        $displayName  = $le[0]["displayname"][0];
                        $email        = $le[0]["mail"][0];
                        $npw          = createPassword();

                        // Active Directory is more likely to support unicodePwd than than userPassword
                        $npw_encoded  = mb_convert_encoding('"' . $npw . '"', "UTF-16LE");
                        $attributes   = array("unicodePwd" => $npw_encoded);

                        if (@ldap_mod_replace($ad, $dn, $attributes))
                        {
                            $feedback .= "<p style='color:#090'>Password successfully reset for $displayName. New password:</p>";
                            $feedback .= "<h3 style='color:#090'>$npw</h3>";

                            if ($email)
                            {
                                mail("$displayName <$email>", "Your account password was just reset", "Hi $displayName,

Your new password for your account ($tun) is: $npw

It was reset by: $un

Thank you!");
                            }

                            $tun = "";
                        }
                        else
                        {
                            $errors[]    = "Unable to reset password for $displayName. You may not be authorised for this operation.";
                            $ldap_error  = ldap_error($ad);
                            $ldap_errno  = ldap_errno($ad);
                        }

                        @ldap_unbind($ad);
                    }
                }
            }
            else
            {
                $errors[]    = "Invalid username and/or password.";
                $ldap_error  = ldap_error($ad);
                $ldap_errno  = ldap_errno($ad);
            }
        }
    }

    if ($errors)
    {
        $feedback .= "<p style='color:#f00'>" . implode("<br />", $errors) . "<br />Please try again.</p>";

        if (LDAP_SHOW_ERROR_DETAILS && ! is_null($ldap_errno))
        {
            $feedback .= "<p style='color:#f00'><small>{$ldap_errno}: {$ldap_error}</small></p>";
        }
    }
}

?>
<html>
<head>
    <title>Reset Password</title>
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1" />
</head>
<body>
    <?php print $feedback; ?>
    <h3>Reset Password</h3>
    <p>All fields are required.</p>
    <?php print "<form method=\"post\" action=\"$_SERVER[REQUEST_URI]\">"; ?>
    <p>My username:<br />
        <input type="text" name="username" size="30" placeholder="e.g. j.smith" value="<?php print htmlspecialchars($un); ?>"></p>
    <p>My password:<br />
        <input type="password" name="password" size="30" value="<?php print htmlspecialchars($pw); ?>"></p>
    <p>Username to reset:<br />
        <input type="text" name="target_username" size="30" placeholder="e.g. tommy.jones" value="<?php print htmlspecialchars($tun); ?>"></p>
    <p><input type="submit" name="submit" value="Reset password" /></p>
    <?php print "</form>"; ?>
</body>
</html>
<?php

// PRETTY_NESTED_ARRAYS,0

?>