<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$user = require_login();
$teacher = is_teacher($user);
$groups = visible_groups($user);
$allTasks = all_tasks();
$groupIds = array_map('strtolower', array_column($groups, 'id'));
$tasks = $teacher
    ? array_values(array_filter($allTasks, fn($t) => in_array(strtolower((string) ($t['group_id'] ?? '')), $groupIds, true)))
    : user_tasks((string) $user['id']);

$logs = all_telemetry();
$mine = $teacher
    ? array_values(array_filter($logs, fn($l) => in_array(strtolower((string) ($l['group_id'] ?? '')), $groupIds, true)))
    : all_telemetry((string) $user['id']);

$verified = count(array_filter($mine, fn($l) => ($l['status'] ?? '') === 'Terverifikasi' || ($l['validation_status'] ?? '') === 'valid'));
$weiOverview = $teacher ? null : fs_member_wei_overview((string) $user['id']);
$hasSufficientWei = $weiOverview && $weiOverview['has_sufficient_data'];
$score = $teacher ? 0.0 : ($hasSufficientWei ? (float) $weiOverview['overall_wei'] : 0.0);
$done = count(array_filter($tasks, fn($t) => ($t['status'] ?? '') === 'Selesai'));
$completion = count($tasks) ? round($done / count($tasks) * 100) : 0;
$activity = read_data('activity.json', []);
if (!$teacher) {
    $activity = array_values(array_filter($activity, fn($l) => ($l['user'] ?? '') === ($user['name'] ?? '')));
}
$recent = array_slice($activity, 0, 5);

// ==============================================================
// PENGOLAHAN GRAFIK DINAMIS (TELEMETRI + ACTIVITY.JSON LOKAL)
// ==============================================================
$indoDays = ['Sun' => 'Min', 'Mon' => 'Sen', 'Tue' => 'Sel', 'Wed' => 'Rab', 'Thu' => 'Kam', 'Fri' => 'Jum', 'Sat' => 'Sab'];
$chartDays = [];

// Petakan 7 hari terakhir mundur dari hari ini(rabu, karena dihari selasa tadi saya mengerjakannya).
for ($i = 6; $i >= 0; $i--) {
    $ts = strtotime("-$i days");
    $dKey = date('Y-m-d', $ts);
    $engDay = date('D', $ts);
    $chartDays[$dKey] = [
        'label' => $indoDays[$engDay] ?? $engDay,
        'count' => 0
    ];
}

// 1. Hitung aktivitas dari database telemetri Supabase (jika ada)
foreach ($mine as $l) {
    $rawDate = $l['date'] ?? $l['created_at'] ?? $l['activity_timestamp'] ?? '';
    if (!empty($rawDate)) {
        $pDate = date('Y-m-d', strtotime((string)$rawDate));
        if (isset($chartDays[$pDate])) {
            $chartDays[$pDate]['count']++;
        }
    }
}

// 2. Hitung aktivitas dari activity.json lokal (Tugas dibuat, Kelompok dibuat, Login)
foreach ($activity as $act) {
    $rawTime = $act['time'] ?? $act['timestamp'] ?? $act['date'] ?? '';
    if (!empty($rawTime)) {
        $pDate = date('Y-m-d', strtotime((string)$rawTime));
        if (isset($chartDays[$pDate])) {
            $chartDays[$pDate]['count']++;
        }
    }
}

// 3. Hitung tugas WBS
foreach ($tasks as $t) {
    $rawDate = $t['created_at'] ?? $t['updated_at'] ?? '';
    if (!empty($rawDate)) {
        $pDate = date('Y-m-d', strtotime((string)$rawDate));
        if (isset($chartDays[$pDate])) {
            $chartDays[$pDate]['count']++;
        }
    }
}

// Konversi ke titik koordinat SVG (Lebar 640, Tinggi 180, Batas Y: 20 sampai 160)
$dayValues = array_values($chartDays);
$totalPoints = count($dayValues);
$maxCount = max(array_column($dayValues, 'count'));
$upperBound = max(4, $maxCount); // Skala sumbu Y dinamis

$points = [];
$stepX = 640 / ($totalPoints - 1);

foreach ($dayValues as $idx => $d) {
    $x = round($idx * $stepX, 1);
    // Skala Y: 0 aktivitas di y=155, nilai tertinggi di y=25
    $y = round(155 - (($d['count'] / $upperBound) * 130), 1);
    $points[] = ['x' => $x, 'y' => $y];
}

// Bangun kurva halus SVG
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

$myClasses = visible_classes($user);
$myClassCount = count($myClasses);

$quickActionHtml = '<div class="quick-action-wrap" data-quick-action-root>'
    . '<button class="btn primary quick-action-trigger" type="button" data-quick-action-toggle aria-haspopup="menu" aria-expanded="false" aria-controls="quickActionMenu" id="quickActionBtn">'
    . '<span>＋ Buat</span> <span class="action-caret" aria-hidden="true">▾</span>'
    . '</button>'
    . '<div class="quick-action-menu" id="quickActionMenu" data-quick-action-menu role="menu" aria-label="Menu Aksi Cepat" hidden>';

if ($teacher) {
    // 1. Buat Kelas (Dosen)
    $quickActionHtml .= '<a class="quick-action-item" role="menuitem" href="classes.php?modal=create">'
        . '<div class="quick-action-icon violet">'
        . '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/></svg>'
        . '</div>'
        . '<div class="quick-action-text">'
        . '<strong>Buat Kelas</strong>'
        . '<span>Buat kelas baru dan bagikan kode undangan</span>'
        . '</div>'
        . '</a>';

    // 2. Buat Kelompok (Dosen)
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

    // 3. Buat Tugas (Dosen)
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
    // 1. Gabung Kelas (Mahasiswa)
    $quickActionHtml .= '<a class="quick-action-item" role="menuitem" href="classes.php?modal=join">'
        . '<div class="quick-action-icon violet">'
        . '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/></svg>'
        . '</div>'
        . '<div class="quick-action-text">'
        . '<strong>Gabung Kelas</strong>'
        . '<span>Masukkan kode undangan dari dosen pengampu</span>'
        . '</div>'
        . '</a>';

    // 2. Buat Kelompok (Mahasiswa)
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

    // 3. Gabung Kelompok (Mahasiswa)
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

$headingAction = $quickActionHtml;

page_heading(
    $teacher ? 'DOSEN / OVERVIEW' : 'WORKSPACE OVERVIEW',
    'Halo, ' . explode(' ', $user['name'])[0] . ' 👋',
    $teacher ? 'Pantau kelas pengajaran, kelompok mahasiswa, dan perkembangan tugas kolaborasi.' : 'Pantau kontribusi, progres tugas, dan transparansi kolaborasi tim kamu.',
    $headingAction
);

echo '<section class="welcome-banner"><div><span class="banner-overline">FAIRSHARE INSIGHT</span><h2>Kolaborasi yang baik dimulai dari transparansi.</h2><p>Semua progres penting tim dalam satu workspace.</p></div><div class="banner-art"><div class="art-ring ring-a"></div><div class="art-ring ring-b"></div><div class="art-core art-logo"><img src="assets/fairshare-logo.svg" alt="Logo FairShare"></div><div class="art-tag">LIVE WORKSPACE</div></div></section>';

$weiDisplayNumber = $teacher ? count($groups) : ($hasSufficientWei ? number_format($score, 1) : '—');
$weiUnit = $teacher ? ' kelompok' : ($hasSufficientWei ? '%' : '');
$weiFootBadge = $teacher ? 'Mode pemantauan' : ($hasSufficientWei ? 'Tervalidasi Resmi' : 'Menunggu aktivitas valid');
$weiFootNote = $teacher ? 'Kelompok aktif' : ($hasSufficientWei ? 'Proporsi kontribusi tim' : 'Belum cukup data valid');

echo '<div class="stats-grid"><article class="stat-card"><div class="stat-top"><span class="stat-label">' . ($teacher ? 'KELOMPOK TERPANTAU' : 'WEI SCORE') . '</span><span class="stat-icon violet">◈</span></div><div class="stat-number">' . $weiDisplayNumber . '<small>' . $weiUnit . '</small></div><div class="stat-foot"><span class="pill purple">' . $weiFootBadge . '</span><span class="muted">' . $weiFootNote . '</span></div><div class="mini-progress"><span style="width:' . min(100, $score) . '%"></span></div></article><article class="stat-card"><div class="stat-top"><span class="stat-label">TIM & KELOMPOK</span><span class="stat-icon blue">♧</span></div><div class="stat-number">' . count($groups) . '<small> kelompok</small></div><div class="stat-foot"><span class="pill blue-pill">Workspace</span><span class="muted">yang kamu ikuti</span></div></article><article class="stat-card"><div class="stat-top"><span class="stat-label">TUGAS SELESAI</span><span class="stat-icon green">✓</span></div><div class="stat-number">' . $done . '<small> / ' . count($tasks) . '</small></div><div class="stat-foot"><span class="pill green-pill">' . $completion . '% selesai</span><span class="muted">progres WBS</span></div><div class="mini-progress green-progress"><span style="width:' . $completion . '%"></span></div></article><article class="stat-card"><div class="stat-top"><span class="stat-label">AKTIVITAS TERCATAT</span><span class="stat-icon orange">⌁</span></div><div class="stat-number">' . count($activity) . '<small> event</small></div><div class="stat-foot"><span class="pill orange-pill">' . count($activity) . ' tercatat</span><span class="muted">log aktivitas lokal</span></div></article></div>';

echo '<div class="content-grid dashboard-grid"><section class="panel chart-panel"><div class="panel-heading"><div><div class="panel-title">Tren kontribusi</div><div class="panel-sub">Aktivitas mingguan dari telemetri dan log tugas</div></div><a class="text-link" href="' . ($teacher ? 'classroom.php' : 'contributions.php') . '">Detail kontribusi ↗</a></div><div class="chart-legend"><span><i></i> ' . ($teacher ? 'Aktivitas kelompok' : 'Aktivitas kamu') . '</span><span class="legend-muted">7 hari terakhir</span></div><div class="chart-area"><div class="chart-y">' . $chartYLabels . '</div><svg viewBox="0 0 640 180" preserveAspectRatio="none" role="img" aria-label="Grafik tren kontribusi"><defs><linearGradient id="area" x1="0" x2="0" y1="0" y2="1"><stop offset="0%" stop-color="#7868ff" stop-opacity=".24"/><stop offset="100%" stop-color="#7868ff" stop-opacity="0"/></linearGradient></defs><path d="' . $svgArea . '" fill="url(#area)"/><path d="' . $svgPath . '" fill="none" stroke="#7564f7" stroke-width="3" stroke-linecap="round"/></svg></div><div class="chart-x">' . $chartXLabels . '</div><div class="chart-note">' . ($maxCount > 0 ? 'Data diperbarui otomatis dari log aktivitas workspace kamu.' : 'Belum ada aktivitas tercatat dalam 7 hari terakhir.') . '</div></section><section class="panel factor-panel"><div class="panel-heading"><div><div class="panel-title">Faktor WEI</div><div class="panel-sub">Komponen indeks kontribusi</div></div><span class="help-dot" title="WEI merupakan indikator pendukung, bukan nilai akhir mata kuliah">?</span></div><div class="factor-item"><div class="factor-head"><span>Volume aktivitas</span><b>' . min(100, count($activity) * 20) . '%</b></div><div class="factor-track"><span style="width:' . min(100, count($activity) * 20) . '%"></span></div></div><div class="factor-item"><div class="factor-head"><span>Konsistensi temporal</span><b>' . min(100, count(array_unique(array_map(fn($a) => substr((string)($a['time'] ?? ''), 0, 10), $activity))) * 25) . '%</b></div><div class="factor-track"><span style="width:' . min(100, count(array_unique(array_map(fn($a) => substr((string)($a['time'] ?? ''), 0, 10), $activity))) * 25) . '%;background:#55b7c5"></span></div></div><div class="factor-item"><div class="factor-head"><span>Tugas terselesaikan</span><b>' . $completion . '%</b></div><div class="factor-track"><span style="width:' . $completion . '%;background:#e8ac54"></span></div></div><div class="factor-callout"><span>✦</span><p>Indikator WEI mencerminkan proporsi kontribusi aktivitas kolaboratif yang berhasil dikumpulkan dan divalidasi FairShare terhadap tugas berbobot kelompok, bukan evaluasi kualitas akademik otomatis.</p></div></section></div>';

echo '<div class="content-grid lower-grid"><section class="panel"><div class="panel-heading"><div><div class="panel-title">Tugas yang perlu diperhatikan</div><div class="panel-sub">Ringkasan Work Breakdown Structure</div></div><a class="text-link" href="tasks.php">Lihat semua ↗</a></div>';
if (!$tasks) {
    echo '<div class="empty-state"><span class="empty-illustration">☷</span><b>Belum ada tugas</b><p>Buat tugas pertama untuk mulai memantau progres.</p><a class="btn primary" href="tasks.php#new-task">Buat tugas pertama</a></div>';
} else {
    echo '<div class="task-list">';
    foreach (array_slice($tasks, 0, 5) as $t) {
        $pct = ($t['status'] ?? '') === 'Selesai' ? 100 : (($t['status'] ?? '') === 'Dikerjakan' ? 55 : 10);
        echo '<div class="task-line"><span class="task-square">' . (($t['status'] ?? '') === 'Selesai' ? '✓' : '↗') . '</span><div class="task-info"><b>' . e($t['title']) . '</b><small>' . e($t['group_name'] ?? 'Kelompok') . ' · Tenggat ' . e($t['due_date'] ?? 'Belum ditentukan') . '</small></div><span class="task-state">' . e($t['status']) . '</span><div class="task-percent">' . $pct . '%</div></div>';
    }
    echo '</div>';
}
echo '</section><section class="panel"><div class="panel-heading"><div><div class="panel-title">Aktivitas terbaru</div><div class="panel-sub">Jejak perubahan di workspace</div></div><a class="text-link" href="audit.php">Buka audit ↗</a></div>';
if (!$recent) {
    echo '<div class="empty-state compact"><span class="empty-illustration">⌁</span><b>Belum ada aktivitas</b><p>Aktivitas sistem akan muncul di sini.</p></div>';
} else {
    echo '<div class="timeline">';
    foreach ($recent as $i => $log) {
        echo '<div class="timeline-row"><span class="timeline-dot ' . e($log['type'] ?? 'info') . '"></span><div><b>' . e($log['title']) . '</b><p>' . e($log['detail']) . '</p><small>' . e($log['time']) . ' · ' . e($log['user'] ?? 'Sistem') . '</small></div></div>';
    }
    echo '</div>';
}
echo '</section></div>';

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