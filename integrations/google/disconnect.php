<?php
declare(strict_types=1);

require_once __DIR__ . '/google_service.php';
$user = require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed.');
}
verify_csrf();

$integration = fs_google_integration((string) $user['id']);
if ($integration) {
    // Attempt best-effort revocation with Google so permissions are clean on Google side
    try {
        $refreshEnc = (string) ($integration['refresh_token_encrypted'] ?? '');
        $accessEnc = (string) ($integration['access_token_encrypted'] ?? '');
        $tokenToRevoke = '';
        if ($refreshEnc !== '') {
            $tokenToRevoke = fs_decrypt_secret($refreshEnc);
        } elseif ($accessEnc !== '') {
            $tokenToRevoke = fs_decrypt_secret($accessEnc);
        }
        if ($tokenToRevoke !== '') {
            fs_google_revoke_token($tokenToRevoke);
        }
    } catch (Throwable $e) {
        // Silently continue if decryption or revoke fails; local row will still be removed
        error_log('FairShare Google revoke on disconnect: ' . $e->getMessage());
    }

    $stmt = require_db()->prepare("DELETE FROM public.user_integrations WHERE user_id = CAST(:user_id AS UUID) AND provider = 'google'");
    $stmt->execute(['user_id' => $user['id']]);
    log_activity('Google diputus', (string) ($integration['provider_email'] ?? 'Akun Google'), 'info');
}

flash('Koneksi akun Google pada FairShare telah diputus.');
redirect('../../integrations.php');
