<?php
declare(strict_types=1);

/**
 * Unit Test untuk Klasifikasi Google Drive Activity Telemetry & Idempotensi Sinkronisasi
 *
 * Menguji skenario:
 * 1. create + move -> 1 event create
 * 2. rename + edit -> 0 event kontribusi
 * 3. permissionChange + edit -> 0 event kontribusi
 * 4. edit -> 1 event edit
 * 5. create + secondary edit -> Maksimal 1 event create, tanpa event edit turunan
 * 6. edit oleh actor lain -> Tidak boleh diatribusikan kepada pengguna yang salah (isCurrentUser false & ambiguous actors)
 * 7. sinkronisasi ulang -> Idempoten, ON CONFLICT DO NOTHING, tidak membuat duplikasi
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../integrations/google/google_service.php';

$testsPassed = 0;
$testsFailed = 0;

function assert_scenario(string $scenario, bool $condition, string $detail = ''): void
{
    global $testsPassed, $testsFailed;
    if ($condition) {
        $testsPassed++;
        echo " [PASS] $scenario\n";
    } else {
        $testsFailed++;
        echo "![FAIL] $scenario - $detail\n";
    }
}

echo "====================================================================\n";
echo "FAIRSHARE GOOGLE DRIVE ACTIVITY CLASSIFICATION UNIT TEST SUITE\n";
echo "====================================================================\n\n";

// -------------------------------------------------------------------------
// REAL FIXTURES DARI AUDIT DOKUMEN GOOGLE DRIVE ACTIVITY API V2
// -------------------------------------------------------------------------

$fixtureCreateMove = [
    'timestamp' => '2026-11-25T16:18:49.298Z',
    'actors' => [
        ['user' => ['knownUser' => ['personName' => 'people/114073686172703659262', 'isCurrentUser' => true]]],
    ],
    'primaryActionDetail' => [
        'create' => ['new' => []],
    ],
    'actions' => [
        [
            'detail' => ['create' => ['new' => []]],
            'timestamp' => null,
            'actor' => null,
            'target' => null,
        ],
        [
            'detail' => ['move' => ['addedParents' => [['driveItem' => ['name' => 'items/root']]]]],
            'timestamp' => null,
            'actor' => null,
            'target' => null,
        ],
    ],
    'targets' => [
        ['driveItem' => ['name' => 'items/doc123', 'title' => 'Dokumen tanpa judul']],
    ],
];

$fixtureRenameEdit = [
    'timestamp' => '2026-11-25T16:19:00.302Z',
    'actors' => [
        ['user' => ['knownUser' => ['personName' => 'people/114073686172703659262', 'isCurrentUser' => true]]],
    ],
    'primaryActionDetail' => [
        'rename' => ['oldTitle' => 'Dokumen tanpa judul', 'newTitle' => 'test wei'],
    ],
    'actions' => [
        [
            'detail' => ['rename' => ['oldTitle' => 'Dokumen tanpa judul', 'newTitle' => 'test wei']],
            'timestamp' => null,
            'actor' => null,
            'target' => null,
        ],
        [
            'detail' => ['edit' => []],
            'timestamp' => null,
            'actor' => null,
            'target' => null,
        ],
    ],
    'targets' => [
        ['driveItem' => ['name' => 'items/doc123', 'title' => 'test wei']],
    ],
];

$fixturePermissionChangeEdit = [
    'timestamp' => '2026-11-25T16:21:35.564Z',
    'actors' => [
        ['user' => ['knownUser' => ['personName' => 'people/114073686172703659262', 'isCurrentUser' => true]]],
    ],
    'primaryActionDetail' => [
        'permissionChange' => ['addedPermissions' => [['role' => 'EDITOR', 'user' => ['knownUser' => ['personName' => 'people/other']]]]],
    ],
    'actions' => [
        [
            'detail' => ['permissionChange' => ['addedPermissions' => [['role' => 'EDITOR']]]],
            'timestamp' => null,
            'actor' => null,
            'target' => null,
        ],
        [
            'detail' => ['edit' => []],
            'timestamp' => null,
            'actor' => null,
            'target' => null,
        ],
    ],
    'targets' => [
        ['driveItem' => ['name' => 'items/doc123', 'title' => 'test wei']],
    ],
];

$fixtureContentEdit = [
    'timestamp' => '2026-11-25T16:22:33.310Z',
    'actors' => [
        ['user' => ['knownUser' => ['personName' => 'people/101039728263636731467', 'isCurrentUser' => true]]],
    ],
    'primaryActionDetail' => [
        'edit' => [],
    ],
    'actions' => [
        [
            'detail' => ['edit' => []],
            'timestamp' => null,
            'actor' => null,
            'target' => null,
        ],
    ],
    'targets' => [
        ['driveItem' => ['name' => 'items/doc123', 'title' => 'test wei']],
    ],
];

$fixtureCreateSecondaryEdit = [
    'timestamp' => '2026-11-25T16:18:49.298Z',
    'actors' => [
        ['user' => ['knownUser' => ['personName' => 'people/114073686172703659262', 'isCurrentUser' => true]]],
    ],
    'primaryActionDetail' => [
        'create' => ['new' => []],
    ],
    'actions' => [
        [
            'detail' => ['create' => ['new' => []]],
            'timestamp' => null,
            'actor' => null,
            'target' => null,
        ],
        [
            'detail' => ['edit' => []],
            'timestamp' => null,
            'actor' => null,
            'target' => null,
        ],
    ],
    'targets' => [
        ['driveItem' => ['name' => 'items/doc123', 'title' => 'Dokumen']],
    ],
];

$fixtureEditOtherActor = [
    'timestamp' => '2026-11-25T16:25:34.273Z',
    'actors' => [
        ['user' => ['knownUser' => ['personName' => 'people/someone_else', 'isCurrentUser' => false]]],
    ],
    'primaryActionDetail' => [
        'edit' => [],
    ],
    'actions' => [
        [
            'detail' => ['edit' => []],
            'timestamp' => null,
            'actor' => null,
            'target' => null,
        ],
    ],
    'targets' => [
        ['driveItem' => ['name' => 'items/doc123', 'title' => 'test wei']],
    ],
];

$fixtureAmbiguousActors = [
    'timestamp' => '2026-11-25T16:26:00.000Z',
    'actors' => [
        ['user' => ['knownUser' => ['personName' => 'people/user1', 'isCurrentUser' => true]]],
        ['user' => ['knownUser' => ['personName' => 'people/user2', 'isCurrentUser' => false]]],
    ],
    'primaryActionDetail' => [
        'edit' => [],
    ],
    'actions' => [
        [
            'detail' => ['edit' => []],
            'timestamp' => null,
            'actor' => null, // ambigu: 2 parent actors!
            'target' => null,
        ],
    ],
    'targets' => [
        ['driveItem' => ['name' => 'items/doc123', 'title' => 'test wei']],
    ],
];

// -------------------------------------------------------------------------
// SKENARIO 1: create + move -> 1 event create
// -------------------------------------------------------------------------
echo "--- Skenario 1: create + move ---\n";
$res1 = fs_google_filter_activity_actions($fixtureCreateMove);
assert_scenario(
    'create + move menghasilkan tepat 1 candidate action',
    count($res1) === 1,
    'Ditemukan ' . count($res1) . ' actions'
);
assert_scenario(
    'Candidate action bertipe create dan bukan move',
    isset($res1[0]) && $res1[0]['action_type'] === 'create' && $res1[0]['primary_type'] === 'create',
    'Tipe action: ' . ($res1[0]['action_type'] ?? 'none')
);
assert_scenario(
    'Candidate action teratribusi ke current user',
    isset($res1[0]) && $res1[0]['is_current_user'] === true,
    'Atribusi gagal'
);

// -------------------------------------------------------------------------
// SKENARIO 2: rename + edit -> 0 event kontribusi
// -------------------------------------------------------------------------
echo "\n--- Skenario 2: rename + edit ---\n";
$res2 = fs_google_filter_activity_actions($fixtureRenameEdit);
assert_scenario(
    'rename + edit diabaikan secara penuh (0 event kontribusi)',
    count($res2) === 0,
    'Ditemukan ' . count($res2) . ' actions yang salah lolos filter'
);

// -------------------------------------------------------------------------
// SKENARIO 3: permissionChange + edit -> 0 event kontribusi
// -------------------------------------------------------------------------
echo "\n--- Skenario 3: permissionChange + edit ---\n";
$res3 = fs_google_filter_activity_actions($fixturePermissionChangeEdit);
assert_scenario(
    'permissionChange + edit diabaikan secara penuh (0 event kontribusi)',
    count($res3) === 0,
    'Ditemukan ' . count($res3) . ' actions yang salah lolos filter'
);

// -------------------------------------------------------------------------
// SKENARIO 4: edit -> 1 event edit
// -------------------------------------------------------------------------
echo "\n--- Skenario 4: Content edit ---\n";
$res4 = fs_google_filter_activity_actions($fixtureContentEdit);
assert_scenario(
    'edit konten menghasilkan tepat 1 candidate action',
    count($res4) === 1,
    'Ditemukan ' . count($res4) . ' actions'
);
assert_scenario(
    'Candidate action bertipe edit',
    isset($res4[0]) && $res4[0]['action_type'] === 'edit' && $res4[0]['primary_type'] === 'edit',
    'Tipe action: ' . ($res4[0]['action_type'] ?? 'none')
);
assert_scenario(
    'Timestamp terdeteksi dengan tepat',
    isset($res4[0]) && $res4[0]['activity_time'] === '2026-11-25 16:22:33',
    'Timestamp: ' . ($res4[0]['activity_time'] ?? 'null')
);

// -------------------------------------------------------------------------
// SKENARIO 5: create + secondary edit -> Maksimal 1 event create, tanpa edit turunan
// -------------------------------------------------------------------------
echo "\n--- Skenario 5: create + secondary edit ---\n";
$res5 = fs_google_filter_activity_actions($fixtureCreateSecondaryEdit);
assert_scenario(
    'create + secondary edit hanya menghasilkan 1 event',
    count($res5) === 1,
    'Ditemukan ' . count($res5) . ' actions'
);
assert_scenario(
    'Event yang dihasilkan adalah create, bukan secondary edit',
    isset($res5[0]) && $res5[0]['action_type'] === 'create',
    'Tipe action: ' . ($res5[0]['action_type'] ?? 'none')
);

// -------------------------------------------------------------------------
// SKENARIO 6: edit oleh actor lain & pemeriksaan atribusi
// -------------------------------------------------------------------------
echo "\n--- Skenario 6: edit oleh actor lain & non-ambiguous check ---\n";
$res6 = fs_google_filter_activity_actions($fixtureEditOtherActor);
assert_scenario(
    'Action terdeteksi namun is_current_user adalah false (tidak diatribusikan ke user aktif)',
    count($res6) === 1 && $res6[0]['is_current_user'] === false,
    'is_current_user bernilai true padahal bukan milik user'
);

$res6b = fs_google_filter_activity_actions($fixtureAmbiguousActors);
assert_scenario(
    'Multi-actor tanpa actor pada action tidak diatribusikan (ambigu)',
    count($res6b) === 1 && $res6b[0]['is_current_user'] === false,
    'Salah mengatribusikan aksi multi-actor ambigu'
);

// -------------------------------------------------------------------------
// SKENARIO 7: sinkronisasi ulang & pengujian ON CONFLICT database
// -------------------------------------------------------------------------
echo "\n--- Skenario 7: Sinkronisasi Ulang & Database ON CONFLICT Idempotensi ---\n";
try {
    $pdo = require_db();
    $pdo->beginTransaction();

    $user = $pdo->query("SELECT id FROM public.users LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $userId = $user['id'];
    $group = $pdo->query("SELECT id FROM public.groups LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $groupId = $group['id'];
    $task = $pdo->query("SELECT id FROM public.tasks LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $taskId = $task['id'];

    $testExtId = 'test_idem_' . bin2hex(random_bytes(8));
    $timestamp = '2026-11-25 16:22:33';

    $insertSql = "
        INSERT INTO public.telemetry_logs (
            task_id, group_id, user_id, source, external_id,
            activity_timestamp, raw_delta, filtered_delta, metadata, created_at
        ) VALUES (
            CAST(:task_id AS UUID), CAST(:group_id AS UUID), CAST(:user_id AS UUID),
            'gdocs', :external_id, :activity_timestamp,
            :raw_delta, :filtered_delta, CAST(:metadata AS JSONB), CURRENT_TIMESTAMP
        ) ON CONFLICT (task_id, source, external_id)
        WHERE task_id IS NOT NULL
          AND external_id IS NOT NULL
        DO NOTHING
    ";

    $stmt = $pdo->prepare($insertSql);

    // Sinkronisasi pertama
    $stmt->execute([
        'task_id' => $taskId,
        'group_id' => $groupId,
        'user_id' => $userId,
        'external_id' => $testExtId,
        'activity_timestamp' => $timestamp,
        'raw_delta' => 1.0,
        'filtered_delta' => 1.0,
        'metadata' => json_encode(['sync' => 1]),
    ]);
    $sync1Count = $stmt->rowCount();
    assert_scenario('Sinkronisasi pertama menyimpan event baru (rowCount = 1)', $sync1Count === 1, "rowCount: $sync1Count");

    // Sinkronisasi ulang dengan event yang sama (idempotensi)
    $stmt->execute([
        'task_id' => $taskId,
        'group_id' => $groupId,
        'user_id' => $userId,
        'external_id' => $testExtId,
        'activity_timestamp' => $timestamp,
        'raw_delta' => 1.0,
        'filtered_delta' => 1.0,
        'metadata' => json_encode(['sync' => 2]),
    ]);
    $sync2Count = $stmt->rowCount();
    assert_scenario('Sinkronisasi ulang tidak menduplikasi data (rowCount = 0 via DO NOTHING)', $sync2Count === 0, "rowCount: $sync2Count");

    // Pastikan tidak ada SQLSTATE 42P10 dan data di DB tetap utuh
    $chkStmt = $pdo->prepare("SELECT metadata FROM public.telemetry_logs WHERE task_id = CAST(:task_id AS UUID) AND source = 'gdocs' AND external_id = :external_id");
    $chkStmt->execute(['task_id' => $taskId, 'external_id' => $testExtId]);
    $row = $chkStmt->fetch(PDO::FETCH_ASSOC);
    $meta = json_decode((string)$row['metadata'], true);
    assert_scenario('Data asli tidak ditimpa/diubah saat sinkronisasi ulang', ($meta['sync'] ?? 0) === 1, 'Metadata termodifikasi');

    $pdo->rollBack();
    echo " Transaksi database di-rollback dengan aman. Tidak ada data telemetry yang diubah.\n";
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    assert_scenario('Pengujian database tidak boleh error', false, $e->getMessage());
}

echo "\n====================================================================\n";
echo "HASIL AKHIR: $testsPassed PASSED, $testsFailed FAILED\n";
echo "====================================================================\n";

if ($testsFailed > 0) {
    exit(1);
}
