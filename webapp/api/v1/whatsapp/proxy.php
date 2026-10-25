<?php
/**
 * api/v1/whatsapp/proxy.php
 * ---------------------------------------------------------------------------
 * Proxy ke Evolution API (hindari CORS). Admin panel memanggil endpoint ini,
 * server PHP yang meneruskan ke Evolution API.
 * ---------------------------------------------------------------------------
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../auth/session.php';
require_once __DIR__ . '/../../../config/agent-config.php';

if (!current_user() || (current_user()['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

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

$evolutionUrl = wa_env('EVOLUTION_URL', 'http://localhost:8081');
// Normalize: strip /manager or trailing slash
$evolutionUrl = preg_replace('#/manager/?$#', '', rtrim($evolutionUrl, '/'));
$evolutionKey = wa_env('EVOLUTION_API_KEY', 'hackathon-key');

$action = (string) ($_GET['action'] ?? '');
$instance = (string) ($_GET['instance'] ?? wa_env('EVOLUTION_INSTANCE', 'presensi'));

$allowed = ['status', 'create', 'qr', 'webhook'];
if (!in_array($action, $allowed, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid action']);
    exit;
}

$ch = curl_init();
switch ($action) {
    case 'status':
        curl_setopt($ch, CURLOPT_URL, rtrim($evolutionUrl, '/') . '/instance/connectionState/' . $instance);
        break;
    case 'create':
        curl_setopt($ch, CURLOPT_URL, rtrim($evolutionUrl, '/') . '/instance/create');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'instanceName' => $instance,
            'qrcode' => true,
            'integration' => 'WHATSAPP-BAILEYS',
        ]));
        break;
    case 'qr':
        curl_setopt($ch, CURLOPT_URL, rtrim($evolutionUrl, '/') . '/instance/connect/' . $instance);
        break;
    case 'webhook':
        $webhookUrl = wa_env('WHATSAPP_WEBHOOK_URL', 'http://web/api/v1/whatsapp/webhook.php');
        curl_setopt($ch, CURLOPT_URL, rtrim($evolutionUrl, '/') . '/webhook/set/' . $instance);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'webhook' => [
                'url' => $webhookUrl,
                'enabled' => true,
                'webhookByEvents' => true,
                'events' => ['MESSAGES_UPSERT'],
            ],
        ]));
        break;
}

curl_setopt_array($ch, [
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'apikey: ' . $evolutionKey],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
]);

$res = curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);

header('Content-Type: application/json');
if ($res === false) {
    http_response_code(502);
    echo json_encode(['error' => 'Evolution unreachable: ' . $err]);
    exit;
}
http_response_code($code >= 400 ? $code : 200);
echo $res;