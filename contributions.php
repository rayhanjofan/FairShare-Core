<?php
require_once __DIR__ . '/includes/bootstrap.php';
$user = require_login();
$groups = visible_groups($user);
$items = all_telemetry((string) $user['id']);
$q = trim((string) ($_GET['q'] ?? ''));
if ($q !== '')
    $items = array_values(array_filter($items, fn($l) => stripos(($l['title'] ?? '') . ' ' . ($l['source'] ?? ''), $q) !== false));
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type:text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="fairshare-kontribusi.csv"');
    $o = fopen('php://output', 'w');
    fputcsv($o, ['Aktivitas', 'Sumber', 'Tanggal', 'Poin Tersaring', 'Status', 'Kelompok']);
    foreach ($items as $i)
        fputcsv($o, [$i['title'], $i['source'], $i['date'], $i['points'], $i['status'], $i['group_name'] ?? '']);
    fclose($o);
    exit;
}
layout_start('Kontribusi Saya', 'contributions');
page_heading('FAIRSHARE / KONTRIBUSI', 'Kontribusi Saya', 'Lihat telemetri yang disimpan pada database bersama Supabase.', button('↓ Ekspor CSV', 'contributions.php?export=csv', 'secondary'));
echo '<div class="stats-grid three-stats"><article class="stat-card"><div class="stat-label">INDIKATOR AKTIVITAS</div><div class="stat-number">' . number_format(score_for_user((string) $user['id']), 1) . '<small>/100</small></div><div class="stat-foot">Skor awal, bukan WEI final</div></article><article class="stat-card"><div class="stat-label">TOTAL AKTIVITAS</div><div class="stat-number">' . count($items) . '<small> event</small></div><div class="stat-foot">Dari telemetry_logs Supabase</div></article><article class="stat-card"><div class="stat-label">SUMBER TERCATAT</div><div class="stat-number">' . count(array_unique(array_column($items, 'source'))) . '<small> sumber</small></div><div class="stat-foot">GitHub / Google Docs</div></article></div>';
echo '<section class="panel"><div class="panel-heading"><div><div class="panel-title">Riwayat kontribusi</div><div class="panel-sub">Data dibaca dari PostgreSQL/Supabase</div></div><form class="search-form" method="get"><input name="q" value="' . e($q) . '" placeholder="Cari aktivitas atau sumber..."><button class="btn secondary">Cari</button></form></div><div class="table-wrap"><table class="data-table"><thead><tr><th>Aktivitas</th><th>Kelompok</th><th>Sumber</th><th>Tanggal</th><th>Poin</th><th>Status</th></tr></thead><tbody>';
if (!$items)
    echo '<tr><td colspan="6"><div class="empty-state"><span class="empty-illustration">◈</span><b>Belum ada telemetri</b><p>Aktivitas GitHub/Google Docs akan muncul setelah sinkronisasi dibuat atau catatan uji disimpan.</p></div></td></tr>';
foreach (array_reverse($items) as $i)
    echo '<tr><td><div class="table-main"><span class="table-icon">◈</span><b>' . e($i['title']) . '</b></div></td><td>' . e($i['group_name'] ?? '—') . '</td><td><span class="source-label">' . e(strtoupper($i['source'])) . '</span></td><td>' . e(format_date($i['date'])) . '</td><td><b>' . e($i['points']) . '</b></td><td><span class="status-badge ' . (($i['status'] ?? '') === 'Terverifikasi' ? 'good' : 'waiting') . '">' . e($i['status']) . '</span></td></tr>';
echo '</tbody></table></div></section>';
echo '<section class="panel task-create-panel"><div class="panel-heading"><div><div class="panel-title">Catatan telemetri uji</div><div class="panel-sub">Gunakan hanya untuk menguji penyimpanan Supabase. Ini bukan sinkronisasi otomatis dari GitHub atau Google Docs.</div></div></div>';
if (!$groups)
    echo '<div class="flash error">Buat atau gabung kelompok sebelum mencatat aktivitas uji.</div>';
else {
    echo '<form method="post" action="actions/add_telemetry.php" class="form-grid"><input type="hidden" name="csrf" value="' . e(csrf_token()) . '"><div class="field-wide"><label>Deskripsi aktivitas uji</label><input name="title" maxlength="50" required placeholder="Contoh: Validasi form (uji)"></div><div><label>Kelompok</label><select name="group_id" required>';
    foreach ($groups as $g)
        echo '<option value="' . e($g['id']) . '">' . e($g['name']) . '</option>';
    echo '</select></div><div><label>Jenis sumber yang disimulasikan</label><select name="source"><option value="github">GitHub</option><option value="gdocs">Google Docs</option></select></div><div><label>Delta uji (0–100)</label><input type="number" name="points" min="0" max="100" step="0.01" value="1" required></div><div class="field-actions"><button class="btn primary">Simpan catatan uji →</button></div></form>';
}
echo '</section>';
layout_end();
