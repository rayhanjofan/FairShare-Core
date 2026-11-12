<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/integrations/google/google_service.php';
$user = require_login();
$pdo = require_db();

$githubError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $postAction = (string) ($_POST['action'] ?? '');

    if ($postAction === 'save_github') {
        $ghUser = trim((string) ($_POST['github_username'] ?? ''));
        // Clean leading '@' if entered
        $ghUser = ltrim($ghUser, '@');

        if ($ghUser === '') {
            $githubError = 'Username GitHub tidak boleh kosong.';
        } elseif (mb_strlen($ghUser) > 100) {
            $githubError = 'Username GitHub maksimal 100 karakter.';
        } elseif (!preg_match('/^[a-zA-Z0-9](?:[a-zA-Z0-9]|-(?=[a-zA-Z0-9])){0,38}$/', $ghUser)) {
            $githubError = 'Format username GitHub tidak valid (hanya huruf, angka, dan tanda hubung).';
        } else {
            $checkGh = $pdo->prepare("SELECT id FROM public.users WHERE LOWER(github_username) = LOWER(:gh) AND id != CAST(:uid AS uuid) LIMIT 1");
            $checkGh->execute(['gh' => $ghUser, 'uid' => (string) $user['id']]);
            if ($checkGh->fetch()) {
                $githubError = 'Username GitHub tersebut sudah digunakan oleh akun lain.';
            } else {
                $updateGh = $pdo->prepare("UPDATE public.users SET github_username = :gh, updated_at = CURRENT_TIMESTAMP WHERE id = CAST(:uid AS uuid)");
                $updateGh->execute(['gh' => $ghUser, 'uid' => (string) $user['id']]);
                $_SESSION['user']['github_username'] = $ghUser;
                $user['github_username'] = $ghUser;
                log_activity('Integrasi GitHub diperbarui', "Username GitHub disimpan: @$ghUser", 'success');
                flash("Username GitHub <b>@" . e($ghUser) . "</b> berhasil disimpan.");
                redirect('integrations.php');
            }
        }
    } elseif ($postAction === 'disconnect_github') {
        $updateGh = $pdo->prepare("UPDATE public.users SET github_username = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = CAST(:uid AS uuid)");
        $updateGh->execute(['uid' => (string) $user['id']]);
        $_SESSION['user']['github_username'] = null;
        $user['github_username'] = null;
        log_activity('Integrasi GitHub diputus', 'Username GitHub dihapus dari profil', 'info');
        flash("Integrasi GitHub berhasil diputus.");
        redirect('integrations.php');
    }
}

// Fetch current GitHub username
$checkUser = $pdo->prepare("SELECT github_username FROM public.users WHERE id = CAST(:uid AS uuid) LIMIT 1");
$checkUser->execute(['uid' => (string) $user['id']]);
$githubUsername = (string) ($checkUser->fetchColumn() ?: '');

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

$githubStatusPillClass = $githubUsername !== '' ? 'warning' : 'muted';
$githubStatusLabel = $githubUsername !== '' ? 'Username Terdaftar (API Belum Aktif)' : 'Belum terhubung';

layout_start('Koneksi', 'integrations');
page_heading('AKUN / KONEKSI', 'Koneksi Akun', 'Hubungkan akun eksternal agar FairShare dapat mengaitkan aktivitas dengan identitas anggota yang benar.', '<a class="btn secondary" href="dashboard.php">Kembali ke Dashboard</a>');

if ($githubError) {
    echo '<div class="flash error" style="max-width:880px">' . e($githubError) . '</div>';
}
?>
<section class="panel" style="max-width:880px">
  <div class="panel-heading">
    <div>
      <div class="panel-title">Koneksi Akun Google</div>
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

<section class="panel" style="max-width:880px; margin-top:24px;">
  <div class="panel-heading">
    <div>
      <div class="panel-title">Koneksi Akun GitHub</div>
      <div class="panel-sub">Digunakan untuk mencocokkan riwayat commit dan kontribusi kode pada tugas kolaboratif bertipe GitHub.</div>
    </div>
    <span class="status-pill <?= $githubStatusPillClass ?>"><?= $githubStatusLabel ?></span>
  </div>

  <?php if ($githubUsername !== ''): ?>
    <div class="stats-grid" style="grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin:16px 0">
      <article class="stat-card">
        <div class="stat-label">USERNAME GITHUB</div>
        <div class="stat-number" style="font-size:1rem;color:var(--color-primary,#6754ef)">@<?= e($githubUsername) ?></div>
        <div class="stat-foot">Identitas author terdaftar</div>
      </article>

      <article class="stat-card">
        <div class="stat-label">STATUS PEMETAAN</div>
        <div class="stat-number" style="font-size:1rem;color:var(--color-success,#16a34a)">✓ Terdaftar</div>
        <div class="stat-foot">Commit WBS dicocokkan otomatis</div>
      </article>

      <article class="stat-card">
        <div class="stat-label">INTEGRASI REPOSITORY</div>
        <div class="stat-number" style="font-size:1rem">Per Tugas WBS</div>
        <div class="stat-foot">URL repo dikaitkan di tugas kelompok</div>
      </article>

      <article class="stat-card">
        <div class="stat-label">WEBHOOK / API GLOBAL</div>
        <div class="stat-number" style="font-size:1rem;color:var(--color-warning,#d97706)">Belum Aktif</div>
        <div class="stat-foot">Sinkronisasi webhook otomatis pending</div>
      </article>
    </div>

    <div class="flash" style="margin-bottom:16px; background:#f4f3ff; border-color:#e0dcff;">
      <b>Status Integrasi:</b> Akun FairShare Anda terhubung dengan identitas <b>@<?= e($githubUsername) ?></b>. Setiap commit pada repository tugas kelompok yang cocok dengan username ini akan diatribusikan ke profil Anda.
    </div>

    <div style="display:flex;gap:12px;align-items:center;margin-top:12px;flex-wrap:wrap;">
      <button class="btn secondary" type="button" onclick="var el = document.getElementById('editGhForm'); el.hidden = !el.hidden;">Ubah Username GitHub ✎</button>
      <form method="post" onsubmit="return confirm('Putuskan koneksi username GitHub dari profil FairShare?')">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="disconnect_github">
        <button class="btn danger" type="submit">Putuskan koneksi GitHub</button>
      </form>
    </div>

    <form method="post" id="editGhForm" hidden style="margin-top:16px;padding:16px;background:var(--bg-subtle,#f8f8fc);border-radius:10px;border:1px solid #ebebf4;max-width:440px;">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="save_github">
      <div style="margin-bottom:10px;">
        <label style="display:block;font-weight:600;font-size:12px;margin-bottom:6px;">Ganti Username GitHub</label>
        <input name="github_username" value="<?= e($githubUsername) ?>" required maxlength="100" style="padding:8px 12px;border-radius:8px;border:1px solid #d9d9ec;width:100%;box-sizing:border-box;">
      </div>
      <button class="btn primary small" type="submit">Simpan Perubahan</button>
    </form>
  <?php else: ?>
    <p>Hubungkan username akun GitHub Anda. FairShare akan memetakan commit, pull request, dan kontribusi kode pada repository tugas kelompok yang terdaftar ke akun Anda.</p>
    
    <form method="post" style="margin-top:16px;padding:16px;background:var(--bg-subtle,#f8f8fc);border-radius:10px;border:1px solid #ebebf4;max-width:480px;">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="save_github">
      <div style="margin-bottom:12px;">
        <label style="display:block;font-weight:600;font-size:12px;margin-bottom:6px;">Username GitHub <span class="text-danger">*</span></label>
        <input name="github_username" placeholder="Contoh: octocat" required maxlength="100" style="padding:8px 12px;border-radius:8px;border:1px solid #d9d9ec;width:100%;box-sizing:border-box;">
        <small class="muted" style="display:block;margin-top:4px;">Masukkan username GitHub tanpa karakter @.</small>
      </div>
      <button class="btn primary" type="submit">Hubungkan Akun GitHub ↗</button>
    </form>
  <?php endif; ?>

  <div class="flash" style="margin-top:18px">
    <b>Catatan Transparansi GitHub:</b> FairShare mengidentifikasi kontribusi commit Git berdasarkan username dan author yang cocok dengan data profil terdaftar. URL repository ditentukan secara terisolasi pada masing-masing tugas WBS kelompok.
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
