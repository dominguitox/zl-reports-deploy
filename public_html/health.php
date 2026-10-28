<?php
declare(strict_types=1);

header('Cache-Control: no-store');
header('Content-Type: text/plain; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    exit('Method not allowed');
}
if (($_SERVER['HTTPS'] ?? '') !== 'on') {
    http_response_code(403);
    exit('HTTPS required');
}

try {
    if (!class_exists(ZipArchive::class)) {
        throw new RuntimeException('ZIP support unavailable');
    }
    require dirname(__DIR__) . '/private/app.php';
    $config = reportConfig();
    $db = database($config);
    $db->query('SELECT 1')->fetchColumn();
    storage($config);
    http_response_code(200);
    echo 'ok';
} catch (Throwable $exception) {
    // Keep the public response generic; no database, path, or storage detail is disclosed.
    error_log('Report receiver health check unavailable');
    http_response_code(503);
    echo 'unavailable';
}
