<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$user = require_login();
$teacher = is_teacher($user);
$pdo = require_db();

$classes = visible_classes($user);
$classIds = array_column($classes, 'id');

$selectedClassId = trim((string) ($_GET['class_id'] ?? ''));
if ($selectedClassId !== '' && !in_array($selectedClassId, $classIds, true)) {
    $selectedClassId = '';
}

// Jika belum memilih kelas dan ada kelas yang tersedia, pilih kelas pertama secara default jika ada filter
$teams = visible_groups($user);
if ($selectedClassId !== '') {
    $teams = array_values(array_filter($teams, fn($t) => ($t['parent_group_id'] ?? '') === $selectedClassId));
}

$teamIds = array_column($teams, 'id');
$allTasks = all_tasks();
$allTelemetry = all_telemetry(null, $teamIds);

// Buat rekap kontribusi per mahasiswa
$reportRows = [];
foreach ($teams as $t) {
    $tid = $t['id'];
    $teamTasks = array_values(array_filter($allTasks, fn($tk) => ($tk['group_id'] ?? '') === $tid));
    $teamDoneTasks = count(array_filter($teamTasks, fn($tk) => ($tk['status_db'] ?? '') === 'done'));

    foreach (($t['members'] ?? []) as $m) {
        $uid = $m['user_id'];
        $userTelemetry = array_values(array_filter($allTelemetry, fn($tl) => strtolower((string) ($tl['user_id'] ?? '')) === strtolower((string) $uid) && ($tl['group_id'] ?? '') === $tid));
        $githubEvents = count(array_filter($userTelemetry, fn($tl) => ($tl['source'] ?? '') === 'github'));
        $gdocsEvents = count(array_filter($userTelemetry, fn($tl) => ($tl['source'] ?? '') === 'gdocs'));
        $totalPoints = array_sum(array_column($userTelemetry, 'points'));
        $assignedTasks = count(array_filter($teamTasks, fn($tk) => strtolower((string) ($tk['assigned_user_id'] ?? '')) === strtolower((string) $uid)));

        $reportRows[] = [
            'user_id'         => $uid,
            'name'            => $m['name'],
            'nim'             => $m['nim_nip'] ?: '—',
            'email'           => $m['email'],
            'team_id'         => $tid,
            'team_name'       => $t['name'],
            'class_name'      => $t['parent_class_name'] ?: ($t['course'] ?: 'Kelas'),
            'role_in_team'    => $m['role_in_group'] === 'ketua' ? 'Project Manager' : 'Anggota',
            'is_pm'           => $m['role_in_group'] === 'ketua',
            'github_events'   => $githubEvents,
            'gdocs_events'    => $gdocsEvents,
            'total_events'    => count($userTelemetry),
            'total_points'    => round($totalPoints, 2),
            'wei_indicator'   => score_for_user($uid, $tid),
            'team_task_done'  => "$teamDoneTasks / " . count($teamTasks),
        ];
    }
}

// Ekspor CSV jika diminta
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="fairshare-laporan-audit-' . date('Ymd-His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Nama Mahasiswa', 'NIM', 'Email', 'Kelas', 'Kelompok', 'Peran', 'Event GitHub', 'Event Google Docs', 'Total Event', 'Poin Delta', 'Indikator WEI', 'Progres Tugas Tim']);
    foreach ($reportRows as $r) {
        fputcsv($out, [
            $r['name'],
            $r['nim'],
            $r['email'],
            $r['class_name'],
            $r['team_name'],
            $r['role_in_team'],
            $r['github_events'],
            $r['gdocs_events'],
            $r['total_events'],
            $r['total_points'],
            $r['wei_indicator'],
            $r['team_task_done'],
        ]);
    }
    fclose($out);
    exit;
}

layout_start('Laporan Audit', 'reports');

$exportUrl = 'reports.php?export=csv' . ($selectedClassId ? '&class_id=' . urlencode($selectedClassId) : '');
page_heading(
    $teacher ? 'DOSEN / AUDIT TRANSPARANSI' : 'WORKSPACE / AUDIT LAPORAN',
    'Laporan Transparansi & Kontribusi',
    'Audit kontribusi individu dalam kelompok berbasis telemetri aktivitas dan progres tugas.',
    '<a class="btn secondary" href="' . e($exportUrl) . '">↓ Unduh Laporan CSV</a>'
);
?>

<div class="filter-panel" style="margin-bottom: 24px; padding: 14px 20px; background: #fff; border-radius: 12px; border: 1px solid #ebebf4; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;">
    <div style="display: flex; align-items: center; gap: 12px;">
        <span style="font-weight: 700; color: #5a49db; font-size: 12px;">FILTER KELAS:</span>
        <form method="get" id="reportFilterForm" style="margin: 0;">
            <select name="class_id" onchange="document.getElementById('reportFilterForm').submit();" style="padding: 7px 14px; border-radius: 8px; border: 1px solid #d9d9ec; font-size: 13px; font-weight: 600;">
                <option value="">-- Seluruh Kelas (<?= count($classes) ?> kelas) --</option>
                <?php foreach ($classes as $cl): ?>
                    <option value="<?= e($cl['id']) ?>" <?= $selectedClassId === $cl['id'] ? 'selected' : '' ?>>
                        <?= e($cl['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
    <div>
        <span class="muted" style="font-size: 12px;">Total Mahasiswa Terpantau: <b><?= count($reportRows) ?></b></span>
    </div>
</div>

<div class="stats-grid three-stats">
    <article class="stat-card">
        <div class="stat-label">TOTAL MAHASISWA</div>
        <div class="stat-number"><?= count($reportRows) ?></div>
        <div class="stat-foot">Tergabung dalam kelompok</div>
    </article>
    <article class="stat-card">
        <div class="stat-label">TOTAL EVENT TELEMETRI</div>
        <div class="stat-number"><?= count($allTelemetry) ?></div>
        <div class="stat-foot">GitHub & Google Docs tersimpan</div>
    </article>
    <article class="stat-card">
        <div class="stat-label">INTEGRITAS AUDIT</div>
        <div class="stat-number"><small>DATA ASLI</small></div>
        <div class="stat-foot">Dibaca langsung dari PostgreSQL Supabase</div>
    </article>
</div>

<section class="panel">
    <div class="panel-heading">
        <div>
            <div class="panel-title">Tabel Rekapitulasi Kontribusi Mahasiswa</div>
            <div class="panel-sub">Laporan kontribusi berbasis telemetri dan penyelesaian tugas WBS kelompok.</div>
        </div>
        <span class="counter"><?= count($reportRows) ?> mahasiswa</span>
    </div>

    <?php if (!$reportRows): ?>
        <div class="empty-state">
            <b>Belum ada data kontribusi</b>
            <p>Data laporan akan terisi saat mahasiswa membentuk kelompok dan melakukan aktivitas tugas/telemetri.</p>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Mahasiswa & NIM</th>
                        <th>Kelas & Kelompok</th>
                        <th>Peran</th>
                        <th>Aktivitas GitHub</th>
                        <th>Aktivitas Docs</th>
                        <th>Total Poin Delta</th>
                        <th>Indikator WEI</th>
                        <th>Progres Tim</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($reportRows as $r): ?>
                        <tr>
                            <td>
                                <b><?= e($r['name']) ?></b>
                                <small class="table-secondary">NIM: <?= e($r['nim']) ?></small>
                            </td>
                            <td>
                                <b><?= e($r['team_name']) ?></b>
                                <small class="table-secondary"><?= e($r['class_name']) ?></small>
                            </td>
                            <td>
                                <span class="status-badge <?= $r['is_pm'] ? 'good' : '' ?>">
                                    <?= e($r['role_in_team']) ?>
                                </span>
                            </td>
                            <td><b><?= $r['github_events'] ?></b> event</td>
                            <td><b><?= $r['gdocs_events'] ?></b> event</td>
                            <td><b><?= $r['total_points'] ?></b> pts</td>
                            <td>
                                <div style="display:flex; align-items:center; gap:6px;">
                                    <div class="mini-progress" style="width:50px;">
                                        <span style="width:<?= min(100, $r['wei_indicator']) ?>%"></span>
                                    </div>
                                    <small><b><?= $r['wei_indicator'] ?></b>/100</small>
                                </div>
                            </td>
                            <td><small><?= e($r['team_task_done']) ?> tuntas</small></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php layout_end(); ?>
