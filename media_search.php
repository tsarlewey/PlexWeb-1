<?php
session_start();

require_once "includes/config.php";
require_once "includes/common.php";

requireSSL();
verify_loggedin(TRUE /*redirect*/);

$type = RequestType::get_type((int)param_or_die('type'));
$imdb = try_get('imdb');
$audible = try_get('audible');
$by_id = try_get('by_id');
$query = param_or_die('query');

if ($imdb == "true")
{
    $endpoint = "find/" . $query;
    $params = [
        'external_source' => 'imdb_id'
    ];

    json_message_and_exit(run_query($endpoint, $params));
}

if ($audible == "true")
{
    json_message_and_exit(get_specific_audible($query));
}

if ($by_id)
{
    switch($type)
    {
        case RequestType::Movie:
            $endpoint = "movie/" . $query;
            $params = [ ];
            json_message_and_exit(run_query($endpoint, $params));
        case RequestType::TVShow:
            $endpoint = "tv/" . $query;
            $params = [ ];

            // TV shows don't list imdb id in the main results page. Query for that as well and append it to the object
            json_message_and_exit(json_encode(parse_single_tv_show(run_query($endpoint, $params))));
        default:
            json_error_and_exit("Unsupported media type");
    }
}

switch ($type)
{
    case RequestType::Movie:
        $endpoint = "search/movie";
        $params = [ "query" => urlencode($query) ];
        json_message_and_exit(run_query($endpoint, $params));
    case RequestType::TVShow:
        $endpoint = "search/tv";
        $params = [ "query" => urlencode($query) ];
        json_message_and_exit(run_query($endpoint, $params));
    case RequestType::AudioBook:
        json_message_and_exit(search_audible($query));
    default:
        json_error_and_exit("Unsupported media type");

}

function parse_single_tv_show($show)
{
    $show = json_decode($show);
    $id = $show->id;
    $show->imdb_id = get_imdb_id_for_tv($show->id);
    return $show;
}

function get_imdb_id_for_tv($id)
{
    $endpoint = "tv/" . $id . "/external_ids";
    $parameters = [];
    $result = json_decode(run_query($endpoint, $parameters));
    return $result->imdb_id;
}

function run_query($endpoint, $params)
{
    $query = TMDB_URL . $endpoint . TMDB_TOKEN;
    foreach ($params as $key => $value)
    {
        $query .= "&" . $key . "=" . $value;
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $query);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);

    $return = curl_exec($ch);
    curl_close($ch);
    return $return;
}

/// <summary>
/// Hacky way to get audiobook results by parsing the raw HTML of
/// an audible search. Pretty slow.
/// </summary>
function search_audible($query)
{
    $query = urlencode(strtolower(trim($query)));
    $curl_time = microtime(TRUE);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://www.audible.com/search?keywords=" . $query);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $text = curl_exec($ch);
    curl_close($ch);

    $time_start = microtime(TRUE);

    $find = strpos($text, "productListItem");
    if ($find === FALSE)
    {
        $obj = new \stdClass();
        $obj->length = 0;
        $obj->top = array();
        $obj->message = "Error processing https://www.audible.com/search?keywords=" . $query;
        return json_encode($obj);
    }

    $results = array();
    while ($find !== FALSE)
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
    $final_obj->results = $results;

    return json_encode($final_obj);
}

/// <summary>
/// Parse the HTML of a specific audible page. If the item doesn't exist,
/// sets 'valid' to false and returns
/// </summary>
function get_specific_audible($id)
{
    $ch = curl_init();
    $url = "https://audible.com/pd/" . $id . "?ipRedirectOverride=true";
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, TRUE);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);

    $text = curl_exec($ch);

    $obj = new \stdClass();
    $obj->valid = 1;

    // Very prone to break if/when audible changes this string
    if (strpos($text, "Shucks. This product isn't available.") !== FALSE)
    {
        $obj->valid = 0;
        return json_encode($obj);
    }

    $title_find = strpos($text, "<meta property=\"og:title\"");
    $title_start = strpos($text, "content=\"", $title_find) + 9;
    $title_end = strpos($text, " />", $title_start) - 1;
    $obj->title = substr($text, $title_start, $title_end - $title_start);

    $image_find = strpos($text, "<meta property=\"og:image\"", $title_end);
    $image_start = strpos($text, "content=\"", $image_find) + 9;
    $image_end = strpos($text, "\"", $image_start);
    $obj->thumb = substr($text, $image_start, $image_end - $image_start);

    return json_encode($obj);
}
?>