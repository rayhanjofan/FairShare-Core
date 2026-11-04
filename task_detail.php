<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/smart_assignment.php';
require_once __DIR__ . '/integrations/google/google_service.php';

$user = require_login();
$taskId = trim((string) ($_GET['id'] ?? $_POST['task_id'] ?? ''));
$task = sa_task_by_id($taskId);
if (!$task || !sa_can_access_task($task, $user)) {
    http_response_code(404);
    layout_start('Tugas tidak ditemukan', 'tasks');
    page_heading('WORKSPACE / TUGAS', 'Tugas tidak ditemukan', 'Tugas tidak tersedia atau kamu tidak memiliki akses.', '<a class="btn secondary" href="tasks.php">Kembali ke tugas</a>');
    echo '<section class="panel"><div class="empty-state"><b>Akses tidak tersedia</b><p>Periksa kembali kelas dan kelompok yang terkait dengan tugas ini.</p></div></section>';
    layout_end();
    exit;
}

$error = '';
$success = '';
$canManageResource = sa_can_manage_task_resource($task, $user);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');

    try {
        if (!$canManageResource) {
            throw new RuntimeException('Hanya dosen pengampu atau Project Manager kelompok yang dapat mengatur resource tugas.');
        }

        if ($action === 'save_resource') {
            $resourceUrl = trim((string) ($_POST['resource_url'] ?? ''));
            if ($resourceUrl === '' || mb_strlen($resourceUrl) > 2000) {
                throw new InvalidArgumentException('Masukkan URL resource yang valid.');
            }

            if ($task['task_type'] === 'gdocs') {
                $fileId = fs_google_parse_document_id($resourceUrl);
                if (!$fileId) {
                    throw new InvalidArgumentException('URL harus berupa link Google Docs yang valid.');
                }
                // The user who attaches the document must prove access to it.
                $accessToken = fs_google_access_token((string) $user['id']);
                $file = fs_google_verify_document($accessToken, $fileId);
                $verifiedUrl = (string) ($file['webViewLink'] ?? $resourceUrl);
                $stmt = require_db()->prepare("UPDATE public.tasks SET resource_url = :resource_url, external_resource_id = :external_id, resource_title = :resource_title, sync_status = 'connected', sync_error = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = CAST(:task_id AS UUID)");
                $stmt->execute([
                    'resource_url' => $verifiedUrl,
                    'external_id' => $fileId,
                    'resource_title' => (string) ($file['name'] ?? 'Google Docs'),
                    'task_id' => $taskId,
                ]);
                flash('Google Docs berhasil diverifikasi dan dihubungkan ke tugas ini. Setiap anggota perlu menghubungkan akun Google masing-masing agar aktivitasnya dapat diatribusikan.');
                redirect('task_detail.php?id=' . urlencode($taskId));
            }

            if ($task['task_type'] === 'github') {
                $repository = sa_parse_github_repo($resourceUrl);
                if (!$repository) {
                    throw new InvalidArgumentException('URL harus berupa repository GitHub, misalnya https://github.com/owner/repository.');
                }
                $stmt = require_db()->prepare("UPDATE public.tasks SET resource_url = :resource_url, external_resource_id = :external_id, resource_title = :resource_title, sync_status = 'not_connected', sync_error = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = CAST(:task_id AS UUID)");
                $stmt->execute([
                    'resource_url' => 'https://github.com/' . $repository,
                    'external_id' => $repository,
                    'resource_title' => $repository,
                    'task_id' => $taskId,
                ]);
                flash('Repository GitHub berhasil disimpan. Pengambilan webhook/commit otomatis belum diaktifkan pada tahap ini.');
                redirect('task_detail.php?id=' . urlencode($taskId));
            }

            throw new RuntimeException('Tugas manual tidak membutuhkan resource eksternal.');
        }

        if ($action === 'clear_resource') {
            $stmt = require_db()->prepare("UPDATE public.tasks SET resource_url = NULL, external_resource_id = NULL, resource_title = NULL, sync_status = CASE WHEN task_type = 'manual' THEN 'not_required' ELSE 'not_connected' END, sync_error = NULL, sync_state = '{}'::jsonb, last_synced_at = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = CAST(:task_id AS UUID)");
            $stmt->execute(['task_id' => $taskId]);
            flash('Resource tugas berhasil dilepas. Riwayat telemetry yang sudah tercatat tetap dipertahankan sebagai histori.');
            redirect('task_detail.php?id=' . urlencode($taskId));
        }

        throw new InvalidArgumentException('Aksi tidak dikenal.');
    } catch (Throwable $e) {
        error_log('FairShare task detail: ' . $e->getMessage());
        $error = $e instanceof InvalidArgumentException || $e instanceof RuntimeException
            ? $e->getMessage()
            : 'Resource gagal disimpan. Periksa koneksi, migration, dan log aplikasi.';
    }
}

// Refresh after POST processing and before rendering.
$task = sa_task_by_id($taskId);
if (!$task || !sa_can_access_task($task, $user)) {
    http_response_code(404);
    exit('Tugas tidak ditemukan.');
}
$canManageResource = sa_can_manage_task_resource($task, $user);
$activities = sa_task_activities($taskId, 100);
$integration = fs_google_integration((string) $user['id']);
$typeLabels = ['manual' => 'Manual', 'gdocs' => 'Google Docs', 'github' => 'GitHub'];
$formatUtcToLocal = static function (?string $value, string $format = 'd M Y H:i:s'): string {
    if ($value === null || trim($value) === '') {
        return 'Belum pernah';
    }
    try {
        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('Asia/Jakarta'))
            ->format($format);
    } catch (Throwable) {
        return $value;
    }
};
$syncLabels = [
    'not_required' => 'Tidak membutuhkan sinkronisasi',
    'not_connected' => 'Resource belum terhubung / sinkronisasi belum aktif',
    'connected' => 'Resource terhubung',
    'synced' => 'Tersinkronisasi',
    'partial' => 'Sinkronisasi sebagian',
    'error' => 'Sinkronisasi gagal',
];

layout_start('Detail Tugas', 'tasks');
page_heading(
    'SMART ASSIGNMENT / DETAIL',
    (string) $task['title'],
    (string) $task['class_name'] . ' · ' . (string) $task['group_name'],
    '<a class="btn secondary" href="tasks.php">← Kembali ke tugas</a>'
);
if ($error !== '') {
    echo '<div class="flash error">' . e($error) . '</div>';
}

$dueLabel = !empty($task['due_at']) ? date('d M Y H:i', strtotime((string) $task['due_at'])) . ' WIB' : 'Tidak ditentukan';
echo '<section class="panel task-detail-summary"><div class="task-detail-top"><div><span class="status-pill muted">' . e($typeLabels[$task['task_type']] ?? 'Manual') . '</span><span class="status-pill muted">' . e(ui_task_status((string) $task['status'])) . '</span><h2>' . e($task['title']) . '</h2><p>' . nl2br(e((string) ($task['description'] ?? ''))) . '</p></div><div class="task-detail-meta"><span>Kelompok</span><b>' . e($task['group_name']) . '</b><span>Kelas</span><b>' . e($task['class_name']) . '</b><span>Bobot</span><b>' . (int) $task['weight'] . ' / 5</b><span>Target minggu</span><b>' . (int) $task['target_week'] . '</b><span>Deadline</span><b>' . e($dueLabel) . '</b></div></div></section>';

if ($task['task_type'] === 'gdocs' || $task['task_type'] === 'github') {
    echo '<section class="panel task-resource-panel"><div class="panel-heading"><div><div class="panel-title">Resource pengerjaan</div><div class="panel-sub">Satu resource disimpan untuk tugas kelompok ini, sehingga aktivitas kelompok lain tidak tercampur.</div></div><span class="status-pill ' . (!empty($task['resource_url']) ? 'success' : 'muted') . '">' . (!empty($task['resource_url']) ? 'Terhubung' : 'Belum terhubung') . '</span></div>';
    if (!empty($task['resource_url'])) {
        echo '<div class="resource-link-panel"><div><b>' . e($task['resource_title'] ?: $task['external_resource_id']) . '</b><small>' . e($task['resource_url']) . '</small></div><a class="btn secondary" href="' . e($task['resource_url']) . '" target="_blank" rel="noopener noreferrer">Buka resource ↗</a></div>';
    }
    echo '<div class="task-sync-summary"><span>Status sinkronisasi</span><b>' . e($syncLabels[$task['sync_status']] ?? 'Belum diketahui') . '</b><span>Sinkronisasi terakhir</span><b>' . e(!empty($task['last_synced_at']) ? $formatUtcToLocal((string) $task['last_synced_at'], 'd M Y H:i') . ' WIB' : 'Belum pernah') . '</b></div>';
    if ($task['task_type'] === 'gdocs') {
        if (!$integration) {
            echo '<div class="flash">Akun Google kamu belum terhubung. <a href="integrations.php">Hubungkan akun Google</a>. Untuk atribusi kontribusi, setiap anggota kelompok harus menghubungkan akun Google masing-masing.</div>';
        }
        if ($canManageResource) {
            echo '<form method="post" class="form-grid task-resource-form"><input type="hidden" name="csrf" value="' . e(csrf_token()) . '"><input type="hidden" name="task_id" value="' . e($taskId) . '"><input type="hidden" name="action" value="save_resource"><div class="field-wide"><label>URL Google Docs</label><input type="url" name="resource_url" maxlength="2000" required value="' . e($task['resource_url'] ?? '') . '" placeholder="https://docs.google.com/document/d/.../edit"></div><div class="field-actions"><button class="btn primary" type="submit">Verifikasi dan hubungkan dokumen</button></div></form>';
            if (!empty($task['external_resource_id'])) {
                echo '<form method="post" onsubmit="return confirm(\'Lepas dokumen dari tugas ini?\')"><input type="hidden" name="csrf" value="' . e(csrf_token()) . '"><input type="hidden" name="task_id" value="' . e($taskId) . '"><input type="hidden" name="action" value="clear_resource"><button class="btn danger" type="submit">Lepas resource</button></form>';
            }
        }
        if (!empty($task['external_resource_id'])) {
            echo '<form method="post" action="integrations/google/sync.php" class="task-sync-form"><input type="hidden" name="csrf" value="' . e(csrf_token()) . '"><input type="hidden" name="task_id" value="' . e($taskId) . '"><button class="btn secondary" type="submit">↻ Sinkronkan aktivitas Google Docs sekarang</button></form>';
        }
        echo '<p class="muted task-integration-note">Drive Activity API menampilkan aktivitas yang tersedia dari Google. API ini bukan perekam setiap ketikan. Aktivitas hanya diatribusikan jika identitas akun yang melakukan sinkronisasi dapat diverifikasi.</p>';
    } else {
        if ($canManageResource) {
            echo '<form method="post" class="form-grid task-resource-form"><input type="hidden" name="csrf" value="' . e(csrf_token()) . '"><input type="hidden" name="task_id" value="' . e($taskId) . '"><input type="hidden" name="action" value="save_resource"><div class="field-wide"><label>URL Repository GitHub</label><input type="url" name="resource_url" maxlength="2000" required value="' . e($task['resource_url'] ?? '') . '" placeholder="https://github.com/owner/repository"></div><div class="field-actions"><button class="btn primary" type="submit">Simpan repository</button></div></form>';
        }
        echo '<div class="flash">Repository dapat ditautkan sekarang. Pengambilan webhook dan commit GitHub otomatis belum termasuk tahap integrasi Google Docs ini.</div>';
    }
    echo '</section>';
}

if (!empty($task['sync_error'])) {
    echo '<div class="flash error">Sinkronisasi terakhir mengalami kendala. Coba ulangi dan periksa konfigurasi API. Detail teknis dicatat di log server.</div>';
}

echo '<section class="panel"><div class="panel-heading"><div><div class="panel-title">Riwayat aktivitas tugas</div><div class="panel-sub">Aktivitas yang telah masuk ke telemetry untuk tugas dan kelompok ini.</div></div><span class="counter">' . count($activities) . ' aktivitas</span></div>';
if (!$activities) {
    echo '<div class="empty-state"><b>Belum ada aktivitas tercatat</b><p>Hubungkan resource, pastikan anggota mengerjakan melalui akun yang terhubung, lalu sinkronkan aktivitas.</p></div>';
} else {
    echo '<div class="activity-table-wrap"><table class="data-table"><thead><tr><th>Anggota</th><th>Sumber</th><th>Aktivitas</th><th>Waktu</th><th>Raw</th><th>Filtered</th></tr></thead><tbody>';
    foreach ($activities as $activity) {
        $meta = $activity['metadata'];
        $eventTitle = (string) ($meta['event_type'] ?? $meta['action'] ?? 'Aktivitas');
        $description = (string) ($meta['description'] ?? $meta['commit_message'] ?? $meta['document_title'] ?? '');
        echo '<tr><td><b>' . e($activity['user_name'] ?? 'Belum teridentifikasi') . '</b></td><td><span class="status-pill muted">' . e(strtoupper((string) $activity['source'])) . '</span></td><td><b>' . e($eventTitle) . '</b><small class="table-secondary">' . e($description) . '</small></td><td>' . e($formatUtcToLocal((string) $activity['activity_timestamp'], 'd M Y H:i:s')) . '</td><td>' . e((string) $activity['raw_delta']) . '</td><td>' . e((string) $activity['filtered_delta']) . '</td></tr>';
    }
    echo '</tbody></table></div>';
}
echo '</section>';

layout_end();
