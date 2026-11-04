-- FairShare Smart Assignment + Google Docs activity tracking
-- Apply manually in Supabase SQL Editor. Safe additive migration; no existing rows are deleted.
BEGIN;
ALTER TABLE public.tasks
ADD COLUMN IF NOT EXISTS task_type VARCHAR(20) NOT NULL DEFAULT 'manual',
    ADD COLUMN IF NOT EXISTS due_at TIMESTAMP,
    ADD COLUMN IF NOT EXISTS assignment_batch_id UUID,
    ADD COLUMN IF NOT EXISTS resource_url TEXT,
    ADD COLUMN IF NOT EXISTS external_resource_id VARCHAR(255),
    ADD COLUMN IF NOT EXISTS resource_title VARCHAR(255),
    ADD COLUMN IF NOT EXISTS sync_status VARCHAR(20) NOT NULL DEFAULT 'not_connected',
    ADD COLUMN IF NOT EXISTS last_synced_at TIMESTAMP,
    ADD COLUMN IF NOT EXISTS sync_state JSONB NOT NULL DEFAULT '{}'::jsonb,
    ADD COLUMN IF NOT EXISTS sync_error TEXT;
DO $$ BEGIN IF NOT EXISTS (
    SELECT 1
    FROM pg_constraint
    WHERE conrelid = 'public.tasks'::regclass
        AND conname = 'chk_tasks_task_type'
) THEN
ALTER TABLE public.tasks
ADD CONSTRAINT chk_tasks_task_type CHECK (task_type IN ('manual', 'gdocs', 'github'));
END IF;
IF NOT EXISTS (
    SELECT 1
    FROM pg_constraint
    WHERE conrelid = 'public.tasks'::regclass
        AND conname = 'chk_tasks_sync_status'
) THEN
ALTER TABLE public.tasks
ADD CONSTRAINT chk_tasks_sync_status CHECK (
        sync_status IN (
            'not_required',
            'not_connected',
            'connected',
            'synced',
            'partial',
            'error'
        )
    );
END IF;
END;
$$;
UPDATE public.tasks
SET sync_status = 'not_required'
WHERE task_type = 'manual'
    AND sync_status = 'not_connected';
-- A telemetry event can now be tied to its exact assignment, not just its team.
ALTER TABLE public.telemetry_logs
ADD COLUMN IF NOT EXISTS task_id UUID;
DO $$ BEGIN IF NOT EXISTS (
    SELECT 1
    FROM pg_constraint
    WHERE conrelid = 'public.telemetry_logs'::regclass
        AND conname = 'fk_telemetry_task'
) THEN
ALTER TABLE public.telemetry_logs
ADD CONSTRAINT fk_telemetry_task FOREIGN KEY (task_id) REFERENCES public.tasks(id) ON DELETE
SET NULL;
END IF;
END;
$$;
CREATE INDEX IF NOT EXISTS idx_tasks_assignment_batch ON public.tasks(assignment_batch_id)
WHERE assignment_batch_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_tasks_type_resource ON public.tasks(task_type, external_resource_id)
WHERE external_resource_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_telemetry_task_timestamp ON public.telemetry_logs(task_id, activity_timestamp DESC)
WHERE task_id IS NOT NULL;
-- Idempotency key: same external event is not inserted twice for one task/provider.
-- Existing records without task_id are unaffected.
CREATE UNIQUE INDEX IF NOT EXISTS uq_telemetry_task_source_external ON public.telemetry_logs(task_id, source, external_id)
WHERE task_id IS NOT NULL
    AND external_id IS NOT NULL;
-- Store OAuth credentials per FairShare user; tokens are encrypted by the PHP app.
CREATE TABLE IF NOT EXISTS public.user_integrations (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id UUID NOT NULL REFERENCES public.users(id) ON DELETE CASCADE,
    provider VARCHAR(20) NOT NULL CHECK (provider IN ('google')),
    provider_user_id VARCHAR(255) NOT NULL,
    provider_email VARCHAR(255) NOT NULL,
    access_token_encrypted TEXT NOT NULL,
    refresh_token_encrypted TEXT,
    token_expires_at TIMESTAMP,
    scopes TEXT,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_user_integrations_user_provider UNIQUE (user_id, provider),
    CONSTRAINT uq_user_integrations_provider_identity UNIQUE (provider, provider_user_id)
);
CREATE INDEX IF NOT EXISTS idx_user_integrations_provider_email ON public.user_integrations(provider, LOWER(provider_email));
COMMIT;
-- Verify after migration:
-- SELECT column_name, data_type FROM information_schema.columns
-- WHERE table_schema='public' AND table_name='tasks'
--   AND column_name IN ('task_type','resource_url','external_resource_id','assignment_batch_id','sync_state');
-- SELECT to_regclass('public.user_integrations') AS user_integrations_table;
-- SELECT indexname FROM pg_indexes WHERE schemaname='public' AND indexname='uq_telemetry_task_source_external';