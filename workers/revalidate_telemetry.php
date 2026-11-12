<?php
declare(strict_types=1);

/**
 * FairShare Telemetry Revalidation Worker (CLI)
 *
 * Script untuk memeriksa dan mereklasifikasi data telemetri Google Docs lama
 * yang sebelumnya tersimpan dengan filtered_delta = 0.00 dan status 'Menunggu kalibrasi bobot'.
 *
 * Mode Penggunaan:
 *   php workers/revalidate_telemetry.php --dry-run
 *   php workers/revalidate_telemetry.php --apply
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Skrip ini hanya dapat dijalankan melalui antarmuka baris perintah (CLI).\n";
    exit(1);
}

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/telemetry_validator.php';

$args = array_slice($argv, 1);
$isApply = in_array('--apply', $args, true);
$isDryRun = in_array('--dry-run', $args, true) || !$isApply;

echo "============================================================\n";
echo "FAIRSHARE TELEMETRY REVALIDATION WORKER\n";
echo "============================================================\n";
echo "Mode Operasi : " . ($isApply ? "APPLY (Menerapkan perubahan ke database)" : "DRY-RUN (Simulasi / Preview saja)") . "\n";
echo "Waktu Mulai  : " . date('Y-m-d H:i:s T') . "\n";
echo "============================================================\n\n";

try {
    $pdo = require_db();
} catch (Throwable $e) {
    fwrite(STDERR, "[ERROR] Gagal terhubung ke database PostgreSQL/Supabase: " . $e->getMessage() . "\n");
    fwrite(STDERR, "Periksa konfigurasi data/.env dan status jaringan sebelum melanjutkan.\n");
    exit(1);
}

try {
    // 1. Ambil data konteks tugas, kelompok, dan keanggotaan
    echo "[1/4] Mengambil konteks tugas dan kelompok aktif...\n";
    $taskSql = "
        SELECT
            t.id::text AS task_id,
            t.task_type,
            t.external_resource_id,
            t.group_id::text AS group_id,
            team.group_type,
            team.parent_group_id::text AS parent_group_id,
            class.group_type AS parent_class_type,
            COALESCE(
                (
                    SELECT array_agg(gm.user_id::text)
                    FROM public.group_members gm
                    JOIN public.users u ON u.id = gm.user_id
                    WHERE gm.group_id = team.id AND u.role = 'mahasiswa'
                ),
                ARRAY[]::text[]
            ) AS member_ids
        FROM public.tasks t
        JOIN public.groups team ON team.id = t.group_id
        LEFT JOIN public.groups class ON class.id = team.parent_group_id
    ";
    $taskRows = $pdo->query($taskSql)->fetchAll(PDO::FETCH_ASSOC);
    $taskContextMap = [];
    foreach ($taskRows as $tr) {
        $rawMembers = $tr['member_ids'] ?? [];
        if (is_string($rawMembers)) {
            $cleaned = trim($rawMembers, '{}');
            $tr['member_ids'] = $cleaned !== '' ? explode(',', $cleaned) : [];
        } elseif (!is_array($rawMembers)) {
            $tr['member_ids'] = [];
        }
        $taskContextMap[$tr['task_id']] = $tr;
    }
    echo "      Ditemukan " . count($taskContextMap) . " tugas terdaftar.\n\n";

    // 2. Ambil seluruh record telemetri Google Docs
    echo "[2/4] Membaca riwayat telemetri gdocs dari database...\n";
    $tlSql = "
        SELECT
            tl.id::text AS id,
            tl.task_id::text AS task_id,
            tl.group_id::text AS group_id,
            tl.user_id::text AS user_id,
            tl.source,
            tl.external_id,
            tl.activity_timestamp::text AS activity_timestamp,
            tl.raw_delta,
            tl.filtered_delta,
            tl.metadata
        FROM public.telemetry_logs tl
        WHERE tl.source = 'gdocs'
        ORDER BY tl.activity_timestamp ASC
    ";
    $records = $pdo->query($tlSql)->fetchAll(PDO::FETCH_ASSOC);
    echo "      Total record gdocs terdeteksi: " . count($records) . "\n\n";

    if (!$records) {
        echo "Tidak ada record telemetri gdocs yang perlu divalidasi.\n";
        exit(0);
    }

    // 3. Evaluasi setiap record dengan 11 kriteria revalidasi
    echo "[3/4] Melakukan audit dan revalidasi record...\n";

    $stats = [
        'total' => count($records),
        'already_valid' => 0,
        'will_accept' => 0,
        'will_reject' => 0,
        'will_pending' => 0,
        'manual_test' => 0,
    ];

    $updatesToApply = [];
    $seenExternalIds = [];

    foreach ($records as $row) {
        $rowId = $row['id'];
        $meta = $row['metadata'];
        if (is_string($meta)) {
            $decoded = json_decode($meta, true);
            $meta = is_array($decoded) ? $decoded : [];
        } elseif (!is_array($meta)) {
            $meta = [];
        }

        $entryMode = (string) ($meta['entry_mode'] ?? '');
        if ($entryMode === 'manual_test') {
            $stats['manual_test']++;
            continue;
        }

        $currentStatus = strtolower(trim((string) ($meta['validation_status'] ?? '')));
        $currentFiltered = (float) $row['filtered_delta'];

        // Cek duplikasi external_id
        $extId = (string) ($row['external_id'] ?? '');
        $isDuplicate = false;
        if ($extId !== '') {
            if (isset($seenExternalIds[$extId])) {
                $isDuplicate = true;
            } else {
                $seenExternalIds[$extId] = true;
            }
        }

        $taskId = (string) ($row['task_id'] ?? '');
        $taskCtx = $taskContextMap[$taskId] ?? null;

        $eventForValidation = [
            'source' => $row['source'],
            'task_id' => $row['task_id'],
            'group_id' => $row['group_id'],
            'user_id' => $row['user_id'],
            'external_id' => $row['external_id'],
            'action_type' => $meta['event_type'] ?? 'edit',
            'document_id' => $meta['document_id'] ?? ($taskCtx['external_resource_id'] ?? ''),
            'activity_timestamp' => $row['activity_timestamp'],
            'actor_attributed' => (bool) ($meta['actor_attributed'] ?? false),
            'attribution_method' => (string) ($meta['attribution_method'] ?? 'oauth_is_current_user'),
            'entry_mode' => $entryMode,
            'is_duplicate' => $isDuplicate,
            'raw_delta' => (float) $row['raw_delta'],
        ];

        $valResult = fs_validate_telemetry_event($eventForValidation, $taskCtx, $pdo);

        if ($valResult['is_valid']) {
            if ($currentStatus === 'valid' && abs($currentFiltered - 1.0) < 0.0001) {
                $stats['already_valid']++;
            } else {
                $stats['will_accept']++;
                $newMeta = array_merge($meta, [
                    'validation_status' => 'valid',
                    'validation_reason' => null,
                    'validation_version' => FS_VALIDATION_VERSION,
                    'validated_at' => $valResult['validated_at'],
                    'actor_attributed' => true,
                    'attribution_method' => $valResult['attribution_method'],
                    'revalidated_by' => 'revalidate_telemetry_worker',
                ]);
                $updatesToApply[] = [
                    'id' => $rowId,
                    'filtered_delta' => 1.0,
                    'metadata' => $newMeta,
                ];
            }
        } elseif ($valResult['validation_status'] === 'pending') {
            $stats['will_pending']++;
            $newMeta = array_merge($meta, [
                'validation_status' => 'pending',
                'validation_reason' => $valResult['validation_reason'],
                'validation_version' => FS_VALIDATION_VERSION,
                'validated_at' => $valResult['validated_at'],
            ]);
            $updatesToApply[] = [
                'id' => $rowId,
                'filtered_delta' => 0.0,
                'metadata' => $newMeta,
            ];
        } else {
            // Rejected / Unattributed / Duplicate
            $stats['will_reject']++;
            $newMeta = array_merge($meta, [
                'validation_status' => $valResult['validation_status'],
                'validation_reason' => $valResult['validation_reason'],
                'validation_version' => FS_VALIDATION_VERSION,
                'validated_at' => $valResult['validated_at'],
            ]);
            $updatesToApply[] = [
                'id' => $rowId,
                'filtered_delta' => 0.0,
                'metadata' => $newMeta,
            ];
        }
    }

    echo "============================================================\n";
    echo "HASIL ANALISIS REVALIDASI TELEMETRI\n";
    echo "============================================================\n";
    echo sprintf(" Total Record gdocs Diperiksa : %d\n", $stats['total']);
    echo sprintf(" - Sudah Valid Sebelumnya     : %d\n", $stats['already_valid']);
    echo sprintf(" - Lolos & Akan Diterima      : %d (Filtered Delta -> 1.00, status: valid)\n", $stats['will_accept']);
    echo sprintf(" - Ditolak (Rejected)         : %d (Filtered Delta = 0.00)\n", $stats['will_reject']);
    echo sprintf(" - Menunggu (Pending)         : %d (Filtered Delta = 0.00)\n", $stats['will_pending']);
    echo sprintf(" - Catatan Uji Manual         : %d (Tidak diubah)\n", $stats['manual_test']);
    echo "============================================================\n\n";

    // 4. Eksekusi jika mode --apply
    if ($isApply) {
        if (count($updatesToApply) === 0) {
            echo "[4/4] Tidak ada perubahan data yang perlu disimpan ke database.\n";
            exit(0);
        }

        echo "[4/4] Menerapkan " . count($updatesToApply) . " pembaruan record ke database...\n";
        $pdo->beginTransaction();

        $updateStmt = $pdo->prepare("
            UPDATE public.telemetry_logs
            SET
                filtered_delta = :filtered_delta,
                metadata = CAST(:metadata AS JSONB)
            WHERE id = CAST(:id AS UUID)
        ");

        $appliedCount = 0;
        foreach ($updatesToApply as $item) {
            $updateStmt->execute([
                'filtered_delta' => $item['filtered_delta'],
                'metadata' => json_encode($item['metadata'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'id' => $item['id'],
            ]);
            $appliedCount += $updateStmt->rowCount();
        }

        $pdo->commit();
        echo "[SUKSES] Berhasil menerapkan $appliedCount record ke database PostgreSQL Supabase.\n";
        echo "Seluruh aktivitas lama yang memenuhi kriteria validasi kini memiliki Filtered Delta = 1.00.\n";
    } else {
        echo "[INFO] Skrip dijalankan dalam mode DRY-RUN. Tidak ada data yang diubah di database.\n";
        echo "Untuk menerapkan perubahan secara permanen ke database, jalankan:\n";
        echo "  php workers/revalidate_telemetry.php --apply\n";
    }

    echo "\nSelesai: " . date('Y-m-d H:i:s T') . "\n";
    exit(0);
} catch (Throwable $e) {
    if ($pdo && $pdo->inTransaction()) {
        $pdo->rollBack();
        echo "[ROLLBACK] Transaksi database dibatalkan karena terjadi kesalahan.\n";
    }
    fwrite(STDERR, "[FATAL ERROR] " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    exit(1);
}
