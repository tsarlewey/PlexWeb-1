<?php
/// <summary>
/// The main class for processing requests. Monolithic, but IMO better than a bunch of different php files
/// 
/// The only required field is 'type', everything else is dependant on the specified type
///
/// The expected pattern for methods is to have them return a JSON string on success or failure.
/// If a GET/POST parameter is set incorrectly, it's very likely that the method will not return
/// and the process will `exit` immediately.
/// </summary>

require_once "includes/common.php";
require_once "includes/config.php";
require_once "includes/tvdb.php";

$type = get('type');

// For requests that are only made when not logged in, don't session_start or verify login state
switch ($type)
{
    case "check_username":
    case "login":
    case "register":
    case "forgot_password":
    case "reset_password":
        break;
    default:
        session_start();
        verify_loggedin(FALSE /*redirect*/, "" /*return*/, TRUE /*json*/);
        break;
}

json_message_and_exit(process_request($type));

/// <summary>
/// Our main entrypoint. Returns a json message (on success or failure)
/// </summary>
function process_request($type)
{
    $message = "";
    switch ($type)
    {
        case "login":
            $message = login(get("username"), get("password"));
            break;
        case "register":
            $message = register(get("username"), get("password"), get("confirm"));
            break;
        case "request":
            $message = process_suggestion(get("name"), get("mediatype"), get("comment"));
            break;
        case "request_new":
            $message = process_suggestion_new(get("name"), get("mediatype"), get("external_id"), get("poster"));
            break;
        case "pr": // pr === permission_request
            $message = process_permission_request();
            break;
        case "req_update":
            $message = process_request_update(get("kind"), get("content"), get("id"));
            break;
        case "set_usr_info":
            $message = update_user_settings(
                get('fn'),
                get('ln'),
                get('e'),
                get('ea'),
                get('p'),
                get('pa'),
                get('c'));
            break;
        case "get_usr_info":
            $message = get_user_settings();
            break;
        case "check_username":
            $message = check_username(get("username"));
            break;
        case "members":
            $message = get_members();
            break;
        case "search":
            $message = search(get("query"), get("kind"));
            break;
        case "season_details":
            $message = get_season_details(get("path"));
            break;
        case "search_external":
            $message = search_external(get("query"), get("kind"));
            break;
        case "update_pass":
            $message = update_password(get("old_pass"), get("new_pass"), get("conf_pass"));
            break;
        case "geoip":
            $message = get_geo_ip(get("ip"));
            break;
        case "set_external_id":
            $message = set_external_id((int)get("req_id"), (int)get("id"));
            break;
        case "add_comment":
            $message = add_request_comment((int)get("req_id"), get("content"));
            break;
        case "get_comments":
            $message = get_request_comments((int)get("req_id"));
            break;
        case "req_nav":
            $message = get_next_req((int)get("id"), (int)get("dir"));
            break;
        case "requests":
            $message = get_requests((int)get("num"), (int)get("page"), get("search"), get("filter"));
            break;
        case "activities":
            $message = get_activites((int)get("num"), (int)get("page"), get("search"), get("filter"));
            break;
        case "new_activities":
            $message = get_new_activity_count();
            break;
        case "log_err":
            $message = log_error(get("error"), get("stack"));
            break;
        case "forgot_password":
            $message = forgot_password(get("username"));
            break;
        case "reset_password":
            $message = reset_password(get("token"), get("password"), get("confirm"));
            break;
        case "forgot_password_admin":
            $message = forgot_password_admin(get("username"), get("email"));
            break;
        case "delete_comment":
            $message = delete_comment((int)get("comment_id"));
            break;
        case "edit_comment":
            $message = edit_comment((int)get("id"), get("content"));
            break;
        default:
            return json_error("Unknown request type: " . $type);
    }

    return $message;
}

class LoginResult
{
    const Success = 1;
    const IncorrectPassword = 2;
    const BadUsername = 3;
    const ServerError = 4;
}

/// <summary>
/// Attempts to login, returning an error on failure
/// </summary>
function login($username, $password)
{
    global $db;
    $username = trim($username);
    $ip = $db->real_escape_string($_SERVER['REMOTE_ADDR']);
    $user_agent = $db->real_escape_string($_SERVER['HTTP_USER_AGENT']);

    if (empty($username) || empty($password))
    {
        record_login($username, $ip, $user_agent, LoginResult::BadUsername);
        return json_error("Username/password cannot be empty!");
    }

    $username = trim($username);
    $normalized = strtolower($username);

    $normalized = $db->real_escape_string($normalized);
    $query = "SELECT id, username, username_normalized, password, level FROM users WHERE username_normalized='$normalized'";
    $result = $db->query($query);
    if (!$result)
    {
        record_login($normalized, $ip, $user_agent, LoginResult::ServerError);
        return json_error("Unexpected server error. Please try again");
    }

    if ($result->num_rows === 0)
    {
        record_login($normalized, $ip, $user_agent, LoginResult::BadUsername);
        return json_error("User does not exist. Would you like to <a href=register.php>register</a>?");
    }

    $row = $result->fetch_row();
    $result->close();
    $id = (int)$row[0];
    $user = $row[1];
    $hashed_pass = $row[3];
    $level = $row[4];

    if (!password_verify($password, $hashed_pass))
    {
        record_login($id, $ip, $user_agent, LoginResult::IncorrectPassword);
        return json_error("Incorrect password!");
    }

    session_start();
    record_login($id, $ip, $user_agent, LoginResult::Success);
    $query = "UPDATE users SET last_login=CURRENT_TIMESTAMP WHERE id=$id";
    $db->query($query);

    $_SESSION['loggedin'] = TRUE;
    $_SESSION['id'] = $id;
    $_SESSION['username'] = $user;
    $_SESSION['level'] = $level;

    return json_success();
}

/// <summary>
/// Record a login attempt on success or failure
/// </summary>
function record_login($userid, $ip, $user_agent, $status)
{
    global $db;
    $query = "";
    if (is_string($userid))
    {
        // Invalid username that we can't map to an id - set the "invalid_username" field instead
        $query = "INSERT INTO logins (userid, invalid_username, ip, user_agent, status) VALUES (-1, '$userid', '$ip', '$user_agent', $status)";
    }
    else
    {
        $query = "INSERT INTO logins (userid, ip, user_agent, status) VALUES ($userid, '$ip', '$user_agent', $status)";
    }

    $db->query($query);
}

/// <summary>
/// Attempt to register a user
/// </summary>
function register($username, $password, $confirm)
{
    global $db;
    $username = trim($username);
    $normalized = $db->real_escape_string(strtolower($username));

    if (empty($username) || empty($password))
    {
        return json_error("Username/password cannot be empty!");
    }

    if (strlen($username) > 50)
    {
        return json_error("Username must be under 50 characters");
    }

    $query = "SELECT username_normalized FROM users WHERE username_normalized = '$normalized'";
    $result = $db->query($query);
    if (!$result)
    {
        return json_error("Unexpected server error. Please try again");
    }

    if ($result->num_rows > 0)
    {
        $result->close();
        return json_error("This user already exists!");
    }

    $result->close();
    if (strcmp($password, $confirm))
    {
        return json_error("Passwords do not match!");
    }

    $pass_hash = password_hash($password, PASSWORD_DEFAULT);
    $escaped_user_preserved = $db->real_escape_string($username);
    $query = "INSERT INTO users (username, username_normalized, password) VALUES ('$escaped_user_preserved', '$normalized', '$pass_hash')";
    $result = $db->query($query);
    if (!$result)
    {
        return json_error("Error entering name into database. Please try again");
    }

    $text_msg = "New user registered on plexweb!\r\n\r\nUsername: " . $username . "\r\nIP: " . $_SERVER["REMOTE_ADDR"];
    send_email_forget(ADMIN_PHONE, $text_msg, "" /*subject*/);
    return json_success();
}

/// <summary>
/// Processes the given suggestion and alerts admins as necessary
/// </summary>
function process_suggestion($suggestion, $type, $comment)
{
    $type = RequestType::get_type_from_str($type);
    if (strlen($suggestion) > 64)
    {
        return json_error("Suggestion must be less than 64 characters");
    }

    if (strlen($comment) > 1024)
    {
        return json_error("Comment must be less than 1024 characters");
    }

    if ($type === RequestType::None)
    {
        return json_error("Unknown media type: " . $_POST['mediatype']);
    }

    global $db;
    $suggestion = $db->real_escape_string($suggestion);
    $comment = $db->real_escape_string($comment);
    $userid = (int)$_SESSION['id'];
    $query = "INSERT INTO user_requests (username_id, request_type, request_name, comment) VALUES ($userid, $type, '$suggestion', '$comment')";
    if (!$db->query($query))
    {
        return json_error($db->error);
    }

    // If there was no user comment we can return now. Otherwise, find the request id of the item we just
    // created and add a comment
    if (strlen($comment) == 0)
    {
        return json_success();
    }

    $query = "SELECT id FROM user_requests WHERE username_id=$userid AND request_type=$type AND request_name='$suggestion' ORDER BY id DESC LIMIT 1";
    $result = $db->query($query);
    if (!$result || $result->num_rows == 0)
    {
        return db_error();
    }

    $req_id = (int)$result->fetch_row()[0];

    $query = "INSERT INTO request_comments (req_id, user_id, content) VALUES ($req_id, $userid, $comment)";
    if (!$db->query($query))
    {
        return db_error();
    }

    return json_success();
}

function request_exists($external_id, $userid)
{
    global $db;
    $query = "SELECT id, request_name, satisfied FROM user_requests WHERE username_id=$userid AND external_id=$external_id";
    $result = $db->query($query);
    if ($result && $result->num_rows != 0)
    {
        $row = $result->fetch_row();
        $existing_request = new \stdClass();
        $existing_request->exists = TRUE;
        $existing_request->rid = $row[0];
        $existing_request->name = $row[1];
        $existing_request->status = $row[2];
        return $existing_request;
    }

    return NULL;
}

function process_suggestion_new($suggestion, $type, $external_id, $poster)
{
    $type = RequestType::get_type_from_str($type);
    $external_id = (int)$external_id;
    if (strlen($suggestion) > 128)
    {
        return json_error("Suggestion must be less than 128 characters");
    }

    if ($type === RequestType::None)
    {
        return json_error("Unknown media type: " . $_POST['mediatype']);
    }

    $userid = (int)$_SESSION['id'];
    $existing_request = request_exists($external_id, $userid);
    if ($existing_request != NULL)
    {
        return json_encode($existing_request);
    }

    global $db;
    $suggestion = $db->real_escape_string($suggestion);
    $poster = $db->real_escape_string($poster);
    $query = "INSERT INTO user_requests (username_id, request_type, request_name, external_id, comment, poster_path) VALUES ($userid, $type, '$suggestion', $external_id, '', '$poster')";
    if (!$db->query($query))
    {
        return db_error();
    }

    // Return the new entry's id
    $query = "SELECT id, request_date FROM user_requests WHERE request_name='$suggestion' AND username_id=$userid AND request_type=$type AND external_id=$external_id ORDER BY request_date DESC";
    $result = $db->query($query);
    if ($result === FALSE)
    {
        return db_error();
    }

    $row = $result->fetch_assoc();
    $id = $row['id'];
    send_notifications_if_needed("create", get_user_from_request($id), $suggestion, "", $id, FALSE /*is_markdown*/);

    // Add an entry to the activity table
    if (!add_create_activity($id, $userid))
    {
        return db_error();
    }

    return "{ \"req_id\" : " . $id . " }";
}

/// <summary>
/// Process the given permission request. Currently only StreamAccess is supported
/// </summary>
function process_permission_request()
{
    $rt = RequestType::None;
    try
    {
        $rt = RequestType::get_type((int)get("req_type"));
    }
    catch (Exception $ex)
    {
        return json_error("Unable to process request type: " . get("req_type"));
    }

    switch ($rt)
    {
        case RequestType::StreamAccess:
            return process_stream_access_request(get("which"));
        default:
            return json_error("Unknown request type: " . get("req_type"));
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
        return json_error("Invalid 'which' parameter '" . $which . "' - must be 'get' or 'req'");
    }

    $get_only = strcmp($which, 'get') === 0;
    $userid = $_SESSION['id'];
    $query = "SELECT id, satisfied FROM user_requests WHERE username_id=$userid AND request_type=10";
    $result = $db->query($query);
    if ($result === FALSE)
    {
        return db_error();
    }

    if ($result->num_rows == 0)
    {
        $result->close();
        if ($get_only)
        {
            return '{ "value" : "Request Access" }';
        }

        $msg = try_get("msg");
        if (!$msg)
        {
            $msg = "";
        }

        $msg = $db->real_escape_string(mb_strimwidth($msg, 0, 1024));

        $query = "INSERT INTO user_requests (username_id, request_type, request_name, comment) VALUES ($userid, 10, 'ViewStream', '$msg')";
        $result = $db->query($query);
        if ($result === FALSE)
        {
            return db_error();
        }

        if (strlen($msg) != 0)
        {
            $query = "SELECT id FROM user_requests WHERE username_id=$userid AND request_type=10 AND request_name='ViewStream' ORDER BY request_date DESC";

            $result = $db->query($query);

            if (!$result)
            {
                return json_error("Error adding user comment");
            }

            $req_id = $result->fetch_row()[0];

            $query = "INSERT INTO request_comments (req_id, user_id, content) VALUES ($req_id, $userid, '$msg')";
            if (!$db->query($query))
            {
                return json_error("Error adding user comment");
            }
        }

        return '{ "value" : "Access Requested!" }';
    }
    else
    {
        $str = "";
        $row = $result->fetch_row();
        $id = $row[0];
        $status = (int)$row[1];
        $result->close();
        switch($status)
        {
            case 0:
                return '{ "value" : "Request Pending", "id" : ' . $id . ' }';
            case 1:
                return '{ "value" : "Request Approved" }';
            case 2:
                return '{ "value" : "Request Denied" }';
            case 3:
                return '{ "value" : "Request In Progress" }';
            case 4:
                return '{ "valie" : "Request Waiting" }';
            default:
                return json_error("Unknown request status");
        }
    }
}

/// <summary>
/// Processes a request to update a user request. 'kind', id' and 'content' must be set
/// </summary>
function process_request_update($kind, $content, $id)
{
    $req_id = (int)$id;
    $level = (int)$_SESSION['level'];
    $sesh_id = (int)$_SESSION['id'];
    $requester = get_user_from_request($req_id);
    if ($requester->id === -1)
    {
        // Bad request id passed in
        return json_error("Bad request");
    }

    if ($level < 100 && $requester->id != $sesh_id)
    {
        // Only superadmins can edit all requests
        return json_error("Not authorized");
    }

    switch ($kind)
    {
        case "adm_cm":
            if ($level < 100)
            {
                return json_error("Not authorized");
            }

            return update_admin_comment($req_id, $content, $requester);
        case "usr_cm":
            if ($requester->id != $sesh_id)
            {
                // Only the requester can update the user comment
                return json_error("Not authorized");
            }

            return update_user_comment($req_id, $content);
        case "status":
            if ($level < 100)
            {
                // Only admins can change status
                return json_error("Not authorized");
            }

            return update_req_status($req_id, (int)$content, $requester);
        default:
            return json_error("Unknown request update type: " . $kind);
    }
}

function add_request_comment($req_id, $content)
{
    global $db;
    $query = "SELECT username_id, request_name FROM user_requests WHERE id=$req_id";
    $result = $db->query($query);
    if ($result === FALSE || $result->num_rows === 0)
    {
        return json_error("bad request id");
    }

    $row = $result->fetch_row();
    $req_userid = (int)$row[0];
    $req_name = $row[1];
    if ($_SESSION['level'] < 100 && $_SESSION['id'] != $req_userid)
    {
        return json_error("Not Authorized");
    }

    (int)$userid = $_SESSION['id'];
    $content = $db->real_escape_string($content);
    $query = "INSERT INTO request_comments (req_id, user_id, content) VALUES ($req_id, $userid, '$content')";
    if (!$db->query($query))
    {
        return db_error();
    }

    $md_content = try_get("md");
    if ($md_content)
    {
        send_notifications_if_needed("comment", get_user_from_request($req_id), $req_name, $md_content, $req_id, TRUE /*is_markdown*/);
    }
    else
    {
        send_notifications_if_needed("comment", get_user_from_request($req_id), $req_name, $content, $req_id, FALSE /*is_markdown*/);
    }

    // Need to get the ID of this comment to add it to the activity table
    $query = "SELECT `id` FROM request_comments WHERE `req_id`=$req_id AND `user_id`=$userid AND `content`='$content' ORDER BY `timestamp` DESC";

    $result = $db->query($query);
    if (!$result)
    {
        return json_error("Successfully added the comment, but failed to query for it");
    }
    else if ($result->num_rows == 0)
    {
        return json_error("Successfully added the comment, but now we can't find it");
    }

    $row = $result->fetch_assoc();
    $id = $row['id'];
    if (!add_comment_activity($id, $req_id, $req_userid, $userid))
    {
        return db_error();
        // return json_error("Successfully added the comment, but failed to add it to the activity table");
    }

    return json_success();
}

function get_request_comments($req_id)
{
    global $db;
    $query = "SELECT username_id FROM user_requests WHERE id=$req_id";
    $result = $db->query($query);
    if ($result === FALSE || $result->num_rows === 0)
    {
        return json_error("bad request id");
    }

    $uid = $_SESSION['id'];
    $req_userid = (int)$result->fetch_row()[0];
    if ($_SESSION['level'] < 100 && $uid != $req_userid)
    {
        return json_error("Not Authorized");
    }

    $query = "SELECT u.username AS user, c.content AS content, c.timestamp AS time, u.id=$uid AS editable, c.id AS id, c.last_edit AS last_edit FROM `request_comments` c INNER JOIN `users` u ON c.user_id=u.id WHERE c.req_id=$req_id ORDER BY c.timestamp ASC";
    $result = $db->query($query);
    if ($result === FALSE)
    {
        return json_error($db->error);
    }

    $rows = array();

    while ($r = $result->fetch_assoc())
    {
        $rows[] = $r;
    }

    return json_encode($rows);

}

/// <summary>
/// Updates the user information. Populates $error on failure
/// </summary>
function update_user_settings($firstname, $lastname, $email, $emailalerts, $phone, $phonealerts, $carrier)
{
    global $db;
    try
    {
        $emailRegex = '/^(([^<>()\[\]\\.,;:\s@"]+(\.[^<>()\[\]\\.,;:\s@"]+)*)|(".+"))@((\[[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}])|(([a-zA-Z\-0-9]+\.)+[a-zA-Z]{2,}))$/';
        // Just show one error at a time
        if (strlen($firstname) > 128)
        {
            return json_error("First name must be less than 128 characters");
        }
        else if (strlen($lastname) > 128)
        {
            return json_error("Last name must be less than 128 characters");
        }
        else if (strlen($email) > 256)
        {
            return json_error("Email must be less than 256 characters");
        }
        else if (!empty($email) && !preg_match($emailRegex, $email))
        {
            return json_error("Invalid email address");
        }
        else if (!empty($phone) && strlen($phone) != 10 && strlen($phone) != 11)
        {
            return json_error("Invalid phone number");
        }
        else if (strcmp($carrier, "verizon") && strcmp($carrier, "att") && strcmp($carrier, "tmobile") && strcmp($carrier, "sprint"))
        {
            return json_error("Invalid phone carrier");
        }

        $firstname = $db->real_escape_string($firstname);
        $lastname = $db->real_escape_string($lastname);
        $email = $db->real_escape_string($email);
        $emailalerts = (!empty($email) && !strcmp($emailalerts, "true")) ? "TRUE" : "FALSE";
        $phonealerts = (!empty($phone) && !strcmp($phonealerts, "true")) ? "TRUE" : "FALSE";
        $phone = (int)$phone;
        $carrier = $db->real_escape_string($carrier);
        $userid = (int)$_SESSION['id'];

        $query = "UPDATE user_info SET firstname='$firstname', lastname='$lastname', email='$email', email_alerts=$emailalerts, phone=$phone, phone_alerts=$phonealerts, carrier='$carrier' WHERE userid=$userid";
        $result = $db->query($query);
        if (!$result)
        {
            return db_error();
        }

        return json_success();
    }
    catch (Exception $e)
    {
        return json_error("Unexpected error occurred. Please try again later");
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
        return db_error();
    }

    $row = $result->fetch_row();
    $old_comment = $row[0];
    $req_name = $row[1];
    $result->close();
    if (strcmp($old_comment, $content) === 0)
    {
        // Comments are the same, do nothing
        return json_success();
    }

    $query = "UPDATE user_requests SET admin_comment = '$content' WHERE id=$req_id";
    if (!$db->query($query))
    {
        return db_error();
    }

    // Failure to send notificactions won't be considered a failure
    send_notifications_if_needed("comment", $requester, $req_name, $content, $req_id, FALSE /*is_markdown*/);
    return json_success();
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
        return db_error();
    }

    return json_success();
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
        return db_error();
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
                return db_error();
            }
        }
        else if ($status == 2 && $requester->level >= 20)
        {
            // Access revoked. Bring them down a peg
            $update_level = "UPDATE users SET level=10 WHERE id=$requester->id";
        }

        if (!empty($update_level) && !$db->query($update_level))
        {
            return db_error();
        }
    }

    // Update the actual request
    $query = "UPDATE user_requests SET satisfied=$status WHERE id=$req_id";
    if (!$db->query($query))
    {
        return db_error();
    }

    $status_str = array("Pending", "Approved", "Denied", "In Progress", "Waiting")[$status];

    send_notifications_if_needed("status", $requester, $req_name, $status_str, $req_id, FALSE /*is_markdown*/);
    return json_success();
}


/// <summary>
/// Send email and text notifications if the user has requested them
/// </summary>
function send_notifications_if_needed($type, $requester, $req_name, $content, $req_id, $is_markdown)
{
    if ($type != "create" && $requester->id == $_SESSION['id'] && $_SESSION['level'] != 100)
    {
        return;
    }

    $text = "";
    $email = "<html><body style='background-color:#313131;color=#c1c1c1'><div>";
    switch ($type)
    {
        case "comment":
            if ($is_markdown)
            {
                $text = "A comment has been added to your request for " . $req_name . ". See it here: https://plex.danrahn.com/request.php?id=" . $req_id;
            }
            else
            {
                $text = "A comment has been added to your request for " . $req_name . ":\n\t" . $content . "\n\nhttps://plex.danrahn.com/request.php?id=" . $req_id;
            }

            // Emails have more formatting and also displays markdown correctly
            $style_noise = 'url("https://danrahn.com/plex/res/noise.8b05ce45d0df59343e206bc9ae78d85d.png")';
            $email_style = '<style>.markdownEmailContent { background: rgba(0,0,0,0) ' . $style_noise . ' repeat scroll 0% 0%; ';
            $email_style .= 'color: #c1c1c1 !important; border: 5px solid #919191; } ';
            $email_style .= '.md { color: #c1c1c1 !important; } ';
            $email_style .= '.h1Title { margin-top: 0; padding: 20px; border-bottom: 5px solid #919191; } ';
            $email_style .= '</style>';

            $body_background = "url('https://danrahn.com/plex/res/preset-light.770a0981b66e038d3ffffbcc4f5a26a4.png')";

            $subheader = '<h3 style="padding: 0 20px 0 20px;">A comment has been added to your request for ' . $req_name . ':</h3>';

            $email = '<html><head><style>' . file_get_contents("style/markdown.css") . '</style>';
            $email .= $email_style;
            $email .= '</head><body style="background-image: ' . $body_background . '; background-size: cover;">';
            $email .=   '<div class="markdownEmailContent">';
            $email .=     '<h1 class="h1Title">New Comment</h1>';
            $email .=     $subheader;
            $email .=     '<div class="md" style="padding: 0 20px 20px 20px"><div style="padding-left: 20px">';
            $email .=        $content;
            $email .=     '</div><br>';
            $email .=     "View your request <a href='https://plex.danrahn.com/request.php?id=" . $req_id . "'>here</a>.";
            $email .=   '</div>';
            $email .= '</div></body></html>';
            break;
        case "status":
            $text = "The status of your request has changed:\nRequest: " . $req_name . "\nStatus: " . $content . "\n\nhttps://plex.danrahn.com/request.php?id=" . $req_id;
            $email = "<div>The status of your request for " . $req_name . " has changed: " . $content . "</div><br />";
            $email .= "<br />View your request here: https://plex.danrahn.com/request.php?id=" . $req_id;
            $email .= "</div></body></html>";
            break;
        case "create":
            $text = $requester->username . " created a request for " . $req_name . ". See it here: https://plex.danrahn.com/request.php?id=" . $req_id;
            $email = $text;
            break;
        default:
            return json_error("Unknown notification type: " . $type);
    }

    if ($type != "create")
    {
        send_notification($requester, $text, $email);
        return json_success();
    }
    else
    {
        $admins = get_admins();
        foreach ($admins as $admin)
        {
            send_notification($admin, $text, $email);
        }
    }

    return json_success();
}

function get_phone_email($phone, $carrier, &$error)
{
    $error = FALSE;
    switch ($carrier)
    {
        case "verizon":
            return $phone . "@vzwpix.com";
        case "tmobile":
            return $phone . "@tmomail.net";
        case "att":
            return $phone . "@mms.att.net";
        case "sprint":
            return $phone . "@pm.sprint.com";
        default:
            $error = TRUE;
            return "";
    }
}

function send_notification($requester, $text, $email)
{
    if ($requester->info->phone_alerts && $requester->info->phone != 0)
    {
        $to = "";
        $phone = $requester->info->phone;
        $error = FALSE;
        $to = get_phone_email($phone, $requester->info->carrier, $error);
        if ($error)
        {
            return json_error("Unknown carrier: " . $requester->info->carrier);
        }

        $subject = "";
        send_email_forget($to, $text, $subject);
    }

    if ($requester->info->email_alerts && !empty($requester->info->email))
    {
        $subject = "Plex Request Update";
        send_email_forget($requester->info->email, $email, $subject);
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

function get_admins()
{
    global $db;
    $admins = array();
    $query = "SELECT u.id, u.username, u.level, i.firstname, i.lastname, i.email, i.email_alerts, i.phone, i.phone_alerts, i.carrier
              FROM users u
                INNER JOIN user_info i on u.id=i.userid
              WHERE u.level >= 100";
    $result = $db->query($query);

    while ($row = $result->fetch_row())
    {
        $user = new \stdClass();
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

        $admins[] = $user;
    }

    $result->close();

    return $admins;
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
        return json_error("Failed to retrieve user settings");
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
/// Checks whether a given username exists
/// </summary>
function check_username($username)
{
    global $db;
    $check = $db->real_escape_string(strtolower(get("username")));
    $result = $db->query("SELECT username FROM users where username_normalized='$check'");
    if (!$result || $result->num_rows !== 0)
    {
        return '{ "value" : 0 }';
    }
    else
    {
        $result->close();
        return '{ "value" : 1, "name" : "' . get("username") . '" }';
    }
}

/// <summary>
/// Returns a json string of members, sorted by the last time they logged in
/// </summary>
function get_members()
{
    if ((int)$_SESSION['level'] < 100)
    {
        return json_error("Not authorized");
    }

    global $db;
    $query = "SELECT id, username, level, last_login FROM users ORDER BY id ASC";
    $result = $db->query($query);
    if (!$result)
    {
        return db_error();
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

    return json_encode($users);
}

/// <summary>
/// Perform a search against the plex server
/// </summary>
function search($query, $kind)
{
    if ((int)$_SESSION['level'] < 20)
    {
        return json_error("Not authorized");
    }

    $query = strtolower(trim($query));
    $type = strtolower(RequestType::get_type_from_str($kind));

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
        return json_error("Unknown media category: " . $type);
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
        return json_error("Could not find category '" . $type_str . "'");
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
        $item->imdbid = get_imdb_link_from_guid((string)$result['guid'], $type);
        if (RequestType::is_audio($type))
        {
            // Todo - search Audible/music apis?
        }
        else
        {
            $copies = $result->xpath('Media');
            $resolutions = array();
            foreach ($copies as $file)
            {
                $res = (string)$file['videoResolution'];
                if ($res)
                {
                    $lastChar = $res[strlen($res) - 1];
                    if ($lastChar != 'k' && $lastChar != 'p' && $lastChar != 'i' && $lastChar != 'd')
                    {
                        $res .= 'p';
                    }

                    $resolutions[$res] = TRUE;
                }
            }
            if (count($resolutions) > 0)
            {
                $item->resolution = join(', ', array_keys($resolutions));
            }

            if ($type == RequestType::TVShow)
            {
                $item->tvChildPath = (string)$result['key'];
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
    return json_encode($final_obj);
}

function extract_id_from_guid($guid)
{
    $guid = substr($guid, strpos($guid, '://') + 3);
    if (($lang = strpos($guid, '?')) != -1)
    {
        return substr($guid, 0, $lang);
    }

    return $guid;
}

function get_imdb_link_from_guid($guid, $type)
{
    global $db;
    $id = extract_id_from_guid($guid);
    if (strpos($guid, "imdb") !== FALSE)
    {
        return $id;
    }

    if (strpos($guid, "themoviedb") !== FALSE || strpos($guid, "tmdb") !== FALSE)
    {
        $query = "SELECT imdb_id FROM tmdb_cache WHERE tmdb_id=$id";
        $result = $db->query($query);
        if ($result && $result->num_rows == 1)
        {
            return $result->fetch_row()[0];
        }

        $endpoint = 'movie/';
        if ($type == RequestType::TVShow)
        {
            $endpoint = 'tv/';
        }

        $url = TMDB_URL . 'movie/' . $id . TMDB_TOKEN;
        // $url = $_SERVER["HTTP_REFERER"] . 'media_search.php?type=1&query=' . $tmdb . '&by_id=true';
        $imdb = json_decode(curl($url))->imdb_id;
        $result = $db->query("INSERT INTO tmdb_cache (tmdb_id, imdb_id) VALUES ($id, '$imdb')");
        return $imdb;
    }

    if (strpos($guid, "thetvdb") != -1)
    {
        $query = "SELECT imdb_link FROM imdb_tv_cache WHERE show_id=$id AND season=-1 AND episode=-1";
        $result = $db->query($query);
        if ($result && $result->num_rows == 1)
        {
            return $result->fetch_row()[0];
        }

        $tvdb_client = new Tvdb();
        if (!$tvdb_client->ready())
        {
            return $guid;
        }

        $imdb = $tvdb_client->get_series($id)['imdbId'];
        $result = $db->query("INSERT INTO imdb_tv_cache (show_id, season, episode, imdb_link) VALUES ($id, -1, -1, '$imdb')");
        return $imdb;
    }

    // Unknown agent. Just return the id if we can
    return $id;
}

/// <summary>
/// Returns informaton about what seasons are available on plex versus total seasons
/// </summary>
function get_season_details($path)
{
    $details = new \stdClass();
    $details->path = $path;
    $seasonStatus = array();
    $children = simplexml_load_string(curl(PLEX_SERVER . $path . '?' . PLEX_TOKEN));
    $totalSeasons = 0;
    $seasons = $children->xpath('Directory');

    $tvdb_client = new Tvdb();
    foreach ($seasons as $season)
    {
        if (!$season['type'] || $season['type'] != 'season')
        {
            continue;
        }

        $data = new \stdClass();
        $data->season = (int)$season['index'];
        $episodePath = $season['key'];
        $episodes = simplexml_load_string(curl(PLEX_SERVER . $episodePath . '?' . PLEX_TOKEN));
        $availableEpisodes = count($episodes);

        if (isset($season['guid']) && strpos($season['guid'], 'thetvdb') !== FALSE)
        {
            $seasonGuid = substr($season['guid'], strpos($season['guid'], 'thetvdb') + 10);
            $seasonGuid = substr($seasonGuid, 0, strpos($seasonGuid, '/'));
            if (!$tvdb_client->ready())
            {
                $tvdb->login();
            }

            if ($totalSeasons == 0)
            {
                $details->totalSeasons = $tvdb_client->get_series($seasonGuid)['season'];
            }

            $totalEpisodes = count($tvdb_client->get_season_episodes($seasonGuid, $data->season));
            $data->complete = $totalEpisodes == $availableEpisodes;
        }
        else
        {
            $data->unknown = TRUE;
        }
        $seasonStatus[] = $data;
    }

    $details->seasons = $seasonStatus;
    return json_encode($details);
}

/// <summary>
/// Search for a movie or tv show via IMDb.
/// </summary>
function search_external($query, $kind)
{
    $query = strtolower(trim($query));
    $letter = substr($query, 0, 1);
    $type = strtolower(RequestType::get_type_from_str($kind));

    if ($type != RequestType::Movie && $type != RequestType::TVShow)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://www.audible.com/search?keywords=" . $query);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $text = curl_exec($ch);
        curl_close($ch);
        $find = strpos($text, "productListItem");
        if ($find === FALSE)
        {
            $obj = new \stdClass();
            $obj->length = 0;
            $obj->top = array();
            return json_encode($obj);
        }

        $results = array();
        while ($find !== FALSE && sizeof($results) < 5)
        {
            $title_start = strpos($text, "aria-label='", $find) + 12;
            $title_end = strpos($text, "'", $title_start);
            $title = html_entity_decode(substr($text, $title_start, $title_end - $title_start), ENT_QUOTES | ENT_HTML5);

            $ref_find = strpos($text, "bc-color-link", $title_end);
            $ref_start = strpos($text, "href=\"", $ref_find) + 6;
            $ref_end = strpos($text, "?", $ref_start);
            $ref = "https://audible.com" . substr($text, $ref_start, $ref_end - $ref_start);

            $id_start = strrpos($ref, "/") + 1;
            $id = substr($ref, $id_start);

            $img_find = strpos($text, "bc-image-inset-border", $find);
            $img_start = strpos($text, "src=", $img_find) + 5;
            $img_end = strpos($text, "\"", $img_start);
            $img = substr($text, $img_start, $img_end - $img_start);

            $rel_start = strpos($text, "Release date:", $img_find) + 13;
            $rel_end = strpos($text, "</span", $rel_start);
            $rel = str_replace("\n", "", str_replace(" ", "", substr($text, $rel_start, $rel_end - $rel_start)));

            $item = new \stdClass();
            $item->title = $title;
            $item->year = $rel;
            $item->thumb = $img;
            $item->id = $id;
            $item->ref = $ref;

            array_push($results, $item);

            $find = strpos($text, "productListItem", $rel_end);
        }

        $final_obj = new \stdClass();
        $final_obj->length = sizeof($results);
        $final_obj->top = $results;

        return json_encode($final_obj);
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
            $item->ref = "https://imdb.com/title/" . $result['id'];
            array_push($results, $item);

            if (sizeof($results) == 5)
            {
                break;
            }
        }

        $final_obj = new \stdClass();
        $final_obj->length = $len;
        $final_obj->top = $results;
        return json_encode($final_obj);
    }
    else
    {
        return json_error("Unknown IMDb error: " . $response);
    }
}

/// <summary>
/// Attempt to update a user's password, failing if the old password is incorrect,
/// the old password matches the new password, or the new password doesn't match it's confirmation
/// </summary>
function update_password($old_pass, $new_pass, $conf_pass)
{
    global $db;

    // First, verify that the old password they entered is correct
    $escaped_user_preserved = $db->real_escape_string($_SESSION['username']);
    $query = "SELECT password FROM users WHERE username='$escaped_user_preserved'";
    $result = $db->query($query);
    if (!$result || $result->num_rows != 1)
    {
        return db_error();
    }

    $old_hash = $result->fetch_row()[0];
    $result->close();
    if (!password_verify($old_pass, $old_hash))
    {
        return json_error("Old password is incorrect!");
    }

    if ($old_pass == $new_pass)
    {
        return json_error("New password must be different from current password!");
    }

    if ($new_pass != $conf_pass)
    {
        return json_error("Passwords don't match!");
    }

    $new_pass_hash = password_hash($new_pass, PASSWORD_DEFAULT);
    $query = "UPDATE users SET password='$new_pass_hash' WHERE username='$escaped_user_preserved'";
    $result = $db->query($query);
    if (!$result)
    {
        return db_error();
    }

    return json_success();
}

/// <summary>
/// Return the location and ISP (sorta) information for the given IP address
/// </summary>
function get_geo_ip($ip)
{
    global $db;
    $ip_safe = $db->real_escape_string($ip);

    # timestamp will return the local time by default, but 'new DateTime' returns UTC
    $query = "SELECT city, state, country, isp, CONVERT_TZ(timestamp, @@session.time_zone, '+00:00') AS `utc_timestamp` FROM ip_cache WHERE ip='$ip_safe'";
    $exists = FALSE;
    $result = $db->query($query);
    if ($result && $result->num_rows == 1)
    {
        $exists = TRUE;
        $row = $result->fetch_row();
        $result->close();

        $timestamp = new DateTime($row[4]);
        $now = new DateTime(date("Y-m-d H:i:s"));
        $diff = ($now->getTimestamp() - $timestamp->getTimestamp()) / 60;

        // Free tier is limited to 1500 api calls a day, so don't continuously ping them
        // Keeping the value around for 30 minutes gives us a large buffer of ~31 unique
        // IP addresses per 30 minute chunk.
        if ($diff <= 30)
        {
            // Less than 30 minutes since our last query. Use the cached value
            $json = new \stdClass();
            $json->city = $row[0];
            $json->state = $row[1];
            $json->country = $row[2];
            $json->isp = $row[3];
            $json->cached = TRUE;
            return json_encode($json);
        }
    }

    // If we have no cached value or our cached value is out of date, grab
    // it from the actual API
    $json = json_decode(curl(GEOIP_URL . $ip));
    if ($json == NULL)
    {
        return json_error("Failed to parse geoip response");
    }

    // We only care about certain fields
    $trimmed_json = new \stdClass();
    $trimmed_json->country = $json->country_name;
    $trimmed_json->state = $json->state_prov;
    $trimmed_json->city = $json->city;
    $trimmed_json->isp = $json->isp;
    $trimmed_json->cached = FALSE;

    $country = $db->real_escape_string($trimmed_json->country);
    $state = $db->real_escape_string($trimmed_json->state);
    $city = $db->real_escape_string($trimmed_json->city);
    $isp = $db->real_escape_string($trimmed_json->isp);
    $query = $exists ?
        "UPDATE ip_cache SET city='$city', state='$state', country='$country', isp='$isp', query_count=query_count+1 WHERE ip='$ip_safe'" :
        "INSERT INTO ip_cache (ip, city, state, country, isp) VALUES ('$ip_safe', '$city', '$state', '$country', '$isp')";

    $db->query($query);
    return json_encode($trimmed_json);
}

function set_external_id($req_id, $ext_id)
{
    global $db;
    $query = "UPDATE user_requests SET external_id=$ext_id WHERE id=$req_id";
    $db->query($query);
    return json_success();
}

function get_next_req($cur_id, $forward)
{
    global $db;
    if ($forward != 0 && $forward != 1)
    {
        return json_error("Bad direction. Expecting 0 or 1");
    }

    $query = "";
    $sort = $forward == 0 ? "DESC" : "ASC";
    $cmp = $forward == 0 ? "<" : ">";
    if ($_SESSION["level"] >= 100)
    {
        $query = "SELECT id FROM user_requests WHERE id " . $cmp . $cur_id . " ORDER BY id " . $sort . " LIMIT 1";
    }
    else
    {
        $query = "SELECT id FROM user_requests WHERE id " . $cmp . $cur_id . " AND username_id = " . $_SESSION["id"] . " ORDER BY id " . $sort . " LIMIT 1";
    }

    $result = $db->query($query);
    if (!$result || $result->num_rows == 0)
    {
        return '{"new_id":-1}';
    }

    return '{"new_id":' . $result->fetch_row()[0] . "}";
}

function get_requests($num, $page, $search, $filter)
{
    global $db;
    $id = (int)$_SESSION['id'];
    $level = (int)$_SESSION['level'];
    $offset = $num == 0 ? 0 : $num * $page;
    $filter = json_decode($filter);

    $query = "SELECT request_name, users.username, request_type, satisfied, request_date, satisfied_date, user_requests.id, users.id, external_id, poster_path, comment_count FROM user_requests INNER JOIN users ON user_requests.username_id=users.id ";

    $filter_status = array();
    if ($filter->status->pending)
    {
        array_push($filter_status, "satisfied=0");
    }
    if ($filter->status->complete)
    {
        array_push($filter_status, "satisfied=1");
    }
    if ($filter->status->declined)
    {
        array_push($filter_status, "satisfied=2");
    }
    if ($filter->status->inprogress)
    {
        array_push($filter_status, "satisfied=3");
    }
    if ($filter->status->waiting)
    {
        array_push($filter_status, "satisfied=4");
    }

    $filter_type = array();
    if ($filter->type->movies)
    {
        array_push($filter_type, "request_type=1");
    }
    if ($filter->type->tv)
    {
        array_push($filter_type, "request_type=2");
    }
    if ($filter->type->other)
    {
        array_push($filter_type, "request_type=10");
    }

    if (count($filter_status) == 0 || count($filter_type) == 0)
    {
        // Filter removes all items, just return an empty object
        $requests = new \stdClass();
        $requests->count = 0;
        $requests->entries = array();
        $requests->total = 0;
        return json_encode($requests);
    }

    $filter_status_string = join(" OR ", $filter_status);
    $filter_type_string = join(" OR ", $filter_type);
    $filter_string = "";
    if ($level != 100)
    {
        $filter_string =
        "WHERE user_requests.username_id=$id AND ("
        . $filter_status_string
        . ") AND ("
        . $filter_type_string
        . ") ";
    }
    else
    {
        $filter_string = " WHERE (" . $filter_status_string . ") AND (" . $filter_type_string . ") ";

        $user = (int)$filter->user;
        if ($user != -1)
        {
            $filter_string .= "AND (user_requests.username_id=$user) ";
        }
    }

    if (strlen($search) > 0)
    {
        $search = $db->real_escape_string($search);
        $filter_string .= "AND (request_name LIKE '%$search%') ";
    }

    $query .= $filter_string;
    $query .= "ORDER BY ";
    $reverse = FALSE;
    switch ($filter->sort)
    {
        case "request":
            $query .= "user_requests.id ";
            break;
        case "update":
            $query .= "user_requests.satisfied_date ";
            break;
        case "title":
            $query .= "user_requests.request_name ";
            $reverse = TRUE;
            break;
        default:
            return json_error("Invalid sort option");
    }

    switch ($filter->order)
    {
        case "desc":
            $query .= ($reverse ? "ASC " : "DESC ");
            break;
        case "asc":
            $query .= ($reverse ? "DESC " : "ASC ");
            break;
        default:
            return json_error("Invalid sort order");
    }

    if ($num != 0)
    {
        $query .= "LIMIT $num ";
    }

    if ($offset != 0)
    {
        $query .= "OFFSET $offset";
    }

    $result = $db->query($query);
    {
        if (!$result)
        {
            return db_error();
        }
    }

    $requests = new \stdClass();
    $requests->count = $result->num_rows;
    $requests->entries = array();
    while ($row = $result->fetch_row())
    {
        $request = new \stdClass();
        $request->n = $row[0]; // Request Name
        $request->r = $row[1]; // Requester
        $request->t = $row[2]; // Request Type
        $request->a = $row[3]; // Addressed
        $request->rd = $row[4]; // Request Date
        $request->ad = $row[5]; // Addressed Date
        $request->rid = $row[6]; // Request ID
        $request->uid = $row[7]; // Requester ID
        $request->eid = $row[8]; // External ID
        $request->c = $row[10]; // Comment count
        $poster_path = $row[9];
        if (!$row[9])
        {
            // If we don't have a poster path, get it
            $poster_path = get_poster_path($request);
        }

        if ($poster_path)
        {
            $request->p = $poster_path;
        }

        array_push($requests->entries, $request);
    }

    $query = "SELECT COUNT(*) FROM user_requests " . $filter_string;
    $result = $db->query($query);
    if (!$result)
    {
        return db_error();
    }

    $requests->total = (int)$result->fetch_row()[0];

    return json_encode($requests);
}

function get_poster_path($request)
{
    global $db;
    $type = RequestType::get_type((int)$request->t);
    $endpoint = "";
    $json = NULL;
    $continue = false;

    // Some early requests don't have an external id. Don't try
    // to get a poster for a null item, as it will fail anyway
    if ($request->eid)
    {
        switch ($type)
        {
            case RequestType::Movie:
                $json = run_query("movie/" . $request->eid);
                break;
            case RequestType::TVShow:
                $json = run_query("tv/" . $request->eid);
                break;
            default:
                $continue = TRUE;
                break;
        }
    }

    if ($continue)
    {
        return "/viewstream.png";
    }

    // Our search didn't return anything. Revert to defaults
    if ($json == NULL)
    {
        switch ($type)
        {
            case RequestType::Movie:
                return "/moviedefault.png";
            case RequestType::TVShow:
                return "/tvdefault.png";
            default:
                return "/viewstream.png";
        }
    }

    $json = json_decode($json);
    $poster_path = '';
    if (isset($json->poster_path) && $json->poster_path)
    {
        $poster_path = $json->poster_path;
    }
    else
    {
        // We got a valid response, but there's no poster. Give up hope
        // of finding a poster and set it to the default image
        switch ($type)
        {
            case RequestType::Movie:
                $poster_path = "/moviedefault.png";
                break;
            case RequestType::TVShow:
                $poster_path = "/tvdefault.png";
                break;
            default:
                $poster_path = "/viewstream.png";
                break;
        }
    }

    $query = "UPDATE user_requests SET poster_path='$poster_path' WHERE id=$request->rid";
    $inner_res = $db->query($query);
    if ($inner_res === FALSE)
    {
        return db_error();
    }

    return $poster_path;
}

/// <summary>
/// Get the number of new activities since the user last visited the activity page
/// </summary>
function get_new_activity_count()
{
    global $db;

    $current_user = $_SESSION['id'];
    $query = "SELECT `last_viewed` FROM `activity_status` WHERE `user_id`=$current_user";
    $active_result = $db->query($query);
    $last_active = new DateTime('1970-01-01 00:00:00');
    if ($active_result->num_rows != 0)
    {
        $last_active = new DateTime($active_result->fetch_row()[0]);
    }

    $active_string = $last_active->format('Y-m-d H:i:s');
    $query = "SELECT `type`, `user_id`, `admin_id`, `request_id`, `data`, `timestamp` FROM `activities` WHERE `timestamp` > '$active_string' ";

    if ($_SESSION['level'] < 100)
    {
        $query .= "AND `user_id`=$current_user ";
    }

    $query .= "ORDER BY `timestamp` DESC";

    $result = $db->query($query);
    if ($result === FALSE)
    {
        return db_error();
    }

    return "{\"new\" : $result->num_rows}";
}

/// <summary>
/// Get all relevant activites for the current user. If the current user is an admin, return
/// all activities, otherwise return activities that directly relate to the current user.
/// </summary>
function get_activites($num, $page, $search, $filter)
{
    global $db;

    $offset = $num == 0 ? 0 : $num * $page;
    $current_user = $_SESSION['id'];
    $filter = json_decode($filter);
    $query = "SELECT `type`, `user_id`, `admin_id`, `request_id`, `data`, `timestamp`, `request_name`, `poster_path` FROM `activities` ";

    $filter_string = "INNER JOIN `user_requests` ON `activities`.`request_id`=`user_requests`.`id` ";

    if ($_SESSION['level'] < 100)
    {
        $filter_string .= "WHERE `user_id`=$current_user ";
        if (!$filter->type->mine)
        {
            $filter_string .= "AND `admin_id` != 0 ";
        }
    }
    else
    {
        $filter_string .= " WHERE 1 ";
        if (!$filter->type->mine)
        {
            $filter_string .= "AND `admin_id` != $current_user AND `user_id` != $current_user ";
        }
        
        if ($filter->user != -1)
        {
            $filter_string .= "AND `user_id` = $filter->user ";
        }
    }

    if (!$filter->type->new)
    {
        $filter_string .= "AND `type` != 1 ";
    }

    if (!$filter->type->comment)
    {
        $filter_string .= "AND `type` != 2 ";
    }

    if (!$filter->type->status)
    {
        $filter_string .= "AND `type` != 3 ";
    }

    if (strlen($search) > 0)
    {
        $search = $db->real_escape_string($search);
        $filter_string .= "AND `request_name` LIKE '%$search%' ";
    }

    $query .= $filter_string;

    $query .= "ORDER BY `timestamp` " . $filter->order . " ";

    if ($num != 0)
    {
        $query .= "LIMIT $num ";
    }

    if ($offset != 0)
    {
        $query .= "OFFSET $offset";
    }

    $result = $db->query($query);
    if ($result === FALSE)
    {
        return db_error();
    }

    $query = "SELECT `last_viewed` FROM `activity_status` WHERE `user_id`=$current_user";
    $active_result = $db->query($query);
    $last_active = new DateTime('1970-01-01 00:00:00');
    if ($active_result->num_rows != 0)
    {
        $last_active = new DateTime($active_result->fetch_row()[0]);
    }

    // SELECT `type`, `user_id`, `admin_id`, `request_id`, `data`, `timestamp`, `request_name`, `poster_path`, `users`.`id` AS `uid`, `username` FROM `activities` INNER JOIN `user_requests` ON `activities`.`request_id`=`user_requests`.`id` INNER JOIN `users` ON `activities`.`user_id`=`users`.`id` WHERE `request_name` LIKE '%ANA%'

    $activities = new \stdClass();
    $activities->activities = array();
    $activities->new = 0;
    $activities->count = $result->num_rows;
    while ($row = $result->fetch_assoc())
    {
        $ts = new DateTime($row['timestamp']);
        if ($ts->getTimestamp() - $last_active->getTimestamp() > 0)
        {
            $activities->new++;
        }

        $activity = new \stdClass();
        $activity->type = $row['type'];
        $activity->timestamp = $row['timestamp'];
        $activity->username = $_SESSION['username'];
        $activity->uid = $row['user_id'];
        $activity->rid = $row['request_id'];
        $activity->value = $row['request_name'];
        $activity->poster = $row['poster_path'];

        $admin_id = $row['admin_id'];
        $inner_query;
        if ($admin_id == 0)
        {
            $inner_query = "SELECT id, username FROM users WHERE id=$activity->uid";
        }
        else
        {
            $inner_query = "SELECT id, username FROM users WHERE id=$admin_id";
        }

        $inner_result = $db->query($inner_query);
        if ($inner_result === FALSE)
        {
            return db_error();
        }

        if ($inner_result->num_rows == 0)
        {
            return json_error("Unable to get username from activity user id $admin_id");
        }

        $inner_row = $inner_result->fetch_assoc();
        $activity->username = $inner_row['username'];
        $activity->uid = $inner_row['id'];
        $inner_result->close();

        $activity_rid = $row['request_id'];

        if ($row['type'] == 3) // Status change
        {
            $statuses = array("Pending", "Complete", "Denied", "In Progress", "Waiting");
            $data = json_decode($row['data']);
            if ($data->status >= count($statuses))
            {
                $activity->status = "Unknown";
            }
            else
            {
                $activity->status = $statuses[$data->status];
            }
        }

        array_push($activities->activities, $activity);
    }

    $query = "SELECT COUNT(*) FROM activities " . $filter_string;

    $result = $db->query($query);
    if (!$result)
    {
        return db_error();
    }

    $activities->total = (int)$result->fetch_row()[0];

    // Assume if we're processing this request, the user is viewing the activity page and we should
    // update their last seen time. Can't update right when we visit the page, because it will appear
    // that we never have new activities.
    update_last_seen();
    return json_encode($activities);
}

/// <summary>
/// Update the 'last seen' activities time so we can correctly show the number of new activities for a user
/// </summary>
function update_last_seen()
{
    global $db;
    $uid = $_SESSION['id'];
    $query = "INSERT INTO `activity_status`
        (`user_id`, `last_viewed`) VALUES ($uid, NOW())
        ON DUPLICATE KEY UPDATE `last_viewed`=NOW()";
    $result = $db->query($query);
    if ($result === FALSE)
    {
        error_and_exit(500, db_error());
    }
}

/// <summary>
/// Adds a create activity to the activity table
/// </summary>
function add_create_activity($rid, $uid)
{
    global $db;
    $query = "INSERT INTO `activities` (`type`, `user_id`, `request_id`, `data`) VALUES (1, $uid, $rid, '{}')";
    return $db->query($query) !== FALSE;
}

function add_comment_activity($cid, $rid, $ruid, $uid)
{
    global $db;
    $admin_id = ($ruid == $uid ? 0 : $uid);
    $data = "{\"comment_id\" : $cid}";
    $query = "INSERT INTO `activities` (`type`, `user_id`, `admin_id`, `request_id`, `data`) VALUES (2, $ruid, $admin_id, $rid, '$data')";
    return $db->query($query) !== FALSE;
}

function run_query($endpoint)
{
    $query = TMDB_URL . $endpoint . TMDB_TOKEN;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $query);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);

    $return = curl_exec($ch);
    curl_close($ch);
    return $return;
}

function log_error($error, $stack)
{
    global $db;
    $query = "INSERT INTO `js_errors` (`error`, `stack`) VALUES ('$error', '$stack')";
    $result = $db->query($query);
    if ($result)
    {
        return json_success();
    }

    return db_error();
}

/// <summary>
/// Sets up a reset token for the given user
/// </summary>
function forgot_password($username)
{
    global $db;
    $user_normalized = $db->real_escape_string(strtolower($username));
    $query = "SELECT id FROM users WHERE `username_normalized`='$user_normalized'";
    $result = $db->query($query);
    if (!$result || $result->num_rows === 0)
    {
        return '{ "Method" : -1 }';
    }

    $id = (int)$result->fetch_row()[0];
    $result->close();

    $query = "SELECT `used`, CONVERT_TZ(timestamp, @@session.time_zone, '+00:00') AS `utc_timestamp` FROM `password_reset` WHERE user_id=$id ORDER BY `timestamp` DESC";
    $result = $db->query($query);
    if ($result && $result->num_rows > 0)
    {
        $row = $result->fetch_assoc();
        $diff = (new DateTime(date("Y-m-d H:i:s")))->getTimestamp() - (new DateTime($row['utc_timestamp']))->getTimestamp();
        if ($diff < 5 * 60)
        {
            return '{ "Method" : 3 }';
        }

        if ($diff < 20 * 60 && (int)$row['used'] == 1)
        {
            return '{ "Method" : 3 }';
        }
    }

    $query = "SELECT * FROM user_info WHERE userid=$id";
    $result = $db->query($query);
    if (!$result)
    {
        return '{ "Method" : 0 }';
    }

    $info = $result->fetch_assoc();
    $method = 0;
    $email = "";
    if ((int)$info['phone'] != 0)
    {
        $method = 1;
        $error = FALSE;
        $email = get_phone_email($info['phone'], $info['carrier'], $error);
        if ($error)
        {
            return '{ "Method" : 4 }';
        }
    }
    else if ($info['email'] != NULL)
    {
        $method = 2;
        $email = $info['email'];
    }

    if ($method == 0)
    {
        return '{ "Method" : 0 }';
    }

    $token = bin2hex(random_bytes(10));
    $query = "INSERT INTO `password_reset` (`user_id`, `token`) VALUES ($id, '$token')";
    $result = $db->query($query);
    if (!$result)
    {
        return '{ "Method" : 4 }';
    }

    $message = "Hello, $username. You recently requested a password reset at plex.danrahn.com. Click the following link to reset your password: https://plex.danrahn.com/reset?token=$token\n\nIf you did not request a password reset, you can ignore this message.";
    send_email_forget($email, $message, "Password Reset");

    return '{ "Method" : ' . $method . ' }';
}

/// <summary>
/// Resets the password for the user identified by the given token, assuming it's valid
/// </summary>
function reset_password($token, $password, $confirm)
{
    global $db;
    if ($password != $confirm)
    {
        return json_error("Passwords don't match!");
    }

    $query = "SELECT `user_id`, `used`, CONVERT_TZ(timestamp, @@session.time_zone, '+00:00') AS `utc_timestamp` FROM `password_reset` WHERE `token`='$token'";

    $result = $db->query($query);
    if (!$result || $result->num_rows != 1)
    {
        return json_error("Invalid token, please go through the reset process again.");
    }

    $row = $result->fetch_assoc();
    $id = $row['user_id'];
    $diff = (new DateTime(date("Y-m-d H:i:s")))->getTimestamp() - (new DateTime($row['utc_timestamp']))->getTimestamp();
    if ($diff < 0 || $diff > 20 * 60)
    {
        return json_error("Token expired");
    }

    if ((int)$row['used'] == 1)
    {
        return json_error("This token has already been used to reset your password.");
    }

    $query = "SELECT `token` FROM `password_reset` WHERE `user_id`=$id ORDER BY `timestamp` DESC";
    $result = $db->query($query);
    if (!$result)
    {
        return json_error("Something went wrong. Please try again later.");
    }

    if ($result->fetch_row()[0] != $token)
    {
        return json_error("This token has been superseded by a newer reset token. Please use the new token or request another reset.");
    }

    $new_pass_hash = password_hash($password, PASSWORD_DEFAULT);
    $query = "UPDATE `users` SET password='$new_pass_hash' WHERE id=$id";
    $result = $db->query($query);
    if (!$result)
    {
        db_error();
    }

    $query = "UPDATE `password_reset` SET `used`=1 WHERE token='$token'";

    return json_success();
}

/// <summary>
/// Allows an administrator to send a reset link for an arbitrary username to
/// an arbitrary email address. Much fewer safeguards as forgot_password
/// </summary>
function forgot_password_admin($username, $email)
{
    if ($_SESSION['level'] < 100)
    {
        return json_error("You can't do that!");
    }

    global $db;
    $user_normalized = $db->real_escape_string(strtolower($username));
    $query = "SELECT id FROM users WHERE `username_normalized`='$user_normalized'";
    $result = $db->query($query);
    if (!$result || $result->num_rows === 0)
    {
        return json_error("User does not exist!");
    }

    $id = $result->fetch_row()[0];
    $token = bin2hex(random_bytes(10));
    $query = "INSERT INTO `password_reset` (`user_id`, `token`) VALUES ($id, '$token')";
    $result = $db->query($query);
    if (!$result)
    {
        return db_error();
    }

    $message = "Hello, $username. You recently requested a password reset at plex.danrahn.com. Click the following link to reset your password: https://plex.danrahn.com/reset?token=$token\n\nIf you did not request a password reset, you can ignore this message.";
    send_email_forget($email, $message, "Password Reset");

    return json_success();
}

function can_modify_comment($comment_id)
{
    global $db;
    $result = $db->query("SELECT user_id FROM request_comments WHERE id=$comment_id");
    if (!$result || $result->num_rows == 0)
    {
        return FALSE;
    }

    $uid = $_SESSION['id'];
    $cuid = $result->fetch_row()[0];
    if ($cuid != $uid)
    {
        return FALSE;
    }

    return TRUE;
}

function delete_comment($comment_id)
{
    global $db;

    // First, make sure the user is allowed to delete this comment
    if (!can_modify_comment($comment_id))
    {
        return json_error("You don't have permission to delete that comment!");
    }

    $result = $db->query("DELETE FROM request_comments WHERE id=$comment_id");
    if (!$result)
    {
        return db_error();
    }

    return json_success();
}

function edit_comment($comment_id, $content)
{
    global $db;

    if (!can_modify_comment($comment_id))
    {
        return json_error("You don't have permission to edit that comment!");
    }

    $comment = $db->real_escape_string($content);
    $result = $db->query("UPDATE request_comments SET content='$comment' WHERE id=$comment_id");
    if (!$result)
    {
        return json_error("Something went wrong, please try again later");
    }

    return json_success();
}

/// <summary>
/// Get the contents of the given url
/// </summary>
function curl($url)
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

    $return = curl_exec($ch);

    if (curl_errno($ch))
    {
        $return = '{ "curl error" : "' . curl_error($ch) . '" }';
    }
    else
    {
        switch ($http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE))
        {
            case 200:
                break;
            default:
                $return = '{ "Bad curl response" : ' . $http_code . ' }';
                break;
        }
    }

    curl_close($ch);
    return $return;
}
?>
