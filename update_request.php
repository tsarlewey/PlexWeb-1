<?php
/// <summary>
/// Processes request updates. Expects JSON input that lists all the updates and sends out batched
/// notifications if necessary. Many elements copied from process_request.php, modified to be applicable
/// for multiple updates instead of a single update.
///
/// JSON format:
///
/// {
///   type : 'req_update',
///   data :
///     [
///        { id: <id>, kind: <kind>, content: <content> },
///        ...
///     ]
/// }
/// </summary>


session_start();

require_once "includes/common.php";
require_once "includes/config.php";

verify_loggedin();

$payload = json_decode(file_get_contents("php://input"));
if (!$payload || gettype($payload) != "object" || !$payload->data || gettype($payload->data) != "array")
{
    json_error_and_exit(json_encode($payload));
}

$type = $payload->type;
$data = $payload->data;

switch ($type)
{
    case "req_update":
        json_message_and_exit(process_request_update($data));
    default:
        json_error_and_exit("Unknown request type");
}

function get_status_string($status)
{
    switch ($status)
    {
        case 0:
            return "Pending";
        case 1:
            return "Complete";
        case 2:
            return "Denied";
        case 3:
            return "In Progress";
        case 4:
            return "Waiting";
        default:
            return "Unknown";
    }
}

/// <summary>
/// High level method that processes multiple request updates
/// </summary>
function process_request_update($requests)
{
    $level = UserLevel::current();
    $sesh_id = (int)$_SESSION["id"];

    // An associative array, mapping contacts (email or phone number)
    // to an array of objects representing individual updates
    $alerts = [];
    $domain = SITE_SHORT_DOMAIN;
    foreach ($requests as $request)
    {
        $req_id = (int)$request->id;
        $requester = get_user_from_request($req_id);
        if ($requester->id === -1)
        {
            return json_error("Bad request");
        }

        if ($level < UserLevel::Admin && $requester->id != $sesh_id)
        {
            return json_error("Not authorized");
        }

        $kind = $request->kind;
        $content = $request->content;
        $contact_info = [];
        $request_name = "";
        // Currently only status updates go through this update channel, so block everything else
        switch ($kind)
        {
            case "status":
                if ($level < UserLevel::Admin)
                {
                    // Only admins can change status
                    return json_error("Not authorized");
                }

                if (!update_req_status($req_id, (int)$content, $requester, $sesh_id, $contact_info, $request_name))
                {
                    return json_error("Unable to update status");
                }
                break;

            default:
                return json_error("Unknown request update type: " . $kind);

        }

        // If we have contact information, add it to the associative array
        foreach ($contact_info as $contact)
        {
            if (!isset($alerts[$contact->email]))
            {
                $alerts[$contact->email] = new \stdClass();
                $alerts[$contact->email]->is_phone = $contact->is_phone;
                $alerts[$contact->email]->messages = [];
            }

            $data = new \stdClass();
            $data->kind = $kind;
            $data->content = $content;
            $data->request = $request_name;
            $data->request_id = $req_id;
            array_push($alerts[$contact->email]->messages, $data);
        }
    }

    foreach ($alerts as $contact => $data)
    {
        // Very simple messages for phones, as they must be less than 160 characters. Also, no subject
        if ($data->is_phone)
        {
            $text = "";
            if (count($data->messages) != 1)
            {
                $text = "Multiple requests have been updated. See them here: https://$domain/requests.php";
            }
            else
            {
                $message = $data->messages[0];
                $max = 160;
                switch ($message->kind)
                {
                    case "adm_cm":
                        $text = "A comment has been added your your request for $message->request. See it here: https://$domain/r/$message->request_id";
                        if (strlen($text) > $max)
                        {
                            $text = "A comment has been added to one of your requests. See it here: https://$domain/r/$message->request_id";
                        }
                        break;
                    case "status":
                        $status = get_status_string((int)$message->content);

                        $text = "The status of your request for $message->request has changed to $status. See it here: https://$domain/r/$message->request_id";
                        if (strlen($text) > $max)
                        {
                            $text = "The status of one of your requests has changed to $status. See it here: https://$domain/r/$message->request_id";
                        }
                        break;
                    default:
                        return json_error("Error sending notifications");
                }
            }

            $subject = "";
            send_email_forget($contact, $text, '');
        }
        else
        {
            $text = '';
            $multi = count($data->messages) > 1;
            // For actual email addresses, add some additional styling. Largely copied from send_notifications_if_needed
            $style_noise = 'url("https://' . $domain . '/res/noise.8b05ce45d0df59343e206bc9ae78d85d.png")';
            $email_style = '<style>.markdownEmailContent { background: rgba(0,0,0,0) ' . $style_noise . ' repeat scroll 0% 0%; ';
            $email_style .= 'color: #c1c1c1 !important; border: 5px solid #919191; } ';
            $email_style .= '.md { color: #c1c1c1 !important; } ';
            $email_style .= '.h1Title { margin-top: 0; padding: 20px; border-bottom: 5px solid #919191; } ';
            $email_style .= '</style>';

            $body_background = "url('https://' . $domain . '/res/preset-light.770a0981b66e038d3ffffbcc4f5a26a4.png')";

            $content = '';
            $email = '<html><head><style>' . file_get_contents("style/markdown.css") . '</style>';
            $email .= $email_style;
            $email .= '</head><body style="background-image: ' . $body_background . '; background-size: cover;">';
            $email .=   '<div class="markdownEmailContent">';
            $email .=     '<h1 class="h1Title">Request Update</h1>';
            if ($multi)
            {
                $email .= '<div class="md" style="margin-left: 10px"><h3 style-"padding: 0 20px 0 20px;">The status of multiple requests has changed:</h3>';
                $email .= '<ul>';
                foreach ($alerts[$contact]->messages as $message)
                {
                    if ($message->kind != "status")
                    {
                        // We shouldn't get here
                        continue;
                    }

                    $status = get_status_string((int)$message->content);
                    $link = '<a href="https://' . $domain . '/r/' . $message->request_id . '">' . $message->request . '</a>';
                    $email .= '<li>The status of ' . $link . ' has changed to <em>' . $status . '</em></li>';
                }

                $email .= '</ul><br></div>';
            }
            else
            {
                $message = $alerts[$contact]->messages[0];
                $status = get_status_string((int)$message->content);
                $email .= '<div class="md" style="margin-left: 10px">';
                $email .= '<div style="padding: 0 20px 0 20px;">';
                $email .= 'The status of your request for <strong>' . $message->request . '</strong> has changed to <em>' . $status . '</em>, ';
                $email .= 'view it <a href="https://' . $domain . '/r/' . $message->request_id . '">here</a>.</div>';
                $email .= '<br></div>';
            }

            $email .=   '</div>';
            $email .= '</div></body></html>';
            $text = $email;
            $subject = "Plex Request Update";
            send_email_forget($contact, $text, $subject);
        }
    }

    return json_success();
}

/// <summary>
/// Updates the status of the given request
/// </summary>
function update_req_status($req_id, $status, $requester, $admin_id, &$contact_info, &$request_name)
{
    global $db;
    $request_query = "SELECT request_type, request_name, satisfied FROM user_requests WHERE id=$req_id";
    $result = $db->query($request_query);
    if (!$result)
    {
        return FALSE;
    }

    $row = $result->fetch_assoc();
    $result->close();
    $request_type = RequestType::get_type($row['request_type']);
    $request_name = $row['request_name'];
    if ($status == $row['satisfied'])
    {
        // No change in status, return success.
        return TRUE;
    }

    if ($request_type == RequestType::StreamAccess)
    {
        // Need to adjust permissions
        $update_level = "";
        $level = UserLevel::get_type($requester->level);
        if ($status == 1 && $level < UserLevel::Regular)
        {
            $update_level = "UPDATE users SET level=20 WHERE id=$requester->id";
            if (!$db->query($update_level))
            {
                return FALSE;
            }
        }
        else if ($status == 2 && $level >= UserLevel::Regular)
        {
            // Access revoked. Bring them down a peg
            $update_level = "UPDATE users SET level=10 WHERE id=$requester->id";
        }

        if (!empty($update_level) && !$db->query($update_level))
        {
            return FALSE;
        }
    }

    // Update the actual request
    $query = "UPDATE user_requests SET satisfied=$status WHERE id=$req_id";
    if (!$db->query($query))
    {
        return FALSE;
    }

    $data = "{\"status\" : $status}";
    $query = "INSERT INTO `activities`
        (`type`, `user_id`, `admin_id`, `request_id`, `data`) VALUES
        (3, $requester->id, $admin_id, $req_id, '$data')";
    if (!$db->query($query))
    {
        // We basically just have to hope that this succeeds, because we don't
        // want to cancel the whole operation if this fails.
    }

    get_contact_info($requester, $contact_info);
    return TRUE;
}

/// <summary>
/// Updates the admin comment for the given request
/// </summary>
function update_admin_comment($req_id, $content, $requester, &$contact_info, &$request_name)
{
    global $db;
    $content = $db->real_escape_string($content);
    $query = "SELECT admin_comment, request_name FROM user_requests WHERE id=$req_id";
    $result = $db->query($query);
    if (!$result || $result->num_rows === 0)
    {
        return FALSE;
    }

    $row = $result->fetch_row();
    $old_comment = $row[0];
    $request_name = $row[1];
    $result->close();
    if (strcmp($old_comment, $content) === 0)
    {
        // Comments are the same, do nothing
        return TRUE;
    }

    $query = "UPDATE user_requests SET admin_comment = '$content' WHERE id=$req_id";
    if (!$db->query($query))
    {
        return FALSE;
    }

    // Failure to send notifications won't be considered a failure
    get_contact_info($requester, $contact_info);
    return TRUE;
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
        return FALSE;
    }

    // TODO: Send message to admins who want updates here
    return TRUE;
}

/// <summary>
/// Update the contact_info array with the given user's information if
/// they're signed up for alerts
/// </summary>
function get_contact_info($requester, &$contact_info)
{
    if ($requester->info->phone_alerts && $requester->info->phone != 0)
    {
        $to = "";
        $phone = $requester->info->phone;
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
                break;
        }

        if (!empty($to))
        {
            $info = new \stdClass();
            $info->email = $to;
            $info->is_phone = TRUE;
            array_push($contact_info, $info);
        }
    }

    if ($requester->info->email_alerts && !empty($requester->info->email))
    {
        $info = new \stdClass();
        $info->email = $requester->info->email;
        $info->is_phone = FALSE;
        array_push($contact_info, $info);
    }

    return;
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

?>