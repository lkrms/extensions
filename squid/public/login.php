<?php

define("SQUID_ROOT", dirname(__file__) . "/..");
require_once (SQUID_ROOT . "/common.php");
$loggedIn          = false;
$deviceRegistered  = false;
$pingFirst         = false;
$un                = "visitor";

// enforce a secure connection
if ( ! $isSecure && ! isset($_GET["nossl"]))
{
    header("Location: https://{$_SERVER[SERVER_NAME]}{$_SERVER[REQUEST_URI]}");
    exit;
}

$redirect = SQUID_DEFAULT_REDIRECT;

if ( ! $isPost && isset($_GET["r"]))
{
    // ignore non-HTTP redirects
    $port = parse_url($_GET["r"], PHP_URL_PORT);

    if (is_null($port) || $port == 80)
    {
        $redirect = $_GET["r"];
    }
}

$redirect = _post("redirect", $redirect);

// determine the client's IP and MAC addresses
$srcIP = $_SERVER["REMOTE_ADDR"];

if (function_exists("apache_request_headers"))
{
    $headers = apache_request_headers();

    if (isset($headers["X-Forwarded-For"]))
    {
        $srcIP = $headers["X-Forwarded-For"];
    }
}
elseif (isset($_SERVER["HTTP_X_FORWARDED_FOR"]))
{
    $srcIP = $_SERVER["HTTP_X_FORWARDED_FOR"];
}

if ($srcIP != $_SERVER["REMOTE_ADDR"])
{
    $pingFirst  = true;
    $srcIP      = array_pop(explode(",", $srcIP));
}

$srcIP = trim($srcIP);

if (is_array($SQUID_ILLEGAL_IP) && in_array($srcIP, $SQUID_ILLEGAL_IP))
{
    if ( ! $isSecure)
    {
        exit ("Unable to authenticate from your IP address. Have you added $_SERVER[SERVER_NAME] to your 'bypass proxy for these addresses' list?");
    }
    else
    {
        $queryString = "nossl=1";

        if ($_SERVER["QUERY_STRING"])
        {
            $queryString .= "&" . $_SERVER["QUERY_STRING"];
        }

        header("Location: http://{$_SERVER[SERVER_NAME]}{$_SERVER[PHP_SELF]}?{$queryString}");
        exit;
    }
}

// force population of ARP table
if ($pingFirst)
{
    shell_exec("ping -c 1 $srcIP");
}

$arp      = shell_exec(SQUID_ARP_PATH . " -n $srcIP");
$mac      = "";
$matches  = array();

if (preg_match("/(([0-9a-f]{1,2}:){5}[0-9a-f]{1,2})/i", $arp, $matches))
{
    // ensure the MAC address is 17 characters long (OS X hosts don't add leading zeroes)
    $macBytes = explode(":", strtolower($matches[0]));

    foreach ($macBytes as $macByte)
    {
        if ($mac)
        {
            $mac .= ":";
        }

        if (strlen($macByte) == 2)
        {
            $mac .= $macByte;
        }
        else
        {
            $mac .= "0$macByte";
        }
    }
}
else
{
    exit ("Unable to determine your hardware address. Are you on the right network?");
}

// now, check for a device record or active session in the database
$conn = new mysqli(SQUID_DB_SERVER, SQUID_DB_USERNAME, SQUID_DB_PASSWORD, SQUID_DB_NAME);

if (mysqli_connect_error())
{
    exit ("Unable to connect to session database. " . mysqli_connect_error());
}

$servers = is_array($SQUID_PM_DB) ? array_keys($SQUID_PM_DB) : array();

// device records take priority over transient sessions
$rs = $conn->query("select username from user_devices where mac_address = '$mac' and " . ($servers ? "(server_name in ('" . implode("', '", $servers) . "') or server_name is null)" : "server_name is null"));

if ($rs && ($row = $rs->fetch_row()))
{
    $loggedIn          = true;
    $deviceRegistered  = true;
    $un                = $row[0];
}
else
{
    $rs = $conn->query("select username from auth_sessions where mac_address = '$mac' and ip_address = '$srcIP' and expiry_time_utc > UTC_TIMESTAMP()");

    if ($rs && ($row = $rs->fetch_row()))
    {
        $loggedIn  = true;
        $un        = $row[0];
    }
}

$errors    = array();
$feedback  = "";

if ( ! $loggedIn && $isPost)
{
    if (SQUID_LDAP_USERNAME_REGEX && ! preg_match(SQUID_LDAP_USERNAME_REGEX, _post("username")))
    {
        $errors[] = "Invalid username.";
    }

    if (SQUID_LDAP_PASSWORD_REGEX && ! preg_match(SQUID_LDAP_PASSWORD_REGEX, _post("password")))
    {
        $errors[] = "Invalid password.";
    }

    if ( ! $errors)
    {
        $un        = _post("username");
        $pw        = _post("password");
        $register  = _post("register_device") == 1;
        $ad        = ldap_connect(SQUID_LDAP_SERVER);

        if ($ad !== false && @ldap_bind($ad, $un . SQUID_LDAP_USERNAME_APPEND, $pw))
        {
            $allowed      = true;
            $sessionTime  = SQUID_DEFAULT_SESSION_DURATION;

            if ( ! empty($SQUID_LDAP_GROUP_PERMISSIONS))
            {
                $allowed  = false;
                $groups   = getUserGroups($un, true, false);

                foreach ($SQUID_LDAP_GROUP_PERMISSIONS as $groupDN => $groupPermissions)
                {
                    if (in_array($groupDN, $groups) && (( ! $register && $groupPermissions["ALLOW_SESSION"]) || ($register && $groupPermissions["ALLOW_DEVICE_REGISTRATION"])))
                    {
                        $allowed = true;

                        if ( ! $register && isset($groupPermissions["SESSION_DURATION"]))
                        {
                            $sessionTime = $groupPermissions["SESSION_DURATION"];
                        }

                        break;
                    }
                }
            }

            if ($allowed)
            {
                if ($register)
                {
                    $sql = "insert into user_devices (mac_address, username, auth_time_utc) values ('$mac', '" . $conn->escape_string($un) . "', UTC_TIMESTAMP())";
                }
                else
                {
                    $sql = "insert into auth_sessions (username, mac_address, ip_address, auth_time_utc, expiry_time_utc) values ('" . $conn->escape_string($un) . "', '$mac', '$srcIP', UTC_TIMESTAMP(), ADDTIME(UTC_TIMESTAMP(), '$sessionTime'))";
                }

                // create a session record
                if ($conn->query($sql) === false)
                {
                    $errors[] = "Unable to create " . ($register ? "device" : "session") . " record in database.";
                }
                else
                {
                    $loggedIn = true;
                }
            }
            else
            {
                $errors[] = "Your username and password are correct, but you're not authorised to " . ($register ? "register devices" : "log in") . " using this service. <a href='" . SQUID_SUPPORT_URL . "'>Click here to request help with this issue.</a>";
            }
        }
        else
        {
            $errors[] = "Invalid username or password.";
        }
    }
}

if ($errors)
{
    $feedback .= "<p style='color:#f00'>" . implode("<br />", $errors) . "</p>";
}

$conn->close();

if ($loggedIn)
{
    $feedback = "<p style='color:#008000'>You are logged in as <strong>$un</strong> on this device ($mac). You will now be redirected to the page you originally requested.</p>";
}

?>
<html>
<head>
    <title><?php echo SQUID_AUTH_TITLE; ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1" />
<?php

if ($loggedIn && $redirect)
{
    print '<meta http-equiv="refresh" content="5;URL=\'' . $redirect . '\'" />';
}

?>
</head>
<body>
    <?php

print $feedback;

if ( ! $loggedIn):
?>
    <h3><?php echo SQUID_AUTH_TITLE; ?></h3>
    <p>By logging in, you agree not to use this service for any inappropriate or illegal purpose.</p>
    <p>Need help? <a href="<?php echo SQUID_SUPPORT_URL; ?>">Click here for support.</a></p>
    <h3>User Login</h3>
    <?php
    if ( ! $isSecure):
?>
    <p style='color:#f00'>WARNING: your username and password will be sent over the network insecurely. To avoid this, add <strong><?php echo $_SERVER["SERVER_NAME"]; ?></strong> to your 'bypass proxy for these addresses' list.</p>
    <?php
    endif;
?>
    <?php print "<form method=\"post\" action=\"$_SERVER[REQUEST_URI]\">"; ?>
    <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($redirect); ?>">
    <p>Username: <input type="text" name="username" size="30" value="<?php echo htmlspecialchars($un); ?>"></p>
    <p>Password: <input type="password" name="password" size="30"></p>
    <p>Device identifier: <strong><?php echo $mac; ?></strong></p>
    <p><input type="checkbox" name="register_device" id="register_device" value="1"> <label for="register_device">I&rsquo;m the only person with access to this device. Register my login permanently.</label></p>
    <p><input type="submit" name="submit" value="Login" /></p>
    <?php print "</form>";
endif;

?>
</body>
</html>
<?php

// PRETTY_NESTED_ARRAYS,0

?>