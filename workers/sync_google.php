<?php
declare(strict_types=1);

// Run from CLI only (for example, Windows Task Scheduler).
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

require_once dirname(__DIR__) . '/integrations/google/google_service.php';

$lockPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fairshare-google-sync.lock';
$lock = fopen($lockPath, 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDOUT, '[' . date('c') . '] A sync worker is already running.\n');
    exit(0);
}

try {
    $pdo = require_db();
    $stmt = $pdo->query("SELECT id::text AS id FROM public.tasks WHERE task_type = 'gdocs' AND external_resource_id IS NOT NULL AND sync_status IN ('connected', 'synced', 'partial', 'error') AND (last_synced_at IS NULL OR last_synced_at < CURRENT_TIMESTAMP - INTERVAL '1 minute') ORDER BY COALESCE(last_synced_at, created_at) ASC LIMIT 20");
    $taskIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $total = count($taskIds);
    fwrite(STDOUT, '[' . date('c') . '] Starting Google Docs sync for ' . $total . ' task(s).\n');

    foreach ($taskIds as $taskId) {
        try {
            $result = fs_google_sync_task((string) $taskId);
            fwrite(STDOUT, '[' . date('c') . '] Task ' . $taskId
                . ': status=' . $result['status']
                . ', inserted=' . (int) $result['inserted']
                . ', checked=' . (int) $result['checked_accounts']
                . ', skipped=' . (int) $result['skipped_accounts'] . "\n");
        } catch (Throwable $e) {
            error_log('FairShare Google sync worker task ' . $taskId . ': ' . $e->getMessage());
            fwrite(STDOUT, '[' . date('c') . '] Task ' . $taskId . ': failed; see PHP error log.\n');
        }
    }

    fwrite(STDOUT, '[' . date('c') . '] Worker finished.\n');
} catch (Throwable $e) {
    error_log('FairShare Google sync worker fatal: ' . $e->getMessage());
    fwrite(STDERR, '[' . date('c') . '] Worker failed; see PHP error log.\n');
    exit(1);
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
