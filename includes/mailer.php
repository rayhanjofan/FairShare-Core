<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

/**
 * Kirim email transaksional menggunakan Brevo REST API v3
 */
function send_brevo_email(string $toEmail, string $toName, string $subject, string $htmlContent): array
{
    $apiKey = app_env('BREVO_API_KEY', '');
    $senderEmail = app_env('BREVO_SENDER_EMAIL', '');
    $senderName = app_env('BREVO_SENDER_NAME', 'FairShare Security');

    if ($apiKey === '' || $senderEmail === '') {
        return [
            'success' => false,
            'message' => 'Konfigurasi Brevo belum lengkap. Pastikan BREVO_API_KEY dan BREVO_SENDER_EMAIL sudah diatur di data/.env.'
        ];
    }

    $payload = [
        'sender' => [
            'name'  => $senderName,
            'email' => $senderEmail,
        ],
        'to' => [
            [
                'email' => $toEmail,
                'name'  => $toName !== '' ? $toName : $toEmail,
            ]
        ],
        'subject'     => $subject,
        'htmlContent' => $htmlContent,
    ];

    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => [
            'accept: application/json',
            'api-key: ' . $apiKey,
            'content-type: application/json',
        ],
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false || $curlError !== '') {
        error_log("Brevo API cURL Error: " . $curlError);
        return [
            'success' => false,
            'message' => 'Gagal menghubungi server Brevo: ' . $curlError
        ];
    }

    $result = json_decode((string) $response, true);

    if ($httpCode >= 200 && $httpCode < 300) {
        return [
            'success'    => true,
            'message_id' => $result['messageId'] ?? '',
        ];
    }

    $apiError = $result['message'] ?? 'Kode HTTP ' . $httpCode;
    error_log("Brevo API Error ({$httpCode}): " . $apiError);

    return [
        'success' => false,
        'message' => 'Gagal mengirim email: ' . $apiError
    ];
}

/**
 * Buat template HTML email OTP FairShare yang rapi & responsif
 */
function build_otp_email_template(string $name, string $otpCode, string $purposeTitle, string $purposeDesc): string
{
    $displayName = $name !== '' ? htmlspecialchars($name, ENT_QUOTES, 'UTF-8') : 'Pengguna FairShare';
    $year = date('Y');

    return <<<HTML
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Kode Verifikasi FairShare</title>
</head>
<body style="margin:0;padding:0;background-color:#f4f5fa;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#172033;">
<table border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color:#f4f5fa;padding:30px 15px;">
  <tr>
    <td align="center">
      <table border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width:540px;background:#ffffff;border-radius:16px;box-shadow:0 4px 20px rgba(0,0,0,0.06);overflow:hidden;border:1px solid #e9eaf2;">
        <tr>
          <td style="background:linear-gradient(135deg,#5d4df4,#3c2cb8);padding:32px 30px;text-align:center;">
            <div style="font-size:26px;font-weight:900;color:#ffffff;letter-spacing:1px;">FairShare</div>
            <div style="font-size:11px;color:#d8d3ff;letter-spacing:2px;margin-top:4px;text-transform:uppercase;">Collaboration Intelligence</div>
          </td>
        </tr>
        <tr>
          <td style="padding:36px 32px;">
            <div style="font-size:18px;font-weight:700;color:#181829;margin-bottom:8px;">Halo, {$displayName} 👋</div>
            <p style="font-size:14px;color:#585b73;line-height:1.6;margin:0 0 20px;">
              Kami menerima permintaan untuk <strong>{$purposeTitle}</strong> pada akun FairShare Anda. {$purposeDesc}
            </p>
            <div style="background:#f6f5ff;border:2px dashed #6b59eb;border-radius:12px;padding:22px;text-align:center;margin:24px 0;">
              <div style="font-size:11px;font-weight:700;color:#6b59eb;text-transform:uppercase;letter-spacing:1.5px;margin-bottom:8px;">KODE VERIFIKASI OTP</div>
              <div style="font-size:36px;font-weight:900;letter-spacing:8px;color:#3b2cb8;font-family:monospace;">{$otpCode}</div>
              <div style="font-size:11px;color:#8589a1;margin-top:10px;">Berlaku selama <strong>10 menit</strong>. Jangan bagikan kode ini kepada siapa pun.</div>
            </div>
            <p style="font-size:12px;color:#8f93a8;line-height:1.6;margin:24px 0 0;">
              Jika Anda tidak merasa melakukan permintaan ini, abaikan email ini. Akun Anda tetap aman.
            </p>
          </td>
        </tr>
        <tr>
          <td style="background:#fafafc;padding:18px 30px;border-top:1px solid #f0f0f5;text-align:center;">
            <div style="font-size:11px;color:#9ca0b4;">© {$year} FairShare · Platform Transparansi Kolaborasi</div>
          </td>
        </tr>
      </table>
    </td>
  </tr>
</table>
</body>
</html>
HTML;
}

/**
 * Buat kode OTP baru di database Supabase dan kirimkan via Brevo
 */
function create_and_send_otp(string $email, string $name, string $purpose, ?array $payload = null): array
{
    $pdo = require_db();
    $email = mb_strtolower(trim($email));

    // 1. Generate 6 digit angka acak
    $otpCode = str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT);

    // 2. Nonaktifkan OTP lama yang belum terpakai untuk email & purpose ini
    $deactivate = $pdo->prepare('UPDATE public.otp_codes SET is_used = TRUE WHERE LOWER(email) = LOWER(:email) AND purpose = :purpose AND is_used = FALSE');
    $deactivate->execute([
        'email'   => $email,
        'purpose' => $purpose,
    ]);

    // 3. Simpan OTP baru dengan masa berlaku 10 menit
    $payloadJson = $payload !== null ? json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;
    $stmt = $pdo->prepare(
        'INSERT INTO public.otp_codes (id, email, otp_code, purpose, payload, expires_at, is_used, created_at) '
        . 'VALUES (gen_random_uuid(), :email, :otp, :purpose, CAST(:payload AS jsonb), CURRENT_TIMESTAMP + INTERVAL \'10 minutes\', FALSE, CURRENT_TIMESTAMP)'
    );
    $stmt->execute([
        'email'   => $email,
        'otp'     => $otpCode,
        'purpose' => $purpose,
        'payload' => $payloadJson,
    ]);

    // 4. Susun pesan email sesuai purpose
    if ($purpose === 'registration') {
        $subject = "Kode Verifikasi Pendaftaran FairShare: {$otpCode}";
        $purposeTitle = 'Pendaftaran Akun Baru';
        $purposeDesc = 'Gunakan kode verifikasi di bawah ini untuk mengaktifkan akun FairShare Anda.';
    } else {
        $subject = "Kode OTP Reset Kata Sandi FairShare: {$otpCode}";
        $purposeTitle = 'Mengatur Ulang Kata Sandi';
        $purposeDesc = 'Gunakan kode verifikasi di bawah ini untuk melanjutkan proses penggantian kata sandi akun Anda.';
    }

    $html = build_otp_email_template($name, $otpCode, $purposeTitle, $purposeDesc);

    // 5. Kirim via Brevo API
    $sendResult = send_brevo_email($email, $name, $subject, $html);

    if (!$sendResult['success']) {
        return [
            'success' => false,
            'message' => $sendResult['message'],
        ];
    }

    return [
        'success'  => true,
        'otp_code' => $otpCode,
    ];
}

/**
 * Validasi OTP yang dimasukkan user
 */
function verify_otp_code(string $email, string $otpCode, string $purpose): array
{
    $pdo = require_db();
    $email = mb_strtolower(trim($email));
    $otpCode = trim($otpCode);

    $stmt = $pdo->prepare(
        'SELECT id::text AS id, email, purpose, payload, expires_at < CURRENT_TIMESTAMP AS is_expired '
        . 'FROM public.otp_codes '
        . 'WHERE LOWER(email) = LOWER(:email) AND otp_code = :otp AND purpose = :purpose AND is_used = FALSE '
        . 'ORDER BY created_at DESC LIMIT 1'
    );
    $stmt->execute([
        'email'   => $email,
        'otp'     => $otpCode,
        'purpose' => $purpose,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return [
            'valid'   => false,
            'message' => 'Kode OTP salah atau sudah tidak aktif.',
        ];
    }

    if (!empty($row['is_expired'])) {
        return [
            'valid'   => false,
            'message' => 'Kode OTP sudah kedaluwarsa (lebih dari 10 menit). Silakan minta kode baru.',
        ];
    }

    // Tandai OTP sudah digunakan
    $mark = $pdo->prepare('UPDATE public.otp_codes SET is_used = TRUE WHERE id = CAST(:id AS uuid)');
    $mark->execute(['id' => $row['id']]);

    $payload = $row['payload'];
    if (is_string($payload)) {
        $payload = json_decode($payload, true) ?: [];
    }

    return [
        'valid'   => true,
        'payload' => is_array($payload) ? $payload : [],
    ];
}
