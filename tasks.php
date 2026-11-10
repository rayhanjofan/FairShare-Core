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
    '<a class="btn primary" href="#new-task">＋ Tambah Tugas</a>'
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
                            <span class="pill" style="font-size:9px; background:#e8f5e9; color:#1b5e20;">📄 Google Docs</span>
                        <?php elseif (($t['task_type'] ?? '') === 'github'): ?>
                            <span class="pill" style="font-size:9px; background:#f3e8ff; color:#6b21a8;">🐙 GitHub</span>
                        <?php endif; ?>
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
                    <div style="margin-top:8px; border-top:1px dashed #f1f2f6; padding-top:6px; text-align:right;">
                        <a href="task_detail.php?id=<?= urlencode((string) $t['id']) ?>" style="font-size:11px; color:#4f46e5; font-weight:600; text-decoration:none;">Detail & Audit Tugas →</a>
                    </div>
                </article>
            <?php endforeach; ?>
            <a class="kanban-add" href="#new-task">＋ Tambah tugas</a>
        </section>
    <?php endforeach; ?>
</div>

<section class="panel task-create-panel" id="new-task">
    <div class="panel-heading">
        <div>
            <div class="panel-title"><?= $teacher ? 'Tambah Tugas Kelas / Kelompok' : 'Tambah Tugas WBS Kelompok' ?></div>
            <div class="panel-sub">Tugas dicatat dalam tabel tasks Supabase untuk pemantauan progres kolaborasi.</div>
        </div>
    </div>

    <?php if (!$groups): ?>
        <div class="flash error">
            <?= $teacher
                ? 'Belum ada kelompok yang terbentuk pada kelasmu. Buat kelompok terlebih dahulu di menu Kelas Saya.'
                : 'Buat atau gabung ke kelompok terlebih dahulu di menu Kelompok Saya.'
            ?>
        </div>
    <?php else: ?>
        <form method="post" class="form-grid">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="create">

            <div class="field-wide">
                <label>Judul Tugas WBS <span class="text-danger">*</span></label>
                <input name="title" maxlength="200" required placeholder="Contoh: Implementasi API dan Database Schema">
            </div>

            <div class="field-wide">
                <label>Deskripsi / Instruksi Pengerjaan</label>
                <textarea 
                    name="description" 
                    rows="3" 
                    style="width: 100%; max-width: 100%; box-sizing: border-box; resize: vertical; min-height: 80px;" 
                    placeholder="Detail kriteria tugas, batasan teknis, atau dokumen rujukan..."></textarea>
            </div>

            <?php if ($teacher): ?>
                <div>
                    <label>Lingkup Penerima</label>
                    <select name="scope" id="taskScopeSelect" onchange="toggleScopeInputs()">
                        <option value="single">Kelompok Tertentu</option>
                        <option value="all_in_class">Semua Kelompok di Kelas</option>
                    </select>
                </div>

                <div id="singleGroupField">
                    <label>Pilih Kelompok Penerima</label>
                    <select name="group_id">
                        <?php foreach ($groups as $g): ?>
                            <option value="<?= e($g['id']) ?>"><?= e($g['name']) ?> (<?= e($g['course'] ?? 'Kelas') ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div id="classField" hidden>
                    <label>Pilih Kelas</label>
                    <select name="class_id">
                        <?php foreach ($classes as $cl): ?>
                            <option value="<?= e($cl['id']) ?>"><?= e($cl['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php else: ?>
                <div>
                    <label>Kelompok Kamu <span class="text-danger">*</span></label>
                    <select name="group_id" required>
                        <?php foreach ($groups as $g): ?>
                            <option value="<?= e($g['id']) ?>"><?= e($g['name']) ?> (<?= e($g['course'] ?? 'Kelas') ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

            <div>
                <label>Bobot Kesulitan (1–5)</label>
                <select name="weight">
                    <option value="1">1 - Sangat Ringan</option>
                    <option value="2">2 - Ringan</option>
                    <option value="3" selected>3 - Sedang</option>
                    <option value="4">4 - Berat</option>
                    <option value="5">5 - Sangat Kompleks</option>
                </select>
            </div>

            <div>
                <label>Status Awal</label>
                <select name="status">
                    <option>Belum dikerjakan</option>
                    <option>Dikerjakan</option>
                    <option>Review</option>
                    <option>Selesai</option>
                </select>
            </div>

            <div>
                <label>Jenis Tugas & Resource</label>
                <select name="task_type" id="taskTypeSelect" onchange="toggleTaskResourceInputs()">
                    <option value="manual">Tugas Biasa (Manual / Non-Resource)</option>
                    <option value="gdocs">Dokumen Google Docs</option>
                    <option value="github">Kode di GitHub</option>
                </select>
            </div>

            <div class="field-wide" id="resourceFieldBox" hidden>
                <label id="resourceFieldLabel">Link Dokumen Google Docs <span class="text-danger">*</span></label>
                <input type="url" name="resource_url" id="resourceFieldInput" maxlength="2000" placeholder="https://docs.google.com/document/d/.../edit">
                <small class="muted" id="resourceFieldHelp" style="display:block; margin-top:4px;">
                    Dokumen Google Docs ini akan dilacak aktivitasnya melalui Google Drive Activity API untuk audit kontribusi tim.
                </small>
            </div>

            <div>
                <label>Target Minggu Ke-</label>
                <input type="number" name="target_week" min="1" max="52" value="1" required>
            </div>

            <div class="field-actions">
                <button class="btn primary">Simpan Tugas WBS →</button>
            </div>
        </form>
    <?php endif; ?>
</section>

<script>
function toggleScopeInputs() {
    var scope = document.getElementById("taskScopeSelect")?.value;
    var singleField = document.getElementById("singleGroupField");
    var classField = document.getElementById("classField");
    if (scope === "all_in_class") {
        if (singleField) singleField.hidden = true;
        if (classField) classField.hidden = false;
    } else {
        if (singleField) singleField.hidden = false;
        if (classField) classField.hidden = true;
    }
}

function toggleTaskResourceInputs() {
    var type = document.getElementById("taskTypeSelect")?.value;
    var box = document.getElementById("resourceFieldBox");
    var label = document.getElementById("resourceFieldLabel");
    var input = document.getElementById("resourceFieldInput");
    var help = document.getElementById("resourceFieldHelp");
    if (!box || !label || !input || !help) return;
    if (type === "gdocs") {
        box.hidden = false;
        label.innerHTML = 'Link Dokumen Google Docs <span class="text-danger">*</span>';
        input.placeholder = "https://docs.google.com/document/d/.../edit";
        help.textContent = "Dokumen Google Docs ini akan dilacak aktivitasnya melalui Google Drive Activity API untuk audit kontribusi tim.";
        input.required = true;
    } else if (type === "github") {
        box.hidden = false;
        label.innerHTML = 'Link Repository GitHub <span class="text-danger">*</span>';
        input.placeholder = "https://github.com/owner/repository";
        help.textContent = "Masukkan tautan repository GitHub kelompok (contoh: https://github.com/owner/repository).";
        input.required = true;
    } else {
        box.hidden = true;
        input.required = false;
        input.value = "";
    }
}
</script>

<?php layout_end(); ?>