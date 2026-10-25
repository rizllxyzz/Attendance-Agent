<?php
/**
 * api/v1/whatsapp/webhook.php
 * ---------------------------------------------------------------------------
 * WhatsApp Cloud API webhook (Meta). Menerima pesan masuk dari orang tua/wali
 * dan membalas menggunakan SmartJournal AI agent (Zetaris + Nightshift).
 *
 * Setup:
 *   1. Buat app di https://developers.facebook.com -> WhatsApp -> Cloud API
 *   2. Set Webhook URL: https://yourdomain.com/api/v1/whatsapp/webhook.php
 *   3. Set Verify Token (WHATSAPP_VERIFY_TOKEN di .env)
 *   4. Subscribe to "messages"
 *
 * Env:
 *   WHATSAPP_VERIFY_TOKEN   - token verifikasi webhook
 *   WHATSAPP_ACCESS_TOKEN   - permanent access token dari Meta
 *   WHATSAPP_PHONE_NUMBER_ID- phone number ID dari Meta
 * ---------------------------------------------------------------------------
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../config/agent-config.php';
require_once __DIR__ . '/../../../config/zetaris.php';
require_once __DIR__ . '/../../../config/database.php';

function wa_env(string $key, string $fallback = ''): string
{
    $v = getenv($key);
    if ($v !== false && trim((string) $v) !== '') return trim((string) $v);
    $file = dirname(__DIR__) . '/../../../.env';
    if (!is_readable($file)) return $fallback;
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if (str_starts_with(trim($line), '#') || strpos($line, '=') === false) continue;
        [$n, $val] = explode('=', $line, 2);
        if (trim($n) === $key) return trim($val, " \t\\\"'");
    }
    return $fallback;
}

$verifyToken = wa_env('WHATSAPP_VERIFY_TOKEN', 'presensi-verify-token');
$accessToken = wa_env('WHATSAPP_ACCESS_TOKEN');
$phoneNumberId = wa_env('WHATSAPP_PHONE_NUMBER_ID');

// --- Verification (GET) ---
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    $mode = $_GET['hub_mode'] ?? $_GET['hub.mode'] ?? '';
    $token = $_GET['hub_verify_token'] ?? $_GET['hub.verify_token'] ?? '';
    $challenge = $_GET['hub_challenge'] ?? $_GET['hub.challenge'] ?? '';
    if ($mode === 'subscribe' && hash_equals($verifyToken, (string) $token)) {
        http_response_code(200);
        echo $challenge;
        exit;
    }
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

// --- Incoming messages (POST) ---
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit;
}

$raw = file_get_contents('php://input');
$payload = json_decode((string) $raw, true);
if (!is_array($payload)) {
    http_response_code(400);
    exit;
}

// Extract message
$messageText = '';
$from = '';
foreach ($payload['entry'] ?? [] as $entry) {
    foreach ($entry['changes'] ?? [] as $change) {
        $value = $change['value'] ?? [];
        foreach ($value['messages'] ?? [] as $msg) {
            if (($msg['type'] ?? '') === 'text') {
                $messageText = trim((string) ($msg['text']['body'] ?? ''));
                $from = (string) ($msg['from'] ?? '');
            }
        }
    }
}

if ($messageText === '' || $from === '') {
    http_response_code(200);
    echo json_encode(['status' => 'no_message']);
    exit;
}

// Build question with context for parent
$question = $messageText;

// Try to call agent (reuse ask.php logic via internal include is heavy; do lightweight)
// We'll call the internal agent endpoint via curl to keep consistent.
$agentUrl = 'http://127.0.0.1/api/v1/agent/ask.php';
// Since this runs on same server, use localhost
$agentUrl = 'http://127.0.0.1' . dirname($_SERVER['SCRIPT_NAME']) . '/../agent/ask.php';
$agentUrl = 'http://127.0.0.1/api/v1/agent/ask.php';

$ch = curl_init($agentUrl);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode(['question' => $question]),
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
]);
$res = curl_exec($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$answer = 'Maaf, terjadi kesalahan. Coba lagi.';
if ($res !== false && $httpCode === 200) {
    $data = json_decode((string) $res, true);
    if (is_array($data)) {
        $answer = (string) ($data['answer'] ?? $data['data']['answer'] ?? $answer);
    }
}
// Trim for WhatsApp (max 4096 chars)
$answer = mb_substr($answer, 0, 4000);

// Send reply via WhatsApp Cloud API
if ($accessToken !== '' && $phoneNumberId !== '') {
    $sendUrl = "https://graph.facebook.com/v18.0/{$phoneNumberId}/messages";
    $sendPayload = [
        'messaging_product' => 'whatsapp',
        'to' => $from,
        'type' => 'text',
        'text' => ['body' => $answer],
    ];
    $ch = curl_init($sendUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($sendPayload),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $accessToken,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    curl_exec($ch);
    curl_close($ch);
}

http_response_code(200);
echo json_encode(['status' => 'ok']);