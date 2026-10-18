<?php
require_once __DIR__ . '/includes/bootstrap.php';
$user = require_login();
$teacher = is_teacher($user);
$groups = visible_groups($user);
$allTasks = all_tasks();
$groupIds = array_column($groups, 'id');
$tasks = $teacher ? array_values(array_filter($allTasks, fn($t) => in_array($t['group_id'] ?? '', $groupIds, true))) : user_tasks((string) $user['id']);
$logs = all_telemetry();
$mine = $teacher ? array_values(array_filter($logs, fn($l) => in_array($l['group_id'] ?? '', $groupIds, true))) : all_telemetry((string) $user['id']);
$verified = count(array_filter($mine, fn($l) => ($l['status'] ?? '') === 'Terverifikasi'));
$score = $teacher ? 0 : score_for_user((string) $user['id']);
$done = count(array_filter($tasks, fn($t) => ($t['status'] ?? '') === 'Selesai'));
$completion = count($tasks) ? round($done / count($tasks) * 100) : 0;
$activity = read_data('activity.json', []);
if (!$teacher)
    $activity = array_values(array_filter($activity, fn($l) => ($l['user'] ?? '') === ($user['name'] ?? '')));
$recent = array_slice($activity, 0, 5);
layout_start('Dashboard', 'dashboard');
page_heading($teacher ? 'DOSEN / OVERVIEW' : 'WORKSPACE OVERVIEW', 'Halo, ' . explode(' ', $user['name'])[0] . ' 👋', $teacher ? 'Pantau kelompok, progres tugas, dan aktivitas kolaborasi mahasiswa.' : 'Pantau kontribusi, progres tugas, dan transparansi kolaborasi tim kamu.', $teacher ? button('Monitor kelas', 'classroom.php', 'primary') : button('＋ Buat tugas', 'tasks.php?action=new', 'primary'));
echo '<section class="welcome-banner"><div><span class="banner-overline">FAIRSHARE INSIGHT</span><h2>Kolaborasi yang baik dimulai dari transparansi.</h2><p>Semua progres penting tim dalam satu workspace.</p></div><div class="banner-art"><div class="art-ring ring-a"></div><div class="art-ring ring-b"></div><div class="art-core">F<span>+</span></div><div class="art-tag">LIVE WORKSPACE</div></div></section>';
echo '<div class="stats-grid"><article class="stat-card"><div class="stat-top"><span class="stat-label">' . ($teacher ? 'KELOMPOK TERPANTAU' : 'WEI SCORE') . '</span><span class="stat-icon violet">◈</span></div><div class="stat-number">' . ($teacher ? count($groups) : number_format($score, 1)) . '<small>' . ($teacher ? ' kelompok' : '/100') . '</small></div><div class="stat-foot"><span class="pill purple">' . ($teacher ? 'Mode pemantauan' : 'Aktivitas tersimpan') . '</span><span class="muted">Indikator awal</span></div><div class="mini-progress"><span style="width:' . $score . '%"></span></div></article><article class="stat-card"><div class="stat-top"><span class="stat-label">TIM & KELOMPOK</span><span class="stat-icon blue">♧</span></div><div class="stat-number">' . count($groups) . '<small> kelompok</small></div><div class="stat-foot"><span class="pill blue-pill">Workspace</span><span class="muted">yang kamu ikuti</span></div></article><article class="stat-card"><div class="stat-top"><span class="stat-label">TUGAS SELESAI</span><span class="stat-icon green">✓</span></div><div class="stat-number">' . $done . '<small> / ' . count($tasks) . '</small></div><div class="stat-foot"><span class="pill green-pill">' . $completion . '% selesai</span><span class="muted">progres WBS</span></div><div class="mini-progress green-progress"><span style="width:' . $completion . '%"></span></div></article><article class="stat-card"><div class="stat-top"><span class="stat-label">AKTIVITAS TERCATAT</span><span class="stat-icon orange">⌁</span></div><div class="stat-number">' . count($mine) . '<small> event</small></div><div class="stat-foot"><span class="pill orange-pill">' . $verified . ' terverifikasi</span><span class="muted">telemetri dari Supabase</span></div></article></div>';
echo '<div class="content-grid dashboard-grid"><section class="panel chart-panel"><div class="panel-heading"><div><div class="panel-title">Tren kontribusi</div><div class="panel-sub">Aktivitas mingguan dari telemetri yang tercatat</div></div><a class="text-link" href="' . ($teacher ? 'classroom.php' : 'contributions.php') . '">Detail kontribusi ↗</a></div><div class="chart-legend"><span><i></i> ' . ($teacher ? 'Aktivitas kelompok' : 'Aktivitas kamu') . '</span><span class="legend-muted">7 hari terakhir</span></div><div class="chart-area"><div class="chart-y"><span>100</span><span>75</span><span>50</span><span>25</span><span>0</span></div><svg viewBox="0 0 640 180" preserveAspectRatio="none" role="img" aria-label="Grafik tren kontribusi"><defs><linearGradient id="area" x1="0" x2="0" y1="0" y2="1"><stop offset="0%" stop-color="#7868ff" stop-opacity=".24"/><stop offset="100%" stop-color="#7868ff" stop-opacity="0"/></linearGradient></defs><path d="M0 145 C42 130 48 118 90 125 S148 94 190 110 S245 68 285 85 S345 52 380 72 S435 38 475 57 S535 18 575 38 S610 24 640 16 L640 180 L0 180Z" fill="url(#area)"/><path d="M0 145 C42 130 48 118 90 125 S148 94 190 110 S245 68 285 85 S345 52 380 72 S435 38 475 57 S535 18 575 38 S610 24 640 16" fill="none" stroke="#7564f7" stroke-width="3" stroke-linecap="round"/></svg></div><div class="chart-x"><span>Sen</span><span>Sel</span><span>Rab</span><span>Kam</span><span>Jum</span><span>Sab</span><span>Min</span></div><div class="chart-note">Grafik ilustratif antarmuka; integrasi historis API belum dikonfigurasi.</div></section><section class="panel factor-panel"><div class="panel-heading"><div><div class="panel-title">Faktor WEI</div><div class="panel-sub">Komponen indeks kontribusi</div></div><span class="help-dot" title="WEI merupakan indikator pendukung, bukan nilai akhir mata kuliah">?</span></div><div class="factor-item"><div class="factor-head"><span>Volume aktivitas</span><b>' . min(100, count($mine) * 12) . '%</b></div><div class="factor-track"><span style="width:' . min(100, count($mine) * 12) . '%"></span></div></div><div class="factor-item"><div class="factor-head"><span>Konsistensi temporal</span><b>' . min(100, count(array_unique(array_column($mine, 'date'))) * 15) . '%</b></div><div class="factor-track"><span style="width:' . min(100, count(array_unique(array_column($mine, 'date'))) * 15) . '%;background:#55b7c5"></span></div></div><div class="factor-item"><div class="factor-head"><span>Tugas terselesaikan</span><b>' . $completion . '%</b></div><div class="factor-track"><span style="width:' . $completion . '%;background:#e8ac54"></span></div></div><div class="factor-callout"><span>✦</span><p>Indikator awal dihitung dari data telemetry tersimpan. Formula WEI final dan sinkronisasi API masih perlu divalidasi.</p></div></section></div>';
echo '<div class="content-grid lower-grid"><section class="panel"><div class="panel-heading"><div><div class="panel-title">Tugas yang perlu diperhatikan</div><div class="panel-sub">Ringkasan Work Breakdown Structure</div></div><a class="text-link" href="tasks.php">Lihat semua ↗</a></div>';
if (!$tasks)
    echo '<div class="empty-state"><span class="empty-illustration">☷</span><b>Belum ada tugas</b><p>Buat tugas pertama untuk mulai memantau progres.</p><a class="btn primary" href="tasks.php?action=new">Buat tugas pertama</a></div>';
else {
    echo '<div class="task-list">';
    foreach (array_slice($tasks, 0, 5) as $t) {
        $pct = ($t['status'] ?? '') === 'Selesai' ? 100 : (($t['status'] ?? '') === 'Dikerjakan' ? 55 : 10);
        echo '<div class="task-line"><span class="task-square">' . (($t['status'] ?? '') === 'Selesai' ? '✓' : '↗') . '</span><div class="task-info"><b>' . e($t['title']) . '</b><small>' . e($t['group_name'] ?? 'Kelompok') . ' · Tenggat ' . e($t['due_date'] ?? 'Belum ditentukan') . '</small></div><span class="task-state">' . e($t['status']) . '</span><div class="task-percent">' . $pct . '%</div></div>';
    }
    echo '</div>';
}
echo '</section><section class="panel"><div class="panel-heading"><div><div class="panel-title">Aktivitas terbaru</div><div class="panel-sub">Jejak perubahan di workspace</div></div><a class="text-link" href="audit.php">Buka audit ↗</a></div>';
if (!$recent)
    echo '<div class="empty-state compact"><span class="empty-illustration">⌁</span><b>Belum ada aktivitas</b><p>Aktivitas sistem akan muncul di sini.</p></div>';
else {
    echo '<div class="timeline">';
    foreach ($recent as $i => $log)
        echo '<div class="timeline-row"><span class="timeline-dot ' . e($log['type'] ?? 'info') . '"></span><div><b>' . e($log['title']) . '</b><p>' . e($log['detail']) . '</p><small>' . e($log['time']) . ' · ' . e($log['user'] ?? 'Sistem') . '</small></div></div>';
    echo '</div>';
}
echo '</section></div>';
layout_end();
