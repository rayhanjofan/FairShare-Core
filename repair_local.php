<?php
require_once __DIR__ . '/includes/bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
?><!doctype html><html lang="id"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Perbaikan Login FairShare</title><link rel="stylesheet" href="assets/style.css"><body class="setup-page"><section class="setup-card"><h1>Perbaikan akun demo lokal</h1><p>Tool ini hanya untuk instalasi XAMPP lokal. Tool akan memperbaiki password akun demo dan memperbaiki penyimpanan data JSON. Akun lain dan kelompok tidak dihapus.</p><form method="post"><label>Ketik FIX-LOCAL untuk konfirmasi</label><input name="confirm" required autocomplete="off"><button class="btn primary" type="submit">Perbaiki login demo</button></form></section></body></html><?php
exit;
}
if (($_POST['confirm'] ?? '') !== 'FIX-LOCAL') { http_response_code(400); exit('Konfirmasi tidak cocok.'); }
$users = read_data('users.json', []);
if (!is_array($users)) $users = [];
$found = false;
foreach ($users as &$user) {
    if (strtolower((string)($user['email'] ?? '')) === 'demo@fairshare.local') {
        $user['password_hash'] = password_hash('demo12345', PASSWORD_DEFAULT);
        $user['name'] = $user['name'] ?? 'Fakhrur Rozi';
        $user['role'] = $user['role'] ?? 'mahasiswa';
        $found = true;
    }
}
unset($user);
if (!$found) {
    $users[] = ['id' => (int)(max(array_map(fn($u)=>(int)($u['id']??0), $users) ?: [0]) + 1), 'name'=>'Fakhrur Rozi', 'email'=>'demo@fairshare.local', 'password_hash'=>password_hash('demo12345', PASSWORD_DEFAULT), 'role'=>'mahasiswa'];
}
if (!write_data('users.json', $users)) { http_response_code(500); exit('Gagal menulis data/users.json. Cek permission folder data.'); }
?><!doctype html><html lang="id"><meta charset="utf-8"><title>Selesai</title><body style="font:16px Arial;padding:40px"><h1>Login demo diperbaiki.</h1><p>Email: <b>demo@fairshare.local</b></p><p>Password: <b>demo12345</b></p><p>Sekarang hapus file <code>repair_local.php</code> dari folder FairShare, lalu masuk melalui <a href="login.php">halaman login</a>.</p></body></html>
