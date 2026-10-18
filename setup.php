<?php
require_once __DIR__ . '/includes/bootstrap.php';
if (file_exists(DATA_PATH . '/installed.lock')) {
    http_response_code(403);
    exit('Setup sudah dijalankan. Hapus file installed.lock hanya jika ingin reset data.');
}
$seed = read_data('seed.json', []);
$seed['users'][0]['password_hash'] = password_hash('demo12345', PASSWORD_DEFAULT);
$seed['users'][1]['password_hash'] = password_hash('dosen12345', PASSWORD_DEFAULT);
foreach (['users', 'groups', 'tasks', 'telemetry', 'activity'] as $key)
    write_data($key . '.json', $seed[$key] ?? []);
file_put_contents(DATA_PATH . '/installed.lock', 'installed ' . date(DATE_ATOM));
?><!doctype html>
<html lang="id">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>FairShare Setup</title>
<link rel="stylesheet" href="assets/style.css">

<body class="setup-page">
    <section class="setup-card">
        <div class="auth-emblem">F</div>
        <h1>FairShare siap digunakan.</h1>
        <p>Data demo berhasil dibuat. Demi keamanan, hapus file <code>setup.php</code> dari folder proyek setelah ini.
        </p><a class="btn primary" href="login.php">Buka halaman login →</a>
    </section>
</body>

</html>