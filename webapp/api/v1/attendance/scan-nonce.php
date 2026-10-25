<?php
/**
 * api/v1/attendance/scan-nonce.php
 * ---------------------------------------------------------------------------
 * Menerbitkan token sekali pakai untuk satu percobaan scan.
 *
 * Kenapa endpoint terpisah, bukan ditanam di halaman:
 *   Token hanya berlaku SCAN_NONCE_TTL detik (default 120). Memuat model
 *   face-api.js butuh 5-15 detik dan bisa lebih lama di HP kelas lama.
 *   Kalau token ditanam saat halaman dirender, token bisa sudah kedaluwarsa
 *   sebelum siswa menekan tombol. Dengan begitu, token baru diminta tepat
 *   sebelum scan dikirim.
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../auth/session.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_fail('METHOD_NOT_ALLOWED', 'Gunakan POST.', 405);
}

$user = current_user();
if (!$user || ($user['role'] ?? '') !== 'siswa') {
    json_fail('UNAUTHENTICATED', 'Silakan masuk sebagai siswa.', 401);
}

$userId = (int) $user['id'];

/* Cek batas percobaan gagal dengan cooldown otomatis */
if (isset($_SESSION['scan_lockout_until'])) {
    if (time() < $_SESSION['scan_lockout_until']) {
        $sisa = $_SESSION['scan_lockout_until'] - time();
        json_fail('RATE_LIMITED', 'Terlalu banyak percobaan gagal. Tunggu ' . $sisa . ' detik lalu coba lagi.', 429, ['retryAfter' => $sisa]);
    } else {
        unset($_SESSION['scan_lockout_until']);
        $_SESSION['scan_attempts'] = 0;
    }
} elseif ((int) ($_SESSION['scan_attempts'] ?? 0) >= MAX_SCAN_ATTEMPTS) {
    $_SESSION['scan_lockout_until'] = time() + 30; // cooldown 30 detik
    json_fail('RATE_LIMITED', 'Terlalu banyak percobaan gagal. Tunggu 30 detik lalu muat ulang halaman.', 429, ['retryAfter' => 30]);
}

/* Batasi juga penerbitannya, bukan hanya scan yang gagal. Tanpa ini satu
 * akun bisa meminta nonce tanpa henti. */
$gate = throttle('nonce', NONCE_MAX_PER_WINDOW, NONCE_WINDOW_SEC);
if (!$gate['allowed']) {
    audit_log($userId, 'nonce_rate_limited', 'warning',
        'Melebihi ' . NONCE_MAX_PER_WINDOW . ' permintaan nonce per ' . NONCE_WINDOW_SEC . ' detik.');
    json_fail('RATE_LIMITED', 'Terlalu banyak permintaan. Coba lagi dalam '
        . $gate['retryAfter'] . ' detik.', 429, ['retryAfter' => $gate['retryAfter']]);
}

/* Pilih challenge acak, supaya klien tidak bisa menentukan sendiri
 * gerakan mana yang akan diminta. */
$pool   = array_keys(LIVENESS_CHALLENGES);
$total  = min(LIVENESS_REQUIRED, count($pool));
$picked = [];
$bag    = $pool;
for ($i = 0; $i < $total; $i++) {
    $k = array_rand($bag);
    $picked[] = $bag[$k];
    unset($bag[$k]);
}

$nonce  = issue_scan_nonce($userId);
$school = db_one('SELECT lat, lng, radius_m, nama FROM lokasi_sekolah WHERE is_active = 1 LIMIT 1');

/* Jadwal yang tidak sehat tidak boleh diam-diam memblokir semua siswa.
 * Laporkan ke klien supaya masalahnya kelihatan. */
$scheduleIssues = validate_schedule();
if ($scheduleIssues) {
    audit_log($userId, 'schedule_misconfigured', 'warning',
        implode(' | ', $scheduleIssues));
}

/* Dukung perangkat yang butuh Izin DeviceMotion secara eksplisit (iOS 13+). */
$needsMotionPermission = (stripos($_SERVER['HTTP_USER_AGENT'] ?? '', 'iPhone') !== false)
    || (stripos($_SERVER['HTTP_USER_AGENT'] ?? '', 'iPad') !== false);

json_ok([
    'nonce'      => $nonce,
    'expiresIn'  => SCAN_NONCE_TTL,
    'challenges' => $picked,
    'schedule'   => [
        'masuk'  => JAM_MASUK_MULAI . '-' . JAM_MASUK_AKHIR,
        'pulang' => JAM_PULANG_MULAI . '-' . JAM_PULANG_AKHIR,
        'type'   => attendance_type((new DateTimeImmutable('now', new DateTimeZone(TIMEZONE)))->format('H:i')) ?? 'lainnya',
        'issues' => $scheduleIssues,
    ],
    'liveness'   => [
        'required'  => LIVENESS_REQUIRED,
        'timeoutMs' => LIVENESS_TIMEOUT_MS,
    ],
    'thresholds' => [
        'face'        => FACE_MATCH_THRESHOLD,
        'descriptorSize' => FACE_DESCRIPTOR_SIZE,
        'samples'     => FACE_SAMPLE_COUNT,
    ],
    'school'     => $school ? [
        'nama'     => $school['nama'],
        'lat'      => (float) $school['lat'],
        'lng'      => (float) $school['lng'],
        'radiusM'  => (int) $school['radius_m'],
    ] : null,
    'needsMotionPermission' => $needsMotionPermission,
]);
