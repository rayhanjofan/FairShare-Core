<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/mailer.php';

if (current_user()) {
  redirect('dashboard.php');
}

$error = '';
$selectedRole = (string) ($_POST['role'] ?? 'mahasiswa');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  verify_csrf();
  $name = trim((string) ($_POST['name'] ?? ''));
  $nimNip = trim((string) ($_POST['nim_nip'] ?? ''));
  $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
  $githubUsername = trim((string) ($_POST['github_username'] ?? ''));
  $password = (string) ($_POST['password'] ?? '');
  $dosenCode = (string) ($_POST['dosen_code'] ?? '');

  if (!in_array($selectedRole, ['mahasiswa', 'dosen'], true)) {
    $error = 'Pilih peran akun yang valid.';
  } elseif (
    $name === '' || mb_strlen($name) > 100 || $nimNip === '' || mb_strlen($nimNip) > 30
    || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 150
    || ($githubUsername !== '' && mb_strlen($githubUsername) > 100)
    || strlen($password) < 8
  ) {
    $error = 'Isi nama (maks. 100 karakter), NIM/NIP (maks. 30 karakter), email valid, username GitHub maksimal 100 karakter (opsional), dan kata sandi minimal 8 karakter.';
  } else {
    // Role dosen memerlukan validasi kode
    if ($selectedRole === 'dosen') {
      $expectedDosenCode = app_env('DOSEN_REGISTRATION_CODE', '');
      if ($expectedDosenCode === '') {
        $error = 'Pendaftaran akun dosen belum diaktifkan. Minta admin menyiapkan DOSEN_REGISTRATION_CODE di data/.env.';
      } elseif (!hash_equals($expectedDosenCode, $dosenCode)) {
        $error = 'Kode aktivasi dosen tidak sesuai.';
      }
    }

    if ($error === '') {
      try {
        $pdo = require_db();

        // Periksa apakah NIM/NIP, email, atau username GitHub sudah terdaftar
        $checkStmt = $pdo->prepare(
          'SELECT id FROM public.users '
          . 'WHERE LOWER(email) = LOWER(:email) OR nim_nip = :nim '
          . 'OR (github_username IS NOT NULL AND :gh <> \'\' AND LOWER(github_username) = LOWER(:gh)) LIMIT 1'
        );
        $checkStmt->execute([
          'email' => $email,
          'nim'   => $nimNip,
          'gh'    => $githubUsername,
        ]);

        if ($checkStmt->fetch()) {
          $error = 'NIM/NIP, email, atau username GitHub sudah terdaftar. Silakan masuk atau gunakan data lain.';
        } else {
          // Siapkan data akun sementara (payload) sebelum diverifikasi dengan OTP
          $payload = [
            'nim_nip'         => $nimNip,
            'name'            => $name,
            'email'           => $email,
            'hash'            => password_hash($password, PASSWORD_DEFAULT),
            'role'            => $selectedRole,
            'github_username' => $githubUsername !== '' ? $githubUsername : null,
          ];

          $_SESSION['registration_payload'] = $payload;
          $_SESSION['otp_pending_email'] = $email;
          $_SESSION['otp_pending_purpose'] = 'registration';

          // Buat OTP dan kirimkan ke email tujuan via Brevo API
          $otpResult = create_and_send_otp($email, $name, 'registration', $payload);

          if (!$otpResult['success']) {
            $error = 'Gagal mengirim kode verifikasi ke email: ' . $otpResult['message'];
          } else {
            flash('Kode verifikasi telah dikirimkan ke email Gmail Anda. Silakan masukkan 6 digit kode OTP.', 'success');
            redirect('verify_otp.php?email=' . urlencode($email) . '&purpose=registration');
          }
        }
      } catch (PDOException $e) {
        error_log('FairShare register SQLSTATE ' . $e->getCode() . ': ' . $e->getMessage());
        $error = match ((string) $e->getCode()) {
          '23505' => 'NIM/NIP, email, atau username GitHub sudah terdaftar. Periksa data yang kamu masukkan.',
          '42P01' => 'Tabel public.users atau public.otp_codes tidak ditemukan pada Supabase.',
          default => 'Pendaftaran gagal diproses. Periksa database atau log PHP.',
        };
      } catch (Throwable $e) {
        error_log('FairShare register: ' . $e->getMessage());
        $error = 'Layanan verifikasi tidak dapat diakses. Periksa data/.env dan koneksi database.';
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
  <title>Daftar · FairShare</title>
  <link rel="stylesheet" href="assets/style.css?v=5">
</head>

<body class="auth-page">
  <div class="auth-top"><a class="brand brand-light" href="login.php"><span class="brand-mark brand-logo"><img
          src="assets/fairshare-logo.svg" alt=""></span><span>FairShare<small>COLLABORATION
          INTELLIGENCE</small></span></a><span>Mulai kolaborasi lebih transparan</span></div>
  <div class="auth-layout register-layout">
    <section class="auth-story">
      <div class="story-chip"><i></i> YOUR TEAM, IN BETTER SYNC</div>
      <h1>Mulai dari tim.<br><span>Tumbuh bersama.</span></h1>
      <p>Buat ruang kerja kolaboratif, kelola tugas, dan bangun riwayat kontribusi yang bisa dipertanggungjawabkan.</p>
      <div class="story-stats">
        <div><b>01</b><span>Data terverifikasi</span></div>
        <div><b>02</b><span>Proteksi email OTP</span></div>
        <div><b>03</b><span>Kolaborasi transparan</span></div>
      </div>
      <div class="story-orbit orbit-one"></div>
      <div class="story-orbit orbit-two"></div>
    </section>
    <section class="auth-panel">
      <div class="auth-card">
        <div class="auth-emblem auth-logo"><img src="assets/fairshare-logo.svg" alt="Logo FairShare"></div>
        <div class="eyebrow center">BUAT AKUN BARU</div>
        <h2>Gabung ke FairShare</h2>
        <p class="auth-muted">Kode verifikasi OTP akan dikirimkan ke email Gmail Anda.</p>
        <?php if ($error !== ''): ?>
          <div class="flash error"><?= e($error) ?></div><?php endif; ?>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <label for="name">Nama lengkap</label><input id="name" name="name" placeholder="Nama kamu" autocomplete="name"
            maxlength="100" required value="<?= e($_POST['name'] ?? '') ?>">
          <label for="nim_nip">NIM/NIP</label><input id="nim_nip" name="nim_nip" placeholder="Masukkan NIM/NIP"
            maxlength="30" required value="<?= e($_POST['nim_nip'] ?? '') ?>">
          <label for="email">Email kampus / Gmail</label><input id="email" type="email" name="email"
            placeholder="nama@gmail.com" maxlength="150" required autocomplete="email"
            value="<?= e($_POST['email'] ?? '') ?>">
          <label for="github_username">Username GitHub <span class="muted">(opsional)</span></label><input
            id="github_username" name="github_username" placeholder="username-github" maxlength="100" autocomplete="off"
            value="<?= e($_POST['github_username'] ?? '') ?>">
          <label for="role">Daftar sebagai</label>
          <select id="role" name="role" required>
            <option value="mahasiswa" <?= $selectedRole === 'mahasiswa' ? 'selected' : '' ?>>Mahasiswa</option>
            <option value="dosen" <?= $selectedRole === 'dosen' ? 'selected' : '' ?>>Dosen (memerlukan kode aktivasi)
            </option>
          </select>
          <div id="dosen-code-wrap" <?= $selectedRole === 'dosen' ? '' : 'hidden' ?>><label for="dosen_code">Kode aktivasi
              dosen</label><input id="dosen_code" type="password" name="dosen_code" autocomplete="off"
              placeholder="Kode dari admin"><small class="field-help">Dosen perlu kode dari administrator. Pilihan peran
              tidak langsung memberikan akses dosen.</small></div>
          <label for="password">Kata sandi</label><input id="password" type="password" name="password" minlength="8"
            placeholder="Minimal 8 karakter" required autocomplete="new-password">
          <button class="btn primary auth-submit" type="submit">Lanjutkan ke Verifikasi OTP <span>→</span></button>
        </form>
        <div class="auth-note">Akun dosen memerlukan kode aktivasi yang disimpan aman pada konfigurasi server.</div>
        <div class="auth-note">Sudah punya akun? <a href="login.php">Masuk di sini</a></div>
      </div>
    </section>
  </div>
  <footer class="auth-footer">© 2026 FairShare · Politeknik Negeri Malang</footer>
  <script>
    (() => {
      const role = document.getElementById('role');
      const wrap = document.getElementById('dosen-code-wrap');
      const code = document.getElementById('dosen_code');
      function syncRoleFields() {
        const isTeacher = role.value === 'dosen';
        wrap.hidden = !isTeacher;
        code.required = isTeacher;
      }
      role.addEventListener('change', syncRoleFields);
      syncRoleFields();
    })();
  </script>
</body>

</html>
