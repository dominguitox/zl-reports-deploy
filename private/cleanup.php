<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require __DIR__ . '/app.php';
try {
    $config = reportConfig();
    $db = database($config);
    $dir = storage($config);
    // Delete file first, then row. Failure leaves a row for the next run.
    $q = $db->query('SELECT id, tester_id FROM reports WHERE expires_at <= UTC_TIMESTAMP() ORDER BY id LIMIT 500');
    foreach ($q as $row) {
        $db->beginTransaction();
        try {
            // Same lock order as upload: tester first, then report.
            $test = $db->prepare('SELECT id FROM testers WHERE id = ? FOR UPDATE');
            $test->execute([$row['tester_id']]);
            $lock = $db->prepare('SELECT filename FROM reports WHERE id = ? AND expires_at <= UTC_TIMESTAMP() FOR UPDATE');
            $lock->execute([$row['id']]);
            $current = $lock->fetch();
            if ($current === false) { $db->commit(); continue; }
            $name = $current['filename'];
            if (!preg_match('/^[a-f0-9]{64}$/D', $name)) throw new RuntimeException('Invalid stored filename');
            $file = $dir . DIRECTORY_SEPARATOR . $name;
            if (is_file($file) && !unlink($file)) throw new RuntimeException('Delete failed');
            $delete = $db->prepare('DELETE FROM reports WHERE id = ?');
            $delete->execute([$row['id']]);
            $db->commit();
        } catch (Throwable $e) { $db->rollBack(); throw $e; }
    }
    // After a crash, unreferenced files and unfinished uploads are removed.
    // One-day grace prevents racing in-flight uploads; no public paths are scanned.
    $refs = $db->query('SELECT filename FROM reports')->fetchAll(PDO::FETCH_COLUMN);
    $known = array_fill_keys($refs, true);
    foreach (new DirectoryIterator($dir) as $item) {
        if (!$item->isFile() || $item->isLink() || $item->getMTime() > time() - 86400) continue;
        $name = $item->getFilename();
        if ((preg_match('/^[a-f0-9]{64}$/D', $name) && !isset($known[$name]))
            || str_starts_with($name, '.incoming-')) {
            if (!unlink($item->getPathname())) throw new RuntimeException('Orphan deletion failed');
        }
    }
} catch (Throwable $e) {
    error_log('Report cleanup failed');
    exit(1);
}
