<?php

define("FACEBOOK_ROOT", dirname(__file__) . "/..");
require_once (FACEBOOK_ROOT . "/common.php");

// yes, this is messy. no, I don't care.
function endOutput($html = "", $exit = true)
{
    echo $html . "
</body>
</html>
";

    if ($exit)
    {
        exit;
    }
}

// we're not going to be uploading content to Facebook, so fileUpload can be false
$fb = new Facebook( array(
    "appId"              => FACEBOOK_APP_ID,
    "secret"             => FACEBOOK_APP_SECRET,
    "fileUpload"         => false,
    "allowSignedRequest" => false,
));

if (isset($_GET["logout"]))
{
    $fb->destroySession();
    header("Location: $_SERVER[PHP_SELF]");
    exit;
}

if (isset($_POST["gid"]))
{
    $gid = $_POST["gid"];

    if ($gid + 0 == $gid && is_int($gid + 0))
    {
        // TODO: completely refactor this into a sustainable [i.e. offline] form
        $db = mysqli_connect(FACEBOOK_DB_SERVER, FACEBOOK_DB_USERNAME, FACEBOOK_DB_PASSWORD, FACEBOOK_DB_NAME);

        if (mysqli_connect_error())
        {
            endOutput('<p class="error">Unable to connect to the database. Please check the connection settings and try again.</p>');
        }

        $dbPost      = mysqli_prepare($db, "insert into posts (gid, post_id, user_id, created_time, updated_time, message, permalink, last_fetched) values (?, ?, ?, ?, ?, ?, ?, utc_timestamp()) on duplicate key update last_fetched = utc_timestamp(), fetch_count = fetch_count + 1, updated_time = values(updated_time), message = values(message), permalink = values(permalink)");
        $dbComment   = mysqli_prepare($db, "insert into comments (gid, comment_id, attached_to, attached_type, parent_id, user_id, created_time, message, last_fetched) values (?, ?, ?, ?, ?, ?, ?, ?, utc_timestamp()) on duplicate key update last_fetched = utc_timestamp(), fetch_count = fetch_count + 1, message = values(message)");
        $dbPhoto     = mysqli_prepare($db, "insert into photos (gid, photo_id, attached_to, attached_type, album_id, owner_id, height, width, src, src_ext, src_big, src_big_ext, caption, permalink) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) on duplicate key update last_fetched = utc_timestamp(), fetch_count = fetch_count + 1, album_id = values(album_id), height = values(height), width = values(width), src = values(src), src_ext = values(src_ext), src_big = values(src_big), src_big_ext = values(src_big_ext), caption = values(caption), permalink = values(permalink)");
        $dbPhotoSrc  = mysqli_prepare($db, "update photos set height = ?, width = ?, src_big = ?, src_big_ext = ? where photo_id = ?");
        $dbUser      = mysqli_prepare($db, "insert into users (user_id, first_name, last_name, name, last_fetched) values (?, ?, ?, ?, utc_timestamp()) on duplicate key update last_fetched = utc_timestamp(), fetch_count = fetch_count + 1, first_name = values(first_name), last_name = values(last_name), name = values(name)");
        $dbInterval  = mysqli_prepare($db, "insert into intervals (gid, job_id, interval_start, interval_stop, fetch_start, fetch_stop, posts, comments, photos) values (?, ?, ?, ?, ?, ?, ?, ?, ?)");

        if ( ! $dbPost || ! $dbComment || ! $dbPhoto || ! $dbPhotoSrc || ! $dbUser || ! $dbInterval)
        {
            endOutput('<p class="error">Unable to prepare database statements.</p>');
        }

        $bindResult  = mysqli_stmt_bind_param($dbPost, "isissss", $_gid, $_post_id, $_user_id, $_created_time, $_updated_time, $_message, $_permalink);
        $bindResult  = mysqli_stmt_bind_param($dbComment, "issssiss", $_gid, $_comment_id, $_attached_to, $_attached_type, $_parent_id, $_user_id, $_created_time, $_message) && $bindResult;
        $bindResult  = mysqli_stmt_bind_param($dbPhoto, "issssiiissssss", $_gid, $_photo_id, $_attached_to, $_attached_type, $_album_id, $_owner_id, $_height, $_width, $_src, $_src_ext, $_src_big, $_src_big_ext, $_caption, $_permalink) && $bindResult;
        $bindResult  = mysqli_stmt_bind_param($dbPhotoSrc, "iisss", $_height, $_width, $_src_big, $_src_big_ext, $_photo_id) && $bindResult;
        $bindResult  = mysqli_stmt_bind_param($dbUser, "isss", $_user_id, $_first_name, $_last_name, $_name) && $bindResult;
        $bindResult  = mysqli_stmt_bind_param($dbInterval, "iissssiii", $_gid, $_job_id, $_interval_start, $_interval_stop, $_fetch_start, $_fetch_stop, $_posts, $_comments, $_photos) && $bindResult;

        if ( ! $bindResult)
        {
            endOutput('<p class="error">Unable to bind database statement parameters.</p>');
        }

        $_gid = $gid + 0;

        // create a job, get a job number
        mysqli_query($db, "insert into jobs (gid, start_time) values ($gid, utc_timestamp())");
        $_job_id = mysqli_insert_id($db);

        // we'll be working backwards from 60 seconds ago
        $stop   = 1388534400 + (floor((time() - 1388534400) / FACEBOOK_ARCHIVE_INTERVAL) + 1) * FACEBOOK_ARCHIVE_INTERVAL;
        $start  = $stop - FACEBOOK_ARCHIVE_INTERVAL;
        $i      = 0;

        do
        {
            $post_pids  = array();
            $pids       = array();
            $uids       = array();

            // only for auditing purposes
            $postCount     = 0;
            $commentCount  = 0;
            $photoCount    = 0;
            $fetchStart    = time();

            try
            {
                // first, we fetch all posts made during this archive interval
                $posts = $fb->api( array(
    "query"  => "select post_id, actor_id, created_time, updated_time, message, permalink, attachment from stream where source_id = $gid and created_time >= $start and created_time < $stop limit 100000",
    "method" => "fql.query"
));
                $_attached_type  = "post";
                $_src_big        = null;
                $_src_big_ext    = null;

                foreach ($posts as $post)
                {
                    // then, we load each of them into the "posts" table
                    $_post_id       = $post["post_id"];
                    $_user_id       = $post["actor_id"];
                    $_created_time  = dbDateTime($post["created_time"]);
                    $_updated_time  = dbDateTime($post["updated_time"]);
                    $_message       = $post["message"] ? $post["message"] : null;
                    $_permalink     = $post["permalink"];
                    mysqli_stmt_execute($dbPost);
                    $uids[] = $_user_id;
                    $postCount++;

                    // and trawl for attached photos
                    if (isset($post["attachment"]["media"]))
                    {
                        foreach ($post["attachment"]["media"] as $media)
                        {
                            switch ($media["type"])
                            {
                                case "photo":

                                    $_photo_id     = $media["photo"]["fbid"];
                                    $_attached_to  = $post["post_id"];
                                    $_album_id     = $media["photo"]["aid"] ? $media["photo"]["aid"] : null;
                                    $_owner_id     = $media["photo"]["owner"];
                                    $_height       = $media["photo"]["height"];
                                    $_width        = $media["photo"]["width"];
                                    $_src          = $media["src"];
                                    $_src_ext      = getExt($media["src"]);
                                    $_caption      = $media["alt"];
                                    $_permalink    = $media["href"];
                                    mysqli_stmt_execute($dbPhoto);
                                    $post_pids[]  = $_photo_id;
                                    $pids[]       = $_photo_id;
                                    $uids[]       = $_owner_id;
                                    $photoCount++;

                                    break;
                            }
                        }
                    }
                }

                // next, we do the same with comments on those posts
                $comments = $fb->api( array(
    "query"  => "select id, post_id, parent_id, fromid, time, text, attachment from comment where post_id in (select post_id from stream where source_id = $gid and created_time >= $start and created_time < $stop limit 100000) limit 100000",
    "method" => "fql.query"
));

                foreach ($comments as $comment)
                {
                    $_comment_id     = $comment["id"];
                    $_attached_to    = $comment["post_id"];
                    $_attached_type  = "post";
                    $_parent_id      = $comment["parent_id"] == "0" ? null : $comment["parent_id"];
                    $_user_id        = $comment["fromid"];
                    $_created_time   = dbDateTime($comment["time"]);
                    $_message        = $comment["text"] ? $comment["text"] : null;
                    mysqli_stmt_execute($dbComment);
                    $uids[] = $_user_id;
                    $commentCount++;

                    // comment attachments are handled a bit differently
                    if (isset($comment["attachment"]["media"]))
                    {
                        switch ($comment["attachment"]["type"])
                        {
                            case "photo":

                                $_photo_id       = $comment["attachment"]["target"]["id"];
                                $_attached_to    = $comment["id"];
                                $_attached_type  = "comment";
                                $_album_id       = null;
                                $_owner_id       = $comment["fromid"];
                                $_height         = $comment["attachment"]["media"]["image"]["height"];
                                $_width          = $comment["attachment"]["media"]["image"]["width"];
                                $_src            = $comment["attachment"]["media"]["image"]["src"];
                                $_src_ext        = getExt($comment["attachment"]["media"]["image"]["src"]);
                                $_caption        = null;
                                $_permalink      = $comment["attachment"]["url"];
                                mysqli_stmt_execute($dbPhoto);
                                $pids[] = $_photo_id;
                                $photoCount++;

                                break;
                        }
                    }
                }

                // now for some big, juicy, hi-res JPEGs (or their URLs, anyway)
                if ($pids)
                {
                    $photos = $fb->api( array(
    "query"  => "select object_id, images from photo where object_id in ('" . implode("', '", array_unique($pids)) . "')",
    "method" => "fql.query"
));

                    foreach ($photos as $photo)
                    {
                        $_photo_id     = $photo["object_id"];
                        $_height       = $photo["images"][0]["height"];
                        $_width        = $photo["images"][0]["width"];
                        $_src_big      = $photo["images"][0]["source"];
                        $_src_big_ext  = getExt($photo["images"][0]["source"]);
                        mysqli_stmt_execute($dbPhotoSrc);
                    }
                }

                // and finally, user names
                if ($uids)
                {
                    $users = $fb->api( array(
    "query"  => "select uid, first_name, last_name, name from user where uid in (" . implode(", ", array_unique($uids)) . ")",
    "method" => "fql.query"
));

                    foreach ($users as $user)
                    {
                        $_user_id     = $user["uid"];
                        $_first_name  = $user["first_name"] ? $user["first_name"] : null;
                        $_last_name   = $user["last_name"] ? $user["last_name"] : null;
                        $_name        = $user["name"] ? $user["name"] : null;
                        mysqli_stmt_execute($dbUser);
                    }
                }
            }
            catch (FacebookApiException $e)
            {
                // TODO: more smartness here
                throw $e;
            }

            $_interval_start  = dbDateTime($start);
            $_interval_stop   = dbDateTime($stop);
            $_fetch_start     = dbDateTime($fetchStart);
            $_fetch_stop      = dbDateTime(time());
            $_posts           = $postCount ? $postCount : null;
            $_comments        = $commentCount ? $commentCount : null;
            $_photos          = $photoCount ? $photoCount : null;
            mysqli_stmt_execute($dbInterval);

            // move to the next interval
            $start -= FACEBOOK_ARCHIVE_INTERVAL;
            $stop  -= FACEBOOK_ARCHIVE_INTERVAL;
            $i++;
        }
        while ((count($posts) || $i == 1) && (FACEBOOK_ARCHIVE_MAX_INTERVALS == 0 || $i < FACEBOOK_ARCHIVE_MAX_INTERVALS));
    }
}

// used if we're not authenticated, or have insufficient permissions to proceed
$loginParams = array(
    "scope" => "user_groups"
);

$loginUrl    = $fb->getLoginUrl($loginParams);
$loginHtml   = '<p><a href="' . $loginUrl . '">Click here to log in with Facebook.</a></p>';
$logoutUrl   = $_SERVER["PHP_SELF"] . "?logout=1";
$logoutHtml  = '<a href="' . $logoutUrl . '">Logout</a>';

?>
<html>
<head>
<title>fb.group.downloader</title>
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1" />
</head>
<body>
<h1>fb.group.downloader</h1>
<?php

if ( ! ($userId = $fb->getUser()))
{
    endOutput("<p>To get started, you'll need to give us permission to access your Facebook account.</p>" . $loginHtml);
}

try
{
    $userProfile = $fb->api("/me", "GET");
}
catch (FacebookApiException $e)
{
    endOutput("<p>Unfortunately your Facebook login seems to have expired.</p>" . $loginHtml);
}

$firstName  = $userProfile["first_name"];
$lastName   = $userProfile["last_name"];
$fullName   = $userProfile["name"];

// a header of sorts
echo "<p>You're logged in as <strong>$fullName.</strong> $logoutHtml</p>";

try
{
    $rows = $fb->api( array(
    "query"  => "select gid, name from group where gid in (select gid from group_member where uid = me()) order by name",
    "method" => "fql.query"
));
}
catch (FacebookApiException $e)
{
    endOutput('<p class="error">Unable to retrieve information about your groups. Please try again.</p>');
}

echo "<form method=\"post\" action=\"$_SERVER[PHP_SELF]\">";
echo "<p>Please select a group to archive:</p><p>";

foreach ($rows as $row)
{
    echo "<input type=\"radio\" name=\"gid\" id=\"gid$row[gid]\" value=\"$row[gid]\" /> <label for=\"gid$row[gid]\">$row[name]</label><br />";
}

echo "</p><p><input type=\"submit\" name=\"submit\" value=\"Archive\" /></p>";
echo "</form>";
endOutput();

?>