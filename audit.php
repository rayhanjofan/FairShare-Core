<?php
require_once __DIR__.'/includes/bootstrap.php';
$user=require_login(); $teacher=is_teacher($user); $groups=visible_groups($user); $groupIds=array_column($groups,'id');
$logs=read_data('activity.json',[]); if(!$teacher) $logs=array_values(array_filter($logs,fn($l)=>($l['user']??'')===($user['name']??'')));
$telemetry=$teacher?all_telemetry(null,$groupIds):all_telemetry((string)$user['id']);
if(($_GET['export']??'')==='csv'){
    header('Content-Type:text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="fairshare-audit.csv"'); $o=fopen('php://output','w');
    fputcsv($o,['Jenis','Aktivitas','Detail','Pengguna','Kelompok','Sumber','Waktu','Status']);
    foreach($logs as $l) fputcsv($o,['Aktivitas aplikasi',$l['title']??'',$l['detail']??'',$l['user']??'','—','—',$l['time']??'',$l['type']??'']);
    foreach($telemetry as $l) fputcsv($o,['Telemetry',$l['title']??'',$l['detail']??'',$l['user_name']??'',$l['group_name']??'',$l['source']??'',$l['activity_timestamp']??'',$l['status']??'']);
    fclose($o); exit;
}
layout_start('Audit & Telemetry','audit'); page_heading('TRANSPARENCY / SYSTEM AUDIT','Audit & Telemetry','Tinjau log aktivitas antarmuka dan telemetri yang tersimpan di Supabase.',button('↓ Ekspor log','audit.php?export=csv','secondary'));
echo '<div class="stats-grid three-stats"><article class="stat-card"><div class="stat-label">LOG AKTIVITAS UI</div><div class="stat-number">'.count($logs).'</div><div class="stat-foot">Catatan antarmuka pada perangkat ini</div></article><article class="stat-card"><div class="stat-label">EVENT TELEMETRI</div><div class="stat-number">'.count($telemetry).'</div><div class="stat-foot">Data bersama dari telemetry_logs</div></article><article class="stat-card"><div class="stat-label">SUMBER API LANGSUNG</div><div class="stat-number">0<small> aktif</small></div><div class="stat-foot">GitHub/Google API belum dikonfigurasi</div></article></div>';
echo '<div class="content-grid audit-grid"><section class="panel"><div class="panel-heading"><div><div class="panel-title">Log aktivitas aplikasi</div><div class="panel-sub">Catatan UI lokal, bukan audit log terpusat</div></div><span class="status-badge waiting">Lokal</span></div><div class="audit-timeline">';
if(!$logs) echo '<div class="empty-state"><span class="empty-illustration">⌁</span><b>Belum ada log aktivitas</b><p>Catatan tindakan antarmuka disimpan lokal pada perangkat ini.</p></div>';
foreach(array_slice($logs,0,40) as $l) echo '<div class="audit-entry"><span class="audit-icon '.e($l['type']??'info').'">'.(($l['type']??'')==='success'?'✓':'↗').'</span><div class="audit-copy"><b>'.e($l['title']??'Aktivitas').'</b><p>'.e($l['detail']??'').'</p><small>'.e($l['user']??'Sistem').' · '.e($l['time']??'').'</small></div></div>';
echo '</div></section><section class="panel"><div class="panel-heading"><div><div class="panel-title">Telemetri dari Supabase</div><div class="panel-sub">Data pada tabel telemetry_logs yang terlihat oleh akunmu</div></div><span class="counter">'.count($telemetry).' event</span></div>';
if(!$telemetry) echo '<div class="empty-state"><b>Belum ada telemetri</b><p>Catat data uji pada halaman Kontribusi Saya atau tunggu modul integrasi resmi diaktifkan.</p></div>';
foreach(array_slice($telemetry,0,20) as $l) echo '<div class="audit-entry"><span class="audit-icon info">↗</span><div class="audit-copy"><b>'.e($l['title']).'</b><p>'.e($l['group_name']??'Kelompok').' · '.e(strtoupper($l['source'])).' · delta '.e($l['points']).'</p><small>'.e($l['user_name']??'Tidak diketahui').' · '.e($l['activity_timestamp']).' · '.e($l['status']).'</small></div></div>';
echo '<div class="privacy-box"><b>Privasi & transparansi</b><p>Catatan uji pada halaman kontribusi bukan hasil sinkronisasi API otomatis. Integrasi hanya diaktifkan setelah izin resmi dan pemetaan identitas diuji.</p></div></section></div>'; layout_end();
