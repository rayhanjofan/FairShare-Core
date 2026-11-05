<?php
declare(strict_types=1);
require_once __DIR__ . '/google_service.php';
$user = require_login();

try {
    $config = fs_google_required_config();
    $state = bin2hex(random_bytes(32));
    $codeVerifier = rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '=');
    $codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
    $_SESSION['google_oauth_state'] = $state;
    $_SESSION['google_oauth_code_verifier'] = $codeVerifier;
    $_SESSION['google_oauth_started_at'] = time();
    $_SESSION['google_oauth_user_id'] = (string) $user['id'];
    $params = [
        'client_id' => $config['client_id'],
        'redirect_uri' => $config['redirect_uri'],
        'response_type' => 'code',
        'scope' => implode(' ', [
            'openid',
            'https://www.googleapis.com/auth/userinfo.email',
            'https://www.googleapis.com/auth/userinfo.profile',
            'https://www.googleapis.com/auth/drive.activity.readonly',
            'https://www.googleapis.com/auth/drive.metadata.readonly',
        ]),
        'access_type' => 'offline',
        'prompt' => 'consent',
        'include_granted_scopes' => 'true',
        'state' => $state,
        'code_challenge' => $codeChallenge,
        'code_challenge_method' => 'S256',
    ];
    redirect('https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986));
} catch (Throwable $e) {
    error_log('FairShare Google connect: ' . $e->getMessage());
    flash('Integrasi Google belum siap. Periksa konfigurasi Google Cloud dan data/.env.', 'error');
    redirect('../../integrations.php');
}
