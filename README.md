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
