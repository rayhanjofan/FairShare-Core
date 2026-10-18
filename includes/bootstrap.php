<?php
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE)
    session_start();
define('APP_NAME', 'FairShare');
define('BASE_PATH', dirname(__DIR__));
define('DATA_PATH', BASE_PATH . '/data');
if (!is_dir(DATA_PATH))
    mkdir(DATA_PATH, 0775, true);

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
    if (!is_file($path))
        return $fallback;
    $raw = file_get_contents($path);
    $data = json_decode($raw ?: '', true);
    return is_array($data) ? $data : $fallback;
}
function write_data(string $file, array $data): bool
{
    $path = DATA_PATH . '/' . basename($file);
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false)
        return false;
    return file_put_contents($path, $json . PHP_EOL, LOCK_EX) !== false;
}
/** Read a single key from data/.env without exposing the full configuration. */
function app_env(string $key, string $default = ''): string
{
    $envFile = DATA_PATH . '/.env';
    if (!is_file($envFile))
        return $default;
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '='))
            continue;
        [$name, $value] = explode('=', $line, 2);
        if (trim($name) === $key)
            return trim(trim($value), " \t\n\r\0\x0B\"'");
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
    if (!$pdo)
        throw new RuntimeException('Database belum dikonfigurasi. Salin data/.env.example menjadi data/.env dan isi kredensial Supabase.');
    return $pdo;
}
function current_user(): ?array
{
    return isset($_SESSION['user']) && is_array($_SESSION['user']) ? $_SESSION['user'] : null;
}
function require_login(): array
{
    $user = current_user();
    if (!$user)
        redirect('login.php');
    return $user;
}
function csrf_token(): string
{
    if (empty($_SESSION['csrf']))
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
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
    array_unshift($logs, ['id' => bin2hex(random_bytes(5)), 'title' => $title, 'detail' => $detail, 'type' => $type, 'time' => date('Y-m-d H:i:s'), 'user' => current_user()['name'] ?? 'Sistem']);
    write_data('activity.json', array_slice($logs, 0, 500));
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
function all_groups(): array
{
    $pdo = require_db();
    $rows = $pdo->query("SELECT g.id::text AS id, g.created_by::text AS created_by, g.name, g.class_name, g.duration_weeks, g.github_repo_url, g.gdocs_file_id, g.created_at::text AS created_at, g.updated_at::text AS updated_at, u.name AS creator_name FROM public.groups g LEFT JOIN public.users u ON u.id=g.created_by ORDER BY g.created_at DESC")->fetchAll();
    $byId = [];
    foreach ($rows as $r) {
        $r['course'] = $r['class_name'] ?: 'PBL';
        $r['invite_code'] = $r['id']; // Schema uses the group UUID as the invite code; no extra table/column required.
        $r['member_ids'] = [];
        $r['members'] = [];
        $byId[strtolower($r['id'])] = $r;
    }
    if (!$byId)
        return [];
    $memberRows = $pdo->query("SELECT gm.group_id::text AS group_id, gm.user_id::text AS user_id, gm.role_in_group, gm.peer_approval, gm.joined_at::text AS joined_at, u.name, u.email FROM public.group_members gm JOIN public.users u ON u.id=gm.user_id ORDER BY gm.joined_at ASC")->fetchAll();
    foreach ($memberRows as $m) {
        $key = strtolower($m['group_id']);
        if (!isset($byId[$key]))
            continue;
        $byId[$key]['member_ids'][] = (string) $m['user_id'];
        $byId[$key]['members'][] = [
            'user_id' => (string) $m['user_id'],
            'name' => $m['name'],
            'email' => $m['email'],
            'role_in_group' => $m['role_in_group'],
            'role' => ucfirst($m['role_in_group']),
            'peer_approval' => (bool) $m['peer_approval'],
            'joined_at' => $m['joined_at']
        ];
    }
    return array_values($byId);
}
function user_groups(string $userId): array
{
    $uid = strtolower($userId);
    return array_values(array_filter(all_groups(), function ($g) use ($uid) {
        if (strtolower((string) $g['created_by']) === $uid)
            return true;
        foreach (($g['member_ids'] ?? []) as $id)
            if (strtolower((string) $id) === $uid)
                return true;
        return false;
    }));
}
function visible_groups(array $user): array
{
    return is_teacher($user) ? all_groups() : user_groups((string) $user['id']);
}
function group_invite_code(array $group): string
{
    return (string) ($group['invite_code'] ?? $group['id'] ?? '');
}
function can_manage_group(array $group, array $user): bool
{
    if (is_teacher($user) || strtolower((string) ($group['created_by'] ?? '')) === strtolower((string) ($user['id'] ?? '')))
        return true;
    foreach (($group['members'] ?? []) as $m) {
        if (strtolower((string) ($m['user_id'] ?? '')) === strtolower((string) ($user['id'] ?? '')) && ($m['role_in_group'] ?? $m['role'] ?? '') === 'ketua')
            return true;
    }
    return false;
}
function group_by_id(string $id): ?array
{
    if (!valid_uuid($id))
        return null;
    $key = strtolower($id);
    foreach (all_groups() as $g)
        if (strtolower((string) $g['id']) === $key)
            return $g;
    return null;
}
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
    $sql = "SELECT t.id::text AS id, t.group_id::text AS group_id, t.assigned_user_id::text AS assigned_user_id, t.title, t.description, t.weight, t.status, t.target_week, t.completed_at::text AS completed_at, t.created_at::text AS created_at, t.updated_at::text AS updated_at, g.name AS group_name, u.name AS assigned_user_name FROM public.tasks t JOIN public.groups g ON g.id=t.group_id LEFT JOIN public.users u ON u.id=t.assigned_user_id ORDER BY t.created_at DESC";
    $rows = require_db()->query($sql)->fetchAll();
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
    // Existing Supabase schema stores the event label inside metadata JSONB (no event_type column).
    $sql = "SELECT tl.id::text AS id, tl.group_id::text AS group_id, tl.user_id::text AS user_id, tl.source, COALESCE(NULLIF(tl.metadata->>'event_type',''), NULLIF(tl.metadata->>'title',''), CASE WHEN tl.source='github' THEN 'Aktivitas GitHub' WHEN tl.source='gdocs' THEN 'Aktivitas Google Docs' ELSE 'Aktivitas' END) AS event_type, tl.external_id, tl.raw_delta, tl.filtered_delta, tl.activity_timestamp::text AS activity_timestamp, tl.created_at::text AS created_at, tl.metadata, u.name AS user_name, g.name AS group_name FROM public.telemetry_logs tl LEFT JOIN public.users u ON u.id=tl.user_id JOIN public.groups g ON g.id=tl.group_id ORDER BY tl.activity_timestamp DESC";
    $rows = require_db()->query($sql)->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        if ($userId !== null && strtolower((string) $r['user_id']) !== strtolower($userId))
            continue;
        if ($groupIds !== null && !in_array((string) $r['group_id'], $groupIds, true))
            continue;
        $metadata = $r['metadata'];
        if (is_string($metadata)) {
            $decoded = json_decode($metadata, true);
            $metadata = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($metadata))
            $metadata = [];
        $date = substr((string) $r['activity_timestamp'], 0, 10);
        $status = (string) ($metadata['validation_status'] ?? 'Tercatat');
        if (($metadata['entry_mode'] ?? '') === 'manual_test')
            $status = 'Catatan manual (belum diverifikasi)';
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
    foreach (all_telemetry($userId) as $row)
        if ($groupId === null || strtolower((string) $row['group_id']) === strtolower($groupId))
            $sum += (float) $row['filtered_delta'];
    // This is an activity indicator capped at 100, not the final validated WEI formula.
    return round(min(100.0, $sum), 1);
}
function layout_start(string $title, string $active = 'dashboard'): void
{
    $user = require_login();
    $flash = take_flash();
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
    echo '<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . e($title) . ' · FairShare</title><link rel="stylesheet" href="assets/style.css?v=2"><script defer src="assets/app.js"></script></head><body>';
    echo '<aside class="sidebar" id="sidebar"><a class="brand" href="dashboard.php"><span class="brand-mark brand-logo"><img src="assets/fairshare-logo.svg" alt=""></span><span>FairShare<small>COLLABORATION INTELLIGENCE</small></span></a><div class="nav-caption">WORKSPACE</div><nav class="nav">';
    foreach ($nav as $key => $item) {
        if ($key === 'classroom' && !is_teacher($user))
            continue;
        echo '<a class="' . ($active === $key ? 'active' : '') . '" href="' . $item[2] . '"><span class="nav-icon">' . $item[0] . '</span><span>' . $item[1] . '</span>' . ($active === $key ? '<i></i>' : '') . '</a>';
    }
    echo '</nav><div class="sidebar-bottom"><div class="workspace-card"><span class="workspace-icon">✦</span><div><b>FairShare Project</b><small>Semester Genap · 2026</small></div><span class="online-dot"></span></div><div class="profile-mini"><span class="avatar">' . e(mb_strtoupper(mb_substr($user['name'], 0, 1))) . '</span><div><b>' . e($user['name']) . '</b><small>' . e(ucfirst((string) ($user['role'] ?? 'mahasiswa'))) . '</small></div><a href="settings.php" title="Pengaturan">···</a></div></div></aside>';
    echo '<div class="app-shell"><header class="topbar"><div class="topbar-left"><button class="mobile-menu" data-menu-toggle aria-label="Buka menu">☰</button><div><div class="breadcrumb">FAIRSHARE <span>/</span> ' . e(strtoupper($title)) . '</div><div class="topbar-sub">Workspace kontribusi dan transparansi kolaborasi</div></div></div><div class="topbar-actions"><span class="system-status"><i></i> Sistem aktif</span><button class="theme-toggle" type="button" data-theme-toggle aria-label="Ganti tema" title="Ganti tema"><span class="theme-icon">☾</span><span class="theme-label">Dark mode</span></button><button class="icon-btn" data-toast="Tidak ada notifikasi baru" aria-label="Notifikasi">♧<em></em></button><span class="avatar avatar-top">' . e(mb_strtoupper(mb_substr($user['name'], 0, 1))) . '</span><div class="top-user"><b>' . e($user['name']) . '</b><small>' . e(ucfirst((string) ($user['role'] ?? 'mahasiswa'))) . '</small></div></div></header><main class="main-content">';
    if ($flash)
        echo '<div class="flash ' . e($flash['type']) . '" data-flash>' . e($flash['message']) . '</div>';
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
