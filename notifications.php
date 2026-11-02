<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

function notification_response(int $status, array $data): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$user = current_user();

if (!$user || !valid_uuid((string) ($user['id'] ?? ''))) {
    notification_response(401, [
        'ok' => false,
        'message' => 'Silakan login kembali.'
    ]);
}

$userId = (string) $user['id'];

try {
    $pdo = require_db();

    /*
     * GET: mengambil daftar notifikasi milik pengguna.
     */
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $stmt = $pdo->prepare("
            SELECT
                n.id::text AS id,
                n.title,
                n.message,
                n.category,
                n.is_read,
                n.created_at::text AS created_at,
                n.read_at::text AS read_at,
                COALESCE(u.name, 'FairShare') AS sender_name
            FROM public.notifications n
            LEFT JOIN public.users u ON u.id = n.sender_id
            WHERE n.recipient_id = CAST(:user_id AS UUID)
            ORDER BY n.created_at DESC
            LIMIT 30
        ");

        $stmt->execute(['user_id' => $userId]);
        $items = $stmt->fetchAll();
        foreach ($items as &$item) {
            $item['is_read'] = in_array(
                strtolower((string) $item['is_read']),
                ['1', 't', 'true'],
                true
            );
        }
        unset($item);

        $countStmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM public.notifications
            WHERE recipient_id = CAST(:user_id AS UUID)
              AND is_read = FALSE
        ");

        $countStmt->execute(['user_id' => $userId]);

        notification_response(200, [
            'ok' => true,
            'items' => $items,
            'unread_count' => (int) $countStmt->fetchColumn()
        ]);
    }

    /*
     * POST: menandai notifikasi sebagai sudah dibaca.
     */
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $csrf = (string) ($_POST['csrf'] ?? '');

        if ($csrf === '' || !hash_equals(csrf_token(), $csrf)) {
            notification_response(419, [
                'ok' => false,
                'message' => 'Sesi kedaluwarsa. Muat ulang halaman.'
            ]);
        }

        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'mark_read') {
            $id = (string) ($_POST['id'] ?? '');

            if (!valid_uuid($id)) {
                notification_response(422, [
                    'ok' => false,
                    'message' => 'ID notifikasi tidak valid.'
                ]);
            }

            $stmt = $pdo->prepare("
                UPDATE public.notifications
                SET is_read = TRUE,
                    read_at = COALESCE(read_at, CURRENT_TIMESTAMP)
                WHERE id = CAST(:id AS UUID)
                  AND recipient_id = CAST(:user_id AS UUID)
            ");

            $stmt->execute([
                'id' => $id,
                'user_id' => $userId
            ]);

            notification_response(200, ['ok' => true]);
        }

        if ($action === 'mark_all_read') {
            $stmt = $pdo->prepare("
                UPDATE public.notifications
                SET is_read = TRUE,
                    read_at = COALESCE(read_at, CURRENT_TIMESTAMP)
                WHERE recipient_id = CAST(:user_id AS UUID)
                  AND is_read = FALSE
            ");

            $stmt->execute(['user_id' => $userId]);

            notification_response(200, ['ok' => true]);
        }

        notification_response(400, [
            'ok' => false,
            'message' => 'Aksi notifikasi tidak dikenal.'
        ]);
    }

    notification_response(405, [
        'ok' => false,
        'message' => 'Metode request tidak didukung.'
    ]);

} catch (Throwable $e) {
    error_log('FairShare notifications: ' . $e->getMessage());

    notification_response(500, [
        'ok' => false,
        'message' => 'Notifikasi belum dapat dimuat. Periksa koneksi database.'
    ]);
}