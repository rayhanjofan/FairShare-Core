<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

$pass = 0;
$fail = 0;

function test_assert(bool $condition, string $scenario, string $detail = ''): void {
    global $pass, $fail;
    if ($condition) {
        echo "  [PASS] $scenario\n";
        $pass++;
    } else {
        echo "  [FAIL] $scenario" . ($detail ? " ($detail)" : '') . "\n";
        $fail++;
    }
}

echo "============================================================\n";
echo "FAIRSHARE TEST SUITE: KELAS, KELOMPOK, TUGAS, & MONITORING\n";
echo "============================================================\n\n";

$pdo = require_db();

$dosen = $pdo->query("SELECT id::text AS id, name, email, role FROM public.users WHERE role = 'dosen' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$students = $pdo->query("SELECT id::text AS id, name, email, role FROM public.users WHERE role = 'mahasiswa' LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);

if (!$dosen || count($students) < 2) {
    echo "ERROR: Data user dosen atau mahasiswa kurang untuk pengujian.\n";
    exit(1);
}

$mhsA = $students[0];
$mhsB = $students[1];
$mhsC = $students[2] ?? null;

echo "Akun Uji:\n";
echo "  Dosen: {$dosen['name']} ({$dosen['email']}) [{$dosen['id']}]\n";
echo "  Mhs A: {$mhsA['name']} ({$mhsA['email']}) [{$mhsA['id']}]\n";
echo "  Mhs B: {$mhsB['name']} ({$mhsB['email']}) [{$mhsB['id']}]\n\n";

$pdo->beginTransaction();

try {
    // ------------------------------------------------------------
    // SKENARIO A: DOSEN MEMBUAT KELAS & KODE UNDANGAN UNIK
    // ------------------------------------------------------------
    echo "--- SKENARIO A: PENGUJIAN DOSEN ---\n";

    $codePBO = generate_unique_invite_code($pdo, 'PBO');
    $stmt = $pdo->prepare("
        INSERT INTO public.groups (id, created_by, name, duration_weeks, group_type, parent_group_id, invite_code, semester, academic_year)
        VALUES (gen_random_uuid(), CAST(:creator AS uuid), 'Pemrograman Berbasis Objek (Uji)', 16, 'class', NULL, :code, 'Genap', '2025/2026')
        RETURNING id::text
    ");
    $stmt->execute(['creator' => $dosen['id'], 'code' => $codePBO]);
    $classPBO_id = (string) $stmt->fetchColumn();
    test_assert(valid_uuid($classPBO_id), "Dosen berhasil membuat Kelas PBO (ID: $classPBO_id)");

    $codeUIUX = generate_unique_invite_code($pdo, 'UIUX');
    $stmt->execute(['creator' => $dosen['id'], 'code' => $codeUIUX]);
    $classUIUX_id = (string) $stmt->fetchColumn();
    test_assert(valid_uuid($classUIUX_id), "Dosen berhasil membuat Kelas UI/UX (ID: $classUIUX_id)");

    test_assert($codePBO !== $codeUIUX, "Kedua kelas memiliki kode undangan unik & berbeda ($codePBO vs $codeUIUX)");

    $dosenClasses = all_classes($dosen['id']);
    $dosenClassIds = array_column($dosenClasses, 'id');
    test_assert(in_array($classPBO_id, $dosenClassIds, true) && in_array($classUIUX_id, $dosenClassIds, true), "Dosen dapat melihat seluruh kelas miliknya");

    // ------------------------------------------------------------
    // SKENARIO B: MAHASISWA BERGABUNG KE KELAS
    // ------------------------------------------------------------
    echo "\n--- SKENARIO B: PENGUJIAN MAHASISWA BERGABUNG KELAS ---\n";

    $foundClass = class_by_invite_code($codePBO);
    test_assert($foundClass !== null && $foundClass['id'] === $classPBO_id, "Pencarian kelas via kode undangan $codePBO berhasil");

    $joinStmt = $pdo->prepare("
        INSERT INTO public.group_members (id, group_id, user_id, role_in_group, peer_approval, joined_at)
        VALUES (gen_random_uuid(), CAST(:gid AS uuid), CAST(:uid AS uuid), 'anggota', FALSE, CURRENT_TIMESTAMP)
    ");
    $joinStmt->execute(['gid' => $classPBO_id, 'uid' => $mhsA['id']]);
    test_assert(true, "Mahasiswa A ({$mhsA['name']}) berhasil bergabung ke kelas PBO");

    $joinStmt->execute(['gid' => $classPBO_id, 'uid' => $mhsB['id']]);
    test_assert(true, "Mahasiswa B ({$mhsB['name']}) berhasil bergabung ke kelas PBO");

    // Coba gabung lagi menggunakan SAVEPOINT agar transaksi PostgreSQL tidak abort
    $pdo->exec("SAVEPOINT sp_dup_class");
    try {
        $joinStmt->execute(['gid' => $classPBO_id, 'uid' => $mhsA['id']]);
        test_assert(false, "Pencegahan duplikasi anggota kelas", "Seharusnya ditolak UNIQUE");
        $pdo->exec("RELEASE SAVEPOINT sp_dup_class");
    } catch (PDOException $e) {
        $pdo->exec("ROLLBACK TO SAVEPOINT sp_dup_class");
        test_assert(true, "Pencegahan duplikasi keanggotaan kelas berhasil (Ditolak UNIQUE constraint)");
    }

    // ------------------------------------------------------------
    // SKENARIO C: MAHASISWA MEMBUAT KELOMPOK DI DALAM KELAS
    // ------------------------------------------------------------
    echo "\n--- SKENARIO C: PEMBENTUKAN KELOMPOK DALAM KELAS ---\n";

    $codeTeam1 = generate_unique_invite_code($pdo, 'TIM1');
    $stmtTeam = $pdo->prepare("
        INSERT INTO public.groups (id, created_by, name, duration_weeks, group_type, parent_group_id, invite_code, semester, academic_year)
        VALUES (gen_random_uuid(), CAST(:creator AS uuid), 'Kelompok 1 PBO', 16, 'team', CAST(:parent_id AS uuid), :code, 'Genap', '2025/2026')
        RETURNING id::text
    ");
    $stmtTeam->execute(['creator' => $mhsA['id'], 'parent_id' => $classPBO_id, 'code' => $codeTeam1]);
    $team1_id = (string) $stmtTeam->fetchColumn();
    test_assert(valid_uuid($team1_id), "Mahasiswa A berhasil membuat Kelompok 1 (ID: $team1_id, Kode: $codeTeam1)");

    $memberStmt = $pdo->prepare("
        INSERT INTO public.group_members (id, group_id, user_id, role_in_group, peer_approval, joined_at)
        VALUES (gen_random_uuid(), CAST(:gid AS uuid), CAST(:uid AS uuid), :role, FALSE, CURRENT_TIMESTAMP)
    ");
    $memberStmt->execute(['gid' => $team1_id, 'uid' => $mhsA['id'], 'role' => 'ketua']);
    test_assert(true, "Mahasiswa A terdaftar sebagai Project Manager (ketua) di Kelompok 1");

    $memberStmt->execute(['gid' => $team1_id, 'uid' => $mhsB['id'], 'role' => 'anggota']);
    test_assert(true, "Mahasiswa B berhasil bergabung ke Kelompok 1 sebagai anggota");

    // ------------------------------------------------------------
    // SKENARIO D: BATASAN 1 MAHASISWA = 1 KELOMPOK PER KELAS
    // ------------------------------------------------------------
    echo "\n--- SKENARIO D: PENGUJIAN BATASAN KEANGGOTAAN KELOMPOK ---\n";

    $codeTeam2 = generate_unique_invite_code($pdo, 'TIM2');
    $stmtTeam->execute(['creator' => $dosen['id'], 'parent_id' => $classPBO_id, 'code' => $codeTeam2]);
    $team2_id = (string) $stmtTeam->fetchColumn();
    test_assert(valid_uuid($team2_id), "Dosen membuat Kelompok 2 di kelas PBO");

    // Coba masukkan Mahasiswa B ke Kelompok 2 menggunakan SAVEPOINT
    $pdo->exec("SAVEPOINT sp_two_teams");
    try {
        $memberStmt->execute(['gid' => $team2_id, 'uid' => $mhsB['id'], 'role' => 'anggota']);
        test_assert(false, "Pencegahan mahasiswa masuk 2 kelompok dalam 1 kelas", "Harusnya ditolak oleh trigger");
        $pdo->exec("RELEASE SAVEPOINT sp_two_teams");
    } catch (PDOException $e) {
        $pdo->exec("ROLLBACK TO SAVEPOINT sp_two_teams");
        $msg = $e->getMessage();
        $isBlocked = str_contains($msg, 'Mahasiswa sudah menjadi anggota kelompok lain') || str_contains($msg, 'fairshare_validate_group_member');
        test_assert($isBlocked, "Trigger database menolak mahasiswa masuk ke kelompok kedua di kelas yang sama");
    }

    // Coba masukkan mahasiswa yang belum terdaftar di kelas ke dalam kelompok menggunakan SAVEPOINT
    if ($mhsC) {
        $pdo->exec("SAVEPOINT sp_non_class_member");
        try {
            $memberStmt->execute(['gid' => $team2_id, 'uid' => $mhsC['id'], 'role' => 'anggota']);
            test_assert(false, "Pencegahan mahasiswa non-kelas masuk kelompok", "Harusnya ditolak trigger");
            $pdo->exec("RELEASE SAVEPOINT sp_non_class_member");
        } catch (PDOException $e) {
            $pdo->exec("ROLLBACK TO SAVEPOINT sp_non_class_member");
            $msg = $e->getMessage();
            $isBlocked = str_contains($msg, 'Mahasiswa harus bergabung ke kelas') || str_contains($msg, 'fairshare_validate_group_member');
            test_assert($isBlocked, "Trigger database menolak mahasiswa yang belum menjadi anggota kelas");
        }
    }

    // ------------------------------------------------------------
    // SKENARIO E: PENGATURAN PROJECT MANAGER OLEH DOSEN
    // ------------------------------------------------------------
    echo "\n--- SKENARIO E: PERGANTIAN PROJECT MANAGER (TRANSAKSI ATOMIK) ---\n";

    $demote = $pdo->prepare("UPDATE public.group_members SET role_in_group = 'anggota' WHERE group_id = CAST(:gid AS uuid) AND role_in_group = 'ketua'");
    $demote->execute(['gid' => $team1_id]);

    $promote = $pdo->prepare("UPDATE public.group_members SET role_in_group = 'ketua' WHERE group_id = CAST(:gid AS uuid) AND user_id = CAST(:uid AS uuid)");
    $promote->execute(['gid' => $team1_id, 'uid' => $mhsB['id']]);

    $membersQ = $pdo->prepare("SELECT user_id::text AS uid, role_in_group FROM public.group_members WHERE group_id = CAST(:gid AS uuid)");
    $membersQ->execute(['gid' => $team1_id]);
    $rolesMap = $membersQ->fetchAll(PDO::FETCH_KEY_PAIR);

    $aRole = $rolesMap[$mhsA['id']] ?? '';
    $bRole = $rolesMap[$mhsB['id']] ?? '';

    test_assert($aRole === 'anggota', "Ketua lama (Mhs A) berhasil beralih menjadi 'anggota'");
    test_assert($bRole === 'ketua', "Ketua baru (Mhs B) berhasil dipromosikan menjadi 'ketua' (PM)");

    $ketuaCount = (int) $pdo->query("SELECT COUNT(*) FROM public.group_members WHERE group_id = CAST('$team1_id' AS uuid) AND role_in_group = 'ketua'")->fetchColumn();
    test_assert($ketuaCount === 1, "Tepat 1 Project Manager dalam kelompok setelah pergantian");

    // ------------------------------------------------------------
    // SKENARIO F: PEMBUATAN TUGAS OLEH DOSEN (SEMUA VS TERTENTU)
    // ------------------------------------------------------------
    echo "\n--- SKENARIO F: PEMBUATAN TUGAS WBS PER KELOMPOK ---\n";

    $taskStmt = $pdo->prepare("
        INSERT INTO public.tasks (id, group_id, title, description, weight, status, target_week)
        VALUES (gen_random_uuid(), CAST(:gid AS uuid), 'Tugas Bersama: Desain DB', 'Instruksi tugas', 3, 'todo', 2)
    ");
    foreach ([$team1_id, $team2_id] as $tId) {
        $taskStmt->execute(['gid' => $tId]);
    }
    test_assert(true, "Tugas bersama berhasil dibuat terpisah untuk Kelompok 1 dan Kelompok 2");

    $taskSingle = $pdo->prepare("
        INSERT INTO public.tasks (id, group_id, title, description, weight, status, target_week)
        VALUES (gen_random_uuid(), CAST(:gid AS uuid), 'Tugas Khusus Kelompok 1', 'Instruksi khusus', 2, 'in_progress', 3)
    ");
    $taskSingle->execute(['gid' => $team1_id]);
    test_assert(true, "Tugas khusus kelompok berhasil ditambahkan hanya ke Kelompok 1");

    $t1Tasks = array_values(array_filter(all_tasks(), fn($t) => ($t['group_id'] ?? '') === $team1_id));
    $t2Tasks = array_values(array_filter(all_tasks(), fn($t) => ($t['group_id'] ?? '') === $team2_id));
    test_assert(count($t1Tasks) === 2 && count($t2Tasks) === 1, "Progres tugas terisolasi secara terpisah per kelompok (Kelompok 1: 2 tugas, Kelompok 2: 1 tugas)");

    // ------------------------------------------------------------
    // SKENARIO G: MONITORING KELAS & ISOLASI DATA
    // ------------------------------------------------------------
    echo "\n--- SKENARIO G: MONITORING KELAS & ISOLASI DATA ---\n";

    $pboTasks = class_tasks($classPBO_id);
    test_assert(count($pboTasks) === 3, "Monitoring kelas PBO mencatat tepat 3 tugas dari seluruh kelompoknya");

    $pboMembers = class_members($classPBO_id);
    test_assert(count($pboMembers) === 2, "Monitoring mahasiswa kelas PBO mencatat tepat 2 mahasiswa");

    $uiuxTasks = class_tasks($classUIUX_id);
    test_assert(count($uiuxTasks) === 0, "Kelas UI/UX tidak tercampur dengan tugas kelas PBO (0 tugas)");

    // ------------------------------------------------------------
    // SKENARIO H: KEAMANAN & VALIDASI
    // ------------------------------------------------------------
    echo "\n--- SKENARIO H: KEAMANAN & VALIDASI INPUT ---\n";

    test_assert(!valid_uuid("not-a-uuid"), "valid_uuid() menolak string acak");
    test_assert(!valid_uuid(""), "valid_uuid() menolak string kosong");
    test_assert(valid_uuid("5de3c46c-afea-4bd2-a8b1-99a874fd174e"), "valid_uuid() menerima UUID valid");

    // Kode kelas tidak boleh dipakai sebagai kode kelompok
    $wrongTypeFind = $pdo->prepare("SELECT 1 FROM public.groups WHERE invite_code = :code AND group_type = 'team'");
    $wrongTypeFind->execute(['code' => $codePBO]);
    test_assert(!$wrongTypeFind->fetch(), "Kode kelas ($codePBO) tidak dapat digunakan untuk join kelompok");

    // Kode kelompok tidak boleh dipakai sebagai kode kelas
    $wrongClassFind = $pdo->prepare("SELECT 1 FROM public.groups WHERE invite_code = :code AND group_type = 'class'");
    $wrongClassFind->execute(['code' => $codeTeam1]);
    test_assert(!$wrongClassFind->fetch(), "Kode kelompok ($codeTeam1) tidak dapat digunakan untuk join kelas");

    // Rollback seluruh data uji agar database bersih
    $pdo->rollBack();
    test_assert(true, "Rollback transaksi pengujian berhasil (Database tetap bersih tanpa data sampah)");

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "ERROR Exception: " . $e->getMessage() . "\n";
    $fail++;
}

echo "\n============================================================\n";
echo "HASIL AKHIR: $pass LULUS | $fail GAGAL\n";
echo "============================================================\n";

exit($fail > 0 ? 1 : 0);
