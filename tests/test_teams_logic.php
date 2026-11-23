<?php
/**
 * FairShare – Teams module diagnostic script.
 * Verifikasi:
 *   1. valid_uuid() bekerja dengan benar.
 *   2. Koneksi Supabase dapat dibuka.
 *   3. Tabel public.users, public.groups, public.group_members ada.
 *   4. gen_random_uuid() menghasilkan UUID valid.
 *   5. Simulasi alur CREATE GROUP (tanpa commit) untuk membuktikan tidak ada SQLSTATE 22P02.
 *
 * Jalankan dari command line:
 *   C:\xampp\php\php.exe data\test_teams_logic.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

$pass = 0;
$fail = 0;
function ok(string $label): void { global $pass; echo "[PASS] $label\n"; $pass++; }
function fail(string $label, string $reason = ''): void { global $fail; echo "[FAIL] $label" . ($reason ? " - $reason" : '') . "\n"; $fail++; }

// 1. valid_uuid()
$cases = [
    ['550e8400-e29b-41d4-a716-446655440000', true,  'standard UUID'],
    ['',                                     false, 'empty string'],
    ['not-a-uuid',                           false, 'plain text'],
    ['00000000-0000-0000-0000-000000000000', false, 'nil UUID'],
];
foreach ($cases as [$val, $expect, $label]) {
    $result = valid_uuid($val);
    $result === $expect ? ok("valid_uuid($label)") : fail("valid_uuid($label)", "got " . var_export($result, true));
}

// 2. Koneksi Supabase
try {
    $pdo = require_db();
    ok('Koneksi PDO PostgreSQL berhasil');
} catch (Throwable $e) {
    fail('Koneksi PDO PostgreSQL', $e->getMessage());
    echo "\nTotal: $pass lulus, $fail gagal\n";
    exit(1);
}

// 3. Tabel wajib ada
foreach (['users', 'groups', 'group_members'] as $tbl) {
    $row = $pdo->query("SELECT to_regclass('public.$tbl')::text AS t")->fetch();
    isset($row['t']) && $row['t'] !== null
        ? ok("Tabel public.$tbl ada")
        : fail("Tabel public.$tbl", 'tidak ditemukan');
}

// 4. gen_random_uuid()
$uuid = (string) $pdo->query('SELECT gen_random_uuid()::text AS u')->fetchColumn();
valid_uuid($uuid) ? ok("gen_random_uuid() = $uuid") : fail('gen_random_uuid()', "hasil: $uuid");

// 5. Ada pengguna?
$userCount = (int) $pdo->query('SELECT COUNT(*) FROM public.users')->fetchColumn();
$userCount > 0 ? ok("public.users memiliki $userCount pengguna") : fail('public.users', 'kosong');

// 6. Simulasi CREATE GROUP (rollback)
if ($userCount > 0) {
    $testUser = $pdo->query("SELECT id::text AS id, email FROM public.users LIMIT 1")->fetch();
    $testUid  = (string) $testUser['id'];
    echo "INFO: Pengguna uji: email={$testUser['email']}, id=$testUid\n";

    if (!valid_uuid($testUid)) {
        fail('UUID pengguna uji', "bukan UUID valid: $testUid");
    } else {
        ok("UUID pengguna uji valid");
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                "INSERT INTO public.groups
                    (id, created_by, name, class_name, duration_weeks, github_repo_url, gdocs_file_id, created_at, updated_at)
                VALUES (
                    gen_random_uuid(), CAST(:creator AS uuid), :name, :class_name, 16, NULL, NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                )
                RETURNING id::text"
            );
            $stmt->execute(['creator' => $testUid, 'name' => '__DiagnosticTest__', 'class_name' => 'PBL']);

            // fetchColumn() HANYA SEKALI - ini kunci perbaikannya
            $gid = (string) $stmt->fetchColumn();

            if (!valid_uuid($gid)) {
                fail('RETURNING id dari INSERT groups', "bukan UUID valid: " . var_export($gid, true));
                $pdo->rollBack();
            } else {
                ok("INSERT groups RETURNING id = $gid");

                $member = $pdo->prepare(
                    "INSERT INTO public.group_members
                        (id, group_id, user_id, role_in_group, peer_approval, joined_at)
                    SELECT gen_random_uuid(), CAST(:gid AS uuid), CAST(:uid AS uuid), 'ketua', FALSE, CURRENT_TIMESTAMP
                    WHERE NOT EXISTS (
                        SELECT 1 FROM public.group_members
                        WHERE group_id = CAST(:gid2 AS uuid)
                          AND user_id  = CAST(:uid2  AS uuid)
                    )"
                );
                $member->execute(['gid' => $gid, 'uid' => $testUid, 'gid2' => $gid, 'uid2' => $testUid]);
                ok('INSERT group_members berhasil - INSERT...SELECT...WHERE NOT EXISTS berfungsi, tidak ada SQLSTATE 22P02');

                $pdo->rollBack();
                ok('Rollback berhasil - tidak ada data uji tersimpan');
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            fail('Simulasi CREATE GROUP', 'SQLSTATE ' . $e->getCode() . ': ' . $e->getMessage());
        }
    }
}

echo "\n========================================\n";
echo "Hasil: $pass lulus | $fail gagal\n";
echo "========================================\n";
exit($fail > 0 ? 1 : 0);
