<?php
declare(strict_types=1);
/** PDO PostgreSQL connection to Supabase. Values are loaded only from data/.env. */
function fairshare_db(): ?PDO
{
    static $connection = false;
    if ($connection instanceof PDO)
        return $connection;
    if ($connection === null)
        return null;
    $envFile = __DIR__ . '/.env';
    if (!is_file($envFile)) {
        $connection = null;
        return null;
    }
    $cfg = [];
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '='))
            continue;
        [$key, $value] = explode('=', $line, 2);
        $cfg[trim($key)] = trim(trim($value), " \t\n\r\0\x0B\"'");
    }
    $host = $cfg['DB_HOST'] ?? '';
    $port = $cfg['DB_PORT'] ?? '5432';
    $dbname = $cfg['DB_NAME'] ?? 'postgres';
    $user = $cfg['DB_USER'] ?? '';
    $pass = $cfg['DB_PASSWORD'] ?? '';
    if ($host === '' || $user === '' || $pass === '') {
        $connection = null;
        return null;
    }
    if (!extension_loaded('pdo_pgsql'))
        throw new RuntimeException('Ekstensi PHP pdo_pgsql belum aktif. Aktifkan ekstensi pada php.ini lalu restart Apache.');
    try {
        $dsn = "pgsql:host={$host};port={$port};dbname={$dbname};sslmode=require;connect_timeout=8";
        $connection = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 8,
        ]);
        return $connection;
    } catch (PDOException $e) {
        $connection = null;
        error_log('FairShare PostgreSQL connection failed: ' . $e->getMessage());
        throw new RuntimeException('Koneksi Supabase gagal. Periksa host, username pooler, password database, jaringan, dan ekstensi pdo_pgsql.');
    }
}
function fairshare_db_status(): string
{
    try {
        return fairshare_db() ? 'connected' : 'not_configured';
    } catch (Throwable $e) {
        return 'error';
    }
}
