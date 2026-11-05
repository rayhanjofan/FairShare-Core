# FairShare Smart Assignment & Google Docs Integration

Dokumen ini menjelaskan konfigurasi tahap Smart Assignment pada FairShare. Integrasi Google Docs menggunakan OAuth akun tiap anggota, Drive API untuk memverifikasi dokumen, dan Drive Activity API untuk membaca aktivitas yang tersedia.

## 1. Ruang lingkup tahap ini

Sudah disiapkan pada source code:

- Dosen membuat tugas manual, Google Docs, atau GitHub.
- Tugas untuk banyak kelompok dibuat sebagai satu record per kelompok dan dihubungkan melalui `assignment_batch_id`.
- Project Manager atau dosen pengampu dapat menghubungkan resource pada detail tugas.
- URL Google Docs diverifikasi melalui Drive API dan harus berupa Google Docs.
- Akun Google anggota disambungkan melalui OAuth 2.0.
- Token disimpan terenkripsi menggunakan AES-256-GCM.
- Sinkronisasi manual mengambil aktivitas yang dapat dikaitkan dengan anggota melalui `isCurrentUser`.
- Aktivitas disimpan pada `telemetry_logs` dan ditautkan ke `task_id`.
- Worker CLI tersedia untuk sinkronisasi berkala di komputer lokal.

GitHub untuk saat ini baru mendukung pemilihan jenis tugas dan penyimpanan link repository. Pengambilan webhook/commit GitHub belum diimplementasikan pada tahap ini. Jangan menandai GitHub sebagai otomatis tersinkronisasi.

## 2. Migration database

File migration baru:

`data/migrations/002_smart_assignment_google.sql`

Jalankan secara manual di Supabase SQL Editor setelah memastikan migration ini belum pernah diterapkan. File ini menambahkan metadata Smart Assignment ke `tasks`, `task_id` ke `telemetry_logs`, index idempotency, dan tabel pendukung `user_integrations`.

Jangan menjalankan ulang `data/schema.sql` pada database aktif. `schema.sql` hanya referensi untuk instalasi database baru.

Setelah migration, verifikasi:

```sql
SELECT column_name, data_type, column_default
FROM information_schema.columns
WHERE table_schema = 'public' AND table_name = 'tasks'
  AND column_name IN (
    'task_type', 'due_at', 'assignment_batch_id', 'resource_url',
    'external_resource_id', 'resource_title', 'sync_status',
    'last_synced_at', 'sync_state', 'sync_error'
  )
ORDER BY ordinal_position;

SELECT column_name, data_type
FROM information_schema.columns
WHERE table_schema = 'public' AND table_name = 'telemetry_logs'
  AND column_name = 'task_id';

SELECT to_regclass('public.user_integrations') AS user_integrations_table;

SELECT indexname
FROM pg_indexes
WHERE schemaname = 'public'
  AND indexname = 'uq_telemetry_task_source_external';
```

## 3. Persiapan Google Cloud

1. Buka Google Cloud Console dan buat/pilih project khusus FairShare.
2. Aktifkan Google Drive API.
3. Aktifkan Google Drive Activity API.
4. Buka Google Auth Platform / OAuth consent screen.
5. Pilih tipe pengguna External bila mahasiswa menggunakan akun Google umum dari luar organisasi.
6. Isi nama aplikasi, email dukungan, dan email kontak developer.
7. Tambahkan akun anggota tim sebagai Test Users selama pengembangan.
8. Buat OAuth Client ID dengan application type **Web application**.
9. Tambahkan Authorized redirect URI persis seperti nilai `GOOGLE_REDIRECT_URI` pada `data/.env`.

Contoh untuk server lokal:

`http://127.0.0.1:8000/integrations/google/callback.php`

Gunakan host yang sama saat membuka aplikasi. Jangan berganti-ganti antara `localhost` dan `127.0.0.1` kecuali keduanya didaftarkan sebagai redirect URI.

### Perhatian cakupan OAuth

Integrasi saat ini meminta:

- `openid`
- `email`
- `profile`
- `https://www.googleapis.com/auth/drive.activity.readonly`
- `https://www.googleapis.com/auth/drive.metadata.readonly`

Google mengategorikan `drive.activity.readonly` dan `drive.metadata.readonly` sebagai restricted scopes. Untuk pengujian akademik, gunakan status Testing dan masukkan akun penguji pada Test Users. Pengguna akan melihat layar peringatan aplikasi belum diverifikasi. Untuk aplikasi eksternal yang dipublikasikan, scope restricted memerlukan proses verifikasi Google; penyimpanan/pemrosesan data restricted pada server dapat memerlukan security assessment. Tinjau kebijakan dan kebutuhan verifikasi sebelum deployment publik.

Pada status OAuth Testing untuk aplikasi External yang meminta scope selain identitas dasar, authorization dan refresh token dapat kedaluwarsa setelah 7 hari. Karena itu akun penguji mungkin perlu menghubungkan ulang Google secara berkala selama aplikasi masih berstatus Testing.

## 4. Konfigurasi data/.env

Salin `data/.env.example` menjadi `data/.env`, lalu isi:

```env
DB_HOST=host-pooler-supabase
DB_PORT=5432
DB_NAME=postgres
DB_USER=username-database
DB_PASSWORD=password-database

GOOGLE_CLIENT_ID=client-id-dari-google
GOOGLE_CLIENT_SECRET=client-secret-dari-google
GOOGLE_REDIRECT_URI=http://127.0.0.1:8000/integrations/google/callback.php
APP_ENCRYPTION_KEY=nilai-random-rahasia-minimal-32-karakter
APP_BASE_URL=http://127.0.0.1:8000
```

Buat encryption key acak menggunakan PHP CLI:

```powershell
C:\xampp\php\php.exe -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

Salin hasilnya ke `APP_ENCRYPTION_KEY`. Simpan nilai ini secara permanen dan rahasia. Jika key berubah, token yang sudah terenkripsi tidak dapat dibuka kembali dan pengguna harus menghubungkan akun Google ulang.

Jangan commit atau membagikan `data/.env`. Jangan masukkan client secret atau encryption key ke source code.

## 5. Hubungkan akun Google

1. Jalankan FairShare secara lokal.
2. Login sebagai mahasiswa atau dosen yang perlu menghubungkan Google.
3. Buka menu **Integrasi Akun**.
4. Tekan **Hubungkan akun Google**.
5. Login dan setujui permintaan akses.
6. Setelah kembali ke FairShare, pastikan status menunjukkan Terhubung.

Setiap anggota yang aktivitasnya ingin dipetakan perlu menghubungkan akun Google miliknya masing-masing. Akun yang terhubung harus mempunyai akses ke dokumen tugas kelompok.

Untuk dokumen kelompok, bagikan Google Docs kepada anggota yang mengerjakan. Project Manager (atau dosen pengampu) membuka detail tugas lalu menempelkan URL Google Docs. Sistem akan memverifikasi bahwa file dapat diakses oleh akun Google yang sedang digunakan dan bahwa MIME type file adalah Google Docs.

## 6. Sinkronisasi aktivitas Google Docs

Pada halaman detail tugas:

1. Hubungkan dokumen Google Docs.
2. Pastikan anggota kelompok sudah menghubungkan akun Google masing-masing.
3. Pastikan dokumen dibagikan kepada akun Google anggota.
4. Tekan **Sinkronkan aktivitas Google Docs sekarang**.
5. Periksa tabel Riwayat Aktivitas Tugas.

Sinkronisasi menanyakan aktivitas dokumen melalui Drive Activity API untuk setiap akun anggota yang terhubung. Implementasi hanya mengatribusikan action ketika Google menandai aktor sebagai `knownUser.isCurrentUser = true` pada konteks akun yang sedang digunakan. Event yang tidak bisa diidentifikasi tidak diberi `user_id` dan tidak boleh diberi skor WEI.

Drive Activity API memberikan event aktivitas yang tersedia, bukan rekaman setiap tombol keyboard atau setiap karakter yang diketik. FairShare hanya boleh menyebut metrik ini sebagai aktivitas dokumen yang teramati.

## 7. Sinkronisasi berkala di Windows

Di localhost, Google tidak dapat mengirim callback webhook ke `127.0.0.1` atau `localhost`. Untuk pengembangan, gunakan tombol sinkronisasi manual atau Windows Task Scheduler.

Script worker:

`workers/sync_google.php`

Contoh konfigurasi Windows Task Scheduler:

- Program/script: `C:\xampp\php\php.exe`
- Add arguments: `"C:\FairShare-Core\FairShare-Core\workers\sync_google.php"`
- Start in: `C:\FairShare-Core\FairShare-Core`
- Trigger: ulangi setiap 1–5 menit saat komputer aktif.

Script memiliki lock untuk menghindari dua worker berjalan bersamaan. Ubah path sesuai lokasi proyek di komputer masing-masing.

Sinkronisasi berkala bukan jaminan data muncul tepat pada detik dokumen berubah. Ada jeda pemrosesan API dan interval worker.

## 8. GitHub pada tahap ini

Dosen dapat membuat tugas bertipe GitHub dan Project Manager dapat menyimpan URL repository. Source code memvalidasi format `https://github.com/owner/repository`.

Namun penerimaan webhook `push`, pemeriksaan `X-Hub-Signature-256`, pengambilan commit REST API, pemetaan author, dan penyimpanan event GitHub belum diaktifkan pada tahap ini. UI harus tetap menunjukkan GitHub belum tersinkronisasi otomatis.

## 9. Pemeriksaan dan troubleshooting

- Jika tombol Google tidak aktif: periksa empat variabel Google pada `data/.env`.
- Jika `redirect_uri_mismatch`: samakan URL callback di Google Cloud dengan `GOOGLE_REDIRECT_URI`.
- Jika akses ditolak: pastikan akun ditambahkan sebagai Test User, API diaktifkan, dan dokumen dibagikan kepada akun Google yang terhubung.
- Jika token tidak dapat didekripsi: pastikan `APP_ENCRYPTION_KEY` sama dengan saat token disimpan.
- Jika refresh token tidak tersedia: putuskan akses FairShare pada akun Google, lalu hubungkan ulang dengan akses offline/consent.
- Jika aktivitas tidak muncul: pastikan anggota memiliki akses ke dokumen, akun Google mereka terhubung, migration sudah diterapkan, dan lakukan sinkronisasi ulang.
- Jika sebagian anggota belum menghubungkan Google: status sinkronisasi dapat menjadi partial.

Lihat PHP error log untuk detail teknis. Jangan menampilkan access token, refresh token, client secret, atau encryption key di browser maupun log.
