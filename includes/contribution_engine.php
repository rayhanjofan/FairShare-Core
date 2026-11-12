<?php
declare(strict_types=1);

/**
 * FairShare Contribution Engine & Weighted Effort Index (WEI) Calculator
 *
 * Mengimplementasikan kalkulasi proporsional kontribusi mahasiswa dalam kelompok
 * berbasis task-level effort dan task weight sesuai metodologi resmi FairShare.
 */

require_once __DIR__ . '/telemetry_validator.php';

/**
 * Menghitung WEI seluruh anggota dalam satu kelompok.
 *
 * @param string $groupId UUID kelompok
 * @param PDO|null $pdo Koneksi database (jika null, akan menggunakan require_db())
 * @return array Struktur data WEI lengkap kelompok
 */
function fs_calculate_group_wei(string $groupId, ?PDO $pdo = null): array
{
    if (!fs_is_valid_uuid($groupId)) {
        return fs_empty_group_wei_result($groupId, 'ID kelompok tidak valid.');
    }

    if ($pdo === null) {
        $pdo = require_db();
    }

    // 1. Ambil data kelompok
    $groupStmt = $pdo->prepare("SELECT id::text AS id, name, group_type, parent_group_id::text AS parent_group_id FROM public.groups WHERE id = CAST(:group_id AS UUID) LIMIT 1");
    $groupStmt->execute(['group_id' => $groupId]);
    $group = $groupStmt->fetch(PDO::FETCH_ASSOC);

    if (!$group) {
        return fs_empty_group_wei_result($groupId, 'Kelompok tidak ditemukan.');
    }

    // 2. Ambil seluruh anggota mahasiswa kelompok
    $memberStmt = $pdo->prepare("
        SELECT
            u.id::text AS user_id,
            u.name,
            u.nim_nip,
            u.email,
            gm.role_in_group,
            gm.joined_at::text AS joined_at
        FROM public.group_members gm
        JOIN public.users u ON u.id = gm.user_id
        WHERE gm.group_id = CAST(:group_id AS UUID)
        ORDER BY CASE WHEN gm.role_in_group = 'ketua' THEN 0 ELSE 1 END, u.name ASC
    ");
    $memberStmt->execute(['group_id' => $groupId]);
    $members = $memberStmt->fetchAll(PDO::FETCH_ASSOC);

    // 3. Ambil seluruh tugas kelompok
    $taskStmt = $pdo->prepare("
        SELECT
            t.id::text AS id,
            t.title,
            t.description,
            t.weight,
            t.task_type,
            t.external_resource_id,
            t.status,
            t.sync_status
        FROM public.tasks t
        WHERE t.group_id = CAST(:group_id AS UUID)
        ORDER BY t.created_at ASC
    ");
    $taskStmt->execute(['group_id' => $groupId]);
    $tasks = $taskStmt->fetchAll(PDO::FETCH_ASSOC);

    // 4. Ambil seluruh telemetri kelompok
    $telemetryStmt = $pdo->prepare("
        SELECT
            tl.id::text AS id,
            tl.task_id::text AS task_id,
            tl.user_id::text AS user_id,
            tl.source,
            tl.external_id,
            tl.activity_timestamp::text AS activity_timestamp,
            tl.raw_delta,
            tl.filtered_delta,
            tl.metadata
        FROM public.telemetry_logs tl
        WHERE tl.group_id = CAST(:group_id AS UUID)
        ORDER BY tl.activity_timestamp ASC
    ");
    $telemetryStmt->execute(['group_id' => $groupId]);
    $telemetryRows = $telemetryStmt->fetchAll(PDO::FETCH_ASSOC);

    // Parsing JSONB metadata jika masih string
    foreach ($telemetryRows as &$tl) {
        if (is_string($tl['metadata'])) {
            $decoded = json_decode($tl['metadata'], true);
            $tl['metadata'] = is_array($decoded) ? $decoded : [];
        } elseif (!is_array($tl['metadata'])) {
            $tl['metadata'] = [];
        }
    }
    unset($tl);

    // Delegasikan ke fungsi kalkulasi murni (dapat dites tanpa DB)
    return fs_compute_wei_from_dataset($group, $members, $tasks, $telemetryRows);
}

/**
 * Menghitung WEI satu tugas tertentu.
 *
 * @param string $taskId UUID tugas
 * @param PDO|null $pdo
 * @return array
 */
function fs_calculate_task_wei(string $taskId, ?PDO $pdo = null): array
{
    if (!fs_is_valid_uuid($taskId)) {
        return [
            'task_id' => $taskId,
            'has_valid_data' => false,
            'data_status' => 'invalid_task_id',
            'weight' => 1,
            'total_raw_delta' => 0.0,
            'total_filtered_delta' => 0.0,
            'valid_events_count' => 0,
            'pending_events_count' => 0,
            'rejected_events_count' => 0,
            'members' => [],
        ];
    }

    if ($pdo === null) {
        $pdo = require_db();
    }

    $taskStmt = $pdo->prepare("SELECT t.id::text AS id, t.group_id::text AS group_id, t.title, t.weight, t.task_type, t.external_resource_id FROM public.tasks t WHERE t.id = CAST(:task_id AS UUID) LIMIT 1");
    $taskStmt->execute(['task_id' => $taskId]);
    $task = $taskStmt->fetch(PDO::FETCH_ASSOC);

    if (!$task) {
        return [
            'task_id' => $taskId,
            'has_valid_data' => false,
            'data_status' => 'not_found',
            'weight' => 1,
            'total_raw_delta' => 0.0,
            'total_filtered_delta' => 0.0,
            'valid_events_count' => 0,
            'pending_events_count' => 0,
            'rejected_events_count' => 0,
            'members' => [],
        ];
    }

    $groupWei = fs_calculate_group_wei((string) $task['group_id'], $pdo);
    return $groupWei['tasks'][$taskId] ?? [
        'task_id' => $taskId,
        'has_valid_data' => false,
        'data_status' => 'no_task_in_group',
        'weight' => (int) ($task['weight'] ?? 1),
        'total_raw_delta' => 0.0,
        'total_filtered_delta' => 0.0,
        'valid_events_count' => 0,
        'pending_events_count' => 0,
        'rejected_events_count' => 0,
        'members' => [],
    ];
}

/**
 * Inti Algoritma WEI FairShare (Pure function / deterministic calculation)
 *
 * @param array $group Data kelompok
 * @param array $members Daftar anggota kelompok
 * @param array $tasks Daftar tugas kelompok
 * @param array $telemetryRows Daftar riwayat telemetri kelompok
 * @return array Struktur hasil perhitungan WEI
 */
function fs_compute_wei_from_dataset(array $group, array $members, array $tasks, array $telemetryRows): array
{
    $groupId = (string) ($group['id'] ?? '');

    // Inisialisasi struktur rekap per mahasiswa
    $memberStats = [];
    foreach ($members as $m) {
        $uid = (string) $m['user_id'];
        $memberStats[$uid] = [
            'user_id' => $uid,
            'name' => (string) ($m['name'] ?? ''),
            'nim' => (string) ($m['nim_nip'] ?? ''),
            'email' => (string) ($m['email'] ?? ''),
            'role_in_group' => (string) ($m['role_in_group'] ?? 'anggota'),
            'raw_delta_total' => 0.0,
            'filtered_delta_total' => 0.0,
            'valid_events_count' => 0,
            'pending_events_count' => 0,
            'rejected_events_count' => 0,
            'manual_events_count' => 0,
            'unattributed_events_count' => 0,
            'task_scores' => [], // task_id => filtered_delta
            'wei_percent' => null, // null jika data belum cukup
            'wei_formatted' => 'Belum cukup data',
            'data_status' => 'insufficient_data',
        ];
    }

    // Inisialisasi struktur per tugas
    $taskDetails = [];
    $totalTasksCount = count($tasks);
    $manualTasksCount = 0;

    foreach ($tasks as $t) {
        $tid = (string) $t['id'];
        $tType = (string) ($t['task_type'] ?? 'manual');
        $rawWeight = (int) ($t['weight'] ?? 1);
        $weight = max(1, min(5, $rawWeight));

        if ($tType === 'manual') {
            $manualTasksCount++;
        }

        $taskDetails[$tid] = [
            'task_id' => $tid,
            'title' => (string) ($t['title'] ?? ''),
            'weight' => $weight,
            'task_type' => $tType,
            'external_resource_id' => (string) ($t['external_resource_id'] ?? ''),
            'has_valid_data' => false,
            'data_status' => 'no_valid_activity',
            'total_raw_delta' => 0.0,
            'total_filtered_delta' => 0.0,
            'valid_events_count' => 0,
            'pending_events_count' => 0,
            'rejected_events_count' => 0,
            'manual_events_count' => 0,
            'member_scores' => [], // user_id => [ raw_delta, filtered_delta, wei_percent ]
        ];

        foreach (array_keys($memberStats) as $uid) {
            $taskDetails[$tid]['member_scores'][$uid] = [
                'user_id' => $uid,
                'name' => $memberStats[$uid]['name'],
                'raw_delta' => 0.0,
                'filtered_delta' => 0.0,
                'valid_events' => 0,
                'wei_percent' => 0.0,
            ];
        }
    }

    // Kategorisasi dan agregasi setiap record telemetri
    foreach ($telemetryRows as $row) {
        $uid = isset($row['user_id']) && $row['user_id'] !== null ? (string) $row['user_id'] : null;
        $tid = isset($row['task_id']) && $row['task_id'] !== null ? (string) $row['task_id'] : null;
        $meta = is_array($row['metadata'] ?? null) ? $row['metadata'] : [];
        $entryMode = (string) ($meta['entry_mode'] ?? '');
        $valStatus = strtolower(trim((string) ($meta['validation_status'] ?? '')));
        $rawDelta = isset($row['raw_delta']) ? (float) $row['raw_delta'] : 1.0;
        $filteredDelta = isset($row['filtered_delta']) ? (float) $row['filtered_delta'] : 0.0;

        // Cek manual test
        $isManualTest = ($entryMode === 'manual_test');

        // Jika actor tidak tercatat
        if ($uid === null || !isset($memberStats[$uid])) {
            if ($tid !== null && isset($taskDetails[$tid])) {
                $taskDetails[$tid]['rejected_events_count']++;
            }
            continue;
        }

        // Akumulasi statistik per mahasiswa
        if ($isManualTest) {
            $memberStats[$uid]['manual_events_count']++;
            if ($tid !== null && isset($taskDetails[$tid])) {
                $taskDetails[$tid]['manual_events_count']++;
            }
            continue; // Manual test tidak boleh masuk ke perhitungan WEI resmi
        }

        $memberStats[$uid]['raw_delta_total'] += $rawDelta;

        // Tentukan apakah event valid untuk WEI
        $isValid = ($valStatus === 'valid' || $valStatus === 'terverifikasi') && $filteredDelta > 0.0;

        if ($isValid) {
            $memberStats[$uid]['valid_events_count']++;
            $memberStats[$uid]['filtered_delta_total'] += $filteredDelta;

            if ($tid !== null && isset($taskDetails[$tid])) {
                $taskDetails[$tid]['valid_events_count']++;
                $taskDetails[$tid]['total_raw_delta'] += $rawDelta;
                $taskDetails[$tid]['total_filtered_delta'] += $filteredDelta;
                $taskDetails[$tid]['member_scores'][$uid]['raw_delta'] += $rawDelta;
                $taskDetails[$tid]['member_scores'][$uid]['filtered_delta'] += $filteredDelta;
                $taskDetails[$tid]['member_scores'][$uid]['valid_events']++;
            }
        } elseif ($valStatus === 'pending' || $valStatus === 'menunggu kalibrasi bobot') {
            $memberStats[$uid]['pending_events_count']++;
            if ($tid !== null && isset($taskDetails[$tid])) {
                $taskDetails[$tid]['pending_events_count']++;
                $taskDetails[$tid]['total_raw_delta'] += $rawDelta;
            }
        } else {
            $memberStats[$uid]['rejected_events_count']++;
            if ($tid !== null && isset($taskDetails[$tid])) {
                $taskDetails[$tid]['rejected_events_count']++;
                $taskDetails[$tid]['total_raw_delta'] += $rawDelta;
            }
        }
    }

    // Hitung persentase WEI per tugas (MemberTaskScore / TotalValidScore_t)
    $validTasksCount = 0;
    $totalEvaluatedWeight = 0;

    foreach ($taskDetails as $tid => &$td) {
        $totalValidScore = (float) $td['total_filtered_delta'];
        if ($totalValidScore > 0.0) {
            $td['has_valid_data'] = true;
            $td['data_status'] = 'sufficient';
            $validTasksCount++;
            $totalEvaluatedWeight += $td['weight'];

            foreach ($td['member_scores'] as $uid => &$ms) {
                // Rumus: WEI_i,t = (MemberTaskScore_i,t / TotalValidScore_t) * 100%
                $ms['wei_percent'] = ($ms['filtered_delta'] / $totalValidScore) * 100.0;
                $memberStats[$uid]['task_scores'][$tid] = $ms['wei_percent'];
            }
            unset($ms);
        } else {
            $td['has_valid_data'] = false;
            $td['data_status'] = 'no_valid_activity';
            foreach ($td['member_scores'] as $uid => &$ms) {
                $ms['wei_percent'] = 0.0;
                $memberStats[$uid]['task_scores'][$tid] = 0.0;
            }
            unset($ms);
        }
    }
    unset($td);

    $pendingOrNoDataTasksCount = max(0, $totalTasksCount - $validTasksCount - $manualTasksCount);
    $hasSufficientData = ($validTasksCount > 0 && $totalEvaluatedWeight > 0);

    // Hitung WEI Keseluruhan Kelompok:
    // WEI_i = [Σ (Weight_t * WEI_i,t)] / Σ Weight_t (hanya tugas dengan valid data)
    $totalWeiSum = 0.0;

    foreach ($memberStats as $uid => &$ms) {
        if ($hasSufficientData) {
            $weightedScoreSum = 0.0;
            foreach ($taskDetails as $tid => $td) {
                if ($td['has_valid_data'] && $td['weight'] > 0) {
                    $taskWei = (float) ($ms['task_scores'][$tid] ?? 0.0);
                    $weightedScoreSum += ($td['weight'] * $taskWei);
                }
            }

            // Normalisasi terbobot
            $calculatedWei = $weightedScoreSum / (float) $totalEvaluatedWeight;
            $ms['wei_percent'] = $calculatedWei;
            $ms['wei_formatted'] = number_format($calculatedWei, 1) . '%';
            $ms['data_status'] = 'sufficient';
            $totalWeiSum += $calculatedWei;
        } else {
            // Jika belum ada data valid sama sekali
            $ms['wei_percent'] = null;
            $ms['wei_formatted'] = 'Belum cukup data';
            $ms['data_status'] = 'insufficient_data';
        }
    }
    unset($ms);

    return [
        'group_id' => $groupId,
        'group_name' => (string) ($group['name'] ?? ''),
        'has_sufficient_data' => $hasSufficientData,
        'data_status' => $hasSufficientData ? 'sufficient' : 'insufficient_data',
        'status_label' => $hasSufficientData ? 'Data Cukup' : 'Belum Cukup Data',
        'total_tasks_count' => $totalTasksCount,
        'valid_tasks_count' => $validTasksCount,
        'pending_or_no_data_tasks_count' => $pendingOrNoDataTasksCount,
        'manual_tasks_count' => $manualTasksCount,
        'total_evaluated_weight' => $totalEvaluatedWeight,
        'total_wei_sum' => $hasSufficientData ? round($totalWeiSum, 4) : 0.0,
        'members' => $memberStats,
        'tasks' => $taskDetails,
    ];
}

/**
 * Helper ringkasan WEI per user (lintas kelompok atau kelompok tertentu)
 */
function fs_member_wei_overview(string $userId, ?PDO $pdo = null): array
{
    if (!fs_is_valid_uuid($userId)) {
        return [
            'has_groups' => false,
            'has_sufficient_data' => false,
            'overall_wei' => 0.0,
            'wei_formatted' => 'Belum cukup data',
            'groups' => [],
        ];
    }

    if ($pdo === null) {
        $pdo = require_db();
    }

    $groupStmt = $pdo->prepare("
        SELECT g.id::text AS group_id, g.name
        FROM public.group_members gm
        JOIN public.groups g ON g.id = gm.group_id
        WHERE gm.user_id = CAST(:user_id AS UUID) AND g.group_type = 'team'
    ");
    $groupStmt->execute(['user_id' => $userId]);
    $groups = $groupStmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$groups) {
        return [
            'has_groups' => false,
            'has_sufficient_data' => false,
            'overall_wei' => 0.0,
            'wei_formatted' => 'Belum ada kelompok',
            'groups' => [],
        ];
    }

    $results = [];
    $validWeiList = [];

    foreach ($groups as $g) {
        $gid = (string) $g['group_id'];
        $calc = fs_calculate_group_wei($gid, $pdo);
        $memberData = $calc['members'][$userId] ?? null;

        $results[$gid] = [
            'group_id' => $gid,
            'group_name' => (string) $g['name'],
            'has_sufficient_data' => $calc['has_sufficient_data'],
            'member_data' => $memberData,
        ];

        if ($calc['has_sufficient_data'] && $memberData !== null && $memberData['wei_percent'] !== null) {
            $validWeiList[] = (float) $memberData['wei_percent'];
        }
    }

    $hasSufficient = count($validWeiList) > 0;
    $avgWei = $hasSufficient ? (array_sum($validWeiList) / count($validWeiList)) : 0.0;

    return [
        'has_groups' => true,
        'has_sufficient_data' => $hasSufficient,
        'overall_wei' => round($avgWei, 2),
        'wei_formatted' => $hasSufficient ? number_format($avgWei, 1) . '%' : 'Belum cukup data',
        'groups' => $results,
    ];
}

/**
 * Helper pembungkus hasil kelompok kosong / error
 */
function fs_empty_group_wei_result(string $groupId, string $reason): array
{
    return [
        'group_id' => $groupId,
        'group_name' => '',
        'has_sufficient_data' => false,
        'data_status' => 'insufficient_data',
        'status_label' => 'Belum Cukup Data',
        'message' => $reason,
        'total_tasks_count' => 0,
        'valid_tasks_count' => 0,
        'pending_or_no_data_tasks_count' => 0,
        'manual_tasks_count' => 0,
        'total_evaluated_weight' => 0,
        'total_wei_sum' => 0.0,
        'members' => [],
        'tasks' => [],
    ];
}
