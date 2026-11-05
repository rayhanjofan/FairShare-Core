<?php
declare(strict_types=1);

require_once __DIR__ . '/google_service.php';
$user = require_login();

$expectedState = (string) ($_SESSION['google_oauth_state'] ?? '');
$state = (string) ($_GET['state'] ?? '');
$startedAt = (int) ($_SESSION['google_oauth_started_at'] ?? 0);
$boundUserId = (string) ($_SESSION['google_oauth_user_id'] ?? '');
$codeVerifier = (string) ($_SESSION['google_oauth_code_verifier'] ?? '');

// Clear single-use OAuth session state
unset(
    $_SESSION['google_oauth_state'],
    $_SESSION['google_oauth_code_verifier'],
    $_SESSION['google_oauth_started_at'],
    $_SESSION['google_oauth_user_id']
);

// Validate OAuth state, session timeout (15 mins), and user binding
if ($expectedState === '' || $state === '' || !hash_equals($expectedState, $state)
    || $startedAt < (time() - 900)
    || strtolower($boundUserId) !== strtolower((string) $user['id'])) {
    flash('Validasi keamanan OAuth Google (state) gagal atau sesi koneksi telah kedaluwarsa. Silakan ulangi dari tombol Hubungkan Akun.', 'error');
    redirect('../../integrations.php');
}

// Handle errors returned directly by Google OAuth endpoint
if (!empty($_GET['error'])) {
    $error = trim((string) ($_GET['error'] ?? ''));
    $errorDesc = trim((string) ($_GET['error_description'] ?? ''));
    $errorSubtype = trim((string) ($_GET['error_subtype'] ?? ''));

    // Safe logging without credentials
    error_log(sprintf('FairShare Google OAuth callback error received: %s - %s (subtype: %s)', $error, $errorDesc, $errorSubtype));

    if ($error === 'access_denied') {
        $message = 'Persetujuan Google ditolak (access_denied). '
            . 'Jika Anda tidak membatalkannya sendiri, pastikan email Google Anda telah didaftarkan ke daftar Test Users di Google Cloud Console '
            . 'dan izin Google Drive Activity telah disetujui.';
    } else {
        $message = 'Google OAuth mengembalikan error (' . e($error) . '): ' . e($errorDesc !== '' ? $errorDesc : 'Proses otorisasi dibatalkan.');
    }

    flash($message, 'error');
    redirect('../../integrations.php');
}

try {
    $code = trim((string) ($_GET['code'] ?? ''));
    if ($code === '') {
        throw new RuntimeException('Authorization code tidak diterima dari Google.');
    }
    if ($codeVerifier === '') {
        throw new RuntimeException('PKCE code verifier tidak ditemukan di sesi browser. Silakan ulangi koneksi.');
    }

    // Exchange authorization code for tokens
    $token = fs_google_exchange_code($code, $codeVerifier);
    $accessToken = (string) ($token['access_token'] ?? '');
    if ($accessToken === '') {
        throw new RuntimeException('Google tidak mengembalikan access token yang valid.');
    }

    // Fetch user identity using the verified access token
    $userinfo = fs_google_fetch_userinfo($accessToken);
    $storeResult = fs_google_store_tokens((string) $user['id'], $userinfo, $token);

    log_activity('Google terhubung', (string) ($storeResult['email'] ?? 'Akun Google'), 'success');

    if ($storeResult['has_activity_scope']) {
        flash('Akun Google (' . $storeResult['email'] . ') berhasil dihubungkan dengan izin audit Google Drive lengkap.');
    } else {
        flash('Akun Google (' . $storeResult['email'] . ') terhubung untuk identitas dasar, namun izin Google Drive Activity belum diberikan. Fitur audit dokumen belum dapat membaca riwayat aktivitas.', 'warning');
    }

    redirect('../../integrations.php');
} catch (Throwable $e) {
    // Log technical detail to error_log without printing secrets
    error_log('FairShare Google OAuth callback exception: ' . $e->getMessage());

    // Show actionable message to the user
    flash($e->getMessage(), 'error');
    redirect('../../integrations.php');
}
