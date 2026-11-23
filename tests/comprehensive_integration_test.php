<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

$pdo = require_db();

$passed = 0;
$failed = 0;

function run_assert(string $desc, bool $condition, string $detail = ''): void {
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  [PASS] {$desc}\n";
    } else {
        $failed++;
        echo " ![FAIL] {$desc} -> {$detail}\n";
    }
}

echo "====================================================================\n";
echo "FAIRSHARE COMPREHENSIVE INTEGRATION & RECONCILIATION TEST SUITE\n";
echo "====================================================================\n\n";

$teamId = 'bf025123-8133-40d9-a0ae-191eed269f80';
$classId = '0dc288c9-5745-4a70-bea8-05a6629186a0';
$lecturerId = '961092aa-f108-4f85-916c-b54a17641d68';
$elsaId = '06b871e0-fd77-4d93-9cf3-4dbab6d8f9aa';
$rayhanId = 'c820f70f-cfe1-4baa-b085-c754e4cc8c2a';
$virnaId = '2918bc0f-275a-4844-ab68-14691b4c586a';

// ------------------------------------------------------------------
// 1. PENGUJIAN WEI ANGGOTA (fn_group_wei_summary & v_team_contribution_summary)
// ------------------------------------------------------------------
echo "--- 1. PENGUJIAN WEI ANGGOTA TIM: Jaya jaya jaya ---\n";
$weiRows = fs_get_group_wei_summary($teamId, $pdo);
$weiByUser = [];
foreach ($weiRows as $w) {
    $weiByUser[strtolower((string) $w['user_id'])] = $w;
}

$elsaWei = $weiByUser[strtolower($elsaId)] ?? null;
run_assert('Elsa mrth terdaftar di fn_group_wei_summary', $elsaWei !== null);
run_assert('Elsa mrth valid_event_count = 5', (int) ($elsaWei['valid_event_count'] ?? -1) === 5);
run_assert('Elsa mrth total_filtered_delta = 5.00', (float) ($elsaWei['total_filtered_delta'] ?? 0) == 5.0);
run_assert('Elsa mrth wei_percentage = 50%', round((float) ($elsaWei['wei_percentage'] ?? 0), 1) == 50.0);

$rayhanWei = $weiByUser[strtolower($rayhanId)] ?? null;
run_assert('Rayhan Jofan Halim terdaftar di fn_group_wei_summary', $rayhanWei !== null);
run_assert('Rayhan Jofan Halim valid_event_count = 5', (int) ($rayhanWei['valid_event_count'] ?? -1) === 5);
run_assert('Rayhan Jofan Halim total_filtered_delta = 5.00', (float) ($rayhanWei['total_filtered_delta'] ?? 0) == 5.0);
run_assert('Rayhan Jofan Halim wei_percentage = 50%', round((float) ($rayhanWei['wei_percentage'] ?? 0), 1) == 50.0);

$virnaWei = $weiByUser[strtolower($virnaId)] ?? null;
run_assert('Virna Amelia terdaftar di fn_group_wei_summary', $virnaWei !== null);
run_assert('Virna Amelia valid_event_count = 0', (int) ($virnaWei['valid_event_count'] ?? -1) === 0);
run_assert('Virna Amelia total_filtered_delta = 0.00', (float) ($virnaWei['total_filtered_delta'] ?? -1) == 0.0);
run_assert('Virna Amelia wei_percentage = 0%', round((float) ($virnaWei['wei_percentage'] ?? -1), 1) == 0.0);

$virnaStatus = fs_determine_wei_status(0, 10, (string) ($virnaWei['data_status'] ?? ''));
run_assert('Virna status label adalah "Belum memiliki aktivitas tervalidasi"', $virnaStatus['label'] === 'Belum memiliki aktivitas tervalidasi');

// ------------------------------------------------------------------
// 2. PENGUJIAN PROGRES TUGAS TIM (fn_task_progress)
// ------------------------------------------------------------------
echo "\n--- 2. PENGUJIAN PROGRES TUGAS TIM: Jaya jaya jaya ---\n";
$progress = fs_get_task_progress($teamId, $pdo);
run_assert('Hasil fn_task_progress tidak null', $progress !== null);
run_assert('Total Tasks = 4', (int) ($progress['total_tasks'] ?? 0) === 4);
run_assert('Completed Tasks = 0', (int) ($progress['completed_tasks'] ?? -1) === 0);
run_assert('In Progress Tasks = 2', (int) ($progress['in_progress_tasks'] ?? 0) === 2);
run_assert('Review Tasks = 0', (int) ($progress['review_tasks'] ?? -1) === 0);
run_assert('Todo Tasks = 2', (int) ($progress['todo_tasks'] ?? 0) === 2);
run_assert('Completion Percentage = 0%', round((float) ($progress['completion_percentage'] ?? -1), 2) == 0.0);

// ------------------------------------------------------------------
// 3. PENGUJIAN AKTIVITAS TUGAS (v_task_activity_summary)
// ------------------------------------------------------------------
echo "\n--- 3. PENGUJIAN AKTIVITAS TUGAS: Laporan Implementasi Pbo ---\n";
$teamTasks = fs_get_task_activity_summary($teamId, null, $pdo);
$targetTask = null;
foreach ($teamTasks as $tk) {
    if ($tk['task_title'] === 'Laporan Implementasi Pbo') {
        $targetTask = $tk;
        break;
    }
}

run_assert('Tugas "Laporan Implementasi Pbo" ditemukan di v_task_activity_summary', $targetTask !== null);
if ($targetTask) {
    run_assert('Total Events = 10', (int) $targetTask['total_events'] === 10);
    run_assert('Google Docs Events = 10', (int) $targetTask['gdocs_events'] === 10);
    run_assert('GitHub Events = 0', (int) $targetTask['github_events'] === 0);
    run_assert('Raw Delta = 10', (float) $targetTask['total_raw_delta'] == 10.0);
    run_assert('Filtered Delta = 10', (float) $targetTask['total_filtered_delta'] == 10.0);
    run_assert('Valid Events = 10', (int) $targetTask['valid_events'] === 10);
    run_assert('Pending Events = 0', (int) $targetTask['pending_events'] === 0);
    run_assert('Rejected Events = 0', (int) $targetTask['rejected_events'] === 0);
    run_assert('Identified Members = 2', (int) $targetTask['identified_members'] === 2);
    run_assert('Weight = 3', (int) $targetTask['weight'] === 3);
    run_assert('Task Status = todo', (string) $targetTask['task_status'] === 'todo');
}

// ------------------------------------------------------------------
// 4. PENGUJIAN REKONSILIASI KONSISTENSI DATA
// ------------------------------------------------------------------
echo "\n--- 4. PENGUJIAN REKONSILIASI ---\n";
// Ambil raw telemetry count
$stmt = $pdo->prepare("SELECT COUNT(*) AS total, SUM(CASE WHEN (metadata->>'validation_status' = 'valid' OR metadata->>'validation_status' = 'terverifikasi') AND filtered_delta > 0 THEN 1 ELSE 0 END) AS valid_cnt, SUM(raw_delta) AS sum_raw, SUM(CASE WHEN (metadata->>'validation_status' = 'valid' OR metadata->>'validation_status' = 'terverifikasi') THEN filtered_delta ELSE 0 END) AS sum_filt FROM public.telemetry_logs WHERE group_id = CAST(:gid AS UUID)");
$stmt->execute(['gid' => $teamId]);
$telemetryStats = $stmt->fetch(PDO::FETCH_ASSOC);

$contribMembers = fs_get_team_contribution_summary($teamId, $pdo);
$memberTotalEvents = (int) array_sum(array_column($contribMembers, 'total_events'));
$memberValidEvents = (int) array_sum(array_column($contribMembers, 'valid_events'));
$memberRawDelta = (float) array_sum(array_column($contribMembers, 'total_raw_delta'));
$memberFilteredDelta = (float) array_sum(array_column($contribMembers, 'total_filtered_delta'));

$taskTotalEvents = (int) array_sum(array_column($teamTasks, 'total_events'));
$taskValidEvents = (int) array_sum(array_column($teamTasks, 'valid_events'));
$taskRawDelta = (float) array_sum(array_column($teamTasks, 'total_raw_delta'));
$taskFilteredDelta = (float) array_sum(array_column($teamTasks, 'total_filtered_delta'));

$weiValidEvents = (int) array_sum(array_column($weiRows, 'valid_event_count'));
$weiFilteredDelta = (float) array_sum(array_column($weiRows, 'total_filtered_delta'));

run_assert('Rekonsiliasi Total Events: Telemetry(10) == MemberView(10) == TaskView(10)',
    (int) $telemetryStats['total'] === 10 && $memberTotalEvents === 10 && $taskTotalEvents === 10);
run_assert('Rekonsiliasi Valid Events: Telemetry(10) == MemberView(10) == TaskView(10) == WEIFunction(10)',
    (int) $telemetryStats['valid_cnt'] === 10 && $memberValidEvents === 10 && $taskValidEvents === 10 && $weiValidEvents === 10);
run_assert('Rekonsiliasi Raw Delta: Telemetry(10) == MemberView(10) == TaskView(10)',
    (float) $telemetryStats['sum_raw'] == 10.0 && $memberRawDelta == 10.0 && $taskRawDelta == 10.0);
run_assert('Rekonsiliasi Filtered Delta: Telemetry(10) == MemberView(10) == TaskView(10) == WEIFunction(10)',
    (float) $telemetryStats['sum_filt'] == 10.0 && $memberFilteredDelta == 10.0 && $taskFilteredDelta == 10.0 && $weiFilteredDelta == 10.0);

// ------------------------------------------------------------------
// 5. PENGUJIAN RINGKASAN KELAS (v_class_overview)
// ------------------------------------------------------------------
echo "\n--- 5. PENGUJIAN RINGKASAN KELAS: v_class_overview ---\n";
$classOverview = fs_get_class_overview($lecturerId, $classId, $pdo);
run_assert('v_class_overview mengembalikan kelas Pemrograman Berbasis Objek', !empty($classOverview));
run_assert('Total students = 3', (int) ($classOverview['total_students'] ?? 0) === 3);
run_assert('Total teams = 1', (int) ($classOverview['total_teams'] ?? 0) === 1);
run_assert('Total tasks = 4', (int) ($classOverview['total_tasks'] ?? 0) === 4);
run_assert('Completed tasks = 0', (int) ($classOverview['completed_tasks'] ?? -1) === 0);
run_assert('Completion percentage = 0.00', round((float) ($classOverview['completion_percentage'] ?? -1), 2) == 0.0);

// ------------------------------------------------------------------
// 6. PENGUJIAN KEAMANAN & AKSES KONTROL SERVER-SIDE
// ------------------------------------------------------------------
echo "\n--- 6. PENGUJIAN KEAMANAN & AKSES KONTROL ---\n";
$lecturerUser = ['id' => $lecturerId, 'role' => 'dosen', 'name' => 'Riyan Firdaus Amerta'];
$elsaUser = ['id' => $elsaId, 'role' => 'mahasiswa', 'name' => 'Elsa mrth'];
$otherStudentUser = ['id' => '00000000-0000-4000-8000-000000000001', 'role' => 'mahasiswa', 'name' => 'Mahasiswa Luar'];
$otherTeacherUser = ['id' => '00000000-0000-4000-8000-000000000002', 'role' => 'dosen', 'name' => 'Dosen Luar'];

run_assert('Dosen pemilik berhak akses kelas miliknya', fs_can_user_access_class($lecturerUser, $classId, $pdo) === true);
run_assert('Dosen pemilik berhak akses tim di kelasnya', fs_can_user_access_team($lecturerUser, $teamId, $pdo) === true);
run_assert('Dosen luar DITOLAK akses kelas bukan miliknya', fs_can_user_access_class($otherTeacherUser, $classId, $pdo) === false);
run_assert('Dosen luar DITOLAK akses tim di kelas bukan miliknya', fs_can_user_access_team($otherTeacherUser, $teamId, $pdo) === false);

run_assert('Mahasiswa anggota berhak akses kelasnya', fs_can_user_access_class($elsaUser, $classId, $pdo) === true);
run_assert('Mahasiswa anggota berhak akses timnya', fs_can_user_access_team($elsaUser, $teamId, $pdo) === true);
run_assert('Mahasiswa luar DITOLAK akses tim bukan anggotanya', fs_can_user_access_team($otherStudentUser, $teamId, $pdo) === false);
run_assert('Mahasiswa luar DITOLAK akses kelas bukan anggotanya', fs_can_user_access_class($otherStudentUser, $classId, $pdo) === false);

run_assert('Akses dengan UUID invalid ditolak secara aman', fs_can_user_access_team($elsaUser, 'invalid-uuid-string', $pdo) === false);

if ($targetTask) {
    run_assert('Dosen berhak akses tugas di kelasnya', fs_can_user_access_task($lecturerUser, (string) $targetTask['task_id'], $pdo) === true);
    run_assert('Anggota berhak akses tugas timnya', fs_can_user_access_task($elsaUser, (string) $targetTask['task_id'], $pdo) === true);
    run_assert('Mahasiswa luar DITOLAK akses tugas tim lain', fs_can_user_access_task($otherStudentUser, (string) $targetTask['task_id'], $pdo) === false);
}

// ------------------------------------------------------------------
// 7. PENGUJIAN INTEGRASI FULL ANALYTICS HELPER
// ------------------------------------------------------------------
echo "\n--- 7. PENGUJIAN FULL ANALYTICS HELPER (fs_get_team_full_analytics) ---\n";
$fullAnalytics = fs_get_team_full_analytics($teamId, $pdo);
run_assert('fs_get_team_full_analytics tidak null', $fullAnalytics !== null);
run_assert('Member count = 3', $fullAnalytics['member_count'] === 3);
run_assert('Total telemetry events = 10', $fullAnalytics['total_events'] === 10);
run_assert('Total valid events = 10', $fullAnalytics['total_valid_events'] === 10);
run_assert('Total Filtered Delta = 10.0', (float) $fullAnalytics['total_filtered_delta'] == 10.0);
run_assert('Has sufficient data = true', $fullAnalytics['has_sufficient_data'] === true);

echo "\n====================================================================\n";
echo "HASIL AKHIR: Lulus: {$passed}, Gagal: {$failed}\n";
echo "====================================================================\n";
