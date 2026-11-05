<?php
declare(strict_types=1);
require_once __DIR__ . '/google_service.php';
$user = require_login();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed.');
}
verify_csrf();
$stmt = require_db()->prepare("DELETE FROM public.user_integrations WHERE user_id = CAST(:user_id AS UUID) AND provider = 'google'");
$stmt->execute(['user_id' => $user['id']]);
flash('Koneksi akun Google pada FairShare telah diputus.');
redirect('../../integrations.php');
