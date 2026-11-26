<?php
declare(strict_types=1);

/**
 * Test Suite: Dynamic API Integration Indicator on audit.php
 *
 * Menguji seluruh 8 skenario yang disyaratkan:
 * 1. Mahasiswa Rayhan dengan Google OAuth terhubung: tampil 1 sumber Google.
 * 2. Mahasiswa Ahmad dengan Google OAuth terhubung: tampil 1 sumber Google.
 * 3. Mahasiswa yang belum menghubungkan API: tampil 0 sumber.
 * 4. Dosen pemilik kelas PBL dengan dua mahasiswa terhubung Google: tampil 1 jenis sumber API dan 2 akun terhubung.
 * 5. Dosen yang tidak mempunyai mahasiswa dengan integrasi: tampil 0 sumber.
 * 6. Integrasi dari kelas milik dosen lain tidak boleh ikut terhitung.
 * 7. Token yang sudah kedaluwarsa tanpa refresh token tidak boleh dianggap aktif.
 * 8. Token kedaluwarsa dengan refresh token yang tersedia dapat ditampilkan sebagai terhubung/dapat diperbarui.
 */

if (!defined('FS_ROOT')) {
    define('FS_ROOT', dirname(__DIR__));
}

require_once FS_ROOT . '/includes/bootstrap.php';
require_once FS_ROOT . '/integrations/google/google_service.php';

$testsPassed = 0;
$testsFailed = 0;

function assert_indicator_test(string $name, bool $condition, string $detail = ''): void
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
echo "FAIRSHARE DYNAMIC API INTEGRATION INDICATOR TEST SUITE\n";
echo "====================================================================\n\n";

$pdo = require_db();

// =========================================================================
// SKENARIO 7: Token kedaluwarsa tanpa refresh token TIDAK boleh dianggap aktif
// =========================================================================
echo "--- Skenario 7: Token kedaluwarsa tanpa refresh token ---\n";
$now = 1750000000;
$integExpiredNoRefresh = [
    'user_id' => 'c820f70f-cfe1-4baa-b085-c754e4cc8c2a',
    'provider' => 'google',
    'access_token_encrypted' => 'enc_access_token',
    'refresh_token_encrypted' => '',
    'token_expires_at' => gmdate('Y-m-d H:i:s', $now - 300),
    'scopes' => 'https://www.googleapis.com/auth/drive.activity.readonly',
];
$isActive7 = fs_is_integration_active($integExpiredNoRefresh, $now);
assert_indicator_test('Skenario 7: Token kedaluwarsa tanpa refresh token menghasilkan false', $isActive7 === false, 'Hasil: ' . var_export($isActive7, true));

$integNullRefresh = [
    'user_id' => 'c820f70f-cfe1-4baa-b085-c754e4cc8c2a',
    'provider' => 'google',
    'access_token_encrypted' => 'enc_access_token',
    'refresh_token_encrypted' => null,
    'token_expires_at' => gmdate('Y-m-d H:i:s', $now - 300),
    'scopes' => 'https://www.googleapis.com/auth/drive.activity.readonly',
];
$isActive7b = fs_is_integration_active($integNullRefresh, $now);
assert_indicator_test('Skenario 7: Token kedaluwarsa dengan refresh token null menghasilkan false', $isActive7b === false, 'Hasil: ' . var_export($isActive7b, true));


// =========================================================================
// SKENARIO 8: Token kedaluwarsa dengan refresh token tetap aktif / dapat diperbarui
// =========================================================================
echo "\n--- Skenario 8: Token kedaluwarsa dengan refresh token ---\n";
$integExpiredWithRefresh = [
    'user_id' => 'c820f70f-cfe1-4baa-b085-c754e4cc8c2a',
    'provider' => 'google',
    'access_token_encrypted' => 'enc_access_token',
    'refresh_token_encrypted' => 'enc_refresh_token',
    'token_expires_at' => gmdate('Y-m-d H:i:s', $now - 300),
    'scopes' => 'https://www.googleapis.com/auth/drive.activity.readonly',
];
$isActive8 = fs_is_integration_active($integExpiredWithRefresh, $now);
assert_indicator_test('Skenario 8: Token kedaluwarsa dengan refresh token menghasilkan true (renewable)', $isActive8 === true, 'Hasil: ' . var_export($isActive8, true));


// =========================================================================
// Validasi Tambahan: Scope, Token Kosong, dan GitHub
// =========================================================================
echo "\n--- Validasi Tambahan: Scope, Token Kosong, dan GitHub ---\n";
$integNoScope = [
    'provider' => 'google',
    'access_token_encrypted' => 'enc_access_token',
    'refresh_token_encrypted' => 'enc_refresh_token',
    'token_expires_at' => gmdate('Y-m-d H:i:s', $now + 3600),
    'scopes' => 'https://www.googleapis.com/auth/userinfo.email',
];
assert_indicator_test('Google tanpa drive.activity scope menghasilkan false', fs_is_integration_active($integNoScope, $now) === false);

$integNoToken = [
    'provider' => 'google',
    'access_token_encrypted' => '',
    'refresh_token_encrypted' => 'enc_refresh_token',
    'token_expires_at' => gmdate('Y-m-d H:i:s', $now + 3600),
    'scopes' => 'https://www.googleapis.com/auth/drive.activity.readonly',
];
assert_indicator_test('Access token kosong menghasilkan false', fs_is_integration_active($integNoToken, $now) === false);

$integGithub = [
    'provider' => 'github',
    'access_token_encrypted' => 'gh_token',
    'refresh_token_encrypted' => '',
    'token_expires_at' => '',
    'scopes' => 'repo',
];
assert_indicator_test('GitHub OAuth belum diimplementasikan -> menghasilkan false', fs_is_integration_active($integGithub, $now) === false);


// =========================================================================
// SKENARIO 1: Mahasiswa Rayhan dengan Google OAuth terhubung
// =========================================================================
echo "\n--- Skenario 1: Mahasiswa Rayhan dengan Google OAuth ---\n";
$rayhanLive = $pdo->query("SELECT * FROM public.users WHERE id = 'c820f70f-cfe1-4baa-b085-c754e4cc8c2a'")->fetch(PDO::FETCH_ASSOC);
$summaryRayhan = fs_get_api_integrations_summary($rayhanLive, $pdo);
assert_indicator_test('Skenario 1: active_provider_count === 1', $summaryRayhan['active_provider_count'] === 1, 'Aktual: ' . $summaryRayhan['active_provider_count']);
assert_indicator_test('Skenario 1: provider adalah google', in_array('google', $summaryRayhan['providers'], true));
assert_indicator_test('Skenario 1: stat_number_html === "1<small> aktif</small>"', $summaryRayhan['stat_number_html'] === '1<small> aktif</small>');
assert_indicator_test('Skenario 1: foot_text === "Google Drive Activity API terhubung."', $summaryRayhan['foot_text'] === 'Google Drive Activity API terhubung.');
assert_indicator_test('Skenario 1: card_tooltip sesuai konteks mahasiswa', $summaryRayhan['card_tooltip'] === 'Integrasi API yang terhubung pada akun Anda.');


// =========================================================================
// SKENARIO 2: Mahasiswa Ahmad dengan Google OAuth terhubung
// =========================================================================
echo "\n--- Skenario 2: Mahasiswa Ahmad dengan Google OAuth ---\n";
$ahmadLive = $pdo->query("SELECT * FROM public.users WHERE id = 'b559c9e3-fb1c-4b39-bfc0-dba145e50a94'")->fetch(PDO::FETCH_ASSOC);
$summaryAhmad = fs_get_api_integrations_summary($ahmadLive, $pdo);
assert_indicator_test('Skenario 2: active_provider_count === 1', $summaryAhmad['active_provider_count'] === 1, 'Aktual: ' . $summaryAhmad['active_provider_count']);
assert_indicator_test('Skenario 2: provider adalah google', in_array('google', $summaryAhmad['providers'], true));
assert_indicator_test('Skenario 2: stat_number_html === "1<small> aktif</small>"', $summaryAhmad['stat_number_html'] === '1<small> aktif</small>');
assert_indicator_test('Skenario 2: foot_text === "Google Drive Activity API terhubung."', $summaryAhmad['foot_text'] === 'Google Drive Activity API terhubung.');


// =========================================================================
// SKENARIO 3: Mahasiswa yang belum menghubungkan API (Ihsan)
// =========================================================================
echo "\n--- Skenario 3: Mahasiswa yang belum menghubungkan API ---\n";
$ihsanLive = $pdo->query("SELECT * FROM public.users WHERE id = '20532a8f-4c59-429d-8e25-dbfb496b3090'")->fetch(PDO::FETCH_ASSOC);
$summaryIhsan = fs_get_api_integrations_summary($ihsanLive, $pdo);
assert_indicator_test('Skenario 3: active_provider_count === 0', $summaryIhsan['active_provider_count'] === 0, 'Aktual: ' . $summaryIhsan['active_provider_count']);
assert_indicator_test('Skenario 3: connected_account_count === 0', $summaryIhsan['connected_account_count'] === 0);
assert_indicator_test('Skenario 3: stat_number_html === "0<small> aktif</small>"', $summaryIhsan['stat_number_html'] === '0<small> aktif</small>');
assert_indicator_test('Skenario 3: foot_text === "Belum ada sumber API yang terhubung."', $summaryIhsan['foot_text'] === 'Belum ada sumber API yang terhubung.');


// =========================================================================
// SKENARIO 4, 5, 6: Pengujian Dosen Kelas & Isolasi Antar-Dosen
// Menggunakan PostgreSQL isolated transaction rollback agar 100% aman
// =========================================================================
echo "\n--- Skenario 4, 5, 6: Pengujian Dosen & Isolasi Kelas ---\n";

$pdo->beginTransaction();
try {
    // Dosen A: Pemilik Kelas PBL Uji dengan 2 mahasiswa terhubung (Rayhan & Ahmad)
    $dosenAId = 'a1111111-1111-4111-a111-111111111111';
    // Dosen B: Dosen lain pemilik Kelas B dengan 1 mahasiswa terhubung (Elsa)
    $dosenBId = 'b2222222-2222-4222-a222-222222222222';
    // Dosen C: Dosen tanpa kelas / mahasiswa terhubung
    $dosenCId = 'c3333333-3333-4333-a333-333333333333';

    $insUser = $pdo->prepare("INSERT INTO public.users (id, nim_nip, name, email, password_hash, role) VALUES (CAST(:id AS uuid), :nip, :name, :email, 'hash', 'dosen')");
    $insUser->execute(['id' => $dosenAId, 'nip' => '19800101001', 'name' => 'Dosen A (PBL)', 'email' => 'dosenA@test.local']);
    $insUser->execute(['id' => $dosenBId, 'nip' => '19800101002', 'name' => 'Dosen B (Lain)', 'email' => 'dosenB@test.local']);
    $insUser->execute(['id' => $dosenCId, 'nip' => '19800101003', 'name' => 'Dosen C (Kosong)', 'email' => 'dosenC@test.local']);

    $insClass = $pdo->prepare("INSERT INTO public.groups (id, name, group_type, created_by, invite_code) VALUES (CAST(:id AS uuid), :name, 'class', CAST(:cb AS uuid), :inv)");
    $classAId = 'ca111111-1111-4111-a111-111111111111';
    $classBId = 'cb222222-2222-4222-a222-222222222222';
    $insClass->execute(['id' => $classAId, 'name' => 'Kelas PBL Uji Dosen A', 'cb' => $dosenAId, 'inv' => 'INV-TEST-A4']);
    $insClass->execute(['id' => $classBId, 'name' => 'Kelas Uji Dosen B', 'cb' => $dosenBId, 'inv' => 'INV-TEST-B6']);

    $insGm = $pdo->prepare("INSERT INTO public.group_members (group_id, user_id, role_in_group) VALUES (CAST(:gid AS uuid), CAST(:uid AS uuid), 'anggota')");
    // Kelas Dosen A: Rayhan (Google) & Ahmad (Google) -> total 2 akun terhubung
    $insGm->execute(['gid' => $classAId, 'uid' => 'c820f70f-cfe1-4baa-b085-c754e4cc8c2a']);
    $insGm->execute(['gid' => $classAId, 'uid' => 'b559c9e3-fb1c-4b39-bfc0-dba145e50a94']);

    // Kelas Dosen B: Elsa (Google) -> 1 akun terhubung
    $insGm->execute(['gid' => $classBId, 'uid' => '06b871e0-fd77-4d93-9cf3-4dbab6d8f9aa']);

    // Skenario 4: Dosen A (PBL) dengan 2 mahasiswa terhubung Google
    $userDosenA = ['id' => $dosenAId, 'role' => 'dosen', 'name' => 'Dosen A (PBL)'];
    $summary4 = fs_get_api_integrations_summary($userDosenA, $pdo);

    assert_indicator_test('Skenario 4: Dosen PBL active_provider_count === 1', $summary4['active_provider_count'] === 1, 'Aktual: ' . $summary4['active_provider_count']);
    assert_indicator_test('Skenario 4: Dosen PBL connected_account_count === 2', $summary4['connected_account_count'] === 2, 'Aktual: ' . $summary4['connected_account_count']);
    assert_indicator_test('Skenario 4: Dosen PBL stat_number_html === "1<small> aktif</small>"', $summary4['stat_number_html'] === '1<small> aktif</small>');
    assert_indicator_test('Skenario 4: Dosen PBL foot_text === "Google Drive Activity API terhubung (2 akun terhubung)."', $summary4['foot_text'] === 'Google Drive Activity API terhubung (2 akun terhubung).', 'Aktual: ' . $summary4['foot_text']);
    assert_indicator_test('Skenario 4: card_tooltip sesuai konteks dosen', $summary4['card_tooltip'] === 'Integrasi dari mahasiswa pada kelas yang Anda kelola.');

    // Skenario 5: Dosen C yang tidak mempunyai mahasiswa dengan integrasi
    $userDosenC = ['id' => $dosenCId, 'role' => 'dosen', 'name' => 'Dosen C (Kosong)'];
    $summary5 = fs_get_api_integrations_summary($userDosenC, $pdo);

    assert_indicator_test('Skenario 5: Dosen tanpa mahasiswa active_provider_count === 0', $summary5['active_provider_count'] === 0, 'Aktual: ' . $summary5['active_provider_count']);
    assert_indicator_test('Skenario 5: Dosen tanpa mahasiswa connected_account_count === 0', $summary5['connected_account_count'] === 0);
    assert_indicator_test('Skenario 5: Dosen tanpa mahasiswa stat_number_html === "0<small> aktif</small>"', $summary5['stat_number_html'] === '0<small> aktif</small>');
    assert_indicator_test('Skenario 5: Dosen tanpa mahasiswa foot_text === "Belum ada sumber API yang terhubung."', $summary5['foot_text'] === 'Belum ada sumber API yang terhubung.');

    // Skenario 6: Integrasi kelas milik dosen lain tidak boleh ikut terhitung
    $userDosenB = ['id' => $dosenBId, 'role' => 'dosen', 'name' => 'Dosen B (Lain)'];
    $summary6B = fs_get_api_integrations_summary($userDosenB, $pdo);

    assert_indicator_test('Skenario 6: Dosen B hanya melihat mahasiswa kelasnya sendiri (1 akun Elsa)', $summary6B['connected_account_count'] === 1, 'Aktual: ' . $summary6B['connected_account_count']);
    assert_indicator_test('Skenario 6: Dosen B foot_text mencatat 1 akun terhubung', $summary6B['foot_text'] === 'Google Drive Activity API terhubung (1 akun terhubung).');
    assert_indicator_test('Skenario 6: Dosen A tidak ketambahan akun Elsa milik Dosen B (tetap 2 akun)', $summary4['connected_account_count'] === 2);

} finally {
    $pdo->rollBack();
    echo " [INFO] Isolasi pengujian PostgreSQL berhasil di-rollback tanpa mengubah data.\n";
}

// Live check: Dosen Riyan yang sedang ada di DB
echo "\n--- Verifikasi Dosen Riyan (Live DB) ---\n";
$riyanLive = $pdo->query("SELECT * FROM public.users WHERE id = '961092aa-f108-4f85-916c-b54a17641d68'")->fetch(PDO::FETCH_ASSOC);
$summaryRiyan = fs_get_api_integrations_summary($riyanLive, $pdo);
assert_indicator_test('Live DB: Dosen Riyan memiliki 1 provider Google aktif', $summaryRiyan['active_provider_count'] === 1);
assert_indicator_test('Live DB: Dosen Riyan mendeteksi akun mahasiswa kelasnya (3 akun: Elsa, Rayhan, Ahmad)', $summaryRiyan['connected_account_count'] === 3, 'Aktual: ' . $summaryRiyan['connected_account_count']);
assert_indicator_test('Live DB: Dosen Riyan foot_text mencatat 3 akun terhubung', $summaryRiyan['foot_text'] === 'Google Drive Activity API terhubung (3 akun terhubung).');

echo "\n====================================================================\n";
echo "HASIL AKHIR: $testsPassed PASSED, $testsFailed FAILED\n";
echo "====================================================================\n";

if ($testsFailed > 0) {
    exit(1);
}
