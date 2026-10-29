<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/mailer.php';

if (current_user()) {
    redirect('dashboard.php');
}

$error = '';
$flash = take_flash();
$step = (string) ($_GET['step'] ?? 'request_otp');

// JIKA STEP: SET KATA SANDI BARU (setelah OTP sukses diverifikasi)
if ($step === 'new_password') {
    $authorizedEmail = (string) ($_SESSION['reset_authorized_email'] ?? '');

    if ($authorizedEmail === '') {
        flash('Sesi verifikasi OTP Anda telah kedaluwarsa. Silakan ulangi proses pemulihan.', 'error');
        redirect('reset_password.php');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

        if (strlen($newPassword) < 8) {
            $error = 'Kata sandi baru minimal harus 8 karakter.';
        } elseif ($newPassword !== $confirmPassword) {
            $error = 'Konfirmasi kata sandi tidak cocok.';
        } else {
            try {
                $pdo = require_db();
                $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
                $update = $pdo->prepare('UPDATE public.users SET password_hash = :hash, updated_at = CURRENT_TIMESTAMP WHERE LOWER(email) = LOWER(:email)');
                $update->execute([
                    'hash'  => $newHash,
                    'email' => $authorizedEmail,
                ]);

                unset($_SESSION['reset_authorized_email']);
                log_activity('Kata sandi diubah', "Kata sandi akun {$authorizedEmail} berhasil diperbarui via verifikasi OTP", 'info');
                flash('Kata sandi berhasil diubah! Silakan masuk menggunakan kata sandi baru Anda.', 'success');
                redirect('login.php');
            } catch (Throwable $e) {
                error_log('FairShare reset password update error: ' . $e->getMessage());
                $error = 'Gagal memperbarui kata sandi. Periksa koneksi database atau coba lagi nanti.';
            }
        }
    }
} else {
    // STEP 1: MASUKKAN EMAIL UNTUK KIRIM OTP
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Masukkan alamat email kampus atau Gmail yang valid.';
        } else {
            try {
                $pdo = require_db();
                $stmt = $pdo->prepare('SELECT id::text AS id, name, email FROM public.users WHERE LOWER(email) = LOWER(:email) LIMIT 1');
                $stmt->execute(['email' => $email]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$user) {
                    // PESAN KHUSUS JIKA EMAIL TIDAK TERDAFTAR
                    $error = 'Email tidak terdaftar pada sistem FairShare. Pastikan Anda memasukkan email yang sudah dibuat sebelumnya.';
                } else {
                    // Email terdaftar, buat OTP dan kirimkan ke email tujuan via Brevo
                    $otpResult = create_and_send_otp($user['email'], $user['name'], 'reset_password', ['user_id' => $user['id']]);

                    if (!$otpResult['success']) {
                        $error = 'Gagal mengirim kode OTP ke email: ' . $otpResult['message'];
                    } else {
                        flash('Kode OTP reset kata sandi telah dikirimkan ke email Gmail Anda. Periksa kotak masuk.', 'success');
                        redirect('verify_otp.php?email=' . urlencode($user['email']) . '&purpose=reset_password');
                    }
                }
            } catch (Throwable $e) {
                error_log('FairShare reset password request error: ' . $e->getMessage());
                $error = 'Layanan pemulihan kata sandi sedang mengalami gangguan. Coba beberapa saat lagi.';
            }
        }
    }
}
?>
<!doctype html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Pemulihan Kata Sandi · FairShare</title>
    <link rel="stylesheet" href="assets/style.css?v=6">
</head>

<body class="auth-page">
    <div class="auth-top">
        <a class="brand brand-light" href="login.php">
            <span class="brand-mark brand-logo">
                <img src="assets/fairshare-logo.svg" alt="FairShare Logo">
            </span>
            <span>FairShare<small>COLLABORATION INTELLIGENCE</small></span>
        </a>
        <span>Platform transparansi kontribusi</span>
    </div>

    <div class="auth-layout">
        <section class="auth-story">
            <div class="story-chip"><i></i> PEMULIHAN AKSES AKUN</div>
            <h1>Akses kembali<br><span>workspace kamu.</span></h1>
            <p>Sistem akan mengirimkan 6 digit kode OTP ke email Gmail Anda untuk memverifikasi bahwa Anda adalah pemilik akun yang sah.</p>
            <div class="story-stats">
                <div><b>01</b><span>Cek ketersediaan email</span></div>
                <div><b>02</b><span>Kirim OTP ke Gmail</span></div>
                <div><b>03</b><span>Perbarui kata sandi</span></div>
            </div>
            <div class="story-orbit orbit-one"></div>
            <div class="story-orbit orbit-two"></div>
        </section>

        <section class="auth-panel">
            <div class="auth-card">
                <div class="auth-emblem auth-logo">
                    <img src="assets/fairshare-logo.svg" alt="Logo FairShare">
                </div>

                <?php if ($step === 'new_password'): ?>
                    <div class="eyebrow center">LANGKAH TERAKHIR</div>
                    <h2>Buat Kata Sandi Baru</h2>
                    <p class="auth-muted">Email Anda <strong><?= e($_SESSION['reset_authorized_email'] ?? '') ?></strong> telah diverifikasi. Masukkan kata sandi baru Anda.</p>

                    <?php if ($error !== ''): ?>
                        <div class="flash error"><?= e($error) ?></div>
                    <?php endif; ?>

                    <form method="post">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

                        <label for="new_password">Kata sandi baru</label>
                        <input id="new_password" type="password" name="new_password"
                            placeholder="Minimal 8 karakter" minlength="8" required autocomplete="new-password" autofocus>

                        <label for="confirm_password">Ulangi kata sandi baru</label>
                        <input id="confirm_password" type="password" name="confirm_password"
                            placeholder="Ketik ulang kata sandi baru" minlength="8" required autocomplete="new-password">

                        <button class="btn primary auth-submit" type="submit">
                            Simpan Kata Sandi Baru <span>→</span>
                        </button>
                    </form>
                <?php else: ?>
                    <div class="eyebrow center">LUPA KATA SANDI</div>
                    <h2>Reset Kata Sandi</h2>
                    <p class="auth-muted">Masukkan email kampus atau Gmail akun Anda. Kami akan mengirimkan kode verifikasi OTP.</p>

                    <?php if ($flash): ?>
                        <div class="flash <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
                    <?php endif; ?>

                    <?php if ($error !== ''): ?>
                        <div class="flash error"><?= e($error) ?></div>
                    <?php endif; ?>

                    <form method="post">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

                        <label for="email">Email terdaftar</label>
                        <input id="email" type="email" name="email"
                            placeholder="nama@kampus.ac.id atau nama@gmail.com" required autocomplete="email"
                            value="<?= e($_POST['email'] ?? '') ?>" autofocus>

                        <button class="btn primary auth-submit" type="submit">
                            Kirim Kode OTP ke Email <span>→</span>
                        </button>
                    </form>
                <?php endif; ?>

                <div class="auth-note" style="margin-top: 20px;">
                    Sudah ingat kata sandi akunmu? <a href="login.php">Masuk sekarang</a>
                </div>

                <div class="auth-privacy">
                    Kode OTP bersifat rahasia dan hanya dikirimkan ke alamat email pemilik akun yang terdaftar pada sistem.
                </div>
            </div>
        </section>
    </div>

    <footer class="auth-footer">© 2026 FairShare · Politeknik Negeri Malang</footer>
</body>

</html>
