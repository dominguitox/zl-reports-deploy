<?php
declare(strict_types=1);

function viewerEscape(mixed $value): string {
    return htmlspecialchars(is_scalar($value) ? (string)$value : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Read bounded text entries without extracting or accepting a path from the browser. */
function viewerArchive(array $config, array $row): array {
    $name = $row['filename'];
    if (!is_string($name) || !preg_match('/^[a-f0-9]{64}$/D', $name)) throw new RuntimeException('Invalid file');
    $root = storage($config);
    $path = $root . DIRECTORY_SEPARATOR . $name;
    if (is_link($path) || !is_file($path) || filesize($path) > MAX_BYTES
        || !hash_equals($row['sha256'], hash_file('sha256', $path, true))) throw new RuntimeException('Unavailable file');
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) throw new RuntimeException('Invalid ZIP');
    $texts = [];
    try {
        foreach (['sesion.json', 'informe.txt', 'equipo.txt', 'configuracion.txt', 'lanzador.txt', 'fallo.txt', 'acciones.jsonl', 'zombie-lane.log', 'zombie-lane.1.log'] as $entry) {
            $stat = $zip->statName($entry);
            if ($stat === false) continue;
            $stream = $zip->getStream($entry);
            if ($stream === false) continue;
            try { $text = stream_get_contents($stream, 131073); }
            finally { fclose($stream); }
            if ($text === false) continue;
            $truncated = strlen($text) > 131072 || $stat['size'] > strlen($text);
            if (strlen($text) > 131072) $text = substr($text, 0, 131072);
            $texts[$entry] = $text . ($truncated ? "\n[Vista truncada a 128 KiB]" : '');
        }
    } finally { $zip->close(); }
    return $texts;
}

function viewerRender(array $testers, array $reports, array $totals, int $page, int $tester, ?array $selected, array $texts, string $error = ''): void {
    $e = 'viewerEscape';
    $session = json_decode($texts['sesion.json'] ?? '{}', true);
    if (!is_array($session)) $session = [];
    ?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Zombie Lane · Observatorio de testers</title><link rel="stylesheet" href="viewer.css"></head><body>
<header><span class="brand">ZL / CONTROL ALPHA</span><span>Lectura administrativa · fechas UTC</span></header>
<main><div class="heading"><div><p class="eyebrow">DIAGNÓSTICO DE SESIONES</p><h1>Observatorio de testers</h1><p>Del informe recibido al detalle de lo que ocurrió en el juego.</p></div><a class="button" href="index.php">Actualizar</a></div>
<section class="metrics" aria-label="Resumen">
<?php foreach (['testers' => 'Testers registrados', 'reports' => 'Informes almacenados', 'today' => 'Recibidos en 24 h', 'bytes' => 'Almacenamiento (MiB)'] as $key => $label): ?>
<article><span><?= $e($label) ?></span><strong><?= $e($key === 'bytes' ? number_format(($totals[$key] ?? 0) / 1048576, 2) : ($totals[$key] ?? 0)) ?></strong></article>
<?php endforeach; ?></section>
<?php if ($error !== ''): ?><p role="alert" class="notice"><?= $e($error) ?></p><?php endif; ?>
<section class="panel"><h2>Testers</h2><div class="table"><table><thead><tr><th>Tester</th><th>Estado</th><th>Alta (UTC)</th><th>Informes</th><th>Última recepción (UTC)</th><th>MiB</th></tr></thead><tbody>
<?php foreach ($testers as $t): ?><tr><td><a href="?tester=<?= (int)$t['id'] ?>">Tester #<?= (int)$t['id'] ?></a></td><td><span class="badge"><?= $t['enabled'] ? 'Habilitado' : 'Deshabilitado' ?></span></td><td><?= $e($t['created_at']) ?></td><td><?= (int)$t['reports'] ?></td><td><?= $e($t['last_received'] ?? 'Sin informes') ?></td><td><?= $e(number_format($t['bytes'] / 1048576, 2)) ?></td></tr><?php endforeach; ?>
<?php if (!$testers): ?><tr><td colspan="6">Todavía no hay testers registrados.</td></tr><?php endif; ?></tbody></table></div></section>
<section class="panel"><div class="heading"><h2>Informes recibidos</h2><form method="get"><label for="tester">Tester</label> <select name="tester" id="tester"><option value="0">Todos</option><?php foreach ($testers as $t): ?><option value="<?= (int)$t['id'] ?>" <?= $tester === (int)$t['id'] ? 'selected' : '' ?>>#<?= (int)$t['id'] ?></option><?php endforeach; ?></select> <button>Filtrar</button></form></div>
<div class="table"><table><thead><tr><th>Informe</th><th>Tester</th><th>Recibido (UTC)</th><th>Caduca (UTC)</th><th>Tamaño</th></tr></thead><tbody>
<?php foreach ($reports as $r): ?><tr><td><a href="?tester=<?= $tester ?>&amp;page=<?= $page ?>&amp;report=<?= (int)$r['id'] ?>#detalle">Abrir #<?= (int)$r['id'] ?></a></td><td>#<?= (int)$r['tester_id'] ?></td><td><?= $e($r['received_at']) ?></td><td><?= $e($r['expires_at']) ?></td><td><?= $e(number_format($r['bytes'] / 1024, 1)) ?> KiB</td></tr><?php endforeach; ?>
<?php if (!$reports): ?><tr><td colspan="5">No hay informes en esta página.</td></tr><?php endif; ?></tbody></table></div>
<nav aria-label="Páginas"><?php if ($page > 1): ?><a href="?tester=<?= $tester ?>&amp;page=<?= $page - 1 ?>">← Anterior</a><?php endif; ?> <span>Página <?= $page ?> · 25 por página</span> <?php if (count($reports) === 25): ?><a href="?tester=<?= $tester ?>&amp;page=<?= $page + 1 ?>">Siguiente →</a><?php endif; ?></nav></section>
<?php if ($selected): ?><section class="panel" id="detalle"><p class="eyebrow">TESTER #<?= (int)$selected['tester_id'] ?></p><h2>Informe #<?= (int)$selected['id'] ?></h2><p>El contenido procede del cliente. Los archivos se leen dentro del ZIP, sin extraerlos.</p>
<dl class="session"><?php foreach (['version'=>'Versión', 'modo'=>'Modo', 'inicio'=>'Inicio', 'fin'=>'Fin', 'segundos'=>'Duración (s)', 'codigo_salida'=>'Código de salida', 'cierre_brusco'=>'Cierre brusco', 'fotogramas'=>'Fotogramas', 'errores_gl'=>'Errores GL', 'gl_fuera_hilo'=>'GL fuera de hilo', 'no_portados'=>'Funciones no portadas', 'usuario'=>'Usuario', 'cuenta'=>'Cuenta', 'servidor'=>'Servidor'] as $key=>$label): ?><div><dt><?= $e($label) ?></dt><dd><?= $e(array_key_exists($key, $session) ? (is_bool($session[$key]) ? ($session[$key] ? 'Sí' : 'No') : $session[$key]) : 'No disponible') ?></dd></div><?php endforeach; ?></dl>
<h3>Archivos del diagnóstico</h3><?php foreach ($texts as $name => $text): ?><details <?= $name === 'informe.txt' ? 'open' : '' ?>><summary><?= $e($name) ?></summary><pre><?= $e($text) ?></pre></details><?php endforeach; ?>
<?php if (!$texts): ?><p>No hay archivos de diagnóstico legibles.</p><?php endif; ?></section><?php endif; ?>
<footer>Zombie Lane · Alpha 0.1.3c · Última versión oficial 0.1.2 · Vista de solo lectura</footer></main></body></html>
<?php
}
