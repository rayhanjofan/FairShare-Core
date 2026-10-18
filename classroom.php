<?php
require_once __DIR__ . '/includes/bootstrap.php';
$user = require_login();
if (!is_teacher($user)) {
    http_response_code(403);
    exit('Akses khusus dosen.');
}
$groups = all_groups();
$tasks = all_tasks();
$groupIds = array_column($groups, 'id');
$totalMembers = [];
foreach ($groups as $g)
    foreach (($g['member_ids'] ?? []) as $id)
        $totalMembers[strtolower((string) $id)] = true;
$taskCount = count(array_filter($tasks, fn($t) => in_array($t['group_id'] ?? '', $groupIds, true)));
$completed = count(array_filter($tasks, fn($t) => in_array($t['group_id'] ?? '', $groupIds, true) && ($t['status'] ?? '') === 'Selesai'));
layout_start('Monitor Kelas', 'classroom');
page_heading('DOSEN / MONITORING', 'Monitor Kelas', 'Ringkasan kelompok, anggota, dan progres proyek dari database bersama.', '<a class="btn secondary" href="teams.php">Semua kelompok ↗</a>');
echo '<div class="stats-grid"><article class="stat-card"><div class="stat-label">KELOMPOK TERDAFTAR</div><div class="stat-number">' . count($groups) . '</div><div class="stat-foot">Kelompok di Supabase</div></article><article class="stat-card"><div class="stat-label">ANGGOTA TERLIBAT</div><div class="stat-number">' . count($totalMembers) . '</div><div class="stat-foot">ID pengguna unik dalam kelompok</div></article><article class="stat-card"><div class="stat-label">TOTAL TUGAS</div><div class="stat-number">' . $taskCount . '</div><div class="stat-foot">Tugas dari seluruh kelompok</div></article><article class="stat-card"><div class="stat-label">PENYELESAIAN TUGAS</div><div class="stat-number">' . ($taskCount ? round($completed / $taskCount * 100) : 0) . '<small>%</small></div><div class="stat-foot">' . $completed . ' dari ' . $taskCount . ' tugas selesai</div></article></div>';
echo '<section class="panel"><div class="panel-heading"><div><div class="panel-title">Ikhtisar kelompok</div><div class="panel-sub">Data dibaca dari PostgreSQL/Supabase</div></div><span class="counter">' . count($groups) . ' kelompok</span></div>';
if (!$groups)
    echo '<div class="empty-state"><b>Belum ada kelompok</b><p>Kelompok akan muncul saat mahasiswa membuat workspace.</p></div>';
else {
    echo '<div class="table-wrap"><table class="data-table"><thead><tr><th>Kelompok</th><th>Mata kuliah / proyek</th><th>Anggota</th><th>Tugas</th><th>Selesai</th><th></th></tr></thead><tbody>';
    foreach ($groups as $g) {
        $gt = array_values(array_filter($tasks, fn($t) => ($t['group_id'] ?? '') === ($g['id'] ?? '')));
        $gd = count(array_filter($gt, fn($t) => ($t['status'] ?? '') === 'Selesai'));
        echo '<tr><td><b>' . e($g['name'] ?? 'Kelompok') . '</b><small class="table-secondary">Dibuat ' . e(format_date($g['created_at'] ?? '')) . '</small></td><td>' . e($g['course'] ?? 'PBL') . '</td><td>' . count($g['members'] ?? []) . '</td><td>' . count($gt) . '</td><td><span class="status-badge ' . ($gd === count($gt) && count($gt) > 0 ? 'good' : '') . '">' . $gd . '/' . count($gt) . '</span></td><td><a class="text-link" href="tasks.php?group=' . e($g['id']) . '">Detail →</a></td></tr>';
    }
    echo '</tbody></table></div>';
}
echo '</section><section class="panel classroom-note"><div class="panel-title">Catatan akses dan integritas</div><p class="panel-sub">Ringkasan kelompok dan tugas diambil dari Supabase. Integrasi API langsung ke GitHub/Google Docs belum aktif hanya karena database sudah terhubung; layanan API perlu dikonfigurasi dan diuji terpisah.</p></section>';
layout_end();
