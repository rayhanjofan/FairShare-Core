<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/integrations/google/google_service.php';
$user = require_login();
$integration = fs_google_integration((string) $user['id']);
$configured = fs_google_configured();

$hasActivityScope = false;
$hasMetadataScope = false;
$statusPillClass = 'muted';
$statusLabel = 'Belum terhubung';

if ($integration) {
    $hasActivityScope = fs_google_has_activity_scope($integration['scopes'] ?? '');
    $hasMetadataScope = fs_google_has_metadata_scope($integration['scopes'] ?? '');
    $hasRefreshToken = !empty($integration['refresh_token_encrypted']);

    if (!$hasRefreshToken) {
        $statusPillClass = 'danger';
        $statusLabel = 'Perlu menghubungkan ulang';
    } elseif ($hasActivityScope) {
        $statusPillClass = 'success';
        $statusLabel = 'Terhubung (Audit Aktif)';
    } else {
        $statusPillClass = 'warning';
        $statusLabel = 'Terhubung (Izin audit belum diberikan)';
    }
}

layout_start('Integrasi Akun', 'integrations');
page_heading('PENGATURAN / INTEGRASI', 'Integrasi Akun', 'Hubungkan akun eksternal agar FairShare dapat mengaitkan aktivitas dengan identitas anggota yang benar.', '<a class="btn secondary" href="tasks.php">Kembali ke tugas</a>');
?>
<section class="panel" style="max-width:880px">
  <div class="panel-heading">
    <div>
      <div class="panel-title">Google Account</div>
      <div class="panel-sub">Digunakan untuk memverifikasi dokumen tugas dan merekam audit kontribusi melalui Google Drive Activity API.</div>
    </div>
    <span class="status-pill <?= $statusPillClass ?>"><?= $statusLabel ?></span>
  </div>

  <?php if (!$configured): ?>
    <div class="flash error">
      <b>Konfigurasi Google OAuth belum lengkap.</b><br>
      Pastikan variabel <code>GOOGLE_CLIENT_ID</code>, <code>GOOGLE_CLIENT_SECRET</code>, <code>GOOGLE_REDIRECT_URI</code>, dan <code>APP_ENCRYPTION_KEY</code> terisi pada <code>data/.env</code>.
    </div>
  <?php endif; ?>

  <?php if ($integration): ?>
    <div class="stats-grid" style="grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin:16px 0">
      <article class="stat-card">
        <div class="stat-label">AKUN GOOGLE</div>
        <div class="stat-number" style="font-size:1rem;word-break:break-all"><?= e($integration['provider_email']) ?></div>
        <div class="stat-foot">Identitas terverifikasi dari Google</div>
      </article>

      <article class="stat-card">
        <div class="stat-label">IZIN DRIVE ACTIVITY</div>
        <div class="stat-number" style="font-size:1rem;color:<?= $hasActivityScope ? 'var(--color-success,#16a34a)' : 'var(--color-warning,#d97706)' ?>">
          <?= $hasActivityScope ? '✓ Aktif (Audit)' : '⚠ Belum diizinkan' ?>
        </div>
        <div class="stat-foot">drive.activity.readonly</div>
      </article>

      <article class="stat-card">
        <div class="stat-label">IZIN METADATA DOKUMEN</div>
        <div class="stat-number" style="font-size:1rem;color:<?= $hasMetadataScope ? 'var(--color-success,#16a34a)' : 'var(--color-warning,#d97706)' ?>">
          <?= $hasMetadataScope ? '✓ Aktif (Metadata)' : '⚠ Belum diizinkan' ?>
        </div>
        <div class="stat-foot">drive.metadata.readonly</div>
      </article>

      <article class="stat-card">
        <div class="stat-label">TERAKHIR DIPERBARUI</div>
        <div class="stat-number" style="font-size:1rem"><?= e(format_date((string) $integration['updated_at'])) ?></div>
        <div class="stat-foot">Credential AES-256-GCM terenkripsi</div>
      </article>
    </div>

    <?php if (!$hasActivityScope): ?>
      <div class="flash warning" style="margin-bottom:16px">
        <b>Perhatian: Izin Google Drive Activity belum diberikan.</b><br>
        Akun Google Anda telah terhubung, namun Anda tidak mencentang izin aktivitas saat login Google. FairShare tidak dapat membaca riwayat edit dokumen tugas Anda sampai izin ini diberikan.<br>
        <a class="btn warning" href="integrations/google/connect.php" style="margin-top:8px;display:inline-block">Perbarui Izin Google Drive ↗</a>
      </div>
    <?php endif; ?>

    <p class="muted">Setiap anggota kelompok wajib menghubungkan akun Google masing-masing agar aktivitas pengerjaan dokumen yang teridentifikasi dapat diatribusikan ke profil FairShare Anda.</p>

    <div style="display:flex;gap:12px;align-items:center;margin-top:12px">
      <a class="btn secondary" href="integrations/google/connect.php">Hubungkan Ulang / Refresh Izin ↗</a>
      <form method="post" action="integrations/google/disconnect.php" onsubmit="return confirm('Putuskan koneksi akun Google dari FairShare? Token akan dicabut dan dihapus.')">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <button class="btn danger" type="submit">Putuskan koneksi</button>
      </form>
    </div>

  <?php else: ?>
    <p>Hubungkan akun Google yang digunakan untuk membuka atau mengedit dokumen Google Docs tugas kelompok. FairShare membaca metadata dokumen dan riwayat aktivitas pengerjaan melalui Google Drive Activity API untuk audit kontribusi tim yang adil.</p>
    <a class="btn primary <?= $configured ? '' : 'disabled' ?>" href="<?= $configured ? 'integrations/google/connect.php' : '#' ?>" <?= $configured ? '' : 'aria-disabled="true" onclick="return false"' ?>>Hubungkan akun Google ↗</a>
  <?php endif; ?>

  <div class="flash" style="margin-top:18px">
    <b>Privasi & Least Privilege:</b> FairShare tidak merekam ketikan per huruf atau isi dokumen pribadi Anda. Sistem hanya membaca event aktivitas (seperti event edit/create, waktu, dan aktor) yang disediakan resmi oleh Google Drive Activity API untuk dokumen yang secara eksplisit dihubungkan ke tugas kelompok.
  </div>
</section>

<section class="panel" style="max-width:880px">
  <div class="panel-title">Troubleshooting: Panduan Mengatasi Error 403: access_denied</div>
  <p class="muted">Jika saat menekan tombol "Hubungkan akun Google" muncul <b>Error 403: access_denied</b> dari Google, periksa checklist konfigurasi berikut di Google Cloud Console:</p>
  <ul style="line-height:1.7;padding-left:20px">
    <li>
      <b>1. Daftarkan Email di Test Users (Wajib untuk status "Testing"):</b><br>
      Jika status publikasi OAuth consent screen masih <i>Testing</i>, Google memblokir semua akun yang belum didaftarkan.<br>
      Buka Google Cloud Console &rarr; <b>OAuth consent screen</b> (atau <b>Google Auth Platform &rarr; Audience</b>) &rarr; Bagian <b>Test users</b> &rarr; Klik <b>+ ADD USERS</b> &rarr; Masukkan alamat email Google yang akan digunakan untuk login.
    </li>
    <li>
      <b>2. Status Publishing & Restricted Scopes:</b><br>
      Scope <code>drive.activity.readonly</code> dan <code>drive.metadata.readonly</code> termasuk dalam <i>Restricted Scopes</i>. Jangan klik "Publish App" sebelum diverifikasi jika aplikasi bersifat publik. Tetap gunakan mode <b>Testing</b> selama proses PBL/pengembangan akademik (maksimal 100 test user).
    </li>
    <li>
      <b>3. Aktifkan API yang Diperlukan di Google Cloud:</b><br>
      Pastikan kedua API berikut sudah berstatus <b>Enabled</b> pada Google Cloud Project yang sama:<br>
      &bull; <code>Google Drive API</code> (untuk verifikasi metadata dokumen)<br>
      &bull; <code>Google Drive Activity API</code> (untuk membaca riwayat edit)
    </li>
    <li>
      <b>4. Kebijakan Organisasi Google Workspace Kampus:</b><br>
      Jika mahasiswa menggunakan akun email institusi (misal: <code>@mahasiswa.kampus.ac.id</code>), administrator Google Workspace kampus mungkin membatasi aplikasi OAuth luar. Gunakan akun Gmail pribadi yang telah ditambahkan ke Test Users jika akun kampus diblokir.
    </li>
    <li>
      <b>5. Kesamaan Redirect URI:</b><br>
      Redirect URI yang didaftarkan di Google Cloud Console harus sama persis dengan yang ada di <code>data/.env</code>:<br>
      <code><?= e(app_env('GOOGLE_REDIRECT_URI', 'http://127.0.0.1:8000/integrations/google/callback.php')) ?></code><br>
      Jangan menukar antara <code>127.0.0.1</code> dan <code>localhost</code>.
    </li>
    <li>
      <b>6. Masa Berlaku Token Testing (7 Hari):</b><br>
      Pada aplikasi berstatus Testing, Google secara otomatis menghentikan refresh token setelah 7 hari. Jika status berubah menjadi perlu menghubungkan ulang, cukup klik tombol <b>Hubungkan Ulang</b> di atas.
    </li>
  </ul>
</section>

<section class="panel" style="max-width:880px">
  <div class="panel-title">Alur Kerja Pengerjaan & Audit Tugas</div>
  <ol style="line-height:1.7;padding-left:20px">
    <li>Setiap anggota tim menghubungkan akun Google masing-masing pada menu ini.</li>
    <li>Dosen membuat tugas bertipe <b>Google Docs</b>.</li>
    <li>Project Manager / Mahasiswa membuat file Google Docs di Google Drive, membagikan dokumen tersebut kepada seluruh anggota kelompoknya, lalu menempelkan link Google Docs di detail tugas FairShare.</li>
    <li>Anggota kelompok mengerjakan tugas secara aktif di Google Docs menggunakan akun Google yang terhubung.</li>
    <li>Project Manager atau Dosen menekan tombol <b>Sinkronkan aktivitas Google Docs</b> pada detail tugas.</li>
    <li>Aktivitas akan tercatat di riwayat telemetry tanpa menduplikasi data antar anggota.</li>
  </ol>
</section>
<?php
layout_end();
