/// <summary>
/// Logic to display notification/activities relevant to the current user. Implements tableCommon
/// </summary>

window.addEventListener("load", function()
{
    // For activities, reset the filter on page load
    Table.Filter.set(Table.Filter.default(), false /*update*/);
    Table.setPage(0);
    Table.update();
});

/// <summary>
/// Get activities from the server, based on the current filter
/// </summary>
/// <param name="searchValue">Optional search term to further filter results based on substring matching</param>
function getActivities(searchValue="")
{
    posterMax = 0;
    let parameters =
    {
        type : ProcessRequest.GetActivities,
        num : Table.getPerPage(),
        page : Table.getPage(),
        search : searchValue,
        filter : JSON.stringify(Table.Filter.get())
    };

    let successFunc = function(message)
    {
        Table.clear();
        buildActivities(message);

        if (searchValue.length != 0)
        {
            $$(".searchBtn").click();
        }
    };

    let failureFunc = function()
    {
        Table.displayInfoMessage("Error loading activities. Pleases try again later. If this problem persists, contact the site administrator");
    };

    sendHtmlJsonRequest("process_request.php", parameters, successFunc, failureFunc);
}

/// <summary>
/// Returns whether we support table search. We do for activities
/// </summary>
Table.supportsSearch = function()
{
    return true;
};

/// <summary>
/// Types of activities that are shown
/// </summary>
const Activity =
{
    AddRequest : 1,
    AddComment : 2,
    StatusChange : 3
};

/// <summary>
/// Gets the title of an activity, including a link to the relevant request
/// </summary>
function getTitleText(activity)
{
    let name = activity.username == attrib("username") ? "You" : activity.username;
    let plainText;
    let linkText;

    switch (parseInt(activity.type))
    {
        case Activity.AddRequest:
            if (activity.value == "ViewStream")
            {
                plainText = `${name} requested permission to `;
                linkText = "view active streams";
            }
            else
            {
                plainText = `${name} added a request for `;
                linkText = activity.value;
            }

            break;
        case Activity.AddComment:
            plainText = `${name} added a comment to the request for `;
            linkText = activity.value;
            break;
        case Activity.StatusChange:
            if (activity.username == attrib("username"))
            {
                plainText = `You changed the status of the request for ${activity.value} to `;
                linkText = activity.status;
            }
            else
            {
                plainText = `The status of the request for ${activity.value} changed to `;
                linkText = activity.status;
            }

            break;
        default:
            plainText = "Error getting activity details. ";
            linkText = "Click here to view the request.";
            Log.error(activity.type, "Unknown activity type");
            break;
    }

    return { plain : plainText, link : linkText };
}

let posterMax = 0;
function onPosterLoaded()
{
    let width = getComputedStyle(this).width;
    width = parseInt(width.substring(0, width.length - 2));
    if (width > posterMax)
    {
        posterMax = width;
        $(".imgHolder").forEach(function(poster) { poster.style.width = width + "px"; });
    }
}

/// <summary>
/// Creates an activity for the activity table
/// </summary>
/// <param name="newActivity">True if the user has not seen this request yet</param>
function buildActivity(activity, newActivity)
{
    let holder = Table.itemHolder();
    if (newActivity)
    {
        holder.classList.add("newActivity");
    }

    let imgHolder = buildNode("div", { class : "imgHolder" });
    if (posterMax != 0)
    {
        imgHolder.style.width = posterMax + "px";
    }
    let imgA = buildNode("a", { href : `request.php?id=${activity.rid}` });

    // For audiobooks, the poster is taken directly from audible
    // TODO: cache audiobook posters via get_image.php
    let img = buildNode(
        "img",
        { src : activity.poster.startsWith("http") ? activity.poster : `poster${activity.poster}`, alt : "Poster" },
        0,
        {
            load : onPosterLoaded
        });

    if (activity.value == "ViewStream")
    {
        img.src = "poster/viewstream.svg";
    }
    else if (!activity.poster)
    {
        img.src = "poster/moviedefault.svg";
    }

    img.style.height = "80px";
    imgA.appendChild(img);
    imgHolder.appendChild(imgA);

    let textHolder = buildNode("div", { class : "textHolder"/*, "style": "max-width: calc(100% - 70px"*/ });
    let span = buildNode("span", { class : "tableEntryTitle" });

    let requestLink = buildNode("a", { href : `request.php?id=${activity.rid}` });
    let titleText = getTitleText(activity);

    requestLink.appendChild(buildNode("span", {}, titleText.link));
    span.appendChild(buildNode("span", {}, titleText.plain));
    span.appendChild(requestLink);

    let activityTime = buildNode("span", {}, DateUtil.getDisplayDate(activity.timestamp));
    Tooltip.setTooltip(activityTime, DateUtil.getFullDate(activity.timestamp));

    holder.appendChildren(imgHolder, textHolder.appendChildren(span, activityTime));
    return holder;
}

/// <summary>
/// Builds the table of activities from the server response
/// </summary>
function buildActivities(response)
{
    if (response.count == 0)
    {
        Log.warn("No results, likely due to bad page index or filter");
        Table.displayInfoMessage("No requests found with the current filter");
        return;
    }

    let activities = response.activities;
    let newActivities = response.new;
    let total = response.total;

    Log.verbose(response);

    for (let i = 0; i < activities.length; ++i)
    {
        Table.addItem(buildActivity(activities[i], i < newActivities));
    }

    Table.setPageInfo(total);
}

/// <summary>
/// Shorthand accessor for attributes that are inserted into the body via PHP
/// </summary>
function attrib(attribute)
{
    return document.body.getAttribute(attribute);
}

/// <summary>
/// Returns whether the current session user is an admin. Easily bypassed
/// by modifying the DOM, but the backend is the source of truth and will block
/// any unauthorized actions.
/// </summary>
function isAdmin()
{
    return parseInt(attrib("admin")) === 1;
}

/// <summary>
/// HTML for the filter overlay/dialog
/// </summary>
Table.Filter.html = function()
{
    let options = [];

    // Statuses + request types
    let checkboxes =
    {
        "Show New Requests" : "showNew",
        "Show Comments" : "showComment",
        "Show Status Changes" : "showStatus",
        "Show My Actions" : "showMine"
    };

    for (let [label, name] of Object.entries(checkboxes))
    {
        options.push(Table.Filter.buildCheckbox(label, name));
    }

    options.push(Table.Filter.buildDropdown(
        "Sort By",
        {
            Date : "request"
        }));

    options.push(Table.Filter.buildDropdown(
        "Sort Order",
        {
            "Newest First" : "sortDesc",
            "Oldest First" : "sortAsc"
        },
        true /*addId*/));

    options.push(buildNode("hr"));

    if (isAdmin())
    {
        options.push(Table.Filter.buildDropdown(
            "Filter To",
            {
                All : -1
            }));

        options.push(buildNode("hr"));
    }

    return Table.Filter.htmlCommon(options);
};

/// <summary>
/// Modifies the filter HTML to reflect the current filter settings
/// </summary>
Table.Filter.populate = function()
{
    let filter = Table.Filter.get();
    $("#showNew").checked = filter.type.new;
    $("#showComment").checked = filter.type.comment;
    $("#showStatus").checked = filter.type.status;
    $("#showMine").checked = filter.type.mine;
    $("#sortBy").value = filter.sort;
    $("#sortOrder").value = filter.order == "desc" ? "sortDesc" : "sortAsc";

    if (isAdmin())
    {
        Table.Filter.populateUserFilter();
    }
};

/// <summary>
/// Returns the new filter definition based on the state of the filter HTML
/// </summary>
Table.Filter.getFromDialog = function()
{
    return {
        type :
        {
            new : $("#showNew").checked,
            comment : $("#showComment").checked,
            status : $("#showStatus").checked,
            mine : $("#showMine").checked
        },
        sort : $("#sortBy").value,
        order : $("#sortOrder").value == "sortDesc" ? "desc" : "asc",
        user : isAdmin() ? $("#filterTo").value : "-1"
    };
};

/// <summary>
/// Unique identifier for this table. Used by tableCommon
/// </summary>
Table.identifier = function()
{
    return "activity";
};

/// <summary>
/// The function to call that will update this table. Used by tableCommon
/// </summary>
Table.updateFunc = function()
{
    return getActivities;
};

/// <summary>
/// Retrieves the stored user filter (persists across page navigation, for better or worse)
/// </summary>
Table.Filter.get = function()
{
    let filter = null;
    try
    {
        filter = JSON.parse(localStorage.getItem(Table.idCore() + "_filter"));
    }
    catch (exception)
    {
        Log.error("Unable to parse stored filter");
    }

    if (filter === null ||
        !hasProp(filter, "type") ||
            !hasProp(filter.type, "new") ||
            !hasProp(filter.type, "comment") ||
            !hasProp(filter.type, "status") ||
            !hasProp(filter.type, "mine") ||
        !hasProp(filter, "sort") ||
        !hasProp(filter, "order") ||
        !hasProp(filter, "user"))
    {
        Log.error("Bad filter, resetting: ");
        Log.error(filter);
        filter = Table.Filter.default();
        Table.Filter.set(filter, false);
    }

    Log.verbose(filter, "Got Filter");
    return filter;
};

/// <summary>
/// Shorthand for the verbose Object's hasOwnProperty call
/// </summary>
function hasProp(item, property)
{
    return Object.prototype.hasOwnProperty.call(item, property);
}

/// <summary>
/// Returns the default filter for the activity table (i.e. nothing filtered)
/// </summary>
Table.Filter.default = function()
{
    let filter =
    {
        type :
        {
            new : true,
            comment : true,
            status : true,
            mine : true
        },
        sort : "request",
        order : "desc",
        user : -1
    };

    return filter;
};
