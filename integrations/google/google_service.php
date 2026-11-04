<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

/** Small HTTPS JSON client based on PHP streams (no Composer package required). */
function fs_google_http_json(string $method, string $url, array $headers = [], ?string $body = null): array
{
    if (!str_starts_with($url, 'https://')) {
        throw new RuntimeException('Permintaan API harus menggunakan HTTPS.');
    }
    $headerLines = [];
    foreach ($headers as $name => $value) {
        $headerLines[] = is_int($name) ? (string) $value : $name . ': ' . $value;
    }
    $options = [
        'http' => [
            'method' => strtoupper($method),
            'header' => implode("\r\n", $headerLines),
            'ignore_errors' => true,
            'timeout' => 25,
            'follow_location' => 0,
            'max_redirects' => 0,
        ],
    ];
    if ($body !== null) {
        $options['http']['content'] = $body;
    }
    $context = stream_context_create($options);
    $raw = @file_get_contents($url, false, $context);
    $status = 0;
    foreach (($http_response_header ?? []) as $line) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $line, $match)) {
            $status = (int) $match[1];
        }
    }
    if ($raw === false) {
        throw new RuntimeException('Tidak dapat menghubungi layanan eksternal. Periksa koneksi internet dan konfigurasi PHP allow_url_fopen.');
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new RuntimeException('Layanan eksternal mengembalikan respons yang tidak valid.');
    }
    if ($status < 200 || $status >= 300) {
        $message = (string) ($data['error_description'] ?? $data['error']['message'] ?? $data['error'] ?? 'Permintaan ditolak.');
        throw new RuntimeException('Google API HTTP ' . $status . ': ' . mb_substr($message, 0, 300));
    }
    return $data;
}

function fs_google_configured(): bool
{
    return app_env('GOOGLE_CLIENT_ID') !== ''
        && app_env('GOOGLE_CLIENT_SECRET') !== ''
        && app_env('GOOGLE_REDIRECT_URI') !== ''
        && strlen(app_env('APP_ENCRYPTION_KEY')) >= 32;
}

function fs_google_required_config(): array
{
    $config = [
        'client_id' => app_env('GOOGLE_CLIENT_ID'),
        'client_secret' => app_env('GOOGLE_CLIENT_SECRET'),
        'redirect_uri' => app_env('GOOGLE_REDIRECT_URI'),
    ];
    if (in_array('', $config, true) || strlen(app_env('APP_ENCRYPTION_KEY')) < 32) {
        throw new RuntimeException('Konfigurasi Google belum lengkap. Isi GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET, GOOGLE_REDIRECT_URI, dan APP_ENCRYPTION_KEY pada data/.env.');
    }
    $redirect = parse_url($config['redirect_uri']);
    if (!$redirect || !in_array(strtolower((string) ($redirect['scheme'] ?? '')), ['https', 'http'], true)) {
        throw new RuntimeException('GOOGLE_REDIRECT_URI tidak valid.');
    }
    if (($redirect['scheme'] ?? '') === 'http' && !in_array($redirect['host'] ?? '', ['localhost', '127.0.0.1'], true)) {
        throw new RuntimeException('HTTP hanya diizinkan untuk localhost. Gunakan HTTPS untuk server publik.');
    }
    return $config;
}

function fs_google_encryption_key(): string
{
    $secret = app_env('APP_ENCRYPTION_KEY');
    if (strlen($secret) < 32) {
        throw new RuntimeException('APP_ENCRYPTION_KEY belum diatur atau terlalu pendek.');
    }
    return hash('sha256', $secret, true);
}

function fs_encrypt_secret(string $plainText): string
{
    $nonce = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plainText, 'aes-256-gcm', fs_google_encryption_key(), OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
    if ($cipher === false) {
        throw new RuntimeException('Gagal mengenkripsi credential integrasi.');
    }
    return base64_encode($nonce . $tag . $cipher);
}

function fs_decrypt_secret(string $encoded): string
{
    $raw = base64_decode($encoded, true);
    if ($raw === false || strlen($raw) < 29) {
        throw new RuntimeException('Credential integrasi tersimpan dalam format yang tidak valid. Hubungkan ulang akun Google.');
    }
    $nonce = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $cipher = substr($raw, 28);
    $plain = openssl_decrypt($cipher, 'aes-256-gcm', fs_google_encryption_key(), OPENSSL_RAW_DATA, $nonce, $tag, '');
    if ($plain === false) {
        throw new RuntimeException('Credential tidak dapat dibuka. Periksa APP_ENCRYPTION_KEY atau hubungkan ulang akun Google.');
    }
    return $plain;
}

function fs_google_integration(string $userId): ?array
{
    if (!valid_uuid($userId)) {
        return null;
    }
    $stmt = require_db()->prepare("SELECT id::text AS id, user_id::text AS user_id, provider_user_id, provider_email, access_token_encrypted, refresh_token_encrypted, token_expires_at::text AS token_expires_at, scopes, created_at::text AS created_at, updated_at::text AS updated_at FROM public.user_integrations WHERE user_id = CAST(:user_id AS UUID) AND provider = 'google' LIMIT 1");
    $stmt->execute(['user_id' => $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function fs_google_exchange_code(string $code, string $codeVerifier = ''): array
{
    $config = fs_google_required_config();
    return fs_google_http_json('POST', 'https://oauth2.googleapis.com/token', [
        'Content-Type' => 'application/x-www-form-urlencoded',
        'Accept' => 'application/json',
    ], http_build_query([
        'code' => $code,
        'client_id' => $config['client_id'],
        'client_secret' => $config['client_secret'],
        'redirect_uri' => $config['redirect_uri'],
        'grant_type' => 'authorization_code',
        'code_verifier' => $codeVerifier,
    ], '', '&', PHP_QUERY_RFC3986));
}

function fs_google_fetch_userinfo(string $accessToken): array
{
    return fs_google_http_json('GET', 'https://openidconnect.googleapis.com/v1/userinfo', [
        'Authorization' => 'Bearer ' . $accessToken,
        'Accept' => 'application/json',
    ]);
}

function fs_google_store_tokens(string $userId, array $userinfo, array $token): void
{
    $sub = trim((string) ($userinfo['sub'] ?? ''));
    $email = strtolower(trim((string) ($userinfo['email'] ?? '')));
    $access = (string) ($token['access_token'] ?? '');
    if ($sub === '' || $email === '' || $access === '') {
        throw new RuntimeException('Google tidak mengembalikan identitas akun atau access token yang lengkap.');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !in_array($userinfo['email_verified'] ?? false, [true, 'true', 1, '1'], true)) {
        throw new RuntimeException('Akun Google harus mempunyai email terverifikasi.');
    }
    $existing = fs_google_integration($userId);
    $refreshToken = (string) ($token['refresh_token'] ?? '');
    if ($refreshToken === '' && $existing && !empty($existing['refresh_token_encrypted'])) {
        $refreshToken = fs_decrypt_secret((string) $existing['refresh_token_encrypted']);
    }
    if ($refreshToken === '') {
        throw new RuntimeException('Google tidak mengirim refresh token. Putuskan koneksi aplikasi pada akun Google lalu hubungkan kembali dengan izin offline.');
    }
    $scopes = trim((string) ($token['scope'] ?? ''));
    $grantedScopes = preg_split('/\s+/', $scopes) ?: [];
    $requiredScopes = [
        'https://www.googleapis.com/auth/drive.activity.readonly',
        'https://www.googleapis.com/auth/drive.metadata.readonly',
    ];
    $missingScopes = array_values(array_diff($requiredScopes, $grantedScopes));
    if ($missingScopes) {
        throw new RuntimeException('Izin Google Drive Activity dan metadata belum diberikan. Putuskan koneksi lalu hubungkan kembali dengan menyetujui semua izin yang diminta.');
    }
    $expiresAt = gmdate('Y-m-d H:i:s', time() + max(60, (int) ($token['expires_in'] ?? 3600)));
    $pdo = require_db();
    $stmt = $pdo->prepare("INSERT INTO public.user_integrations (user_id, provider, provider_user_id, provider_email, access_token_encrypted, refresh_token_encrypted, token_expires_at, scopes, created_at, updated_at) VALUES (CAST(:user_id AS UUID), 'google', :provider_user_id, :provider_email, :access_token, :refresh_token, :expires_at, :scopes, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP) ON CONFLICT (user_id, provider) DO UPDATE SET provider_user_id = EXCLUDED.provider_user_id, provider_email = EXCLUDED.provider_email, access_token_encrypted = EXCLUDED.access_token_encrypted, refresh_token_encrypted = EXCLUDED.refresh_token_encrypted, token_expires_at = EXCLUDED.token_expires_at, scopes = EXCLUDED.scopes, updated_at = CURRENT_TIMESTAMP");
    $stmt->execute([
        'user_id' => $userId,
        'provider_user_id' => $sub,
        'provider_email' => $email,
        'access_token' => fs_encrypt_secret($access),
        'refresh_token' => fs_encrypt_secret($refreshToken),
        'expires_at' => $expiresAt,
        'scopes' => $scopes,
    ]);
}

function fs_google_access_token(string $userId): string
{
    $integration = fs_google_integration($userId);
    if (!$integration) {
        throw new RuntimeException('Akun Google belum dihubungkan. Buka menu Integrasi terlebih dahulu.');
    }
    $expiresValue = trim((string) ($integration['token_expires_at'] ?? ''));
    $expires = $expiresValue !== '' ? (strtotime($expiresValue . ' UTC') ?: 0) : 0;
    $access = fs_decrypt_secret((string) $integration['access_token_encrypted']);
    if ($expires > time() + 90 && $access !== '') {
        return $access;
    }
    $refresh = (string) ($integration['refresh_token_encrypted'] ?? '');
    if ($refresh === '') {
        throw new RuntimeException('Refresh token Google tidak tersedia. Hubungkan ulang akun Google.');
    }
    $config = fs_google_required_config();
    $refreshToken = fs_decrypt_secret($refresh);
    $token = fs_google_http_json('POST', 'https://oauth2.googleapis.com/token', [
        'Content-Type' => 'application/x-www-form-urlencoded',
        'Accept' => 'application/json',
    ], http_build_query([
        'client_id' => $config['client_id'],
        'client_secret' => $config['client_secret'],
        'refresh_token' => $refreshToken,
        'grant_type' => 'refresh_token',
    ], '', '&', PHP_QUERY_RFC3986));
    $newAccess = (string) ($token['access_token'] ?? '');
    if ($newAccess === '') {
        throw new RuntimeException('Google gagal memperbarui access token. Hubungkan ulang akun Google.');
    }
    $newRefresh = (string) ($token['refresh_token'] ?? $refreshToken);
    $expiresAt = gmdate('Y-m-d H:i:s', time() + max(60, (int) ($token['expires_in'] ?? 3600)));
    $stmt = require_db()->prepare("UPDATE public.user_integrations SET access_token_encrypted = :access_token, refresh_token_encrypted = :refresh_token, token_expires_at = :expires_at, updated_at = CURRENT_TIMESTAMP WHERE user_id = CAST(:user_id AS UUID) AND provider = 'google'");
    $stmt->execute([
        'access_token' => fs_encrypt_secret($newAccess),
        'refresh_token' => fs_encrypt_secret($newRefresh),
        'expires_at' => $expiresAt,
        'user_id' => $userId,
    ]);
    return $newAccess;
}

function fs_google_parse_document_id(string $url): ?string
{
    $parts = parse_url(trim($url));
    if (!$parts || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
        return null;
    }
    $host = strtolower((string) ($parts['host'] ?? ''));
    if (!in_array($host, ['docs.google.com', 'drive.google.com'], true)) {
        return null;
    }
    $path = (string) ($parts['path'] ?? '');
    if (preg_match('~/(?:document|file)/(?:u/\d+/)?d/([A-Za-z0-9_-]{10,})~', $path, $match)) {
        return $match[1];
    }
    return null;
}

function fs_google_verify_document(string $accessToken, string $fileId): array
{
    $url = 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($fileId)
        . '?fields=' . rawurlencode('id,name,mimeType,webViewLink')
        . '&supportsAllDrives=true';
    $file = fs_google_http_json('GET', $url, [
        'Authorization' => 'Bearer ' . $accessToken,
        'Accept' => 'application/json',
    ]);
    if (($file['mimeType'] ?? '') !== 'application/vnd.google-apps.document') {
        throw new RuntimeException('Resource yang dipilih bukan file Google Docs.');
    }
    return $file;
}

function fs_google_query_activity(string $accessToken, string $fileId, string $since): array
{
    $timestamp = strtotime($since . ' UTC');
    if (!$timestamp) {
        $timestamp = time() - 86400;
    }
    $filterTime = gmdate('Y-m-d\TH:i:s\Z', max(0, $timestamp - 120));
    $request = [
        'itemName' => 'items/' . $fileId,
        'pageSize' => 100,
        'filter' => 'time >= "' . $filterTime . '"',
        'consolidationStrategy' => ['none' => new stdClass()],
    ];
    $all = [];
    $pageCount = 0;
    do {
        $response = fs_google_http_json('POST', 'https://driveactivity.googleapis.com/v2/activity:query', [
            'Authorization' => 'Bearer ' . $accessToken,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ], json_encode($request, JSON_THROW_ON_ERROR));
        foreach (($response['activities'] ?? []) as $activity) {
            if (is_array($activity)) {
                $all[] = $activity;
            }
        }
        $request['pageToken'] = (string) ($response['nextPageToken'] ?? '');
        $pageCount++;
    } while ($request['pageToken'] !== '' && $pageCount < 30);
    if ($request['pageToken'] !== '') {
        throw new RuntimeException('Riwayat dokumen sangat besar. Sinkronisasi dibatasi per batch; coba ulangi beberapa saat lagi.');
    }
    return $all;
}

function fs_google_time_from_activity(array $value): ?string
{
    $candidates = [
        $value['timestamp'] ?? null,
        $value['timeRange']['endTime'] ?? null,
        $value['timeRange']['startTime'] ?? null,
    ];

    foreach ($candidates as $candidate) {
        if (is_array($candidate) && isset($candidate['seconds'])) {
            $seconds = (int) $candidate['seconds'];
            if ($seconds > 0) {
                return gmdate('Y-m-d H:i:s', $seconds);
            }
        }

        // Google REST Timestamp fields are normally RFC 3339 strings.
        if (is_string($candidate) && trim($candidate) !== '') {
            try {
                $date = new DateTimeImmutable($candidate, new DateTimeZone('UTC'));
                return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            } catch (Throwable) {
                continue;
            }
        }
    }

    return null;
}

function fs_google_canonical_json(mixed $value): string
{
    if (is_array($value)) {
        if (!array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $child) {
            $value[$key] = json_decode(fs_google_canonical_json($child), true) ?? $child;
        }
    }
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
}

/**
 * Build a stable idempotency key from the full DriveActivity context.
 * Google may omit actor/target/time from Action when those values are shared
 * with the parent DriveActivity. Hashing Action alone would collapse repeated
 * edits (for example, every Action containing only {detail: {edit: {}}}).
 */
function fs_google_event_external_id(
    string $taskId,
    string $memberId,
    string $fileId,
    array $activity,
    array $action,
    int $actionIndex
): string {
    return hash('sha256', fs_google_canonical_json([
        'task_id' => $taskId,
        'member_id' => $memberId,
        'file_id' => $fileId,
        'activity' => $activity,
        'action_index' => $actionIndex,
        'action' => $action,
    ]));
}

/**
 * Query each connected member's view of a task document. Only actions marked
 * isCurrentUser=true are attributed to that member. Events stay raw (filtered_delta=0)
 * until FairShare's scoring rules are formally calibrated.
 */
function fs_google_sync_task(string $taskId): array
{
    if (!valid_uuid($taskId)) {
        throw new InvalidArgumentException('ID tugas tidak valid.');
    }
    $pdo = require_db();
    $taskStmt = $pdo->prepare("SELECT t.id::text AS id, t.group_id::text AS group_id, t.task_type, t.external_resource_id, t.created_at::text AS created_at, t.sync_state::text AS sync_state FROM public.tasks t JOIN public.groups team ON team.id = t.group_id AND team.group_type = 'team' WHERE t.id = CAST(:task_id AS UUID) LIMIT 1");
    $taskStmt->execute(['task_id' => $taskId]);
    $task = $taskStmt->fetch(PDO::FETCH_ASSOC);
    if (!$task || $task['task_type'] !== 'gdocs' || empty($task['external_resource_id'])) {
        throw new RuntimeException('Tugas Google Docs belum memiliki dokumen yang terhubung.');
    }

    $memberStmt = $pdo->prepare("SELECT gm.user_id::text AS user_id FROM public.group_members gm JOIN public.users u ON u.id = gm.user_id WHERE gm.group_id = CAST(:group_id AS UUID) AND u.role = 'mahasiswa' ORDER BY gm.joined_at ASC");
    $memberStmt->execute(['group_id' => $task['group_id']]);
    $members = $memberStmt->fetchAll(PDO::FETCH_COLUMN);
    $state = json_decode((string) ($task['sync_state'] ?? '{}'), true);
    if (!is_array($state)) {
        $state = [];
    }

    $inserted = 0;
    $checkedAccounts = 0;
    $skippedAccounts = 0;
    $errors = [];
    foreach ($members as $memberId) {
        $integration = fs_google_integration((string) $memberId);
        if (!$integration) {
            $skippedAccounts++;
            continue;
        }
        try {
            $accessToken = fs_google_access_token((string) $memberId);
            $since = (string) ($state[(string) $memberId] ?? $task['created_at']);
            $activities = fs_google_query_activity($accessToken, (string) $task['external_resource_id'], $since);
            foreach ($activities as $activity) {
                $activityActors = is_array($activity['actors'] ?? null) ? $activity['actors'] : [];
                $activityTargets = is_array($activity['targets'] ?? null) ? $activity['targets'] : [];
                $actions = $activity['actions'] ?? [];
                if (!$actions) {
                    $actions = [[
                        'detail' => $activity['primaryActionDetail'] ?? [],
                        'timestamp' => $activity['timestamp'] ?? ($activity['timeRange']['endTime'] ?? null),
                    ]];
                }
                foreach ($actions as $actionIndex => $action) {
                    // Action may omit actor when it is inherited from DriveActivity.
                    // Only inherit when there is exactly one parent actor; never guess
                    // from the first element of a multi-actor activity.
                    $actor = $action['actor'] ?? (count($activityActors) === 1 ? $activityActors[0] : null);
                    $knownUser = is_array($actor) ? ($actor['user']['knownUser'] ?? null) : null;
                    if (!is_array($knownUser) || ($knownUser['isCurrentUser'] ?? false) !== true) {
                        continue;
                    }
                    $detail = $action['detail'] ?? ($activity['primaryActionDetail'] ?? []);
                    $actionType = is_array($detail) ? (string) array_key_first($detail) : '';
                    if (!in_array($actionType, ['edit', 'create'], true)) {
                        continue;
                    }
                    $activityTime = fs_google_time_from_activity($action);
                    if (!$activityTime) {
                        $activityTime = fs_google_time_from_activity($activity);
                    }
                    if (!$activityTime) {
                        continue;
                    }
                    $target = $action['target'] ?? (count($activityTargets) === 1 ? $activityTargets[0] : []);
                    $targetTitle = (string) ($target['driveItem']['title'] ?? 'Google Docs');
                    $personName = (string) ($knownUser['personName'] ?? '');
                    // Include DriveActivity parent context. Action frequently omits
                    // inherited actor/target/timestamp fields, so Action-only hashes
                    // would collapse separate edits with identical detail payloads.
                    $externalId = fs_google_event_external_id(
                        $taskId,
                        (string) $memberId,
                        (string) $task['external_resource_id'],
                        $activity,
                        $action,
                        (int) $actionIndex
                    );
                    $metadata = [
                        'provider' => 'google_drive_activity',
                        'event_type' => $actionType,
                        'document_id' => $task['external_resource_id'],
                        'document_title' => $targetTitle,
                        'actor_person_name' => $personName,
                        'actor_attributed' => true,
                        'attribution_method' => 'oauth_is_current_user',
                        'validation_status' => 'Menunggu kalibrasi bobot',
                        'description' => 'Aktivitas ' . $actionType . ' teramati melalui Google Drive Activity API.',
                    ];
                    $insert = $pdo->prepare("INSERT INTO public.telemetry_logs (task_id, group_id, user_id, source, external_id, activity_timestamp, raw_delta, filtered_delta, metadata, created_at) VALUES (CAST(:task_id AS UUID), CAST(:group_id AS UUID), CAST(:user_id AS UUID), 'gdocs', :external_id, :activity_timestamp, 1, 0, CAST(:metadata AS JSONB), CURRENT_TIMESTAMP) ON CONFLICT DO NOTHING");
                    $insert->execute([
                        'task_id' => $taskId,
                        'group_id' => $task['group_id'],
                        'user_id' => $memberId,
                        'external_id' => $externalId,
                        'activity_timestamp' => $activityTime,
                        'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    ]);
                    $inserted += $insert->rowCount();
                }
            }
            $state[(string) $memberId] = gmdate('Y-m-d H:i:s');
            $checkedAccounts++;
        } catch (Throwable $e) {
            $skippedAccounts++;
            $errors[] = 'Satu akun Google gagal disinkronkan: ' . mb_substr($e->getMessage(), 0, 180);
            error_log('FairShare Google sync member ' . $memberId . ': ' . $e->getMessage());
        }
    }

    if ($checkedAccounts === 0 && $skippedAccounts > 0) {
        $message = 'Belum ada akun Google anggota kelompok yang terhubung dan dapat disinkronkan. Minta setiap anggota menghubungkan akun Google mereka.';
        $update = $pdo->prepare("UPDATE public.tasks SET last_synced_at = CURRENT_TIMESTAMP, sync_status = 'error', sync_error = :sync_error, updated_at = CURRENT_TIMESTAMP WHERE id = CAST(:task_id AS UUID)");
        $update->execute(['sync_error' => $message, 'task_id' => $taskId]);
        throw new RuntimeException($message);
    }

    $status = ($errors || $skippedAccounts > 0) ? 'partial' : 'synced';
    $syncError = $errors ? implode(' | ', array_slice($errors, 0, 3))
        : ($skippedAccounts > 0 ? $skippedAccounts . ' akun anggota belum menghubungkan Google.' : null);
    $update = $pdo->prepare("UPDATE public.tasks SET sync_state = CAST(:sync_state AS JSONB), last_synced_at = CURRENT_TIMESTAMP, sync_status = :sync_status, sync_error = :sync_error, updated_at = CURRENT_TIMESTAMP WHERE id = CAST(:task_id AS UUID)");
    $update->execute([
        'sync_state' => json_encode($state, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        'sync_status' => $status,
        'sync_error' => $syncError,
        'task_id' => $taskId,
    ]);
    return [
        'inserted' => $inserted,
        'checked_accounts' => $checkedAccounts,
        'skipped_accounts' => $skippedAccounts,
        'errors' => $errors,
        'status' => $status,
    ];
}
