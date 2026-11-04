<?php
declare(strict_types=1);
require_once __DIR__ . '/google_service.php';
$user = require_login();

$expectedState = (string) ($_SESSION['google_oauth_state'] ?? '');
$state = (string) ($_GET['state'] ?? '');
$startedAt = (int) ($_SESSION['google_oauth_started_at'] ?? 0);
$boundUserId = (string) ($_SESSION['google_oauth_user_id'] ?? '');
$codeVerifier = (string) ($_SESSION['google_oauth_code_verifier'] ?? '');
unset($_SESSION['google_oauth_state'], $_SESSION['google_oauth_code_verifier'], $_SESSION['google_oauth_started_at'], $_SESSION['google_oauth_user_id']);

if ($expectedState === '' || $state === '' || !hash_equals($expectedState, $state)
    || $startedAt < time() - 900 || strtolower($boundUserId) !== strtolower((string) $user['id'])) {
    flash('Validasi OAuth Google gagal atau sesi telah kedaluwarsa. Coba hubungkan kembali.', 'error');
    redirect('../../integrations.php');
}

if (!empty($_GET['error'])) {
    flash('Persetujuan akun Google dibatalkan atau ditolak.', 'error');
    redirect('../../integrations.php');
}

try {
    $code = (string) ($_GET['code'] ?? '');
    if ($code === '') {
        throw new RuntimeException('Authorization code tidak diterima dari Google.');
    }
    if ($codeVerifier === '') {
        throw new RuntimeException('PKCE verifier OAuth tidak tersedia. Ulangi proses koneksi Google.');
    }
    $token = fs_google_exchange_code($code, $codeVerifier);
    $accessToken = (string) ($token['access_token'] ?? '');
    if ($accessToken === '') {
        throw new RuntimeException('Access token tidak diterima dari Google.');
    }
    $userinfo = fs_google_fetch_userinfo($accessToken);
    fs_google_store_tokens((string) $user['id'], $userinfo, $token);
    log_activity('Google terhubung', (string) ($userinfo['email'] ?? 'Akun Google'), 'success');
    flash('Akun Google berhasil dihubungkan. Pastikan dokumen tugas dibagikan kepada email Google tersebut.');
    redirect('../../integrations.php');
} catch (Throwable $e) {
    error_log('FairShare Google OAuth callback: ' . $e->getMessage());
    flash('Akun Google belum berhasil dihubungkan. Periksa konfigurasi OAuth, scope, dan izin aplikasi Google.', 'error');
    redirect('../../integrations.php');
}
