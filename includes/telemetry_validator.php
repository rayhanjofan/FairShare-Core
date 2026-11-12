<?php
declare(strict_types=1);

/**
 * FairShare Telemetry Event Validator
 *
 * Mengimplementasikan 14 kriteria validasi aktivitas Google Docs dan sumber eksternal
 * sesuai standar transparansi dan audit FairShare.
 */

if (!defined('FS_VALIDATION_VERSION')) {
    define('FS_VALIDATION_VERSION', 1);
}

/**
 * Validasi satu event aktivitas telemetri.
 *
 * @param array $event Data event yang akan divalidasi
 * @param array|null $taskContext Konteks tugas, kelompok, dan keanggotaan
 * @param PDO|null $pdo Koneksi database jika diperlukan lookup
 * @return array Hasil validasi lengkap dengan status, alasan, raw_delta, dan filtered_delta
 */
function fs_validate_telemetry_event(array $event, ?array $taskContext = null, ?PDO $pdo = null): array
{
    $source = strtolower(trim((string) ($event['source'] ?? '')));
    $rawDelta = isset($event['raw_delta']) && is_numeric($event['raw_delta']) ? (float) $event['raw_delta'] : 1.0;
    if ($rawDelta <= 0.0) {
        $rawDelta = 1.0;
    }

    $taskId = trim((string) ($event['task_id'] ?? ''));
    $groupId = trim((string) ($event['group_id'] ?? ''));
    $userId = isset($event['user_id']) && $event['user_id'] !== null ? trim((string) $event['user_id']) : null;
    $externalId = trim((string) ($event['external_id'] ?? ''));
    $actionType = strtolower(trim((string) ($event['action_type'] ?? $event['event_type'] ?? '')));
    $documentId = trim((string) ($event['document_id'] ?? ''));
    $timestamp = trim((string) ($event['activity_timestamp'] ?? ''));
    $actorAttributed = (bool) ($event['actor_attributed'] ?? false);
    $attributionMethod = trim((string) ($event['attribution_method'] ?? ''));
    $entryMode = trim((string) ($event['entry_mode'] ?? ''));
    $isCurrentUser = (bool) ($event['is_current_user'] ?? ($actorAttributed && $attributionMethod === 'oauth_is_current_user'));

    $validatedAt = gmdate('Y-m-d\TH:i:s\Z');

    // Helper untuk membuat response penolakan
    $reject = static function (
        string $status,
        string $reason,
        float $raw = 1.0,
        ?string $customMsg = null
    ) use ($validatedAt, $actorAttributed, $attributionMethod): array {
        return [
            'is_valid' => false,
            'validation_status' => $status, // 'rejected' | 'pending' | 'unattributed' | 'duplicate'
            'validation_reason' => $reason,
            'reason_description' => $customMsg ?? fs_validation_reason_text($reason),
            'raw_delta' => $raw,
            'filtered_delta' => 0.0,
            'validation_version' => FS_VALIDATION_VERSION,
            'validated_at' => $validatedAt,
            'actor_attributed' => $actorAttributed,
            'attribution_method' => $attributionMethod,
        ];
    };

    // 1. Periksa apakah event adalah catatan uji manual
    if ($entryMode === 'manual_test' || ($event['metadata']['entry_mode'] ?? '') === 'manual_test') {
        return $reject('rejected', 'manual_test', $rawDelta, 'Catatan manual tidak diperhitungkan dalam evaluasi resmi.');
    }

    // 2. Kriteria 1: Sumber harus terintegrasi ('gdocs' atau 'github')
    if (!in_array($source, ['gdocs', 'github'], true)) {
        return $reject('rejected', 'invalid_source', $rawDelta, 'Sumber event tidak didukung.');
    }

    // 3. Kriteria 12: External event ID harus tersedia
    if ($externalId === '') {
        return $reject('rejected', 'missing_external_id', $rawDelta, 'External event ID tidak tersedia.');
    }

    // 4. Kriteria 2: Event terhubung dengan task ID yang valid
    if ($taskId === '' || !fs_is_valid_uuid($taskId)) {
        return $reject('rejected', 'invalid_task', $rawDelta, 'Task ID tidak valid atau kosong.');
    }

    // 5. Muat / periksa task context
    if ($taskContext === null && $pdo !== null) {
        $taskContext = fs_fetch_task_validation_context($taskId, $pdo);
    }

    if ($taskContext === null) {
        // Jika tidak ada konteks tugas dan tidak bisa di-query, status pending
        return $reject('pending', 'missing_task_context', $rawDelta, 'Konteks tugas belum dapat diverifikasi.');
    }

    // 6. Kriteria 3: Task menggunakan tipe sumber yang cocok
    $taskType = strtolower((string) ($taskContext['task_type'] ?? ''));
    if ($taskType !== $source) {
        return $reject('rejected', 'task_type_mismatch', $rawDelta, 'Tipe tugas tidak sesuai dengan sumber event.');
    }

    // 7. Kriteria 4: Task terhubung dengan kelompok bertipe 'team'
    $groupType = strtolower((string) ($taskContext['group_type'] ?? ''));
    if ($groupType !== 'team') {
        return $reject('rejected', 'invalid_group_type', $rawDelta, 'Tugas tidak terhubung ke kelompok tim.');
    }

    // Validasi kesesuaian group_id jika ada di event
    if ($groupId !== '' && strtolower($groupId) !== strtolower((string) $taskContext['group_id'])) {
        return $reject('rejected', 'invalid_group', $rawDelta, 'Kelompok tugas tidak cocok dengan event.');
    }

    // 8. Kriteria 5: Kelompok memiliki kelas induk yang valid
    $parentClassId = trim((string) ($taskContext['parent_group_id'] ?? ''));
    $parentClassType = strtolower((string) ($taskContext['parent_class_type'] ?? ''));
    if ($parentClassId === '' || $parentClassType !== 'class') {
        return $reject('rejected', 'invalid_parent_class', $rawDelta, 'Kelompok belum terhubung ke kelas yang valid.');
    }

    // 9. Kriteria 6: Pengguna terdaftar sebagai anggota kelompok tersebut
    if ($userId === null || $userId === '' || !fs_is_valid_uuid($userId)) {
        return $reject('unattributed', 'actor_not_verified', $rawDelta, 'Identitas pengguna tidak ditemukan.');
    }

    $validMemberIds = array_map('strtolower', (array) ($taskContext['member_ids'] ?? []));
    if (!in_array(strtolower($userId), $validMemberIds, true)) {
        return $reject('rejected', 'user_not_group_member', $rawDelta, 'Pengguna bukan anggota kelompok tugas ini.');
    }

    // 10. Kriteria 7 & 8: Identitas aktor terverifikasi melalui OAuth (isCurrentUser)
    if (!$actorAttributed || !$isCurrentUser) {
        return $reject('rejected', 'actor_not_verified', $rawDelta, 'Identitas aktor belum terverifikasi akun OAuth.');
    }

    // 11. Kriteria 9: Action merupakan 'edit' atau 'create'
    if (!in_array($actionType, ['edit', 'create'], true)) {
        return $reject('rejected', 'invalid_action', $rawDelta, 'Hanya aksi edit dan create yang dihitung.');
    }

    // 12. Kriteria 10: Timestamp valid
    if ($timestamp === '') {
        return $reject('rejected', 'missing_timestamp', $rawDelta, 'Waktu aktivitas kosong.');
    }

    $parsedTs = strtotime($timestamp);
    if ($parsedTs === false || $parsedTs <= 0) {
        return $reject('rejected', 'invalid_timestamp', $rawDelta, 'Format waktu aktivitas tidak valid.');
    }

    // Toleransi waktu masa depan (maksimal 15 menit untuk clock drift)
    if ($parsedTs > time() + 900) {
        return $reject('rejected', 'future_timestamp', $rawDelta, 'Waktu aktivitas melebihi waktu sistem saat ini.');
    }

    // 13. Kriteria 11: Target dokumen sesuai dengan Google Docs tugas
    if ($source === 'gdocs') {
        $taskDocId = trim((string) ($taskContext['external_resource_id'] ?? ''));
        if ($taskDocId === '') {
            return $reject('rejected', 'task_missing_resource', $rawDelta, 'Tugas belum memiliki dokumen Google Docs.');
        }

        if ($documentId !== '' && $documentId !== $taskDocId) {
            return $reject('rejected', 'wrong_document', $rawDelta, 'Dokumen yang diedit tidak cocok dengan tugas.');
        }
    }

    // 14. Kriteria 13: Cek duplikasi jika parameter duplicate_check diaktifkan
    if (!empty($event['is_duplicate'])) {
        return $reject('duplicate', 'duplicate_event', $rawDelta, 'Aktivitas sudah pernah tercatat sebelumnya.');
    }

    // Seluruh 14 kriteria terpenuhi: status VALID!
    return [
        'is_valid' => true,
        'validation_status' => 'valid',
        'validation_reason' => null,
        'reason_description' => 'Aktivitas terverifikasi valid dan memenuhi seluruh kriteria FairShare.',
        'raw_delta' => 1.0,
        'filtered_delta' => 1.0,
        'validation_version' => FS_VALIDATION_VERSION,
        'validated_at' => $validatedAt,
        'actor_attributed' => true,
        'attribution_method' => $attributionMethod !== '' ? $attributionMethod : 'oauth_is_current_user',
    ];
}

/**
 * Mengambil konteks tugas, kelompok, kelas induk, dan daftar anggota dari database.
 */
function fs_fetch_task_validation_context(string $taskId, PDO $pdo): ?array
{
    if (!fs_is_valid_uuid($taskId)) {
        return null;
    }

    $sql = "
        SELECT
            t.id::text AS task_id,
            t.task_type,
            t.external_resource_id,
            t.weight,
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
        WHERE t.id = CAST(:task_id AS UUID)
        LIMIT 1
    ";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['task_id' => $taskId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        // Parse PostgreSQL array string jika driver mengembalikan string "{uuid1,uuid2}"
        $rawMembers = $row['member_ids'] ?? [];
        if (is_string($rawMembers)) {
            $cleaned = trim($rawMembers, '{}');
            $row['member_ids'] = $cleaned !== '' ? explode(',', $cleaned) : [];
        } elseif (!is_array($rawMembers)) {
            $row['member_ids'] = [];
        }

        return $row;
    } catch (Throwable $e) {
        error_log('FairShare fetch task validation context error: ' . $e->getMessage());
        return null;
    }
}

/**
 * Helper validasi format UUID v4
 */
function fs_is_valid_uuid(string $value): bool
{
    if (function_exists('valid_uuid')) {
        return valid_uuid($value);
    }
    return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', trim($value));
}

/**
 * Terjemahan alasan validasi yang ramah audit
 */
function fs_validation_reason_text(?string $reason): string
{
    $reasons = [
        'actor_not_verified' => 'Identitas akun Google pelaku edit tidak terverifikasi.',
        'invalid_task' => 'Tugas tidak valid atau sudah tidak tersedia.',
        'invalid_group' => 'Kelompok tugas tidak sesuai.',
        'invalid_group_type' => 'Tugas tidak terhubung ke kelompok tim aktif.',
        'invalid_parent_class' => 'Kelompok belum terhubung ke kelas yang sah.',
        'user_not_group_member' => 'Pelaku aktivitas bukan anggota mahasiswa di kelompok tugas ini.',
        'invalid_action' => 'Aksi bukan merupakan edit atau create dokumen.',
        'invalid_timestamp' => 'Format waktu aktivitas tidak valid.',
        'missing_timestamp' => 'Waktu aktivitas tidak tercatat.',
        'future_timestamp' => 'Waktu aktivitas mendahului waktu server.',
        'wrong_document' => 'ID dokumen tidak cocok dengan Google Docs yang ditautkan ke tugas.',
        'task_missing_resource' => 'Tugas belum memiliki resource dokumen resmi.',
        'task_type_mismatch' => 'Tipe tugas bukan Google Docs.',
        'invalid_source' => 'Sumber aktivitas tidak didukung.',
        'duplicate_event' => 'Aktivitas duplikat sudah pernah diproses sebelumnya.',
        'missing_external_id' => 'External event ID tidak tersedia.',
        'manual_test' => 'Catatan uji manual tidak dimasukkan ke dalam perhitungan kontribusi resmi.',
        'missing_task_context' => 'Konteks tugas belum dapat diverifikasi.',
    ];

    return $reasons[$reason ?? ''] ?? 'Aktivitas tidak memenuhi kriteria validasi FairShare.';
}
