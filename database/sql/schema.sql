-- FairShare PostgreSQL schema reference for a FRESH / EMPTY database only.
-- The current team Supabase database already contains these tables: DO NOT rerun this file blindly.
-- CREATE TABLE IF NOT EXISTS does not repair mismatched existing columns.
-- No DROP/TRUNCATE statements are included.
CREATE EXTENSION IF NOT EXISTS pgcrypto;
CREATE TABLE IF NOT EXISTS public.users (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    nim_nip VARCHAR(30) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    role VARCHAR(20) NOT NULL DEFAULT 'mahasiswa' CHECK (role IN ('mahasiswa', 'dosen')),
    github_username VARCHAR(100) UNIQUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS public.groups (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    created_by UUID NOT NULL REFERENCES public.users(id) ON DELETE RESTRICT,
    name VARCHAR(150) NOT NULL,
    class_name VARCHAR(100),
    duration_weeks INTEGER NOT NULL DEFAULT 16 CHECK (duration_weeks >= 1),
    github_repo_url TEXT,
    gdocs_file_id VARCHAR(255),
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS public.group_members (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    group_id UUID NOT NULL REFERENCES public.groups(id) ON DELETE CASCADE,
    user_id UUID NOT NULL REFERENCES public.users(id) ON DELETE CASCADE,
    role_in_group VARCHAR(30) NOT NULL DEFAULT 'anggota' CHECK (role_in_group IN ('ketua', 'anggota')),
    peer_approval BOOLEAN NOT NULL DEFAULT FALSE,
    joined_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_group_members UNIQUE (group_id, user_id)
);
CREATE TABLE IF NOT EXISTS public.tasks (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    group_id UUID NOT NULL REFERENCES public.groups(id) ON DELETE CASCADE,
    assigned_user_id UUID REFERENCES public.users(id) ON DELETE
    SET NULL,
        title VARCHAR(200) NOT NULL,
        description TEXT,
        weight INTEGER NOT NULL DEFAULT 1 CHECK (
            weight BETWEEN 1 AND 5
        ),
        status VARCHAR(20) NOT NULL DEFAULT 'todo' CHECK (status IN ('todo', 'in_progress', 'review', 'done')),
        target_week INTEGER NOT NULL DEFAULT 1 CHECK (target_week >= 1),
        completed_at TIMESTAMP,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS public.telemetry_logs (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    group_id UUID NOT NULL REFERENCES public.groups(id) ON DELETE CASCADE,
    user_id UUID REFERENCES public.users(id) ON DELETE
    SET NULL,
        source VARCHAR(20) NOT NULL CHECK (source IN ('github', 'gdocs')),
        event_type VARCHAR(50) NOT NULL,
        external_id VARCHAR(255),
        activity_timestamp TIMESTAMP NOT NULL,
        raw_delta NUMERIC(10, 2) NOT NULL DEFAULT 0 CHECK (raw_delta >= 0),
        filtered_delta NUMERIC(10, 2) NOT NULL DEFAULT 0 CHECK (filtered_delta >= 0),
        metadata JSONB,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_group_members_group ON public.group_members(group_id);
CREATE INDEX IF NOT EXISTS idx_group_members_user ON public.group_members(user_id);
CREATE INDEX IF NOT EXISTS idx_tasks_group ON public.tasks(group_id);
CREATE INDEX IF NOT EXISTS idx_tasks_assigned_user ON public.tasks(assigned_user_id);
CREATE INDEX IF NOT EXISTS idx_telemetry_group_timestamp ON public.telemetry_logs(group_id, activity_timestamp);
CREATE INDEX IF NOT EXISTS idx_telemetry_user ON public.telemetry_logs(user_id);