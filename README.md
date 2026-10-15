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
