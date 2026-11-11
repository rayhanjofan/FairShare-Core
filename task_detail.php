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
$canManageResource = sa_can_manage_task_resource($task, $user);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'delete_task') {
            $pdo = require_db();
            $pdo->beginTransaction();

            $delTelemetry = $pdo->prepare("DELETE FROM public.telemetry_logs WHERE task_id = CAST(:id AS uuid)");
            $delTelemetry->execute(['id' => $taskId]);

            $delStmt = $pdo->prepare("DELETE FROM public.tasks WHERE id = CAST(:id AS uuid)");
            $delStmt->execute(['id' => $taskId]);

            $pdo->commit();

            log_activity('Tugas dihapus', (string) $task['title'], 'warning');
            flash('Tugas <b>' . e($task['title']) . '</b> berhasil dihapus.');
            redirect('tasks.php');
        }

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
                    throw new InvalidArgumentException('URL harus berupa link Google Docs yang valid (contoh: https://docs.google.com/document/d/.../edit).');
                }
                
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
                flash('Google Docs berhasil diverifikasi dan dihubungkan ke tugas ini. Setiap anggota kelompok perlu menghubungkan akun Google masing-masing agar kontribusinya dapat dihitung.');
                redirect('task_detail.php?id=' . urlencode($taskId));
            }

            if ($task['task_type'] === 'github') {
                $repository = sa_parse_github_repo($resourceUrl);
                if (!$repository) {
                    throw new InvalidArgumentException('URL harus berupa repository GitHub yang valid (contoh: https://github.com/owner/repository).');
                }
                $stmt = require_db()->prepare("UPDATE public.tasks SET resource_url = :resource_url, external_resource_id = :external_id, resource_title = :resource_title, sync_status = 'not_connected', sync_error = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = CAST(:task_id AS UUID)");
                $stmt->execute([
                    'resource_url' => 'https://github.com/' . $repository,
                    'external_id' => $repository,
                    'resource_title' => $repository,
                    'task_id' => $taskId,
                ]);
                flash('Repository GitHub berhasil disimpan untuk tugas ini.');
                redirect('task_detail.php?id=' . urlencode($taskId));
            }

            throw new RuntimeException('Tugas manual tidak membutuhkan resource eksternal.');
        }

        if ($action === 'clear_resource') {
            $stmt = require_db()->prepare("UPDATE public.tasks SET resource_url = NULL, external_resource_id = NULL, resource_title = NULL, sync_status = CASE WHEN task_type = 'manual' THEN 'not_required' ELSE 'not_connected' END, sync_error = NULL, sync_state = '{}'::jsonb, last_synced_at = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = CAST(:task_id AS UUID)");
            $stmt->execute(['task_id' => $taskId]);
            flash('Resource tugas berhasil dilepas.');
            redirect('task_detail.php?id=' . urlencode($taskId));
        }

        throw new InvalidArgumentException('Aksi tidak dikenal.');
    } catch (Throwable $e) {
        error_log('FairShare task detail: ' . $e->getMessage());
        $error = $e instanceof InvalidArgumentException || $e instanceof RuntimeException
            ? $e->getMessage()
            : 'Operasi gagal diproses: ' . $e->getMessage();
    }
}

// Refresh data setelah update
$task = sa_task_by_id($taskId);
if (!$task || !sa_can_access_task($task, $user)) {
    http_response_code(404);
    exit('Tugas tidak ditemukan.');
}
$canManageResource = sa_can_manage_task_resource($task, $user);
$activities = sa_task_activities($taskId, 100);
$integration = fs_google_integration((string) $user['id']);

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

$taskType = (string) ($task['task_type'] ?? 'manual');
$taskStatus = (string) ($task['status'] ?? 'todo');
$taskStatusDb = db_task_status($taskStatus);

layout_start('Detail Tugas: ' . $task['title'], 'tasks');

$headerActions = '<div style="display:flex; align-items:center; gap:8px;">'
    . '<a class="btn secondary" href="tasks.php">← Kembali ke Tugas</a>'
    . '<form method="post" style="display:inline;" onsubmit="return confirm(\'Apakah kamu yakin ingin menghapus tugas ini? Data telemetry terkait juga akan dibersihkan.\');">'
    . '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'
    . '<input type="hidden" name="task_id" value="' . e($taskId) . '">'
    . '<input type="hidden" name="action" value="delete_task">'
    . '<button class="btn danger" type="submit" style="display:inline-flex; align-items:center; gap:5px;">'
    . '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>'
    . 'Hapus Tugas</button>'
    . '</form>'
    . '</div>';

page_heading(
    'SMART ASSIGNMENT / DETAIL TUGAS',
    (string) $task['title'],
    'Kelas ' . (string) $task['class_name'] . ' · Kelompok ' . (string) $task['group_name'],
    $headerActions
);

if ($error !== '') {
    echo '<div class="flash error">' . e($error) . '</div>';
}
?>

<div class="task-detail-container">
    <!-- PANEL 1: INFORMASI UTAMA TUGAS -->
    <section class="panel">
        <div class="task-header-tags">
            <?php if ($taskType === 'gdocs'): ?>
                <span class="task-type-badge gdocs">📄 Google Docs</span>
            <?php elseif ($taskType === 'github'): ?>
                <span class="task-type-badge github">🐙 GitHub</span>
            <?php else: ?>
                <span class="task-type-badge manual">📝 Tugas Manual</span>
            <?php endif; ?>

            <span class="task-status-badge <?= e($taskStatusDb) ?>">
                ● <?= e(ui_task_status($taskStatus)) ?>
            </span>

            <span class="pill" style="background:#f1f5f9; color:#475569; font-size:11px; padding:5px 10px;">
                Target Minggu Ke-<?= (int) ($task['target_week'] ?? 1) ?>
            </span>

            <span class="pill" style="background:#f1f5f9; color:#475569; font-size:11px; padding:5px 10px;">
                Bobot <?= (int) ($task['weight'] ?? 1) ?>×
            </span>
        </div>

        <h1 class="task-detail-title"><?= e($task['title']) ?></h1>

        <?php if (!empty($task['description'])): ?>
            <div class="task-desc-box">
                <?= nl2br(e((string) $task['description'])) ?>
            </div>
        <?php else: ?>
            <p style="font-size:12px; color:#94a3b8; font-style:italic; margin-bottom:18px;">
                Tidak ada catatan atau instruksi khusus untuk tugas ini.
            </p>
        <?php endif; ?>

        <div class="task-meta-grid">
            <div class="task-meta-item">
                <div class="task-meta-label">Kelas Terdaftar</div>
                <div class="task-meta-val"><?= e($task['class_name']) ?></div>
            </div>

            <div class="task-meta-item">
                <div class="task-meta-label">Kelompok Mahasiswa</div>
                <div class="task-meta-val"><?= e($task['group_name']) ?></div>
            </div>

            <div class="task-meta-item">
                <div class="task-meta-label">Bobot Kesulitan</div>
                <div class="task-meta-val"><?= (int) $task['weight'] ?> / 5 (<?= match((int)$task['weight']){ 1=>'Sangat Ringan', 2=>'Ringan', 3=>'Sedang', 4=>'Berat', 5=>'Sangat Kompleks', default=>'Sedang' } ?>)</div>
            </div>

            <div class="task-meta-item">
                <div class="task-meta-label">Target Minggu</div>
                <div class="task-meta-val">Minggu Ke-<?= (int) $task['target_week'] ?></div>
            </div>

            <div class="task-meta-item">
                <div class="task-meta-label">Status Saat Ini</div>
                <div class="task-meta-val"><?= e(ui_task_status($taskStatus)) ?></div>
            </div>
        </div>
    </section>

    <!-- PANEL 2: RESOURCE PENGERJAAN & INTEGRASI (HANYA GDOCS & GITHUB) -->
    <?php if ($taskType === 'gdocs' || $taskType === 'github'): ?>
        <section class="panel">
            <div class="panel-heading">
                <div>
                    <div class="panel-title">Resource Pengerjaan & Audit Aktivitas</div>
                    <div class="panel-sub">Satu resource disimpan khusus untuk tugas ini agar kontribusi anggota dapat dilacak dan tidak tercampur.</div>
                </div>
                <div>
                    <?php if (!empty($task['resource_url'])): ?>
                        <span class="pill green-pill" style="font-size:10px; padding:5px 10px;">● Terhubung</span>
                    <?php else: ?>
                        <span class="pill" style="background:#fff3e0; color:#c2410c; font-size:10px; padding:5px 10px;">○ Belum Terhubung</span>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!empty($task['resource_url'])): ?>
                <div class="resource-highlight-box">
                    <div class="resource-highlight-left">
                        <div class="resource-icon-wrap">
                            <?= $taskType === 'gdocs' ? '📄' : '🐙' ?>
                        </div>
                        <div class="resource-text-wrap">
                            <b><?= e($task['resource_title'] ?: ($taskType === 'gdocs' ? 'Dokumen Google Docs' : 'Repository GitHub')) ?></b>
                            <small title="<?= e($task['resource_url']) ?>"><?= e($task['resource_url']) ?></small>
                        </div>
                    </div>
                    <a class="btn secondary" href="<?= e($task['resource_url']) ?>" target="_blank" rel="noopener noreferrer">
                        Buka Resource ↗
                    </a>
                </div>
            <?php endif; ?>

            <div class="sync-status-grid">
                <div class="sync-status-card">
                    <div class="task-meta-label">Status Sinkronisasi</div>
                    <div class="task-meta-val" style="font-size:12px;">
                        <?= e($syncLabels[$task['sync_status']] ?? 'Belum terhubung') ?>
                    </div>
                </div>
                <div class="sync-status-card">
                    <div class="task-meta-label">Sinkronisasi Terakhir</div>
                    <div class="task-meta-val" style="font-size:12px;">
                        <?= e(!empty($task['last_synced_at']) ? $formatUtcToLocal((string) $task['last_synced_at'], 'd M Y H:i') . ' WIB' : 'Belum pernah') ?>
                    </div>
                </div>
            </div>

            <?php if ($taskType === 'gdocs'): ?>
                <?php if (!$integration): ?>
                    <div class="flash">
                        Akun Google kamu belum terhubung. <a href="integrations.php" style="font-weight:700; text-decoration:underline;">Hubungkan akun Google di sini</a>. Setiap anggota kelompok harus menghubungkan akun Google masing-masing agar aktivitas pengerjaan dapat diatribusikan ke profilnya.
                    </div>
                <?php endif; ?>

                <?php if (!empty($task['external_resource_id'])): ?>
                    <div style="display:flex; align-items:center; gap:10px; margin-bottom:16px;">
                        <form method="post" action="integrations/google/sync.php">
                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="task_id" value="<?= e($taskId) ?>">
                            <button class="btn secondary" type="submit" style="display:inline-flex; align-items:center; gap:6px;">
                                <span>↻</span> Sinkronkan Aktivitas Google Docs Sekarang
                            </button>
                        </form>

                        <?php if ($canManageResource): ?>
                            <form method="post" onsubmit="return confirm('Apakah kamu yakin ingin melepas dokumen ini dari tugas?');">
                                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="task_id" value="<?= e($taskId) ?>">
                                <input type="hidden" name="action" value="clear_resource">
                                <button class="btn danger" type="submit">Lepas Dokumen</button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if ($canManageResource): ?>
                    <details style="margin-top:12px; background:#fafafc; border:1px solid #ededf6; border-radius:10px; padding:12px 16px;">
                        <summary style="font-size:11px; font-weight:700; color:#475569; cursor:pointer;">
                            <?= empty($task['resource_url']) ? '＋ Hubungkan Dokumen Google Docs' : '✎ Ganti URL Dokumen Google Docs' ?>
                        </summary>
                        <form method="post" class="form-grid" style="margin-top:12px;">
                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="task_id" value="<?= e($taskId) ?>">
                            <input type="hidden" name="action" value="save_resource">

                            <div class="field-wide">
                                <label>URL Google Docs <span class="text-danger">*</span></label>
                                <input type="url" name="resource_url" maxlength="2000" required value="<?= e($task['resource_url'] ?? '') ?>" placeholder="https://docs.google.com/document/d/.../edit">
                                <small class="muted" style="display:block; margin-top:4px;">Pastikan dokumen Google Docs sudah dibagikan (share) dengan izin edit kepada anggota kelompok.</small>
                            </div>

                            <div class="field-actions">
                                <button class="btn primary" type="submit">Verifikasi & Simpan Dokumen →</button>
                            </div>
                        </form>
                    </details>
                <?php endif; ?>

                <p class="muted" style="font-size:11px; margin-top:14px; line-height:1.5;">
                    💡 <b>Catatan Google Drive Activity API:</b> API ini merekam aktivitas seperti pembuatan, pengeditan, komentar, dan pembaruan dokumen. Untuk pencatatan yang akurat, anggota kelompok harus mengedit dokumen menggunakan akun Google yang telah dihubungkan di FairShare.
                </p>
            <?php else: ?>
                <!-- GitHub resource -->
                <?php if ($canManageResource): ?>
                    <details style="margin-top:12px; background:#fafafc; border:1px solid #ededf6; border-radius:10px; padding:12px 16px;">
                        <summary style="font-size:11px; font-weight:700; color:#475569; cursor:pointer;">
                            <?= empty($task['resource_url']) ? '＋ Hubungkan Repository GitHub' : '✎ Ganti Repository GitHub' ?>
                        </summary>
                        <form method="post" class="form-grid" style="margin-top:12px;">
                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="task_id" value="<?= e($taskId) ?>">
                            <input type="hidden" name="action" value="save_resource">

                            <div class="field-wide">
                                <label>URL Repository GitHub <span class="text-danger">*</span></label>
                                <input type="url" name="resource_url" maxlength="2000" required value="<?= e($task['resource_url'] ?? '') ?>" placeholder="https://github.com/owner/repository">
                            </div>

                            <div class="field-actions">
                                <button class="btn primary" type="submit">Simpan Repository →</button>
                            </div>
                        </form>
                    </details>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <!-- PANEL 3: RIWAYAT AKTIVITAS & TELEMETRY -->
    <section class="panel">
        <div class="panel-heading">
            <div>
                <div class="panel-title">Riwayat Kontribusi & Aktivitas Pengerjaan</div>
                <div class="panel-sub">Data aktivitas telemetry yang berhasil tercatat untuk tugas kelompok ini.</div>
            </div>
            <span class="counter"><?= count($activities) ?> aktivitas</span>
        </div>

        <?php if (!$activities): ?>
            <div class="empty-state">
                <b>Belum Ada Aktivitas Tercatat</b>
                <p>Hubungkan resource dokumen, pastikan anggota kelompok beraktivitas menggunakan akun terhubung, lalu jalankan sinkronisasi.</p>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Anggota Mahasiswa</th>
                            <th>Sumber</th>
                            <th>Aktivitas</th>
                            <th>Waktu Pengerjaan</th>
                            <th>Raw Delta</th>
                            <th>Filtered Delta</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($activities as $act): ?>
                            <?php
                            $meta = $act['metadata'];
                            $eventTitle = (string) ($meta['event_type'] ?? $meta['action'] ?? 'Aktivitas');
                            $description = (string) ($meta['description'] ?? $meta['commit_message'] ?? $meta['document_title'] ?? '');
                            ?>
                            <tr>
                                <td><b><?= e($act['user_name'] ?? 'Belum teridentifikasi') ?></b></td>
                                <td>
                                    <span class="pill blue-pill" style="font-size:9px;">
                                        <?= e(strtoupper((string) $act['source'])) ?>
                                    </span>
                                </td>
                                <td>
                                    <b><?= e($eventTitle) ?></b>
                                    <?php if ($description !== ''): ?>
                                        <div style="font-size:10px; color:#64748b; margin-top:2px;"><?= e($description) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><?= e($formatUtcToLocal((string) $act['activity_timestamp'], 'd M Y H:i:s')) ?></td>
                                <td><?= e((string) $act['raw_delta']) ?></td>
                                <td><b><?= e((string) $act['filtered_delta']) ?></b></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <!-- PANEL 4: ZONA BAHAYA (HAPUS TUGAS) -->
    <section class="danger-zone-panel">
        <div class="danger-zone-content">
            <div>
                <b style="color:#b91c1c; font-size:13px; display:block;">Hapus Tugas Ini</b>
                <span style="font-size:11px; color:#7f1d1d; display:block; margin-top:2px;">
                    Tindakan ini akan menghapus tugas ini secara permanen dari WBS kelompok. Data telemetry terkait juga akan dibersihkan.
                </span>
            </div>
            <form method="post" onsubmit="return confirm('Apakah kamu yakin ingin menghapus tugas ini secara permanen? Data telemetry terkait juga akan dibersihkan.');">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="task_id" value="<?= e($taskId) ?>">
                <input type="hidden" name="action" value="delete_task">
                <button class="btn danger" type="submit" style="background:#dc2626; color:#fff; border-color:#dc2626;">
                    🗑 Hapus Tugas Secara Permanen
                </button>
            </form>
        </div>
    </section>
</div>

<?php layout_end(); ?>
