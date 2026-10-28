<?php
declare(strict_types=1);

header('Cache-Control: no-store');
header('Content-Type: text/plain; charset=utf-8');
header('X-Content-Type-Options: nosniff');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Method not allowed'); }
// On a TLS-terminating proxy configure HTTPS in PHP via trusted server settings,
// never via an arbitrary client-supplied forwarded header.
if (($_SERVER['HTTPS'] ?? '') !== 'on') { http_response_code(403); exit('HTTPS required'); }
if (($_SERVER['CONTENT_LENGTH'] ?? '0') !== '' && (int)$_SERVER['CONTENT_LENGTH'] > 1048576) {
    http_response_code(413); exit('Too large');
}
try {
    require dirname(__DIR__, 2) . '/private/app.php';
    $config = reportConfig();
    $headers = getallheaders();
    // Header names are case-insensitive; normalize them for the receiver.
    $headers = array_change_key_case($headers, CASE_LOWER);
    $normalized = [];
    foreach (['Content-Type','X-ZL-Consent','X-ZL-Report-Id','X-ZL-Report-Token','X-ZL-Game-Version'] as $name) {
        $normalized[$name] = $headers[strtolower($name)] ?? null;
    }
    $input = fopen('php://input', 'rb');
    if ($input === false) throw new RuntimeException('Input unavailable');
    try { $status = receive(database($config), $config, $normalized, $input); }
    finally { fclose($input); }
    http_response_code($status);
    echo $status === 200 ? 'OK' : 'Rejected';
} catch (Throwable $e) {
    // Do not include headers, tokens, paths or ZIP content in logs or responses.
    error_log('Report receiver internal failure');
    http_response_code(503);
    echo 'Unavailable';
}
