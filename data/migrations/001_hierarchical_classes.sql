-- FairShare: hierarchical classes and teams (no new core table required)
-- Run once in Supabase SQL Editor. It does not drop tables or delete rows.
BEGIN;

ALTER TABLE public.groups
    ADD COLUMN IF NOT EXISTS group_type VARCHAR(20) NOT NULL DEFAULT 'team',
    ADD COLUMN IF NOT EXISTS parent_group_id UUID,
    ADD COLUMN IF NOT EXISTS invite_code VARCHAR(20),
    ADD COLUMN IF NOT EXISTS description TEXT,
    ADD COLUMN IF NOT EXISTS semester VARCHAR(30),
    ADD COLUMN IF NOT EXISTS academic_year VARCHAR(20);

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conrelid = 'public.groups'::regclass
          AND conname = 'chk_groups_group_type'
    ) THEN
        ALTER TABLE public.groups
            ADD CONSTRAINT chk_groups_group_type
            CHECK (group_type IN ('class', 'team'));
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conrelid = 'public.groups'::regclass
          AND conname = 'fk_groups_parent_group'
    ) THEN
        ALTER TABLE public.groups
            ADD CONSTRAINT fk_groups_parent_group
            FOREIGN KEY (parent_group_id)
            REFERENCES public.groups(id)
            ON DELETE RESTRICT;
    END IF;
END $$;

-- Existing rows receive a recognizable legacy code; no existing row is deleted.
UPDATE public.groups
SET invite_code = 'OLD-' || UPPER(SUBSTRING(REPLACE(id::text, '-', '') FROM 1 FOR 12))
WHERE invite_code IS NULL;

ALTER TABLE public.groups
    ALTER COLUMN invite_code SET NOT NULL;

CREATE UNIQUE INDEX IF NOT EXISTS uq_groups_invite_code_lower
    ON public.groups (LOWER(invite_code))
    WHERE invite_code IS NOT NULL;

CREATE INDEX IF NOT EXISTS idx_groups_parent_group
    ON public.groups(parent_group_id)
    WHERE parent_group_id IS NOT NULL;

CREATE INDEX IF NOT EXISTS idx_groups_created_by_type
    ON public.groups(created_by, group_type);

-- One Project Manager (role_in_group='ketua') at most for each group.
CREATE UNIQUE INDEX IF NOT EXISTS uq_group_members_one_leader
    ON public.group_members(group_id)
    WHERE role_in_group = 'ketua';

CREATE OR REPLACE FUNCTION public.fairshare_validate_group_row()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    creator_role VARCHAR(20);
    parent_type VARCHAR(20);
    parent_owner UUID;
BEGIN
    SELECT role INTO creator_role
    FROM public.users
    WHERE id = NEW.created_by;

    IF NEW.group_type = 'class' THEN
        IF NEW.parent_group_id IS NOT NULL THEN
            RAISE EXCEPTION 'Kelas tidak boleh memiliki parent_group_id.';
        END IF;
        IF creator_role IS DISTINCT FROM 'dosen' AND creator_role IS DISTINCT FROM 'admin' THEN
            RAISE EXCEPTION 'Hanya dosen yang dapat membuat kelas.';
        END IF;
    ELSIF NEW.group_type = 'team' THEN
        IF NEW.parent_group_id IS NULL THEN
            RAISE EXCEPTION 'Kelompok harus berada di dalam sebuah kelas.';
        END IF;

        SELECT group_type, created_by
        INTO parent_type, parent_owner
        FROM public.groups
        WHERE id = NEW.parent_group_id;

        IF parent_type IS DISTINCT FROM 'class' THEN
            RAISE EXCEPTION 'Parent kelompok harus berupa kelas yang valid.';
        END IF;

        IF creator_role = 'dosen' OR creator_role = 'admin' THEN
            IF NEW.created_by <> parent_owner THEN
                RAISE EXCEPTION 'Dosen hanya dapat membuat kelompok pada kelas miliknya.';
            END IF;
        ELSE
            IF NOT EXISTS (
                SELECT 1 FROM public.group_members gm
                WHERE gm.group_id = NEW.parent_group_id
                  AND gm.user_id = NEW.created_by
            ) THEN
                RAISE EXCEPTION 'Pembuat kelompok harus menjadi anggota kelas terlebih dahulu.';
            END IF;
        END IF;
    ELSE
        RAISE EXCEPTION 'Tipe grup tidak dikenal.';
    END IF;

    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS trg_fairshare_validate_group_row ON public.groups;
CREATE TRIGGER trg_fairshare_validate_group_row
BEFORE INSERT OR UPDATE OF group_type, parent_group_id, created_by
ON public.groups
FOR EACH ROW
EXECUTE FUNCTION public.fairshare_validate_group_row();

CREATE OR REPLACE FUNCTION public.fairshare_validate_group_member()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    target_type VARCHAR(20);
    target_parent UUID;
    user_role VARCHAR(20);
BEGIN
    SELECT g.group_type, g.parent_group_id
    INTO target_type, target_parent
    FROM public.groups g
    WHERE g.id = NEW.group_id;

    SELECT role INTO user_role
    FROM public.users
    WHERE id = NEW.user_id;

    IF target_type = 'class' THEN
        IF user_role IS DISTINCT FROM 'mahasiswa' THEN
            RAISE EXCEPTION 'Hanya mahasiswa yang dapat bergabung sebagai anggota kelas.';
        END IF;
        IF NEW.role_in_group <> 'anggota' THEN
            RAISE EXCEPTION 'Keanggotaan kelas menggunakan role anggota.';
        END IF;
    ELSIF target_type = 'team' THEN
        IF target_parent IS NULL THEN
            RAISE EXCEPTION 'Kelompok belum terhubung ke kelas.';
        END IF;
        IF user_role IS DISTINCT FROM 'mahasiswa' THEN
            RAISE EXCEPTION 'Hanya mahasiswa yang dapat menjadi anggota kelompok.';
        END IF;
        IF NOT EXISTS (
            SELECT 1 FROM public.group_members class_member
            WHERE class_member.group_id = target_parent
              AND class_member.user_id = NEW.user_id
        ) THEN
            RAISE EXCEPTION 'Mahasiswa harus bergabung ke kelas sebelum bergabung ke kelompok.';
        END IF;

        -- Prevent a student from joining two teams in the same class.
        PERFORM pg_advisory_xact_lock(
            hashtextextended(target_parent::text || ':' || NEW.user_id::text, 0)
        );
        IF EXISTS (
            SELECT 1
            FROM public.group_members existing_member
            JOIN public.groups existing_team
              ON existing_team.id = existing_member.group_id
            WHERE existing_team.group_type = 'team'
              AND existing_team.parent_group_id = target_parent
              AND existing_member.user_id = NEW.user_id
              AND existing_member.group_id <> NEW.group_id
        ) THEN
            RAISE EXCEPTION 'Mahasiswa sudah menjadi anggota kelompok lain di kelas ini.';
        END IF;
    ELSE
        RAISE EXCEPTION 'Keanggotaan hanya dapat ditambahkan ke kelas atau kelompok.';
    END IF;

    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS trg_fairshare_validate_group_member ON public.group_members;
CREATE TRIGGER trg_fairshare_validate_group_member
BEFORE INSERT OR UPDATE OF group_id, user_id, role_in_group
ON public.group_members
FOR EACH ROW
EXECUTE FUNCTION public.fairshare_validate_group_member();

COMMIT;

-- Post-migration inspection:
-- SELECT group_type, COUNT(*) FROM public.groups GROUP BY group_type;
-- SELECT * FROM public.groups WHERE group_type='class';
