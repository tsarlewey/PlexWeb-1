/// <summary>
/// Console logging class. Allows easy timestamped logging with various log levels
///
/// Because this class is used on almost every page, some additional manual minification
/// has been done to reduce its overall size. Nothing like unnecessary micro-optimizations!
/// </summary>

/// <summary>
/// All possible log levels, from most to least verbose
/// </summary>
const LOG = {
    Extreme: -1, // Log everytime something is logged
    Tmi: 0,
    Verbose: 1,
    Info: 2,
    Warn: 3,
    Error: 4,
    Critical: 5
};

const g_logStr = ["TMI", "VERBOSE", "INFO", "WARN", "ERROR", "CRITICAL"];
const _inherit = "inherit";

/// <summary>
/// Console color definitions for each log level
/// </summary>
const g_levelColors =
[
    ["#00CC00", "#00AA00", "#AAA", "#888"],
    ["#c661e8", "#c661e8", _inherit, _inherit],
    ["blue", "#88C", _inherit, _inherit],
    ["E50", "#C40", _inherit, _inherit],
    [_inherit, _inherit, _inherit, _inherit],
    ["inherit; font-size: 2em", "inherit; font-size: 2em", "#800; font-size: 2em", "#C33; font-size: 2em"],
    ["#009900", "#006600", "#AAA", "#888"]
];

/// <summary>
/// Trace color definitions for each log level
/// </summary>
const g_traceColors =
[
    g_levelColors[0],
    g_levelColors[1],
    g_levelColors[2],
    ["#E50; background-color: #FFFBE5", "#C40; background-color: #332B00", "inherit; background-color: #FFFBE5", "#DFC185; background-color: #332B00"],
    ["red; background-color: #FEF0EF", "#D76868; background-color: #290000", "red; background-color: #FEF0EF", "#D76868; background-color: #290000"],
    ["red; font-size: 2em", "red; font-size: 2em", "#800; font-size: 2em", "#C33; font-size: 2em"],
    g_levelColors[6]
];

/// <summary>
/// The current log level. Anything below this will not be logged
/// </summary>
let g_logLevel = parseInt(localStorage.getItem("loglevel"));
if (isNaN(g_logLevel))
{
    g_logLevel = LOG.Info;
}

/// <summary>
/// Determine whether we should add a trace to every log event, not just errors
/// </summary>
let g_traceLogging = parseInt(localStorage.getItem("logtrace"));
if (isNaN(g_traceLogging))
{
    g_traceLogging = 0;
}

/// <summary>
/// Tweak colors a bit based on whether the user is using a dark console theme
/// </summary>
let g_darkConsole = parseInt(localStorage.getItem("darkconsole"));
if (isNaN(g_darkConsole))
{
    g_darkConsole = 0;
}

logInfo("Welcome to the console! For debugging help, call consoleHelp()");

// Don't include this in minified versions
function testAll()
{
    const old = g_logLevel;
    setLogLevel(-1);
    logTmi("TMI!");
    setLogLevel(0);
    logVerbose("Verbose!");
    logInfo("Info!");
    logWarn("Warn!");
    logError("Error!");
    log("Crit!", undefined, false /*freeze*/, LOG.Critical);
    setLogLevel(old);
}

function setLogLevel(level)
{
    localStorage.setItem("loglevel", level);
    g_logLevel = level;
}

function setDarkConsole(dark)
{
    localStorage.setItem("darkconsole", dark);
    g_darkConsole = dark;
}

/// <summary>
/// Sets whether to print stack traces for each log. Helpful when debugging
/// </summary>
function setTrace(trace)
{
    localStorage.setItem("logtrace", trace);
    g_traceLogging = trace;
}

function logTmi(obj, description, freeze)
{
    log(obj, description, freeze, LOG.Tmi);
}

function logVerbose(obj, description, freeze)
{
    log(obj, description, freeze, LOG.Verbose);
}

function logInfo(obj, description, freeze)
{
    log(obj, description, freeze, LOG.Info);
}

function logWarn(obj, description, freeze)
{
    log(obj, description, freeze, LOG.Warn);
}

function logError(obj, description, freeze)
{
    log(obj, description, freeze, LOG.Error);
}

function log(obj, description, freeze, level)
{
    if (level < g_logLevel)
    {
        return;
    }

    const print = function(output, text, obj, level, colors)
    {
        let textColor = `color: ${colors[level][2 + g_darkConsole]}`;
        let titleColor = `color: ${colors[level][g_darkConsole]}`;
        output(text, textColor, titleColor, textColor, titleColor, textColor, obj);
    }

    let d = getTimestring();
    let colors = g_traceLogging ? g_traceColors : g_levelColors;
    let typ = (obj) => typeof(obj) == "string" ? "%s" : "%o";

    let curState = (obj, str=0) => typeof(obj) == "string" ? obj : str ? JSON.stringify(obj) : freeze ? JSON.parse(JSON.stringify(obj)) : obj;
    if (g_logLevel === LOG.Extreme) {
        print(
            console.log,
            `%c[%cEXTREME%c][%c${d}%c] Called log with '${description ? description + ': ' : ''}${typ(obj)}, ${level}'`, curState(obj), 6, colors);
    }

    let output = g_traceLogging ? console.trace : level < LOG.Info ? console.log : level < LOG.Warn ? console.info : level < LOG.Error ? console.warn : console.error;
    print(output, `%c[%c${g_logStr[level]}%c][%c${d}%c] ${description ? description + ': ' : ''}${typ(obj)}`, curState(obj), level, colors);

    function getTimestring() {
        let z = function(n,x=2) {
            return ('00' + n).substr(-x);
        }

        let d = new Date();
        return `${d.getFullYear()}.${z(d.getMonth()+1)}.${z(d.getDate())} ${z(d.getHours())}:${z(d.getMinutes())}:${z(d.getSeconds())}.${z(d.getMilliseconds(),3)}`;
    };

    if (level > LOG.Warn)
    {
        // Don't worry about the result, just try to log the error
        freeze = true;
        let http = new XMLHttpRequest();
        http.open("POST", "process_request.php", true);
        http.setRequestHeader("Content-type", "application/x-www-form-urlencoded");
        let encode = encodeURIComponent;

        // Note: Keep type in sync with ProcessRequest enum in common.js. It's not accessible here
        http.send(`&type=29&error=${encode(curState(obj, 1))}&stack=${encode(Error().stack)}`);
    }
}

function consoleHelp()
{
    // After initializing everything we need, print a message to the user to give some basic tips
    const logLevelSav = g_logLevel;
    g_logLevel = 2;
    logInfo(' ');
    console.log("Welcome to the console!\n" +
    "If you're debugging an issue, here are some tips:\n" +
    "  1. Set dark/light mode for the console via setDarkConsole(isDark), where isDark is 1 or 0.\n" +
    "  2. Set the log level via setLogLevel(level), where level is a value from the LOG dictionary (e.g. setLogLevel(LOG.Verbose);)\n" +
    "  3. To view unminified js sources, add nomin=1 to the url parameters.\n" +
    "  4. To view the stack trace for every logged event, call setTrace(1). To revert, setTrace(0)\n\n");
    g_logLevel = logLevelSav;
}
