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

// Buat rekap kontribusi per mahasiswa menggunakan engine WEI FairShare
$reportRows = [];
foreach ($teams as $t) {
    $tid = $t['id'];
    $teamTasks = array_values(array_filter($allTasks, fn($tk) => ($tk['group_id'] ?? '') === $tid));
    $teamDoneTasks = count(array_filter($teamTasks, fn($tk) => ($tk['status_db'] ?? '') === 'done'));

    // Hitung distribusi kontribusi terbobot (WEI) kelompok
    $groupWei = fs_calculate_group_wei((string) $tid, $pdo);

    foreach (($t['members'] ?? []) as $m) {
        $uid = $m['user_id'];
        $userTelemetry = array_values(array_filter($allTelemetry, fn($tl) => strtolower((string) ($tl['user_id'] ?? '')) === strtolower((string) $uid) && ($tl['group_id'] ?? '') === $tid));
        $githubEvents = count(array_filter($userTelemetry, fn($tl) => ($tl['source'] ?? '') === 'github'));
        $gdocsEvents = count(array_filter($userTelemetry, fn($tl) => ($tl['source'] ?? '') === 'gdocs'));
        
        $mWei = $groupWei['members'][$uid] ?? null;
        $rawDeltaTotal = $mWei ? (float) $mWei['raw_delta_total'] : array_sum(array_column($userTelemetry, 'raw_delta'));
        $filteredDeltaTotal = $mWei ? (float) $mWei['filtered_delta_total'] : array_sum(array_column($userTelemetry, 'filtered_delta'));
        
        $weiVal = $mWei && $mWei['wei_percent'] !== null ? round((float) $mWei['wei_percent'], 1) : null;
        $weiFormatted = $mWei ? $mWei['wei_formatted'] : 'Belum cukup data';
        $dataStatus = $groupWei['has_sufficient_data'] ? 'Cukup Data' : 'Belum Cukup Data';

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
            'raw_delta'       => round($rawDeltaTotal, 2),
            'filtered_delta'  => round($filteredDeltaTotal, 2),
            'total_points'    => round($filteredDeltaTotal, 2),
            'wei_val'         => $weiVal,
            'wei_indicator'   => $weiVal ?? 0.0,
            'wei_formatted'   => $weiFormatted,
            'data_status'     => $dataStatus,
            'has_sufficient'  => $groupWei['has_sufficient_data'],
            'team_task_done'  => "$teamDoneTasks / " . count($teamTasks),
        ];
    }
}

// Ekspor CSV jika diminta
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="fairshare-laporan-audit-' . date('Ymd-His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, [
        'Nama Mahasiswa',
        'NIM',
        'Email',
        'Kelas',
        'Kelompok',
        'Peran',
        'Event GitHub',
        'Event Google Docs',
        'Total Event',
        'Raw Delta',
        'Filtered Delta',
        'Indikator WEI',
        'Status Data',
        'Progres Tugas Tim'
    ]);
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
            $r['raw_delta'],
            $r['filtered_delta'],
            $r['wei_formatted'],
            $r['data_status'],
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
                        <th>Aktivitas Terdata</th>
                        <th>Raw Delta</th>
                        <th>Filtered Delta</th>
                        <th>Indikator WEI</th>
                        <th>Status Data</th>
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
                            <td>
                                <small>Docs: <b><?= $r['gdocs_events'] ?></b> | Git: <b><?= $r['github_events'] ?></b></small>
                                <div style="font-size:10px; color:#64748b; margin-top:2px;"><?= $r['total_events'] ?> total event</div>
                            </td>
                            <td><?= number_format($r['raw_delta'], 2) ?></td>
                            <td><b><?= number_format($r['filtered_delta'], 2) ?></b></td>
                            <td>
                                <?php if ($r['has_sufficient'] && $r['wei_val'] !== null): ?>
                                    <div style="display:flex; align-items:center; gap:6px;">
                                        <div class="mini-progress" style="width:50px;">
                                            <span style="width:<?= min(100, $r['wei_val']) ?>%"></span>
                                        </div>
                                        <small><b><?= number_format($r['wei_val'], 1) ?>%</b></small>
                                    </div>
                                <?php else: ?>
                                    <span class="muted" style="font-size:11px;">Belum cukup data</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="status-badge <?= $r['has_sufficient'] ? 'good' : 'waiting' ?>" style="font-size:10px;">
                                    <?= e($r['data_status']) ?>
                                </span>
                            </td>
                            <td><small><?= e($r['team_task_done']) ?> tuntas</small></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div style="margin-top:16px; padding:12px 16px; background:#f8fafc; border-radius:8px; border:1px solid #e2e8f0; font-size:11px; color:#475569; line-height:1.5;">
            💡 <b>Catatan Integritas WEI (Weighted Effort Index):</b> WEI adalah indikator proporsi kontribusi aktivitas kolaboratif yang berhasil dikumpulkan dan divalidasi FairShare terhadap tugas berbobot kelompok, bukan penilaian kualitas akademik otomatis.
        </div>
    <?php endif; ?>
</section>

<?php layout_end(); ?>
