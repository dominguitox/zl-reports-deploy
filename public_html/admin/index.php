<?php
declare(strict_types=1);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
try {
    // Keep both private includes inside the handler so missing/misplaced files
    // produce a controlled response instead of an empty web-server HTTP 500.
    $private = dirname(__DIR__, 2) . '/private';
    require_once $private . '/app.php';
    require_once $private . '/viewer.php';
    $local = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
    if (!$local && ($_SERVER['HTTPS'] ?? '') !== 'on') { http_response_code(403); exit('Se requiere HTTPS.'); }
    $config = reportConfig();
    if (empty($config['viewer_user']) || empty($config['viewer_password_hash'])) {
        http_response_code(503); exit('Configura el acceso del visor en la configuración privada.');
    }
    if (!hash_equals($config['viewer_user'], $_SERVER['PHP_AUTH_USER'] ?? '')
        || !password_verify($_SERVER['PHP_AUTH_PW'] ?? '', $config['viewer_password_hash'])) {
        header('WWW-Authenticate: Basic realm="Zombie Lane Admin", charset="UTF-8"');
        http_response_code(401); exit('Acceso administrativo requerido.');
    }
    $db = database($config);
    $testers = $db->query('SELECT t.id,t.enabled,t.created_at,COUNT(r.id) AS reports,COALESCE(SUM(r.bytes),0) AS bytes,MAX(r.received_at) AS last_received FROM testers t LEFT JOIN reports r ON r.tester_id=t.id GROUP BY t.id,t.enabled,t.created_at ORDER BY t.id')->fetchAll();
    $totals = $db->query('SELECT COUNT(*) AS reports,COALESCE(SUM(bytes),0) AS bytes,COALESCE(SUM(received_at >= UTC_TIMESTAMP() - INTERVAL 1 DAY),0) AS today FROM reports')->fetch();
    $totals['testers'] = count($testers);
    $tester = max(0, min(255, (int)($_GET['tester'] ?? 0)));
    $page = max(1, min(100000, (int)($_GET['page'] ?? 1)));
    $query = $db->prepare('SELECT id,tester_id,bytes,received_at,expires_at FROM reports' . ($tester ? ' WHERE tester_id = ?' : '') . ' ORDER BY received_at DESC,id DESC LIMIT 25 OFFSET ' . (($page - 1) * 25));
    $query->execute($tester ? [$tester] : []);
    $reports = $query->fetchAll();
    $selected = null; $texts = []; $error = '';
    if (isset($_GET['report'])) {
        $query = $db->prepare('SELECT id,tester_id,filename,sha256 FROM reports WHERE id = ?');
        $query->execute([(int)$_GET['report']]);
        $selected = $query->fetch() ?: null;
        if (!$selected) { http_response_code(404); $error = 'Informe no encontrado.'; }
        else {
            try { $texts = viewerArchive($config, $selected); }
            catch (Throwable $e) { $error = 'El archivo no está disponible o no supera la comprobación de integridad.'; }
        }
    }
    viewerRender($testers, $reports, $totals, $page, $tester, $selected, $texts, $error);
} catch (Throwable $e) {
    error_log('Report viewer unavailable (' . get_class($e) . ')');
    http_response_code(503);
    echo 'No se pudo cargar el visor. Comprueba la configuración y la conexión a MySQL en el hosting.';
}
