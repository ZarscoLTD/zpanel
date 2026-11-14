<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/autoload.php';

use ZarsPanel\App;

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
// a private server panel: never in search engines (header on every answer, plus robots.txt)
header('X-Robots-Tag: noindex, nofollow');
if ($path === '/robots.txt') {
    header('Content-Type: text/plain; charset=utf-8');
    echo "User-agent: *\nDisallow: /\n";
    exit;
}
if (!str_starts_with($path, '/api/') && !preg_match('#^/(hook|ddns)/#', $path)) { // the React app: any other address serves it (client side routes); git deploy hooks and DDNS updates go to the router
    $file = __DIR__ . '/app' . ($path === '/' ? '/index.html' : $path);
    $real = realpath($file);
    if ($real && str_starts_with($real, realpath(__DIR__ . '/app') . '/') && is_file($real)) {
        $types = ['js' => 'text/javascript', 'css' => 'text/css', 'html' => 'text/html; charset=utf-8', 'svg' => 'image/svg+xml', 'png' => 'image/png', 'woff2' => 'font/woff2', 'json' => 'application/json'];
        header('Content-Type: ' . ($types[pathinfo($real, PATHINFO_EXTENSION)] ?? 'application/octet-stream'));
        header('X-Content-Type-Options: nosniff');
        readfile($real);
        exit;
    }
    header('Content-Type: text/html; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    @readfile(__DIR__ . '/app/index.html') ?: print('<h1>ZARS Panel</h1><p>The web app is not built yet.</p>');
    exit;
}

$raw = file_get_contents('php://input') ?: '';
$body = $_POST;
if ($raw !== '' && str_contains((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'json')) {
    $body = json_decode($raw, true) ?: [];
}
$headers = [];
foreach ($_SERVER as $k => $v) {
    if (str_starts_with($k, 'HTTP_')) {
        $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = $v;
    }
}
// $_FILES -> one flat list of files (a "files[]" field arrives as parallel arrays: name[], tmp_name[], ...)
$files = [];
foreach ($_FILES as $field) {
    if (is_array($field['name'] ?? null)) {
        foreach (array_keys($field['name']) as $i) {
            $files[] = ['name' => $field['name'][$i], 'type' => $field['type'][$i] ?? '', 'tmp_name' => $field['tmp_name'][$i], 'error' => $field['error'][$i], 'size' => $field['size'][$i] ?? 0];
        }
    } elseif (isset($field['tmp_name'])) {
        $files[] = $field;
    }
}
if (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off') {
    $headers['https'] = 'on'; // the panel's own TLS (Sso builds its return address from it)
}
$res = (new App())->handle(['method' => $_SERVER['REQUEST_METHOD'], 'path' => $path, 'query' => $_GET, 'body' => $body, 'headers' => $headers, 'cookies' => $_COOKIE, 'files' => $files, 'ip' => $_SERVER['REMOTE_ADDR'] ?? '']);
http_response_code($res['status']);
foreach ($res['headers'] as $k => $v) {
    foreach ((array) $v as $one) { // Set-Cookie may come as a list (log in as a client)
        header("$k: $one", false);
    }
}
echo $res['body'];
