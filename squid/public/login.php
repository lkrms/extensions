<?php

define("SQUID_ROOT", dirname(__file__) . "/..");
require_once (SQUID_ROOT . "/common.php");

function _get($name, $default = "")
{
    if (isset($_POST[$name]))
    {
        return $_POST[$name];
    }
    else
    {
        return "";
    }
}

$loggedIn  = false;
$un        = "visitor";
$redirect  = _get("redirect", isset($_GET["r"]) ? $_GET["r"] : SQUID_DEFAULT_REDIRECT);

// determine the client's IP and MAC addresses
$srcIP    = $_SERVER["REMOTE_ADDR"];
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
    exit ("Unable to determine hardware address.");
}

// now, check for an active session in the database
$conn = new mysqli(SQUID_BYOD_DB_SERVER, SQUID_BYOD_DB_USERNAME, SQUID_BYOD_DB_PASSWORD, SQUID_BYOD_DB_NAME);

if (mysqli_connect_error())
{
    exit ("Unable to connect to session database. " . mysqli_connect_error());
}

$rs = $conn->query("select username from auth_sessions where mac_address = '$mac' and ip_address = '$srcIP' and expiry_time_utc > UTC_TIMESTAMP()");

if ($rs && ($row = $rs->fetch_row()))
{
    $loggedIn  = true;
    $un        = $row[0];
}

$errors    = array();
$feedback  = "";

if ( ! $loggedIn && $_SERVER["REQUEST_METHOD"] == "POST")
{
    if (SQUID_LDAP_USERNAME_REGEX && ! preg_match(SQUID_LDAP_USERNAME_REGEX, _get("username")))
    {
        $errors[] = "Invalid username.";
    }

    if (SQUID_LDAP_PASSWORD_REGEX && ! preg_match(SQUID_LDAP_PASSWORD_REGEX, _get("password")))
    {
        $errors[] = "Invalid password.";
    }

    if ( ! $errors)
    {
        $un  = _get("username");
        $pw  = _get("password");
        $ad  = ldap_connect(SQUID_LDAP_SERVER);

        if ($ad !== false && @ldap_bind($ad, $un . SQUID_LDAP_USERNAME_APPEND, $pw))
        {
            $sessionTime = SQUID_BYOD_SESSION_DURATION;

            // create a session record
            if ($conn->query("insert into auth_sessions (username, mac_address, ip_address, auth_time_utc, expiry_time_utc) values ('" . $conn->escape_string($un) . "', '$mac', '$srcIP', UTC_TIMESTAMP(), ADDTIME(UTC_TIMESTAMP(), '$sessionTime'))") === false)
            {
                $errors[] = "Unable to create session record in database.";
            }
            else
            {
                $loggedIn = true;
            }
        }
        else
        {
            $errors[] = "Invalid username or password.";
        }
    }

    if ($errors)
    {
        $feedback = "<p style='color:#f00'>" . implode("<br />", $errors) . "<br />Please try again.</p>";
    }
}

$conn->close();

if ($loggedIn)
{
    $feedback = "<p style='color:#008000'>You are logged in as <strong>$un.</strong> You will now be redirected to the page you originally requested.</p>";
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
    <?php print "<form method=\"post\" action=\"$_SERVER[REQUEST_URI]\">"; ?>
    <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($redirect); ?>">
    <p>Username: <input type="text" name="username" size="30" value="<?php echo htmlspecialchars($un); ?>"></p>
    <p>Password: <input type="password" name="password" size="30"></p>
    <p><input type="submit" name="submit" value="Login" /></p>
    <?php print "</form>";
endif;

?>
</body>
</html>
<?php

// PRETTY_NESTED_ARRAYS,0

?>