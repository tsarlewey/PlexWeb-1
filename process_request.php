<?php
/// <summary>
/// The main class for processing requests. Monolithic, but IMO better than a bunch of different php files
/// 
/// The only required field is 'type', everything else is dependant on the specified type
/// </summary>

require_once "includes/common.php";
require_once "includes/config.php";

$type = param_or_die('type');

// For requests that are only made when not logged in, don't session_start or verify login state
switch ($type)
{
    case "check_username":
    case "login":
    case "register":
        break;
    default:
        session_start();
        verify_loggedin();
        break;
}

// pr === permission_request
switch ($type)
{
    case "login":
        $error = "";
        if (!login($error))
        {
            json_error_and_exit($error);
        }
        json_success();
    case "register":
        $error = "";
        if (!register($error))
        {
            json_error_and_exit($error);
        }
        json_success();
    case "request":
        process_suggestion();
        json_success();
    case "pr":
        process_permission_request();
        return;
    case "req_update":
        process_request_update();

        // If we've returned here we succeeded. '1' === success for req_update
        echo "1";
        return;
    case "set_usr_info":
        $error = "";
        if (!update_user_settings(
            param_or_json_exit('fn'),
            param_or_json_exit('ln'),
            param_or_json_exit('e'),
            param_or_json_exit('ea'),
            param_or_json_exit('p'),
            param_or_json_exit('pa'),
            param_or_json_exit('c'),
            $error))
        {
            json_error_and_exit($error);
        }
        json_success();
    case "get_usr_info":
        $json = get_user_settings();
        header("Content-Type: application/json; charset-UTF=8");
        echo $json;
        return;
    case "check_username":
        $check = $db->real_escape_string(strtolower(param_or_die('username')));
        $result = $db->query("SELECT username FROM users where username_normalized='$check'");
        if (!$result || $result->num_rows !== 0)
        {
            json_message_and_exit('{ "value" : 0 }');
        }
        else
        {
            $result->close();
            json_message_and_exit('{ "value" : 1, "name" : "' . param_or_die('username') . '" }');
        }

    case "members":
        get_members();
        break;
    case "search":
        search();
        break;
    case "search_external":
        search_external();
        break;
    case "update_pass":
        $error = "";
        if (!update_password(param_or_json_exit("old_pass"), param_or_json_exit("new_pass"), param_or_json_exit("conf_pass"), $error))
        {
            json_error_and_exit($error);
        }
        json_success();
    case "geoip":
        $error = "";
        $response = get_geo_ip(param_or_die("ip"), $error);
        if (!$response)
        {
            json_error_and_exit($error);
        }
        json_message_and_exit($response);
    default:
        error_and_exit(400);
}

/// <summary>
/// Attempts to login, returning an error on failure
/// </summary>
function login(&$error)
{
    global $db;
    $username = trim(param_or_die("username"));
    $password = param_or_die("password");
    if (empty($username) || empty($password))
    {
        $error = "Username/password cannot be empty!";
        return FALSE;
    }

    $username = trim($username);
    $normalized = strtolower($username);

    $normalized = $db->real_escape_string($normalized);
    $query = "SELECT id, username, username_normalized, password, level FROM users WHERE username_normalized='$normalized'";
    $result = $db->query($query);
    if (!$result)
    {
        $error = "Unexpected server error. Please try again";
        return FALSE;
    }

    if ($result->num_rows === 0)
    {
        $error = "User does not exist. Would you like to <a href=register.php>register</a>?";
        return FALSE;
    }

    $row = $result->fetch_row();
    $result->close();
    $id = $row[0];
    $user = $row[1];
    $hashed_pass = $row[3];
    $level = $row[4];

    if (!password_verify($password, $hashed_pass))
    {
        $error = "Incorrect password!";
        return FALSE;
    }

    session_start();

    $ip = $db->real_escape_string($_SERVER['REMOTE_ADDR']);
    $user_agent = $db->real_escape_string($_SERVER['HTTP_USER_AGENT']);
    $query = "INSERT INTO logins (userid, ip, user_agent) VALUES ($id, '$ip', '$user_agent')";
    $db->query($query);
    $query = "UPDATE users SET last_login=CURRENT_TIMESTAMP WHERE id=$id";
    $db->query($query);

    $_SESSION['loggedin'] = TRUE;
    $_SESSION['id'] = $id;
    $_SESSION['username'] = $user;
    $_SESSION['level'] = $level;

    return TRUE;
}

function register(&$error)
{
    global $db;
    $username = trim(param_or_die("username"));
    $normalized = $db->real_escape_string(strtolower($username));
    $password = param_or_die("password");
    $confirm = param_or_die("confirm");

    if (empty($username) || empty($password))
    {
        $error = "Username/password cannot be empty!";
        return FALSE;
    }

    if (strlen($username) > 50)
    {
        $error = "Username must be under 50 characters";
        return FALSE;
    }

    $query = "SELECT username_normalized FROM users WHERE username_normalized = '$normalized'";
    $result = $db->query($query);
    if (!$result)
    {
        $error = "Unexpected server error. Please try again";
        return FALSE;
    }

    if ($result->num_rows > 0)
    {
        $result->close();
        $error = "This user already exists!";
        return FALSE;
    }

    $result->close();
    if (strcmp($password, $confirm))
    {
        $error = "Passwords do not match!";
        return FALSE;
    }

    $pass_hash = password_hash($password, PASSWORD_DEFAULT);
    $escaped_user_preserved = $db->real_escape_string($username);
    $query = "INSERT INTO users (username, username_normalized, password) VALUES ('$escaped_user_preserved', '$normalized', '$pass_hash')";
    $result = $db->query($query);
    if (!$result)
    {
        $error = "Error entering name into database. Please try again";
        return FALSE;
    }

    $text_msg = "New user registered on plexweb!\r\n\r\nUsername: " . $username . "\r\nIP: " . $_SERVER["REMOTE_ADDR"];
    send_email_forget(ADMIN_PHONE, $text_msg, "" /*subject*/);
    return TRUE;
}

/// <summary>
/// Processes the given suggestion and alerts admins as necessary
/// </summary>
function process_suggestion()
{
    $suggestion = param_or_json_exit('name');
    $type = RequestType::get_type_from_str(param_or_json_exit('mediatype'));
    $comment = param_or_json_exit('comment');
    if (strlen($suggestion) > 64)
    {
        json_error_and_exit("Suggestion must be less than 64 characters");
    }

    if (strlen($comment) > 1024)
    {
        json_error_and_exit("Comment must be less than 1024 characters");
    }

    if ($type === RequestType::None)
    {
        json_error_and_exit("Unknown media type: " . $_POST['mediatype']);
    }

    global $db;
    $suggestion = $db->real_escape_string($suggestion);
    $comment = $db->real_escape_string($comment);
    $userid = (int)$_SESSION['id'];
    $query = "INSERT INTO user_requests (username_id, request_type, request_name, comment) VALUES ($userid, $type, '$suggestion', '$comment')";
    if (!$db->query($query))
    {
        json_error_and_exit($db->error);
    }
}

/// <summary>
/// Process the given permission request. Currently only StreamAccess is supported
/// </summary>
function process_permission_request()
{
    $rt = RequestType::None;
    try
    {
        $rt = RequestType::get_type((int)param_or_die('req_type'));
    }
    catch (Exception $ex)
    {
        error_and_exit(400);
    }

    switch ($rt)
    {
        case RequestType::StreamAccess:
            process_stream_access_request(param_or_die('which'));
            break;
        default:
            error_and_exit(400);
    }
}

/// <summary>
/// Processes a stream access request.
///
/// If we're getting stream request status, print '0' if the user has not requested access, and '1' if they have
/// If we're requesting access, print '1' if the request was successful, '0' if the user has already requested access
/// </summary>
function process_stream_access_request($which)
{
    global $db;
    if ($which != 'get' && $which != 'req')
    {
        error_and_exit(400);
    }

    $get_only = strcmp($which, 'get') === 0;
    $userid = $_SESSION['id'];
    $query = "SELECT id FROM user_requests WHERE username_id=$userid AND request_type=10";
    $result = $db->query($query);
    if ($result === FALSE)
    {
        error_and_exit(500);
    }

    if ($result->num_rows == 0)
    {
        $result->close();
        if ($get_only)
        {
            header('Content-Type: text/plain');
            echo "1";
            return;
        }

        $query = "INSERT INTO user_requests (username_id, request_type, request_name, comment) VALUES ($userid, 10, 'ViewStream', '')";
        $result = $db->query($query);
        if ($result === FALSE)
        {
            echo "ugh2";
            error_and_exit(500);
        }

        header('Content-Type: text/plain');
        echo "1";
        return;
    }
    else
    {
        $result->close();
        header('Content-Type: text/plain');
        echo "0";
    }
}

/// <summary>
/// Processes a request to update a user request. 'kind', id' and 'content' must be set
/// </summary>
function process_request_update()
{
    $content = param_or_die('content');
    $req_id = (int)param_or_die('id');
    $level = (int)$_SESSION['level'];
    $sesh_id = (int)$_SESSION['id'];
    $requester = get_user_from_request($req_id);
    if ($requester->id === -1)
    {
        // Bad request id passed in
        error_and_exit(400);
    }

    if ($level < 100 && $requester->$id != $sesh_id)
    {
        // Only superadmins can edit all requests
        error_and_exit(401);
    }

    switch (param_or_die('kind'))
    {
        case "adm_cm":
            if ($level < 100)
            {
                error_and_exit(401);
            }

            update_admin_comment($req_id, $content, $requester);
            break;
        case "usr_cm":
            if ($requester->id != $sesh_id)
            {
                // Only the requester can update the user comment
                error_and_exit(401);
            }

            update_user_comment($req_id, $content);
            break;
        case "status":
            if ($level < 100)
            {
                // Only admins can change status
                error_and_exit(401);
            }

            update_req_status($req_id, (int)$content, $requester);
    }
}

/// <summary>
/// Updates the user information. Populates $error on failure
/// </summary>
function update_user_settings($firstname, $lastname, $email, $emailalerts, $phone, $phonealerts, $carrier, &$error)
{
    global $db;
    try
    {
        $emailRegex = '/^(([^<>()\[\]\\.,;:\s@"]+(\.[^<>()\[\]\\.,;:\s@"]+)*)|(".+"))@((\[[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}])|(([a-zA-Z\-0-9]+\.)+[a-zA-Z]{2,}))$/';
        // Just show one error at a time
        if (strlen($firstname) > 128)
        {
            $error = "First name must be less than 128 characters";
        }
        else if (strlen($lastname) > 128)
        {
            $error = "Last name must be less than 128 characters";
        }
        else if (strlen($email) > 256)
        {
            $error = "Email must be less than 256 characters";
        }
        else if (!empty($email) && !preg_match($emailRegex, $email))
        {
            $error = "Invalid email address";
        }
        else if (!empty($phone) && strlen($phone) != 10 && strlen($phone) != 11)
        {
            $error = "Invalid phone number";
        }
        else if (strcmp($carrier, "verizon") && strcmp($carrier, "att") && strcmp($carrier, "tmobile") && strcmp($carrier, "sprint"))
        {
            $error = "Invalid phone carrier";
        }

        if (!empty($error))
        {
            return FALSE;
        }

        $firstname = $db->real_escape_string($firstname);
        $lastname = $db->real_escape_string($lastname);
        $email = $db->real_escape_string($email);
        $emailalerts = !strcmp($emailalerts, "true") ? "TRUE" : "FALSE";
        $phonealerts = !strcmp($phonealerts, "true") ? "TRUE" : "FALSE";
        $phone = strcmp($phonealerts, "FALSE") ? (int)$phone : 0;
        $carrier = $db->real_escape_string($carrier);
        $userid = (int)$_SESSION['id'];

        $query = "UPDATE user_info SET firstname='$firstname', lastname='$lastname', email='$email', email_alerts=$emailalerts, phone=$phone, phone_alerts=$phonealerts, carrier='$carrier' WHERE userid=$userid";
        $result = $db->query($query);
        if (!$result)
        {
            $error = "Failed to update information<br/>" . $db->error;
            return FALSE;
        }

        return TRUE;
    }
    catch (Exception $e)
    {
        return FALSE;
    }
}

/// <summary>
/// Updates the admin comment for the given request
/// </summary>
function update_admin_comment($req_id, $content, $requester)
{
    global $db;
    $content = $db->real_escape_string($content);
    $query = "SELECT admin_comment, request_name FROM user_requests WHERE id=$req_id";
    $result = $db->query($query);
    if (!$result || $result->num_rows === 0)
    {
        error_and_exit(500);
    }

    $row = $result->fetch_row();
    $old_comment = $row[0];
    $req_name = $row[1];
    $result->close();
    if (strcmp($old_comment, $content) === 0)
    {
        // Comments are the same, do nothing
        return;
    }

    $query = "UPDATE user_requests SET admin_comment = '$content' WHERE id=$req_id";
    if (!$db->query($query))
    {
        error_and_exit(500);
    }

    send_notifications_if_needed("comment", $requester, $req_name, $content);
}

/// <summary>
/// Updates the user comment of the given request
/// </summary>
/// <todo>Send notifications to admins on update</todo>
function update_user_comment($req_id, $content)
{
    global $db;
    $content = $db->real_escape_string($content);
    $query = "UPDATE user_requests SET comment = '$content' WHERE id=$req_id";
    if (!$db->query($query))
    {
        error_and_exit(500);
    }
}

/// <summary>
/// Updates the status of the given request
/// </summary>
function update_req_status($req_id, $status, $requester)
{
    global $db;
    $request_query = "SELECT * FROM user_requests WHERE id=$req_id";
    $result = $db->query($request_query);
    if (!$result)
    {
        error_and_exit(400);
    }

    $row = $result->fetch_row();
    $result->close();
    $request_type = RequestType::get_type($row[2]);
    $req_name = RequestType::get_str($request_type) . " " . $row[3];
    if ($request_type == RequestType::StreamAccess)
    {
        // Need to adjust permissions
        $update_level = "";
        if ($status == 1 && $requester->level < 20)
        {
            $update_level = "UPDATE users SET level=20 WHERE id=$requester->id";
            if (!$db->query($update_level))
            {
                error_and_exit(500);
            }
        }
        else if ($status == 2 && $requester->level >= 20)
        {
            // Access revoked. Bring them down a peg
            $update_level = "UPDATE users SET level=10 WHERE id=$requester->id";
        }

        if (!empty($update_level) && !$db->query($update_level))
        {
            error_and_exit(500);
        }
    }

    // Update the actual request
    $query = "UPDATE user_requests SET satisfied=$status WHERE id=$req_id";
    if (!$db->query($query))
    {
		json_error_and_exit($db->error);
    }

    $status_str = ($status == 0 ? "pending" : ($status == 1 ? "approved" : "denied"));
    send_notifications_if_needed("status", $requester, $req_name, $status_str);
}

/// <summary>
/// Send email and text notifications if the user has requested them
/// </summary>
function send_notifications_if_needed($type, $requester, $req_name, $content)
{
    $text = "";
    switch ($type)
    {
        case "comment":
            $text = "A comment has been added to your request for " . $req_name . ": " . $content;
            break;
        case "status":
            $text = "The status of your request has changed:\nRequest: " . $req_name . "\nStatus: " . $content;
            break;
        default:
            error_and_exit(500);
    }

    if ($requester->info->phone_alerts && $requester->info->phone != 0)
    {
        $to = "";
        switch ($requester->info->carrier)
        {
            case "verizon":
                $to = $phone . "@vtext.com";
                break;
            case "tmobile":
                $to = $phone . "@tmomail.net";
                break;
            case "att":
                $to = $phone . "@txt.att.net";
                break;
            case "sprint":
                $to = $phone . "@messaging.sprintpcs.com";
                break;
            default:
                error_and_exit(400);
                break;
        }

        $subject = "";
        send_email_forget($to, $text, $subject);
    }

    if ($requester->info->email_alerts && !empty($requester->info->email))
    {
        $subject = "Plex Request Update";
        send_email_forget($requester->info->email, $text, $subject);
    }
}

/// <summary>
/// Returns the user who submitted the given request
/// </summary>
function get_user_from_request($req_id)
{
    global $db;
    $user = new \stdClass();
    $query = "SELECT u.id, u.username, u.level, i.firstname, i.lastname, i.email, i.email_alerts, i.phone, i.phone_alerts, i.carrier
              FROM user_requests
                  INNER JOIN users u ON user_requests.username_id=u.id
                  INNER JOIN user_info i ON u.id = i.userid
              WHERE user_requests.id=$req_id";
    $result = $db->query($query);
    if (!$result || $result->num_rows === 0)
    {
        $user->id = -1;
        return $user;
    }

    $row = $result->fetch_row();
    $user->id = $row[0];
    $user->username = $row[1];
    $user->level = $row[2];
    $user->info = new \stdClass();
    $user->info->firstname = $row[3];
    $user->info->lastname = $row[4];
    $user->info->email = $row[5];
    $user->info->email_alerts = $row[6];
    $user->info->phone = $row[7];
    $user->info->phone_alerts = $row[8];
    $user->info->carrier = $row[9];

    $result->close();
    return $user;
}

/// <summary>
/// Retrieves the current user's information
/// </summary>
function get_user_settings()
{
    global $db;
    $userid = (int)$_SESSION["id"];
    $query = "SELECT * FROM user_info WHERE userid=$userid";
    $result = $db->query($query);
    if (!$result || $result->num_rows != 1)
    {
        json_error_and_exit("Failed to retrieve user settings");
    }

    $row = $result->fetch_row();
    $json = new \stdClass();
    $json->firstname = $row[2];
    $json->lastname = $row[3];
    $json->email = $row[4];
    $json->emailalerts = $row[5];
    $json->phone = $row[6];
    $json->phonealerts = $row[7];
    $json->carrier = $row[8];

    $result->close();

    return json_encode($json);
}

/// <summary>
/// Returns a json string of members, sorted by the last time they logged in
/// </summary>
function get_members()
{
    if ((int)$_SESSION['level'] < 100)
    {
        json_error_and_exit("Not authorized");
    }

    global $db;
    $query = "SELECT id, username, level, last_login FROM users ORDER BY id ASC";
    $result = $db->query($query);
    if (!$result)
    {
        json_error_and_exit("Error getting member list");
    }

    $users = array();
    while ($row = $result->fetch_row())
    {
        $user = new \stdClass();
        $user->id = $row[0];
        $user->username = $row[1];
        $user->level = $row[2];
        $user->last_seen = $row[3];
        array_push($users, $user);
    }

    $result->close();

    json_message_and_exit(json_encode($users));
}

/// <summary>
/// Perform a search against the plex server
/// </summary>
function search()
{
    if ((int)$_SESSION['level'] < 20)
    {
        json_error_and_exit("Not authorized");
    }

    $query = param_or_json_exit('query');
    $type = strtolower(RequestType::get_type_from_str(param_or_json_exit('kind')));

    $libraries = simplexml_load_string(curl(PLEX_SERVER . '/library/sections?' . PLEX_TOKEN))->xpath("Directory");

    $type_str = "";
    if ($type == RequestType::Movie)
    {
        $type_str = "movies";
    }
    else if ($type == RequestType::TVShow)
    {
        $type_str = "tv shows";
    }
    else if ($type == RequestType::AudioBook)
    {
        $type_str = "audiobooks";
    }
    else if ($type == RequestType::Music)
    {
        $type_str = "music";
    }
    else
    {
        json_error_and_exit("Unknown media category: " . $type);
    }

    $section = -1;
    foreach ($libraries as $library)
    {
        if (strtolower($library['title']) == $type_str)
        {
            $section = $library['key'];
        }
    }

    if ($section == -1)
    {
        json_error_and_exit("Could not find category '" . $type_str . "'");
    }

    $prefix = ($type == RequestType::AudioBook ? "albums" : "all");
    $url = PLEX_SERVER . "/library/sections/" . $section . "/" . $prefix . "?" . PLEX_TOKEN;
    $url .= '&title=' . urlencode($query) . '&sort=addedAt:desc';
    $results = simplexml_load_string(curl($url));
    $existing = array();

    foreach ($results as $result)
    {
        $item = new \stdClass();
        $item->title = (string)$result['title'];
        $item->thumb = 'thumb' . $result['thumb'];
        $item->year = (string)$result['year'];
        if (RequestType::is_audio($type))
        {

        }
        else
        {
            $copies = $result->xpath('Media');
            $res = "";
            foreach ($copies as $file)
            {
                $newRes = $file['videoResolution'];
                if ($newRes)
                {
                    $res .= $newRes . ", ";
                }
            }

            $len = strlen($res);
            if ($len > 0)
            {
                $res = substr($res, 0, $len - 2);
                $item->resolution = $res;
            }
        }

        array_push($existing, $item);
        if (sizeof($existing) == 5)
        {
            break;
        }
    }

    $final_obj = new \stdClass();
    $final_obj->length = sizeof($results);
    $final_obj->top = $existing;
    json_message_and_exit(json_encode($final_obj));
}

/// <summary>
/// Search for a movie or tv show via IMDb.
///
/// This method does _not_ return on success or failure, but sends a message to the output stream and exits
/// </summary>
function search_external()
{
    $query = strtolower(trim(param_or_json_exit('query')));
    $letter = substr($query, 0, 1);
    $type = strtolower(RequestType::get_type_from_str(param_or_json_exit('kind')));

    if ($type != RequestType::Movie && $type != RequestType::TVShow)
    {
        json_error_and_exit("Only Movie and TV shows supported ATM");
    }

    $url = "https://v2.sg.media-imdb.com/suggests/" . urlencode($letter) . "/" . urlencode($query) . ".json";
    $response = curl($url);
    if (strtolower(substr($response, 0, 5)) == "imdb$")
    {
        $index = strpos($response, "(") + 1;
        $length = strlen($response) - $index;
        $response = substr($response, $index, $length - 1);

        $results = array();
        $response = json_decode($response, true)['d'];

        $len = sizeof($response);

        foreach ($response as $result)
        {
            if (substr($result['id'], 0, 2) != "tt" || !isset($result['q']))
            {
                // Not a movie/show
                --$len;
                continue;
            }

            if ($type == RequestType::Movie && $result['q'] != "feature")
            {
                // Movie type (q) is 'feature'
                --$len;
                continue;
            }
            else if ($type == RequestType::TVShow && strtolower($result['q']) != "tv series")
            {
                --$len;
                continue;
            }

            if (!isset($result['y']) || !isset($result['i']))
            {
                // No year/thumbnail == no entry
                --$len;
                continue;
            }

            $item = new \stdClass();
            $item->title = $result['l'];
            $item->year = $result['y'];
            $item->thumb = $result['i'][0];
            $item->id = $result['id'];
            array_push($results, $item);

            if (sizeof($results) == 5)
            {
                break;
            }
        }

        $final_obj = new \stdClass();
        $final_obj->length = $len;
        $final_obj->top = $results;
        json_message_and_exit(json_encode($final_obj));

        json_message_and_exit(json_encode($results));
    }
    else
    {
        json_error_and_exit("Unknown IMDb error: " . $response);
    }
}

/// <summary>
/// Attempt to update a user's password, failing if the old password is incorrect,
/// the old password matches the new password, or the new password doesn't match it's confirmation
/// </summary>
function update_password($old_pass, $new_pass, $conf_pass, &$error)
{
    global $db;

    // First, verify that the old password they entered is correct
    $escaped_user_preserved = $db->real_escape_string($_SESSION['username']);
    $query = "SELECT password FROM users WHERE username='$escaped_user_preserved'";
    $result = $db->query($query);
    if (!$result || $result->num_rows != 1)
    {
        $error = "Error updating password. Please try again";
        return FALSE;
    }

    $old_hash = $result->fetch_row()[0];
    $result->close();
    if (!password_verify($old_pass, $old_hash))
    {
        $error = "Old password is incorrect!";
        return FALSE;
    }

    if ($old_pass == $new_pass)
    {
        $error = "New password must be different from current password!";
        return FALSE;
    }

    if ($new_pass != $conf_pass)
    {
        $error = "Passwords don't match!";
        return FALSE;
    }

    $new_pass_hash = password_hash($new_pass, PASSWORD_DEFAULT);
    $query = "UPDATE users SET password='$new_pass_hash' WHERE username='$escaped_user_preserved'";
    $result = $db->query($query);
    if (!$result)
    {
        $error = "Error updating password. Please try again";
        return FALSE;
    }

    return TRUE;
}

/// <summary>
/// Return the location and ISP (sorta) information for the given IP address
/// </summary>
function get_geo_ip($ip, &$error)
{
    $json = json_decode(curl(GEOIP_URL . $ip));
    if ($json == NULL)
    {
        $error = "Failed to parse geoip response";
        return FALSE;
    }

    // We only care about certain fields
    $trimmed_json = new \stdClass();
    $trimmed_json->country = $json->country_name;
    $trimmed_json->state = $json->state_prov;
    $trimmed_json->city = $json->city;
    $trimmed_json->isp = $json->isp;
    return json_encode($trimmed_json);
}

/// <summary>
/// Get the contents of the given url
/// </summary>
function curl($url)
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $return = curl_exec($ch);
    curl_close($ch);
    return $return;
}
?>
