# FairShare — PHP Native + PostgreSQL/Supabase

Versi ini membaca dan menyimpan data inti FairShare pada PostgreSQL/Supabase: `users`, `groups`, `group_members`, `tasks`, dan `telemetry_logs`. File JSON tidak lagi menjadi sumber data akun, kelompok, tugas, atau telemetri. Catatan aktivitas antarmuka dan preferensi tampilan masih lokal karena rancangan lima tabel tidak memiliki tabel khusus untuk data tersebut.

## 1. Persiapan XAMPP

1. Ekstrak folder `FairShare` ke `C:\xampp\htdocs\FairShare`.
2. Buka `C:\xampp\php\php.ini`; pastikan ekstensi `pdo_pgsql` aktif (baris `extension=pdo_pgsql`, tanpa titik koma di depannya). Jika tersedia, aktifkan juga `pgsql`.
3. Restart Apache dari XAMPP Control Panel setelah mengubah `php.ini`.
4. Buka terminal dan jalankan `C:\xampp\php\php.exe -m`; pastikan terlihat `PDO` dan `pdo_pgsql`.

## 2. Konfigurasi Supabase

1. Dari folder `FairShare\data`, salin `.env.example` menjadi `.env`.
2. Pada Supabase, pilih **Connect → Session Pooler**. Salin host, port, database, dan format username yang disediakan project.
3. Isi nilai di `data/.env`:

```dotenv
DB_HOST=host_pooler_dari_supabase
DB_PORT=5432
DB_NAME=postgres
DB_USER=fairshare_app.project_ref
DB_PASSWORD=password_role_database
```

`DB_USER` harus memakai nama role PostgreSQL yang benar dan project reference sesuai petunjuk Session Pooler. Password di atas adalah password role database, bukan password akun dashboard Supabase.

**Jangan commit atau membagikan `data/.env`.** Gunakan akun database aplikasi dengan izin minimum; jangan gunakan kredensial admin `postgres` di repository.

## 3. Izin role aplikasi

Jalankan melalui SQL Editor Supabase dengan akun yang berwenang, setelah role `fairshare_app` dibuat dan passwordnya disetel. Jika kamu sudah memiliki role dengan nama lain, ganti nama role pada query ini:

```sql
GRANT CONNECT ON DATABASE postgres TO fairshare_app;
GRANT USAGE ON SCHEMA public TO fairshare_app;
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO fairshare_app;
```

Jika role belum ada, buat sekali melalui SQL Editor dengan password pilihanmu:

```sql
CREATE ROLE fairshare_app WITH LOGIN PASSWORD 'GANTI_DENGAN_PASSWORD_KUAT';
```

Jika role sudah ada, jangan jalankan CREATE ROLE lagi; pastikan password role diketahui atau reset terlebih dahulu. Setelah role dibuat, kamu juga dapat menjalankan `data/supabase_permissions.sql` melalui SQL Editor. Role harus memperoleh izin untuk operasi CRUD. Jangan berikan hak superuser kepada aplikasi.

## 4. Jalankan aplikasi lokal

### Menggunakan Apache XAMPP

Buka `http://localhost/FairShare/healthcheck.php`. Targetnya `Status Supabase: CONNECTED`. Setelah itu buka `http://localhost/FairShare/`.

### Menggunakan PHP built-in server

Dari folder project `FairShare`, jalankan:

```powershell
C:\xampp\php\php.exe -S 127.0.0.1:8000
```

Kemudian buka `http://127.0.0.1:8000/healthcheck.php`.

## 5. Tes alur data bersama

1. Buka `register.php` dan buat akun baru dengan nama, NIM/NIP, email, dan password minimal 8 karakter. Akun disimpan ke `public.users`.
2. Login dengan akun tersebut.
3. Buka **Tim & Kelompok**, buat kelompok. Data akan masuk ke `public.groups` dan `public.group_members`.
4. Salin ID UUID kelompok sebagai kode undangan untuk anggota kedua. Anggota kedua dapat bergabung dari laptopnya menggunakan ID itu.
5. Buka **WBS & Tugas**, buat tugas dan ubah status. Data akan tersimpan di `public.tasks`.
6. Buka **Kontribusi Saya**, tambahkan catatan telemetri uji. Catatan akan masuk ke `public.telemetry_logs` dan ditandai belum diverifikasi. Ini hanya menguji penyimpanan, bukan integrasi API otomatis.
7. Periksa data dari DBeaver dengan koneksi `FairShare - Supabase`. Uji dengan dua perangkat/akun untuk memastikan data yang sama terlihat.

## 6. Struktur database

Tabel yang digunakan:

- `users` — akun serta identitas.
- `groups` — kelompok/proyek.
- `group_members` — hubungan anggota-kelompok.
- `tasks` — WBS, bobot, status, dan target minggu.
- `telemetry_logs` — aktivitas GitHub/Google Docs.

`data/schema.sql` adalah referensi schema untuk database kosong saja. Database Supabase tim sudah berisi tabel, jadi **jangan menjalankan ulang schema tanpa terlebih dahulu memeriksa kolom aktual**. Tidak ada `DROP` atau `TRUNCATE` pada file referensi.

## 7. Batasan yang masih ada

- Sinkronisasi live GitHub API/webhook belum aktif.
- Integrasi Google Docs/Google Drive API belum aktif.
- Catatan telemetri dari UI adalah data uji manual yang jelas ditandai, bukan commit/revisi yang diambil otomatis.
- Skor pada dashboard saat ini adalah indikator awal berbasis total `filtered_delta`, dibatasi 100; ini **bukan formula WEI final**.
- Laporan saat ini dapat diekspor sebagai CSV; generator PDF dengan QR verifikasi masih perlu dikembangkan.
- Catatan aktivitas antarmuka dan preferensi masih tersimpan lokal karena schema yang disepakati berisi lima tabel inti saja.

## 8. Role dosen

Registrasi membuat role `mahasiswa`. Untuk menjadikan akun uji sebagai dosen, ubah role dengan query terkontrol di Supabase:

```sql
UPDATE public.users SET role='dosen', updated_at=CURRENT_TIMESTAMP WHERE email='email-dosen@example.com';
```

Gunakan email akun yang benar. Logout lalu login kembali agar role pada session diperbarui.
