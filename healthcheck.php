<?php
require_once __DIR__.'/includes/bootstrap.php';
$status='not_configured'; $message='data/.env belum dibuat atau belum lengkap.'; $dbName='—';
try {
    $pdo=fairshare_pdo();
    if($pdo){ $row=$pdo->query('SELECT current_database() AS db, 1 AS ok')->fetch(); $status='connected'; $message='PHP berhasil membuka koneksi PostgreSQL dan menjalankan query uji.'; $dbName=(string)$row['db']; }
} catch(Throwable $e) { error_log('FairShare healthcheck: '.$e->getMessage()); $status='error'; $message='Koneksi gagal. Periksa data/.env, username pooler, password database, jaringan, dan ekstensi pdo_pgsql.'; }
?><!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>FairShare Healthcheck</title><link rel="stylesheet" href="assets/style.css"></head><body class="setup-page"><section class="setup-card"><div class="auth-emblem">F</div><h1>Status koneksi FairShare</h1><p>PHP runtime: <b><?=e(PHP_VERSION)?></b></p><p>PDO PostgreSQL: <b><?=extension_loaded('pdo_pgsql')?'Aktif':'Tidak aktif'?></b></p><p>Status Supabase: <b><?=e(strtoupper($status))?></b></p><p>Database: <b><?=e($dbName)?></b></p><p><?=e($message)?></p><p><a class="btn primary" href="login.php">Ke halaman login →</a></p></section></body></html>
