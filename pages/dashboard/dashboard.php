<?php
declare(strict_types=1);

if (!defined('FS_ROOT')) {
    define('FS_ROOT', dirname(__DIR__, 2));
}

require_once FS_ROOT . '/includes/bootstrap.php';

$user = require_login();
$teacher = is_teacher($user);
$userId = (string) $user['id'];
$pdo = require_db();

// AMBIL KELAS & KELOMPOK VISIBLE
$groups = visible_groups($user);
$myClasses = visible_classes($user);
$myClassCount = count($myClasses);
$allTasks = all_tasks();
$groupIds = array_map('strtolower', array_column($groups, 'id'));
$tasks = $teacher
    ? array_values(array_filter($allTasks, fn($t) => in_array(strtolower((string) ($t['group_id'] ?? '')), $groupIds, true)))
    : user_tasks($userId);

$logs = all_telemetry();
$mine = $teacher
    ? array_values(array_filter($logs, fn($l) => in_array(strtolower((string) ($l['group_id'] ?? '')), $groupIds, true)))
    : all_telemetry($userId);

$verifiedCount = count(array_filter($mine, fn($l) => ($l['status'] ?? '') === 'Terverifikasi' || ($l['validation_status'] ?? '') === 'valid'));
$activity = read_data('activity.json', []);
if (!$teacher) {
    $activity = array_values(array_filter($activity, fn($l) => ($l['user'] ?? '') === ($user['name'] ?? '')));
}
$recent = array_slice($activity, 0, 5);

// ==============================================================
// METRIK ANALITIK DARI DATABASE (VIEWS & FUNCTIONS)
// ==============================================================
if ($teacher) {
    // 1. Dosen: Ambil v_class_overview milik dosen login
    $overviewClasses = fs_get_class_overview($userId, null, $pdo);
    $totalStudents = (int) array_sum(array_column($overviewClasses, 'total_students'));
    $totalTeams = (int) array_sum(array_column($overviewClasses, 'total_teams'));
    $totalTasks = (int) array_sum(array_column($overviewClasses, 'total_tasks'));
    $completedTasks = (int) array_sum(array_column($overviewClasses, 'completed_tasks'));
    $uncompletedTasks = max(0, $totalTasks - $completedTasks);
    $classCompletion = $totalTasks > 0 ? round(($completedTasks / $totalTasks) * 100, 1) : 0.0;

    // Hitung status telemetry dari kelompok terpantau
    $validTelemetryCount = count(array_filter($mine, fn($l) => ($l['validation_status'] ?? '') === 'valid' || ($l['status'] ?? '') === 'Terverifikasi'));
    $pendingTelemetryCount = count(array_filter($mine, fn($l) => in_array(($l['validation_status'] ?? ''), ['pending', 'menunggu kalibrasi bobot'], true)));
    $rejectedTelemetryCount = count(array_filter($mine, fn($l) => ($l['validation_status'] ?? '') === 'rejected'));
} else {
    // 2. Mahasiswa: Ambil data tim pertama/utama & analitik diri sendiri
    $primaryGroup = $groups[0] ?? null;
    $primaryGroupId = $primaryGroup ? (string) $primaryGroup['id'] : '';

    // Progres tugas tim dari fn_task_progress
    $teamProgress = $primaryGroupId ? fs_get_task_progress($primaryGroupId, $pdo) : null;
    $teamTotalTasks = (int) ($teamProgress['total_tasks'] ?? count($tasks));
    $teamCompletedTasks = (int) ($teamProgress['completed_tasks'] ?? count(array_filter($tasks, fn($t) => ($t['status_db'] ?? '') === 'done')));
    $teamProgressPct = $teamProgress ? (float) $teamProgress['completion_percentage'] : ($teamTotalTasks > 0 ? round(($teamCompletedTasks / $teamTotalTasks) * 100, 1) : 0.0);

    // Ringkasan kontribusi pribadi dari v_team_contribution_summary
    $myContribution = $primaryGroupId ? fs_get_user_team_contribution($primaryGroupId, $userId, $pdo) : null;
    $myRawDelta = (float) ($myContribution['total_raw_delta'] ?? 0.0);
    $myFilteredDelta = (float) ($myContribution['total_filtered_delta'] ?? 0.0);
    $myValidEvents = (int) ($myContribution['valid_events'] ?? 0);
    $myPendingEvents = (int) ($myContribution['pending_events'] ?? 0);
    $myRejectedEvents = (int) ($myContribution['rejected_events'] ?? 0);
    $myLastActivityAt = $myContribution['last_activity_at'] ?? null;

    // WEI dari PostgreSQL function fn_group_wei_summary
    $groupWeiRows = $primaryGroupId ? fs_get_group_wei_summary($primaryGroupId, $pdo) : [];
    $myWeiRow = null;
    $teamHasValidEvents = false;
    foreach ($groupWeiRows as $gw) {
        if ((int) ($gw['valid_event_count'] ?? 0) > 0) {
            $teamHasValidEvents = true;
        }
        if (strtolower((string) $gw['user_id']) === strtolower($userId)) {
            $myWeiRow = $gw;
        }
    }

    $myWeiScore = $myWeiRow !== null ? (float) $myWeiRow['wei_percentage'] : 0.0;
    $myWeiDataStatus = $myWeiRow['data_status'] ?? 'Belum Cukup Data';
    $myWeiStatus = fs_determine_wei_status($myValidEvents, $teamHasValidEvents ? 1 : 0, $myWeiDataStatus);
}

// ==============================================================
// PENGOLAHAN GRAFIK DINAMIS (TELEMETRI + ACTIVITY.JSON LOKAL)
// ==============================================================
$indoDays = ['Sun' => 'Min', 'Mon' => 'Sen', 'Tue' => 'Sel', 'Wed' => 'Rab', 'Thu' => 'Kam', 'Fri' => 'Jum', 'Sat' => 'Sab'];
$chartDays = [];

for ($i = 6; $i >= 0; $i--) {
    $ts = strtotime("-$i days");
    $dKey = date('Y-m-d', $ts);
    $engDay = date('D', $ts);
    $chartDays[$dKey] = [
        'label' => $indoDays[$engDay] ?? $engDay,
        'count' => 0
    ];
}

foreach ($mine as $l) {
    $rawDate = $l['date'] ?? $l['created_at'] ?? $l['activity_timestamp'] ?? '';
    if (!empty($rawDate)) {
        $pDate = date('Y-m-d', strtotime((string)$rawDate));
        if (isset($chartDays[$pDate])) {
            $chartDays[$pDate]['count']++;
        }
    }
}

foreach ($activity as $act) {
    $rawTime = $act['time'] ?? $act['timestamp'] ?? $act['date'] ?? '';
    if (!empty($rawTime)) {
        $pDate = date('Y-m-d', strtotime((string)$rawTime));
        if (isset($chartDays[$pDate])) {
            $chartDays[$pDate]['count']++;
        }
    }
}

foreach ($tasks as $t) {
    $rawDate = $t['created_at'] ?? $t['updated_at'] ?? '';
    if (!empty($rawDate)) {
        $pDate = date('Y-m-d', strtotime((string)$rawDate));
        if (isset($chartDays[$pDate])) {
            $chartDays[$pDate]['count']++;
        }
    }
}

$dayValues = array_values($chartDays);
$totalPoints = count($dayValues);
$maxCount = count($dayValues) > 0 ? max(array_column($dayValues, 'count')) : 0;
$upperBound = max(4, $maxCount);

$points = [];
$stepX = 640 / max(1, ($totalPoints - 1));

foreach ($dayValues as $idx => $d) {
    $x = round($idx * $stepX, 1);
    $y = round(155 - (($d['count'] / $upperBound) * 130), 1);
    $points[] = ['x' => $x, 'y' => $y];
}

$svgPath = "M {$points[0]['x']} {$points[0]['y']}";
for ($i = 0; $i < count($points) - 1; $i++) {
    $p0 = $points[$i];
    $p1 = $points[$i + 1];
    $cx = ($p0['x'] + $p1['x']) / 2;
    $svgPath .= " C $cx {$p0['y']}, $cx {$p1['y']}, {$p1['x']} {$p1['y']}";
}
$svgArea = $svgPath . " L 640 180 L 0 180 Z";

$chartXLabels = '';
foreach ($dayValues as $d) {
    $chartXLabels .= '<span>' . e($d['label']) . '</span>';
}

$chartYLabels = '<span>' . $upperBound . '</span>'
    . '<span>' . round($upperBound * 0.75) . '</span>'
    . '<span>' . round($upperBound * 0.5) . '</span>'
    . '<span>' . round($upperBound * 0.25) . '</span>'
    . '<span>0</span>';

layout_start('Dashboard', 'dashboard');

$quickActionHtml = '<div class="quick-action-wrap" data-quick-action-root>'
    . '<button class="btn primary quick-action-trigger" type="button" data-quick-action-toggle aria-haspopup="menu" aria-expanded="false" aria-controls="quickActionMenu" id="quickActionBtn">'
    . '<span>＋ Buat</span> <span class="action-caret" aria-hidden="true">▾</span>'
    . '</button>'
    . '<div class="quick-action-menu" id="quickActionMenu" data-quick-action-menu role="menu" aria-label="Menu Aksi Cepat" hidden>';

if ($teacher) {
    $quickActionHtml .= '<a class="quick-action-item" role="menuitem" href="classes.php?modal=create">'
        . '<div class="quick-action-icon violet">'
        . '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/></svg>'
        . '</div>'
        . '<div class="quick-action-text">'
        . '<strong>Buat Kelas</strong>'
        . '<span>Buat kelas baru dan bagikan kode undangan</span>'
        . '</div>'
        . '</a>';

    if ($myClassCount === 0) {
        $quickActionHtml .= '<a class="quick-action-item" role="menuitem" href="classes.php?modal=create" title="Buat kelas terlebih dahulu">'
            . '<div class="quick-action-icon blue">'
            . '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>'
            . '</div>'
            . '<div class="quick-action-text">'
            . '<strong>Buat Kelompok</strong>'
            . '<span>Buat kelas terlebih dahulu untuk kelompok</span>'
            . '</div>'
            . '</a>';
    } elseif ($myClassCount === 1) {
        $classId = (string) $myClasses[0]['id'];
        $quickActionHtml .= '<a class="quick-action-item" role="menuitem" href="class_detail.php?id=' . urlencode($classId) . '&modal=create_team">'
            . '<div class="quick-action-icon blue">'
            . '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>'
            . '</div>'
            . '<div class="quick-action-text">'
            . '<strong>Buat Kelompok</strong>'
            . '<span>Bentuk kelompok di ' . e($myClasses[0]['name']) . '</span>'
            . '</div>'
            . '</a>';
    } else {
        $quickActionHtml .= '<button type="button" class="quick-action-item" role="menuitem" data-modal-open="selectClassForTeamModal">'
            . '<div class="quick-action-icon blue">'
            . '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>'
            . '</div>'
            . '<div class="quick-action-text">'
            . '<strong>Buat Kelompok</strong>'
            . '<span>Pilih kelas untuk membuat kelompok baru</span>'
            . '</div>'
            . '</button>';
    }

    $quickActionHtml .= '<a class="quick-action-item" role="menuitem" href="tasks.php#new-task">'
        . '<div class="quick-action-icon orange">'
        . '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1" ry="1"/><path d="M9 12h6"/><path d="M9 16h6"/></svg>'
        . '</div>'
        . '<div class="quick-action-text">'
        . '<strong>Buat Tugas</strong>'
        . '<span>Buat tugas kelompok dengan Smart Assignment</span>'
        . '</div>'
        . '</a>';
} else {
    $quickActionHtml .= '<a class="quick-action-item" role="menuitem" href="classes.php?modal=join">'
        . '<div class="quick-action-icon violet">'
        . '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/></svg>'
        . '</div>'
        . '<div class="quick-action-text">'
        . '<strong>Gabung Kelas</strong>'
        . '<span>Masukkan kode undangan dari dosen pengampu</span>'
        . '</div>'
        . '</a>';

    if ($myClassCount === 0) {
        $quickActionHtml .= '<a class="quick-action-item" role="menuitem" href="classes.php?modal=join" title="Gabung ke kelas terlebih dahulu">'
            . '<div class="quick-action-icon blue">'
            . '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>'
            . '</div>'
            . '<div class="quick-action-text">'
            . '<strong>Buat Kelompok</strong>'
            . '<span>Gabung ke kelas terlebih dahulu</span>'
            . '</div>'
            . '</a>';
    } elseif ($myClassCount === 1) {
        $classId = (string) $myClasses[0]['id'];
        $quickActionHtml .= '<a class="quick-action-item" role="menuitem" href="teams.php?class_id=' . urlencode($classId) . '#create-group">'
            . '<div class="quick-action-icon blue">'
            . '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>'
            . '</div>'
            . '<div class="quick-action-text">'
            . '<strong>Buat Kelompok</strong>'
            . '<span>Bentuk kelompok di ' . e($myClasses[0]['name']) . '</span>'
            . '</div>'
            . '</a>';
    } else {
        $quickActionHtml .= '<a class="quick-action-item" role="menuitem" href="teams.php#create-group">'
            . '<div class="quick-action-icon blue">'
            . '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>'
            . '</div>'
            . '<div class="quick-action-text">'
            . '<strong>Buat Kelompok</strong>'
            . '<span>Pilih kelas dan bentuk kelompok baru</span>'
            . '</div>'
            . '</a>';
    }

    $quickActionHtml .= '<a class="quick-action-item" role="menuitem" href="teams.php#create-group">'
        . '<div class="quick-action-icon green">'
        . '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg>'
        . '</div>'
        . '<div class="quick-action-text">'
        . '<strong>Gabung Kelompok</strong>'
        . '<span>Masuk ke kelompok dengan kode undangan</span>'
        . '</div>'
        . '</a>';
}

$quickActionHtml .= '</div></div>';

page_heading(
    $teacher ? 'DOSEN / OVERVIEW' : 'WORKSPACE OVERVIEW',
    'Halo, ' . explode(' ', $user['name'])[0] . ' 👋',
    $teacher ? 'Pantau kelas pengajaran, kelompok mahasiswa, dan perkembangan tugas kolaborasi.' : 'Pantau kontribusi, progres tugas, dan transparansi kolaborasi tim kamu.',
    $quickActionHtml
);

// BANNER DASHBOARD: LOGO DI TENGAH RING A & B, LIVE WORKSPACE STABIL DI BAWAH DENGAN JARAK AMAN
echo '<section class="welcome-banner">
    <div>
        <span class="banner-overline">FAIRSHARE INSIGHT</span>
        <h2>Kolaborasi yang baik dimulai dari transparansi.</h2>
        <p>Semua progres penting tim dalam satu workspace.</p>
    </div>
    <div class="banner-art" style="position: relative; display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100%;">
        <div class="art-ring ring-a" style="position: absolute; top: calc(50% - 10px); left: 50%; transform: translate(-50%, -50%) scale(1.15);"></div>
        <div class="art-ring ring-b" style="position: absolute; top: calc(50% - 10px); left: 50%; transform: translate(-50%, -50%) scale(1.15);"></div>
        <div class="art-core art-logo" style="position: relative; top: -6px; right: 0; margin-bottom: 24px;">
            <img src="assets/fairshare-logo.svg" alt="Logo FairShare">
        </div>
        <div class="art-tag" style="position: relative; bottom: 0; right: 0; margin-top: 14px;">LIVE WORKSPACE</div>
    </div>
</section>';
?>

<!-- KARTU STATISTIK UTAMA DASHBOARD -->
<?php if ($teacher): ?>
    <div class="stats-grid">
        <article class="stat-card">
            <div class="stat-top">
                <span class="stat-label">KELAS DIKELOLA</span>
                <span class="stat-icon violet">▤</span>
            </div>
            <div class="stat-number"><?= count($overviewClasses) ?><small> kelas</small></div>
            <div class="stat-foot">
                <span class="pill purple"><?= $totalTeams ?> kelompok aktif</span>
                <span class="muted">di database</span>
            </div>
        </article>

        <article class="stat-card">
            <div class="stat-top">
                <span class="stat-label">MAHASISWA AKTIF</span>
                <span class="stat-icon blue">♧</span>
            </div>
            <div class="stat-number"><?= $totalStudents ?><small> mahasiswa</small></div>
            <div class="stat-foot">
                <span class="pill blue-pill">v_class_overview</span>
                <span class="muted">terdaftar di kelasmu</span>
            </div>
        </article>

        <article class="stat-card">
            <div class="stat-top">
                <span class="stat-label">PENYELESAIAN TUGAS</span>
                <span class="stat-icon green">✓</span>
            </div>
            <div class="stat-number"><?= $completedTasks ?><small> / <?= $totalTasks ?></small></div>
            <div class="stat-foot">
                <span class="pill green-pill"><?= number_format($classCompletion, 1) ?>% selesai</span>
                <span class="muted"><?= $uncompletedTasks ?> belum selesai</span>
            </div>
            <div class="mini-progress green-progress">
                <span style="width:<?= min(100, $classCompletion) ?>%"></span>
            </div>
        </article>

        <article class="stat-card">
            <div class="stat-top">
                <span class="stat-label">AKTIVITAS TELEMETRI</span>
                <span class="stat-icon orange">⌁</span>
            </div>
            <div class="stat-number"><?= count($mine) ?><small> event</small></div>
            <div class="stat-foot">
                <span class="pill orange-pill"><?= $validTelemetryCount ?> valid</span>
                <span class="muted"><?= $pendingTelemetryCount ?> pending · <?= $rejectedTelemetryCount ?> ditolak</span>
            </div>
        </article>
    </div>
<?php else: ?>
    <div class="stats-grid">
        <article class="stat-card">
            <div class="stat-top">
                <span class="stat-label">WEI SCORE SAYA</span>
                <span class="stat-icon violet">◈</span>
            </div>
            <div class="stat-number">
                <?php if ($myWeiStatus['has_valid_data']): ?>
                    <?= number_format($myWeiScore, 1) ?><small>%</small>
                <?php else: ?>
                    —
                <?php endif; ?>
            </div>
            <div class="stat-foot">
                <span class="pill <?= e($myWeiStatus['class']) ?>">
                    <?= e($myWeiStatus['label']) ?>
                </span>
                <span class="muted"><?= $primaryGroup ? e($primaryGroup['name']) : 'Kelompok kamu' ?></span>
            </div>
            <div class="mini-progress">
                <span style="width:<?= min(100, $myWeiScore) ?>%"></span>
            </div>
        </article>

        <article class="stat-card">
            <div class="stat-top">
                <span class="stat-label">KELAS & KELOMPOK</span>
                <span class="stat-icon blue">♧</span>
            </div>
            <div class="stat-number"><?= count($groups) ?><small> kelompok</small></div>
            <div class="stat-foot">
                <span class="pill blue-pill"><?= count($myClasses) ?> kelas diikuti</span>
                <span class="muted">ruang kolaborasi</span>
            </div>
        </article>

        <article class="stat-card">
            <div class="stat-top">
                <span class="stat-label">PROGRES TUGAS TIM</span>
                <span class="stat-icon green">✓</span>
            </div>
            <div class="stat-number"><?= $teamCompletedTasks ?><small> / <?= $teamTotalTasks ?></small></div>
            <div class="stat-foot">
                <span class="pill green-pill"><?= number_format($teamProgressPct, 1) ?>% selesai</span>
                <span class="muted">fn_task_progress</span>
            </div>
            <div class="mini-progress green-progress">
                <span style="width:<?= min(100, $teamProgressPct) ?>%"></span>
            </div>
        </article>

        <article class="stat-card">
            <div class="stat-top">
                <span class="stat-label">FILTERED DELTA PRIBADI</span>
                <span class="stat-icon orange">⌁</span>
            </div>
            <div class="stat-number"><?= number_format($myFilteredDelta, 2) ?></div>
            <div class="stat-foot">
                <span class="pill orange-pill"><?= $myValidEvents ?> event valid</span>
                <span class="muted">Raw: <?= number_format($myRawDelta, 2) ?></span>
            </div>
        </article>
    </div>
<?php endif; ?>

<!-- GRAFIK TREN KONTRIBUSI & FAKTOR WEI -->
<div class="content-grid dashboard-grid">
    <section class="panel chart-panel">
        <div class="panel-heading">
            <div>
                <div class="panel-title">Tren kontribusi</div>
                <div class="panel-sub">Aktivitas mingguan dari telemetri dan log tugas</div>
            </div>
            <a class="text-link" href="<?= $teacher ? 'classroom.php' : 'contributions.php' ?>">Detail kontribusi ↗</a>
        </div>
        <div class="chart-legend">
            <span><i></i> <?= $teacher ? 'Aktivitas kelompok' : 'Aktivitas kamu' ?></span>
            <span class="legend-muted">7 hari terakhir</span>
        </div>
        <div class="chart-area">
            <div class="chart-y"><?= $chartYLabels ?></div>
            <svg viewBox="0 0 640 180" preserveAspectRatio="none" role="img" aria-label="Grafik tren kontribusi">
                <defs>
                    <linearGradient id="area" x1="0" x2="0" y1="0" y2="1">
                        <stop offset="0%" stop-color="#7868ff" stop-opacity=".24"/>
                        <stop offset="100%" stop-color="#7868ff" stop-opacity="0"/>
                    </linearGradient>
                </defs>
                <path d="<?= $svgArea ?>" fill="url(#area)"/>
                <path d="<?= $svgPath ?>" fill="none" stroke="#7564f7" stroke-width="3" stroke-linecap="round"/>
            </svg>
        </div>
        <div class="chart-x"><?= $chartXLabels ?></div>
        <div class="chart-note"><?= $maxCount > 0 ? 'Data diperbarui otomatis dari log aktivitas workspace kamu.' : 'Belum ada aktivitas tercatat dalam 7 hari terakhir.' ?></div>
    </section>

    <!-- FAKTOR WEI -->
    <section class="panel factor-panel">
        <div class="panel-heading">
            <div>
                <div class="panel-title">Faktor WEI</div>
                <div class="panel-sub">Komponen indeks kontribusi</div>
            </div>
            <span class="help-dot" title="WEI merupakan indikator pendukung, bukan nilai akhir mata kuliah">?</span>
        </div>
        <div class="factor-item">
            <div class="factor-head">
                <span>Volume aktivitas</span>
                <b style="color: #ffffff !important;"><?= min(100, count($activity) * 20) ?>%</b>
            </div>
            <div class="factor-track"><span style="width:<?= min(100, count($activity) * 20) ?>%"></span></div>
        </div>
        <div class="factor-item">
            <div class="factor-head">
                <span>Konsistensi temporal</span>
                <b style="color: #ffffff !important;"><?= min(100, count(array_unique(array_map(fn($a) => substr((string)($a['time'] ?? ''), 0, 10), $activity))) * 25) ?>%</b>
            </div>
            <div class="factor-track">
                <span style="width:<?= min(100, count(array_unique(array_map(fn($a) => substr((string)($a['time'] ?? ''), 0, 10), $activity))) * 25) ?>%;background:#55b7c5"></span>
            </div>
        </div>
        <div class="factor-item">
            <div class="factor-head">
                <span>Tugas terselesaikan</span>
                <b style="color: #ffffff !important;"><?= $teacher ? number_format($classCompletion, 0) : number_format($teamProgressPct, 0) ?>%</b>
            </div>
            <div class="factor-track">
                <span style="width:<?= $teacher ? min(100, $classCompletion) : min(100, $teamProgressPct) ?>%;background:#e8ac54"></span>
            </div>
        </div>
        <div class="factor-callout" style="background: rgba(120, 104, 255, 0.12) !important; border: 1px solid rgba(120, 104, 255, 0.3);">
            <span style="color: #a29bfe !important;">✦</span>
            <p style="color: #e2e8f0 !important;">Indikator WEI mencerminkan proporsi kontribusi aktivitas kolaboratif yang berhasil dikumpulkan dan divalidasi FairShare terhadap tugas berbobot kelompok, bukan evaluasi kualitas akademik otomatis.</p>
        </div>
    </section>
</div>

<!-- LOWER GRID: TUGAS YANG PERLU DIPERHATIKAN -->
<div class="content-grid lower-grid">
    <?php if ($teacher): ?>
        <section class="panel">
            <div class="panel-heading">
                <div>
                    <div class="panel-title">Kelompok & Progres Kelas</div>
                    <div class="panel-sub">Data dari <code>v_class_overview</code> dan <code>fn_task_progress</code></div>
                </div>
                <a class="text-link" href="classroom.php">Buka monitoring ↗</a>
            </div>

            <?php if (!$groups): ?>
                <div class="empty-state">
                    <span class="empty-illustration">♧</span>
                    <b>Belum ada kelompok</b>
                    <p>Kelompok mahasiswa yang terbentuk akan muncul di sini.</p>
                </div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="data-table" style="font-size:12px;">
                        <thead>
                            <tr>
                                <th>Kelompok</th>
                                <th>Anggota</th>
                                <th>Progres WBS</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (array_slice($groups, 0, 5) as $g): ?>
                                <?php
                                    $prog = fs_get_task_progress((string) $g['id'], $pdo);
                                    $pRate = (float) ($prog['completion_percentage'] ?? 0);
                                    $pDone = (int) ($prog['completed_tasks'] ?? 0);
                                    $pTot = (int) ($prog['total_tasks'] ?? 0);
                                ?>
                                <tr>
                                    <td>
                                        <b><?= e($g['name']) ?></b>
                                        <small class="table-secondary"><?= e($g['parent_class_name'] ?: ($g['course'] ?: 'Kelas')) ?></small>
                                    </td>
                                    <td><?= count($g['members'] ?? []) ?> orang</td>
                                    <td>
                                        <div style="display:flex; align-items:center; gap:6px;">
                                            <div class="mini-progress green-progress" style="width:50px;">
                                                <span style="width:<?= min(100, $pRate) ?>%"></span>
                                            </div>
                                            <small><?= $pDone ?>/<?= $pTot ?> (<?= number_format($pRate, 0) ?>%)</small>
                                        </div>
                                    </td>
                                    <td>
                                        <a class="btn secondary small" href="team_detail.php?id=<?= urlencode((string) $g['id']) ?>">
                                            Analitik ↗
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    <?php else: ?>
        <section class="panel">
            <div class="panel-heading">
                <div>
                    <div class="panel-title">Tugas yang perlu diperhatikan</div>
                    <div class="panel-sub">Ringkasan Work Breakdown Structure tim</div>
                </div>
                <a class="text-link" href="tasks.php">Lihat semua ↗</a>
            </div>

            <?php if (!$tasks): ?>
                <div class="empty-state">
                    <span class="empty-illustration">☷</span>
                    <b>Belum ada tugas</b>
                    <p>Buat tugas pertama untuk mulai memantau progres kolaborasi.</p>
                    <a class="btn primary" href="tasks.php#new-task">Buat tugas pertama</a>
                </div>
            <?php else: ?>
                <div class="task-list">
                    <?php foreach (array_slice($tasks, 0, 5) as $t): ?>
                        <?php
                            $pct = ($t['status'] ?? '') === 'Selesai' ? 100 : (($t['status'] ?? '') === 'Dikerjakan' ? 55 : 10);
                        ?>
                        <div class="task-line">
                            <span class="task-square"><?= ($t['status'] ?? '') === 'Selesai' ? '✓' : '↗' ?></span>
                            <div class="task-info">
                                <b style="color: #ffffff !important;"><?= e($t['title']) ?></b>
                                <small style="color: #94a3b8 !important;"><?= e($t['group_name'] ?? 'Kelompok') ?> · Tenggat <?= e($t['due_date'] ?? 'Belum ditentukan') ?></small>
                            </div>
                            <span class="task-state" style="background: rgba(255, 255, 255, 0.08) !important; color: #cbd5e1 !important; border: 1px solid rgba(255, 255, 255, 0.12); padding: 4px 10px; border-radius: 20px; font-size: 11px;"><?= e($t['status']) ?></span>
                            <div class="task-percent" style="color: #a29bfe !important; font-weight: bold;"><?= $pct ?>%</div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <section class="panel">
        <div class="panel-heading">
            <div>
                <div class="panel-title">Aktivitas terbaru</div>
                <div class="panel-sub">Jejak perubahan di workspace</div>
            </div>
            <a class="text-link" href="audit.php">Buka audit ↗</a>
        </div>

        <?php if (!$recent): ?>
            <div class="empty-state compact">
                <span class="empty-illustration">⌁</span>
                <b>Belum ada aktivitas</b>
                <p>Aktivitas sistem akan muncul di sini.</p>
            </div>
        <?php else: ?>
            <div class="timeline">
                <?php foreach ($recent as $log): ?>
                    <div class="timeline-row">
                        <span class="timeline-dot <?= e($log['type'] ?? 'info') ?>"></span>
                        <div>
                            <b><?= e($log['title']) ?></b>
                            <p><?= e($log['detail']) ?></p>
                            <small><?= e($log['time']) ?> · <?= e($log['user'] ?? 'Sistem') ?></small>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</div>

<?php
if ($teacher && $myClassCount > 1) {
    echo '<div class="modal-overlay" id="selectClassForTeamModal" hidden>
        <div class="modal-box">
            <div class="modal-head">
                <div>
                    <h3>Pilih Kelas untuk Kelompok Baru</h3>
                    <small>Pilih salah satu kelas yang kamu ampu untuk membuat kelompok di dalamnya.</small>
                </div>
                <button class="modal-close" type="button" data-modal-close="selectClassForTeamModal">✕</button>
            </div>
            <div class="modal-body" style="padding:16px 20px;">
                <p class="muted" style="margin-top:0;">Klik pada kelas tujuan pembentukan kelompok:</p>
                <div style="display:flex; flex-direction:column; gap:8px;">';
    foreach ($myClasses as $cl) {
        echo '<a href="class_detail.php?id=' . urlencode((string) $cl['id']) . '&modal=create_team" 
                   class="panel" 
                   style="display:flex; justify-content:space-between; align-items:center; padding:12px 16px; margin:0; text-decoration:none; color:inherit; border:1px solid #ebebf4; border-radius:10px; transition:all 0.2s;">
                <div>
                    <strong style="color:var(--color-primary, #6754ef); display:block;">' . e($cl['name']) . '</strong>
                    <small class="muted">' . e($cl['semester'] ?? 'Semester') . ' · ' . e($cl['academic_year'] ?? '') . ' · ' . (int) ($cl['team_count'] ?? 0) . ' kelompok saat ini</small>
                </div>
                <span class="btn secondary small">Pilih Kelas →</span>
            </a>';
    }
    echo '      </div>
            </div>
            <div class="modal-foot">
                <button class="btn secondary" type="button" data-modal-close="selectClassForTeamModal">Batal</button>
            </div>
        </div>
    </div>';
}

layout_end();
