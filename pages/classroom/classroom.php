<?php
declare(strict_types=1);

if (!defined('FS_ROOT')) {
    define('FS_ROOT', dirname(__DIR__, 2));
}

require_once FS_ROOT . '/includes/bootstrap.php';

$user = require_login();
if (!is_teacher($user)) {
    http_response_code(403);
    exit('Akses khusus dosen pengampu.');
}

$pdo = require_db();
$lecturerId = (string) $user['id'];

// Ambil overview seluruh kelas milik dosen ini dari view resmi v_class_overview
$overviewClasses = fs_get_class_overview($lecturerId);
$myClassIds = array_column($overviewClasses, 'class_id');

// Filter kelas dari parameter URL
$selectedClassId = trim((string) ($_GET['class_id'] ?? ''));
if ($selectedClassId !== '' && !in_array($selectedClassId, $myClassIds, true)) {
    $selectedClassId = '';
}

// Data overview yang aktif (spesifik kelas atau agregat seluruh kelas milik dosen)
if ($selectedClassId !== '') {
    $currentOverview = array_values(array_filter($overviewClasses, fn($c) => $c['class_id'] === $selectedClassId));
    $activeOverview = $currentOverview[0] ?? null;
} else {
    $activeOverview = null;
}

// Hitung metrik dari view v_class_overview
if ($activeOverview) {
    $totalStudents = (int) $activeOverview['total_students'];
    $totalTeamsCount = (int) $activeOverview['total_teams'];
    $totalTasksCount = (int) $activeOverview['total_tasks'];
    $doneTasksCount = (int) $activeOverview['completed_tasks'];
    $doingTasksCount = (int) $activeOverview['in_progress_tasks'];
    $reviewTasksCount = (int) $activeOverview['review_tasks'];
    $todoTasksCount = (int) $activeOverview['todo_tasks'];
    $completionRate = (float) $activeOverview['completion_percentage'];
} else {
    $totalStudents = (int) array_sum(array_column($overviewClasses, 'total_students'));
    $totalTeamsCount = (int) array_sum(array_column($overviewClasses, 'total_teams'));
    $totalTasksCount = (int) array_sum(array_column($overviewClasses, 'total_tasks'));
    $doneTasksCount = (int) array_sum(array_column($overviewClasses, 'completed_tasks'));
    $doingTasksCount = (int) array_sum(array_column($overviewClasses, 'in_progress_tasks'));
    $reviewTasksCount = (int) array_sum(array_column($overviewClasses, 'review_tasks'));
    $todoTasksCount = (int) array_sum(array_column($overviewClasses, 'todo_tasks'));
    $completionRate = $totalTasksCount > 0 ? round(($doneTasksCount / $totalTasksCount) * 100, 1) : 0.0;
}

// Ambil kelompok sesuai filter kelas
$allTeams = visible_groups($user);
if ($selectedClassId !== '') {
    $teams = array_values(array_filter($allTeams, fn($t) => ($t['parent_group_id'] ?? '') === $selectedClassId));
} else {
    $teams = $allTeams;
}

// Ambil analitik lengkap per kelompok menggunakan function dan views
$teamAnalyticsList = [];
$totalAllTelemetryEvents = 0;
$totalAllValidEvents = 0;

foreach ($teams as $t) {
    $tid = (string) $t['id'];
    $analytics = fs_get_team_full_analytics($tid, $pdo);
    if ($analytics) {
        $totalAllTelemetryEvents += $analytics['total_events'];
        $totalAllValidEvents += $analytics['total_valid_events'];
        $teamAnalyticsList[$tid] = $analytics;
    }
}

layout_start('Monitoring Kelas', 'classroom');

page_heading(
    'DOSEN / MONITORING & AUDIT',
    'Monitoring Kelas & Kelompok',
    'Pantau ringkasan kelas, progres pengerjaan tugas, aktivitas telemetri, dan kontribusi anggota dari database Supabase.',
    '<a class="btn primary" href="classes.php">Kelola Kelas Saya ↗</a>'
);

echo monitoring_tabs('classroom');
?>

<div class="filter-panel" style="margin-bottom: 24px; padding: 14px 20px; background: #fff; border-radius: 12px; border: 1px solid #ebebf4; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;">
    <div style="display: flex; align-items: center; gap: 12px;">
        <span style="font-weight: 700; color: #5a49db; font-size: 12px;">FILTER KELAS:</span>
        <form method="get" id="classFilterForm" style="margin: 0;">
            <select name="class_id" onchange="document.getElementById('classFilterForm').submit();" style="padding: 7px 14px; border-radius: 8px; border: 1px solid #d9d9ec; font-size: 13px; font-weight: 600;">
                <option value="">-- Seluruh Kelas Saya (<?= count($overviewClasses) ?> kelas) --</option>
                <?php foreach ($overviewClasses as $cl): ?>
                    <option value="<?= e($cl['class_id']) ?>" <?= $selectedClassId === $cl['class_id'] ? 'selected' : '' ?>>
                        <?= e($cl['class_name']) ?> (<?= e($cl['semester'] ?? 'Genap') ?> <?= e($cl['academic_year'] ?? '') ?>)
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

<!-- STATISTIK KELAS DARI v_class_overview -->
<div class="stats-grid four-stats" style="margin-bottom: 16px;">
    <article class="stat-card">
        <div class="stat-label">TOTAL MAHASISWA AKTIF</div>
        <div class="stat-number"><?= $totalStudents ?></div>
        <div class="stat-foot">Dalam kelas terpantau</div>
    </article>
    <article class="stat-card">
        <div class="stat-label">TOTAL KELOMPOK</div>
        <div class="stat-number"><?= $totalTeamsCount ?></div>
        <div class="stat-foot">Tim mahasiswa terdaftar</div>
    </article>
    <article class="stat-card">
        <div class="stat-label">TOTAL TUGAS WBS</div>
        <div class="stat-number"><?= $totalTasksCount ?></div>
        <div class="stat-foot"><?= $doneTasksCount ?> selesai · <?= $totalTasksCount - $doneTasksCount ?> belum selesai</div>
    </article>
    <article class="stat-card">
        <div class="stat-label">PENYELESAIAN TUGAS</div>
        <div class="stat-number"><?= number_format($completionRate, 1) ?><small>%</small></div>
        <div class="mini-progress green-progress"><span style="width:<?= min(100, $completionRate) ?>%"></span></div>
    </article>
</div>

<div class="stats-grid" style="grid-template-columns: repeat(3, 1fr); margin-top: -8px; margin-bottom: 24px;">
    <article class="stat-card" style="padding: 14px 20px;">
        <div class="stat-label">BELUM DIKERJAKAN</div>
        <div class="stat-number" style="font-size: 20px; color:#64748b;"><?= $todoTasksCount ?> <small>tugas</small></div>
    </article>
    <article class="stat-card" style="padding: 14px 20px;">
        <div class="stat-label">SEDANG DIKERJAKAN / REVIEW</div>
        <div class="stat-number" style="font-size: 20px; color: #5a49db;"><?= $doingTasksCount + $reviewTasksCount ?> <small>tugas</small></div>
    </article>
    <article class="stat-card" style="padding: 14px 20px;">
        <div class="stat-label">TOTAL EVENT TELEMETRI</div>
        <div class="stat-number" style="font-size: 20px; color: #1aa778;"><?= $totalAllTelemetryEvents ?> <small>event (<?= $totalAllValidEvents ?> valid)</small></div>
    </article>
</div>

<section class="panel">
    <div class="panel-heading">
        <div>
            <div class="panel-title">Ikhtisar Kinerja & Kontribusi Kelompok</div>
            <div class="panel-sub">Ringkasan Project Manager, progres tugas dari <code>fn_task_progress()</code>, dan WEI dari <code>fn_group_wei_summary()</code>.</div>
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
                        <th>Progres Tugas</th>
                        <th>Event Telemetri</th>
                        <th>Filtered Delta</th>
                        <th>Distribusi WEI</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($teams as $t): ?>
                        <?php
                            $tid = (string) $t['id'];
                            $an = $teamAnalyticsList[$tid] ?? null;
                            $prog = $an ? $an['task_progress'] : null;
                            $tDone = (int) ($prog['completed_tasks'] ?? 0);
                            $tTotal = (int) ($prog['total_tasks'] ?? 0);
                            $tRate = (float) ($prog['completion_percentage'] ?? 0);
                            $pm = $t['project_manager'] ?? null;
                            $members = $an ? $an['members'] : ($t['members'] ?? []);
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
                            <td><b><?= count($members) ?></b> mahasiswa</td>
                            <td>
                                <div style="display:flex; align-items:center; gap:8px;">
                                    <div class="mini-progress green-progress" style="width:65px;">
                                        <span style="width:<?= min(100, $tRate) ?>%"></span>
                                    </div>
                                    <small><b><?= $tDone ?>/<?= $tTotal ?></b> (<?= number_format($tRate, 1) ?>%)</small>
                                </div>
                            </td>
                            <td>
                                <?php if ($an): ?>
                                    <small style="display:block;">Valid: <b style="color:#10b981;"><?= (int) $an['total_valid_events'] ?></b> / <?= (int) $an['total_events'] ?></small>
                                    <small style="display:block; color:#94a3b8;"><?= (int) $an['total_pending_events'] ?> pending · <?= (int) $an['total_rejected_events'] ?> ditolak</small>
                                <?php else: ?>
                                    <small>0 event</small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <b><?= number_format((float) ($an['total_filtered_delta'] ?? 0), 2) ?></b>
                                <small class="table-secondary">Raw: <?= number_format((float) ($an['total_raw_delta'] ?? 0), 2) ?></small>
                            </td>
                            <td>
                                <?php if ($an && $an['has_sufficient_data']): ?>
                                    <span class="status-badge good" style="font-size:10px;">Tervalidasi Resmi</span>
                                <?php else: ?>
                                    <span class="status-badge waiting" style="font-size:10px;">Belum Cukup Data</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="display:flex; gap:6px;">
                                    <a class="btn primary small" href="team_detail.php?id=<?= urlencode($tid) ?>">
                                        Analitik →
                                    </a>
                                    <button
                                        class="btn secondary small"
                                        type="button"
                                        data-open-team-modal
                                        data-team-name="<?= e($t['name']) ?>"
                                        data-class-name="<?= e($t['parent_class_name'] ?: ($t['course'] ?: 'Kelas')) ?>"
                                        data-pm-name="<?= e($pm ? $pm['name'] : 'Belum ada PM') ?>"
                                        data-members='<?= json_encode($members) ?>'>
                                        Rincian
                                    </button>
                                </div>
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
    <div class="modal-box" style="max-width: 720px;">
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

            <h4 style="margin: 0 0 8px; font-size: 13px;">Daftar Anggota, Telemetri, & WEI Resmi Database</h4>
            <div class="table-wrap" style="margin-bottom: 20px;">
                <table class="data-table" style="font-size: 12px;">
                    <thead>
                        <tr>
                            <th>Mahasiswa</th>
                            <th>NIM</th>
                            <th>Peran</th>
                            <th>Valid Events</th>
                            <th>Filtered Delta</th>
                            <th>Indikator WEI</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody id="modalMembersTable">
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

            document.getElementById("modalTeamName").textContent = name;
            document.getElementById("modalTeamSub").textContent = "Kelas: " + className;
            document.getElementById("modalPmName").textContent = pmName;

            var memTbody = document.getElementById("modalMembersTable");
            memTbody.innerHTML = "";
            if (members.length === 0) {
                memTbody.innerHTML = '<tr><td colspan="7" class="muted">Belum ada anggota.</td></tr>';
            } else {
                members.forEach(function(m) {
                    var tr = document.createElement("tr");
                    var nameStr = m.student_name || m.name || '—';
                    var nimStr = m.nim_nip || '—';
                    var roleStr = (m.role_in_group === 'ketua') ? 'Project Manager' : 'Anggota';
                    var validEvt = m.valid_events !== undefined ? m.valid_events : (m.valid_events_count || 0);
                    var filDelta = m.total_filtered_delta !== undefined ? Number(m.total_filtered_delta).toFixed(2) : (m.filtered_delta_total !== undefined ? Number(m.filtered_delta_total).toFixed(2) : '0.00');
                    var weiStr = m.wei_formatted || (m.wei_percentage !== undefined ? Number(m.wei_percentage).toFixed(1) + '%' : '—');
                    var statusBadge = m.status_label || (validEvt > 0 ? 'Sudah memiliki aktivitas tervalidasi' : 'Belum memiliki aktivitas tervalidasi');
                    var statusClass = (validEvt > 0) ? 'good' : 'danger';

                    tr.innerHTML = '<td><b>' + nameStr + '</b></td>' +
                                   '<td>' + nimStr + '</td>' +
                                   '<td><span class="status-badge ' + (m.role_in_group === 'ketua' ? 'good' : '') + '">' + roleStr + '</span></td>' +
                                   '<td>' + validEvt + '</td>' +
                                   '<td><b>' + filDelta + '</b></td>' +
                                   '<td><b>' + weiStr + '</b></td>' +
                                   '<td><span class="status-badge ' + statusClass + '" style="font-size:10px;">' + statusBadge + '</span></td>';
                    memTbody.appendChild(tr);
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
