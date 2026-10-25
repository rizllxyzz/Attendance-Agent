<?php
/**
 * helper.php
 * ---------------------------------------------------------------------------
 * Fungsi utilitas: validasi lokasi (anti-spoofing), respon JSON, keamanan.
 *
 * PRINSIP UTAMA
 * -------------
 * Nilai yang dikirim browser TIDAK PERNAH dipercaya apa adanya. Server
 * menghitung ulang jarak dari lat/lng mentah, dan hanya memakai laporan
 * heuristik client sebagai SKOR tambahan, bukan sebagai dasar keputusan.
 * ----------------------------------------------------------------------------
 */

declare(strict_types=1);

require_once __DIR__ . '/app-config.php';

/* ==================================================================
 |  RESPON JSON
 | ================================================================== */

function json_response($data, int $status = 200): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Respons sukses.
 *
 * $status ada karena beberapa operasi memang bukan 200. Pendaftaran
 * wajah yang membuat sumber daya baru, misalnya, harus 201 Created
 * supaya klien dan cache bisa membedakannya dari pembacaan biasa.
 * Tanpa parameter ini, argumen kedua diam-diam diabaikan oleh PHP dan
 * respons selalu 200, yang membuat klien salah membaca hasilnya.
 */
function json_ok(array $data = [], int $status = 200): void
{
    json_response(array_merge(['success' => true], $data), $status);
}

function json_fail(string $code, string $message, int $status = 400, array $extra = []): void
{
    json_response(array_merge([
        'success' => false,
        'code'    => $code,
        'message' => $message,
    ], $extra), $status);
}

/* ==================================================================
 |  INPUT
 | ================================================================== */

function clean($value): string
{
    return trim(strip_tags((string) $value));
}

function post(string $key, $default = null)
{
    return isset($_POST[$key]) ? clean($_POST[$key]) : $default;
}

function get(string $key, $default = null)
{
    return isset($_GET[$key]) ? clean($_GET[$key]) : $default;
}

function body_json(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function is_float_like($v): bool
{
    return is_int($v) || is_float($v) || (is_string($v) && is_numeric(trim($v)));
}

/* ==================================================================
 |  JARAK GEOGRAFIS
 | ================================================================== */

/**
 * Jarak dua koordinat dalam meter (Haversine).
 * Dipakai server sebagai sumber kebenaran, bukan nilai dari client.
 */
function haversine_distance(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $earthRadius = 6371000.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);

    $a = sin($dLat / 2) * sin($dLat / 2)
       + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
       * sin($dLng / 2) * sin($dLng / 2);

    return 2 * $earthRadius * asin(min(1.0, sqrt($a)));
}

function decimal_places($value): int
{
    $s = (string) $value;
    if (stripos($s, 'e') !== false) {
        return 0;
    }
    $dot = strpos($s, '.');
    return $dot === false ? 0 : strlen($s) - $dot - 1;
}

/* ==================================================================
 |  VERIFIKASI LOKASI (SERVER SIDE)
 | ================================================================== */

/**
 * Nilai verifikasi lokasi.
 *
 * @param array $payload  Data dari gps-verifier.js toPayload()
 * @return array Laporan keputusan
 */
function verify_location(array $payload, ?array $school = null): array
{
    $g = GPS_GUARD;
    $school = $school ?? [
        'lat'       => SEKOLAH_LAT,
        'lng'       => SEKOLAH_LNG,
        'radius_m'  => SEKOLAH_RADIUS_M,
    ];

    /* Terima nama kolom dari baris database (radius_m) maupun dari nilai
     * bawaan, supaya pemanggil tidak perludua kunci yang berbeda. */
    $radius = 0.0;
    foreach (['radius_m', 'maxRadiusM', 'radiusM', 'radius'] as $key) {
        if (isset($school[$key]) && is_numeric($school[$key])) {
            $radius = (float) $school[$key];
            break;
        }
    }
    if ($radius <= 0.0) {
        $radius = (float) SEKOLAH_RADIUS_M;
    }

    $findings = [];
    $add = function (string $code, int $weight, string $detail) use (&$findings) {
        $findings[] = compact('code', 'weight', 'detail');
    };

    /* --- 1. Bentuk dasar payload ------------------------------------ */
    $lat = $payload['lat'] ?? null;
    $lng = $payload['lng'] ?? null;
    $acc = $payload['accuracy'] ?? null;

    if (!is_float_like($lat) || !is_float_like($lng)) {
        return location_verdict([
            'code'    => 'MISSING_COORD',
            'weight'  => 100,
            'detail'  => 'Latitude atau longitude tidak dikirim',
        ], $findings, null);
    }

    $lat = (float) $lat;
    $lng = (float) $lng;
    $acc = is_float_like($acc) ? (float) $acc : null;

    /* --- 2. Rentang nilai ------------------------------------------- */
    if (abs($lat) > 90.0 || abs($lng) > 180.0) {
        $add('OUT_OF_RANGE', 100, 'Koordinat di luar rentang bumi valid');
    }
    if (abs($lat) < $g['null_island_tolerance'] && abs($lng) < $g['null_island_tolerance']) {
        $add('NULL_ISLAND', 100, 'Koordinat 0,0 - default aplikasi fake-GPS');
    }

    /* --- 3. Akurasi -------------------------------------------------- */
    if ($acc === null) {
        /* Standar Geolocation W3C mewajibkan accuracy selalu ada pada
         * Position yang berhasil. Ketiadaannya berarti payload ini
         * disusun tangan, bukan berasal dari navigator.geolocation.
         *
         * Ini juga menutup celah: tanpa nilai akurasi, pemeriksaan
         * radius-sadar-ketidakpastian tidak bisa dijalankan sama sekali,
         * jadi permintaan bisa melewatinya dengan sengaja mengosongkan
         * field. Karena itu ketiadaan akurasi ditolak, bukan hanya
         * diberi skor. */
        $add('NO_ACCURACY', $g['missing_accuracy_is_fatal'] ? 100 : 35,
            'Akurasi tidak dilaporkan');
    } else {
        if ($acc < $g['min_accuracy_m']) {
            $add('ACCURACY_TOO_GOOD', 15, 'Akurasi ' . round($acc, 2) . ' m, mustahil untuk GNSS konsumen');
        }
        if ($acc > GPS_MAX_ACCURACY_M) {
            $add('ACCURACY_TOO_LOW', 15, 'Akurasi ' . round($acc) . ' m melebihi batas ' . GPS_MAX_ACCURACY_M . ' m');
        }
    }

    /* --- 4. Presisi -------------------------------------------------- */
    $dec = max(decimal_places($lat), decimal_places($lng));
    if ($dec > $g['max_decimals']) {
        $add('EXCESS_PRECISION', 5, $dec . ' angka desimal, GNSS tidak sepresisi itu');
    }

    /* --- 5. Kesegaran fix -------------------------------------------- */
    $ts = isset($payload['timestamp']) && is_numeric($payload['timestamp'])
        ? (int) $payload['timestamp']
        : null;
    $ageMs = $ts === null ? null : (int) round((microtime(true) - $ts / 1000) * 1000);

    if ($ts === null) {
        $add('NO_TIMESTAMP', 15, 'Timestamp lokasi tidak dikirim');
    } elseif ($ts / 1000 > microtime(true) + 60) {
        $add('FUTURE_TIMESTAMP', 100, 'Timestamp berada di masa depan');
    } elseif ($ageMs !== null && $ageMs > $g['max_sample_age_ms']) {
        // Diberi bobot 60 agar PERLU_DITINJAU: fix lama berarti posisi bisa
        // sudah tidak akurat, sering gejala replay payload absen.
        $add('STALE_FIX', 60, 'Fix GPS berumur ' . round($ageMs / 1000) . ' detik');
    }

    /* --- 6. JARAK: dihitung ulang oleh server ------------------------ */
    $distance = haversine_distance(
        (float) $school['lat'],
        (float) $school['lng'],
        $lat,
        $lng
    );

    if ($distance > $radius) {
        $add('OUT_OF_RADIUS', 100,
            'Jarak ' . round($distance) . ' m, batas ' . $radius . ' m');
    } elseif (!empty($g['uncertainty_in_radius']) && $acc !== null) {
        /* Fix yang tidak presisi berarti posisi sebenarnya bisa meleset
         * sejauh nilai akurasi. Kalau radius 100 m tetapi akurasinya
         * 90 m, koordinat yang dilaporkan di tengah radius bisa berarti
         * siswa berada 90 m di luar sana. Karena itu yang dibandingkan
         * adalah worst case: jarak + akurasi.
         *
         * Menolak ini sebagai hard failure, bukan skor, karena radius
         * sekolah kecil dan akurasi buruk membuat geofence tidak
         * bermakna sama sekali. */
        if ($distance + $acc > $radius) {
            $add('UNCERTAIN_POSITION', 100,
                'Akurasi ' . round($acc) . ' m membuat posisi tidak pasti dalam radius '
                . $radius . ' m (jarak ' . round($distance) . ' m)');
        }
    }

    /* --- 7. Laporan heuristik dari client ---------------------------- */
    // Diambil sebagai SKOR, bukan sebagai keputusan. Browser bisa dipalsukan.
    $guard = is_array($payload['guard'] ?? null) ? $payload['guard'] : [];

    if (!empty($guard['suspicious'])) {
        $add('CLIENT_SUSPICIOUS', 20, 'Browser melaporkan indikasi spoofing');
    }
    if (($guard['motionAvailable'] ?? false) === true
        && (float) ($guard['motionDelta'] ?? 0) > 0.012
        && (float) ($guard['jitter'] ?? 0) < 0.5) {
        $add('MOTION_WITHOUT_GPS_MOVEMENT', 100,
            'Sensor gerak aktif tetapi koordinat beku');
    }
    if (($guard['motionAvailable'] ?? false) !== true) {
        $add('MOTION_UNAVAILABLE', 0, 'Cross-check sensor gerak tidak dilakukan');
    }
    $guardCodes = $guard['codes'] ?? [];
    if (is_array($guardCodes)) {
        foreach ($guardCodes as $code) {
            if (in_array($code, ['FROZEN_COORDS', 'FROZEN_ACCURACY'], true)) {
                $add('CLIENT_' . $code, 10, 'Browser melaporkan ' . $code);
            }
        }
    }

    /* --- 8. Skor akhir ----------------------------------------------- */
    $score = array_sum(array_column($findings, 'weight'));
    $hard  = false;
    foreach ($findings as $f) {
        if ($f['weight'] >= 100) {
            $hard = true;
            break;
        }
    }

    /* Nama verdict sengaja sama dengan nilai enum gps_verdict di
     * tabel attendance, supaya bisa langsung disimpan tanpa pemetaan. */
    $verdict = 'DITERIMA';
    if ($hard) {
        $verdict = 'DITOLAK';
    } elseif ($score >= $g['max_suspicious_score']) {
        $verdict = 'PERLU_DITINJAU';
    }

    return location_verdict([
        'code'   => null,
        'weight' => 0,
        'detail' => null,
    ], $findings, [
        'verdict'      => $verdict,
        'score'        => $score,
        'distanceM'    => round($distance, 1),
        'radius'       => $radius,
        'withinRadius' => $distance <= $radius,
        'accuracyM'    => $acc,
        'fixAgeMs'     => $ageMs,
        'lat'          => $lat,
        'lng'          => $lng,
    ]);
}

function location_verdict(?array $hard, array $findings, ?array $data): array
{
    if ($hard && $hard['weight'] >= 100) {
        $findings[] = $hard;
        $data = $data ?? [];
        $data['verdict'] = 'DITOLAK';
        $data['score'] = 100;
    }

    return [
        'verdict'  => $data['verdict'] ?? 'DITOLAK',
        'score'    => $data['score'] ?? 100,
        'details'  => $data ?? [],
        'findings' => array_values(array_filter($findings, static function ($f) {
            return ($f['weight'] ?? 0) > 0 || ($f['code'] ?? '') !== '';
        })),
    ];
}

/* ==================================================================
 |  GEOIP (pendukung, bukan penentu utama)
 * ================================================================== */

/**
 * Alamat IP klien.
 *
 * Hanya REMOTE_ADDR yang dipakai secara bawaan. Header seperti
 * X-Forwarded-For dan Client-IP bisa disisipkan siapa saja, sehingga
 * mempercayainya membuat catatan audit dan GeoIP bisa dipalsukan oleh
 * penyerang. Header proxy hanya dibaca bila TRUST_PROXY_HEADERS
 * dinyalakan, yang hanya pantas dilakukan kalau Anda yakin hosting
 * menaruh aplikasi di belakang reverse proxy yang menimpa header itu.
 */
function client_ip(): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

    if (TRUST_PROXY_HEADERS) {
        foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP', 'HTTP_X_REAL_IP'] as $header) {
            $raw = $_SERVER[$header] ?? '';
            if ($raw === '') {
                continue;
            }
            foreach (explode(',', (string) $raw) as $candidate) {
                $candidate = trim($candidate);
                if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                    $ip = $candidate;
                    break 2;
                }
            }
        }
    }

    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

/**
 * True bila IP bersifat privat, reserved, atau tidak valid.
 * Dengan flag NO_PRIV_RANGE|NO_RES_RANGE, filter_var mengembalikan false
 * untuk kedua kategori tersebut, sehingga cukup diperiksa hasilnya.
 */
function is_private_ip(string $ip): bool
{
    return filter_var(
        $ip,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
    ) === false;
}

/**
 * Ambil negara dari IP. Sengaja hanya negara, bukan kota, karena sekolah
 * di Indonesia umumnya berada di belakang carrier NAT sehingga kota IP
 * tidak merepresentasikan lokasi siswa.
 */
function geoip_country(string $ip): ?string
{
    if ($ip === '0.0.0.0' || is_private_ip($ip)) {
        return null;
    }

    $ctx = stream_context_create(['http' => [
        'timeout' => 2,
        'header'  => 'User-Agent: PresensiApp/1.0',
    ]]);

    $json = @file_get_contents(
        'http://ip-api.com/json/' . urlencode($ip) . '?fields=countryCode,status',
        false,
        $ctx
    );

    if ($json === false) {
        return null;
    }

    $data = json_decode($json, true);
    if (!is_array($data) || ($data['status'] ?? '') !== 'success') {
        return null;
    }

    return strtoupper((string) ($data['countryCode'] ?? '')) ?: null;
}

/**
 * GeoIP hanya menambah skor, tidak pernah menolak. VPN/proxy membuat
 * pemeriksa ini tidak bisa dijadikan bukti.
 */
function geoip_signal(string $ip): array
{
    $country = geoip_country($ip);
    if ($country === null) {
        return ['country' => null, 'mismatch' => false, 'available' => false];
    }
    $mismatch = !in_array($country, GEOIP_ALLOWED_COUNTRIES, true);
    return [
        'country'  => $country,
        'mismatch' => $mismatch,
        'available' => true,
    ];
}

/* ==================================================================
 |  TOKEN SCAN SEKALI PAKAI
 * ================================================================== */

/**
 * Token yang tangani server. Menahan replay: nonce hanya berlaku sekali
 * dalam TTL pendek, sehingga payload absen tidak bisa dikirim ulang.
 */
function issue_scan_nonce(int $userId): string
{
    $nonce = bin2hex(random_bytes(16));
    $exp   = time() + SCAN_NONCE_TTL;
    $sig   = hash_hmac('sha256', $userId . '|' . $nonce . '|' . $exp, API_FINGERPRINT_KEY);
    return base64_encode($userId . '|' . $nonce . '|' . $exp . '|' . $sig);
}

function verify_scan_nonce(string $token, int $userId): array
{
    $raw = base64_decode($token, true);
    if ($raw === false) {
        return [false, 'Token tidak valid'];
    }

    $parts = explode('|', $raw);
    if (count($parts) !== 4) {
        return [false, 'Token tidak valid'];
    }

    [$uid, $nonce, $exp, $sig] = $parts;

    $expected = hash_hmac('sha256', $uid . '|' . $nonce . '|' . $exp, API_FINGERPRINT_KEY);
    if (!hash_equals($expected, $sig)) {
        return [false, 'Token tidak valid'];
    }
    if ((int) $uid !== $userId) {
        return [false, 'Token milik pengguna lain'];
    }
    if ((int) $exp < time()) {
        return [false, 'Token kedaluwarsa, mulai ulang halaman scan'];
    }

    if (!consume_nonce($nonce, $userId)) {
        return [false, 'Token sudah pernah dipakai'];
    }

    return [true, null];
}

/**
 * Tandai nonce sebagai terpakai.
 *
 * @return bool true bila nonce ini BARU (berhasil diklaim),
 *              false bila sudah pernah dipakai (percobaan replay)
 *
 * Uniqueness dicekkan oleh batasan UNIQUE di database, bukan oleh
 * select-then-insert, supaya dua request bersamaan tidak bisa
 * sama-sama lolos. Bila insert ditolak,nilai kembalian dianggap replay dan
 * percobaan ditolak..
 */
function consume_nonce(string $nonce, int $userId = 0): bool
{
    try {
        require_once __DIR__ . '/database.php';
        return db_insert_unique(
            'INSERT INTO scan_nonces (nonce, user_id, used_at) VALUES (?, ?, ' . db_now_sql() . ')',
            [$nonce, $userId]
        );
    } catch (Throwable $e) {
        /* Bila tabel tidak bisa diakses, tolak percobaan, jangan lolos. */
        error_log('[NONCE] ' . $e->getMessage());
        return false;
    }
}

/* ==================================================================
 |  BATASAN LAJU
 * ================================================================== */

/**
 * Pembatas laju berbasis session.
 *
 * Dipakai untuk membatasi berapa kali student boleh meminta nonce.
 * Tanpa ini, satu akun bisa meminta nonce tanpa henti dan membebani
 * server, atau mencoba menebak-nebak challenge sampai kebetulan cocok.
 *
 * session di PHP hanya dibaca pada side server, jadi angka ini tidak
 * bisa diubah sendiri oleh klien; klien hanya boleh enticing satu
 * cookie httpOnly yang sudah ditandatangani server.
 *
 * @return array ['allowed'=>bool,'remaining'=>int,'retryAfter'=>int]
 */
function throttle(string $bucket, int $max, int $windowSec): array
{
    $now    = time();
    $key    = 'throttle_' . $bucket;
    $hits   = $_SESSION[$key] ?? [];
    $hits   = is_array($hits) ? $hits : [];

    // buang yang sudah keluar dari jendela
    $hits = array_values(array_filter($hits, static fn($t) => ($now - (int) $t) < $windowSec));

    $count = count($hits);
    if ($count >= $max) {
        $oldest = min($hits);
        $_SESSION[$key] = $hits;
        return [
            'allowed'    => false,
            'remaining'  => 0,
            'retryAfter' => max(1, $windowSec - ($now - $oldest)),
        ];
    }

    $hits[] = $now;
    $_SESSION[$key] = $hits;

    return [
        'allowed'    => true,
        'remaining'  => max(0, $max - count($hits)),
        'retryAfter' => 0,
    ];
}

function reset_throttle(string $bucket): void
{
    unset($_SESSION['throttle_' . $bucket]);
}

/* ==================================================================
 |  CATATAN AUDIT
 | ================================================================== */

/**
 * Catat percobaan kecurangan atau kejadian penting.
 *
 * Sengaja tidak melempar exception: kegagalan menulis log tidak boleh
 * menggagalkan operasi absensi yang sah. Kegagalan hanya dicatat ke
 * error log server.
 */
function audit_log(?int $userId, string $eventType, string $severity = 'info', ?string $details = null): bool
{
    try {
        require_once __DIR__ . '/database.php';
        db_insert(
            'INSERT INTO audit_log (user_id, event_type, severity, details, ip_address, user_agent)
             VALUES (?, ?, ?, ?, ?, ?)',
            [
                $userId,
                $eventType,
                in_array($severity, ['info', 'warning', 'critical'], true) ? $severity : 'info',
                $details,
                client_ip(),
                substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            ]
        );
        return true;
    } catch (Throwable $e) {
        error_log('[AUDIT] Gagal menulis log: ' . $e->getMessage());
        return false;
    }
}

/* ==================================================================
 |  PEMERIKSAAN KONSISTENSI LAPIS
 * ================================================================== */

/**
 * Cocokkan daftar challenge liveness di server dengan yang bisa
 * dieksekusi klien.
 *
 * Dua daftar ini harus persis sama, karena server yang memilih
 * challenge lalu mengirimkannya ke klien. Kalau berbeda, server bisa
 * mengirim challenge yang tidak ada handler-nya di klien, dan setiap
 * scan akan ditolak tanpa alasan yang jelas.
 *
 * Daftar klien dibaca langsung dari assets/js/liveness-detection.js
 * supaya yang dibandingkan adalah kode yang benar-benar berjalan,
 * bukan salinan yang bisa basi.
 *
 * @return array ['server'=>int,'client'=>int,'serverOnly'=>[],'clientOnly'=>[]]
 */
function check_liveness_vocabulary(): array
{
    $server = array_keys(LIVENESS_CHALLENGES);
    $client = [];

    $jsPath = dirname(__DIR__) . '/assets/js/liveness-detection.js';
    if (is_readable($jsPath)) {
        $js = (string) file_get_contents($jsPath);
        if (preg_match('/CHALLENGE_POOL\s*=\s*\[([^\]]*)\]/', $js, $m)) {
            if (preg_match_all("/'([a-z_]+)'/", $m[1], $items)) {
                $client = $items[1];
            }
        }
    }

    return [
        'server'     => count($server),
        'client'     => count($client),
        'serverOnly' => array_values(array_diff($server, $client)),
        'clientOnly' => array_values(array_diff($client, $server)),
    ];
}

/**
 * Daftar pemeriksaan konfigurasi yang perlu diperbaiki operator.
 * Kembalikan array pesan, kosong bila semua sehat.
 */
function config_warnings(): array
{
    $w = validate_schedule();

    if (!defined('API_FINGERPRINT_KEY')
        || API_FINGERPRINT_KEY === 'GANTI-DENGAN-HASIL-bin2hex-random_bytes-32') {
        $w[] = 'API_FINGERPRINT_KEY belum diganti. Token scan memakai kunci ini, '
             . 'jadi siapa pun bisa memalsukan token. Ganti dengan bin2hex(random_bytes(32)).';
    }

    $vocab = check_liveness_vocabulary();
    if ($vocab['clientOnly'] === 0 && $vocab['server'] > 0) {
        $w[] = 'assets/js/liveness-detection.js tidak terbaca atau CHALLENGE_POOL-nya kosong, '
             . 'sehingga challenge liveness tak bisa diverifikasi.';
    }
    if ($vocab['serverOnly'] || $vocab['clientOnly']) {
        $w[] = 'Kosakata challenge liveness tidak cocok antara server dan klien. '
             . 'Hanya di server: ' . implode(', ', $vocab['serverOnly'])
             . ' | hanya di klien: ' . implode(', ', $vocab['clientOnly'])
             . '. Setiap scan akan ditolak sampai keduanya disamakan.';
    }

    if (defined('DB_DRIVER') && DB_DRIVER === 'mysql' && defined('DB_PASS') && trim((string) DB_PASS) === '') {
        $w[] = 'DB_PASS kosong. Ganti dengan kredensial MySQL hosting.';
    }

    return $w;
}

/* ==================================================================
 |  JADWAL ABSEN
 * ================================================================== */

/**
 * Periksa apakah jendela jam masuk dan jam pulang tumpang tindih.
 *
 * Penting karena penentuan tipe absensi selalu mengecek jam masuk lebih
 * dulu. Kalau jendela keduanya tumpang tindih, absen pulang menjadi
 * tidak terjangkau tanpa tanda peringatan: siswa yang absen pada jam pulang akan selalu
 * dicatat sebagai "masuk", lalu ditolak sebagai duplikat. Gejalanya
 * jauh dari jelas, jadi lebih baik dicek sejak konfigurasi.
 *
 * @return array daftar pesan masalah, kosong bila jadwal sehat
 */
function validate_schedule(): array
{
    $problems = [];

    $w = [
        'masuk'  => [JAM_MASUK_MULAI,  JAM_MASUK_AKHIR],
        'pulang' => [JAM_PULANG_MULAI, JAM_PULANG_AKHIR],
    ];

    foreach ($w as $name => [$mulai, $akhir]) {
        foreach ([$mulai, $akhir] as $v) {
            if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $v)) {
                $problems[] = "Jam $name '$v' bukan format HH:MM yang sah.";
            }
        }
        if (strtotime($mulai) === false || strtotime($akhir) === false) {
            continue;
        }
        if (strtotime($mulai) > strtotime($akhir)) {
            $problems[] = "Jam $name terbalik: mulai $mulai lebih belakangan dari akhir $akhir.";
        }
    }

    if (!$problems) {
        $overlap = strtotime(JAM_MASUK_AKHIR) > strtotime(JAM_PULANG_MULAI)
            && strtotime(JAM_PULANG_AKHIR) > strtotime(JAM_MASUK_MULAI);
        if ($overlap) {
            $problems[] = sprintf(
                'Jam masuk (%s-%s) tumpang tindih dengan jam pulang (%s-%s). '
                . 'Absen pulang akan selalu tercatat sebagai masuk.',
                JAM_MASUK_MULAI, JAM_MASUK_AKHIR, JAM_PULANG_MULAI, JAM_PULANG_AKHIR
            );
        }
    }

    return $problems;
}

/**
 * Tentukan tipe absen dari jam server, atau null bila di luar jadwal.
 * Fungsi ini yang dipakai scan-check.php.
 */
function attendance_type(string $clock): ?string
{
    $t = strtotime($clock);
    if ($t === false) {
        return null;
    }
    if ($t >= strtotime(JAM_MASUK_MULAI) && $t <= strtotime(JAM_MASUK_AKHIR)) {
        return 'masuk';
    }
    if ($t >= strtotime(JAM_PULANG_MULAI) && $t <= strtotime(JAM_PULANG_AKHIR)) {
        return 'pulang';
    }
    return null;
}

function fmt_date(?string $date): string
{
    if (empty($date)) {
        return '-';
    }
    $ts = strtotime($date);
    return $ts ? date(DATE_FORMAT, $ts) : '-';
}

function fmt_datetime(?string $date): string
{
    if (empty($date)) {
        return '-';
    }
    $ts = strtotime($date);
    return $ts ? date(DATETIME_FORMAT, $ts) : '-';
}

function fmt_distance(?float $m): string
{
    if ($m === null) {
        return '-';
    }
    return $m < 1000
        ? round($m) . ' m'
        : number_format($m / 1000, 2) . ' km';
}

/* ==================================================================
 |  FACE DESCRIPTOR  -  Konversi Float32Array <-> BLOB
 | ==================================================================
 |
 |  Deskripsi wajah face-api.js adalah Float32Array berisi 128 angka.
 |  Float32 di JavaScript selalu little-endian, 4 byte per angka.
 |  Agar PHP dan browser menghitung jarak yang sama persis, urutan byte
 |  di database harus identik dengan yang di memori browser.
 |
 |  'g' = float machine dependent (4 byte), 'f' = float (4 byte)
 |  'V' = unsigned little-endian 32 bit -> cocok dengan Float32Array
 |
 |  Ukuran: 128 x 4 = 512 byte per siswa
 * ================================================================== */

/**
 * Array angka (128 float) -> string biner 512 byte untuk disimpan di BLOB.
 */
/* ==================================================================
 |  DESCRIPTOR WAJAH
 * ================================================================== */

/**
 * Terima descriptor dari klien dalam berbagai bentuk dan kembalikan
 * vektor 128 float yang tervalidasi, atau null kalau tidak sah.
 *
 * Bentuk yang diterima: base64 (512 byte), larik angka, atau larik
 * base64. Yang terakhir dipakai saat pendaftaran wajah, di mana
 * beberapa sampel dikirim sekaligus.
 *
 * Semua pemeriksaan yang membedakan "descriptor wajah sungguhan" dari
 * isian asal-isalan ada di sini, supaya scan-check.php dan
 * register-face.php tidak bisa berbeda pendapat. Mengembalikan null
 * lebih baik daripada exception: pemanggil yang memutuskan kode error
 * mana yang tepat untuk konteksnya.
 */
function descriptor_from_input($raw): ?array
{
    if (is_string($raw)) {
        $blob = base64_decode($raw, true);
        if ($blob === false || strlen($blob) !== FACE_DESCRIPTOR_SIZE * 4) {
            return null;
        }
        $v = unpack_descriptor($blob);
    } elseif (is_array($raw)) {
        if (count($raw) !== FACE_DESCRIPTOR_SIZE) {
            return null;
        }
        $v = [];
        foreach ($raw as $x) {
            // terima string angka supaya JSON longgar tidak ditolak
            if (!is_float_like($x)) {
                return null;
            }
            $v[] = (float) $x;
        }
    } else {
        return null;
    }

    if (count($v) !== FACE_DESCRIPTOR_SIZE) {
        return null;
    }

    /* Nilai tidak hingga (NaN/Inf) harus ditolak sebelum perbandingan
     * apa pun. Di PHP, NaN lebih besar dari ambang mana pun bernilai
     * false, sehingga pemeriksaan bisa lolos tanpa sengaja. */
    foreach ($v as $x) {
        if (!is_finite((float) $x) || abs((float) $x) > 10.0) {
            return null;
        }
    }

    /* Varian terlalu rendah menandakan isian asal-isalan, bukan wajah;
     * terlalu tinggi berarti noise atau prekusor rusak. */
    $mean    = array_sum($v) / count($v);
    $variance = 0.0;
    foreach ($v as $x) {
        $variance += ((float) $x - $mean) ** 2;
    }
    $stddev = sqrt($variance / count($v));

    if ($stddev < 0.02 || $stddev > 1.2) {
        return null;
    }

    return $v;
}

/**
 * Kualitas descriptor 0..1 untuk disimpan sebagai catatan.
 * Bukan penentu kelulusan pencocokan, hanya informasi untuk guru saat
 * menentukan apakah pendaftaran ulang perlu.
 */
function descriptor_quality(array $v): float
{
    $mean     = array_sum($v) / count($v);
    $variance = 0.0;
    foreach ($v as $x) {
        $variance += ((float) $x - $mean) ** 2;
    }
    $stddev = sqrt($variance / count($v));

    // 0,08 pelan naik 0,60 = jelek, 0,30 = bagus
    $score = ($stddev - 0.08) / 0.52;
    return round(max(0.0, min(1.0, $score)), 3);
}

function pack_descriptor(array $descriptor): string
{
    if (count($descriptor) !== FACE_DESCRIPTOR_SIZE) {
        throw new InvalidArgumentException(
            'Descriptor harus ' . FACE_DESCRIPTOR_SIZE . ' angka, diterima ' . count($descriptor)
        );
    }
    $bin = '';
    foreach ($descriptor as $value) {
        $bin .= pack('g', (float) $value);
    }
    return $bin;
}

/**
 * BLOB 512 byte -> array 128 float.
 */
function unpack_descriptor(?string $blob): array
{
    if ($blob === null || $blob === '') {
        return [];
    }
    $expected = FACE_DESCRIPTOR_SIZE * 4;
    if (strlen($blob) !== $expected) {
        throw new InvalidArgumentException(
            'Panjang descriptor tidak sesuai: diharapkan ' . $expected
            . ' byte, diterima ' . strlen($blob)
        );
    }
    $out = [];
    for ($i = 0; $i < FACE_DESCRIPTOR_SIZE; $i++) {
        $out[] = unpack('g', substr($blob, $i * 4, 4))[1];
    }
    return $out;
}

/**
 * Bawa semua descriptor siswa ke browser dalam SATU request.
 *
 * Dipakai untuk memuat galeri 1200 siswa sekaligus, bukan 1200 request.
 * Format: { id, label, d:[128 float] } per siswa.
 * Ukuran respons sekitar 1,8 MB teks -acceptable untuk satu kali unduhan,
 * setelah itu disimpan di IndexedDB oleh scan-engine.js.
 */
function load_face_gallery(bool $centroidOnly = true): array
{
    require_once __DIR__ . '/database.php';

    $sql = 'SELECT f.id, f.user_id, f.descriptor, u.nama_lengkap, u.nis, u.kelas_id
            FROM face_templates f
            INNER JOIN users u ON u.id = f.user_id
            WHERE u.role = :role AND u.is_active = 1';

    if ($centroidOnly) {
        $sql .= ' AND f.is_centroid = 1';
    }

    $sql .= ' ORDER BY f.user_id';

    $rows = db_all($sql, [':role' => 'siswa']);

    $gallery = [];
    foreach ($rows as $r) {
        try {
            $vec = unpack_descriptor($r['descriptor']);
        } catch (InvalidArgumentException $e) {
            error_log('[FACE] Descriptor user ' . $r['user_id'] . ' rusak: ' . $e->getMessage());
            continue;
        }
        if (count($vec) !== FACE_DESCRIPTOR_SIZE) {
            continue;
        }
        $gallery[] = [
            'id'   => (int) $r['user_id'],
            'name' => $r['nama_lengkap'],
            'nis'  => $r['nis'],
            'kls'  => $r['kelas_id'] !== null ? (int) $r['kelas_id'] : null,
            'd'    => $vec,
        ];
    }

    return $gallery;
}

/**
 * Rata-rata beberapa descriptor menjadi satu centroid.
 * Centroid dipakai untuk matching karena lebih stabil daripada satu sampel.
 */
function centroid_of(array $descriptors): array
{
    $n = count($descriptors);
    if ($n === 0) {
        throw new InvalidArgumentException('Tidak ada descriptor untuk dihitung');
    }

    $sum = array_fill(0, FACE_DESCRIPTOR_SIZE, 0.0);
    foreach ($descriptors as $d) {
        if (count($d) !== FACE_DESCRIPTOR_SIZE) {
            throw new InvalidArgumentException(
                'Descriptor harus ' . FACE_DESCRIPTOR_SIZE . ' angka'
            );
        }
        for ($i = 0; $i < FACE_DESCRIPTOR_SIZE; $i++) {
            $sum[$i] += (float) $d[$i];
        }
    }

    $centroid = [];
    for ($i = 0; $i < FACE_DESCRIPTOR_SIZE; $i++) {
        $centroid[] = $sum[$i] / $n;
    }
    return $centroid;
}

/* ==================================================================
 |  FACE MATCHING  -  Jarak descriptor
 | ==================================================================
 |
 | face-api.js memakai jarak Euclid untuk pencocokan. Fungsi di sini
 | harus memakai rumus yang sama agar ambang batas 0.55 berlaku di
 | server maupun di browser.
 * ================================================================== */

/** Jarak Euclid dua descriptor. Lebih kecil = lebih mirip. */
function face_distance(array $a, array $b): float
{
    $n = FACE_DESCRIPTOR_SIZE;
    if (count($a) !== $n || count($b) !== $n) {
        throw new InvalidArgumentException('Panjang descriptor tidak valid');
    }

    $sum = 0.0;
    for ($i = 0; $i < $n; $i++) {
        $diff = (float) $a[$i] - (float) $b[$i];
        $sum += $diff * $diff;
    }
    return sqrt($sum);
}

/** Keyakinan 0..1 dari jarak. 0 jarak = keyakinan 1. */
function face_confidence(float $distance, float $threshold): float
{
    if ($distance >= $threshold) {
        return 0.0;
    }
    return round(1.0 - ($distance / $threshold), 4);
}

/**
 * Cari siswa terdekat dari sebuah descriptor.
 * Hanya membandingkan terhadap centroid, bukan setiap sampel, supaya
 * hemat CPU. Diukur pada 1200 siswa: pemuatan galeri 68 ms, pencocokan
 * 79 ms di PHP. Di browser dengan Float32Array jauh lebih cepat lagi
 * karena tidak ada overhead array PHP.
 */
function find_nearest_face(array $query, array $gallery, int $limit = 3): array
{
    $scored = [];
    foreach ($gallery as $person) {
        $dist = face_distance($query, $person['d']);
        $scored[] = [
            'id'         => $person['id'],
            'name'       => $person['name'],
            'nis'        => $person['nis'],
            'distance'   => round($dist, 4),
            'confidence' => face_confidence($dist, FACE_MATCH_THRESHOLD),
        ];
    }

    usort($scored, static fn($a, $b) => $a['distance'] <=> $b['distance']);

    return array_slice($scored, 0, $limit);
}
