<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/integrations/google/google_service.php';
require_once __DIR__ . '/includes/smart_assignment.php';

$user = require_login();
$teacher = is_teacher($user);
$error = '';
$groups = visible_groups($user);
$classes = visible_classes($user);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');

    try {
        $pdo = require_db();

        if ($action === 'create') {
            $title = trim((string) ($_POST['title'] ?? ''));
            $desc = trim((string) ($_POST['description'] ?? ''));
            $status = db_task_status((string) ($_POST['status'] ?? 'Belum dikerjakan'));
            $targetWeek = max(1, (int) ($_POST['target_week'] ?? 1));
            $weight = max(1, min(5, (int) ($_POST['weight'] ?? 1)));

            // Pilihan tipe tugas: manual, gdocs, github
            $taskType = trim((string) ($_POST['task_type'] ?? 'manual'));
            if (!in_array($taskType, ['manual', 'gdocs', 'github'], true)) {
                $taskType = 'manual';
            }
            $rawResourceUrl = trim((string) ($_POST['resource_url'] ?? ''));
            $resourceUrl = null;
            $externalResourceId = null;
            $resourceTitle = null;
            $syncStatus = 'not_required';

            if ($taskType === 'gdocs') {
                if ($rawResourceUrl === '') {
                    $error = 'Link dokumen Google Docs wajib diisi jika memilih jenis tugas Google Docs.';
                } else {
                    $fileId = fs_google_parse_document_id($rawResourceUrl);
                    if (!$fileId) {
                        $error = 'URL harus berupa link Google Docs yang valid (contoh: https://docs.google.com/document/d/.../edit).';
                    } else {
                        $resourceUrl = $rawResourceUrl;
                        $externalResourceId = $fileId;
                        $resourceTitle = 'Google Docs';
                        $syncStatus = 'connected';
                        try {
                            $accessToken = fs_google_access_token((string) $user['id']);
                            $doc = fs_google_verify_document($accessToken, $fileId);
                            $resourceTitle = (string) ($doc['name'] ?? 'Google Docs');
                            $resourceUrl = (string) ($doc['webViewLink'] ?? $resourceUrl);
                        } catch (Throwable) {
                            // Link tetap disimpan; verifikasi dokumen dapat disinkronkan saat audit
                        }
                    }
                }
            } elseif ($taskType === 'github') {
                if ($rawResourceUrl === '') {
                    $error = 'Link repository GitHub wajib diisi jika memilih jenis tugas Kode di GitHub.';
                } else {
                    $repo = sa_parse_github_repo($rawResourceUrl);
                    if (!$repo) {
                        $error = 'URL harus berupa repository GitHub yang valid (contoh: https://github.com/owner/repository).';
                    } else {
                        $resourceUrl = 'https://github.com/' . $repo;
                        $externalResourceId = $repo;
                        $resourceTitle = $repo;
                        $syncStatus = 'not_connected';
                    }
                }
            }

            if ($error === '' && ($title === '' || mb_strlen($title) > 200)) {
                $error = 'Judul tugas valid wajib diisi (maksimal 200 karakter).';
            }

            if ($error === '') {
                if ($teacher) {
                    // Dosen membuat tugas: bisa untuk semua kelompok di suatu kelas atau kelompok tertentu
                    $scope = (string) ($_POST['scope'] ?? 'single');
                    $classId = trim((string) ($_POST['class_id'] ?? ''));
                    $singleGid = trim((string) ($_POST['group_id'] ?? ''));

                    $targetGroupIds = [];

                    if ($scope === 'all_in_class' && valid_uuid($classId)) {
                        // Pastikan dosen pemilik kelas
                        $class = class_by_id($classId);
                        if (!$class || strtolower((string) $class['created_by']) !== strtolower((string) $user['id'])) {
                            throw new RuntimeException('Kamu tidak berwenang mengelola kelas ini.');
                        }
                        $allClassTeams = class_teams($classId);
                        $targetGroupIds = array_column($allClassTeams, 'id');
                        if (empty($targetGroupIds)) {
                            throw new RuntimeException('Belum ada kelompok yang terbentuk di kelas ini.');
                        }
                    } elseif (valid_uuid($singleGid)) {
                        $group = group_by_id($singleGid);
                        if (!$group || !can_manage_group($group, $user)) {
                            throw new RuntimeException('Kamu tidak memiliki akses ke kelompok ini.');
                        }
                        $targetGroupIds = [$singleGid];
                    } else {
                        throw new RuntimeException('Kelompok atau kelas target tidak valid.');
                    }

                    $pdo->beginTransaction();
                    $stmt = $pdo->prepare("
                        INSERT INTO public.tasks (
                            id,
                            group_id,
                            assigned_user_id,
                            assignment_batch_id,
                            title,
                            description,
                            weight,
                            status,
                            target_week,
                            task_type,
                            resource_url,
                            external_resource_id,
                            resource_title,
                            sync_status,
                            completed_at,
                            created_at,
                            updated_at
                        ) VALUES (
                            gen_random_uuid(),
                            CAST(:gid AS uuid),
                            NULL,
                            CAST(:batch_id AS uuid),
                            :title,
                            :description,
                            :weight,
                            :status,
                            :target_week,
                            :task_type,
                            :resource_url,
                            :external_resource_id,
                            :resource_title,
                            :sync_status,
                            CASE WHEN :status_done = 'done' THEN CURRENT_TIMESTAMP ELSE NULL END,
                            CURRENT_TIMESTAMP,
                            CURRENT_TIMESTAMP
                        )
                    ");

                    $batchId = count($targetGroupIds) > 1 ? $pdo->query("SELECT gen_random_uuid()")->fetchColumn() : null;

                    foreach ($targetGroupIds as $tgid) {
                        $stmt->execute([
                            'gid'                  => $tgid,
                            'batch_id'             => $batchId,
                            'title'                => $title,
                            'description'          => $desc !== '' ? $desc : null,
                            'weight'               => $weight,
                            'status'               => $status,
                            'target_week'          => $targetWeek,
                            'task_type'            => $taskType,
                            'resource_url'         => $resourceUrl,
                            'external_resource_id' => $externalResourceId,
                            'resource_title'       => $resourceTitle,
                            'sync_status'          => $syncStatus,
                            'status_done'          => $status,
                        ]);
                    }

                    $pdo->commit();
                    $cnt = count($targetGroupIds);
                    log_activity('Tugas dibuat oleh dosen', "$title untuk $cnt kelompok", 'success');
                    flash("Tugas berhasil dibagikan kepada $cnt kelompok.");
                    redirect('tasks.php');
                } else {
                    // Mahasiswa membuat tugas untuk kelompoknya
                    $gid = trim((string) ($_POST['group_id'] ?? ''));
                    $group = group_by_id($gid);
                    $isMember = $group && in_array(strtolower((string) $user['id']), array_map('strtolower', $group['member_ids'] ?? []), true);

                    if (!$group || !$isMember) {
                        $error = 'Pilih kelompok yang kamu ikuti.';
                    } else {
                        $stmt = $pdo->prepare("
                            INSERT INTO public.tasks (
                                id,
                                group_id,
                                assigned_user_id,
                                assignment_batch_id,
                                title,
                                description,
                                weight,
                                status,
                                target_week,
                                task_type,
                                resource_url,
                                external_resource_id,
                                resource_title,
                                sync_status,
                                completed_at,
                                created_at,
                                updated_at
                            ) VALUES (
                                gen_random_uuid(),
                                CAST(:gid AS uuid),
                                CAST(:uid AS uuid),
                                NULL,
                                :title,
                                :description,
                                :weight,
                                :status,
                                :target_week,
                                :task_type,
                                :resource_url,
                                :external_resource_id,
                                :resource_title,
                                :sync_status,
                                CASE WHEN :status_done = 'done' THEN CURRENT_TIMESTAMP ELSE NULL END,
                                CURRENT_TIMESTAMP,
                                CURRENT_TIMESTAMP
                            )
                        ");
                        $stmt->execute([
                            'gid'                  => $gid,
                            'uid'                  => (string) $user['id'],
                            'title'                => $title,
                            'description'          => $desc !== '' ? $desc : null,
                            'weight'               => $weight,
                            'status'               => $status,
                            'target_week'          => $targetWeek,
                            'task_type'            => $taskType,
                            'resource_url'         => $resourceUrl,
                            'external_resource_id' => $externalResourceId,
                            'resource_title'       => $resourceTitle,
                            'sync_status'          => $syncStatus,
                            'status_done'          => $status,
                        ]);

                        log_activity('Tugas kelompok dibuat', $title, 'success');
                        flash('Tugas berhasil ditambahkan ke WBS kelompok.');
                        redirect('tasks.php');
                    }
                }
            }
        } elseif ($action === 'update') {
            $id = trim((string) ($_POST['id'] ?? ''));
            $status = db_task_status((string) ($_POST['status'] ?? 'Belum dikerjakan'));

            if (!valid_uuid($id)) {
                $error = 'ID tugas tidak valid.';
            } else {
                $taskStmt = $pdo->prepare("
                    SELECT t.id::text AS id, t.group_id::text AS group_id, g.parent_group_id::text AS parent_group_id
                    FROM public.tasks t
                    JOIN public.groups g ON g.id = t.group_id
                    WHERE t.id = CAST(:id AS uuid)
                    LIMIT 1
                ");
                $taskStmt->execute(['id' => $id]);
                $task = $taskStmt->fetch(PDO::FETCH_ASSOC);

                $allowedGroups = array_map('strtolower', array_column($groups, 'id'));
                if (!$task || !in_array(strtolower((string) $task['group_id']), $allowedGroups, true)) {
                    $error = 'Tugas tidak ditemukan atau kamu tidak memiliki akses ke kelompok ini.';
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE public.tasks
                        SET status = :status,
                            completed_at = CASE WHEN :status_done = 'done' THEN COALESCE(completed_at, CURRENT_TIMESTAMP) ELSE NULL END,
                            updated_at = CURRENT_TIMESTAMP
                        WHERE id = CAST(:id AS uuid)
                    ");
                    $stmt->execute([
                        'status'      => $status,
                        'status_done' => $status,
                        'id'          => $id,
                    ]);

                    log_activity('Status tugas diperbarui', 'Status diubah menjadi ' . ui_task_status($status), 'success');
                    flash('Status tugas berhasil diperbarui di Supabase.');
                    redirect('tasks.php');
                }
            }
        } elseif ($action === 'delete') {
            $id = trim((string) ($_POST['id'] ?? ''));

            if (!valid_uuid($id)) {
                $error = 'ID tugas tidak valid.';
            } else {
                $taskStmt = $pdo->prepare("
                    SELECT t.id::text AS id, t.title, t.group_id::text AS group_id, g.parent_group_id::text AS parent_group_id
                    FROM public.tasks t
                    JOIN public.groups g ON g.id = t.group_id
                    WHERE t.id = CAST(:id AS uuid)
                    LIMIT 1
                ");
                $taskStmt->execute(['id' => $id]);
                $task = $taskStmt->fetch(PDO::FETCH_ASSOC);

                $allowedGroups = array_map('strtolower', array_column($groups, 'id'));
                if (!$task || !in_array(strtolower((string) $task['group_id']), $allowedGroups, true)) {
                    $error = 'Tugas tidak ditemukan atau kamu tidak memiliki akses untuk menghapus tugas ini.';
                } else {
                    $pdo->beginTransaction();

                    $delTelemetry = $pdo->prepare("DELETE FROM public.telemetry_logs WHERE task_id = CAST(:id AS uuid)");
                    $delTelemetry->execute(['id' => $id]);

                    $delStmt = $pdo->prepare("DELETE FROM public.tasks WHERE id = CAST(:id AS uuid)");
                    $delStmt->execute(['id' => $id]);

                    $pdo->commit();

                    log_activity('Tugas dihapus', (string) $task['title'], 'warning');
                    flash('Tugas <b>' . e($task['title']) . '</b> berhasil dihapus.');
                    redirect('tasks.php');
                }
            }
        }
    } catch (PDOException $e) {
        error_log('FairShare tasks SQLSTATE ' . $e->getCode() . ': ' . $e->getMessage());
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = 'Tugas gagal disimpan: ' . $e->getMessage();
    } catch (Throwable $e) {
        error_log('FairShare tasks: ' . $e->getMessage());
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e->getMessage();
    }
}

$allTasks = all_tasks();
$groupIds = array_map('strtolower', array_column($groups, 'id'));
$tasks = array_values(array_filter($allTasks, fn($t) => in_array(strtolower((string) ($t['group_id'] ?? '')), $groupIds, true)));

$requestedGroup = trim((string) ($_GET['group'] ?? ''));
if ($requestedGroup !== '' && valid_uuid($requestedGroup)) {
    $tasks = array_values(array_filter($tasks, fn($t) => strtolower((string) $t['group_id']) === strtolower($requestedGroup)));
}

$filterStatus = trim((string) ($_GET['status'] ?? ''));
if ($filterStatus !== '') {
    $tasks = array_values(array_filter($tasks, fn($t) => ($t['status'] ?? '') === $filterStatus));
}

layout_start($teacher ? 'Pemantauan Tugas' : 'Tugas', 'tasks');

page_heading(
    $teacher ? 'DOSEN / PEMANTAUAN WBS' : 'WORKSPACE / WORK BREAKDOWN STRUCTURE',
    $teacher ? 'Pemantauan Tugas WBS' : 'WBS & Tugas Kelompok',
    $teacher ? 'Pantau pembagian kerja, status, dan target minggu tugas kelompok di kelasmu.' : 'Pecah pekerjaan menjadi tugas yang terukur, dengan bobot kesulitan, penanggung jawab, dan target minggu.',
    '<button class="btn primary" id="openTaskModal" type="button" onclick="openNewTaskModal()">＋ Tambah Tugas</button>'
);

if ($error) {
    echo '<div class="flash error">' . e($error) . '</div>';
}

$allUserTasks = $tasks;
$doneCount = count(array_filter($allUserTasks, fn($t) => ($t['status_db'] ?? '') === 'done'));
$inProgCount = count(array_filter($allUserTasks, fn($t) => ($t['status_db'] ?? '') === 'in_progress'));
?>

<div class="stats-grid three-stats">
    <article class="stat-card">
        <div class="stat-label">TOTAL TUGAS TERPANTAU</div>
        <div class="stat-number"><?= count($allUserTasks) ?></div>
        <div class="stat-foot"><?= $teacher ? 'Tugas dalam kelasmu' : 'Tugas dalam kelompokmu' ?></div>
    </article>
    <article class="stat-card">
        <div class="stat-label">SEDANG DIKERJAKAN</div>
        <div class="stat-number"><?= $inProgCount ?></div>
        <div class="stat-foot">Aktivitas pengerjaan berjalan</div>
    </article>
    <article class="stat-card">
        <div class="stat-label">SELESAI DIKERJAKAN</div>
        <div class="stat-number"><?= $doneCount ?></div>
        <div class="stat-foot">Target WBS tuntas</div>
    </article>
</div>

<div class="kanban-filter">
    <span class="filter-caption">FILTER STATUS</span>
    <a class="<?= $filterStatus === '' ? 'active' : '' ?>" href="tasks.php<?= $requestedGroup ? '?group=' . urlencode($requestedGroup) : '' ?>">Semua</a>
    <a class="<?= $filterStatus === 'Belum dikerjakan' ? 'active' : '' ?>" href="tasks.php?status=Belum%20dikerjakan<?= $requestedGroup ? '&group=' . urlencode($requestedGroup) : '' ?>">Belum dikerjakan</a>
    <a class="<?= $filterStatus === 'Dikerjakan' ? 'active' : '' ?>" href="tasks.php?status=Dikerjakan<?= $requestedGroup ? '&group=' . urlencode($requestedGroup) : '' ?>">Dikerjakan</a>
    <a class="<?= $filterStatus === 'Review' ? 'active' : '' ?>" href="tasks.php?status=Review<?= $requestedGroup ? '&group=' . urlencode($requestedGroup) : '' ?>">Review</a>
    <a class="<?= $filterStatus === 'Selesai' ? 'active' : '' ?>" href="tasks.php?status=Selesai<?= $requestedGroup ? '&group=' . urlencode($requestedGroup) : '' ?>">Selesai</a>
</div>

<div class="task-board">
    <?php
    $columns = [
        'Belum dikerjakan' => 'todo',
        'Dikerjakan'       => 'doing',
        'Review'           => 'review',
        'Selesai'          => 'done',
    ];
    foreach ($columns as $statusLabel => $cls):
        $subset = array_values(array_filter($tasks, fn($t) => ($t['status'] ?? '') === $statusLabel));
    ?>
        <section class="kanban-column">
            <div class="kanban-head">
                <span class="kanban-dot <?= $cls ?>"></span>
                <b><?= e($statusLabel) ?></b>
                <span class="kanban-count"><?= count($subset) ?></span>
            </div>
            <?php foreach ($subset as $t): ?>
                <?php $tid = e($t['id']); ?>
                <article class="kanban-card">
                    <div class="kanban-card-top">
                        <span class="task-tag"><?= e($t['weight']) ?>× bobot</span>
                        <span class="pill blue-pill" style="font-size:9px;"><?= e($t['group_name'] ?? 'Kelompok') ?></span>
                        <?php if (($t['task_type'] ?? '') === 'gdocs'): ?>
                            <span class="pill" style="font-size:9px; background:#e8f5e9; color:#1b5e20;">📄 Docs</span>
                        <?php elseif (($t['task_type'] ?? '') === 'github'): ?>
                            <span class="pill" style="font-size:9px; background:#f3e8ff; color:#6b21a8;">🐙 Git</span>
                        <?php endif; ?>

                        <div class="task-dropdown-wrap">
                            <button type="button" class="task-dropdown-trigger" title="Opsi tugas" onclick="toggleTaskMenu('<?= $tid ?>', event)">
                                •••
                            </button>
                            <div class="task-dropdown-menu" id="taskMenu-<?= $tid ?>" hidden>
                                <a class="task-dropdown-item" href="task_detail.php?id=<?= urlencode((string) $t['id']) ?>">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                                    <span>Lihat Detail & Audit</span>
                                </a>
                                <div class="task-dropdown-divider"></div>
                                <form method="post" onsubmit="return confirm('Apakah kamu yakin ingin menghapus tugas ini? Data telemetry terkait juga akan dibersihkan.');">
                                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= $tid ?>">
                                    <button type="submit" class="task-dropdown-item danger">
                                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                                        <span>Hapus Tugas</span>
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                    <h3><a href="task_detail.php?id=<?= urlencode((string) $t['id']) ?>" style="color:inherit; text-decoration:none;"><?= e($t['title']) ?></a></h3>
                    <?php if (!empty($t['description'])): ?>
                        <p style="font-size:11px; color:#7e849e; margin-top:4px;"><?= e($t['description']) ?></p>
                    <?php endif; ?>
                    <?php if (!empty($t['resource_url'])): ?>
                        <div style="margin-top:6px; font-size:11px;">
                            <a href="<?= e($t['resource_url']) ?>" target="_blank" rel="noopener noreferrer" style="color:#2563eb; text-decoration:underline;">
                                <?= ($t['task_type'] ?? '') === 'gdocs' ? '📄 Buka Google Docs' : '🐙 Buka Repository' ?> ↗
                            </a>
                        </div>
                    <?php endif; ?>
                    <div class="kanban-card-bottom" style="margin-top:10px;">
                        <span class="due-date">◷ <?= e($t['due_date'] ?? ('Minggu ' . ($t['target_week'] ?? 1))) ?></span>
                        <form method="post" class="status-form">
                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="update">
                            <input type="hidden" name="id" value="<?= $tid ?>">
                            <select name="status" aria-label="Ubah status" onchange="this.form.submit()">
                                <?php foreach (array_keys($columns) as $s): ?>
                                    <option <?= $s === ($t['status'] ?? '') ? 'selected' : '' ?> value="<?= e($s) ?>"><?= e($s) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                    </div>
                </article>
            <?php endforeach; ?>
            <a class="kanban-add" href="#" onclick="openNewTaskModal(); return false;">＋ Tambah tugas</a>
        </section>
    <?php endforeach; ?>
</div>

<?php if (!$groups): ?>
<!-- Modal Tambah Tugas (no groups state) -->
<div id="taskModal" class="fs-modal-overlay" hidden aria-modal="true" role="dialog" aria-labelledby="taskModalTitle">
    <div class="fs-modal">
        <div class="fs-modal-header">
            <div>
                <h2 class="fs-modal-title" id="taskModalTitle"><?= $teacher ? 'Tambah Tugas WBS' : 'Tambah Tugas WBS' ?></h2>
                <p class="fs-modal-sub"><?= $teacher ? 'Tugas akan dibagikan ke kelompok penerima.' : 'Tugas akan dicatat pada WBS kelompokmu.' ?></p>
            </div>
            <button type="button" class="fs-modal-close" onclick="closeNewTaskModal()" aria-label="Tutup">✕</button>
        </div>
        <div class="fs-modal-body">
            <div class="flash error">
                <?= $teacher
                    ? 'Belum ada kelompok yang terbentuk pada kelasmu. Buat kelompok terlebih dahulu di menu Kelas Saya.'
                    : 'Buat atau gabung ke kelompok terlebih dahulu di menu Tim &amp; Kelompok.' ?>
            </div>
        </div>
        <div class="fs-modal-footer">
            <button type="button" class="btn secondary" onclick="closeNewTaskModal()">Tutup</button>
        </div>
    </div>
</div>
<?php else: ?>
<!-- Modal Tambah Tugas -->
<div id="taskModal" class="fs-modal-overlay" hidden aria-modal="true" role="dialog" aria-labelledby="taskModalTitle">
    <div class="fs-modal fs-modal-lg">
        <div class="fs-modal-header">
            <div>
                <h2 class="fs-modal-title" id="taskModalTitle"><?= $teacher ? 'Tambah Tugas WBS' : 'Tambah Tugas WBS Kelompok' ?></h2>
                <p class="fs-modal-sub"><?= $teacher ? 'Tugas akan dibagikan ke kelompok atau seluruh kelas yang kamu kelola.' : 'Tugas dicatat dalam tabel tasks Supabase untuk pemantauan progres kolaborasi.' ?></p>
            </div>
            <button type="button" class="fs-modal-close" onclick="closeNewTaskModal()" aria-label="Tutup">✕</button>
        </div>
        <form method="post" id="newTaskForm">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="create">
            <div class="fs-modal-body">

                <!-- Judul Tugas -->
                <div class="fs-field">
                    <label class="fs-label">Judul Tugas WBS <span class="text-danger">*</span></label>
                    <input class="fs-input" name="title" maxlength="200" required placeholder="Contoh: Implementasi API dan Database Schema">
                </div>

                <!-- Deskripsi -->
                <div class="fs-field">
                    <label class="fs-label">Deskripsi / Instruksi Pengerjaan</label>
                    <textarea class="fs-input fs-textarea" name="description" rows="3" placeholder="Detail kriteria tugas, batasan teknis, atau dokumen rujukan..."></textarea>
                </div>

                <?php if ($teacher): ?>
                <!-- Lingkup Penerima & Kelompok/Kelas — baris 1 -->
                <div class="fs-field-row">
                    <div class="fs-field">
                        <label class="fs-label">Lingkup Penerima</label>
                        <select class="fs-input" name="scope" id="taskScopeSelect" onchange="toggleScopeInputs()">
                            <option value="single">Kelompok Tertentu</option>
                            <option value="all_in_class">Semua Kelompok di Kelas</option>
                        </select>
                    </div>
                    <div class="fs-field" id="singleGroupField">
                        <label class="fs-label">Pilih Kelompok Penerima</label>
                        <select class="fs-input" name="group_id">
                            <?php foreach ($groups as $g): ?>
                                <option value="<?= e($g['id']) ?>"><?= e($g['name']) ?> (<?= e($g['course'] ?? 'Kelas') ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="fs-field" id="classField" hidden>
                        <label class="fs-label">Pilih Kelas</label>
                        <select class="fs-input" name="class_id">
                            <?php foreach ($classes as $cl): ?>
                                <option value="<?= e($cl['id']) ?>"><?= e($cl['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <?php else: ?>
                <!-- Mahasiswa: pilih kelompok sendiri -->
                <div class="fs-field">
                    <label class="fs-label">Kelompok Kamu <span class="text-danger">*</span></label>
                    <select class="fs-input" name="group_id" required>
                        <?php foreach ($groups as $g): ?>
                            <option value="<?= e($g['id']) ?>"><?= e($g['name']) ?> (<?= e($g['course'] ?? 'Kelas') ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <!-- Bobot & Status — baris 2 -->
                <div class="fs-field-row">
                    <div class="fs-field">
                        <label class="fs-label">Bobot Kesulitan (1–5)</label>
                        <select class="fs-input" name="weight">
                            <option value="1">1 – Sangat Ringan</option>
                            <option value="2">2 – Ringan</option>
                            <option value="3" selected>3 – Sedang</option>
                            <option value="4">4 – Berat</option>
                            <option value="5">5 – Sangat Kompleks</option>
                        </select>
                    </div>
                    <div class="fs-field">
                        <label class="fs-label">Status Awal</label>
                        <select class="fs-input" name="status">
                            <option>Belum dikerjakan</option>
                            <option>Dikerjakan</option>
                            <option>Review</option>
                            <option>Selesai</option>
                        </select>
                    </div>
                </div>

                <!-- Jenis Tugas & Target Minggu — baris 3 -->
                <div class="fs-field-row">
                    <div class="fs-field">
                        <label class="fs-label">Jenis Tugas &amp; Resource</label>
                        <select class="fs-input" name="task_type" id="taskTypeSelect" onchange="toggleTaskResourceInputs()">
                            <option value="manual">Tugas Biasa (Manual / Non-Resource)</option>
                            <option value="gdocs">Dokumen Google Docs</option>
                            <option value="github">Kode di GitHub</option>
                        </select>
                    </div>
                    <div class="fs-field">
                        <label class="fs-label">Target Minggu Ke-</label>
                        <input class="fs-input" type="number" name="target_week" min="1" max="52" value="1" required>
                    </div>
                </div>

                <!-- Resource URL (conditional) -->
                <div class="fs-field" id="resourceFieldBox" hidden>
                    <label class="fs-label" id="resourceFieldLabel">Link Dokumen Google Docs <span class="text-danger">*</span></label>
                    <input class="fs-input" type="url" name="resource_url" id="resourceFieldInput" maxlength="2000" placeholder="https://docs.google.com/document/d/.../edit">
                    <small class="fs-hint" id="resourceFieldHelp">Dokumen Google Docs ini akan dilacak aktivitasnya melalui Google Drive Activity API untuk audit kontribusi tim.</small>
                </div>

            </div><!-- /.fs-modal-body -->
            <div class="fs-modal-footer">
                <button type="button" class="btn secondary" onclick="closeNewTaskModal()">Batal</button>
                <button type="submit" class="btn primary">＋ Simpan Tugas WBS</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
/* ── Task Modal ── */
function openNewTaskModal() {
    var modal = document.getElementById('taskModal');
    if (!modal) return;
    modal.hidden = false;
    document.body.style.overflow = 'hidden';
    var firstInput = modal.querySelector('input[name="title"]');
    if (firstInput) setTimeout(function(){ firstInput.focus(); }, 80);
}
function closeNewTaskModal() {
    var modal = document.getElementById('taskModal');
    if (!modal) return;
    modal.hidden = true;
    document.body.style.overflow = '';
}
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeNewTaskModal();
});
document.getElementById('taskModal')?.addEventListener('click', function(e) {
    if (e.target === this) closeNewTaskModal();
});

/* ── Scope toggle (dosen) ── */
function toggleScopeInputs() {
    var scope = document.getElementById('taskScopeSelect')?.value;
    var singleField = document.getElementById('singleGroupField');
    var classField  = document.getElementById('classField');
    if (!singleField || !classField) return;
    if (scope === 'all_in_class') {
        singleField.hidden = true;
        classField.hidden  = false;
    } else {
        singleField.hidden = false;
        classField.hidden  = true;
    }
}

/* ── Task type toggle ── */
function toggleTaskResourceInputs() {
    var type   = document.getElementById('taskTypeSelect')?.value;
    var box    = document.getElementById('resourceFieldBox');
    var label  = document.getElementById('resourceFieldLabel');
    var input  = document.getElementById('resourceFieldInput');
    var help   = document.getElementById('resourceFieldHelp');
    if (!box || !label || !input || !help) return;
    if (type === 'gdocs') {
        box.hidden = false;
        label.innerHTML = 'Link Dokumen Google Docs <span class="text-danger">*</span>';
        input.placeholder = 'https://docs.google.com/document/d/.../edit';
        help.textContent = 'Dokumen Google Docs ini akan dilacak aktivitasnya melalui Google Drive Activity API untuk audit kontribusi tim.';
        input.required = true;
    } else if (type === 'github') {
        box.hidden = false;
        label.innerHTML = 'Link Repository GitHub <span class="text-danger">*</span>';
        input.placeholder = 'https://github.com/owner/repository';
        help.textContent  = 'Masukkan tautan repository GitHub kelompok (contoh: https://github.com/owner/repository).';
        input.required = true;
    } else {
        box.hidden = true;
        input.required = false;
        input.value = '';
    }
}

/* ── Task card dropdown ── */
function toggleTaskMenu(taskId, event) {
    if (event) event.stopPropagation();
    var menu = document.getElementById('taskMenu-' + taskId);
    if (!menu) return;
    var isHidden = menu.hidden;
    document.querySelectorAll('.task-dropdown-menu').forEach(function(m){ m.hidden = true; });
    menu.hidden = !isHidden;
}
document.addEventListener('click', function(e) {
    if (!e.target.closest('.task-dropdown-wrap')) {
        document.querySelectorAll('.task-dropdown-menu').forEach(function(m){ m.hidden = true; });
    }
});

<?php if ($error): ?>
/* Re-open modal on server validation error */
openNewTaskModal();
<?php endif; ?>
</script>

<?php layout_end(); ?>