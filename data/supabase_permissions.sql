-- Jalankan melalui Supabase SQL Editor dengan akun yang berwenang.
-- Pastikan role fairshare_app sudah dibuat terlebih dahulu.
-- Tidak mengubah atau menghapus isi tabel.
GRANT CONNECT ON DATABASE postgres TO fairshare_app;
GRANT USAGE ON SCHEMA public TO fairshare_app;
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO fairshare_app;
