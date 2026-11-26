<?php
declare(strict_types=1);

/**
 * FairShare Analytics Database Integration Helper
 * 
 * Helper terstruktur untuk membaca Database Views dan memanggil PostgreSQL Functions
 * analitik FairShare di Supabase:
 * - public.v_class_overview
 * - public.v_team_contribution_summary
 * - public.v_task_activity_summary
 * - public.fn_group_wei_summary(p_group_id UUID)
 * - public.fn_task_progress(p_group_id UUID)
 */

require_once __DIR__ . '/bootstrap.php';

/**
 * Mengambil ringkasan overview kelas dari public.v_class_overview.
 *
 * @param string|null $lecturerId UUID dosen pemilik kelas
 * @param string|null $classId UUID spesifik kelas (jika hanya butuh 1 kelas)
 * @param PDO|null $pdo
 * @return array
 */
function fs_get_class_overview(?string $lecturerId = null, ?string $classId = null, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? require_db();

    if ($classId !== null) {
        if (!valid_uuid($classId)) {
            return [];
        }
        $stmt = $pdo->prepare("
            SELECT *
            FROM public.v_class_overview
            WHERE class_id = CAST(:class_id AS UUID)
            LIMIT 1
        ");
        $stmt->execute(['class_id' => $classId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: [];
    }

    if ($lecturerId !== null) {
        if (!valid_uuid($lecturerId)) {
            return [];
        }
        $stmt = $pdo->prepare("
            SELECT *
            FROM public.v_class_overview
            WHERE lecturer_id = CAST(:lecturer_id AS UUID)
            ORDER BY class_name ASC
        ");
        $stmt->execute(['lecturer_id' => $lecturerId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $stmt = $pdo->query("
        SELECT *
        FROM public.v_class_overview
        ORDER BY class_name ASC
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Mengambil ringkasan kontribusi tim dari public.v_team_contribution_summary.
 * Perhatikan kolom identitas tim di view ini adalah team_id.
 *
 * @param string $teamId UUID kelompok/tim
 * @param PDO|null $pdo
 * @return array
 */
function fs_get_team_contribution_summary(string $teamId, ?PDO $pdo = null): array
{
    if (!valid_uuid($teamId)) {
        return [];
    }
    $pdo = $pdo ?? require_db();

    $stmt = $pdo->prepare("
        SELECT *
        FROM public.v_team_contribution_summary
        WHERE team_id = CAST(:team_id AS UUID)
        ORDER BY student_name ASC
    ");
    $stmt->execute(['team_id' => $teamId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Mengambil ringkasan kontribusi satu anggota di satu tim dari v_team_contribution_summary.
 *
 * @param string $teamId UUID kelompok
 * @param string $userId UUID anggota
 * @param PDO|null $pdo
 * @return array|null
 */
function fs_get_user_team_contribution(string $teamId, string $userId, ?PDO $pdo = null): ?array
{
    if (!valid_uuid($teamId) || !valid_uuid($userId)) {
        return null;
    }
    $pdo = $pdo ?? require_db();

    $stmt = $pdo->prepare("
        SELECT *
        FROM public.v_team_contribution_summary
        WHERE team_id = CAST(:team_id AS UUID)
          AND user_id = CAST(:user_id AS UUID)
        LIMIT 1
    ");
    $stmt->execute([
        'team_id' => $teamId,
        'user_id' => $userId,
    ]);
    $res = $stmt->fetch(PDO::FETCH_ASSOC);
    return $res ?: null;
}

/**
 * Memanggil function PostgreSQL public.fn_group_wei_summary(p_group_id UUID).
 * Merupakan sumber utama kalkulasi WEI resmi dari database.
 *
 * @param string $groupId UUID kelompok (p_group_id)
 * @param PDO|null $pdo
 * @return array List baris hasil kalkulasi per anggota
 */
function fs_get_group_wei_summary(string $groupId, ?PDO $pdo = null): array
{
    if (!valid_uuid($groupId)) {
        return [];
    }
    $pdo = $pdo ?? require_db();

    $stmt = $pdo->prepare("
        SELECT *
        FROM public.fn_group_wei_summary(CAST(:group_id AS UUID))
    ");
    $stmt->execute(['group_id' => $groupId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Memanggil function PostgreSQL public.fn_task_progress(p_group_id UUID).
 * Merupakan sumber utama progres pengerjaan tugas tim dari database.
 *
 * @param string $groupId UUID kelompok (p_group_id)
 * @param PDO|null $pdo
 * @return array|null Baris progres atau null jika tidak ditemukan
 */
function fs_get_task_progress(string $groupId, ?PDO $pdo = null): ?array
{
    if (!valid_uuid($groupId)) {
        return null;
    }
    $pdo = $pdo ?? require_db();

    $stmt = $pdo->prepare("
        SELECT *
        FROM public.fn_task_progress(CAST(:group_id AS UUID))
        LIMIT 1
    ");
    $stmt->execute(['group_id' => $groupId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Mengambil ringkasan aktivitas tugas dari public.v_task_activity_summary.
 *
 * @param string|null $teamId UUID kelompok (kolom team_id pada view)
 * @param string|null $taskId UUID tugas (kolom task_id pada view)
 * @param PDO|null $pdo
 * @return array|null
 */
function fs_get_task_activity_summary(?string $teamId = null, ?string $taskId = null, ?PDO $pdo = null): array|null
{
    $pdo = $pdo ?? require_db();

    if ($taskId !== null) {
        if (!valid_uuid($taskId)) {
            return null;
        }
        $stmt = $pdo->prepare("
            SELECT *
            FROM public.v_task_activity_summary
            WHERE task_id = CAST(:task_id AS UUID)
            LIMIT 1
        ");
        $stmt->execute(['task_id' => $taskId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    if ($teamId !== null) {
        if (!valid_uuid($teamId)) {
            return [];
        }
        $stmt = $pdo->prepare("
            SELECT *
            FROM public.v_task_activity_summary
            WHERE team_id = CAST(:team_id AS UUID)
            ORDER BY task_created_at DESC
        ");
        $stmt->execute(['team_id' => $teamId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $stmt = $pdo->query("
        SELECT *
        FROM public.v_task_activity_summary
        ORDER BY task_created_at DESC
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Validasi hak akses pengguna terhadap kelas tertentu (Server-Side).
 * Dosen: Hanya kelas yang dia kelola/buat.
 * Mahasiswa: Hanya kelas yang dia ikuti sebagai anggota.
 *
 * @param array $user Data user sesi login
 * @param string $classId UUID kelas
 * @param PDO|null $pdo
 * @return bool
 */
function fs_can_user_access_class(array $user, string $classId, ?PDO $pdo = null): bool
{
    if (!valid_uuid($classId)) {
        return false;
    }
    $userId = (string) ($user['id'] ?? '');
    if (!valid_uuid($userId)) {
        return false;
    }
    $pdo = $pdo ?? require_db();

    if (is_teacher($user)) {
        $stmt = $pdo->prepare("
            SELECT 1
            FROM public.groups
            WHERE id = CAST(:class_id AS UUID)
              AND group_type = 'class'
              AND created_by = CAST(:user_id AS UUID)
            LIMIT 1
        ");
        $stmt->execute(['class_id' => $classId, 'user_id' => $userId]);
        return (bool) $stmt->fetchColumn();
    }

    $stmt = $pdo->prepare("
        SELECT 1
        FROM public.group_members gm
        JOIN public.groups g ON g.id = gm.group_id
        WHERE g.id = CAST(:class_id AS UUID)
          AND g.group_type = 'class'
          AND gm.user_id = CAST(:user_id AS UUID)
        LIMIT 1
    ");
    $stmt->execute(['class_id' => $classId, 'user_id' => $userId]);
    return (bool) $stmt->fetchColumn();
}

/**
 * Validasi hak akses pengguna terhadap kelompok/tim tertentu (Server-Side).
 * Dosen: Hanya kelompok yang berada dalam kelas miliknya.
 * Mahasiswa: Hanya kelompok tempat ia menjadi anggota terdaftar.
 *
 * @param array $user Data user sesi login
 * @param string $teamId UUID kelompok
 * @param PDO|null $pdo
 * @return bool
 */
function fs_can_user_access_team(array $user, string $teamId, ?PDO $pdo = null): bool
{
    if (!valid_uuid($teamId)) {
        return false;
    }
    $userId = (string) ($user['id'] ?? '');
    if (!valid_uuid($userId)) {
        return false;
    }
    $pdo = $pdo ?? require_db();

    if (is_teacher($user)) {
        $stmt = $pdo->prepare("
            SELECT 1
            FROM public.groups team
            JOIN public.groups class ON class.id = team.parent_group_id
            WHERE team.id = CAST(:team_id AS UUID)
              AND team.group_type = 'team'
              AND class.created_by = CAST(:user_id AS UUID)
            LIMIT 1
        ");
        $stmt->execute(['team_id' => $teamId, 'user_id' => $userId]);
        return (bool) $stmt->fetchColumn();
    }

    $stmt = $pdo->prepare("
        SELECT 1
        FROM public.group_members gm
        JOIN public.groups team ON team.id = gm.group_id
        WHERE team.id = CAST(:team_id AS UUID)
          AND team.group_type = 'team'
          AND gm.user_id = CAST(:user_id AS UUID)
        LIMIT 1
    ");
    $stmt->execute(['team_id' => $teamId, 'user_id' => $userId]);
    return (bool) $stmt->fetchColumn();
}

/**
 * Validasi hak akses pengguna terhadap tugas tertentu (Server-Side).
 *
 * @param array $user
 * @param string $taskId
 * @param PDO|null $pdo
 * @return bool
 */
function fs_can_user_access_task(array $user, string $taskId, ?PDO $pdo = null): bool
{
    if (!valid_uuid($taskId)) {
        return false;
    }
    $pdo = $pdo ?? require_db();

    $stmt = $pdo->prepare("
        SELECT group_id::text AS group_id
        FROM public.tasks
        WHERE id = CAST(:task_id AS UUID)
        LIMIT 1
    ");
    $stmt->execute(['task_id' => $taskId]);
    $groupId = $stmt->fetchColumn();

    if (!$groupId || !valid_uuid((string) $groupId)) {
        return false;
    }

    return fs_can_user_access_team($user, (string) $groupId, $pdo);
}

/**
 * Mengambil analitik tim terpadu yang menggabungkan:
 * - Informasi kelompok & kelas induk
 * - fn_task_progress (progres tugas)
 * - v_team_contribution_summary (aktivitas per anggota)
 * - fn_group_wei_summary (perhitungan WEI database resmi)
 *
 * @param string $teamId UUID kelompok
 * @param PDO|null $pdo
 * @return array|null
 */
function fs_get_team_full_analytics(string $teamId, ?PDO $pdo = null): ?array
{
    if (!valid_uuid($teamId)) {
        return null;
    }
    $pdo = $pdo ?? require_db();

    // 1. Ambil info tim & kelas
    $stmt = $pdo->prepare("
        SELECT
            team.id::text AS team_id,
            team.name AS team_name,
            team.created_by::text AS created_by,
            team.created_at::text AS created_at,
            class.id::text AS class_id,
            class.name AS class_name,
            class.semester,
            class.academic_year,
            lecturer.name AS lecturer_name
        FROM public.groups team
        LEFT JOIN public.groups class ON class.id = team.parent_group_id
        LEFT JOIN public.users lecturer ON lecturer.id = class.created_by
        WHERE team.id = CAST(:team_id AS UUID)
          AND team.group_type = 'team'
        LIMIT 1
    ");
    $stmt->execute(['team_id' => $teamId]);
    $teamMeta = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$teamMeta) {
        return null;
    }

    // 2. Progres tugas tim (fn_task_progress)
    $progress = fs_get_task_progress($teamId, $pdo);
    if (!$progress) {
        $progress = [
            'group_id' => $teamId,
            'team_name' => $teamMeta['team_name'],
            'total_tasks' => 0,
            'completed_tasks' => 0,
            'in_progress_tasks' => 0,
            'review_tasks' => 0,
            'todo_tasks' => 0,
            'completion_percentage' => 0.0,
        ];
    }

    // 3. Ringkasan kontribusi anggota (v_team_contribution_summary)
    $contribRows = fs_get_team_contribution_summary($teamId, $pdo);

    // 4. WEI resmi dari PostgreSQL function (fn_group_wei_summary)
    $weiRows = fs_get_group_wei_summary($teamId, $pdo);
    $weiByUser = [];
    foreach ($weiRows as $w) {
        $weiByUser[strtolower((string) $w['user_id'])] = $w;
    }

    // Hitung total agregat tim dari kontribusi anggota
    $totalTeamEvents = 0;
    $totalTeamRawDelta = 0.0;
    $totalTeamFilteredDelta = 0.0;
    $totalTeamValidEvents = 0;
    $totalTeamPendingEvents = 0;
    $totalTeamRejectedEvents = 0;

    // Event valid untuk penentuan status WEI mengikuti filter fn_group_wei_summary
    // (valid_event_count). Fallback ke v_team_contribution_summary jika baris WEI
    // anggota tidak tersedia (mis. anggota non-mahasiswa).
    $statusValidEventsByUser = [];
    $totalTeamStatusValidEvents = 0;

    // LOOP 1: akumulasi total tim terlebih dahulu (sebelum status anggota ditentukan).
    foreach ($contribRows as $c) {
        $uid = strtolower((string) $c['user_id']);
        $weiData = $weiByUser[$uid] ?? null;

        $totalTeamEvents += (int) ($c['total_events'] ?? 0);
        $totalTeamRawDelta += (float) ($c['total_raw_delta'] ?? 0.0);
        $totalTeamFilteredDelta += (float) ($c['total_filtered_delta'] ?? 0.0);
        $totalTeamValidEvents += (int) ($c['valid_events'] ?? 0);
        $totalTeamPendingEvents += (int) ($c['pending_events'] ?? 0);
        $totalTeamRejectedEvents += (int) ($c['rejected_events'] ?? 0);

        $statusValid = $weiData !== null
            ? (int) ($weiData['valid_event_count'] ?? 0)
            : (int) ($c['valid_events'] ?? 0);
        $statusValidEventsByUser[$uid] = $statusValid;
        $totalTeamStatusValidEvents += $statusValid;
    }

    // LOOP 2: tentukan status setiap anggota berdasarkan total tim yang sudah lengkap.
    $members = [];
    foreach ($contribRows as $c) {
        $uid = strtolower((string) $c['user_id']);
        $weiData = $weiByUser[$uid] ?? null;

        $validEvents = (int) ($c['valid_events'] ?? 0);
        $filteredDelta = (float) ($c['total_filtered_delta'] ?? 0.0);
        $rawDelta = (float) ($c['total_raw_delta'] ?? 0.0);
        $totalEvents = (int) ($c['total_events'] ?? 0);
        $pendingEvents = (int) ($c['pending_events'] ?? 0);
        $rejectedEvents = (int) ($c['rejected_events'] ?? 0);

        // Status analitik & tampilan WEI
        $weiPercentage = ($weiData !== null && $weiData['wei_percentage'] !== null) ? (float) $weiData['wei_percentage'] : 0.0;
        $dataStatus = $weiData['data_status'] ?? 'Belum Cukup Data';

        $statusBadge = fs_determine_wei_status($statusValidEventsByUser[$uid] ?? 0, $totalTeamStatusValidEvents, $dataStatus);

        $members[] = [
            'user_id' => (string) $c['user_id'],
            'student_name' => (string) $c['student_name'],
            'nim_nip' => (string) ($c['nim_nip'] ?? ''),
            'role_in_group' => (string) ($c['role_in_group'] ?? 'anggota'),
            'total_events' => $totalEvents,
            'gdocs_events' => (int) ($c['gdocs_events'] ?? 0),
            'github_events' => (int) ($c['github_events'] ?? 0),
            'total_raw_delta' => $rawDelta,
            'total_filtered_delta' => $filteredDelta,
            'valid_events' => $validEvents,
            'pending_events' => $pendingEvents,
            'rejected_events' => $rejectedEvents,
            'manual_test_events' => (int) ($c['manual_test_events'] ?? 0),
            'unclassified_events' => (int) ($c['unclassified_events'] ?? 0),
            'last_activity_at' => $c['last_activity_at'] ?? null,
            'wei_percentage' => $weiPercentage,
            'wei_formatted' => $statusBadge['has_valid_data'] ? number_format($weiPercentage, 1) . '%' : '—',
            'status_label' => $statusBadge['label'],
            'status_class' => $statusBadge['class'],
            'has_valid_data' => $statusBadge['has_valid_data'],
        ];
    }

    return [
        'team_id' => $teamId,
        'team_name' => (string) $teamMeta['team_name'],
        'class_id' => (string) $teamMeta['class_id'],
        'class_name' => (string) $teamMeta['class_name'],
        'semester' => (string) ($teamMeta['semester'] ?? ''),
        'academic_year' => (string) ($teamMeta['academic_year'] ?? ''),
        'lecturer_name' => (string) ($teamMeta['lecturer_name'] ?? ''),
        'member_count' => count($members),
        'task_progress' => $progress,
        'total_events' => $totalTeamEvents,
        'total_raw_delta' => $totalTeamRawDelta,
        'total_filtered_delta' => $totalTeamFilteredDelta,
        'total_valid_events' => $totalTeamValidEvents,
        'total_pending_events' => $totalTeamPendingEvents,
        'total_rejected_events' => $totalTeamRejectedEvents,
        'has_sufficient_data' => $totalTeamValidEvents > 0,
        'members' => $members,
    ];
}

/**
 * Agregasi harian Contribution Trend dari telemetri tervalidasi (7 hari kalender WIB).
 *
 * Sumber tunggal: public.telemetry_logs. Filter validasi identik dengan CTE valid_events
 * pada public.fn_group_wei_summary, ditambah syarat aktor adalah mahasiswa anggota kelompok
 * (setara CTE members pada fungsi tersebut). Semua JOIN bersifat 1:1 (PK) dan syarat
 * keanggotaan memakai EXISTS sehingga tidak menggandakan baris telemetri.
 *
 * activity_timestamp bertipe TIMESTAMP WITHOUT TIME ZONE dan disimpan dalam UTC oleh sinkronisasi
 * Google, sehingga dikonversi: (ts AT TIME ZONE 'UTC') AT TIME ZONE 'Asia/Jakarta'.
 *
 * @param PDO $pdo
 * @param array $groupIds UUID kelompok (team) yang boleh dilihat pengguna
 * @param string|null $userId Jika diisi, hanya telemetri milik pengguna ini (tampilan mahasiswa)
 * @param DateTimeImmutable|null $now Waktu acuan (untuk pengujian)
 * @return array{days: array, total_events: int, total_filtered_delta: float, active_days: int, start_date: string, end_date: string, error: ?string}
 */
function fs_get_valid_contribution_trend(PDO $pdo, array $groupIds, ?string $userId = null, ?DateTimeImmutable $now = null): array
{
    static $indoDays = ['Sun' => 'Min', 'Mon' => 'Sen', 'Tue' => 'Sel', 'Wed' => 'Rab', 'Thu' => 'Kam', 'Fri' => 'Jum', 'Sat' => 'Sab'];

    $tzJakarta = new DateTimeZone('Asia/Jakarta');
    $now = ($now ?? new DateTimeImmutable('now'))->setTimezone($tzJakarta);
    $todayStart = $now->setTime(0, 0, 0);
    $rangeStart = $todayStart->modify('-6 days');
    $rangeEndExclusive = $todayStart->modify('+1 day');

    $days = [];
    for ($d = $rangeStart; $d < $rangeEndExclusive; $d = $d->modify('+1 day')) {
        $key = $d->format('Y-m-d');
        $days[$key] = [
            'date' => $key,
            'label' => $indoDays[$d->format('D')] ?? $d->format('D'),
            'sum' => 0.0,
            'count' => 0,
        ];
    }

    $result = [
        'days' => $days,
        'total_events' => 0,
        'total_filtered_delta' => 0.0,
        'active_days' => 0,
        'start_date' => $rangeStart->format('Y-m-d'),
        'end_date' => $todayStart->format('Y-m-d'),
        'error' => null,
    ];

    // Hanya UUID valid; normalisasi huruf kecil dan buang duplikat.
    $cleanIds = [];
    foreach ($groupIds as $gid) {
        $gid = strtolower(trim((string) $gid));
        if ($gid !== '' && valid_uuid($gid)) {
            $cleanIds[$gid] = true;
        }
    }
    $cleanIds = array_keys($cleanIds);

    if ($cleanIds === []) {
        return $result; // Tidak ada kelompok terlihat: grafik nol, bukan error.
    }
    if ($userId !== null && !valid_uuid($userId)) {
        return $result;
    }

    $utc = new DateTimeZone('UTC');
    $params = [
        'group_ids' => '{' . implode(',', $cleanIds) . '}', // literal array PostgreSQL, di-CAST ke uuid[]
        'start_utc' => $rangeStart->setTimezone($utc)->format('Y-m-d H:i:s'),
        'end_utc' => $rangeEndExclusive->setTimezone($utc)->format('Y-m-d H:i:s'),
    ];
    $userFilter = '';
    if ($userId !== null) {
        $userFilter = ' AND tl.user_id = CAST(:user_id AS UUID)';
        $params['user_id'] = $userId;
    }

    $sql = "
        SELECT
            ((tl.activity_timestamp AT TIME ZONE 'UTC') AT TIME ZONE 'Asia/Jakarta')::date::text AS activity_date,
            SUM(tl.filtered_delta) AS total_filtered_delta,
            COUNT(*) AS valid_event_count
        FROM public.telemetry_logs tl
        JOIN public.groups g
            ON g.id = tl.group_id
           AND g.group_type = 'team'
        JOIN public.tasks t
            ON t.id = tl.task_id
           AND t.group_id = tl.group_id
        WHERE tl.group_id = ANY(CAST(:group_ids AS UUID[]))
          AND tl.activity_timestamp >= CAST(:start_utc AS TIMESTAMP)
          AND tl.activity_timestamp <  CAST(:end_utc AS TIMESTAMP)
          AND tl.task_id IS NOT NULL
          AND tl.user_id IS NOT NULL
          AND tl.source IN ('gdocs', 'github')
          AND tl.raw_delta >= 0
          AND tl.filtered_delta > 0
          AND LOWER(TRIM(COALESCE(tl.metadata->>'validation_status', ''))) IN ('valid', 'verified', 'terverifikasi')
          AND COALESCE(tl.metadata->>'entry_mode', '') <> 'manual_test'
          AND EXISTS (
              SELECT 1
              FROM public.group_members gm
              JOIN public.users u ON u.id = gm.user_id
              WHERE gm.group_id = tl.group_id
                AND gm.user_id = tl.user_id
                AND u.role = 'mahasiswa'
          )
          {$userFilter}
        GROUP BY 1
        ORDER BY 1
    ";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = (string) $row['activity_date'];
            if (!isset($result['days'][$key])) {
                continue;
            }
            $result['days'][$key]['sum'] = (float) $row['total_filtered_delta'];
            $result['days'][$key]['count'] = (int) $row['valid_event_count'];
        }
    } catch (Throwable $e) {
        error_log('FairShare contribution trend query failed: ' . $e->getMessage());
        $result['error'] = 'Data tren kontribusi tidak dapat dimuat saat ini.';
        return $result;
    }

    foreach ($result['days'] as $day) {
        $result['total_events'] += $day['count'];
        $result['total_filtered_delta'] += $day['sum'];
        if ($day['count'] > 0) {
            $result['active_days']++;
        }
    }

    return $result;
}

/**
 * Menentukan kelompok aktif mahasiswa pada dashboard dengan validasi otorisasi server-side.
 *
 * Aturan keamanan & persistensi:
 * 1. Hanya kelompok yang ada di $visibleGroups (hasil visible_groups($user)) yang diizinkan.
 * 2. Jika $pdo tersedia, juga diverifikasi ulang via fs_can_user_access_team() (defense-in-depth).
 * 3. Permintaan ?group_id yang tidak valid / milik orang lain ditolak (rejected_request = true)
 *    tanpa membocorkan apakah kelompok tersebut ada, lalu fallback ke pilihan sesi/kelompok pertama.
 * 4. Pilihan sah disimpan di $session['dashboard_selected_group_id'] agar bertahan saat reload.
 *
 * @param array $user Data pengguna sesi login
 * @param array $visibleGroups Daftar kelompok milik mahasiswa dari visible_groups($user)
 * @param string|null $requestedGroupId Nilai dari $_GET['group_id'] (jika ada)
 * @param array $session Referensi $_SESSION
 * @param PDO|null $pdo Koneksi DB opsional untuk verifikasi tambahan
 * @return array{group: ?array, group_id: string, rejected_request: bool}
 */
function fs_resolve_student_dashboard_group(
    array $user,
    array $visibleGroups,
    ?string $requestedGroupId,
    array &$session,
    ?PDO $pdo = null
): array {
    $allowedById = [];
    foreach ($visibleGroups as $g) {
        $gid = strtolower(trim((string) ($g['id'] ?? '')));
        if ($gid !== '' && valid_uuid($gid)) {
            $allowedById[$gid] = $g;
        }
    }

    $requested = strtolower(trim((string) ($requestedGroupId ?? '')));
    $hasRequest = ($requested !== '');

    if ($allowedById === []) {
        unset($session['dashboard_selected_group_id']);
        return [
            'group' => null,
            'group_id' => '',
            'rejected_request' => $hasRequest,
        ];
    }

    $isAuthorized = static function (string $gid) use ($allowedById, $user, $pdo): bool {
        if (!valid_uuid($gid) || !isset($allowedById[$gid])) {
            return false;
        }
        if ($pdo !== null && !fs_can_user_access_team($user, $gid, $pdo)) {
            return false;
        }
        return true;
    };

    $rejectedRequest = false;
    if ($hasRequest) {
        if ($isAuthorized($requested)) {
            $session['dashboard_selected_group_id'] = $requested;
            return [
                'group' => $allowedById[$requested],
                'group_id' => $requested,
                'rejected_request' => false,
            ];
        }
        $rejectedRequest = true;
    }

    $savedId = strtolower(trim((string) ($session['dashboard_selected_group_id'] ?? '')));
    if ($savedId !== '' && $isAuthorized($savedId)) {
        return [
            'group' => $allowedById[$savedId],
            'group_id' => $savedId,
            'rejected_request' => $rejectedRequest,
        ];
    }

    $firstId = array_key_first($allowedById);
    $session['dashboard_selected_group_id'] = $firstId;
    return [
        'group' => $allowedById[$firstId],
        'group_id' => $firstId,
        'rejected_request' => $rejectedRequest,
    ];
}

/**
 * Menentukan status dan badge WEI agar tidak menyesatkan.
 * Sesuai pedoman:
 * - Sudah memiliki aktivitas tervalidasi: jika valid_events > 0.
 * - Belum memiliki aktivitas tervalidasi: jika valid_events == 0 tetapi kelompok sudah punya aktivitas valid.
 * - Belum cukup data: jika seluruh kelompok belum punya aktivitas valid.
 */
function fs_determine_wei_status(int $memberValidEvents, int $teamValidEvents, string $dbDataStatus = ''): array
{
    if ($teamValidEvents === 0) {
        return [
            'label' => 'Belum cukup data',
            'class' => 'waiting',
            'has_valid_data' => false,
            'description' => 'Belum ada aktivitas tervalidasi di seluruh anggota kelompok.',
        ];
    }

    if ($memberValidEvents > 0) {
        return [
            'label' => 'Sudah memiliki aktivitas tervalidasi',
            'class' => 'good',
            'has_valid_data' => true,
            'description' => 'Aktivitas telah melewati validasi struktural telemetri.',
        ];
    }

    return [
        'label' => 'Belum memiliki aktivitas tervalidasi',
        'class' => 'danger',
        'has_valid_data' => true,
        'description' => 'Anggota terdaftar namun belum mencatatkan aktivitas valid.',
    ];
}
