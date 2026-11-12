# LAPORAN IMPLEMENTASI MESIN KONTRIBUSI, FILTERED DELTA & WEI
## FairShare — Collaboration Intelligence & Transparency Engine

---

### A. Ringkasan Perubahan

Dalam tahapan implementasi ini, sistem kontribusi FairShare telah diperbarui secara menyeluruh dari skema prototipe penjumlahan sederhana menjadi **mesin analisis kontribusi kolaboratif berbasis validasi bertingkat (Filtered Delta) dan normalisasi persentase berbobot tugas (Weighted Effort Index / WEI)**.

Pencapaian utama:
1. **Pemisahan Tegas Raw Delta dan Filtered Delta:** Setiap aktivitas terdeteksi kini dicatat mentah sebagai Raw Delta (1.00), namun hanya event yang lolos 14 kriteria validasi FairShare yang memperoleh Filtered Delta (1.00). Event yang ditolak atau belum terverifikasi memperoleh Filtered Delta (0.00) dengan alasan audit eksplisit.
2. **Mesin Kalkulasi WEI Sebenarnya:** Menghapus logika lama yang hanya menjumlahkan delta sampai batas 100. Menggantinya dengan formulasi matematis WEI per tugas dan WEI keseluruhan kelompok berbobot tugas ($Weight_t \in [1, 5]$).
3. **Penyelamatan dan Reklasifikasi Data Lama:** Menyediakan worker CLI berorientasi transaksi (`workers/revalidate_telemetry.php`) dengan mode simulasi aman (`--dry-run`) dan mode eksekusi database (`--apply`) untuk memulihkan record telemetri Google Docs lama berstatus *'Menunggu kalibrasi bobot'*.
4. **Isolasi Catatan Uji Manual:** Catatan simulasi manual dari `actions/add_telemetry.php` tetap dipertahankan untuk pengujian database, tetapi diisolasi secara permanen dari kalkulasi WEI resmi kelompok.
5. **Transparansi UI:** Dashboard, Halaman Detail Tugas (`task_detail.php`), Laporan Audit (`reports.php`), dan Ringkasan Kontribusi (`contributions.php`) telah diperbarui dengan visualisasi indikator audit, alasan filtering, dan persentase WEI.
6. **Kesiapan Integrasi GitHub:** Struktur validator dan formula kontribusi dibuat modular sehingga integrasi GitHub kelak dapat langsung menggunakan mesin penilaian yang sama.

---

### B. Daftar File PHP yang Diubah

1. **`includes/bootstrap.php`**
   - Menghubungkan modul validator (`telemetry_validator.php`) dan mesin kontribusi (`contribution_engine.php`).
   - Mencegah inisialisasi session warning pada runtime CLI.
   - Mengubah fungsi `score_for_user(string $userId, ?string $groupId = null): float` agar menghitung persentase WEI resmi kelompok dari engine baru.
   - Meningkatkan `all_telemetry()` agar mengekstrak status validasi, alasan audit, serta nilai `raw_delta` dan `filtered_delta` secara akurat.

2. **`integrations/google/google_service.php`**
   - Mengintegrasikan fungsi `fs_validate_telemetry_event()` pada `fs_google_sync_task()`.
   - Mengubah nilai hardcoded `filtered_delta = 0` menjadi dinamis (1.00 jika lolos 14 kriteria, 0.00 jika ditolak).
   - Menyimpan metadata validasi lengkap: `validation_status`, `validation_reason`, `validation_version`, `validated_at`, `actor_attributed`, dan `attribution_method`.
   - Menggunakan query `INSERT ... ON CONFLICT (task_id, source, external_id) DO UPDATE` untuk menjamin idempotensi absolut.

3. **`reports.php`**
   - Mengintegrasikan kalkulasi `fs_calculate_group_wei($teamId)` per kelompok tim.
   - Menampilkan kolom: Mahasiswa & NIM, Kelas & Kelompok, Peran, Aktivitas Terdata (Docs & Git), Raw Delta, Filtered Delta, Indikator WEI (%), dan Status Data.
   - Memperbarui ekspor CSV laporan dengan kolom Raw Delta, Filtered Delta, WEI %, dan Status Data.
   - Menambahkan catatan transparansi bahwa WEI adalah indikator aktivitas, bukan penilaian mutu akademik otomatis.

4. **`contributions.php`**
   - Menampilkan kartu ringkasan: Indeks WEI Pengguna, Total Filtered Delta, Total Raw Delta, dan Rincian Status Audit (Valid, Pending, Ditolak, Uji).
   - Menambahkan panel *Perbandingan Kontribusi Anggota Kelompok* untuk membandingkan proporsi kontribusi antar anggota tim.
   - Menampilkan kolom Raw Delta dan Filtered Delta pada tabel riwayat telemetri.
   - Memperjelas panel simulasi uji manual sebagai catatan uji tanpa memengaruhi WEI resmi.

5. **`task_detail.php`**
   - Mengintegrasikan `fs_calculate_task_wei($taskId)` untuk menampilkan distribusi kontribusi tugas per anggota mahasiswa.
   - Menambahkan ringkasan audit tugas: Total Raw Delta, Total Filtered Delta, Event Valid, Event Pending, dan Event Ditolak.
   - Menambahkan kolom Status Validasi, Alasan Tidak Tervalidasi, dan Indikator Kelayakan (✓ Lolos / ✕ Diabaikan) pada tabel riwayat aktivitas.

6. **`dashboard.php`**
   - Memperbarui tampilan kartu *WEI SCORE* agar membaca kalkulasi resmi dari `fs_member_wei_overview()`.
   - Menampilkan *Belum cukup data* saat kelompok belum memiliki aktivitas valid.
   - Menambahkan catatan edukatif mengenai sifat indikator pendukung dari WEI.

7. **`integrations/google/sync.php`**
   - Memperbaiki deklarasi pembuka file PHP agar sesuai standar `declare(strict_types=1)` tanpa spasi pembuka.

---

### C. Daftar File Baru

1. **`includes/telemetry_validator.php`**
   - Fungsi kanonikal `fs_validate_telemetry_event()` yang menguji 14 kriteria integritas aktivitas.
   - Fungsi lookup konteks tugas `fs_fetch_task_validation_context()`.
   - Kamus terjemahan alasan penolakan `fs_validation_reason_text()`.
   - Helper validasi format UUID v4 `fs_is_valid_uuid()`.

2. **`includes/contribution_engine.php`**
   - Implementasi matematis resmi Weighted Effort Index (WEI).
   - Fungsi `fs_calculate_group_wei()` untuk menghitung WEI seluruh anggota kelompok berbasis bobot tugas.
   - Fungsi `fs_calculate_task_wei()` untuk menghitung kontribusi tugas spesifik.
   - Fungsi `fs_member_wei_overview()` untuk menghitung ringkasan kontribusi mahasiswa lintas kelompok.
   - Fungsi murni independen database `fs_compute_wei_from_dataset()` untuk eksekusi kalkulasi deterministik.

3. **`workers/revalidate_telemetry.php`**
   - Worker baris perintah (CLI) untuk memeriksa dan memperbarui record telemetri lama.
   - Mendukung argumen `--dry-run` (simulasi aman) dan `--apply` (eksekusi dalam transaksi database dengan auto-rollback).

4. **`database/migrations/003_telemetry_validation_and_wei.sql`**
   - Skrip migration SQL aman dan non-destruktif untuk menambahkan index performa pada metadata JSONB dan foreign key telemetri.
   - Disediakan terpisah untuk ditinjau dan dieksekusi secara manual oleh administrator database.

5. **`tests/WeiContributionEngineTest.php`**
   - Test suite otomatis mencakup 48 skenario unit testing, termasuk verifikasi skenario master Bagian 7.D, isolasi kelompok, zero denominator, dan 14 kriteria validasi.

6. **`docs/CONTRIBUTION_SCORING_METHODOLOGY.md`**
   - Dokumentasi lengkap metodologi penilaian kontribusi, definisi Raw/Filtered Delta, rumus WEI, dan panduan audit.

7. **`docs/IMPLEMENTATION_REPORT_CONTRIBUTION_ENGINE.md`**
   - Dokumen laporan implementasi ini.

---

### D. Perubahan Database dan Migration

- **Prinsip Utama:** Tidak ada perubahan destruktif pada struktur tabel yang ada. Lima tabel inti (`users`, `groups`, `group_members`, `tasks`, `telemetry_logs`) dipertahankan sepenuhnya.
- **Pemanfaatan Kolom:** Seluruh detail audit dan validasi disimpan ke dalam kolom `public.telemetry_logs.metadata` bertipe `JSONB` yang sudah tersedia di skema inti.
- **Migration SQL 003 (`database/migrations/003_telemetry_validation_and_wei.sql`):**
  - Bersifat aditif (`CREATE INDEX IF NOT EXISTS`).
  - Menambahkan index pada `((metadata->>'validation_status'))` untuk akselerasi query audit.
  - Menambahkan index komposit `(group_id, task_id, user_id)` untuk mempercepat kalkulasi WEI.
  - Menambahkan index `(source, external_id)` untuk integritas idempotensi.
  - *Catatan: File ini disiapkan untuk ditinjau dan dieksekusi manual oleh pemilik database.*

---

### E. Rumus Raw Delta

$$\text{Raw Delta} = 1.00 \quad \text{per detected substantive action (edit / create)}$$

- Nilai mentah yang mencerminkan jejak digital aktivitas teramati sebelum validasi.
- Nilai tidak berubah dan tidak dimodifikasi untuk kepentingan rumus.

---

### F. Rumus Filtered Delta

$$\text{Filtered Delta} = \begin{cases} 1.00 & \text{jika lolos ke-14 kriteria validasi FairShare} \\ 0.00 & \text{jika gagal pada salah satu kriteria validasi} \end{cases}$$

Setiap event dengan Filtered Delta 0.00 menyimpan kode alasan pada `metadata->>'validation_reason'`.

---

### G. Rumus Weighted Effort Index (WEI)

1. **Skor Tugas Mahasiswa:**
   $$MemberTaskScore(i, t) = \sum_{e \in ValidEvents(i, t)} filtered\_delta(e)$$

2. **Kontribusi Tugas Relatif ($WEI_{i, t}$):**
   $$WEI_{i, t} = \left(\frac{MemberTaskScore(i, t)}{\sum_{m \in Group} MemberTaskScore(m, t)}\right) \times 100\%$$

3. **WEI Keseluruhan Kelompok ($WEI_i$):**
   $$WEI_i = \frac{\sum_{t \in EvaluatedTasks} \left(Weight_t \times WEI_{i, t}\right)}{\sum_{t \in EvaluatedTasks} Weight_t}$$
   *(Hanya memperhitungkan tugas dengan aktivitas valid dan bobot positif).*

---

### H. Contoh Hasil Pengujian (Skenario Bagian 7.D)

Dijalankan melalui `tests/WeiContributionEngineTest.php`:

- **Konfigurasi Kelompok:** 4 Mahasiswa (A, B, C, D)
- **Tugas 1 (Bobot 2):** A = 10, B = 5, C = 0, D = 0 (Total = 15)
  - Hasil Tugas 1: A = 66.6667%, B = 33.3333%, C = 0.0000%, D = 0.0000%
- **Tugas 2 (Bobot 1):** A = 0, B = 0, C = 10, D = 0 (Total = 10)
  - Hasil Tugas 2: A = 0.0000%, B = 0.0000%, C = 100.0000%, D = 0.0000%
- **Kompilasi Akhir Terbobot (Weight 2 : 1):**
  - **Mahasiswa A:** $\mathbf{44.4444\%}$ `[PASS]`
  - **Mahasiswa B:** $\mathbf{22.2222\%}$ `[PASS]`
  - **Mahasiswa C:** $\mathbf{33.3333\%}$ `[PASS]`
  - **Mahasiswa D:** $\mathbf{0.0000\%}$ `[PASS]`
  - **Total Akumulasi WEI:** $\mathbf{100.0000\%}$ `[PASS]`

---

### I. Hasil Testing PASS / FAIL / NOT TESTED

| Kategori Pengujian | Deskripsi Kasus Uji | Status |
|---|---|:---:|
| **WEI Algoritma** | Skenario Master Bagian 7.D (A=44.4%, B=22.2%, C=33.3%, D=0%) | **PASS** |
| **WEI Algoritma** | Total seluruh anggota kelompok terakumulasi 100% | **PASS** |
| **WEI Algoritma** | Anggota tunggal dengan seluruh aktivitas valid memperoleh WEI 100% | **PASS** |
| **WEI Algoritma** | Dua anggota dengan aktivitas seimbang memperoleh 50% masing-masing | **PASS** |
| **WEI Algoritma** | Anggota pasif memperoleh WEI 0% saat kelompok memiliki data valid | **PASS** |
| **WEI Algoritma** | Kelompok tanpa aktivitas valid menghasilkan status *Belum Cukup Data* | **PASS** |
| **WEI Algoritma** | Pembagian dengan nol (zero denominator) tertangani secara aman | **PASS** |
| **Isolasi Data** | Event manual test (`entry_mode = 'manual_test'`) diabaikan dari WEI | **PASS** |
| **Isolasi Data** | Event berstatus pending dan rejected diabaikan dari WEI | **PASS** |
| **Isolasi Data** | Data kelompok lain tidak memengaruhi penilaian kelompok target | **PASS** |
| **Validasi Event** | Aksi Google Docs edit terverifikasi menghasilkan Filtered Delta 1.00 | **PASS** |
| **Validasi Event** | Aksi Google Docs create terverifikasi menghasilkan Filtered Delta 1.00 | **PASS** |
| **Validasi Event** | Aktor tanpa verifikasi OAuth ditolak (`actor_not_verified`) | **PASS** |
| **Validasi Event** | Event dari dokumen berbeda ditolak (`wrong_document`) | **PASS** |
| **Validasi Event** | Pengguna di luar kelompok tim ditolak (`user_not_group_member`) | **PASS** |
| **Validasi Event** | Waktu aktivitas mendahului waktu server ditolak (`future_timestamp`) | **PASS** |
| **Validasi Event** | Format timestamp tidak valid ditolak (`invalid_timestamp`) | **PASS** |
| **Validasi Event** | Event dengan Task ID invalid ditolak (`invalid_task`) | **PASS** |
| **Validasi Event** | Event duplikat ditolak (`duplicate_event`) | **PASS** |
| **Idempotency** | Penguncian SHA-256 external event ID stabil | **PASS** |
| **Sintaksis PHP** | Seluruh 47 file PHP dalam workspace lolos `php -l` dengan 0 error | **PASS** |
| **Worker CLI** | Parameter handling `--dry-run` dan penanganan transaksi aman | **PASS** |
| **Integrasi Eksternal** | Penarikan live Google Docs API ke server Supabase produksi | **NOT TESTED** *(Koneksi jaringan remote dibatasi lingkungan sandbox; kode dan unit test telah diverifikasi)* |

---

### J. Cara Menjalankan Revalidation Dry-Run

Untuk melihat pratinjau record telemetri lama yang memenuhi kriteria tanpa mengubah isi database:

```powershell
php workers/revalidate_telemetry.php --dry-run
```

*(Gunakan path executable PHP lokal seperti `C:\xampp\php\php.exe` jika variabel environment `php` belum terdaftar pada PowerShell).*

Output dry-run akan menampilkan rekapitulasi:
- Total record Google Docs yang diperiksa.
- Jumlah record yang sudah valid sebelumnya.
- Jumlah record yang lolos kriteria dan akan diterima (`filtered_delta` akan diubah menjadi 1.00).
- Jumlah record yang ditolak beserta rincian alasannya.
- Jumlah catatan uji manual yang dipertahankan.

---

### K. Cara Menerapkan Revalidation Apabila Hasil Dry-Run Sudah Benar

Setelah data pratinjau diperiksa dan dipastikan sesuai, terapkan perubahan secara permanen ke basis data dengan perintah:

```powershell
php workers/revalidate_telemetry.php --apply
```

**Mekanisme Keamanan:**
- Skrip berjalan di dalam transaksi database PostgreSQL (`BEGIN ... COMMIT`).
- Jika terjadi kegagalan jaringan atau query error, skrip secara otomatis menjalankan `ROLLBACK` penuh sehingga tidak ada data yang tersimpan separuh jalan.

---

### L. Risiko dan Pekerjaan yang Masih Tersisa

1. **Jaringan Eksternal Supabase:**
   - Karena batasan isolasi sandbox lokal, eksekusi live ke endpoint Supabase (`aws-0-ap-southeast-2.pooler.supabase.com`) memerlukan koneksi jaringan aktif dan peninjauan langsung oleh administrator.
2. **Review Eksekusi Migration 003:**
   - Administrator disarankan mengeksekusi file `database/migrations/003_telemetry_validation_and_wei.sql` di Supabase SQL Editor untuk mengoptimalkan performa index pencarian status validasi pada kelompok besar.
3. **Pengembangan Modul GitHub Selanjutnya:**
   - Arsitektur validasi dan engine WEI telah siap menerima sumber `github`. Tahap berikutnya adalah melengkapi handler webhook / API fetcher GitHub pada direktori `integrations/github/`.
