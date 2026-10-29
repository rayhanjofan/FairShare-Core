-- ============================================================
-- FairShare – 002_repair_group_members_constraints.sql
-- ============================================================
-- Tujuan: Menambahkan PRIMARY KEY, UNIQUE constraint, FK, dan
--         CHECK yang hilang dari tabel public.group_members dan
--         public.groups pada database Supabase.
--
-- CATATAN: Jalankan via DBeaver atau Supabase SQL Editor.
--          Periksa setiap blok sebelum eksekusi.
--          File ini AMAN dijalankan berkali-kali (IF NOT EXISTS).
-- ============================================================

-- Pastikan ekstensi pgcrypto aktif (untuk gen_random_uuid)
CREATE EXTENSION IF NOT EXISTS pgcrypto;

-- ============================================================
-- A. Perbaiki kolom role_in_group agar NOT NULL
--    (saat ini nullable di Supabase)
-- ============================================================

-- Isi dulu nilai NULL yang mungkin ada
UPDATE public.group_members
SET role_in_group = 'anggota'
WHERE role_in_group IS NULL OR role_in_group = '';

-- Ubah kolom menjadi NOT NULL dengan default
ALTER TABLE public.group_members
    ALTER COLUMN role_in_group SET NOT NULL,
    ALTER COLUMN role_in_group SET DEFAULT 'anggota';

-- ============================================================
-- B. Tambahkan PRIMARY KEY pada group_members.id jika belum ada
-- ============================================================
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conrelid = 'public.group_members'::regclass
          AND contype = 'p'
    ) THEN
        ALTER TABLE public.group_members ADD PRIMARY KEY (id);
        RAISE NOTICE 'PRIMARY KEY ditambahkan ke group_members.id';
    ELSE
        RAISE NOTICE 'PRIMARY KEY sudah ada pada group_members';
    END IF;
END
$$;

-- ============================================================
-- C. Tambahkan UNIQUE (group_id, user_id) pada group_members
-- ============================================================

-- Hapus duplikat terlebih dahulu jika ada (pertahankan baris pertama)
DELETE FROM public.group_members gm1
USING public.group_members gm2
WHERE gm1.ctid > gm2.ctid
  AND gm1.group_id = gm2.group_id
  AND gm1.user_id  = gm2.user_id;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conrelid = 'public.group_members'::regclass
          AND contype = 'u'
    ) THEN
        ALTER TABLE public.group_members
            ADD CONSTRAINT uq_group_member UNIQUE (group_id, user_id);
        RAISE NOTICE 'UNIQUE constraint uq_group_member ditambahkan';
    ELSE
        RAISE NOTICE 'UNIQUE constraint sudah ada pada group_members';
    END IF;
END
$$;

-- ============================================================
-- D. Tambahkan CHECK constraint role_in_group
-- ============================================================
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conrelid = 'public.group_members'::regclass
          AND conname = 'chk_group_member_role'
    ) THEN
        ALTER TABLE public.group_members
            ADD CONSTRAINT chk_group_member_role
            CHECK (role_in_group IN ('ketua', 'anggota'));
        RAISE NOTICE 'CHECK chk_group_member_role ditambahkan';
    END IF;
END
$$;

-- ============================================================
-- E. Tambahkan FK group_id -> groups(id) ON DELETE CASCADE
-- ============================================================
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conrelid = 'public.group_members'::regclass
          AND conname = 'fk_group_members_group'
    ) THEN
        ALTER TABLE public.group_members
            ADD CONSTRAINT fk_group_members_group
            FOREIGN KEY (group_id) REFERENCES public.groups(id) ON DELETE CASCADE;
        RAISE NOTICE 'FK fk_group_members_group ditambahkan';
    END IF;
END
$$;

-- ============================================================
-- F. Tambahkan FK user_id -> users(id) ON DELETE CASCADE
-- ============================================================
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conrelid = 'public.group_members'::regclass
          AND conname = 'fk_group_members_user'
    ) THEN
        ALTER TABLE public.group_members
            ADD CONSTRAINT fk_group_members_user
            FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;
        RAISE NOTICE 'FK fk_group_members_user ditambahkan';
    END IF;
END
$$;

-- ============================================================
-- G. Tambahkan PRIMARY KEY pada groups.id jika belum ada
-- ============================================================
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conrelid = 'public.groups'::regclass
          AND contype = 'p'
    ) THEN
        ALTER TABLE public.groups ADD PRIMARY KEY (id);
        RAISE NOTICE 'PRIMARY KEY ditambahkan ke groups.id';
    ELSE
        RAISE NOTICE 'PRIMARY KEY sudah ada pada groups';
    END IF;
END
$$;

-- ============================================================
-- H. Tambahkan FK created_by -> users(id) ON DELETE RESTRICT
-- ============================================================
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conrelid = 'public.groups'::regclass
          AND conname = 'fk_groups_created_by'
    ) THEN
        ALTER TABLE public.groups
            ADD CONSTRAINT fk_groups_created_by
            FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE RESTRICT;
        RAISE NOTICE 'FK fk_groups_created_by ditambahkan';
    END IF;
END
$$;

-- ============================================================
-- I. Tambahkan CHECK duration_weeks >= 1 pada groups
-- ============================================================
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conrelid = 'public.groups'::regclass
          AND conname = 'chk_groups_duration'
    ) THEN
        ALTER TABLE public.groups
            ADD CONSTRAINT chk_groups_duration
            CHECK (duration_weeks >= 1);
        RAISE NOTICE 'CHECK chk_groups_duration ditambahkan';
    END IF;
END
$$;

-- ============================================================
-- J. Buat indexes untuk performa
-- ============================================================
CREATE INDEX IF NOT EXISTS idx_group_members_group ON public.group_members(group_id);
CREATE INDEX IF NOT EXISTS idx_group_members_user  ON public.group_members(user_id);
CREATE INDEX IF NOT EXISTS idx_groups_created_by   ON public.groups(created_by);

-- ============================================================
-- K. Verifikasi akhir
-- ============================================================
SELECT
    conname,
    contype,
    pg_get_constraintdef(oid) AS definition
FROM pg_constraint
WHERE conrelid IN (
    'public.group_members'::regclass,
    'public.groups'::regclass
)
ORDER BY conrelid::text, contype, conname;
