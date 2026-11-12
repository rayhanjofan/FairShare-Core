-- ============================================================
-- FairShare Migration 003: Telemetry Validation & WEI Performance Indexes
-- ============================================================
-- CATATAN: Migration ini bersifat aditif (non-destruktif) dan aman.
--          TIDAK menghapus data, TIDAK mengubah primary key, dan TIDAK membuat tabel baru.
--          Detail validasi dan audit disimpan langsung di kolom JSONB metadata.
--          Jalankan secara manual via Supabase SQL Editor atau DBeaver setelah ditinjau.
-- ============================================================

BEGIN;

-- 1. Index untuk mempercepat query telemetri berdasarkan status validasi (JSONB)
CREATE INDEX IF NOT EXISTS idx_telemetry_validation_status
ON public.telemetry_logs ((metadata->>'validation_status'))
WHERE metadata IS NOT NULL;

-- 2. Index komposit untuk kalkulasi WEI per kelompok dan tugas
CREATE INDEX IF NOT EXISTS idx_telemetry_group_task_user
ON public.telemetry_logs (group_id, task_id, user_id)
WHERE task_id IS NOT NULL;

-- 3. Index untuk idempotency pencarian external_id pada level task dan group
CREATE INDEX IF NOT EXISTS idx_telemetry_external_id_source
ON public.telemetry_logs (source, external_id)
WHERE external_id IS NOT NULL;

-- 4. Komentar dokumentasi kolom metadata JSONB
COMMENT ON COLUMN public.telemetry_logs.metadata IS
'Menyimpan detail audit: validation_status (valid|rejected|pending|unattributed|duplicate), validation_reason, validation_version, validated_at, actor_attributed, attribution_method, provider, event_type, document_id.';

COMMIT;
