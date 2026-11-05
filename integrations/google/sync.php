<?php
declare(strict_types=1);

require_once __DIR__ . '/google_service.php';
require_once dirname(__DIR__, 2) . '/includes/smart_assignment.php';

$user = require_login();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}
verify_csrf();

$taskId = trim((string) ($_POST['task_id'] ?? ''));
$task = sa_task_by_id($taskId);
if (!$task || !sa_can_access_task($task, $user)) {
    http_response_code(404);
    exit('Tugas tidak ditemukan.');
}
if (($task['task_type'] ?? '') !== 'gdocs' || empty($task['external_resource_id'])) {
    flash('Tugas Google Docs belum memiliki dokumen yang terhubung.', 'error');
    redirect('../../task_detail.php?id=' . urlencode($taskId));
}

try {
    $result = fs_google_sync_task($taskId);
    $message = 'Sinkronisasi selesai. Aktivitas baru: ' . (int) $result['inserted']
        . '; akun diperiksa: ' . (int) $result['checked_accounts']
        . '; akun dilewati: ' . (int) $result['skipped_accounts'] . '.';
    flash($message, $result['status'] === 'synced' ? 'success' : 'warning');
} catch (Throwable $e) {
    error_log('FairShare Google sync endpoint task ' . $taskId . ': ' . $e->getMessage());
    flash($e->getMessage(), 'error');
}
redirect('../../task_detail.php?id=' . urlencode($taskId));
