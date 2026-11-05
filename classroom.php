<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$user = require_login();
if (!is_teacher($user)) {
    http_response_code(403);
    exit('Akses khusus dosen pengampu.');
}

$pdo = require_db();
$myClasses = all_classes((string) $user['id']);
$myClassIds = array_column($myClasses, 'id');

// Filter kelas
$selectedClassId = trim((string) ($_GET['class_id'] ?? ''));
if ($selectedClassId !== '' && !in_array($selectedClassId, $myClassIds, true)) {
    $selectedClassId = '';
}

// Ambil kelompok sesuai filter kelas
$allTeams = visible_groups($user);
if ($selectedClassId !== '') {
    $teams = array_values(array_filter($allTeams, fn($t) => ($t['parent_group_id'] ?? '') === $selectedClassId));
} else {
    $teams = $allTeams;
}

$teamIds = array_column($teams, 'id');

// Ambil seluruh tugas kelompok yang terpilih
$allTasks = all_tasks();
$tasks = array_values(array_filter($allTasks, fn($t) => in_array($t['group_id'] ?? '', $teamIds, true)));

// Ambil telemetri untuk kelompok yang terpilih
$telemetry = all_telemetry(null, $teamIds);

// Hitung metrik agregat
$totalMembersCount = 0;
$uniqueStudentIds = [];
foreach ($teams as $t) {
    foreach (($t['member_ids'] ?? []) as $mid) {
        $uniqueStudentIds[strtolower((string) $mid)] = true;
    }
}
$totalStudents = count($uniqueStudentIds);
$totalTeamsCount = count($teams);
$totalTasksCount = count($tasks);
$doneTasksCount = count(array_filter($tasks, fn($t) => ($t['status_db'] ?? '') === 'done'));
$doingTasksCount = count(array_filter($tasks, fn($t) => ($t['status_db'] ?? '') === 'in_progress'));
$todoTasksCount = count(array_filter($tasks, fn($t) => ($t['status_db'] ?? '') === 'todo'));
$reviewTasksCount = count(array_filter($tasks, fn($t) => ($t['status_db'] ?? '') === 'review'));
$completionRate = $totalTasksCount > 0 ? round(($doneTasksCount / $totalTasksCount) * 100) : 0;

layout_start('Monitoring Kelas', 'classroom');

page_heading(
    'DOSEN / MONITORING & AUDIT',
    'Monitoring Kelas & Kelompok',
    'Pantau progres tugas WBS, aktivitas telemetri, dan kontribusi anggota dari kelas pengajaranmu.',
    '<a class="btn primary" href="classes.php">Kelola Kelas Saya ↗</a>'
);
?>

<div class="filter-panel" style="margin-bottom: 24px; padding: 14px 20px; background: #fff; border-radius: 12px; border: 1px solid #ebebf4; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;">
    <div style="display: flex; align-items: center; gap: 12px;">
        <span style="font-weight: 700; color: #5a49db; font-size: 12px;">FILTER KELAS:</span>
        <form method="get" id="classFilterForm" style="margin: 0;">
            <select name="class_id" onchange="document.getElementById('classFilterForm').submit();" style="padding: 7px 14px; border-radius: 8px; border: 1px solid #d9d9ec; font-size: 13px; font-weight: 600;">
                <option value="">-- Seluruh Kelas Saya (<?= count($myClasses) ?> kelas) --</option>
                <?php foreach ($myClasses as $cl): ?>
                    <option value="<?= e($cl['id']) ?>" <?= $selectedClassId === $cl['id'] ? 'selected' : '' ?>>
                        <?= e($cl['name']) ?> (<?= e($cl['semester'] ?? 'Genap') ?> <?= e($cl['academic_year'] ?? '') ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
    <div>
        <a class="btn secondary small" href="reports.php<?= $selectedClassId ? '?class_id=' . urlencode($selectedClassId) : '' ?>">
            ⌁ Buka Laporan Transparansi →
        </a>
    </div>
</div>

<div class="stats-grid">
    <article class="stat-card">
        <div class="stat-label">TOTAL MAHASISWA AKTIF</div>
        <div class="stat-number"><?= $totalStudents ?></div>
        <div class="stat-foot">Dalam kelompok terdaftar</div>
    </article>
    <article class="stat-card">
        <div class="stat-label">TOTAL KELOMPOK</div>
        <div class="stat-number"><?= $totalTeamsCount ?></div>
        <div class="stat-foot">Kelompok di kelas terpilih</div>
    </article>
    <article class="stat-card">
        <div class="stat-label">TOTAL TUGAS WBS</div>
        <div class="stat-number"><?= $totalTasksCount ?></div>
        <div class="stat-foot"><?= $doneTasksCount ?> selesai · <?= $doingTasksCount ?> berjalan</div>
    </article>
    <article class="stat-card">
        <div class="stat-label">PENYELESAIAN TUGAS</div>
        <div class="stat-number"><?= $completionRate ?><small>%</small></div>
        <div class="mini-progress green-progress"><span style="width:<?= $completionRate ?>%"></span></div>
    </article>
</div>

<div class="stats-grid" style="grid-template-columns: repeat(3, 1fr); margin-top: -12px;">
    <article class="stat-card" style="padding: 14px 20px;">
        <div class="stat-label">BELUM DIKERJAKAN</div>
        <div class="stat-number" style="font-size: 20px;"><?= $todoTasksCount ?> <small>tugas</small></div>
    </article>
    <article class="stat-card" style="padding: 14px 20px;">
        <div class="stat-label">SEDANG DIKERJAKAN / REVIEW</div>
        <div class="stat-number" style="font-size: 20px; color: #5a49db;"><?= $doingTasksCount + $reviewTasksCount ?> <small>tugas</small></div>
    </article>
    <article class="stat-card" style="padding: 14px 20px;">
        <div class="stat-label">TOTAL EVENT TELEMETRI</div>
        <div class="stat-number" style="font-size: 20px; color: #1aa778;"><?= count($telemetry) ?> <small>event tercatat</small></div>
    </article>
</div>

<section class="panel">
    <div class="panel-heading">
        <div>
            <div class="panel-title">Ikhtisar Kinerja Kelompok</div>
            <div class="panel-sub">Ringkasan Project Manager, progres WBS, dan telemetri aktivitas kolaboratif.</div>
        </div>
        <span class="counter"><?= count($teams) ?> kelompok</span>
    </div>

    <?php if (!$teams): ?>
        <div class="empty-state">
            <b>Belum ada kelompok pada kelas ini</b>
            <p>Bentuk kelompok terlebih dahulu di menu Kelas Saya.</p>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Kelompok & Kelas</th>
                        <th>Project Manager</th>
                        <th>Anggota</th>
                        <th>Tugas Selesai</th>
                        <th>Aktivitas GitHub / Docs</th>
                        <th>Progres</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($teams as $t): ?>
                        <?php
                            $tTasks = array_values(array_filter($tasks, fn($tk) => ($tk['group_id'] ?? '') === $t['id']));
                            $tDone = count(array_filter($tTasks, fn($tk) => ($tk['status_db'] ?? '') === 'done'));
                            $tRate = count($tTasks) > 0 ? round(($tDone / count($tTasks)) * 100) : 0;
                            $tTelemetry = array_values(array_filter($telemetry, fn($tl) => ($tl['group_id'] ?? '') === $t['id']));
                            $githubCount = count(array_filter($tTelemetry, fn($tl) => ($tl['source'] ?? '') === 'github'));
                            $gdocsCount = count(array_filter($tTelemetry, fn($tl) => ($tl['source'] ?? '') === 'gdocs'));
                            $pm = $t['project_manager'] ?? null;
                            $tMembers = $t['members'] ?? [];
                        ?>
                        <tr>
                            <td>
                                <b><?= e($t['name']) ?></b>
                                <small class="table-secondary"><?= e($t['parent_class_name'] ?: ($t['course'] ?: 'Kelas')) ?> · Kode: <?= e($t['invite_code']) ?></small>
                            </td>
                            <td>
                                <span class="pm-badge" style="display:inline-block; margin-bottom:3px;">★ PM</span>
                                <div><b><?= e($pm ? $pm['name'] : '—') ?></b></div>
                            </td>
                            <td><b><?= count($tMembers) ?></b> mahasiswa</td>
                            <td>
                                <span class="status-badge <?= $tDone === count($tTasks) && count($tTasks) > 0 ? 'good' : '' ?>">
                                    <?= $tDone ?> / <?= count($tTasks) ?>
                                </span>
                            </td>
                            <td>
                                <small style="display:block;">GitHub: <b><?= $githubCount ?></b> event</small>
                                <small style="display:block; color:#7e849e;">Google Docs: <b><?= $gdocsCount ?></b> event</small>
                            </td>
                            <td>
                                <div style="display:flex; align-items:center; gap:8px;">
                                    <div class="mini-progress green-progress" style="width:75px;">
                                        <span style="width:<?= $tRate ?>%"></span>
                                    </div>
                                    <small><b><?= $tRate ?>%</b></small>
                                </div>
                            </td>
                            <td>
                                <button
                                    class="btn secondary small"
                                    type="button"
                                    data-open-team-modal
                                    data-team-name="<?= e($t['name']) ?>"
                                    data-class-name="<?= e($t['parent_class_name'] ?: ($t['course'] ?: 'Kelas')) ?>"
                                    data-pm-name="<?= e($pm ? $pm['name'] : 'Belum ada PM') ?>"
                                    data-members='<?= json_encode($tMembers) ?>'
                                    data-tasks='<?= json_encode(array_map(fn($tk) => ['title' => $tk['title'], 'status' => $tk['status'], 'weight' => $tk['weight']], $tTasks)) ?>'>
                                    Rincian →
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<!-- MODAL RINCIAN KELOMPOK (DRILLDOWN MONITORING) -->
<div class="modal-overlay" id="teamDetailModal" hidden>
    <div class="modal-box" style="max-width: 680px;">
        <div class="modal-head">
            <div>
                <h3 id="modalTeamName">Rincian Kelompok</h3>
                <small id="modalTeamSub">Kelas</small>
            </div>
            <button class="modal-close" type="button" data-modal-close="teamDetailModal">✕</button>
        </div>
        <div class="modal-body">
            <div style="margin-bottom: 16px; padding: 10px 14px; background: #f6f5ff; border-radius: 8px;">
                <span style="font-size: 11px; font-weight: 700; color: #5a49db;">PROJECT MANAGER:</span>
                <span id="modalPmName" style="font-weight: 800; margin-left: 8px;"></span>
            </div>

            <h4 style="margin: 0 0 8px; font-size: 13px;">Daftar Anggota & Kontribusi</h4>
            <div class="table-wrap" style="margin-bottom: 20px;">
                <table class="data-table" style="font-size: 12px;">
                    <thead>
                        <tr>
                            <th>Mahasiswa</th>
                            <th>NIM</th>
                            <th>Peran</th>
                        </tr>
                    </thead>
                    <tbody id="modalMembersTable">
                    </tbody>
                </table>
            </div>

            <h4 style="margin: 0 0 8px; font-size: 13px;">Daftar Tugas WBS Kelompok</h4>
            <div class="table-wrap">
                <table class="data-table" style="font-size: 12px;">
                    <thead>
                        <tr>
                            <th>Nama Tugas</th>
                            <th>Bobot</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody id="modalTasksTable">
                    </tbody>
                </table>
            </div>
        </div>
        <div class="modal-foot">
            <button class="btn secondary" type="button" data-modal-close="teamDetailModal">Tutup</button>
        </div>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function() {
    document.querySelectorAll("[data-open-team-modal]").forEach(function(btn) {
        btn.addEventListener("click", function() {
            var name = btn.getAttribute("data-team-name");
            var className = btn.getAttribute("data-class-name");
            var pmName = btn.getAttribute("data-pm-name");
            var members = JSON.parse(btn.getAttribute("data-members") || "[]");
            var tasks = JSON.parse(btn.getAttribute("data-tasks") || "[]");

            document.getElementById("modalTeamName").textContent = name;
            document.getElementById("modalTeamSub").textContent = "Kelas: " + className;
            document.getElementById("modalPmName").textContent = pmName;

            var memTbody = document.getElementById("modalMembersTable");
            memTbody.innerHTML = "";
            if (members.length === 0) {
                memTbody.innerHTML = '<tr><td colspan="3" class="muted">Belum ada anggota.</td></tr>';
            } else {
                members.forEach(function(m) {
                    var tr = document.createElement("tr");
                    tr.innerHTML = '<td><b>' + (m.name || '—') + '</b></td>' +
                                   '<td>' + (m.nim_nip || '—') + '</td>' +
                                   '<td><span class="status-badge ' + (m.role_in_group === 'ketua' ? 'good' : '') + '">' +
                                   (m.role_in_group === 'ketua' ? 'Project Manager' : 'Anggota') + '</span></td>';
                    memTbody.appendChild(tr);
                });
            }

            var taskTbody = document.getElementById("modalTasksTable");
            taskTbody.innerHTML = "";
            if (tasks.length === 0) {
                taskTbody.innerHTML = '<tr><td colspan="3" class="muted">Belum ada tugas tercatat.</td></tr>';
            } else {
                tasks.forEach(function(tk) {
                    var tr = document.createElement("tr");
                    tr.innerHTML = '<td><b>' + (tk.title || '—') + '</b></td>' +
                                   '<td>' + (tk.weight || 1) + '×</td>' +
                                   '<td><span class="status-badge">' + (tk.status || 'todo') + '</span></td>';
                    taskTbody.appendChild(tr);
                });
            }

            document.getElementById("teamDetailModal").hidden = false;
        });
    });

    document.querySelectorAll("[data-modal-close]").forEach(function(btn) {
        btn.addEventListener("click", function() {
            var modalId = btn.getAttribute("data-modal-close");
            var modal = document.getElementById(modalId);
            if (modal) modal.hidden = true;
        });
    });

    document.querySelectorAll(".modal-overlay").forEach(function(overlay) {
        overlay.addEventListener("click", function(e) {
            if (e.target === overlay) overlay.hidden = true;
        });
    });
});
</script>

<?php layout_end(); ?>
