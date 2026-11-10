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

$verified = count(array_filter($mine, fn($l) => ($l['status'] ?? '') === 'Terverifikasi'));
$score = $teacher ? 0.0 : score_for_user((string) $user['id']);
$done = count(array_filter($tasks, fn($t) => ($t['status'] ?? '') === 'Selesai'));
$completion = count($tasks) ? round($done / count($tasks) * 100) : 0;
$activity = read_data('activity.json', []);
if (!$teacher) {
    $activity = array_values(array_filter($activity, fn($l) => ($l['user'] ?? '') === ($user['name'] ?? '')));
}
$recent = array_slice($activity, 0, 5);

// ==========================================
// PENGOLAHAN GRAFIK DINAMIS 7 HARI TERAKHIR
// ==========================================
$indoDays = ['Sun' => 'Min', 'Mon' => 'Sen', 'Tue' => 'Sel', 'Wed' => 'Rab', 'Thu' => 'Kam', 'Fri' => 'Jum', 'Sat' => 'Sab'];
$chartDays = [];

for ($i = 6; $i >= 0; $i--) {
    $timestamp = strtotime("-$i days");
    $dateKey = date('Y-m-d', $timestamp);
    $engDay = date('D', $timestamp);
    $chartDays[$dateKey] = [
        'label' => $indoDays[$engDay] ?? $engDay,
        'count' => 0
    ];
}

// Hitung kemunculan log per tanggal dari data telemetri
foreach ($mine as $logItem) {
    $rawDate = $logItem['date'] ?? $logItem['created_at'] ?? $logItem['activity_timestamp'] ?? '';
    if (!empty($rawDate)) {
        $parsedDate = date('Y-m-d', strtotime((string)$rawDate));
        if (isset($chartDays[$parsedDate])) {
            $chartDays[$parsedDate]['count']++;
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

foreach ($dayValues as $idx => $dayData) {
    $x = round($idx * $stepX, 1);
    // Skala Y: 0 aktivitas berada di y=160, nilai tertinggi mendekati y=20
    $y = round(160 - (($dayData['count'] / $upperBound) * 140), 1);
    $points[] = ['x' => $x, 'y' => $y];
}

// Bangun kurva halus (cubic bezier) SVG
$svgPath = "M {$points[0]['x']} {$points[0]['y']}";
for ($i = 0; $i < count($points) - 1; $i++) {
    $p0 = $points[$i];
    $p1 = $points[$i + 1];
    $cx = ($p0['x'] + $p1['x']) / 2;
    $svgPath .= " C $cx {$p0['y']}, $cx {$p1['y']}, {$p1['x']} {$p1['y']}";
}
$svgArea = $svgPath . " L 640 180 L 0 180 Z";

$chartXLabels = '';
foreach ($dayValues as $dayData) {
    $chartXLabels .= '<span>' . e($dayData['label']) . '</span>';
}

$chartYLabels = '<span>' . $upperBound . '</span>'
    . '<span>' . round($upperBound * 0.75) . '</span>'
    . '<span>' . round($upperBound * 0.5) . '</span>'
    . '<span>' . round($upperBound * 0.25) . '</span>'
    . '<span>0</span>';

layout_start('Dashboard', 'dashboard');

$headingAction = $teacher
    ? '<a class="btn primary" href="classes.php">Kelas Saya ↗</a> <a class="btn secondary" href="classroom.php">Monitor Kelas ↗</a>'
    : button('＋ Buat tugas', 'tasks.php#new-task', 'primary');

page_heading(
    $teacher ? 'DOSEN / OVERVIEW' : 'WORKSPACE OVERVIEW',
    'Halo, ' . explode(' ', $user['name'])[0] . ' 👋',
    $teacher ? 'Pantau kelas pengajaran, kelompok mahasiswa, dan perkembangan tugas kolaborasi.' : 'Pantau kontribusi, progres tugas, dan transparansi kolaborasi tim kamu.',
    $headingAction
);

echo '<section class="welcome-banner"><div><span class="banner-overline">FAIRSHARE INSIGHT</span><h2>Kolaborasi yang baik dimulai dari transparansi.</h2><p>Semua progres penting tim dalam satu workspace.</p></div><div class="banner-art"><div class="art-ring ring-a"></div><div class="art-ring ring-b"></div><div class="art-core art-logo"><img src="assets/fairshare-logo.svg" alt="Logo FairShare"></div><div class="art-tag">LIVE WORKSPACE</div></div></section>';
echo '<div class="stats-grid"><article class="stat-card"><div class="stat-top"><span class="stat-label">' . ($teacher ? 'KELOMPOK TERPANTAU' : 'WEI SCORE') . '</span><span class="stat-icon violet">◈</span></div><div class="stat-number">' . ($teacher ? count($groups) : number_format($score, 1)) . '<small>' . ($teacher ? ' kelompok' : '/100') . '</small></div><div class="stat-foot"><span class="pill purple">' . ($teacher ? 'Mode pemantauan' : 'Aktivitas tersimpan') . '</span><span class="muted">Indikator awal</span></div><div class="mini-progress"><span style="width:' . $score . '%"></span></div></article><article class="stat-card"><div class="stat-top"><span class="stat-label">TIM & KELOMPOK</span><span class="stat-icon blue">♧</span></div><div class="stat-number">' . count($groups) . '<small> kelompok</small></div><div class="stat-foot"><span class="pill blue-pill">Workspace</span><span class="muted">yang kamu ikuti</span></div></article><article class="stat-card"><div class="stat-top"><span class="stat-label">TUGAS SELESAI</span><span class="stat-icon green">✓</span></div><div class="stat-number">' . $done . '<small> / ' . count($tasks) . '</small></div><div class="stat-foot"><span class="pill green-pill">' . $completion . '% selesai</span><span class="muted">progres WBS</span></div><div class="mini-progress green-progress"><span style="width:' . $completion . '%"></span></div></article><article class="stat-card"><div class="stat-top"><span class="stat-label">AKTIVITAS TERCATAT</span><span class="stat-icon orange">⌁</span></div><div class="stat-number">' . count($mine) . '<small> event</small></div><div class="stat-foot"><span class="pill orange-pill">' . $verified . ' terverifikasi</span><span class="muted">telemetri dari Supabase</span></div></article></div>';

echo '<div class="content-grid dashboard-grid"><section class="panel chart-panel"><div class="panel-heading"><div><div class="panel-title">Tren kontribusi</div><div class="panel-sub">Aktivitas mingguan dari telemetri yang tercatat</div></div><a class="text-link" href="' . ($teacher ? 'classroom.php' : 'contributions.php') . '">Detail kontribusi ↗</a></div><div class="chart-legend"><span><i></i> ' . ($teacher ? 'Aktivitas kelompok' : 'Aktivitas kamu') . '</span><span class="legend-muted">7 hari terakhir</span></div><div class="chart-area"><div class="chart-y">' . $chartYLabels . '</div><svg viewBox="0 0 640 180" preserveAspectRatio="none" role="img" aria-label="Grafik tren kontribusi"><defs><linearGradient id="area" x1="0" x2="0" y1="0" y2="1"><stop offset="0%" stop-color="#7868ff" stop-opacity=".24"/><stop offset="100%" stop-color="#7868ff" stop-opacity="0"/></linearGradient></defs><path d="' . $svgArea . '" fill="url(#area)"/><path d="' . $svgPath . '" fill="none" stroke="#7564f7" stroke-width="3" stroke-linecap="round"/></svg></div><div class="chart-x">' . $chartXLabels . '</div><div class="chart-note">' . ($maxCount > 0 ? 'Data diperbarui otomatis dari log telemetri Supabase.' : 'Belum ada aktivitas tercatat dalam 7 hari terakhir.') . '</div></section><section class="panel factor-panel"><div class="panel-heading"><div><div class="panel-title">Faktor WEI</div><div class="panel-sub">Komponen indeks kontribusi</div></div><span class="help-dot" title="WEI merupakan indikator pendukung, bukan nilai akhir mata kuliah">?</span></div><div class="factor-item"><div class="factor-head"><span>Volume aktivitas</span><b>' . min(100, count($mine) * 12) . '%</b></div><div class="factor-track"><span style="width:' . min(100, count($mine) * 12) . '%"></span></div></div><div class="factor-item"><div class="factor-head"><span>Konsistensi temporal</span><b>' . min(100, count(array_unique(array_column($mine, 'date'))) * 15) . '%</b></div><div class="factor-track"><span style="width:' . min(100, count(array_unique(array_column($mine, 'date'))) * 15) . '%;background:#55b7c5"></span></div></div><div class="factor-item"><div class="factor-head"><span>Tugas terselesaikan</span><b>' . $completion . '%</b></div><div class="factor-track"><span style="width:' . $completion . '%;background:#e8ac54"></span></div></div><div class="factor-callout"><span>✦</span><p>Indikator awal dihitung dari data telemetry tersimpan. Formula WEI final dan sinkronisasi API masih perlu divalidasi.</p></div></section></div>';

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
layout_end();