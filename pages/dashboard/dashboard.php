<?php
declare(strict_types=1);

if (!defined('FS_ROOT')) {
    define('FS_ROOT', dirname(__DIR__, 2));
}

require_once FS_ROOT . '/includes/bootstrap.php';

$user = require_login();
$teacher = is_teacher($user);
$userId = (string) $user['id'];
$pdo = require_db();

// AMBIL KELAS & KELOMPOK VISIBLE
$groups = visible_groups($user);
$myClasses = visible_classes($user);
$myClassCount = count($myClasses);
$allTasks = all_tasks();
$groupIds = array_map('strtolower', array_column($groups, 'id'));
$tasks = $teacher
    ? array_values(array_filter($allTasks, fn($t) => in_array(strtolower((string) ($t['group_id'] ?? '')), $groupIds, true)))
    : user_tasks($userId);

$logs = all_telemetry();
$mine = $teacher
    ? array_values(array_filter($logs, fn($l) => in_array(strtolower((string) ($l['group_id'] ?? '')), $groupIds, true)))
    : all_telemetry($userId);

$verifiedCount = count(array_filter($mine, fn($l) => ($l['status'] ?? '') === 'Terverifikasi' || ($l['validation_status'] ?? '') === 'valid'));

// ==============================================================
// AKTIVITAS TERBARU: Diambil dari telemetry_logs (Supabase)
// - Dosen  : telemetri dari SEMUA kelompok yang terlihat (access-scoped).
// - Mahasiswa: hanya telemetri milik user pada kelompok terpilih.
// - Fallback ke activity.json hanya jika koneksi DB gagal.
// ==============================================================
$recent = [];
$recentDbError = false;
try {
    if ($teacher) {
        // Dosen: ambil aktivitas telemetri dari kelompok yang terpantau
        if (!empty($groupIds)) {
            $pgGroupIds = '{' . implode(',', array_map('strtolower', $groupIds)) . '}';
            $recentStmt = $pdo->prepare("
                SELECT
                    tl.id::text AS id,
                    u.name AS actor_name,
                    COALESCE(
                        NULLIF(tl.metadata->>'event_type', ''),
                        CASE
                            WHEN tl.source = 'gdocs' THEN 'Edit Google Docs'
                            WHEN tl.source = 'github' THEN 'Aktivitas GitHub'
                            ELSE 'Aktivitas Telemetri'
                        END
                    ) AS activity_title,
                    t.title AS task_title,
                    g.name AS group_name,
                    tl.source,
                    LOWER(TRIM(COALESCE(tl.metadata->>'validation_status', ''))) AS val_status,
                    (tl.activity_timestamp AT TIME ZONE 'UTC') AT TIME ZONE 'Asia/Jakarta' AS activity_wib
                FROM public.telemetry_logs tl
                LEFT JOIN public.users u ON u.id = tl.user_id
                LEFT JOIN public.tasks t ON t.id = tl.task_id
                JOIN public.groups g ON g.id = tl.group_id AND g.group_type = 'team'
                WHERE tl.group_id = ANY(CAST(:group_ids AS UUID[]))
                ORDER BY tl.activity_timestamp DESC
                LIMIT 5
            ");
            $recentStmt->execute(['group_ids' => $pgGroupIds]);
            $recentRows = $recentStmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $recentRows = [];
        }
    } else {
        // Mahasiswa: hanya telemetri milik dirinya pada kelompok yang terlihat
        $visibleGroupIds = array_map('strtolower', array_column($groups, 'id'));
        if (!empty($visibleGroupIds)) {
            $pgGroupIdsMhs = '{' . implode(',', $visibleGroupIds) . '}';
            $recentStmt = $pdo->prepare("
                SELECT
                    tl.id::text AS id,
                    u.name AS actor_name,
                    COALESCE(
                        NULLIF(tl.metadata->>'event_type', ''),
                        CASE
                            WHEN tl.source = 'gdocs' THEN 'Edit Google Docs'
                            WHEN tl.source = 'github' THEN 'Aktivitas GitHub'
                            ELSE 'Aktivitas Telemetri'
                        END
                    ) AS activity_title,
                    t.title AS task_title,
                    g.name AS group_name,
                    tl.source,
                    LOWER(TRIM(COALESCE(tl.metadata->>'validation_status', ''))) AS val_status,
                    (tl.activity_timestamp AT TIME ZONE 'UTC') AT TIME ZONE 'Asia/Jakarta' AS activity_wib
                FROM public.telemetry_logs tl
                LEFT JOIN public.users u ON u.id = tl.user_id
                LEFT JOIN public.tasks t ON t.id = tl.task_id
                JOIN public.groups g ON g.id = tl.group_id AND g.group_type = 'team'
                WHERE tl.user_id = CAST(:user_id AS UUID)
                  AND tl.group_id = ANY(CAST(:group_ids AS UUID[]))
                ORDER BY tl.activity_timestamp DESC
                LIMIT 5
            ");
            $recentStmt->execute([
                'user_id'   => $userId,
                'group_ids' => $pgGroupIdsMhs,
            ]);
            $recentRows = $recentStmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $recentRows = [];
        }
    }

    // Normalisasi baris telemetri ke format yang dipakai template HTML
    foreach ($recentRows as $row) {
        $valStatus = (string) ($row['val_status'] ?? '');
        $dotType = match (true) {
            in_array($valStatus, ['valid', 'terverifikasi', 'verified'], true) => 'success',
            $valStatus === 'rejected' => 'danger',
            $valStatus === 'duplicate' => 'warning',
            default => 'info',
        };
        $sourceLabel = match ((string) ($row['source'] ?? '')) {
            'gdocs'  => 'Google Docs',
            'github' => 'GitHub',
            default  => 'Telemetri',
        };
        $taskLabel = !empty($row['task_title']) ? ' · Tugas: ' . $row['task_title'] : '';
        $groupLabel = !empty($row['group_name']) ? ' · ' . $row['group_name'] : '';
        $timeFormatted = !empty($row['activity_wib'])
            ? date('d M Y H:i', strtotime((string) $row['activity_wib'])) . ' WIB'
            : '—';

        $recent[] = [
            'id'     => (string) ($row['id'] ?? ''),
            'title'  => (string) ($row['activity_title'] ?? 'Aktivitas'),
            'detail' => $sourceLabel . $taskLabel . $groupLabel,
            'type'   => $dotType,
            'time'   => $timeFormatted,
            'user'   => (string) ($row['actor_name'] ?? 'Tidak diketahui'),
        ];
    }
} catch (Throwable $recentException) {
    error_log('FairShare dashboard recent activity DB error: ' . $recentException->getMessage());
    $recentDbError = true;
    // Fallback ke activity.json dengan access control berbasis nama pengguna
    $jsonActivity = read_data('activity.json', []);
    if (!$teacher) {
        $jsonActivity = array_values(array_filter($jsonActivity, fn($l) => ($l['user'] ?? '') === ($user['name'] ?? '')));
    }
    $recent = array_slice($jsonActivity, 0, 5);
}

// ==============================================================
// METRIK ANALITIK DARI DATABASE (VIEWS & FUNCTIONS)
// ==============================================================
if ($teacher) {
    // 1. Dosen: Ambil v_class_overview milik dosen login
    $overviewClasses = fs_get_class_overview($userId, null, $pdo);
    $totalStudents = (int) array_sum(array_column($overviewClasses, 'total_students'));
    $totalTeams = (int) array_sum(array_column($overviewClasses, 'total_teams'));
    $totalTasks = (int) array_sum(array_column($overviewClasses, 'total_tasks'));
    $completedTasks = (int) array_sum(array_column($overviewClasses, 'completed_tasks'));
    $uncompletedTasks = max(0, $totalTasks - $completedTasks);
    $classCompletion = $totalTasks > 0 ? round(($completedTasks / $totalTasks) * 100, 1) : 0.0;

    // Hitung status telemetry dari kelompok terpantau
    $validTelemetryCount = count(array_filter($mine, fn($l) => ($l['validation_status'] ?? '') === 'valid' || ($l['status'] ?? '') === 'Terverifikasi'));
    $pendingTelemetryCount = count(array_filter($mine, fn($l) => in_array(($l['validation_status'] ?? ''), ['pending', 'menunggu kalibrasi bobot'], true)));
    $rejectedTelemetryCount = count(array_filter($mine, fn($l) => ($l['validation_status'] ?? '') === 'rejected'));
} else {
    // 2. Mahasiswa: Resolusi kelompok aktif (server-side validated & session-persisted)
    $requestedGroupId = isset($_GET['group_id']) ? (string) $_GET['group_id'] : null;
    $resolvedGroup = fs_resolve_student_dashboard_group($user, $groups, $requestedGroupId, $_SESSION, $pdo);
    $primaryGroup = $resolvedGroup['group'];
    $primaryGroupId = $resolvedGroup['group_id'];
    $rejectedGroupRequest = $resolvedGroup['rejected_request'];

    // Filter daftar tugas dashboard khusus kelompok terpilih (tidak dicampur antarkelompok)
    $tasks = $primaryGroupId !== ''
        ? array_values(array_filter($tasks, fn($t) => strtolower((string) ($t['group_id'] ?? '')) === strtolower($primaryGroupId)))
        : [];

    // Informasi anggota kelompok terpilih
    $selectedMembers = is_array($primaryGroup['members'] ?? null) ? $primaryGroup['members'] : [];
    $selectedMemberCount = count($selectedMembers);
    $myRoleInGroup = 'anggota';
    foreach ($selectedMembers as $sm) {
        if (strtolower((string) ($sm['user_id'] ?? '')) === strtolower($userId)) {
            $myRoleInGroup = (string) ($sm['role_in_group'] ?? 'anggota');
            break;
        }
    }

    // Progres tugas tim dari fn_task_progress (khusus kelompok terpilih)
    $teamProgress = $primaryGroupId !== '' ? fs_get_task_progress($primaryGroupId, $pdo) : null;
    $teamTotalTasks = (int) ($teamProgress['total_tasks'] ?? count($tasks));
    $teamCompletedTasks = (int) ($teamProgress['completed_tasks'] ?? count(array_filter($tasks, fn($t) => ($t['status_db'] ?? '') === 'done')));
    $teamProgressPct = $teamProgress ? (float) $teamProgress['completion_percentage'] : ($teamTotalTasks > 0 ? round(($teamCompletedTasks / $teamTotalTasks) * 100, 1) : 0.0);

    // Ringkasan kontribusi pribadi dari v_team_contribution_summary (khusus kelompok terpilih)
    $myContribution = $primaryGroupId !== '' ? fs_get_user_team_contribution($primaryGroupId, $userId, $pdo) : null;
    $myRawDelta = (float) ($myContribution['total_raw_delta'] ?? 0.0);
    $myFilteredDelta = (float) ($myContribution['total_filtered_delta'] ?? 0.0);
    $myValidEvents = (int) ($myContribution['valid_events'] ?? 0);
    $myPendingEvents = (int) ($myContribution['pending_events'] ?? 0);
    $myRejectedEvents = (int) ($myContribution['rejected_events'] ?? 0);
    $myLastActivityAt = $myContribution['last_activity_at'] ?? null;

    // WEI dari PostgreSQL function fn_group_wei_summary (khusus kelompok terpilih)
    $groupWeiRows = $primaryGroupId !== '' ? fs_get_group_wei_summary($primaryGroupId, $pdo) : [];
    $myWeiRow = null;
    $teamHasValidEvents = false;
    foreach ($groupWeiRows as $gw) {
        if ((int) ($gw['valid_event_count'] ?? 0) > 0) {
            $teamHasValidEvents = true;
        }
        if (strtolower((string) $gw['user_id']) === strtolower($userId)) {
            $myWeiRow = $gw;
        }
    }

    $myWeiScore = ($myWeiRow !== null && $myWeiRow['wei_percentage'] !== null) ? (float) $myWeiRow['wei_percentage'] : 0.0;
    $myWeiDataStatus = $myWeiRow['data_status'] ?? 'Belum Cukup Data';
    $myWeiStatus = fs_determine_wei_status($myValidEvents, $teamHasValidEvents ? 1 : 0, $myWeiDataStatus);
}

// ==============================================================
// CONTRIBUTION TREND: HANYA TELEMETRI TERVALIDASI (public.telemetry_logs)
// Nilai grafik = SUM(filtered_delta) per hari WIB; tooltip = COUNT(*) event valid.
// Filter identik dengan fn_group_wei_summary. activity.json & tasks TIDAK dipakai.
// Dosen: seluruh kelompok di kelas miliknya.
// Mahasiswa: telemetri miliknya khusus pada kelompok terpilih ($primaryGroupId).
// ==============================================================
$trendGroupIds = $teacher
    ? $groupIds
    : ($primaryGroupId !== '' ? [$primaryGroupId] : []);
$trend = fs_get_valid_contribution_trend($pdo, $trendGroupIds, $teacher ? null : $userId);
$dayValues = array_values($trend['days']);
$totalPoints = count($dayValues);
$maxSum = $totalPoints > 0 ? (float) max(array_column($dayValues, 'sum')) : 0.0;
$maxCount = $trend['total_events']; // dipakai untuk catatan grafik

// Skala dinamis sumbu Y (kelipatan 4 bulat, mulai dari 0, aman saat semua 0)
$yStep = $maxSum <= 4.0 ? 1 : (int) ceil($maxSum / 4.0);
$upperBound = $yStep * 4;
$yTicks = [$upperBound, $yStep * 3, $yStep * 2, $yStep, 0];

$indoMonths = [
    '01' => 'Januari', '02' => 'Februari', '03' => 'Maret', '04' => 'April',
    '05' => 'Mei', '06' => 'Juni', '07' => 'Juli', '08' => 'Agustus',
    '09' => 'September', '10' => 'Oktober', '11' => 'November', '12' => 'Desember',
];
$indoFullDays = [
    'Min' => 'Minggu', 'Sen' => 'Senin', 'Sel' => 'Selasa',
    'Rab' => 'Rabu', 'Kam' => 'Kamis', 'Jum' => 'Jumat', 'Sab' => 'Sabtu',
];

$barDays = [];
foreach ($dayValues as $d) {
    $parts = explode('-', (string) $d['date']);
    $dayNum = isset($parts[2]) ? ltrim($parts[2], '0') : '';
    $monthName = $indoMonths[$parts[1] ?? ''] ?? ($parts[1] ?? '');
    $yearNum = $parts[0] ?? '';
    $fullDate = trim($dayNum . ' ' . $monthName . ' ' . $yearNum);
    $fullDay = $indoFullDays[$d['label']] ?? $d['label'];
    $sumVal = (float) $d['sum'];
    $evtCount = (int) $d['count'];
    $heightPct = ($upperBound > 0 && $sumVal > 0.0)
        ? min(100.0, round(($sumVal / $upperBound) * 100.0, 2))
        : 0.0;

    $barDays[] = [
        'date' => (string) $d['date'],
        'full_date' => $fullDate,
        'full_day' => $fullDay,
        'label' => (string) $d['label'],
        'sum' => $sumVal,
        'sum_formatted' => number_format($sumVal, 2),
        'count' => $evtCount,
        'height_pct' => $heightPct,
        'aria_label' => $fullDay . ', ' . $fullDate . ': Filtered Delta ' . number_format($sumVal, 2) . ', ' . $evtCount . ' event valid',
    ];
}

// Indikator pendukung Faktor WEI (7 hari terakhir, telemetri tervalidasi, WIB).
// Bukan komponen rumus WEI; rumus WEI tetap pada fn_group_wei_summary.
$factorValidEvents = (int) $trend['total_events'];
$factorActiveDays = (int) $trend['active_days'];
$factorVolumePct = min(100, $factorValidEvents * 20); // skala tampilan: 5 event valid = bar penuh
$factorConsistencyPct = (int) round(($factorActiveDays / 7) * 100);

layout_start('Dashboard', 'dashboard');

$quickActionHtml = '<div class="quick-action-wrap" data-quick-action-root>'
    . '<button class="btn primary quick-action-trigger" type="button" data-quick-action-toggle aria-haspopup="menu" aria-expanded="false" aria-controls="quickActionMenu" id="quickActionBtn">'
    . '<span>＋ Buat</span> <span class="action-caret" aria-hidden="true">▾</span>'
    . '</button>'
    . '<div class="quick-action-menu" id="quickActionMenu" data-quick-action-menu role="menu" aria-label="Menu Aksi Cepat" hidden>';

if ($teacher) {
    $quickActionHtml .= '<a class="quick-action-item" role="menuitem" href="classes.php?modal=create">'
        . '<div class="quick-action-icon violet">'
        . '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/></svg>'
        . '</div>'
        . '<div class="quick-action-text">'
        . '<strong>Buat Kelas</strong>'
        . '<span>Buat kelas baru dan bagikan kode undangan</span>'
        . '</div>'
        . '</a>';

    if ($myClassCount === 0) {
        $quickActionHtml .= '<a class="quick-action-item" role="menuitem" href="classes.php?modal=create" title="Buat kelas terlebih dahulu">'
            . '<div class="quick-action-icon blue">'
            . '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>'
            . '</div>'
            . '<div class="quick-action-text">'
            . '<strong>Buat Kelompok</strong>'
            . '<span>Buat kelas terlebih dahulu untuk kelompok</span>'
            . '</div>'
            . '</a>';
    } elseif ($myClassCount === 1) {
        $classId = (string) $myClasses[0]['id'];
        $quickActionHtml .= '<a class="quick-action-item" role="menuitem" href="class_detail.php?id=' . urlencode($classId) . '&modal=create_team">'
            . '<div class="quick-action-icon blue">'
            . '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>'
            . '</div>'
            . '<div class="quick-action-text">'
            . '<strong>Buat Kelompok</strong>'
            . '<span>Bentuk kelompok di ' . e($myClasses[0]['name']) . '</span>'
            . '</div>'
            . '</a>';
    } else {
        $quickActionHtml .= '<button type="button" class="quick-action-item" role="menuitem" data-modal-open="selectClassForTeamModal">'
            . '<div class="quick-action-icon blue">'
            . '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>'
            . '</div>'
            . '<div class="quick-action-text">'
            . '<strong>Buat Kelompok</strong>'
            . '<span>Pilih kelas untuk membuat kelompok baru</span>'
            . '</div>'
            . '</button>';
    }

    $quickActionHtml .= '<a class="quick-action-item" role="menuitem" href="tasks.php#new-task">'
        . '<div class="quick-action-icon orange">'
        . '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1" ry="1"/><path d="M9 12h6"/><path d="M9 16h6"/></svg>'
        . '</div>'
        . '<div class="quick-action-text">'
        . '<strong>Buat Tugas</strong>'
        . '<span>Buat tugas kelompok dengan Smart Assignment</span>'
        . '</div>'
        . '</a>';
} else {
    $quickActionHtml .= '<a class="quick-action-item" role="menuitem" href="classes.php?modal=join">'
        . '<div class="quick-action-icon violet">'
        . '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/></svg>'
        . '</div>'
        . '<div class="quick-action-text">'
        . '<strong>Gabung Kelas</strong>'
        . '<span>Masukkan kode undangan dari dosen pengampu</span>'
        . '</div>'
        . '</a>';

    if ($myClassCount === 0) {
        $quickActionHtml .= '<a class="quick-action-item" role="menuitem" href="classes.php?modal=join" title="Gabung ke kelas terlebih dahulu">'
            . '<div class="quick-action-icon blue">'
            . '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>'
            . '</div>'
            . '<div class="quick-action-text">'
            . '<strong>Buat Kelompok</strong>'
            . '<span>Gabung ke kelas terlebih dahulu</span>'
            . '</div>'
            . '</a>';
    } elseif ($myClassCount === 1) {
        $classId = (string) $myClasses[0]['id'];
        $quickActionHtml .= '<a class="quick-action-item" role="menuitem" href="teams.php?class_id=' . urlencode($classId) . '#create-group">'
            . '<div class="quick-action-icon blue">'
            . '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>'
            . '</div>'
            . '<div class="quick-action-text">'
            . '<strong>Buat Kelompok</strong>'
            . '<span>Bentuk kelompok di ' . e($myClasses[0]['name']) . '</span>'
            . '</div>'
            . '</a>';
    } else {
        $quickActionHtml .= '<a class="quick-action-item" role="menuitem" href="teams.php#create-group">'
            . '<div class="quick-action-icon blue">'
            . '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>'
            . '</div>'
            . '<div class="quick-action-text">'
            . '<strong>Buat Kelompok</strong>'
            . '<span>Pilih kelas dan bentuk kelompok baru</span>'
            . '</div>'
            . '</a>';
    }

    $quickActionHtml .= '<a class="quick-action-item" role="menuitem" href="teams.php#create-group">'
        . '<div class="quick-action-icon green">'
        . '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg>'
        . '</div>'
        . '<div class="quick-action-text">'
        . '<strong>Gabung Kelompok</strong>'
        . '<span>Masuk ke kelompok dengan kode undangan</span>'
        . '</div>'
        . '</a>';
}

$quickActionHtml .= '</div></div>';

page_heading(
    $teacher ? 'DOSEN / OVERVIEW' : 'WORKSPACE OVERVIEW',
    'Halo, ' . explode(' ', $user['name'])[0] . ' 👋',
    $teacher ? 'Pantau kelas pengajaran, kelompok mahasiswa, dan perkembangan tugas kolaborasi.' : 'Pantau kontribusi, progres tugas, dan transparansi kolaborasi tim kamu.',
    $quickActionHtml
);

// WELCOME BANNER: ORBIT, LOGO, DAN LIVE WORKSPACE DALAM SATU CENTER ALIGNMENT
echo '<section class="welcome-banner">
    <div class="banner-copy">
        <span class="banner-overline">FAIRSHARE INSIGHT</span>
        <h2>Kolaborasi yang baik dimulai dari transparansi.</h2>
        <p>Semua progres penting tim dalam satu workspace.</p>
    </div>
    <div class="banner-art">
        <div class="banner-orbit-stage">
            <span class="art-ring ring-a" aria-hidden="true"></span>
            <span class="art-ring ring-b" aria-hidden="true"></span>
            <div class="art-core art-logo">
                <img src="assets/fairshare-logo.svg" alt="Logo FairShare">
            </div>
        </div>
        <div class="art-tag">LIVE WORKSPACE</div>
    </div>
</section>';
?>

<!-- KARTU STATISTIK UTAMA DASHBOARD -->
<?php if ($teacher): ?>
    <div class="stats-grid">
        <article class="stat-card">
            <div class="stat-top">
                <span class="stat-label">KELAS DIKELOLA</span>
                <span class="stat-icon violet">▤</span>
            </div>
            <div class="stat-number"><?= count($overviewClasses) ?><small> kelas</small></div>
            <div class="stat-foot">
                <span class="pill purple"><?= $totalTeams ?> kelompok aktif</span>
                <span class="muted">di database</span>
            </div>
        </article>

        <article class="stat-card">
            <div class="stat-top">
                <span class="stat-label">MAHASISWA AKTIF</span>
                <span class="stat-icon blue">♧</span>
            </div>
            <div class="stat-number"><?= $totalStudents ?><small> mahasiswa</small></div>
            <div class="stat-foot">
                <span class="pill blue-pill">v_class_overview</span>
                <span class="muted">terdaftar di kelasmu</span>
            </div>
        </article>

        <article class="stat-card">
            <div class="stat-top">
                <span class="stat-label">PENYELESAIAN TUGAS</span>
                <span class="stat-icon green">✓</span>
            </div>
            <div class="stat-number"><?= $completedTasks ?><small> / <?= $totalTasks ?></small></div>
            <div class="stat-foot">
                <span class="pill green-pill"><?= number_format($classCompletion, 1) ?>% selesai</span>
                <span class="muted"><?= $uncompletedTasks ?> belum selesai</span>
            </div>
            <div class="mini-progress green-progress">
                <span style="width:<?= min(100, $classCompletion) ?>%"></span>
            </div>
        </article>

        <article class="stat-card">
            <div class="stat-top">
                <span class="stat-label">AKTIVITAS TELEMETRI</span>
                <span class="stat-icon orange">⌁</span>
            </div>
            <div class="stat-number"><?= count($mine) ?><small> event</small></div>
            <div class="stat-foot">
                <span class="pill orange-pill"><?= $validTelemetryCount ?> valid</span>
                <span class="muted"><?= $pendingTelemetryCount ?> pending · <?= $rejectedTelemetryCount ?> ditolak</span>
            </div>
        </article>
    </div>
<?php else: ?>
    <?php if (!empty($rejectedGroupRequest)): ?>
        <div class="alert warning" role="alert" style="margin-bottom:14px;">
            Akses ke kelompok yang diminta tidak diizinkan. Menampilkan kelompok aktif yang kamu ikuti.
        </div>
    <?php endif; ?>

    <?php if (count($groups) > 1): ?>
        <section class="panel" style="margin-bottom:16px; padding:14px 18px; display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:12px;">
            <div style="display:flex; flex-direction:column; gap:2px;">
                <span class="stat-label" style="font-size:11px;">KELOMPOK AKTIF DASHBOARD</span>
                <small class="muted">
                    <?= e($primaryGroup['parent_class_name'] ?? ($primaryGroup['course'] ?? 'Kelas')) ?>
                    · <?= (int) $selectedMemberCount ?> anggota
                    · Peran: <b><?= e(ucfirst($myRoleInGroup)) ?></b>
                </small>
            </div>
            <form method="get" action="dashboard.php" class="dashboard-group-form" style="display:flex; flex-wrap:wrap; align-items:center; gap:8px; margin:0; max-width:100%;">
                <label for="dashboardGroupSelect" class="muted" style="font-size:12px; white-space:nowrap;">Pilih kelompok:</label>
                <select id="dashboardGroupSelect" name="group_id" onchange="this.form.submit()" style="flex:1 1 180px; min-width:0; max-width:100%; padding:7px 10px; border-radius:8px; border:1px solid var(--border, #d8dce8); background:var(--surface, #ffffff); color:var(--text, #172033); font-size:13px;">
                    <?php foreach ($groups as $gOpt): ?>
                        <?php
                            $optId = (string) $gOpt['id'];
                            $optSelected = strtolower($optId) === strtolower($primaryGroupId);
                            $optClass = trim((string) ($gOpt['parent_class_name'] ?? ($gOpt['course'] ?? '')));
                            $optLabel = (string) $gOpt['name'] . ($optClass !== '' ? ' — ' . $optClass : '');
                        ?>
                        <option value="<?= e($optId) ?>" <?= $optSelected ? 'selected' : '' ?>><?= e($optLabel) ?></option>
                    <?php endforeach; ?>
                </select>
                <noscript><button class="btn secondary small" type="submit">Tampilkan</button></noscript>
                <?php if ($primaryGroupId !== ''): ?>
                    <a class="btn secondary small" href="team_detail.php?id=<?= urlencode($primaryGroupId) ?>">Detail tim ↗</a>
                <?php endif; ?>
            </form>
        </section>
    <?php endif; ?>

    <div class="stats-grid">
        <article class="stat-card">
            <div class="stat-top">
                <span class="stat-label">WEI SCORE SAYA</span>
                <span class="stat-icon violet">📊</span>
            </div>
            <div class="stat-number">
                <?php if ($myWeiStatus['has_valid_data']): ?>
                    <?= number_format($myWeiScore, 1) ?><small>%</small>
                <?php else: ?>
                    —
                <?php endif; ?>
            </div>
            <div class="stat-foot">
                <span class="pill <?= e($myWeiStatus['class']) ?>">
                    <?= e($myWeiStatus['label']) ?>
                </span>
                <span class="muted"><?= $primaryGroup ? e($primaryGroup['name']) : 'Kelompok kamu' ?></span>
            </div>
            <div class="mini-progress">
                <span style="width:<?= min(100, $myWeiScore) ?>%"></span>
            </div>
        </article>

        <article class="stat-card">
            <div class="stat-top">
                <span class="stat-label">KELAS & KELOMPOK</span>
                <span class="stat-icon blue">👥</span>
            </div>
            <div class="stat-number"><?= count($groups) ?><small> kelompok</small></div>
            <div class="stat-foot">
                <span class="pill blue-pill"><?= count($myClasses) ?> kelas diikuti</span>
                <span class="muted"><?= $primaryGroup ? ((int) $selectedMemberCount . ' anggota · ' . e(ucfirst($myRoleInGroup))) : 'ruang kolaborasi' ?></span>
            </div>
        </article>

        <article class="stat-card">
            <div class="stat-top">
                <span class="stat-label">PROGRES TUGAS TIM</span>
                <span class="stat-icon green">📝</span>
            </div>
            <div class="stat-number"><?= $teamCompletedTasks ?><small> / <?= $teamTotalTasks ?></small></div>
            <div class="stat-foot">
                <span class="pill green-pill"><?= number_format($teamProgressPct, 1) ?>% selesai</span>
                <span class="muted">fn_task_progress</span>
            </div>
            <div class="mini-progress green-progress">
                <span style="width:<?= min(100, $teamProgressPct) ?>%"></span>
            </div>
        </article>

        <article class="stat-card">
            <div class="stat-top">
                <span class="stat-label">FILTERED DELTA PRIBADI</span>
                <span class="stat-icon orange">⚖️</span>
            </div>
            <div class="stat-number"><?= number_format($myFilteredDelta, 2) ?></div>
            <div class="stat-foot">
                <span class="pill orange-pill"><?= $myValidEvents ?> event valid</span>
                <span class="muted">Raw: <?= number_format($myRawDelta, 2) ?></span>
            </div>
        </article>
    </div>
<?php endif; ?>

<!-- GRAFIK TREN KONTRIBUSI & FAKTOR WEI -->
<div class="content-grid dashboard-grid">
    <section class="panel chart-panel">
        <div class="panel-heading">
            <div>
                <div class="panel-title">Tren kontribusi</div>
                <div class="panel-sub">Filtered delta harian dari telemetri tervalidasi (WIB)</div>
            </div>
            <a class="text-link" href="<?= $teacher ? 'classroom.php' : 'contributions.php' ?>">Detail kontribusi ↗</a>
        </div>
        <div class="chart-legend">
            <span><i></i> <?= $teacher ? 'Kontribusi valid kelompok' : ('Kontribusi valid kamu' . ($primaryGroup ? ' · ' . e($primaryGroup['name']) : '')) ?></span>
            <span class="legend-muted">7 hari terakhir · <?= (int) $trend['total_events'] ?> event valid · <?= number_format((float) $trend['total_filtered_delta'], 2) ?> filtered delta</span>
        </div>
        <div class="fs-bar-chart" data-fs-bar-chart role="img" aria-label="Diagram batang tren kontribusi 7 hari terakhir (WIB)">
            <div class="fs-bar-y" aria-hidden="true">
                <?php foreach ($yTicks as $tick): ?>
                    <span><?= (int) $tick ?></span>
                <?php endforeach; ?>
            </div>
            <div class="fs-bar-plot" data-fs-bar-plot>
                <div class="fs-bar-grid" aria-hidden="true">
                    <span></span>
                    <span></span>
                    <span></span>
                    <span></span>
                    <span></span>
                </div>
                <div class="fs-bar-cols">
                    <?php foreach ($barDays as $d): ?>
                        <div class="fs-bar-col<?= $d['sum'] > 0 ? ' has-value' : ' is-zero' ?>"
                             tabindex="0"
                             role="button"
                             aria-label="<?= e($d['aria_label']) ?>"
                             title="<?= e($d['full_date'] . ' · Filtered Delta: ' . $d['sum_formatted'] . ' · Valid Events: ' . $d['count'] . ' event') ?>"
                             data-bar-date="<?= e($d['full_date']) ?>"
                             data-bar-delta="<?= e($d['sum_formatted']) ?>"
                             data-bar-events="<?= (int) $d['count'] ?> event">
                            <div class="fs-bar-track">
                                <?php if ($d['sum'] > 0): ?>
                                    <span class="fs-bar-fill" style="height: <?= number_format($d['height_pct'], 2, '.', '') ?>%;"></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="fs-bar-tooltip" data-fs-bar-tooltip role="tooltip" hidden>
                    <div class="fs-bar-tooltip-date" data-tt-date></div>
                    <div class="fs-bar-tooltip-row"><span>Filtered Delta</span><strong data-tt-delta></strong></div>
                    <div class="fs-bar-tooltip-row"><span>Valid Events</span><strong data-tt-events></strong></div>
                </div>
            </div>
        </div>
        <div class="fs-bar-x" aria-hidden="true">
            <?php foreach ($barDays as $d): ?>
                <span title="<?= e($d['full_date']) ?>"><?= e($d['label']) ?></span>
            <?php endforeach; ?>
        </div>
        <div class="chart-note"><?php
            if ($trend['error'] !== null) {
                echo e($trend['error']);
            } elseif ($maxCount > 0) {
                echo 'Sumber: telemetri tervalidasi (sama dengan dasar perhitungan WEI). Arahkan kursor atau fokuskan batang untuk melihat detail harian.';
            } else {
                echo 'Belum ada aktivitas tervalidasi dalam 7 hari terakhir.';
            }
        ?></div>
    </section>

    <!-- FAKTOR WEI -->
    <section class="panel factor-panel">
        <div class="panel-heading">
            <div>
                <div class="panel-title">Faktor WEI</div>
                <div class="panel-sub">Indikator pendukung · telemetri tervalidasi 7 hari terakhir</div>
            </div>
            <span class="help-dot" title="Indikator pendukung dashboard, bukan komponen rumus WEI dan bukan nilai akhir mata kuliah. Rumus WEI dihitung oleh fn_group_wei_summary.">?</span>
        </div>
        <div class="factor-item">
            <div class="factor-head">
                <span title="Jumlah event telemetri valid (filter sama dengan WEI) dalam 7 hari terakhir. Skala tampilan: 5 event = bar penuh.">Volume aktivitas</span>
                <b style="color: #ffffff !important;"><?= $factorValidEvents ?> event</b>
            </div>
            <div class="factor-track"><span style="width:<?= $factorVolumePct ?>%"></span></div>
        </div>
        <div class="factor-item">
            <div class="factor-head">
                <span title="Jumlah hari (WIB) yang memiliki minimal satu event telemetri valid dari 7 hari terakhir.">Konsistensi temporal</span>
                <b style="color: #ffffff !important;"><?= $factorActiveDays ?>/7 hari · <?= $factorConsistencyPct ?>%</b>
            </div>
            <div class="factor-track">
                <span style="width:<?= $factorConsistencyPct ?>%;background:#55b7c5"></span>
            </div>
        </div>
        <div class="factor-item">
            <div class="factor-head">
                <span>Tugas terselesaikan</span>
                <b style="color: #ffffff !important;"><?= $teacher ? number_format($classCompletion, 0) : number_format($teamProgressPct, 0) ?>%</b>
            </div>
            <div class="factor-track">
                <span style="width:<?= $teacher ? min(100, $classCompletion) : min(100, $teamProgressPct) ?>%;background:#e8ac54"></span>
            </div>
        </div>
        <div class="factor-callout" style="background: rgba(120, 104, 255, 0.12) !important; border: 1px solid rgba(120, 104, 255, 0.3);">
            <span style="color: #a29bfe !important;">✦</span>
            <p style="color: #e2e8f0 !important;">Indikator WEI mencerminkan proporsi kontribusi aktivitas kolaboratif yang berhasil dikumpulkan dan divalidasi FairShare terhadap tugas berbobot kelompok, bukan evaluasi kualitas akademik otomatis.</p>
        </div>
    </section>
</div>

<!-- LOWER GRID: TUGAS YANG PERLU DIPERHATIKAN -->
<div class="content-grid lower-grid">
    <?php if ($teacher): ?>
        <section class="panel">
            <div class="panel-heading">
                <div>
                    <div class="panel-title">Kelompok & Progres Kelas</div>
                    <div class="panel-sub">Data dari <code>v_class_overview</code> dan <code>fn_task_progress</code></div>
                </div>
                <a class="text-link" href="classroom.php">Buka monitoring ↗</a>
            </div>

            <?php if (!$groups): ?>
                <div class="empty-state">
                    <span class="empty-illustration">♧</span>
                    <b>Belum ada kelompok</b>
                    <p>Kelompok mahasiswa yang terbentuk akan muncul di sini.</p>
                </div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="data-table" style="font-size:12px;">
                        <thead>
                            <tr>
                                <th>Kelompok</th>
                                <th>Anggota</th>
                                <th>Progres WBS</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (array_slice($groups, 0, 5) as $g): ?>
                                <?php
                                    $prog = fs_get_task_progress((string) $g['id'], $pdo);
                                    $pRate = (float) ($prog['completion_percentage'] ?? 0);
                                    $pDone = (int) ($prog['completed_tasks'] ?? 0);
                                    $pTot = (int) ($prog['total_tasks'] ?? 0);
                                ?>
                                <tr>
                                    <td>
                                        <b><?= e($g['name']) ?></b>
                                        <small class="table-secondary"><?= e($g['parent_class_name'] ?: ($g['course'] ?: 'Kelas')) ?></small>
                                    </td>
                                    <td><?= count($g['members'] ?? []) ?> orang</td>
                                    <td>
                                        <div style="display:flex; align-items:center; gap:6px;">
                                            <div class="mini-progress green-progress" style="width:50px;">
                                                <span style="width:<?= min(100, $pRate) ?>%"></span>
                                            </div>
                                            <small><?= $pDone ?>/<?= $pTot ?> (<?= number_format($pRate, 0) ?>%)</small>
                                        </div>
                                    </td>
                                    <td>
                                        <a class="btn secondary small" href="team_detail.php?id=<?= urlencode((string) $g['id']) ?>">
                                            Analitik ↗
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    <?php else: ?>
        <section class="panel">
            <div class="panel-heading">
                <div>
                    <div class="panel-title">Tugas yang perlu diperhatikan</div>
                    <div class="panel-sub">Ringkasan Work Breakdown Structure tim</div>
                </div>
                <a class="text-link" href="tasks.php">Lihat semua ↗</a>
            </div>

            <?php if (!$tasks): ?>
                <div class="empty-state">
                    <span class="empty-illustration">☷</span>
                    <b>Belum ada tugas</b>
                    <p>Buat tugas pertama untuk mulai memantau progres kolaborasi.</p>
                    <a class="btn primary" href="tasks.php#new-task">Buat tugas pertama</a>
                </div>
            <?php else: ?>
                <?php
                    // Prioritaskan tugas yang belum selesai (todo/in_progress/review),
                    // lalu urutkan berdasarkan target_week (paling awal dulu).
                    $priorityOrder = ['todo' => 0, 'in_progress' => 1, 'review' => 2, 'done' => 3];
                    $sortedTasks = $tasks;
                    usort($sortedTasks, function (array $a, array $b) use ($priorityOrder): int {
                        $pa = $priorityOrder[$a['status_db'] ?? 'todo'] ?? 0;
                        $pb = $priorityOrder[$b['status_db'] ?? 'todo'] ?? 0;
                        if ($pa !== $pb) {
                            return $pa <=> $pb;
                        }
                        return (int) ($a['target_week'] ?? 0) <=> (int) ($b['target_week'] ?? 0);
                    });
                ?>
                <div class="task-list">
                    <?php foreach (array_slice($sortedTasks, 0, 5) as $t): ?>
                        <?php
                            $pct = match ($t['status_db'] ?? 'todo') {
                                'done'        => 100,
                                'review'      => 75,
                                'in_progress' => 50,
                                default       => 10,
                            };
                        ?>
                        <div class="task-line">
                            <span class="task-square"><?= ($t['status'] ?? '') === 'Selesai' ? '✓' : '↗' ?></span>
                            <div class="task-info">
                                <b style="color: #ffffff !important;"><?= e($t['title']) ?></b>
                                <small style="color: #94a3b8 !important;"><?= e($t['group_name'] ?? 'Kelompok') ?> · Tenggat <?= e($t['due_date'] ?? 'Belum ditentukan') ?></small>
                            </div>
                            <span class="task-state" style="background: rgba(255, 255, 255, 0.08) !important; color: #cbd5e1 !important; border: 1px solid rgba(255, 255, 255, 0.12); padding: 4px 10px; border-radius: 20px; font-size: 11px;"><?= e($t['status']) ?></span>
                            <div class="task-percent" style="color: #a29bfe !important; font-weight: bold;"><?= $pct ?>%</div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <section class="panel">
        <div class="panel-heading">
            <div>
                <div class="panel-title">Aktivitas terbaru</div>
                <div class="panel-sub">Jejak perubahan di workspace</div>
            </div>
            <a class="text-link" href="audit.php">Buka audit ↗</a>
        </div>

        <?php if ($recentDbError): ?>
            <div class="alert warning" role="alert" style="margin:12px 0 4px;">
                Data aktivitas tidak dapat dimuat dari database. Menampilkan log lokal sebagai cadangan.
            </div>
        <?php endif; ?>
        <?php if (!$recent): ?>
            <div class="empty-state compact">
                <span class="empty-illustration">⌁</span>
                <b>Belum ada aktivitas</b>
                <p>Aktivitas sistem akan muncul di sini.</p>
            </div>
        <?php else: ?>
            <div class="timeline">
                <?php foreach ($recent as $log): ?>
                    <div class="timeline-row">
                        <span class="timeline-dot <?= e($log['type'] ?? 'info') ?>"></span>
                        <div>
                            <b><?= e($log['title']) ?></b>
                            <p><?= e($log['detail']) ?></p>
                            <small><?= e($log['time']) ?> · <?= e($log['user'] ?? 'Sistem') ?></small>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</div>

<?php
if ($teacher && $myClassCount > 1) {
    echo '<div class="modal-overlay" id="selectClassForTeamModal" hidden>
        <div class="modal-box">
            <div class="modal-head">
                <div>
                    <h3>Pilih Kelas untuk Kelompok Baru</h3>
                    <small>Pilih salah satu kelas yang kamu ampu untuk membuat kelompok di dalamnya.</small>
                </div>
                <button class="modal-close" type="button" data-modal-close="selectClassForTeamModal">✕</button>
            </div>
            <div class="modal-body" style="padding:16px 20px;">
                <p class="muted" style="margin-top:0;">Klik pada kelas tujuan pembentukan kelompok:</p>
                <div style="display:flex; flex-direction:column; gap:8px;">';
    foreach ($myClasses as $cl) {
        echo '<a href="class_detail.php?id=' . urlencode((string) $cl['id']) . '&modal=create_team" 
                   class="panel" 
                   style="display:flex; justify-content:space-between; align-items:center; padding:12px 16px; margin:0; text-decoration:none; color:inherit; border:1px solid #ebebf4; border-radius:10px; transition:all 0.2s;">
                <div>
                    <strong style="color:var(--color-primary, #6754ef); display:block;">' . e($cl['name']) . '</strong>
                    <small class="muted">' . e($cl['semester'] ?? 'Semester') . ' · ' . e($cl['academic_year'] ?? '') . ' · ' . (int) ($cl['team_count'] ?? 0) . ' kelompok saat ini</small>
                </div>
                <span class="btn secondary small">Pilih Kelas →</span>
            </a>';
    }
    echo '      </div>
            </div>
            <div class="modal-foot">
                <button class="btn secondary" type="button" data-modal-close="selectClassForTeamModal">Batal</button>
            </div>
        </div>
    </div>';
}

layout_end();
