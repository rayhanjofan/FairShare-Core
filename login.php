<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
if (current_user())
    redirect('dashboard.php');
$error = '';
$selectedRole = (string) ($_POST['role'] ?? 'mahasiswa');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');
    if (!in_array($selectedRole, ['mahasiswa', 'dosen'], true)) {
        $error = 'Pilih jenis akun yang valid.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Masukkan email yang valid.';
    } else {
        try {
            $pdo = require_db();
            $stmt = $pdo->prepare('SELECT id::text AS id, nim_nip, name, email, password_hash, COALESCE(NULLIF(role, \'\'), \'mahasiswa\') AS role FROM public.users WHERE LOWER(email)=LOWER(:email) LIMIT 1');
            $stmt->execute(['email' => $email]);
            $row = $stmt->fetch();
            if ($row && password_verify($password, (string) $row['password_hash'])) {
                $dbRole = strtolower((string) ($row['role'] ?? 'mahasiswa'));
                if ($dbRole !== $selectedRole) {
                    $error = 'Jenis akun tidak sesuai dengan peran yang dipilih. Pilih ' . ($dbRole === 'dosen' ? 'Dosen' : 'Mahasiswa') . ' untuk akun ini.';
                } else {
                    session_regenerate_id(true);
                    $_SESSION['user'] = [
                        'id' => (string) $row['id'],
                        'nim_nip' => $row['nim_nip'],
                        'name' => $row['name'],
                        'email' => $row['email'],
                        'role' => $dbRole,
                    ];
                    log_activity('Login berhasil', 'Pengguna masuk ke workspace', 'success');
                    redirect('dashboard.php');
                }
            } else {
                $error = 'Email atau kata sandi tidak cocok.';
            }
        } catch (Throwable $e) {
            error_log('FairShare login: ' . $e->getMessage());
            $error = 'Supabase belum bisa diakses. Periksa data/.env, izin database, dan ekstensi pdo_pgsql.';
        }
    }
}
?>
<!doctype html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Masuk · FairShare</title>
    <link rel="stylesheet" href="assets/style.css?v=4">
</head>

<body class="auth-page">
    <div class="auth-top"><a class="brand brand-light" href="login.php"><span class="brand-mark brand-logo"><img
                    src="assets/fairshare-logo.svg" alt=""></span><span>FairShare<small>COLLABORATION
                    INTELLIGENCE</small></span></a><span>Platform transparansi kontribusi</span></div>
    <div class="auth-layout">
        <section class="auth-story">
            <div class="story-chip"><i></i> WORKSPACE FOR BETTER TEAMWORK</div>
            <h1>Kerja tim yang<br><span>lebih transparan.</span></h1>
            <p>Ubah aktivitas kolaborasi menjadi insight yang jelas. Pantau progres, pahami kontribusi, dan bangun kerja
                kelompok yang lebih adil.</p>
            <div class="story-stats">
                <div><b>01</b><span>Kontribusi terukur</span></div>
                <div><b>02</b><span>Audit transparan</span></div>
                <div><b>03</b><span>Progres terpantau</span></div>
            </div>
            <div class="story-orbit orbit-one"></div>
            <div class="story-orbit orbit-two"></div>
        </section>
        <section class="auth-panel">
            <div class="auth-card">
                <div class="auth-emblem auth-logo"><img src="assets/fairshare-logo.svg" alt="Logo FairShare"></div>
                <div class="eyebrow center">SELAMAT DATANG KEMBALI</div>
                <h2>Masuk ke FairShare</h2>
                <p class="auth-muted">Lanjutkan memantau kontribusi dan progres tim kamu.</p>
                <?php if ($error !== ''): ?>
                    <div class="flash error"><?= e($error) ?></div><?php endif; ?>
                <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <label for="email">Email kampus</label><input id="email" type="email" name="email"
                        placeholder="nama@kampus.ac.id" required autocomplete="username"
                        value="<?= e($_POST['email'] ?? '') ?>">
                    <label for="role">Masuk sebagai</label><select id="role" name="role" required>
                        <option value="mahasiswa" <?= $selectedRole === 'mahasiswa' ? 'selected' : '' ?>>Mahasiswa</option>
                        <option value="dosen" <?= $selectedRole === 'dosen' ? 'selected' : '' ?>>Dosen</option>
                    </select>
                    <label for="password">Kata sandi</label><input id="password" type="password" name="password"
                        placeholder="Masukkan kata sandi" required autocomplete="current-password">
                    <button class="btn primary auth-submit" type="submit">Masuk ke workspace <span>→</span></button>
                </form>
                <div class="auth-note">Pilih peran yang terdaftar pada akun Supabase kamu. Pilihan ini tidak mengubah
                    peran akun.</div>
                <div class="auth-note">Belum punya akun? <a href="register.php">Buat akun baru</a></div>
                <div class="auth-privacy">Data aktivitas ditampilkan untuk transparansi proyek. FairShare tidak memantau
                    perangkat secara invasif.</div>
            </div>
        </section>
    </div>
    <footer class="auth-footer">© 2026 FairShare · Politeknik Negeri Malang</footer>
</body>

</html>