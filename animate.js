/// </summary>
/// A lightweight class to animate varioius element properties. Performance is questionable at best,
/// but the minified version is ~10x times smaller than jQuery.min.js
/// </summary>


/// <summary>
/// The main animation class, responsible for synchronous execution
/// </summary>
/// <todo>When required, support multiple properties at once, i.e. { "backgroundColor" : "#AAA", "color": "#BBB" }</todo>
let A = function()
{
    /// <summary>
    /// Queue an animation of the given element
    /// </summary>
    this.queue = function(func, element, ...args)
    {
        this.queueDelayed(func, element, 0, ...args);
    };

    /// <summary>
    /// Queue an animation that once queued for exexcution will fire after the specified delay
    /// </summary>
    this.queueDelayed = function(func, element, delay, ...args)
    {
        if (arguments.length < 2)
        {
            return;
        }

        if (!element.id)
        {
            logWarn("Animated element does not have an id!");
        }

        if (!animationQueue[element.id])
        {
            animationQueue[element.id] = [];
        }

        let animations = [];
        let seen = {};
        for (let [key, value] of Object.entries(func))
        {
            if (key in seen)
            {
                // Don't allow duplicate entries
                return;
            }

            seen[key] = 1;

            animations.push(new AnimationParams(/*Funcs[key]*/getFunc(key), key, delay, value, ...args));
        }

        animationQueue[element.id].push(animations);
        if (animationQueue[element.id].length !== 1)
        {
            // Can't fire immediately
            logTmi("Adding animation for " + element.id + " to queue");
            return;
        }

        logTmi("Firing animation for " + element.id + " immediately");
        animationQueue[element.id][0].timers = [];
        for (let i = 0; i < animations.length; ++i)
        {
            animationQueue[element.id][0].timers.push(setTimeout(function(func, element, prop, ...args) {
                func(element, prop, ...args);
            }, delay, animations[i].func, element, animations[i].prop, ...animations[i].args));
        }
    };

    /// <summary>
    /// Immediately stop any active animations and queues this one to be fired
    /// </summary>
    this.fireNow = function(func, element, ...args)
    {
        let queue = animationQueue[element.id];
        if (queue)
        {
            for (let i = 0; i < queue[0].timers.length; ++i)
            {
                clearTimeout(queue[0].timers[i]);
            }

            // Otherwise splice out everything except what's currently firing
            queue = queue.splice(0, 1);
            queue[0].canceled = true;
        }

        this.queue(func, element, ...args);
    }

    // Our animation queue allows us to keep track of the current animations that are pending execution
    let animationQueue = {};

    /// <summary>
    /// Generic holder for the various arguments for a given animation
    /// </summary>
    let AnimationParams = function(func, prop, delay, ...args)
    {
        this.func = func;
        this.prop = prop;
        this.delay = delay;
        this.args = args;
    };

    /// <summary>
    /// Should only be called after an animation completes. Removes the current
    /// animation from the queue and fires the next one if applicable
    /// </summary>
    let fireNext = function(element)
    {
    	let queue = animationQueue[element.id];
        queue[0].shift();
        if (queue[0].length == 0)
        {
            // Clear it from our dictionary to save some space
            queue.shift();
        }
        else
        {
            // Still waiting for the last animation from the given group
            return;
        }

        if (queue.length == 0)
        {
            delete animationQueue[element.id];
        }
        else
        {
            let nextAnimations = queue[0];
            nextAnimations.timers = [];
            for (let i = 0; i < nextAnimations.length; ++i)
            {
                nextAnimations.timers[i] = setTimeout(function(element, nextAnimation) {
                    nextAnimation.func(element, nextAnimation.prop, ...nextAnimation.args);
                }, nextAnimations[i].delay, element, nextAnimations[i]);
            }
        }
    };

    /// <summary>
    /// The list of supported animations and their subsequent implementations
    /// </summary>
    let getFunc = function(func)
    {
        switch (func)
        {
            case "backgroundColor":
            case "color":
                return function(element, prop, newColor, duration, deleteAfterTransition = false)
                {
                    // '(x + .5) | 0' == Math.round.
                    // 'y || 1' because we need at least one step
                    const steps = (duration / (50 / 3) + 0.5) | 0 || 1; // 1000 / 60 -> 60Hz
                    let oldColor = new Color(getStyle(element)[prop]);

                    // If newColor is a string, try to parse a hex value. Otherwise it needs to be 'transparent'
                    if (typeof(newColor) == "string")
                    {
                        if ((newColor = newColor.toLowerCase()) == "transparent")
                        {
                            newColor = new Color(oldColor.toString());
                            newColor.a = 0;
                        }
                        else if (newColor[0] == '#')
                        {
                            newColor = new Color(newColor);
                        }
                        else
                        {
                            // Create an element with our color and use whatever the document
                            // tells us the color should be. If it's invalid, the default
                            // body color will be returned
                            let tempElement = document.createElement("q");
                            tempElement.style.color = newColor;
                            document.body.append(tempElement); // Some browsers need the element to be attached
                            newColor = new Color(getStyle(tempElement)[prop]);
                            document.body.removeChild(tempElement);
                        }
                    }

                    logTmi("Animating " + prop + " of " + element.id + " from " + oldColor.toString() + " to " + newColor.toString() + " in " + duration + "ms");

                    let animationFunc = function(func, element, oldColor, newColor, i, steps, prop, deleteAfterTransition)
                    {
                        if (animationQueue[element.id][0].canceled)
                        {
                            i = steps;
                        }

                        element.style[prop] = new Color(
                            oldColor.r + (((newColor.r - oldColor.r) / steps) * i),
                            oldColor.g + (((newColor.g - oldColor.g) / steps) * i),
                            oldColor.b + (((newColor.b - oldColor.b) / steps) * i),
                            oldColor.a + (((newColor.a - oldColor.a) / steps) * i)).toString();

                        if (i == steps)
                        {
                            if (deleteAfterTransition)
                            {
                                element.style[prop] = null;
                            }

                            // Always need to call this once a particular animation is done!
                            fireNext(element);
                        }
                        else
                        {
                            setTimeout(func, 50 / 3, func, element, oldColor, newColor, i + 1, steps, prop, deleteAfterTransition);
                        }
                    };


                    setTimeout(animationFunc, 50 / 3, animationFunc, element, oldColor, newColor, 1, steps, prop, deleteAfterTransition);
                };
            case "opacity":
            case "left":
                return function(element, prop, newValue, duration, deleteAfterTransition = false)
                {
                    var steps = (duration / (50 / 3) + 0.5) | 0 || 1;
                    let lastChar = newValue[newValue.length - 1];
                    const percent = lastChar == '%';
                    let px = lastChar == 'x';
                    const newVal = parseFloat(newValue);

                    let oldVal = parseFloat(getStyle(element)[prop]);
                    if (percent)
                    {
                        oldVal = oldVal / parseInt(getStyle(document.body).width);
                    }

                    logTmi("Animating " + prop + " of " + element.id + " from " + oldVal + " to " + newVal + " in " + duration + "ms");
                    let animationFunc = function(func, element, prop, oldVal, newVal, percent, px, i, steps, deleteAfterTransition)
                    {
                        if (animationQueue[element.id][0].canceled)
                        {
                            i = steps;
                        }

                        element.style[prop] = oldVal + (((newVal - oldVal) / steps) * i) + (percent ? "%" : px ? "px" : "");
                        if (i == steps)
                        {
                            if (deleteAfterTransition)
                            {
                                element.parentNode.removeChild(element);
                            }

                            // Always need to call this once a particular animation is done!
                            fireNext(element);
                        }
                        else
                        {
                            setTimeout(func, 50 / 3, func, element, prop, oldVal, newVal, percent, px, i + 1, steps, deleteAfterTransition);
                        }
                    };

                    setTimeout(animationFunc, 50 / 3, animationFunc, element, prop, oldVal, newVal, percent, px, 1, steps, deleteAfterTransition);
                };
            case "display":
                return function(element, prop, newValue)
                {
                    // Not really an animation, but being able to queue this is nice
                    element.style.display = newValue;
                    fireNext(element);
                };
            default:
                logError("Bad:" + func);
                return;
        }
    };

    /// <summary>
    /// Helps with the basic minification I run this script through
    /// </summary>
    let getStyle = function(element)
    {
        return getComputedStyle(element);
    };
};

let Animation = new A();

/// <summary>
/// Simple class to represent an rgba color. Takes either rgba value or a valid hex string (#AAA, #C1D1E1)
/// </summary>
function Color(r, g, b, a)
{
    // if g is undefined, r better be a string
    if (g === undefined && r[0] == "#")
    {
        // Better be a hex string!
        let result;
        if (r.length == 4)
        {
            // Cheap (character-count-wise) conversion from "#ABC" to "#AABBCC"
            r = r[0] + r[1] + r[1] + r[2] + r[2] + r[3] + r[3];
        }

        // Assume rgb string
        result = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(r);

        this.r = parseInt(result[1], 16);
        this.g = parseInt(result[2], 16);
        this.b = parseInt(result[3], 16);
        this.a = 1;
    }
    else
    {
        if (g === undefined)
        {
            // Hacky to keep the trailing parenthsis, but parseInt/Float figures it out
            [r, g, b, a] = r.substr(r.indexOf("(") + 1).split(",");
        }

        this.r = r ? parseInt(r) : 0;
        this.g = g ? parseInt(g) : 0;
        this.b = b ? parseInt(b) : 0;
        this.a = a ? parseFloat(a) : 1; // Opaque by default
    }

    /// <summary>
    /// Return an rgba string representation of this color
    /// </summary>
    this.toString = function() {
        return "rgba(" + this.r + ", " + this.g + ", " + this.b + ", " + this.a + ")";
    };
}