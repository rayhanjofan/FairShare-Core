<?php
require_once __DIR__ . '/config.php';

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
function go(string $url): never
{
    header('Location: ' . $url);
    exit;
}
function logged_in(): bool
{
    return isset($_SESSION['user']);
}
function require_user(): void
{
    if (!logged_in())
        go('login.php');
}
function user_data(): array
{
    return $_SESSION['user'] ?? ['name' => 'Fakhrur', 'email' => 'user@polinema.ac.id'];
}
function money_idr($amount): string
{
    return 'Rp ' . number_format((float) $amount, 0, ',', '.');
}
function store_read(string $file, array $fallback = []): array
{
    $path = DATA_PATH . '/' . $file;
    if (!file_exists($path)) {
        file_put_contents($path, json_encode($fallback, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
        return $fallback;
    }
    $data = json_decode((string) file_get_contents($path), true);
    return is_array($data) ? $data : $fallback;
}
function store_write(string $file, array $data): bool
{
    return file_put_contents(DATA_PATH . '/' . $file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX) !== false;
}
function csrf(): string
{
    if (empty($_SESSION['csrf']))
        $_SESSION['csrf'] = bin2hex(random_bytes(24));
    return $_SESSION['csrf'];
}
function valid_csrf(): bool
{
    return isset($_POST['csrf'], $_SESSION['csrf']) && hash_equals($_SESSION['csrf'], (string) $_POST['csrf']);
}
function flash_set(string $message, string $type = 'success'): void
{
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
}
function flash_get(): ?array
{
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

function seed_data(): void
{
    $users = store_read('users.json', []);
    if (!$users) {
        $users = [
            [
                'id' => 1,
                'name' => 'Fakhrur',
                'email' => 'demo@fairshare.local',
                'password' => password_hash('demo12345', PASSWORD_DEFAULT)
            ]
        ];
        store_write('users.json', $users);
    }
    store_read('contributions.json', [
        ['id' => 1, 'user_id' => 1, 'title' => 'Integrasi GitHub API', 'type' => 'Commit', 'date' => date('Y-m-d'), 'status' => 'Terverifikasi', 'points' => 18],
        ['id' => 2, 'user_id' => 1, 'title' => 'Dokumentasi endpoint', 'type' => 'Dokumen', 'date' => date('Y-m-d', strtotime('-1 day')), 'status' => 'Terverifikasi', 'points' => 12],
        ['id' => 3, 'user_id' => 1, 'title' => 'Perbaikan UI dashboard', 'type' => 'Pull request', 'date' => date('Y-m-d', strtotime('-2 days')), 'status' => 'Diproses', 'points' => 9],
    ]);
    store_read('settings.json', ['notifications_deadline' => true, 'notifications_wei' => true, 'github_owner' => '', 'github_repo' => '', 'github_token' => '', 'google_client_id' => '', 'google_client_secret' => '']);
    store_read('team.json', [
        ['id' => 1, 'name' => 'Anggota A', 'role' => 'Ketua', 'wei' => 82, 'consistency' => '8/8', 'status' => 'Aktif'],
        ['id' => 2, 'name' => 'Fakhrur', 'role' => 'Anggota', 'wei' => 74, 'consistency' => '6/8', 'status' => 'Aktif'],
        ['id' => 3, 'name' => 'Anggota B', 'role' => 'Anggota', 'wei' => 69, 'consistency' => '5/8', 'status' => 'Aktif'],
        ['id' => 4, 'name' => 'Anggota C', 'role' => 'Anggota', 'wei' => 61, 'consistency' => '5/8', 'status' => 'Perlu pemantauan']
    ]);
    store_read('activities.json', [
        ['title' => 'Commit GitHub terdeteksi', 'detail' => 'Integrasi GitHub API', 'time' => 'Hari ini · 10:54', 'status' => 'Terverifikasi'],
        ['title' => 'Revisi dokumen masuk', 'detail' => 'Dokumentasi endpoint', 'time' => 'Kemarin · 16:22', 'status' => 'Terverifikasi'],
        ['title' => 'Tugas diperbarui', 'detail' => 'Perbaikan UI dashboard', 'time' => '2 hari lalu', 'status' => 'Diproses'],
    ]);
}
seed_data();
