<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

define('APP_NAME', 'FairShare');
define('BASE_PATH', dirname(__DIR__));
define('DATA_PATH', BASE_PATH . '/data');
if (!is_dir(DATA_PATH)) {
    mkdir(DATA_PATH, 0775, true);
}

/** Keep page-level failures user-friendly; details go to PHP error log, never the browser. */
set_exception_handler(function (Throwable $e): void {
    error_log('FairShare uncaught error: ' . $e->getMessage());
    if (!headers_sent()) {
        http_response_code(503);
        header('Content-Type: text/html; charset=utf-8');
    }
    echo '<!doctype html><html lang="id"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>FairShare — Gangguan Layanan</title><body style="font:16px system-ui;max-width:680px;margin:10vh auto;padding:24px;color:#172033">'
        . '<h1>Data FairShare belum dapat diakses</h1><p>Periksa koneksi PostgreSQL/Supabase dan konfigurasi <code>data/.env</code>. '
        . 'Jika masalah berlanjut, periksa error log PHP. Detail teknis tidak ditampilkan di browser demi keamanan.</p>'
        . '<p><a href="healthcheck.php">Buka pemeriksaan koneksi</a></p></body></html>';
    exit;
});

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function redirect(string $path): never
{
    header('Location: ' . $path);
    exit;
}

function read_data(string $file, array $fallback = []): array
{
    $path = DATA_PATH . '/' . basename($file);
    if (!is_file($path)) {
        return $fallback;
    }
    $raw = file_get_contents($path);
    $data = json_decode($raw ?: '', true);
    return is_array($data) ? $data : $fallback;
}

function write_data(string $file, array $data): bool
{
    $path = DATA_PATH . '/' . basename($file);
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }
    return file_put_contents($path, $json . PHP_EOL, LOCK_EX) !== false;
}

/** Read a single key from data/.env without exposing the full configuration. */
function app_env(string $key, string $default = ''): string
{
    $envFile = DATA_PATH . '/.env';
    if (!is_file($envFile)) {
        return $default;
    }
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$name, $value] = explode('=', $line, 2);
        if (trim($name) === $key) {
            return trim(trim($value), " \t\n\r\0\x0B\"'");
        }
    }
    return $default;
}

function fairshare_pdo(): ?PDO
{
    require_once BASE_PATH . '/data/db.php';
    return fairshare_db();
}

function require_db(): PDO
{
    $pdo = fairshare_pdo();
    if (!$pdo) {
        throw new RuntimeException('Database belum dikonfigurasi. Salin data/.env.example menjadi data/.env dan isi kredensial Supabase.');
    }
    return $pdo;
}

function current_user(): ?array
{
    return isset($_SESSION['user']) && is_array($_SESSION['user']) ? $_SESSION['user'] : null;
}

function require_login(): array
{
    $user = current_user();
    if (!$user) {
        redirect('login.php');
    }
    return $user;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function verify_csrf(): void
{
    $token = $_POST['csrf'] ?? '';
    if (!is_string($token) || !hash_equals(csrf_token(), $token)) {
        http_response_code(419);
        exit('Sesi formulir kedaluwarsa. Muat ulang halaman lalu coba lagi.');
    }
}

function flash(string $message, string $type = 'success'): void
{
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
}

function take_flash(): ?array
{
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

/** Local UI audit notes only. Core FairShare entities are stored in PostgreSQL. */
function log_activity(string $title, string $detail, string $type = 'info'): void
{
    $logs = read_data('activity.json', []);
    array_unshift($logs, [
        'id' => bin2hex(random_bytes(5)),
        'title' => $title,
        'detail' => $detail,
        'type' => $type,
        'time' => date('Y-m-d H:i:s'),
        'user' => current_user()['name'] ?? 'Sistem'
    ]);
    write_data('activity.json', array_slice($logs, 0, 500));
}

/**
 * Mengirim notifikasi kepada pengguna tertentu.
 * $category: info, success, warning, task, atau system.
 */
function send_notification(
    string $recipientId,
    string $title,
    string $message,
    string $category = 'info',
    ?string $senderId = null
): void {
    if (!valid_uuid($recipientId)) {
        throw new InvalidArgumentException('ID penerima notifikasi tidak valid.');
    }

    if ($senderId !== null && !valid_uuid($senderId)) {
        throw new InvalidArgumentException('ID pengirim notifikasi tidak valid.');
    }

    $allowedCategories = ['info', 'success', 'warning', 'task', 'system'];
    if (!in_array($category, $allowedCategories, true)) {
        $category = 'info';
    }

    $pdo = require_db();
    $stmt = $pdo->prepare("
        INSERT INTO public.notifications (
            recipient_id,
            sender_id,
            title,
            message,
            category
        ) VALUES (
            CAST(:recipient_id AS UUID),
            CAST(:sender_id AS UUID),
            :title,
            :message,
            :category
        )
    ");

    $stmt->execute([
        'recipient_id' => $recipientId,
        'sender_id' => $senderId,
        'title' => $title,
        'message' => $message,
        'category' => $category
    ]);
}

function valid_uuid(string $value): bool
{
    return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', trim($value));
}

function is_teacher(?array $user = null): bool
{
    $user = $user ?? current_user();
    return in_array(($user['role'] ?? 'mahasiswa'), ['dosen', 'admin'], true);
}

/**
 * Hasilkan kode undangan unik dan acak untuk kelas atau kelompok (contoh: PBO-A8K29X, TIM-W7D8Q2).
 */
function generate_unique_invite_code(PDO $pdo, string $prefix = 'CLS'): string
{
    $chars = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
    $cleanPrefix = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $prefix));
    if (mb_strlen($cleanPrefix) > 4) {
        $cleanPrefix = mb_substr($cleanPrefix, 0, 4);
    }
    if ($cleanPrefix === '') {
        $cleanPrefix = 'FS';
    }

    for ($attempt = 0; $attempt < 100; $attempt++) {
        $random = '';
        for ($i = 0; $i < 6; $i++) {
            $random .= $chars[random_int(0, strlen($chars) - 1)];
        }
        $code = $cleanPrefix . '-' . $random;
        $stmt = $pdo->prepare('SELECT 1 FROM public.groups WHERE UPPER(invite_code) = UPPER(:code) LIMIT 1');
        $stmt->execute(['code' => $code]);
        if (!$stmt->fetch()) {
            return $code;
        }
    }
    throw new RuntimeException('Gagal menghasilkan kode undangan unik.');
}

// ============================================================
// FUNGSI PENGELOLAAN KELAS (group_type = 'class')
// ============================================================

/**
 * Mengambil daftar kelas. Jika $dosenId diberikan, hanya kelas buatan dosen tersebut.
 */
function all_classes(?string $dosenId = null): array
{
    $pdo = require_db();
    $sql = "
        SELECT
            g.id::text AS id,
            g.created_by::text AS created_by,
            g.name,
            g.description,
            g.semester,
            g.academic_year,
            g.duration_weeks,
            g.invite_code,
            g.created_at::text AS created_at,
            g.updated_at::text AS updated_at,
            u.name AS lecturer_name,
            u.email AS lecturer_email,
            (SELECT COUNT(*) FROM public.group_members gm WHERE gm.group_id = g.id) AS student_count,
            (SELECT COUNT(*) FROM public.groups teams WHERE teams.parent_group_id = g.id AND teams.group_type = 'team') AS team_count,
            (SELECT COUNT(*) FROM public.tasks t JOIN public.groups tm ON tm.id = t.group_id WHERE tm.parent_group_id = g.id AND tm.group_type = 'team') AS task_count
        FROM public.groups g
        LEFT JOIN public.users u ON u.id = g.created_by
        WHERE g.group_type = 'class'
    ";
    $params = [];
    if ($dosenId !== null) {
        if (!valid_uuid($dosenId)) {
            return [];
        }
        $sql .= " AND g.created_by = CAST(:dosen_id AS uuid)";
        $params['dosen_id'] = $dosenId;
    }
    $sql .= " ORDER BY g.created_at DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Mengambil daftar kelas yang diikuti mahasiswa.
 */
function user_classes(string $userId): array
{
    if (!valid_uuid($userId)) {
        return [];
    }
    $pdo = require_db();
    $stmt = $pdo->prepare("
        SELECT
            g.id::text AS id,
            g.created_by::text AS created_by,
            g.name,
            g.description,
            g.semester,
            g.academic_year,
            g.duration_weeks,
            g.invite_code,
            g.created_at::text AS created_at,
            g.updated_at::text AS updated_at,
            u.name AS lecturer_name,
            u.email AS lecturer_email,
            gm.joined_at::text AS joined_at,
            (SELECT COUNT(*) FROM public.group_members m WHERE m.group_id = g.id) AS student_count,
            (SELECT COUNT(*) FROM public.groups teams WHERE teams.parent_group_id = g.id AND teams.group_type = 'team') AS team_count,
            (SELECT COUNT(*) FROM public.tasks t JOIN public.groups tm ON tm.id = t.group_id WHERE tm.parent_group_id = g.id AND tm.group_type = 'team') AS task_count
        FROM public.groups g
        JOIN public.group_members gm ON gm.group_id = g.id
        LEFT JOIN public.users u ON u.id = g.created_by
        WHERE g.group_type = 'class' AND gm.user_id = CAST(:uid AS uuid)
        ORDER BY g.created_at DESC
    ");
    $stmt->execute(['uid' => $userId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Kelas yang dapat dilihat pengguna sesuai rolenya.
 */
function visible_classes(array $user): array
{
    $uid = (string) ($user['id'] ?? '');
    if (is_teacher($user)) {
        return all_classes($uid);
    }
    return user_classes($uid);
}

/**
 * Ambil satu kelas berdasarkan ID UUID.
 */
function class_by_id(string $classId): ?array
{
    if (!valid_uuid($classId)) {
        return null;
    }
    $pdo = require_db();
    $stmt = $pdo->prepare("
        SELECT
            g.id::text AS id,
            g.created_by::text AS created_by,
            g.name,
            g.description,
            g.semester,
            g.academic_year,
            g.duration_weeks,
            g.invite_code,
            g.created_at::text AS created_at,
            g.updated_at::text AS updated_at,
            u.name AS lecturer_name,
            u.email AS lecturer_email,
            (SELECT COUNT(*) FROM public.group_members gm WHERE gm.group_id = g.id) AS student_count,
            (SELECT COUNT(*) FROM public.groups teams WHERE teams.parent_group_id = g.id AND teams.group_type = 'team') AS team_count,
            (SELECT COUNT(*) FROM public.tasks t JOIN public.groups tm ON tm.id = t.group_id WHERE tm.parent_group_id = g.id AND tm.group_type = 'team') AS task_count
        FROM public.groups g
        LEFT JOIN public.users u ON u.id = g.created_by
        WHERE g.id = CAST(:id AS uuid) AND g.group_type = 'class'
        LIMIT 1
    ");
    $stmt->execute(['id' => $classId]);
    $res = $stmt->fetch(PDO::FETCH_ASSOC);
    return $res ?: null;
}

/**
 * Cari kelas berdasarkan kode undangan.
 */
function class_by_invite_code(string $code): ?array
{
    $code = strtoupper(trim($code));
    if ($code === '') {
        return null;
    }
    $pdo = require_db();
    $stmt = $pdo->prepare("
        SELECT
            g.id::text AS id,
            g.created_by::text AS created_by,
            g.name,
            g.description,
            g.semester,
            g.academic_year,
            g.invite_code,
            u.name AS lecturer_name
        FROM public.groups g
        LEFT JOIN public.users u ON u.id = g.created_by
        WHERE UPPER(g.invite_code) = :code AND g.group_type = 'class'
        LIMIT 1
    ");
    $stmt->execute(['code' => $code]);
    $res = $stmt->fetch(PDO::FETCH_ASSOC);
    return $res ?: null;
}

/**
 * Ambil daftar mahasiswa terdaftar di suatu kelas beserta nama kelompoknya (jika sudah ada).
 */
function class_members(string $classId): array
{
    if (!valid_uuid($classId)) {
        return [];
    }
    $pdo = require_db();
    $stmt = $pdo->prepare("
        SELECT
            u.id::text AS user_id,
            u.nim_nip,
            u.name,
            u.email,
            gm.joined_at::text AS joined_at,
            tm.id::text AS team_id,
            tm.name AS team_name,
            tm_member.role_in_group AS role_in_team
        FROM public.group_members gm
        JOIN public.users u ON u.id = gm.user_id
        LEFT JOIN LATERAL (
            SELECT t.id, t.name, m.role_in_group
            FROM public.group_members m
            JOIN public.groups t ON t.id = m.group_id
            WHERE t.parent_group_id = CAST(:class_id AS uuid)
              AND t.group_type = 'team'
              AND m.user_id = u.id
            LIMIT 1
        ) tm_member ON true
        LEFT JOIN public.groups tm ON tm.id = tm_member.id
        WHERE gm.group_id = CAST(:class_id2 AS uuid)
        ORDER BY u.name ASC
    ");
    $stmt->execute(['class_id' => $classId, 'class_id2' => $classId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Ambil daftar kelompok dalam suatu kelas.
 */
function class_teams(string $classId): array
{
    if (!valid_uuid($classId)) {
        return [];
    }
    $all = all_groups();
    return array_values(array_filter($all, fn($g) => ($g['parent_group_id'] ?? '') === $classId));
}

/**
 * Ambil seluruh tugas kelompok dalam suatu kelas.
 */
function class_tasks(string $classId): array
{
    if (!valid_uuid($classId)) {
        return [];
    }
    $pdo = require_db();
    $stmt = $pdo->prepare("
        SELECT
            t.id::text AS id,
            t.group_id::text AS group_id,
            t.assigned_user_id::text AS assigned_user_id,
            t.title,
            t.description,
            t.weight,
            t.status,
            t.target_week,
            t.completed_at::text AS completed_at,
            t.task_type,
            t.due_at::text AS due_at,
            t.assignment_batch_id::text AS assignment_batch_id,
            t.resource_url,
            t.external_resource_id,
            t.resource_title,
            t.sync_status,
            t.last_synced_at::text AS last_synced_at,
            t.sync_error,
            t.created_at::text AS created_at,
            t.updated_at::text AS updated_at,
            g.name AS group_name,
            u.name AS assigned_user_name
        FROM public.tasks t
        JOIN public.groups g ON g.id = t.group_id
        LEFT JOIN public.users u ON u.id = t.assigned_user_id
        WHERE g.parent_group_id = CAST(:class_id AS uuid) AND g.group_type = 'team'
        ORDER BY t.created_at DESC
    ");
    $stmt->execute(['class_id' => $classId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['status_db'] = $r['status'];
        $r['status'] = ui_task_status((string) $r['status']);
        $r['due_date'] = 'Minggu target ' . (int) $r['target_week'];
    }
    unset($r);
    return $rows;
}

// ============================================================
// FUNGSI PENGELOLAAN KELOMPOK (group_type = 'team')
// ============================================================

/**
 * Mengambil semua kelompok (team). Tidak termasuk kelas (class).
 */
function all_groups(): array
{
    $pdo = require_db();
    $rows = $pdo->query("
        SELECT
            g.id::text AS id,
            g.created_by::text AS created_by,
            g.name,
            g.class_name,
            g.duration_weeks,
            g.github_repo_url,
            g.gdocs_file_id,
            g.created_at::text AS created_at,
            g.updated_at::text AS updated_at,
            g.group_type,
            g.parent_group_id::text AS parent_group_id,
            g.invite_code,
            g.description,
            g.semester,
            g.academic_year,
            u.name AS creator_name,
            p.name AS parent_class_name
        FROM public.groups g
        LEFT JOIN public.users u ON u.id = g.created_by
        LEFT JOIN public.groups p ON p.id = g.parent_group_id
        WHERE g.group_type = 'team'
        ORDER BY g.created_at DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $byId = [];
    foreach ($rows as $r) {
        $r['course'] = $r['parent_class_name'] ?: ($r['class_name'] ?: 'PBL');
        $r['member_ids'] = [];
        $r['members'] = [];
        $r['project_manager'] = null;
        $byId[strtolower($r['id'])] = $r;
    }
    if (!$byId) {
        return [];
    }

    $memberRows = $pdo->query("
        SELECT
            gm.group_id::text AS group_id,
            gm.user_id::text AS user_id,
            gm.role_in_group,
            gm.peer_approval,
            gm.joined_at::text AS joined_at,
            u.name,
            u.email,
            u.nim_nip
        FROM public.group_members gm
        JOIN public.users u ON u.id = gm.user_id
        JOIN public.groups g ON g.id = gm.group_id
        WHERE g.group_type = 'team'
        ORDER BY CASE WHEN gm.role_in_group = 'ketua' THEN 0 ELSE 1 END, gm.joined_at ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($memberRows as $m) {
        $key = strtolower($m['group_id']);
        if (!isset($byId[$key])) {
            continue;
        }
        $byId[$key]['member_ids'][] = (string) $m['user_id'];
        $memberInfo = [
            'user_id' => (string) $m['user_id'],
            'name' => $m['name'],
            'email' => $m['email'],
            'nim_nip' => $m['nim_nip'] ?? '',
            'role_in_group' => $m['role_in_group'],
            'role' => $m['role_in_group'] === 'ketua' ? 'Project Manager' : 'Anggota',
            'peer_approval' => (bool) $m['peer_approval'],
            'joined_at' => $m['joined_at']
        ];
        $byId[$key]['members'][] = $memberInfo;
        if ($m['role_in_group'] === 'ketua' && $byId[$key]['project_manager'] === null) {
            $byId[$key]['project_manager'] = $memberInfo;
        }
    }
    return array_values($byId);
}

/**
 * Mengambil kelompok yang diikuti oleh mahasiswa.
 */
function user_groups(string $userId): array
{
    $uid = strtolower($userId);
    return array_values(array_filter(all_groups(), function ($g) use ($uid) {
        if (strtolower((string) $g['created_by']) === $uid) {
            return true;
        }
        foreach (($g['member_ids'] ?? []) as $id) {
            if (strtolower((string) $id) === $uid) {
                return true;
            }
        }
        return false;
    }));
}

/**
 * Kelompok yang terlihat oleh pengguna.
 * Jika Dosen: hanya kelompok dari kelas yang dimiliki dosen tersebut.
 * Jika Mahasiswa: hanya kelompok yang diikuti mahasiswa tersebut.
 */
function visible_groups(array $user): array
{
    $uid = strtolower((string) ($user['id'] ?? ''));
    if (is_teacher($user)) {
        // Ambil ID semua kelas buatan dosen ini
        $myClassIds = array_map('strtolower', array_column(all_classes((string) $user['id']), 'id'));
        return array_values(array_filter(all_groups(), function ($g) use ($myClassIds, $uid) {
            $parent = strtolower((string) ($g['parent_group_id'] ?? ''));
            return in_array($parent, $myClassIds, true) || strtolower((string) $g['created_by']) === $uid;
        }));
    }
    return user_groups($uid);
}

function group_invite_code(array $group): string
{
    return (string) ($group['invite_code'] ?? $group['id'] ?? '');
}

/**
 * Memeriksa apakah pengguna memiliki hak mengelola kelompok (Dosen pemilik kelas atau Ketua/PM kelompok).
 */
function can_manage_group(array $group, array $user): bool
{
    $uid = strtolower((string) ($user['id'] ?? ''));
    if (is_teacher($user)) {
        // Dosen pemilik kelas induk atau pembuat kelompok
        $classId = (string) ($group['parent_group_id'] ?? '');
        if ($classId !== '') {
            $parent = class_by_id($classId);
            if ($parent && strtolower((string) $parent['created_by']) === $uid) {
                return true;
            }
        }
        return strtolower((string) ($group['created_by'] ?? '')) === $uid;
    }
    foreach (($group['members'] ?? []) as $m) {
        if (strtolower((string) ($m['user_id'] ?? '')) === $uid && ($m['role_in_group'] ?? '') === 'ketua') {
            return true;
        }
    }
    return false;
}

function group_by_id(string $id): ?array
{
    if (!valid_uuid($id)) {
        return null;
    }
    $key = strtolower($id);
    foreach (all_groups() as $g) {
        if (strtolower((string) $g['id']) === $key) {
            return $g;
        }
    }
    return null;
}

/**
 * Cari kelompok berdasarkan kode undangan.
 */
function team_by_invite_code(string $code): ?array
{
    $code = strtoupper(trim($code));
    if ($code === '') {
        return null;
    }
    foreach (all_groups() as $g) {
        if (strtoupper((string) ($g['invite_code'] ?? '')) === $code) {
            return $g;
        }
    }
    return null;
}

// ============================================================
// FUNGSI TUGAS & TELEMETRY
// ============================================================

function db_task_status(string $label): string
{
    return match ($label) {
        'Belum dikerjakan', 'todo' => 'todo',
        'Dikerjakan', 'in_progress' => 'in_progress',
        'Review', 'review' => 'review',
        'Selesai', 'done' => 'done',
        default => 'todo'
    };
}

function ui_task_status(string $status): string
{
    return match ($status) {
        'todo' => 'Belum dikerjakan',
        'in_progress' => 'Dikerjakan',
        'review' => 'Review',
        'done' => 'Selesai',
        default => $status
    };
}

function all_tasks(): array
{
    $sql = "
        SELECT
            t.id::text AS id,
            t.group_id::text AS group_id,
            t.assigned_user_id::text AS assigned_user_id,
            t.title,
            t.description,
            t.weight,
            t.status,
            t.target_week,
            t.completed_at::text AS completed_at,
            t.created_at::text AS created_at,
            t.updated_at::text AS updated_at,
            g.name AS group_name,
            g.parent_group_id::text AS parent_group_id,
            u.name AS assigned_user_name
        FROM public.tasks t
        JOIN public.groups g ON g.id = t.group_id
        LEFT JOIN public.users u ON u.id = t.assigned_user_id
        WHERE g.group_type = 'team'
        ORDER BY t.created_at DESC
    ";
    $rows = require_db()->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['status_db'] = $r['status'];
        $r['status'] = ui_task_status((string) $r['status']);
        $r['due_date'] = 'Minggu target ' . (int) $r['target_week'];
    }
    unset($r);
    return $rows;
}

function user_tasks(string $userId): array
{
    $groupIds = array_column(user_groups($userId), 'id');
    return array_values(array_filter(all_tasks(), fn($t) => in_array((string) ($t['group_id'] ?? ''), $groupIds, true)));
}

function all_telemetry(?string $userId = null, ?array $groupIds = null): array
{
    $sql = "
        SELECT
            tl.id::text AS id,
            tl.group_id::text AS group_id,
            tl.user_id::text AS user_id,
            tl.source,
            COALESCE(
                NULLIF(tl.metadata->>'event_type',''),
                NULLIF(tl.metadata->>'title',''),
                CASE WHEN tl.source='github' THEN 'Aktivitas GitHub' WHEN tl.source='gdocs' THEN 'Aktivitas Google Docs' ELSE 'Aktivitas' END
            ) AS event_type,
            tl.external_id,
            tl.raw_delta,
            tl.filtered_delta,
            tl.activity_timestamp::text AS activity_timestamp,
            tl.created_at::text AS created_at,
            tl.metadata,
            u.name AS user_name,
            g.name AS group_name
        FROM public.telemetry_logs tl
        LEFT JOIN public.users u ON u.id = tl.user_id
        JOIN public.groups g ON g.id = tl.group_id
        WHERE g.group_type = 'team'
        ORDER BY tl.activity_timestamp DESC
    ";
    $rows = require_db()->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) {
        if ($userId !== null && strtolower((string) $r['user_id']) !== strtolower($userId)) {
            continue;
        }
        if ($groupIds !== null && !in_array((string) $r['group_id'], $groupIds, true)) {
            continue;
        }
        $metadata = $r['metadata'];
        if (is_string($metadata)) {
            $decoded = json_decode($metadata, true);
            $metadata = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($metadata)) {
            $metadata = [];
        }
        $date = substr((string) $r['activity_timestamp'], 0, 10);
        $status = (string) ($metadata['validation_status'] ?? 'Tercatat');
        if (($metadata['entry_mode'] ?? '') === 'manual_test') {
            $status = 'Catatan manual (belum diverifikasi)';
        }
        $r['metadata'] = $metadata;
        $r['title'] = $r['event_type'];
        $r['date'] = $date;
        $r['points'] = (float) $r['filtered_delta'];
        $r['status'] = $status;
        $r['user'] = $r['user_name'] ?? 'Tidak diketahui';
        $r['detail'] = $metadata['description'] ?? '';
        $out[] = $r;
    }
    return $out;
}

function score_for_user(string $userId, ?string $groupId = null): float
{
    $sum = 0.0;
    foreach (all_telemetry($userId) as $row) {
        if ($groupId === null || strtolower((string) $row['group_id']) === strtolower($groupId)) {
            $sum += (float) $row['filtered_delta'];
        }
    }
    return round(min(100.0, $sum), 1);
}

// ============================================================
// TAMPILAN / LAYOUT
// ============================================================

function layout_start(string $title, string $active = 'dashboard'): void
{
    $user = require_login();
    $flash = take_flash();
    $teacher = is_teacher($user);

    $nav = [
        'dashboard' => ['▦', 'Dashboard', 'dashboard.php'],
        'contributions' => ['◈', 'Kontribusi Saya', 'contributions.php'],
        'teams' => ['♧', 'Tim & Kelompok', 'teams.php'],
        'classroom' => ['▤', 'Monitor Kelas', 'classroom.php'],
        'tasks' => ['☷', 'WBS & Tugas', 'tasks.php'],
        'audit' => ['⌁', 'Audit & Telemetry', 'audit.php'],
        'reports' => ['▤', 'Laporan Audit', 'reports.php'],
        'settings' => ['⚙', 'Pengaturan', 'settings.php'],
    ];

    echo '<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . e($title) . ' · FairShare</title><script>(function(){try{var t=localStorage.getItem("fairshare-theme");if(t)document.documentElement.dataset.theme=t;}catch(e){}})();</script><link rel="stylesheet" href="assets/style.css?v=5"><script defer src="assets/app.js?v=5"></script></head><body>';
    echo '<aside class="sidebar" id="sidebar"><a class="brand" href="dashboard.php"><span class="brand-mark brand-logo"><img src="assets/fairshare-logo.svg" alt=""></span><span>FairShare<small>COLLABORATION INTELLIGENCE</small></span></a><div class="nav-caption">WORKSPACE</div><nav class="nav">';
    foreach ($nav as $key => $item) {
        if ($key === 'classroom' && !$teacher) {
            continue;
        }
        echo '<a class="' . ($active === $key ? 'active' : '') . '" href="' . $item[2] . '"><span class="nav-icon">' . $item[0] . '</span><span>' . $item[1] . '</span>' . ($active === $key ? '<i></i>' : '') . '</a>';
    }
    echo '</nav><div class="sidebar-bottom"><div class="workspace-card"><span class="workspace-icon">✦</span><div><b>FairShare Academic</b><small>' . ($teacher ? 'Portal Dosen' : 'Portal Mahasiswa') . '</small></div><span class="online-dot"></span></div><div class="profile-mini"><span class="avatar">' . e(mb_strtoupper(mb_substr($user['name'], 0, 1))) . '</span><div><b>' . e($user['name']) . '</b><small>' . e(ucfirst((string) ($user['role'] ?? 'mahasiswa'))) . '</small></div></div></div></aside>';
    echo '<div class="app-shell"><header class="topbar"><div class="topbar-left"><button class="mobile-menu" data-menu-toggle aria-label="Buka menu">☰</button><div><div class="breadcrumb">FAIRSHARE <span>/</span> ' . e(strtoupper($title)) . '</div><div class="topbar-sub">' . ($teacher ? 'Portal Dosen Pengampu & Monitoring Kolaborasi' : 'Workspace kontribusi dan transparansi kolaborasi mahasiswa') . '</div></div></div><div class="topbar-actions"><span class="system-status"><i></i> Sistem aktif</span>
    <div class="notification-wrap" data-notification-root data-csrf="' . e(csrf_token()) . '">
    <button class="icon-btn notification-trigger" type="button" data-notification-toggle aria-label="Buka notifikasi" aria-expanded="false" aria-controls="notificationPanel">
        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/>
            <path d="M10 21h4"/>
        </svg>
        <span class="notification-badge" data-notification-count hidden>0</span>
    </button>
    <section class="notification-panel" id="notificationPanel" data-notification-panel aria-label="Daftar notifikasi" hidden>
        <div class="notification-head">
            <div>
                <strong>Notifikasi</strong>
                <small>Pesan terbaru untuk kamu</small>
            </div>
            <button type="button" class="notification-read-all" data-notification-read-all>
                Tandai semua dibaca
            </button>
        </div>
        <div class="notification-list" data-notification-list>
            <div class="notification-loading">Memuat notifikasi...</div>
        </div>
        <div class="notification-footer">Notifikasi akun FairShare</div>
    </section>
</div>
    <div class="profile-menu" data-profile-menu>
        <button class="profile-trigger"
                type="button"
                data-profile-toggle
                aria-haspopup="menu"
                aria-expanded="false"
                aria-controls="profilePanel"
                title="Menu profil">
            <span class="avatar avatar-top">' . e(mb_strtoupper(mb_substr($user['name'], 0, 1))) . '</span>
            <span class="top-user">
                <b>' . e($user['name']) . '</b>
                <small>' . ($teacher ? 'Dosen' : 'Mahasiswa') . '</small>
            </span>
            <span class="profile-caret" aria-hidden="true">▾</span>
        </button>
        <div class="profile-dropdown"
             id="profilePanel"
             data-profile-panel
             role="menu"
             aria-label="Menu akun"
             hidden>
            <div class="profile-head">
                <span class="avatar avatar-popup">' . e(mb_strtoupper(mb_substr($user['name'], 0, 1))) . '</span>
                <div class="profile-meta">
                    <strong class="profile-name">' . e($user['name']) . '</strong>
                    ' . (!empty($user['email']) ? '<span class="profile-email">' . e($user['email']) . '</span>' : '') . '
                    <span class="profile-role-badge">' . ($teacher ? 'Dosen' : 'Mahasiswa') . '</span>
                </div>
            </div>
            <div class="profile-divider"></div>
            <nav class="profile-nav">
                <a class="profile-item" role="menuitem" href="settings.php#akun">
                    <span class="profile-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                            <circle cx="12" cy="7" r="4"/>
                        </svg>
                    </span>
                    <span>Manage account</span>
                </a>
                ' . ($teacher ? '
                <a class="profile-item" role="menuitem" href="audit.php">
                    <span class="profile-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>
                        </svg>
                    </span>
                    <span>Activity</span>
                </a>' : '
                <a class="profile-item" role="menuitem" href="contributions.php">
                    <span class="profile-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>
                        </svg>
                    </span>
                    <span>Activity</span>
                </a>') . '
                <a class="profile-item" role="menuitem" href="settings.php#preferensi">
                    <span class="profile-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="3"/>
                            <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>
                        </svg>
                    </span>
                    <span>Pengaturan</span>
                </a>
                <button type="button" class="profile-item profile-theme-toggle" data-theme-toggle role="menuitem" aria-label="Ganti tema">
                    <span class="profile-icon theme-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
                        </svg>
                    </span>
                    <span class="theme-label">Mode gelap</span>
                    <span class="theme-switch" aria-hidden="true"><span class="theme-switch-thumb"></span></span>
                </button>
            </nav>
            <div class="profile-divider"></div>
            <div class="profile-foot">
                <a class="profile-item danger" role="menuitem" href="logout.php">
                    <span class="profile-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                            <polyline points="16 17 21 12 16 7"/>
                            <line x1="21" y1="12" x2="9" y2="12"/>
                        </svg>
                    </span>
                    <span>Keluar</span>
                </a>
            </div>
        </div>
    </div>
    </div></header><main class="main-content">';
    if ($flash) {
        echo '<div class="flash ' . e($flash['type']) . '" data-flash>' . e($flash['message']) . '</div>';
    }
}

function layout_end(): void
{
    echo '</main><footer class="footer"><span>© ' . date('Y') . ' FairShare · Built for transparent collaboration</span><span>Privasi terjaga <b>·</b> Aktivitas berbasis bukti</span></footer></div></body></html>';
}

function page_heading(string $eyebrow, string $title, string $desc, string $action = ''): void
{
    echo '<div class="page-heading"><div><div class="eyebrow">' . e($eyebrow) . '</div><h1>' . e($title) . '</h1><p>' . e($desc) . '</p></div>' . $action . '</div>';
}

function button(string $label, string $href, string $kind = 'secondary'): string
{
    return '<a class="btn ' . $kind . '" href="' . e($href) . '">' . $label . '</a>';
}

function format_date(string $date): string
{
    $ts = strtotime($date);
    return $ts ? date('d M Y', $ts) : $date;
}
