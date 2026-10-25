<?php
/**
 * config/zetaris.php
 * ---------------------------------------------------------------------------
 * Integrasi Zetaris Data Virtualization REST API untuk Sistem Presensi.
 * Menghubungkan aplikasi Presensi (PHP) langsung ke layer virtualisasi Zetaris.
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);

/* Baca .env secara ringan bila hosting belum mengekspor variabel environment.
 * API key tidak pernah punya fallback di source code. */
if (!function_exists('zetaris_env')) {
    function zetaris_env(string $key, string $fallback = ''): string
    {
        $value = getenv($key);
        if ($value !== false && trim((string) $value) !== '') return trim((string) $value);
        $file = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env';
        if (!is_readable($file)) return $fallback;
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if (str_starts_with(trim($line), '#') || strpos($line, '=') === false) continue;
            [$name, $val] = explode('=', $line, 2);
            if (trim($name) === $key) return trim($val, " \t\\\"'");
        }
        return $fallback;
    }
}
if (!defined('ZETARIS_API_KEY')) {
    define('ZETARIS_API_KEY', zetaris_env('ZETARIS_API_KEY'));
}
if (!defined('ZETARIS_REST_URL')) {
    define('ZETARIS_REST_URL', zetaris_env('ZETARIS_REST_URL', 'http://127.0.0.1:8888/api/v1.0'));
}
if (!defined('ZETARIS_ORG_ID')) {
    define('ZETARIS_ORG_ID', zetaris_env('ZETARIS_ORG_ID', '1'));
}

/**
 * Generate standard UUID v4 string.
 */
function zetaris_uuid(): string
{
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // set version to 0100
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // set bits 6-7 to 10
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

/**
 * Menjalankan SQL kueri ke Zetaris Data Virtualization engine.
 *
 * @param string $sql   Kueri SQL (misal: "SELECT * FROM presensi_db.attendance LIMIT 10")
 * @param int    $limit Batas maksimal baris data
 * @return array Hasil data
 */
function zetaris_query(string $sql, int $limit = 100): array
{
    $url = rtrim(ZETARIS_REST_URL, '/') . '/sql-editor/sqls/run';
    $payload = json_encode([
        'queryId' => zetaris_uuid(),
        'sql'     => $sql,
        'source'  => 'SqlEditor',
        'limit'   => $limit,
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . ZETARIS_API_KEY,
            'X-Org-ID: ' . ZETARIS_ORG_ID,
            'X-Request-ID: ' . zetaris_uuid(),
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
    ]);

    $res = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($res === false || $err !== '') {
        throw new RuntimeException('Koneksi ke Zetaris gagal: ' . $err);
    }
    if ($httpCode >= 400) {
        throw new RuntimeException("Zetaris HTTP error {$httpCode}: " . substr((string) $res, 0, 200));
    }

    $data = json_decode((string) $res, true);
    if (!is_array($data)) {
        return [];
    }

    if (isset($data['data']) && is_array($data['data'])) {
        $rows = $data['data'];
        $cols = $data['columns'] ?? $data['columnNames'] ?? [];
        if (!empty($cols) && !empty($rows) && is_array($rows[0])) {
            $assoc = [];
            foreach ($rows as $r) {
                if (count($cols) === count($r)) {
                    $assoc[] = array_combine($cols, $r);
                } else {
                    $assoc[] = $r;
                }
            }
            return $assoc;
        }
        return $rows;
    }
    if (isset($data['rows']) && is_array($data['rows'])) {
        return $data['rows'];
    }

    return $data;
}

/**
 * Cek status kesehatan koneksi ke Zetaris.
 */
function zetaris_health(): bool
{
    try {
        $res = zetaris_query('SELECT 1 AS health', 1);
        return !empty($res);
    } catch (Throwable $e) {
        error_log('[ZETARIS] Health check failed: ' . $e->getMessage());
        return false;
    }
}
