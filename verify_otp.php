<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/mailer.php';

if (current_user()) {
    redirect('dashboard.php');
}

$email = mb_strtolower(trim((string) ($_GET['email'] ?? $_POST['email'] ?? $_SESSION['otp_pending_email'] ?? '')));
$purpose = (string) ($_GET['purpose'] ?? $_POST['purpose'] ?? $_SESSION['otp_pending_purpose'] ?? 'registration');

if (!in_array($purpose, ['registration', 'reset_password'], true) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    flash('Permintaan verifikasi tidak valid atau sesi telah berakhir.', 'error');
    redirect('login.php');
}

$error = '';
$flash = take_flash();

// Aksi Kirim Ulang OTP
if (isset($_GET['action']) && $_GET['action'] === 'resend') {
    try {
        $name = 'Pengguna FairShare';
        $payload = null;

        if ($purpose === 'registration') {
            $payload = $_SESSION['registration_payload'] ?? null;
            if (!empty($payload['name'])) {
                $name = (string) $payload['name'];
            }
        } else {
            $pdo = require_db();
            $stmt = $pdo->prepare('SELECT name FROM public.users WHERE LOWER(email) = LOWER(:email) LIMIT 1');
            $stmt->execute(['email' => $email]);
            $u = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($u && !empty($u['name'])) {
                $name = (string) $u['name'];
            }
        }

        $resend = create_and_send_otp($email, $name, $purpose, $payload);
        if ($resend['success']) {
            flash('Kode OTP baru telah berhasil dikirim ke ' . $email, 'success');
        } else {
            flash('Gagal mengirim ulang OTP: ' . $resend['message'], 'error');
        }
    } catch (Throwable $e) {
        flash('Terjadi kesalahan saat mengirim ulang kode OTP.', 'error');
    }
    redirect('verify_otp.php?email=' . urlencode($email) . '&purpose=' . urlencode($purpose));
}

// Verifikasi Form Submission OTP
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $otpCode = trim((string) ($_POST['otp_code'] ?? ''));

    if (!preg_match('/^[0-9]{6}$/', $otpCode)) {
        $error = 'Masukkan 6 digit angka kode OTP yang valid.';
    } else {
        try {
            $check = verify_otp_code($email, $otpCode, $purpose);

            if (!$check['valid']) {
                $error = $check['message'] ?? 'Kode OTP salah atau tidak berlaku.';
            } else {
                // OTP VALID!
                if ($purpose === 'registration') {
                    $payload = $check['payload'];
                    if (empty($payload) && isset($_SESSION['registration_payload'])) {
                        $payload = $_SESSION['registration_payload'];
                    }

                    if (empty($payload['nim_nip']) || empty($payload['name']) || empty($payload['hash'])) {
                        $error = 'Sesi registrasi kedaluwarsa. Silakan ulangi pendaftaran dari awal.';
                    } else {
                        $pdo = require_db();
                        $stmt = $pdo->prepare(
                            'INSERT INTO public.users '
                            . '(id, nim_nip, name, email, password_hash, role, github_username, created_at, updated_at) '
                            . 'VALUES (gen_random_uuid(), :nim_nip, :name, :email, :hash, :role, :github_username, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
                        );
                        $stmt->execute([
                            'nim_nip'         => $payload['nim_nip'],
                            'name'            => $payload['name'],
                            'email'           => $email,
                            'hash'            => $payload['hash'],
                            'role'            => $payload['role'] ?? 'mahasiswa',
                            'github_username' => !empty($payload['github_username']) ? $payload['github_username'] : null,
                        ]);

                        unset($_SESSION['registration_payload'], $_SESSION['otp_pending_email'], $_SESSION['otp_pending_purpose']);
                        log_activity('Akun terverifikasi', "Email {$email} berhasil diverifikasi melalui OTP", 'success');
                        flash('Verifikasi email berhasil! Akun Anda telah aktif, silakan masuk.', 'success');
                        redirect('login.php');
                    }
                } elseif ($purpose === 'reset_password') {
                    // Izin untuk mengganti password
                    $_SESSION['reset_authorized_email'] = $email;
                    unset($_SESSION['otp_pending_email'], $_SESSION['otp_pending_purpose']);
                    redirect('reset_password.php?step=new_password');
                }
            }
        } catch (Throwable $e) {
            error_log('FairShare verify OTP error: ' . $e->getMessage());
            $error = 'Terjadi gangguan saat memproses verifikasi. Periksa database atau coba kembali.';
        }
    }
}
?>
<!doctype html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Verifikasi OTP · FairShare</title>
    <link rel="stylesheet" href="assets/style.css?v=6">
    <style>
        .otp-input-field {
            text-align: center;
            font-size: 24px !important;
            font-family: monospace;
            letter-spacing: 12px;
            font-weight: 800;
            padding: 14px !important;
        }
        .otp-info-card {
            background: #f7f6ff;
            border: 1px solid #eeecff;
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 20px;
            font-size: 11px;
            line-height: 1.6;
            color: #555375;
        }
        [data-theme="dark"] .otp-info-card {
            background: #171b2e;
            border-color: #303651;
            color: #c4c9e2;
        }
        .otp-resend {
            margin-top: 18px;
            text-align: center;
            font-size: 11px;
            color: #777294;
        }
        .otp-resend a {
            color: #6b59eb;
            font-weight: 700;
            text-decoration: none;
        }
        .otp-resend a:hover {
            text-decoration: underline;
        }
    </style>
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
            <div class="story-chip"><i></i> VERIFIKASI KEAMANAN EMAIL</div>
            <h1>Verifikasi satu<br><span>langkah lagi.</span></h1>
            <p>Kami telah mengirimkan 6 digit kode OTP ke alamat Gmail Anda untuk memastikan keamanan dan kepemilikan akun.</p>
            <div class="story-stats">
                <div><b>01</b><span>Kirim ke Gmail</span></div>
                <div><b>02</b><span>Masukkan kode</span></div>
                <div><b>03</b><span>Akun terproteksi</span></div>
            </div>
            <div class="story-orbit orbit-one"></div>
            <div class="story-orbit orbit-two"></div>
        </section>

        <section class="auth-panel">
            <div class="auth-card">
                <div class="auth-emblem auth-logo">
                    <img src="assets/fairshare-logo.svg" alt="Logo FairShare">
                </div>
                <div class="eyebrow center">VERIFIKASI EMAIL GMAIL</div>
                <h2>Masukkan Kode OTP</h2>
                <p class="auth-muted">Periksa kotak masuk (atau folder Spam) di email Gmail Anda.</p>

                <div class="otp-info-card">
                    Kode verifikasi 6-digit telah dikirim ke: <br>
                    <strong style="color: #6b59eb; font-size: 13px;"><?= e($email) ?></strong><br>
                    <span style="color:#8589a1; font-size: 10px;">Kode ini hanya berlaku selama 10 menit.</span>
                </div>

                <?php if ($flash): ?>
                    <div class="flash <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
                <?php endif; ?>

                <?php if ($error !== ''): ?>
                    <div class="flash error"><?= e($error) ?></div>
                <?php endif; ?>

                <form method="post">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="email" value="<?= e($email) ?>">
                    <input type="hidden" name="purpose" value="<?= e($purpose) ?>">

                    <label for="otp_code">6 DIGIT KODE OTP</label>
                    <input id="otp_code" class="otp-input-field" type="text" name="otp_code"
                        placeholder="••••••" maxlength="6" pattern="[0-9]{6}" required autocomplete="one-time-code" autofocus>

                    <button class="btn primary auth-submit" type="submit">
                        Verifikasi Kode <span>→</span>
                    </button>
                </form>

                <div class="otp-resend">
                    Tidak menerima kode? <a href="verify_otp.php?email=<?= urlencode($email) ?>&purpose=<?= urlencode($purpose) ?>&action=resend">Kirim ulang kode OTP</a>
                </div>

                <div class="auth-note" style="margin-top: 20px;">
                    Salah memasukkan email? <a href="<?= $purpose === 'registration' ? 'register.php' : 'reset_password.php' ?>">Kembali</a>
                </div>
            </div>
        </section>
    </div>

    <footer class="auth-footer">© 2026 FairShare · Politeknik Negeri Malang</footer>
</body>

</html>
