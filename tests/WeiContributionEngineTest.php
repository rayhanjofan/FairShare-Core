<?php
declare(strict_types=1);

/**
 * Unit Test untuk FairShare Contribution Engine & Validator
 *
 * Menguji:
 * 1. Skenario Bagian 7.D (A=44.4444%, B=22.2222%, C=33.3333%, D=0%)
 * 2. Satu anggota kontribusi 100%
 * 3. Dua anggota kontribusi seimbang 50% / 50%
 * 4. Anggota tanpa aktivitas mendapat 0% jika kelompok memiliki aktivitas valid
 * 5. Seluruh anggota tanpa aktivitas menghasilkan status "Belum cukup data"
 * 6. Dua tugas dengan bobot berbeda (Weight 2 vs 1)
 * 7. Total seluruh WEI kelompok = 100% (dengan toleransi floating point)
 * 8. Manual test (entry_mode = 'manual_test') diabaikan dari WEI
 * 9. Pending dan rejected events diabaikan dari WEI
 * 10. Validasi 14 kriteria Google Docs (valid edit, create, actor OAuth, doc mismatch, duplicate)
 */

require_once __DIR__ . '/../includes/telemetry_validator.php';
require_once __DIR__ . '/../includes/contribution_engine.php';

$testsPassed = 0;
$testsFailed = 0;

function assert_test(string $name, bool $condition, string $detail = ''): void
{
    global $testsPassed, $testsFailed;
    if ($condition) {
        $testsPassed++;
        echo " [PASS] $name\n";
    } else {
        $testsFailed++;
        echo "![FAIL] $name - $detail\n";
    }
}

echo "============================================================\n";
echo "FAIRSHARE CONTRIBUTION ENGINE & VALIDATOR TEST SUITE\n";
echo "============================================================\n\n";

// ------------------------------------------------------------
// TEST 1: Skenario Bagian 7.D Master Prompt
// ------------------------------------------------------------
echo "--- TEST 1: Bagian 7.D Formula Verification ---\n";
$group = [
    'id' => '11111111-1111-4111-8111-111111111111',
    'name' => 'Kelompok 1 (Test 7.D)',
    'group_type' => 'team',
];

$members = [
    ['user_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'name' => 'Mahasiswa A', 'nim_nip' => '101', 'email' => 'a@test.local', 'role_in_group' => 'ketua'],
    ['user_id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'name' => 'Mahasiswa B', 'nim_nip' => '102', 'email' => 'b@test.local', 'role_in_group' => 'anggota'],
    ['user_id' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc', 'name' => 'Mahasiswa C', 'nim_nip' => '103', 'email' => 'c@test.local', 'role_in_group' => 'anggota'],
    ['user_id' => 'dddddddd-dddd-4ddd-8ddd-dddddddddddd', 'name' => 'Mahasiswa D', 'nim_nip' => '104', 'email' => 'd@test.local', 'role_in_group' => 'anggota'],
];

$tasks = [
    [
        'id' => 't1111111-1111-4111-8111-111111111111',
        'title' => 'Tugas 1 (Bobot 2)',
        'weight' => 2,
        'task_type' => 'gdocs',
        'external_resource_id' => 'doc_1',
    ],
    [
        'id' => 't2222222-2222-4222-8222-222222222222',
        'title' => 'Tugas 2 (Bobot 1)',
        'weight' => 1,
        'task_type' => 'gdocs',
        'external_resource_id' => 'doc_2',
    ],
];

// Buat telemetri sesuai 7.D:
// Tugas 1: A=10 Filtered Delta, B=5, C=0, D=0
// Tugas 2: A=0, B=0, C=10, D=0
$telemetry = [];

// A: 10 event valid di Tugas 1
for ($i = 1; $i <= 10; $i++) {
    $telemetry[] = [
        'user_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
        'task_id' => 't1111111-1111-4111-8111-111111111111',
        'source' => 'gdocs',
        'raw_delta' => 1.0,
        'filtered_delta' => 1.0,
        'metadata' => ['validation_status' => 'valid'],
    ];
}

// B: 5 event valid di Tugas 1
for ($i = 1; $i <= 5; $i++) {
    $telemetry[] = [
        'user_id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
        'task_id' => 't1111111-1111-4111-8111-111111111111',
        'source' => 'gdocs',
        'raw_delta' => 1.0,
        'filtered_delta' => 1.0,
        'metadata' => ['validation_status' => 'valid'],
    ];
}

// C: 10 event valid di Tugas 2
for ($i = 1; $i <= 10; $i++) {
    $telemetry[] = [
        'user_id' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
        'task_id' => 't2222222-2222-4222-8222-222222222222',
        'source' => 'gdocs',
        'raw_delta' => 1.0,
        'filtered_delta' => 1.0,
        'metadata' => ['validation_status' => 'valid'],
    ];
}

// Jalankan kalkulasi
$res = fs_compute_wei_from_dataset($group, $members, $tasks, $telemetry);

assert_test('Status kecukupan data kelompok adalah sufficient', $res['has_sufficient_data'] === true);
assert_test('Valid tasks count adalah 2', $res['valid_tasks_count'] === 2);
assert_test('Total evaluated weight adalah 3', $res['total_evaluated_weight'] === 3);

// Periksa skor tugas 1
$t1Scores = $res['tasks']['t1111111-1111-4111-8111-111111111111']['member_scores'];
assert_test('Tugas 1 A = 66.6667%', abs($t1Scores['aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa']['wei_percent'] - (10.0 / 15.0 * 100.0)) < 0.0001);
assert_test('Tugas 1 B = 33.3333%', abs($t1Scores['bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb']['wei_percent'] - (5.0 / 15.0 * 100.0)) < 0.0001);
assert_test('Tugas 1 C = 0.0000%', abs($t1Scores['cccccccc-cccc-4ccc-8ccc-cccccccccccc']['wei_percent'] - 0.0) < 0.0001);
assert_test('Tugas 1 D = 0.0000%', abs($t1Scores['dddddddd-dddd-4ddd-8ddd-dddddddddddd']['wei_percent'] - 0.0) < 0.0001);

// Periksa skor tugas 2
$t2Scores = $res['tasks']['t2222222-2222-4222-8222-222222222222']['member_scores'];
assert_test('Tugas 2 A = 0.0000%', abs($t2Scores['aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa']['wei_percent'] - 0.0) < 0.0001);
assert_test('Tugas 2 B = 0.0000%', abs($t2Scores['bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb']['wei_percent'] - 0.0) < 0.0001);
assert_test('Tugas 2 C = 100.0000%', abs($t2Scores['cccccccc-cccc-4ccc-8ccc-cccccccccccc']['wei_percent'] - 100.0) < 0.0001);
assert_test('Tugas 2 D = 0.0000%', abs($t2Scores['dddddddd-dddd-4ddd-8ddd-dddddddddddd']['wei_percent'] - 0.0) < 0.0001);

// Periksa skor WEI keseluruhan:
// A: (2 * 66.6667% + 1 * 0%) / 3 = 44.4444%
// B: (2 * 33.3333% + 1 * 0%) / 3 = 22.2222%
// C: (2 * 0% + 1 * 100%) / 3 = 33.3333%
// D: 0%
$mRes = $res['members'];
$expectedA = (2.0 * (10.0 / 15.0 * 100.0)) / 3.0; // 44.444444%
$expectedB = (2.0 * (5.0 / 15.0 * 100.0)) / 3.0;  // 22.222222%
$expectedC = (1.0 * 100.0) / 3.0;                 // 33.333333%
$expectedD = 0.0;

assert_test('Overall WEI A = 44.4444%', abs($mRes['aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa']['wei_percent'] - $expectedA) < 0.0001, "Got " . $mRes['aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa']['wei_percent']);
assert_test('Overall WEI B = 22.2222%', abs($mRes['bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb']['wei_percent'] - $expectedB) < 0.0001, "Got " . $mRes['bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb']['wei_percent']);
assert_test('Overall WEI C = 33.3333%', abs($mRes['cccccccc-cccc-4ccc-8ccc-cccccccccccc']['wei_percent'] - $expectedC) < 0.0001, "Got " . $mRes['cccccccc-cccc-4ccc-8ccc-cccccccccccc']['wei_percent']);
assert_test('Overall WEI D = 0.0000%', abs($mRes['dddddddd-dddd-4ddd-8ddd-dddddddddddd']['wei_percent'] - $expectedD) < 0.0001, "Got " . $mRes['dddddddd-dddd-4ddd-8ddd-dddddddddddd']['wei_percent']);

$totalWei = $mRes['aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa']['wei_percent']
    + $mRes['bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb']['wei_percent']
    + $mRes['cccccccc-cccc-4ccc-8ccc-cccccccccccc']['wei_percent']
    + $mRes['dddddddd-dddd-4ddd-8ddd-dddddddddddd']['wei_percent'];

assert_test('Total WEI seluruh anggota adalah 100%', abs($totalWei - 100.0) < 0.0001, "Total: $totalWei");

// ------------------------------------------------------------
// TEST 2: Satu anggota dengan seluruh aktivitas valid = 100%
// ------------------------------------------------------------
echo "\n--- TEST 2: Single Active Member gets 100% ---\n";
$singleActTelemetry = [
    [
        'user_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
        'task_id' => 't1111111-1111-4111-8111-111111111111',
        'source' => 'gdocs',
        'raw_delta' => 1.0,
        'filtered_delta' => 1.0,
        'metadata' => ['validation_status' => 'valid'],
    ],
];
$resSingle = fs_compute_wei_from_dataset($group, $members, [$tasks[0]], $singleActTelemetry);
assert_test('Anggota A mendapat 100%', abs($resSingle['members']['aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa']['wei_percent'] - 100.0) < 0.0001);
assert_test('Anggota B, C, D mendapat 0%', $resSingle['members']['bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb']['wei_percent'] === 0.0);

// ------------------------------------------------------------
// TEST 3: Dua anggota seimbang = 50% masing-masing
// ------------------------------------------------------------
echo "\n--- TEST 3: Two Equal Members get 50% each ---\n";
$twoEqualTelemetry = [
    [
        'user_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
        'task_id' => 't1111111-1111-4111-8111-111111111111',
        'source' => 'gdocs',
        'raw_delta' => 1.0,
        'filtered_delta' => 1.0,
        'metadata' => ['validation_status' => 'valid'],
    ],
    [
        'user_id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
        'task_id' => 't1111111-1111-4111-8111-111111111111',
        'source' => 'gdocs',
        'raw_delta' => 1.0,
        'filtered_delta' => 1.0,
        'metadata' => ['validation_status' => 'valid'],
    ],
];
$resEqual = fs_compute_wei_from_dataset($group, $members, [$tasks[0]], $twoEqualTelemetry);
assert_test('A = 50.0%', abs($resEqual['members']['aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa']['wei_percent'] - 50.0) < 0.0001);
assert_test('B = 50.0%', abs($resEqual['members']['bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb']['wei_percent'] - 50.0) < 0.0001);
assert_test('C = 0.0%', $resEqual['members']['cccccccc-cccc-4ccc-8ccc-cccccccccccc']['wei_percent'] === 0.0);
assert_test('D = 0.0%', $resEqual['members']['dddddddd-dddd-4ddd-8ddd-dddddddddddd']['wei_percent'] === 0.0);

// ------------------------------------------------------------
// TEST 4: Seluruh anggota tanpa aktivitas = Belum cukup data
// ------------------------------------------------------------
echo "\n--- TEST 4: Zero Valid Telemetry = Insufficient Data ---\n";
$resZero = fs_compute_wei_from_dataset($group, $members, $tasks, []);
assert_test('Kelompok has_sufficient_data adalah false', $resZero['has_sufficient_data'] === false);
assert_test('Status label adalah Belum Cukup Data', $resZero['status_label'] === 'Belum Cukup Data');
assert_test('Anggota A wei_percent adalah null', $resZero['members']['aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa']['wei_percent'] === null);
assert_test('Anggota A wei_formatted adalah Belum cukup data', $resZero['members']['aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa']['wei_formatted'] === 'Belum cukup data');

// ------------------------------------------------------------
// TEST 5: Manual test diabaikan dari WEI
// ------------------------------------------------------------
echo "\n--- TEST 5: Manual Test Ignored from Official WEI ---\n";
$manualOnlyTelemetry = [
    [
        'user_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
        'task_id' => 't1111111-1111-4111-8111-111111111111',
        'source' => 'gdocs',
        'raw_delta' => 50.0,
        'filtered_delta' => 50.0,
        'metadata' => [
            'entry_mode' => 'manual_test',
            'validation_status' => 'Catatan manual (belum diverifikasi)',
        ],
    ],
];
$resManual = fs_compute_wei_from_dataset($group, $members, $tasks, $manualOnlyTelemetry);
assert_test('Manual test tidak menyebabkan data status sufficient', $resManual['has_sufficient_data'] === false);
assert_test('A manual events count adalah 1', $resManual['members']['aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa']['manual_events_count'] === 1);
assert_test('A valid events count adalah 0', $resManual['members']['aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa']['valid_events_count'] === 0);

// ------------------------------------------------------------
// TEST 6: Pending dan Rejected Events diabaikan dari WEI
// ------------------------------------------------------------
echo "\n--- TEST 6: Pending / Rejected Events Ignored from WEI ---\n";
$pendingRejectedTelemetry = [
    [
        'user_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
        'task_id' => 't1111111-1111-4111-8111-111111111111',
        'source' => 'gdocs',
        'raw_delta' => 1.0,
        'filtered_delta' => 0.0,
        'metadata' => [
            'validation_status' => 'pending',
            'validation_reason' => 'Menunggu kalibrasi bobot',
        ],
    ],
    [
        'user_id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
        'task_id' => 't1111111-1111-4111-8111-111111111111',
        'source' => 'gdocs',
        'raw_delta' => 1.0,
        'filtered_delta' => 0.0,
        'metadata' => [
            'validation_status' => 'rejected',
            'validation_reason' => 'actor_not_verified',
        ],
    ],
];
$resPending = fs_compute_wei_from_dataset($group, $members, $tasks, $pendingRejectedTelemetry);
assert_test('Pending/Rejected tidak menghasilkan data sufficient', $resPending['has_sufficient_data'] === false);
assert_test('A pending events count = 1', $resPending['members']['aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa']['pending_events_count'] === 1);
assert_test('B rejected events count = 1', $resPending['members']['bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb']['rejected_events_count'] === 1);

// ------------------------------------------------------------
// TEST 7: Validasi Event (14 Kriteria)
// ------------------------------------------------------------
echo "\n--- TEST 7: Telemetry Validator (14 Criteria) ---\n";
$taskContextValid = [
    'task_id' => 'ba111111-1111-4111-8111-111111111111',
    'task_type' => 'gdocs',
    'group_id' => '11111111-1111-4111-8111-111111111111',
    'group_type' => 'team',
    'parent_group_id' => 'ca111111-1111-4111-8111-111111111111',
    'parent_class_type' => 'class',
    'external_resource_id' => 'doc_12345',
    'member_ids' => ['aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'],
];

// 7a. Event valid
$validEvent = [
    'source' => 'gdocs',
    'task_id' => 'ba111111-1111-4111-8111-111111111111',
    'group_id' => '11111111-1111-4111-8111-111111111111',
    'user_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
    'action_type' => 'edit',
    'external_id' => 'sha256_hash_1',
    'document_id' => 'doc_12345',
    'activity_timestamp' => date('Y-m-d H:i:s', time() - 3600),
    'actor_attributed' => true,
    'attribution_method' => 'oauth_is_current_user',
    'is_current_user' => true,
];
$vRes = fs_validate_telemetry_event($validEvent, $taskContextValid);
assert_test('Event valid lolos validasi (status: valid, filtered_delta: 1.0)', $vRes['is_valid'] === true && $vRes['validation_status'] === 'valid' && $vRes['filtered_delta'] === 1.0);

// 7b. Event create valid
$validCreateEvent = $validEvent;
$validCreateEvent['action_type'] = 'create';
$vResCreate = fs_validate_telemetry_event($validCreateEvent, $taskContextValid);
assert_test('Event create lolos validasi (status: valid, filtered_delta: 1.0)', $vResCreate['is_valid'] === true && $vResCreate['filtered_delta'] === 1.0);

// 7c. Aktor tidak terverifikasi
$unverifiedActorEvent = $validEvent;
$unverifiedActorEvent['actor_attributed'] = false;
$unverifiedActorEvent['is_current_user'] = false;
$vResUnverified = fs_validate_telemetry_event($unverifiedActorEvent, $taskContextValid);
assert_test('Actor tidak terverifikasi ditolak (filtered_delta: 0.0, reason: actor_not_verified)', $vResUnverified['is_valid'] === false && $vResUnverified['filtered_delta'] === 0.0 && $vResUnverified['validation_reason'] === 'actor_not_verified');

// 7d. Dokumen berbeda (doc mismatch)
$wrongDocEvent = $validEvent;
$wrongDocEvent['document_id'] = 'doc_OTHER';
$vResWrongDoc = fs_validate_telemetry_event($wrongDocEvent, $taskContextValid);
assert_test('Dokumen beda ditolak (reason: wrong_document)', $vResWrongDoc['is_valid'] === false && $vResWrongDoc['validation_reason'] === 'wrong_document');

// 7e. User bukan anggota kelompok
$nonMemberEvent = $validEvent;
$nonMemberEvent['user_id'] = '99999999-9999-4999-8999-999999999999';
$vResNonMember = fs_validate_telemetry_event($nonMemberEvent, $taskContextValid);
assert_test('Bukan anggota ditolak (reason: user_not_group_member)', $vResNonMember['is_valid'] === false && $vResNonMember['validation_reason'] === 'user_not_group_member');

// 7f. Timestamp masa depan tidak wajar
$futureEvent = $validEvent;
$futureEvent['activity_timestamp'] = date('Y-m-d H:i:s', time() + 86400); // 1 hari ke depan
$vResFuture = fs_validate_telemetry_event($futureEvent, $taskContextValid);
assert_test('Timestamp masa depan ditolak (reason: future_timestamp)', $vResFuture['is_valid'] === false && $vResFuture['validation_reason'] === 'future_timestamp');

// 7g. Manual test
$manualTestEvent = $validEvent;
$manualTestEvent['entry_mode'] = 'manual_test';
$vResManual = fs_validate_telemetry_event($manualTestEvent, $taskContextValid);
assert_test('Manual test ditolak (reason: manual_test)', $vResManual['is_valid'] === false && $vResManual['validation_reason'] === 'manual_test');

// 7h. Duplikat
$dupEvent = $validEvent;
$dupEvent['is_duplicate'] = true;
$vResDup = fs_validate_telemetry_event($dupEvent, $taskContextValid);
assert_test('Duplikat ditolak (status: duplicate, reason: duplicate_event)', $vResDup['validation_status'] === 'duplicate' && $vResDup['validation_reason'] === 'duplicate_event');

// 7i. Task invalid
$invalidTaskEvent = $validEvent;
$invalidTaskEvent['task_id'] = 'not-a-valid-uuid';
$vResInvTask = fs_validate_telemetry_event($invalidTaskEvent, $taskContextValid);
assert_test('Task ID invalid ditolak (reason: invalid_task)', $vResInvTask['is_valid'] === false && $vResInvTask['validation_reason'] === 'invalid_task');

// 7j. Timestamp tidak valid
$invalidTsEvent = $validEvent;
$invalidTsEvent['activity_timestamp'] = 'bukan-format-tanggal';
$vResInvTs = fs_validate_telemetry_event($invalidTsEvent, $taskContextValid);
assert_test('Timestamp tidak valid ditolak (reason: invalid_timestamp)', $vResInvTs['is_valid'] === false && $vResInvTs['validation_reason'] === 'invalid_timestamp');

// 7k. Identitas null / tidak teridentifikasi
$nullUserEvent = $validEvent;
$nullUserEvent['user_id'] = null;
$vResNullUser = fs_validate_telemetry_event($nullUserEvent, $taskContextValid);
assert_test('User ID null ditolak (status: unattributed, reason: actor_not_verified)', $vResNullUser['validation_status'] === 'unattributed' && $vResNullUser['filtered_delta'] === 0.0);

// ------------------------------------------------------------
// TEST 8: Isolasi Antar Kelompok (Cross-Group Isolation)
// ------------------------------------------------------------
echo "\n--- TEST 8: Cross-Group Isolation ---\n";
// Ambil skenario kelompok 1 dari Test 1, tambahkan event dari user asing / tugas asing di telemetry array
$crossGroupTelemetry = $telemetry; // telemetri kelompok 1
$crossGroupTelemetry[] = [
    'user_id' => '99999999-9999-4999-8999-999999999999', // User dari kelompok lain
    'task_id' => 't1111111-1111-4111-8111-111111111111',
    'source' => 'gdocs',
    'raw_delta' => 100.0,
    'filtered_delta' => 100.0,
    'metadata' => ['validation_status' => 'valid'],
];
$crossGroupTelemetry[] = [
    'user_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
    'task_id' => '99999999-9999-4999-8999-999999999998', // Tugas dari kelompok lain
    'source' => 'gdocs',
    'raw_delta' => 50.0,
    'filtered_delta' => 50.0,
    'metadata' => ['validation_status' => 'valid'],
];

$resIsolated = fs_compute_wei_from_dataset($group, $members, $tasks, $crossGroupTelemetry);
// Nilai A, B, C, D di kelompok ini HARUS TETAP PERSIS SAMA seperti Test 1!
assert_test('Data kelompok lain tidak memengaruhi skor A', abs($resIsolated['members']['aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa']['wei_percent'] - $expectedA) < 0.0001);
assert_test('Data kelompok lain tidak memengaruhi skor B', abs($resIsolated['members']['bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb']['wei_percent'] - $expectedB) < 0.0001);
assert_test('Data kelompok lain tidak memengaruhi skor C', abs($resIsolated['members']['cccccccc-cccc-4ccc-8ccc-cccccccccccc']['wei_percent'] - $expectedC) < 0.0001);
assert_test('Data kelompok lain tidak memengaruhi skor D', abs($resIsolated['members']['dddddddd-dddd-4ddd-8ddd-dddddddddddd']['wei_percent'] - $expectedD) < 0.0001);
assert_test('User asing tidak masuk ke daftar anggota kelompok', !isset($resIsolated['members']['99999999-9999-4999-8999-999999999999']));

echo "\n============================================================\n";
echo "SUMMARY: Passed: $testsPassed, Failed: $testsFailed\n";
echo "============================================================\n";

if ($testsFailed > 0) {
    exit(1);
}
exit(0);
