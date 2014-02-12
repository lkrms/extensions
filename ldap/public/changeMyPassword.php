<?php

define("LDAP_ROOT", dirname(__file__) . "/..");
require_once (LDAP_ROOT . "/common.php");

// die silently if this isn't a secure request
if (empty($_SERVER["HTTPS"]))
{
    exit;
}

$errors      = array();
$feedback    = "";
$ldap_error  = "";
$ldap_errno  = null;
$un          = _get("username");

if ($_SERVER["REQUEST_METHOD"] == "POST")
{
    // before we do anything
    if (LDAP_USERNAME_REGEX && ! preg_match(LDAP_USERNAME_REGEX, $un))
    {
        $errors[] = "Invalid username.";
    }

    if (LDAP_PASSWORD_REGEX)
    {
        if ( ! preg_match(LDAP_PASSWORD_REGEX, _get("password")))
        {
            $errors[] = "Current password invalid.";
        }

        if ( ! preg_match(LDAP_PASSWORD_REGEX, _get("new_password")))
        {
            $errors[] = "New password invalid.";
        }
    }

    if (_get("new_password") != _get("confirm_password"))
    {
        $errors[] = "New password fields don't match.";
    }

    if (_get("new_password") == LDAP_EXAMPLE_PASSWORD)
    {
        $errors[] = "You're not allowed to use the example password.";
    }

    if ( ! $errors)
    {
        $pw   = _get("password");
        $npw  = _get("new_password");
        $ad   = @ldap_connect("ldaps://" . LDAP_SERVER);

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
                // authentication succeeded; now to find the user's particulars
                if ( ! $ls = @ldap_search($ad, LDAP_BASE_DN, "(sAMAccountName=$un)", array("givenName", "sn")))
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
                        @ldap_unbind($ad);
                        $dn     = $le[0]["dn"];
                        $first  = $le[0]["givenname"][0];
                        $last   = $le[0]["sn"][0];

                        // Active Directory is more likely to support unicodePwd than than userPassword
                        $npw_encoded  = mb_convert_encoding('"' . $npw . '"', "UTF-16LE");
                        $attributes   = array("unicodePwd" => $npw_encoded);

                        if ( ! ($ad = @ldap_connect("ldaps://" . LDAP_SERVER)) || ! @ldap_bind($ad, LDAP_ADMIN_USER_DN, LDAP_ADMIN_USER_PW))
                        {
                            $errors[]    = "Unable to bind to LDAP server.";
                            $ldap_error  = ldap_error($ad);
                            $ldap_errno  = ldap_errno($ad);
                        }
                        else
                        {
                            if (@ldap_mod_replace($ad, $dn, $attributes))
                            {
                                $feedback .= "<p style='color:#090'>Thanks, $first. Your password was changed successfully.</p>";
                                $un        = "";
                            }
                            else
                            {
                                $errors[]    = "Unable to change your password, $first. Does your new password meet the criteria listed below?";
                                $ldap_error  = ldap_error($ad);
                                $ldap_errno  = ldap_errno($ad);
                            }

                            @ldap_unbind($ad);
                        }
                    }
                }
            }
            else
            {
                $errors[]    = "Invalid username and/or current password.";
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
    <title>Change My Password</title>
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1" />
</head>
<body>
    <?php print $feedback; ?>
    <h3>Change My Password</h3>
    <p>All fields are required.</p>
    <p>Your new password must meet the following criteria:</p>
    <ul>
        <li>Must contain 6 or more characters. <em>Longer passwords are better.</em></li>
        <li>Must contain at least 3 different character types, e.g. a lowercase letter, an uppercase letter and a number.</li>
        <li>Cannot contain any part of your name or username.</li>
    </ul>
    <p>Here's an example password that exceeds this criteria: <strong><?php print LDAP_EXAMPLE_PASSWORD; ?></strong> <em>(Don't use this one, though!)</em></p>
    <?php print "<form method=\"post\" action=\"$_SERVER[REQUEST_URI]\">"; ?>
    <p>Username: <input type="text" name="username" size="30" placeholder="e.g. johnny.smith" value="<?php print htmlspecialchars($un); ?>"></p>
    <p>Current password: <input type="password" name="password" size="30"></p>
    <p>New password: <input type="password" name="new_password" size="30"></p>
    <p>New password again: <input type="password" name="confirm_password" size="30"></p>
    <p><input type="submit" name="submit" value="Change password" /></p>
    <?php print "</form>"; ?>
</body>
</html>
<?php

// PRETTY_NESTED_ARRAYS,0

?>