<?php
declare(strict_types=1);

const MAX_BYTES = 1048576;
const MAX_ENTRIES = 32;
const MAX_UNPACKED = 8388608;
const DAILY_REPORTS = 20;
const DAILY_BYTES = 10485760;
const MAX_STORED_BYTES = 52428800;

/** Load the private config, defaulting to this directory's config.php for shared hosting. */
function reportConfig(): array {
    $path = getenv('ZL_REPORT_CONFIG');
    if ($path === false || $path === '') {
        $path = __DIR__ . '/config.php';
    }
    if (!is_file($path)) {
        throw new RuntimeException('Configuration unavailable');
    }
    $config = require $path;
    if (!is_array($config)) {
        throw new RuntimeException('Configuration unavailable');
    }
    return $config;
}

function database(array $config): PDO {
    return new PDO($config['dsn'], $config['user'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

function storage(array $config): string {
    $path = realpath($config['storage']);
    if ($path === false || !is_dir($path) || !is_writable($path)) {
        throw new RuntimeException('Private storage unavailable');
    }
    return $path;
}

function validReportId(mixed $id): bool {
    // The Java client uses yyyyMMdd-HHmmss-<UUID> and may append -recuperada.
    return is_string($id) && preg_match('/^[A-Za-z0-9_-]{1,100}$/D', $id) === 1;
}

function validateZip(string $file): bool {
    $zip = new ZipArchive();
    if ($zip->open($file, ZipArchive::CHECKCONS) !== true) return false;
    try {
        if ($zip->numFiles < 1 || $zip->numFiles > MAX_ENTRIES) return false;
        $total = 0;
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) return false;
            $name = $stat['name'];
            if (strlen($name) > 120 || !preg_match('~^[A-Za-z0-9._/-]+$~D', $name)
                || str_starts_with($name, '/') || str_contains($name, '\\')
                || in_array('..', explode('/', $name), true) || str_contains($name, '//')
                || isset($names[$name]) || str_ends_with($name, '/')) return false;
            $names[$name] = true;
            $total += $stat['size'];
            if ($total > MAX_UNPACKED || $stat['size'] > MAX_UNPACKED
                || !in_array($stat['comp_method'], [ZipArchive::CM_STORE, ZipArchive::CM_DEFLATE], true)
                || ($stat['encryption_method'] ?? 0) !== 0) return false;
            if ($zip->getExternalAttributesIndex($i, $opsys, $attrs) && $opsys === ZipArchive::OPSYS_UNIX) {
                $type = ($attrs >> 16) & 0170000;
                if ($type !== 0 && $type !== 0100000) return false;
            }
            // Stream without extracting; enforce actual size and CRC rather than trusting the index.
            $stream = $zip->getStream($name);
            if ($stream === false) return false;
            $crc = hash_init('crc32b');
            $read = 0;
            try {
                while (!feof($stream)) {
                    $chunk = fread($stream, 8192);
                    if ($chunk === false) return false;
                    $read += strlen($chunk);
                    if ($read > $stat['size'] || $read > MAX_UNPACKED) return false;
                    hash_update($crc, $chunk);
                }
            } finally { fclose($stream); }
            if ($read !== $stat['size'] || hexdec(hash_final($crc)) !== $stat['crc']) return false;
        }
        return true;
    } finally { $zip->close(); }
}

/** Returns HTTP status; never returns report content or file names. */
function receive(PDO $db, array $config, array $headers, $input): int {
    $token = $headers['X-ZL-Report-Token'] ?? '';
    if (!is_string($token) || !preg_match('/^[A-Za-z0-9_-]{32,128}$/D', $token)) return 403;
    $id = $headers['X-ZL-Report-Id'] ?? '';
    if (!validReportId($id)) return 400;
    $clientVersion = $headers['X-ZL-Game-Version'] ?? '';
    if (($headers['Content-Type'] ?? '') !== 'application/zip' || ($headers['X-ZL-Consent'] ?? '') !== 'alpha'
        || !in_array($clientVersion, ['0.1.2', '0.1.3a', '0.1.3b', '0.1.3c', '0.1.3'], true)) return 400;
    $dir = storage($config);
    $tmp = tempnam($dir, '.incoming-');
    if ($tmp === false) throw new RuntimeException('Cannot create temporary file');
    $placed = null;
    try {
        $out = fopen($tmp, 'wb');
        if ($out === false) throw new RuntimeException('Cannot open temporary file');
        $size = 0;
        try {
            while (!feof($input)) {
                $chunk = fread($input, 8192);
                if ($chunk === false) throw new RuntimeException('Read failed');
                $size += strlen($chunk);
                if ($size > MAX_BYTES) return 413;
                if (fwrite($out, $chunk) !== strlen($chunk)) throw new RuntimeException('Write failed');
            }
            if (!fflush($out) || !fsync($out)) throw new RuntimeException('Flush failed');
        } finally { fclose($out); }
        if ($size === 0 || !validateZip($tmp)) return 400;
        $bodyHash = hash_file('sha256', $tmp, true);
        $key = hash('sha256', $id, true);
        $db->beginTransaction();
        try {
            // Lock a single tester row to serialize quota, duplicate, cleanup and insertion.
            $q = $db->prepare('SELECT id FROM testers WHERE token_sha256 = ? AND enabled = 1 FOR UPDATE');
            $q->execute([hash('sha256', $token, true)]);
            $tester = $q->fetchColumn();
            if ($tester === false) { $db->rollBack(); return 403; }
            $q = $db->prepare('SELECT tester_id, filename, sha256 FROM reports WHERE report_key = ?');
            $q->execute([$key]);
            $existing = $q->fetch();
            if ($existing !== false) {
                $ok = (int)$existing['tester_id'] === (int)$tester
                    && hash_equals($existing['sha256'], $bodyHash)
                    && is_file($dir . DIRECTORY_SEPARATOR . $existing['filename']);
                $db->rollBack();
                return $ok ? 200 : 409;
            }
            $q = $db->prepare('SELECT COUNT(*) AS n, COALESCE(SUM(bytes),0) AS bytes FROM reports WHERE tester_id = ? AND received_at >= UTC_TIMESTAMP() - INTERVAL 1 DAY');
            $q->execute([$tester]);
            $daily = $q->fetch();
            $q = $db->prepare('SELECT COALESCE(SUM(bytes),0) FROM reports WHERE tester_id = ?');
            $q->execute([$tester]);
            if ((int)$daily['n'] >= DAILY_REPORTS || (int)$daily['bytes'] + $size > DAILY_BYTES
                || (int)$q->fetchColumn() + $size > MAX_STORED_BYTES) { $db->rollBack(); return 429; }
            $filename = bin2hex(random_bytes(32));
            $placed = $dir . DIRECTORY_SEPARATOR . $filename;
            if (!rename($tmp, $placed)) throw new RuntimeException('Cannot place report');
            $q = $db->prepare('INSERT INTO reports (report_key, tester_id, filename, bytes, sha256, received_at, expires_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP() + INTERVAL 30 DAY)');
            $q->execute([$key, $tester, $filename, $size, $bodyHash]);
            $db->commit();
            $placed = null;
            return 200;
        } catch (PDOException $e) {
            if ($db->inTransaction()) $db->rollBack();
            // Competing upload with the same ID: resolve the unique-key winner.
            if ($e->getCode() === '23000') {
                $q = $db->prepare('SELECT tester_id, filename, sha256 FROM reports WHERE report_key = ?');
                $q->execute([$key]);
                $winner = $q->fetch();
                if ($winner !== false) {
                    return (int)$winner['tester_id'] === (int)$tester
                        && hash_equals($winner['sha256'], $bodyHash)
                        && is_file($dir . DIRECTORY_SEPARATOR . $winner['filename']) ? 200 : 409;
                }
            }
            throw $e;
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    } finally {
        if ($placed !== null) @unlink($placed);
        if (is_file($tmp)) @unlink($tmp);
    }
}
