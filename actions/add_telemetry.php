<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$user = require_login();
if ($_SERVER['REQUEST_METHOD'] !== 'POST')
    redirect('../contributions.php');
verify_csrf();
$title = trim((string) ($_POST['title'] ?? ''));
$source = (string) ($_POST['source'] ?? '');
$points = (float) ($_POST['points'] ?? 0);
$groupId = (string) ($_POST['group_id'] ?? '');
if ($title === '' || mb_strlen($title) > 50 || !in_array($source, ['github', 'gdocs'], true) || $points < 0 || $points > 100 || !is_finite($points) || !valid_uuid($groupId)) {
    flash('Catatan telemetri tidak valid.', 'error');
    redirect('../contributions.php');
}
try {
    $group = group_by_id($groupId);
    $isMember = $group && (in_array(strtolower((string) $user['id']), array_map('strtolower', $group['member_ids'] ?? []), true) || strtolower((string) $group['created_by']) === strtolower((string) $user['id']));
    if (!$group || !$isMember) {
        flash('Kamu hanya dapat mencatat aktivitas uji pada kelompok yang kamu ikuti.', 'error');
        redirect('../contributions.php');
    }
    $metadata = json_encode(['event_type' => $title, 'title' => $title, 'entry_mode' => 'manual_test', 'validation_status' => 'Catatan manual (belum diverifikasi)', 'description' => 'Entri uji yang dimasukkan melalui UI; bukan hasil sinkronisasi API.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $pdo = require_db();
    $stmt = $pdo->prepare('INSERT INTO public.telemetry_logs (group_id,user_id,source,external_id,activity_timestamp,raw_delta,filtered_delta,metadata) VALUES (CAST(:gid AS uuid),CAST(:uid AS uuid),:source,NULL,CURRENT_TIMESTAMP,:raw_delta,:filtered_delta,CAST(:metadata AS jsonb))');
    $stmt->execute(['gid' => $groupId, 'uid' => $user['id'], 'source' => $source, 'raw_delta' => $points, 'filtered_delta' => $points, 'metadata' => $metadata]);
    log_activity('Catatan telemetri uji disimpan', $title, 'success');
    flash('Catatan uji tersimpan di telemetry_logs Supabase. Ini belum berarti integrasi API aktif.');
    redirect('../contributions.php');
} catch (Throwable $e) {
    error_log('FairShare telemetry insert: ' . $e->getMessage());
    flash('Gagal menyimpan telemetri. Periksa koneksi dan izin INSERT pada telemetry_logs.', 'error');
    redirect('../contributions.php');
}
