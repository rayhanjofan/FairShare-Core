<<<<<<< HEAD
# FairShare-Core

FairShare adalah aplikasi pemantauan kontribusi dan transparansi kerja kolaboratif. Aplikasi lokal ini menggunakan PHP native, PDO, PostgreSQL, dan aset CSS/JavaScript yang disertakan di repositori.

## Fitur yang tersedia

- Setup akun pertama, login, logout, session, password hashing, dan CSRF token.
- Dashboard metrik dari database.
- CRUD pengguna.
- CRUD kelompok/proyek dan pengelolaan anggota kelompok.
- CRUD tugas, penugasan anggota, status, bobot, dan target minggu.
- Daftar dan filter log telemetry yang sudah tersimpan.
- Ringkasan kontribusi/WEI dengan rumus sementara yang transparan.
- Halaman pemeriksaan runtime dan koneksi database di `/healthcheck`.

Integrasi live GitHub, Google Docs API, notifikasi, dan ekspor PDF belum diaktifkan oleh build lokal ini. Halaman telemetry membaca log yang sudah tersimpan di tabel `telemetry_logs`.

## 1. Prasyarat Windows

- PHP CLI 8.1+; untuk instalasi XAMPP biasanya tersedia di `C:\xampp\php\php.exe`.
- Ekstensi PHP `pdo_pgsql` aktif.
- PostgreSQL berjalan dan database FairShare yang sudah ada dapat diakses.
- DBeaver dapat digunakan untuk memeriksa database.

Periksa PHP yang akan dipakai untuk menjalankan server:

```cmd
C:\xampp\php\php.exe -v
C:\xampp\php\php.exe -m | findstr /I "PDO pgsql"
```

Output ideal mencakup `PDO` dan `pdo_pgsql`. Jika `pdo_pgsql` tidak muncul, buka file `C:\xampp\php\php.ini`, cari baris `;extension=pdo_pgsql` dan `;extension=pgsql`, aktifkan baris yang tersedia dengan menghapus titik koma di depannya, lalu buka terminal baru. Jika DLL extension tidak tersedia pada instalasi PHP, pasang distribusi PHP/XAMPP yang sesuai dengan versi dan arsitektur PHP yang dipakai.

## 2. Konfigurasi database lokal

Dari root proyek ini, jalankan di PowerShell:

```powershell
Copy-Item .env.example .env
```

Jika `.env` sudah ada, jangan timpa; edit file tersebut. Isi dengan detail koneksi yang sama seperti DBeaver:

```dotenv
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=nama_database_persis_seperti_di_DBeaver
DB_USERNAME=username_postgresql
DB_PASSWORD=password_postgresql
APP_ENV=development
APP_DEBUG=true
APP_URL=http://127.0.0.1:8000
```

Nama database PostgreSQL harus persis sama dengan nama database di DBeaver. Jika nama database dibuat menggunakan tanda kutip dan kapitalisasi tertentu, gunakan nama dengan kapitalisasi yang sama. Jangan masukkan isi `.env` ke Git atau membagikannya dengan password aktif.

**Jangan jalankan ulang migration terhadap database yang sudah berisi tabel.** Aplikasi ini membaca tabel yang sudah ada (`users`, `groups`, `group_members`, `tasks`, `telemetry_logs`) sesuai `database/migrations/001_initial_schema.sql`.

## 3. Jalankan server lokal

Di terminal, pastikan current directory adalah root proyek yang berisi `apps`, `database`, `.env.example`, dan `README.md`. Jalankan:

```cmd
C:\xampp\php\php.exe -S 127.0.0.1:8000 -t apps/public
```

Biarkan terminal tetap terbuka selama aplikasi digunakan. Buka browser:

- `http://127.0.0.1:8000/` — aplikasi
- `http://127.0.0.1:8000/healthcheck` — status PHP dan PostgreSQL

Jika perintah `php` sudah tersedia melalui PATH, perintah di atas dapat disingkat menjadi:

```cmd
php -S 127.0.0.1:8000 -t apps/public
```

## 4. Setup akun pertama

Jika tabel `users` belum memiliki akun, buka `/setup`. Isi NIM/NIP, nama, email, peran, dan password minimal 8 karakter. Password disimpan menggunakan `password_hash()`. Jika akun sudah ada, halaman setup akan dialihkan ke login.

Setelah masuk, buat kelompok, tambahkan anggota, lalu buat dan tugaskan pekerjaan. Tabel telemetry yang kosong akan menampilkan empty state; data GitHub/Google Docs asli tidak dibuat otomatis.

## 5. Rumus WEI sementara

Tidak ditemukan rumus WEI formal yang dinyatakan sebagai formula final di migration SQL. Karena itu, untuk build lokal ini WEI ditandai sebagai **rumus sementara**:

`Skor anggota = jumlah bobot tugas berstatus done + jumlah filtered_delta dari telemetry anggota dalam kelompok tersebut.`

`Persentase WEI = skor anggota / total skor semua anggota kelompok × 100%`.

Log telemetry yang tidak terhubung dengan `user_id` tidak masuk ke skor individu. Bobot tugas hanya masuk setelah status tugas `done`. Formula ini dapat dikalibrasi kembali sebelum digunakan untuk penilaian resmi karena bobot tugas dan telemetry memiliki satuan yang berbeda.

## 6. Troubleshooting

### `php is not recognized`
Gunakan executable PHP lengkap, contohnya `C:\xampp\php\php.exe`.

### `could not find driver` atau `pdo_pgsql tidak ditemukan`
Aktifkan ekstensi `pdo_pgsql` pada `php.ini` dari executable PHP yang sama dengan yang dipakai untuk server. Cek melalui `C:\xampp\php\php.exe -m`.

### `Database connection failed`
Buka `/healthcheck`, periksa `DB_HOST`, `DB_PORT`, nama database, username, password, dan apakah server PostgreSQL sedang berjalan. Jangan mengirim file `.env` berisi kredensial ke pihak lain.

### `View template not found` atau `Controller handler is missing`
Periksa bahwa folder controller/view ada di bawah `apps/src`. Build ini menggunakan `apps/src/Controllers` dan `apps/src/views` dengan huruf besar/kecil seperti pada repositori.

### Port 8000 sudah digunakan
Jalankan server dengan port lain, misalnya `-S 127.0.0.1:8001`, lalu akses `http://127.0.0.1:8001`.

## 7. Validasi kode

Sintaks PHP dapat diperiksa dari root proyek dengan:

```powershell
Get-ChildItem .\apps -Recurse -Filter *.php | ForEach-Object { & 'C:\xampp\php\php.exe' -l $_.FullName }
```

Pemeriksaan sintaks tidak menggantikan pengujian koneksi database atau pengujian interaksi melalui browser.
=======
# FairShare

Aplikasi PHP Native untuk pemantauan kontribusi dan audit transparansi kerja kolaboratif. UI terinspirasi rancangan pada proposal PBL FairShare.

## Jalankan di XAMPP
1. Ekstrak folder `FairShare` ke `C:\xampp\htdocs\FairShare`.
2. Pastikan Apache berjalan. Versi ini menyimpan data lokal dalam JSON, jadi MySQL tidak diperlukan.
3. Buka `http://localhost/FairShare/`.
4. Akun demo: `demo@fairshare.local` / `demo12345`.

## Fitur yang aktif
- Login dan registrasi, password di-hash, CSRF token, session regeneration.
- Dashboard statistik dari data lokal dan riwayat aktivitas.
- Buat kelompok, gabung dengan ID, lihat anggota dan salin ID.
- CRUD dasar WBS: tambah tugas, filter status, ubah status tugas.
- Riwayat kontribusi, pencarian dan ekspor CSV.
- Audit log dan ekspor CSV.
- Ringkasan laporan kontribusi dan ekspor CSV.
- Pengaturan profil, ganti kata sandi dan preferensi.
- Layout responsif untuk desktop/tablet/mobile.

## Batasan yang dinyatakan
- GitHub dan Google Workspace belum terhubung; perlu OAuth/token dan konfigurasi API.
- Grafik tren merupakan ilustrasi UI sampai ada data historis yang cukup.
- Skor WEI di versi ini merupakan skor awal berbasis jumlah poin telemetri lokal, bukan implementasi final rumus proposal.
- PDF dan QR verifikasi belum diaktifkan; halaman laporan dapat mengekspor CSV.

## Data demo
Data awal diletakkan di `data/seed.json`. Saat instalasi, jalankan `setup.php` sekali untuk membuat data demo. Setelah setup berhasil, hapus `setup.php` dari folder web.


## Pembaruan kelompok dan dosen
- Mahasiswa dapat membuat kelompok, membagikan kode undangan, dan bergabung menggunakan kode tersebut.
- Ketua kelompok dapat menyalin kode undangan dari halaman Tim & Kelompok.
- Akun dengan role `dosen` atau `admin` mendapatkan menu Monitor Kelas dan dapat melihat seluruh kelompok pada instalasi ini.
- Akun mahasiswa hanya mendapatkan kelompok tempat akun tersebut terdaftar pada halaman Tim & Kelompok, serta log audit personal pada halaman Audit.
- Role pendaftaran baru selalu `mahasiswa`. Untuk mengubah akun uji menjadi dosen, ubah field `role` menjadi `dosen` pada `data/users.json` setelah akun dibuat, lalu logout dan login kembali.
- Data demo diinisialisasi oleh `setup.php` dari `data/seed.json`. Gunakan setup hanya untuk instalasi baru; jangan jalankan ulang pada data yang ingin dipertahankan.

## Catatan penting
Paket ini menggunakan penyimpanan JSON lokal untuk memudahkan uji coba XAMPP. Untuk penggunaan banyak pengguna secara serentak, pindahkan penyimpanan ke MySQL/MariaDB dan terapkan pembatasan dosen berdasarkan kelas yang diampu. Integrasi GitHub live belum aktif sampai GitHub App/OAuth dan izin repository dikonfigurasi; data grafik yang masih ditandai ilustratif bukan data commit nyata.


## Logo referensi
`assets/fairshare-logo.svg` sekarang menggunakan bentuk logo referensi yang diberikan, dengan latar transparan untuk penggunaan pada tema gelap/terang.

## Perbaikan login/registrasi untuk Windows XAMPP
1. Timpa file project dengan versi ini setelah backup.
2. Buka `http://localhost/FairShare/repair_local.php`, ketik `FIX-LOCAL`, lalu submit.
3. Setelah berhasil, HAPUS `repair_local.php`.
4. Login dengan `demo@fairshare.local` / `demo12345`. Link pendaftaran tersedia di bawah formulir login.
5. Jika registrasi gagal menulis file, cek izin tulis folder `FairShare/data`.

**Database:** versi ini BELUM memakai MySQL. Akun, kelompok, tugas, dan aktivitas disimpan di file JSON dalam `data/`. Perbaikan ini tidak mengubahnya menjadi database MySQL.


## Supabase PostgreSQL (login dan registrasi)
1. Aktifkan `extension=pdo_pgsql` di `C:\xampp\php\php.ini` (hapus `;` di depannya), simpan, lalu restart Apache. Pastikan `PDO` dan `pdo_pgsql` tampil di `phpinfo()`.
2. Buka Supabase → SQL Editor, jalankan isi `data/schema.sql`.
3. Isi `data/.env` dengan host, port, database, username, dan password database dari Supabase. Jangan gunakan tanda kurung siku di sekitar password kecuali memang bagian dari password. Jangan unggah `.env` ke GitHub.
4. Login dan registrasi akan memakai PostgreSQL jika koneksi berhasil. Akun lama di `data/users.json` tetap dapat login untuk kompatibilitas. Fitur data kelompok/tugas/telemetri yang lain masih memakai JSON lokal; migrasi seluruh modul belum dilakukan.
5. Karena password database pernah ditempel ke percakapan, ganti/reset password database di Supabase lalu perbarui `DB_PASSWORD`.
>>>>>>> de456e9156d3f19876d656c4651db31f96587d50
