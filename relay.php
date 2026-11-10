<?php
// API relay for the VPN client, as one PHP file run by PHP's built-in server
// (see the Dockerfile) on Railway. PHP 7.3 or later, with the curl extension.
//
// When Mozilla's sign-in, Guardian, or server list cannot be reached from
// your network, the client sends those requests (never the VPN traffic)
// through this relay. Set its URL in the app's Settings > Connection >
// "If Mozilla is blocked", for example
// https://NAME.up.railway.app/?secret=SECRET
// Opened in a browser, the URL shows whether the relay works. Add &health=1
// for a JSON health check that contacts no upstream.
//
// The client asks for /?url=<target>&secret=SECRET; the relay sends the same
// method, headers, and body to the target and answers with its response.
// Redirects are not followed: they go back to the client. Only the hosts
// below are relayed, so the relay is not an open proxy for others.

// Warnings must never end up inside a relayed response.
ini_set('display_errors', '0');

// A secret only your client knows, taken from the SECRET environment
// variable. Required: without one the relay relays nothing (the sign-in and
// the account tokens cross it). It is never logged or echoed.
$SECRET = getenv('SECRET') ?: '';

$ALLOWED = [
    'mozilla.org', 'mozilla.com', 'firefox.com', // accounts, Guardian, server list
    'github.com', 'githubusercontent.com', 'jsdelivr.net', // geo files, flags
];

// Headers that describe this hop, not the request or the response.
// Accept-Encoding is deliberately not here: the app sends "identity" on
// purpose (other values drew HTTP 406 from Mozilla's edge in its tests), and
// the Cloudflare worker forwards it too. curl is not asked to decode, so the
// body and its Content-Encoding pass through unchanged.
$HOP = ['host', 'connection', 'keep-alive', 'content-length', 'transfer-encoding',
    'x-forwarded-for', 'x-forwarded-proto', 'x-forwarded-host', 'x-real-ip', 'upgrade', 'te', 'trailer'];

function fail($status, $why)
{
    $why = trim(preg_replace('/[\r\n]+/', ' ', (string)$why));
    http_response_code($status);
    header('X-Relay-Error: ' . $why);
    header('Content-Type: text/plain');
    echo $why;
    exit;
}

function ends_with($text, $suffix)
{
    return substr($text, -strlen($suffix)) === $suffix;
}

// The page for whoever opens the relay's URL in a browser (no url
// parameter): whether it works, and what to do.
function page($state)
{
    $states = [
        'works' => [200, '#10b981', '✓ Working', 'Your relay is ready', "Put this page's address in Arman VPN:",
            ['Settings', 'Connection', 'If Mozilla is blocked', 'Cloudflare Worker']],
        'wrong' => [403, '#ef4444', '✗ Wrong secret', 'This address has no secret, or the wrong one',
            'It must contain <code>?secret=</code> followed by the value of the <code>SECRET</code> variable set on the server.', []],
        'unset' => [503, '#f59e0b', '⚠ Not set up', 'Set a secret first',
            'Set the <code>SECRET</code> variable to a long random text, redeploy, and open this address with <code>?secret=…</code> at the end.', []],
    ];
    [$status, $color, $badge, $title, $text, $steps] = $states[$state];
    $path = $steps ? '<div class="path"><span>' . implode('</span><i>›</i><span>', $steps) . '</span></div>' : '';
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    echo <<<HTML
<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Arman VPN relay</title>
<style>
:root{--bg:#eef0fb;--card:#fff;--text:#1d2040;--muted:#5d6385;--accent:#5b5bd6;--c:$color}
@media (prefers-color-scheme:dark){:root{--bg:#0b0e1f;--card:#151a35;--text:#eef0ff;--muted:#9aa3c7;--accent:#8b8ff8}}
*{box-sizing:border-box}
body{margin:0;min-height:100vh;display:grid;place-items:center;padding:24px;color:var(--text);
font:16px/1.65 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;
background:radial-gradient(900px 500px at 15% -10%,color-mix(in srgb,var(--accent) 28%,transparent),transparent),
radial-gradient(700px 500px at 110% 110%,color-mix(in srgb,var(--c) 22%,transparent),transparent),var(--bg)}
.card{width:min(560px,100%);background:var(--card);border-radius:22px;padding:34px 32px;
box-shadow:0 24px 70px rgba(20,27,61,.18);border-top:5px solid var(--c)}
.brand{display:flex;align-items:center;gap:10px;font-weight:700;color:var(--muted);font-size:14px;letter-spacing:.02em}
.dot{width:10px;height:10px;border-radius:50%;background:var(--accent)}
.badge{display:inline-block;margin-top:22px;padding:6px 14px;border-radius:999px;font-weight:700;font-size:14px;
color:var(--c);background:color-mix(in srgb,var(--c) 15%,transparent)}
h1{font-size:23px;line-height:1.3;margin:14px 0 8px}
p{margin:0;color:var(--muted)}
code{background:color-mix(in srgb,var(--accent) 12%,transparent);color:var(--accent);padding:1px 6px;border-radius:6px}
.path{display:flex;flex-wrap:wrap;align-items:center;gap:6px;margin-top:16px}
.path span{padding:5px 12px;border-radius:10px;font-weight:600;font-size:14px;
background:color-mix(in srgb,var(--accent) 13%,transparent);color:var(--accent)}
.path i{color:var(--muted);font-style:normal}
</style>
<div class="card"><div class="brand"><span class="dot"></span>Arman VPN relay</div>
<span class="badge">$badge</span><h1>$title</h1><p>$text</p>$path</div></html>
HTML;
    exit;
}

$isHealth = ($_GET['health'] ?? '') === '1';
$browser = !$isHealth && !isset($_GET['url']);

if ($SECRET === '') {
    $browser ? page('unset') : fail(503, 'no secret is set in the relay');
}
$given = $_GET['secret'] ?? '';
if (!is_string($given) || !hash_equals($SECRET, $given)) {
    $browser ? page('wrong') : fail(403, 'wrong secret');
}

// Health check: answers without contacting any upstream.
if ($isHealth) {
    http_response_code(200);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode(['relay' => 'arman-vpn', 'version' => 1, 'ready' => true]);
    exit;
}
if ($browser) {
    page('works');
}

// The target: https only, no credentials, no fragment, port 443 only, and a
// host from the allowlist.
$target = $_GET['url'] ?? '';
if (!is_string($target) || $target === '') {
    fail(400, 'url must be an https URL');
}
if (strpos($target, '#') !== false) {
    fail(400, 'url must not have a fragment');
}
if (preg_match('/[\x00-\x20\x7f\\\\]/', $target)) {
    fail(400, 'url must not contain spaces, control characters or backslashes');
}
$parts = parse_url($target);
if (!$parts || strtolower($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
    fail(400, 'url must be an https URL');
}
if (isset($parts['user']) || isset($parts['pass'])) {
    fail(400, 'url must not contain credentials');
}
if (isset($parts['port']) && (int)$parts['port'] !== 443) {
    fail(400, 'only port 443 is allowed');
}
$host = strtolower($parts['host']);
if (!preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/', $host)) {
    fail(400, 'invalid host');
}
$allowed = false;
foreach ($ALLOWED as $domain) {
    if ($host === $domain || ends_with($host, '.' . $domain)) {
        $allowed = true;
        break;
    }
}
if (!$allowed) {
    fail(403, 'host not allowed: ' . $host);
}

$headers = [];
foreach (getallheaders() as $name => $value) {
    $lower = strtolower($name);
    if (in_array($lower, $HOP, true) || strpos($lower, 'cf-') === 0) {
        continue;
    }
    $headers[] = $name . ': ' . $value;
}

$method = $_SERVER['REQUEST_METHOD'];
$ch = curl_init($target);
curl_setopt_array($ch, [
    CURLOPT_CUSTOMREQUEST => $method,
    CURLOPT_HTTPHEADER => $headers,
    // Redirects go back to the client, which follows them through the relay.
    CURLOPT_FOLLOWLOCATION => false,
    // Never use a proxy, even if the environment sets one.
    CURLOPT_PROXY => '',
    // The HTTP version is left at curl's default (HTTP/2 over TLS when
    // available, otherwise HTTP/1.1).
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 60,
    // The response's status and headers, as they arrive (a 100 Continue
    // comes first sometimes).
    CURLOPT_HEADERFUNCTION => function ($ch, $line) use ($HOP) {
        $trimmed = trim($line);
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $trimmed, $m)) {
            http_response_code((int)$m[1]);
            header('X-Arman-Relay: 1');
        } elseif ($trimmed !== '' && strpos($trimmed, ':') !== false) {
            $name = strtolower(trim(strstr($trimmed, ':', true)));
            if (!in_array($name, $HOP, true)) {
                header($trimmed, false);
            }
        }
        return strlen($line);
    },
    // The body is passed on as it arrives, so large files need no memory.
    CURLOPT_WRITEFUNCTION => function ($ch, $data) {
        echo $data;
        flush();
        return strlen($data);
    },
]);
if ($method !== 'GET' && $method !== 'HEAD') {
    curl_setopt($ch, CURLOPT_POSTFIELDS, file_get_contents('php://input'));
}
if ($method === 'HEAD') {
    curl_setopt($ch, CURLOPT_NOBODY, true);
}
if (curl_exec($ch) === false) {
    $error = curl_error($ch) ?: 'unknown curl error';
    curl_close($ch);
    if (!headers_sent()) {
        // Drop any upstream headers already collected, then answer 502.
        header_remove();
        fail(502, 'the target did not answer: ' . $error);
    }
    exit;
}
curl_close($ch);
