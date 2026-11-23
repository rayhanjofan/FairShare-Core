<?php
declare(strict_types=1);

if (!defined('FS_ROOT')) {
    define('FS_ROOT', dirname(__DIR__, 2));
}

require_once FS_ROOT . '/includes/bootstrap.php';

$user = require_login();
$teacher = is_teacher($user);
$pdo = require_db();

$classes = visible_classes($user);
$classIds = array_column($classes, 'id');

$selectedClassId = trim((string) ($_GET['class_id'] ?? ''));
if ($selectedClassId !== '' && !in_array($selectedClassId, $classIds, true)) {
    $selectedClassId = '';
}

$teams = visible_groups($user);
if ($selectedClassId !== '') {
    $teams = array_values(array_filter($teams, fn($t) => ($t['parent_group_id'] ?? '') === $selectedClassId));
}

// Rekapitulasi kontribusi anggota menggunakan view & function resmi Supabase
$reportRows = [];
$totalAllTelemetry = 0;

foreach ($teams as $t) {
    $tid = (string) $t['id'];

    // 1. Progres tugas tim dari fn_task_progress
    $prog = fs_get_task_progress($tid, $pdo);
    $teamTaskDone = $prog ? "{$prog['completed_tasks']} / {$prog['total_tasks']} ({$prog['completion_percentage']}%)" : '0 / 0';

    // 2. Ringkasan kontribusi tim dari v_team_contribution_summary
    $contribRows = fs_get_team_contribution_summary($tid, $pdo);

    // 3. WEI resmi dari fn_group_wei_summary
    $weiRows = fs_get_group_wei_summary($tid, $pdo);
    $weiByUser = [];
    foreach ($weiRows as $w) {
        $weiByUser[strtolower((string) $w['user_id'])] = $w;
    }

    $teamTotalValid = (int) array_sum(array_column($contribRows, 'valid_events'));

    foreach ($contribRows as $c) {
        $uid = strtolower((string) $c['user_id']);
        $w = $weiByUser[$uid] ?? null;

        $validEvents = (int) ($c['valid_events'] ?? 0);
        $pendingEvents = (int) ($c['pending_events'] ?? 0);
        $rejectedEvents = (int) ($c['rejected_events'] ?? 0);
        $totalEvents = (int) ($c['total_events'] ?? 0);
        $totalAllTelemetry += $totalEvents;

        $rawDelta = (float) ($c['total_raw_delta'] ?? 0.0);
        $filteredDelta = (float) ($c['total_filtered_delta'] ?? 0.0);

        $weiVal = $w !== null ? (float) $w['wei_percentage'] : 0.0;
        $dataStatus = $w['data_status'] ?? 'Belum Cukup Data';
        $statusInfo = fs_determine_wei_status($validEvents, $teamTotalValid, $dataStatus);

        $reportRows[] = [
            'user_id'         => (string) $c['user_id'],
            'name'            => (string) $c['student_name'],
            'nim'             => (string) ($c['nim_nip'] ?: '—'),
            'email'           => '',
            'team_id'         => $tid,
            'team_name'       => (string) $c['team_name'],
            'class_name'      => (string) $c['class_name'],
            'role_in_team'    => ($c['role_in_group'] === 'ketua') ? 'Project Manager' : 'Anggota',
            'is_pm'           => ($c['role_in_group'] === 'ketua'),
            'github_events'   => (int) ($c['github_events'] ?? 0),
            'gdocs_events'    => (int) ($c['gdocs_events'] ?? 0),
            'total_events'    => $totalEvents,
            'valid_events'    => $validEvents,
            'pending_events'  => $pendingEvents,
            'rejected_events' => $rejectedEvents,
            'raw_delta'       => $rawDelta,
            'filtered_delta'  => $filteredDelta,
            'wei_val'         => $statusInfo['has_valid_data'] ? $weiVal : null,
            'wei_formatted'   => $statusInfo['has_valid_data'] ? number_format($weiVal, 1) . '%' : '—',
            'status_label'    => $statusInfo['label'],
            'status_class'    => $statusInfo['class'],
            'has_sufficient'  => $statusInfo['has_valid_data'],
            'team_task_done'  => $teamTaskDone,
            'last_activity_at'=> $c['last_activity_at'] ?? null,
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
        'Kelas',
        'Kelompok',
        'Peran',
        'Event Google Docs',
        'Event GitHub',
        'Total Event',
        'Event Valid',
        'Event Pending',
        'Event Ditolak',
        'Raw Delta',
        'Filtered Delta',
        'Indikator WEI',
        'Status Data',
        'Progres Tugas Tim',
        'Waktu Aktivitas Terakhir'
    ]);
    foreach ($reportRows as $r) {
        fputcsv($out, [
            $r['name'],
            $r['nim'],
            $r['class_name'],
            $r['team_name'],
            $r['role_in_team'],
            $r['gdocs_events'],
            $r['github_events'],
            $r['total_events'],
            $r['valid_events'],
            $r['pending_events'],
            $r['rejected_events'],
            number_format($r['raw_delta'], 2),
            number_format($r['filtered_delta'], 2),
            $r['wei_formatted'],
            $r['status_label'],
            $r['team_task_done'],
            !empty($r['last_activity_at']) ? date('Y-m-d H:i:s', strtotime((string)$r['last_activity_at'])) : '—',
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
    'Audit kontribusi individu dalam kelompok berbasis telemetri aktivitas dan progres tugas dari database resmi Supabase.',
    '<a class="btn secondary" href="' . e($exportUrl) . '">↓ Unduh Laporan CSV</a>'
);

if ($teacher) {
    echo monitoring_tabs('reports');
}
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
        <div class="stat-number"><?= $totalAllTelemetry ?></div>
        <div class="stat-foot">v_team_contribution_summary</div>
    </article>
    <article class="stat-card">
        <div class="stat-label">INTEGRITAS AUDIT</div>
        <div class="stat-number"><small>DATA ASLI</small></div>
        <div class="stat-foot">fn_group_wei_summary() Supabase</div>
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
                        <th>Event Valid / Total</th>
                        <th>Raw Delta</th>
                        <th>Filtered Delta</th>
                        <th>Indikator WEI</th>
                        <th>Status Data</th>
                        <th>Progres Tim</th>
                        <th>Aktivitas Terakhir</th>
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
                                <span style="color:#10b981; font-weight:700;">✓ <?= $r['valid_events'] ?></span> / <?= $r['total_events'] ?>
                                <div style="font-size:10px; color:#64748b; margin-top:2px;">Docs: <?= $r['gdocs_events'] ?> · Git: <?= $r['github_events'] ?></div>
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
                                <span class="status-badge <?= e($r['status_class']) ?>" style="font-size:10px;" title="<?= e($r['status_label']) ?>">
                                    <?= e($r['status_label']) ?>
                                </span>
                            </td>
                            <td><small><?= e($r['team_task_done']) ?></small></td>
                            <td>
                                <small class="muted">
                                    <?= !empty($r['last_activity_at']) ? date('d M Y H:i', strtotime((string)$r['last_activity_at'])) . ' WIB' : '—' ?>
                                </small>
                            </td>
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
