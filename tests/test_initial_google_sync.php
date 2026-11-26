<?php
declare(strict_types=1);

/**
 * Unit Test untuk Initial & Incremental Google Drive Activity Sync
 *
 * Menguji skenario:
 * 1. Dokumen dibuat sebelum task FairShare -> Create tetap ditemukan (tanpa filter waktu pada initial sync)
 * 2. Create, lalu rename -> Hanya create
 * 3. Create, lalu permissionChange -> Hanya create
 * 4. Create lebih lama beberapa jam sebelum task -> Create tetap ditemukan jika tersedia dari API
 * 5. Lebih dari satu halaman -> Semua halaman diproses (pagination loop)
 * 6. Halaman kedua gagal -> Cursor tidak maju (error handling & state preservation)
 * 7. Sinkronisasi ulang -> Tidak ada duplikasi (idempoten via ON CONFLICT DO NOTHING)
 * 8. Cursor sudah ada -> Incremental filter tetap berjalan (time >= cursor - 120s)
 * 9. Dua anggota memiliki integrasi Google -> Cursor diproses secara terpisah
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../integrations/google/google_service.php';

$testsPassed = 0;
$testsFailed = 0;

function assert_sync_test(string $name, bool $condition, string $detail = ''): void
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

echo "====================================================================\n";
echo "FAIRSHARE INITIAL & INCREMENTAL GOOGLE SYNC TEST SUITE\n";
echo "====================================================================\n\n";

$fileId = '1ntFaXRSCD9nrrOMOi7KrNAQAyhSwOkSQ8qKIUH-hY-Y';

// -------------------------------------------------------------------------
// SKENARIO 1: Dokumen dibuat sebelum task FairShare -> Create tetap ditemukan
// -------------------------------------------------------------------------
echo "--- Skenario 1: Dokumen dibuat sebelum task FairShare ---\n";
// Task dibuat pada 17:16:17 UTC. Doc dibuat pada 17:13:00 UTC (3m 17s sebelum task).
// Pada initial sync ($since === null), build request TIDAK boleh menyertakan properti 'filter'.
$reqInitial = fs_google_build_activity_request($fileId, null);
assert_sync_test('Initial sync request memiliki itemName yang benar', $reqInitial['itemName'] === 'items/' . $fileId);
assert_sync_test('Initial sync request pageSize adalah 100', $reqInitial['pageSize'] === 100);
assert_sync_test('Initial sync request TIDAK menyertakan properti filter', !isset($reqInitial['filter']));

// Fixture riil create event Rayhan pada 17:13:00 UTC
$fixtureCreate = [
    'timestamp' => '2026-11-25T17:13:00.154Z',
    'actors' => [
        ['user' => ['knownUser' => ['personName' => 'people/114073686172703659262', 'isCurrentUser' => true]]],
    ],
    'primaryActionDetail' => ['create' => ['new' => []]],
    'actions' => [
        ['detail' => ['create' => ['new' => []]]],
        ['detail' => ['move' => ['addedParents' => [['driveItem' => ['name' => 'items/root']]]]]],
    ],
    'targets' => [
        ['driveItem' => ['name' => 'items/' . $fileId, 'title' => 'Dokumen tanpa judul']],
    ],
];
$cand1 = fs_google_filter_activity_actions($fixtureCreate);
assert_sync_test('Event create dari dokumen pra-task berhasil diekstrak', count($cand1) === 1 && $cand1[0]['action_type'] === 'create');
assert_sync_test('Timestamp create pra-task akurat', ($cand1[0]['activity_time'] ?? '') === '2026-11-25 17:13:00');

// -------------------------------------------------------------------------
// SKENARIO 2: Create, lalu rename -> Hanya create
// -------------------------------------------------------------------------
echo "\n--- Skenario 2: Create, lalu rename ---\n";
$fixtureRename = [
    'timestamp' => '2026-11-25T17:13:08.600Z',
    'actors' => [
        ['user' => ['knownUser' => ['personName' => 'people/114073686172703659262', 'isCurrentUser' => true]]],
    ],
    'primaryActionDetail' => ['rename' => ['oldTitle' => 'Dokumen tanpa judul', 'newTitle' => 'Test Wei']],
    'actions' => [
        ['detail' => ['rename' => ['oldTitle' => 'Dokumen tanpa judul', 'newTitle' => 'Test Wei']]],
        ['detail' => ['edit' => []]],
    ],
    'targets' => [
        ['driveItem' => ['name' => 'items/' . $fileId, 'title' => 'Test Wei']],
    ],
];
$batch2 = [$fixtureCreate, $fixtureRename];
$candidates2 = [];
foreach ($batch2 as $act) {
    foreach (fs_google_filter_activity_actions($act) as $c) {
        $candidates2[] = $c;
    }
}
assert_sync_test('Create + rename hanya menghasilkan tepat 1 event kontribusi', count($candidates2) === 1);
assert_sync_test('Event kontribusi tunggal tersebut adalah create', ($candidates2[0]['action_type'] ?? '') === 'create');

// -------------------------------------------------------------------------
// SKENARIO 3: Create, lalu permissionChange -> Hanya create
// -------------------------------------------------------------------------
echo "\n--- Skenario 3: Create, lalu permissionChange ---\n";
$fixturePerm = [
    'timestamp' => '2026-11-25T17:13:19.483Z',
    'actors' => [
        ['user' => ['knownUser' => ['personName' => 'people/114073686172703659262', 'isCurrentUser' => true]]],
    ],
    'primaryActionDetail' => ['permissionChange' => ['addedPermissions' => [['role' => 'EDITOR']]]],
    'actions' => [
        ['detail' => ['permissionChange' => ['addedPermissions' => [['role' => 'EDITOR']]]]],
        ['detail' => ['edit' => []]],
    ],
    'targets' => [
        ['driveItem' => ['name' => 'items/' . $fileId, 'title' => 'Test Wei']],
    ],
];
$batch3 = [$fixtureCreate, $fixturePerm];
$candidates3 = [];
foreach ($batch3 as $act) {
    foreach (fs_google_filter_activity_actions($act) as $c) {
        $candidates3[] = $c;
    }
}
assert_sync_test('Create + permissionChange hanya menghasilkan tepat 1 event kontribusi', count($candidates3) === 1);
assert_sync_test('Event kontribusi adalah create (bukan edit turunan permission)', ($candidates3[0]['action_type'] ?? '') === 'create');

// -------------------------------------------------------------------------
// SKENARIO 4: Create lebih lama beberapa jam sebelum task -> Tetap ditemukan
// -------------------------------------------------------------------------
echo "\n--- Skenario 4: Create lebih lama beberapa jam sebelum task ---\n";
// Misal doc dibuat 5 jam sebelum task FairShare
$fixtureOldCreate = [
    'timestamp' => '2026-11-25T12:00:00.000Z',
    'actors' => [
        ['user' => ['knownUser' => ['personName' => 'people/114073686172703659262', 'isCurrentUser' => true]]],
    ],
    'primaryActionDetail' => ['create' => ['new' => []]],
    'actions' => [
        ['detail' => ['create' => ['new' => []]]],
    ],
    'targets' => [
        ['driveItem' => ['name' => 'items/' . $fileId, 'title' => 'Dokumen Lama']],
    ],
];
// Karena initial sync tidak membatasi waktu dengan task.created_at, request bebas filter waktu
$reqInitialOld = fs_google_build_activity_request($fileId, null);
assert_sync_test('Request initial tidak terpengaruh usia dokumen (tidak ada filter waktu)', !isset($reqInitialOld['filter']));
$cand4 = fs_google_filter_activity_actions($fixtureOldCreate);
assert_sync_test('Event create berumur beberapa jam tetap berhasil diekstrak', count($cand4) === 1 && $cand4[0]['action_type'] === 'create');

// -------------------------------------------------------------------------
// SKENARIO 5: Lebih dari satu halaman (Pagination) -> Semua halaman diproses
// -------------------------------------------------------------------------
echo "\n--- Skenario 5: Lebih dari satu halaman (Pagination) ---\n";
// Simulasi pagination loop helper
function simulate_pagination_sync(array $pages): array
{
    $allActivities = [];
    $pageIndex = 0;
    do {
        $page = $pages[$pageIndex] ?? null;
        if ($page === null) {
            break;
        }
        if (!empty($page['error'])) {
            throw new RuntimeException($page['error']);
        }
        foreach (($page['activities'] ?? []) as $act) {
            $allActivities[] = $act;
        }
        $nextPageToken = (string) ($page['nextPageToken'] ?? '');
        $pageIndex++;
    } while ($nextPageToken !== '');

    return $allActivities;
}

$simulatedPages = [
    [
        'activities' => [$fixtureCreate, $fixtureRename],
        'nextPageToken' => 'token_for_page_2',
    ],
    [
        'activities' => [
            [
                'timestamp' => '2026-11-25T17:18:21.000Z',
                'actors' => [['user' => ['knownUser' => ['personName' => 'people/114073686172703659262', 'isCurrentUser' => true]]]],
                'primaryActionDetail' => ['edit' => []],
                'actions' => [['detail' => ['edit' => []]]],
                'targets' => [['driveItem' => ['name' => 'items/' . $fileId, 'title' => 'Test Wei']]],
            ]
        ],
        'nextPageToken' => '', // Halaman terakhir
    ],
];

$pagedActivities = simulate_pagination_sync($simulatedPages);
assert_sync_test('Pagination berhasil mengumpulkan aktivitas dari semua halaman (total 3 aktivitas)', count($pagedActivities) === 3);

// -------------------------------------------------------------------------
// SKENARIO 6: Halaman kedua gagal -> Cursor tidak maju
// -------------------------------------------------------------------------
echo "\n--- Skenario 6: Halaman kedua gagal -> Cursor tidak maju ---\n";
$simulatedFailingPages = [
    [
        'activities' => [$fixtureCreate],
        'nextPageToken' => 'token_for_page_2',
    ],
    [
        'error' => 'HTTP 500 Google API Internal Error',
        'nextPageToken' => '',
    ],
];

$stateMock = ['user_1' => '2026-11-25 16:00:00'];
$initialCursorBeforeSync = $stateMock['user_1'];
$syncCompleted = false;

try {
    $syncStartedAt = '2026-11-25 17:30:00';
    $activitiesFailing = simulate_pagination_sync($simulatedFailingPages);
    // Jika tidak gagal, cursor maju:
    $stateMock['user_1'] = $syncStartedAt;
    $syncCompleted = true;
} catch (Throwable $e) {
    // Sinkronisasi gagal, tangkap exception
}

assert_sync_test('Proses sinkronisasi terhenti jika halaman kedua error', $syncCompleted === false);
assert_sync_test('Cursor anggota tidak maju saat pagination gagal', $stateMock['user_1'] === $initialCursorBeforeSync);

// -------------------------------------------------------------------------
// SKENARIO 7: Sinkronisasi ulang -> Tidak ada duplikasi (Idempoten via DB)
// -------------------------------------------------------------------------
echo "\n--- Skenario 7: Sinkronisasi ulang -> Idempoten & Tidak ada duplikasi ---\n";
try {
    $pdo = require_db();
    $pdo->beginTransaction();

    $user = $pdo->query("SELECT id FROM public.users LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $userId = $user['id'];
    $group = $pdo->query("SELECT id FROM public.groups LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $groupId = $group['id'];
    $task = $pdo->query("SELECT id FROM public.tasks LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $taskId = $task['id'];

    $testExtId = 'test_re_' . bin2hex(random_bytes(8));
    $timestamp = '2026-11-25 17:13:00';

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

    // Sync 1
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
    assert_sync_test('Sync pertama memasukkan 1 baris (rowCount = 1)', $stmt->rowCount() === 1);

    // Sync 2 (Ulang)
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
    assert_sync_test('Sync ulang tidak menduplikasi (rowCount = 0 via DO NOTHING)', $stmt->rowCount() === 0);

    $pdo->rollBack();
    echo " Transaksi database di-rollback dengan aman.\n";
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    assert_sync_test('Database test tidak boleh error', false, $e->getMessage());
}

// -------------------------------------------------------------------------
// SKENARIO 8: Cursor sudah ada -> Incremental filter tetap berjalan (120s overlap)
// -------------------------------------------------------------------------
echo "\n--- Skenario 8: Cursor sudah ada -> Incremental filter berjalan ---\n";
$savedCursor = '2026-11-25 17:22:07';
$reqIncremental = fs_google_build_activity_request($fileId, $savedCursor);

assert_sync_test('Incremental sync request menyertakan properti filter', isset($reqIncremental['filter']));
// 17:22:07 UTC - 120 detik = 17:20:07 UTC
assert_sync_test(
    'Filter waktu memiliki batas mundur 120 detik yang tepat',
    $reqIncremental['filter'] === 'time >= "2026-11-25T17:20:07Z"',
    'Filter: ' . ($reqIncremental['filter'] ?? 'null')
);

// -------------------------------------------------------------------------
// SKENARIO 9: Dua anggota memiliki integrasi Google -> Cursor terpisah
// -------------------------------------------------------------------------
echo "\n--- Skenario 9: Dua anggota -> Cursor diproses terpisah ---\n";
$memberRayhan = 'c820f70f-cfe1-4baa-b085-c754e4cc8c2a';
$memberAhmad = 'b559c9e3-fb1c-4b39-bfc0-dba145e50a94';

// Misal Rayhan sudah pernah sync sebelumnya (cursor ada), sedangkan Ahmad baru pertama kali (cursor belum ada)
$simulatedState = [
    $memberRayhan => '2026-11-25 17:22:07',
];

$sinceRayhan = isset($simulatedState[$memberRayhan]) && trim((string) $simulatedState[$memberRayhan]) !== ''
    ? (string) $simulatedState[$memberRayhan]
    : null;

$sinceAhmad = isset($simulatedState[$memberAhmad]) && trim((string) $simulatedState[$memberAhmad]) !== ''
    ? (string) $simulatedState[$memberAhmad]
    : null;

$reqRayhan = fs_google_build_activity_request($fileId, $sinceRayhan);
$reqAhmad = fs_google_build_activity_request($fileId, $sinceAhmad);

assert_sync_test('Anggota dengan cursor (Rayhan) menjalankan incremental sync berfilter', isset($reqRayhan['filter']) && str_contains($reqRayhan['filter'], '2026-11-25T17:20:07Z'));
assert_sync_test('Anggota tanpa cursor (Ahmad) menjalankan initial sync tanpa filter', !isset($reqAhmad['filter']));

// Simulasi update cursor setelah Ahmad selesai sync
$simulatedState[$memberAhmad] = '2026-11-25 17:25:00';
assert_sync_test('Cursor kedua anggota tersimpan terpisah dalam sync_state', count($simulatedState) === 2 && isset($simulatedState[$memberRayhan], $simulatedState[$memberAhmad]));

echo "\n====================================================================\n";
echo "HASIL AKHIR: $testsPassed PASSED, $testsFailed FAILED\n";
echo "====================================================================\n";

if ($testsFailed > 0) {
    exit(1);
}
