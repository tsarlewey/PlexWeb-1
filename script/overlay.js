/* exported overlay, overlayDismiss, buildOverlay */

/// <summary>
/// Creates a full-screen overlay with the given message, button text, and button function.
/// </summary>
function overlay(message, buttonText, buttonFunc, dismissable=true)
{
    buildOverlay(dismissable,
        buildNode("div", {}, message),
        buildNode(
            "input",
            {
                type : "button",
                id : "overlayBtn",
                value : buttonText,
                style : "width: 100px"
            },
            0,
            {
                click : buttonFunc
            }
        )
    );
}

const overlayDismiss = () => Animation.queue({ opacity : 0 }, $("#mainOverlay"), 250, true /*deleteAfterTransition*/);

/// <summary>
/// Generic overlay builder
/// </summary>
/// <param name="dismissable">Determines whether the overlay can be dismissed</param>
/// <param name="...children">The list of nodes to append to the overaly.</param>
function buildOverlay(dismissable, ...children)
{
    if ($("#mainOverlay") && $("#mainOverlay").style.opacity != "0")
    {
        return;
    }

    let overlayNode = buildNode("div",
        {
            id : "mainOverlay",
            style : "opacity: 0",
            dismissable : dismissable
        },
        0,
        {
            click : function(e)
            {
                let overlayElement = $("#mainOverlay");
                if (overlayElement &&
                    !!overlayElement.getAttribute("dismissable") &&
                    e.target.id == "mainOverlay" &&
                    e.target.style.opacity == 1)
                {
                    overlayDismiss();
                }
            }
        });

    let container = buildNode("div", { id : "overlayContainer", class : "defaultOverlay" });
    children.forEach(function(element)
    {
        container.appendChild(element);
    });

    overlayNode.appendChild(container);
    document.body.appendChild(overlayNode);
    Animation.queue({ opacity : 1 }, overlayNode, 250);
    if (container.clientHeight / window.innerHeight > 0.7)
    {
        container.classList.remove("defaultOverlay");
        container.classList.add("fullOverlay");
    }

    window.addEventListener("keydown", _overlayKeyListener, false);
}

function _overlayKeyListener(e)
{
    if (e.keyCode == 27 /*esc*/)
    {
        let overlayNode = $("#mainOverlay");
        if (overlayNode && !!overlayNode.getAttribute("dismissable") && overlayNode.style.opacity == "1")
        {
            window.removeEventListener("keydown", _overlayKeyListener, false);
            overlayDismiss();
        }
    }
}
