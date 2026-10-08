-- ============================================================
-- FAIRSHARE DATABASE
-- 001_initial_schema.sql
-- PostgreSQL
-- ============================================================
-- ============================================================
-- 1. EXTENSION
-- ============================================================
CREATE EXTENSION IF NOT EXISTS pgcrypto;


-- ============================================================
-- 2. TABLE: USERS
-- Master data pengguna
-- ============================================================

CREATE TABLE users (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    nim_nip VARCHAR(30) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    role VARCHAR(20) NOT NULL DEFAULT 'mahasiswa',
    github_username VARCHAR(100) UNIQUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_users_role CHECK (role IN ('mahasiswa', 'dosen'))
);
-- ============================================================
-- 3. TABLE: GROUPS
-- Master data kelompok/proyek
-- ============================================================

CREATE TABLE groups (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    created_by UUID NOT NULL,
    name VARCHAR(150) NOT NULL,
    class_name VARCHAR(100),
    duration_weeks INTEGER NOT NULL DEFAULT 16,
    github_repo_url TEXT,
    gdocs_file_id VARCHAR(255),
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_groups_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT chk_groups_duration CHECK (duration_weeks >= 1)
);
-- ============================================================
-- 4. TABLE: GROUP_MEMBERS
-- Relasi MANY-TO-MANY antara USERS dan GROUPS
-- ============================================================

CREATE TABLE group_members (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    group_id UUID NOT NULL,
    user_id UUID NOT NULL,
    role_in_group VARCHAR(30) NOT NULL DEFAULT 'anggota',
    peer_approval BOOLEAN NOT NULL DEFAULT FALSE,
    joined_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_group_members_group FOREIGN KEY (group_id) REFERENCES groups(id) ON DELETE CASCADE,
    CONSTRAINT fk_group_members_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT uq_group_member UNIQUE (group_id, user_id),
    CONSTRAINT chk_group_member_role CHECK (
        role_in_group IN ('ketua', 'anggota')
    )
);
-- ============================================================
-- 5. TABLE: TASKS
-- Master data tugas
-- ============================================================

CREATE TABLE tasks (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    group_id UUID NOT NULL,
    assigned_user_id UUID,
    title VARCHAR(200) NOT NULL,
    description TEXT,
    weight INTEGER NOT NULL DEFAULT 1,
    status VARCHAR(20) NOT NULL DEFAULT 'todo',
    target_week INTEGER NOT NULL DEFAULT 1,
    completed_at TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_tasks_group FOREIGN KEY (group_id) REFERENCES groups(id) ON DELETE CASCADE,
    CONSTRAINT fk_tasks_assigned_user FOREIGN KEY (assigned_user_id) REFERENCES users(id) ON DELETE
    SET NULL,
        CONSTRAINT chk_tasks_weight CHECK (
            weight BETWEEN 1 AND 5
        ),
        CONSTRAINT chk_tasks_status CHECK (
            status IN (
                'todo',
                'in_progress',
                'review',
                'done'
            )
        ),
        CONSTRAINT chk_tasks_target_week CHECK (target_week >= 1)
);
-- ============================================================
-- 6. TABLE: TELEMETRY_LOGS
-- Aktivitas pengguna dari GitHub / Google Docs
-- ============================================================

CREATE TABLE telemetry_logs (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    group_id UUID NOT NULL,
    user_id UUID,
    source VARCHAR(20) NOT NULL,
    external_id VARCHAR(255),
    activity_timestamp TIMESTAMP NOT NULL,
    raw_delta NUMERIC(10, 2) NOT NULL DEFAULT 0,
    filtered_delta NUMERIC(10, 2) NOT NULL DEFAULT 0,
    metadata JSONB,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_telemetry_group FOREIGN KEY (group_id) REFERENCES groups(id) ON DELETE CASCADE,
    CONSTRAINT fk_telemetry_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE
    SET NULL,
        CONSTRAINT chk_telemetry_source CHECK (
            source IN ('github', 'gdocs')
        ),
        CONSTRAINT chk_telemetry_raw_delta CHECK (raw_delta >= 0),
        CONSTRAINT chk_telemetry_filtered_delta CHECK (filtered_delta >= 0)
);
-- ============================================================
-- 7. INDEX
-- ============================================================

CREATE INDEX idx_groups_created_by ON groups(created_by);
CREATE INDEX idx_group_members_group ON group_members(group_id);
CREATE INDEX idx_group_members_user ON group_members(user_id);
CREATE INDEX idx_tasks_group ON tasks(group_id);
CREATE INDEX idx_tasks_assigned_user ON tasks(assigned_user_id);
CREATE INDEX idx_telemetry_group_timestamp ON telemetry_logs(group_id, activity_timestamp);
CREATE INDEX idx_telemetry_user ON telemetry_logs(user_id);
-- ============================================================
-- END OF INITIAL SCHEMA
-- ============================================================