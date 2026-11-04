<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/integrations/google/google_service.php';
$user = require_login();
$integration = fs_google_integration((string) $user['id']);
$configured = fs_google_configured();
layout_start('Integrasi Akun', 'integrations');
page_heading('PENGATURAN / INTEGRASI', 'Integrasi Akun', 'Hubungkan akun eksternal agar FairShare dapat mengaitkan aktivitas dengan identitas anggota yang benar.', '<a class="btn secondary" href="tasks.php">Kembali ke tugas</a>');
?>
<section class="panel" style="max-width:820px">
  <div class="panel-heading">
    <div>
      <div class="panel-title">Google Account</div>
      <div class="panel-sub">Digunakan untuk memverifikasi aktivitas Google Docs yang dibagikan ke kelompok.</div>
    </div>
    <span class="status-pill <?= $integration ? 'success' : 'muted' ?>"><?= $integration ? 'Terhubung' : 'Belum terhubung' ?></span>
  </div>
  <?php if (!$configured): ?>
    <div class="flash error">Konfigurasi Google OAuth belum lengkap. Isi GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET, GOOGLE_REDIRECT_URI, dan APP_ENCRYPTION_KEY pada data/.env. Lihat docs/SMART_ASSIGNMENT_INTEGRATION.md.</div>
  <?php endif; ?>
  <?php if ($integration): ?>
    <div class="stats-grid two-stats">
      <article class="stat-card"><div class="stat-label">AKUN GOOGLE</div><div class="stat-number" style="font-size:1.05rem"><?= e($integration['provider_email']) ?></div><div class="stat-foot">Identitas terverifikasi dari Google</div></article>
      <article class="stat-card"><div class="stat-label">TERAKHIR DIPERBARUI</div><div class="stat-number" style="font-size:1.05rem"><?= e(format_date((string) $integration['updated_at'])) ?></div><div class="stat-foot">Credential disimpan terenkripsi</div></article>
    </div>
    <p class="muted">Setiap anggota kelompok perlu menghubungkan akun Google masing-masing agar aktivitas edit yang dapat diidentifikasi sebagai aktivitas dirinya bisa dikaitkan ke akun FairShare.</p>
    <form method="post" action="integrations/google/disconnect.php" onsubmit="return confirm('Putuskan koneksi akun Google dari FairShare?')">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <button class="btn danger" type="submit">Putuskan koneksi</button>
    </form>
  <?php else: ?>
    <p>Hubungkan akun Google yang digunakan untuk mengakses dokumen tugas kelompok. FairShare hanya meminta izin baca metadata dan aktivitas Drive yang diperlukan untuk audit.</p>
    <a class="btn primary <?= $configured ? '' : 'disabled' ?>" href="<?= $configured ? 'integrations/google/connect.php' : '#' ?>" <?= $configured ? '' : 'aria-disabled="true" onclick="return false"' ?>>Hubungkan akun Google ↗</a>
  <?php endif; ?>
  <div class="flash" style="margin-top:18px">FairShare tidak merekam setiap huruf yang diketik. Sistem mencatat aktivitas yang disediakan oleh Google Drive Activity API, seperti event edit/create, waktu, target dokumen, dan identitas aktor jika dapat diverifikasi.</div>
</section>
<section class="panel" style="max-width:820px">
  <div class="panel-title">Cara kerja integrasi</div>
  <ol>
    <li>Hubungkan akun Google masing-masing anggota.</li>
    <li>Project Manager menghubungkan satu Google Docs pada detail tugas.</li>
    <li>Pastikan dokumen dibagikan kepada anggota yang mengerjakannya.</li>
    <li>Tekan Sinkronkan Aktivitas pada detail tugas untuk mengambil riwayat aktivitas.</li>
  </ol>
  <p class="muted">Sinkronisasi manual dapat digunakan di localhost. Sinkronisasi otomatis berkala memerlukan worker terjadwal pada komputer atau server.</p>
</section>
<?php
layout_end();
