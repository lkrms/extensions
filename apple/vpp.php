<?php

/**
 * Apple VPP Code Distribution Service
 *
 */

// load settings
define("VPP_ROOT", dirname(__file__));
require_once (VPP_ROOT . "/vpp.config.php");

// actually fetches $_POST values
function _get($name, $default = "")
{
    if (isset($_POST[$name]))
    {
        return $_POST[$name];
    }
    else
    {
        return $default;
    }
}

date_default_timezone_set("Australia/Sydney");

// no database abstraction, sorry
$conn      = new mysqli($dbHost, $dbUser, $dbPassword, $dbDatabase);
$feedback  = "";

if ($_SERVER["REQUEST_METHOD"] == "POST")
{
    $errors = array();

    if ( ! preg_match($usernameRegex, _get("username")))
    {
        $errors[] = "Invalid username.";
    }

    if ( ! preg_match($passwordRegex, _get("password")))
    {
        $errors[] = "Invalid password.";
    }

    $apps = _get("apps", array());

    // validate our app ID's too. one can never be too safe ;)
    if ( ! $apps)
    {
        $errors[] = "No apps selected!";
    }
    else
    {
        foreach ($apps as $appId)
        {
            if ( ! (is_numeric($appId) && is_int(0 + $appId)))
            {
                $errors[] = "Invalid app selection. Bad you!";
            }
        }
    }

    if ( ! $errors)
    {
        $un  = _get("username");
        $pw  = _get("password");
        $ad  = ldap_connect($ldapServer);

        if ($ad !== false && @ldap_bind($ad, $un . '@' . $domain, $pw))
        {
            $ls = ldap_search($ad, $baseDn, "(sAMAccountName=$un)", array("memberOf"));

            if ( ! $ls)
            {
                ldap_unbind($ad);
                $errors[] = "Unable to look up your group membership.";
            }
            else
            {
                $le = ldap_get_entries($ad, $ls);
                ldap_unbind($ad);
                $groups = $le[0]["memberof"];
                unset($groups["count"]);

                // now our groups are ready to cross-check with app requests. let's do this!
                $rs = $conn->query("select product.id, product.name, group_concat(user_group.dn separator '\\n') as eligible_groups, vpp_code.code
from product
    inner join product_group on product.id = product_group.product_id
    inner join user_group on product_group.group_dn = user_group.dn
    left join vpp_code on product.id = vpp_code.product_id and vpp_code.assigned_username = '$un'
where product.id in (" . implode(",", $apps) . ")
group by product.id, product.name
order by product.name");

                // first, figure out which apps we're eligible for
                $mailBody  = array();
                $toIssue   = array();

                while ($app = $rs->fetch_row())
                {
                    // we've already been issued with a code for this app
                    if ( ! is_null($app[3]))
                    {
                        $mailBody[]  = "You have already received the following code for $app[1]:";
                        $mailBody[]  = $app[3];
                        $mailBody[]  = $vppUrl . $app[3];
                        $mailBody[]  = "";

                        continue;
                    }

                    $eligible = explode("\n", $app[2]);

                    foreach ($eligible as $eligible_dn)
                    {
                        if (in_array($eligible_dn, $groups))
                        {
                            $toIssue[] = $app[0];

                            continue 2;
                        }
                    }

                    $mailBody[]  = "You are not eligible for $app[1]. Sorry!";
                    $mailBody[]  = "";
                }

                $rs->close();

                // next, issue codes (if eligible and available)
                $mailBodyStage1  = implode("\n", $mailBody);
                $mailBody        = array();

                // start building out the email body while we're at it
                $mailBody[]  = "Hi,";
                $mailBody[]  = "";
                $mailBody[]  = "Please see below for the results of your recent app request. You can redeem codes manually (using the Redeem option in Apple's App Store), or by following the provided link.";
                $mailBody[]  = "";
                $mailBody[]  = "Enjoy!";
                $mailBody[]  = "";
                $mailBody[]  = "===";
                $mailBody[]  = "";

                if ($toIssue)
                {
                    $codesIssued  = array();
                    $rs           = $conn->query("select product.id, product.name, min(vpp_code.code) as code
from product
    left join vpp_code on product.id = vpp_code.product_id and vpp_code.assigned_username is null
where product.id in (" . implode(",", $toIssue) . ")
group by product.id, product.name
order by product.name");

                    while ($app = $rs->fetch_row())
                    {
                        if ( ! is_null($app[2]))
                        {
                            $codesIssued[]  = $app[2];
                            $mailBody[]     = "Here's your redemption code for $app[1]:";
                            $mailBody[]     = $app[2];
                            $mailBody[]     = $vppUrl . $app[2];
                            $mailBody[]     = "";
                        }
                        else
                        {
                            $mailBody[]  = "Unfortunately we're all out of redemption codes for $app[1] at the moment. We'll be in touch when we have more codes available.";
                            $mailBody[]  = "";
                        }
                    }

                    $rs->close();

                    if ($codesIssued)
                    {
                        $conn->query("update vpp_code
set assigned_username = '$un', assigned_time = now()
where code in ('" . implode("','", $codesIssued) . "')");
                    }
                }

                $mailBody = implode("\n", $mailBody) . "\n" . $mailBodyStage1;

                // send the all-important email
                mail("$un@$emailDomain", "Your app request", $mailBody, "From: $emailFrom\r\nBcc: $notifyEmail");
                $feedback = "<p style='color:#008000'>Your app request has been processed. Please check your email.</p>";
            }
        }
        else
        {
            $errors[] = "Invalid username or password.";
        }
    }

    if ($errors)
    {
        $feedback .= "<p style='color:#f00'>" . implode("<br />", $errors) . "<br />Please press the back button on your browser and try again.</p>";
    }
}

?>
<html>
<head>
    <title>Apple VPP Code Distribution Service - <?php echo $companyName; ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1" />
</head>
<body>
    <?php print $feedback; ?>
    <h3>Apple VPP Code Distribution Service - <?php echo $companyName; ?></h3>
    <p>Here's how it works:</p>
    <ol>
        <li>Enter your <?php echo $companyName; ?> username and password.</li>
        <li>Tick any number of boxes to request apps (but please don't request apps you already own or don't need - you can always grab them later).</li>
        <li>Hit "Request apps". You'll immediately be emailed with codes you can redeem in the App Store.</li>
    </ol>
    <p>It's OK to request the same app multiple times, but you'll get the same single-use code each time. This can be handy if you delete the email with your codes in it before you've had a chance to redeem them!</p>
    <p>If you accidentally request an app you're not eligible for, don't worry, a code for that app won't be issued to you.</p>
    <p>Having trouble? <a href="<?php echo $helpUrl; ?>">Help is only a click away.</a></p>
    <p>Enjoy!</p>
    <h3>App Request Form</h3>
    <?php print "<form method=\"post\" action=\"$_SERVER[REQUEST_URI]\">"; ?>
    <p>Username: <input type="text" name="username" size="30"></p>
    <p>Password: <input type="password" name="password" size="30"></p>
    <p><em>Apps requested:</em></p>
    <?php

$rs = $conn->query("select product.id, product.name, group_concat(user_group.name order by user_group.name separator ', ') as eligible_names
        from product
        inner join product_group on product.id = product_group.product_id
        inner join user_group on product_group.group_dn = user_group.dn
        group by product.id, product.name
        order by product.name");

while ($app = $rs->fetch_row())
{
    print "<p><input type='checkbox' name='apps[]' id='app$app[0]' value='$app[0]' /> <label for='app$app[0]'>$app[1] (for $app[2])</label></p>";
}

$rs->close();

?>
    <p><input type="submit" name="submit" value="Request apps" /></p>
    <?php print "</form>"; ?>
</body>
</html>
<?php

$conn->close();

// PRETTY_NESTED_ARRAYS,0

?>