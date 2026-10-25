<?php
/**
 * api/v1/attendance/scan-check.php
 * ---------------------------------------------------------------------------
 * Verifikasi absensi. Ini satu-satunya tempat yang BERHAK memutuskan
 * apakah seorang siswa hadir.
 *
 * Prinsip: browser tidak dipercaya sama sekali.
 * Semua angka yang dikirim klien (descriptor wajah, koordinat GPS, skor
 * liveness) diperlakukan sebagai klaim yang harus dihitung ulang di sini.
 * Hasil pencocokan di HP hanya untuk tampilan, bukan keputusan.
 *
 * Rangkaian pemeriksaan berurutan, berhenti di langkah pertama yang gagal:
 *   1. Metode dan Content-Type
 *   2. Sesi siswa aktif
 *   3. Rate limit percobaan scan
 *   4. Token anti-replay
 *   5. Validasi bentuk descriptor
 *   6. Cocokkan wajah di server   <- authoritative
 *   7. Verifikasi lokasi di server <- authoritative
 *   8. Jadwal absen
 *   9. Anti duplikat
 *  10. Tulis catatan + audit
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../auth/session.php';

/* ==================================================================
 |  Pesan lokasi yang bisa dibaca siswa
 |
 |  Kode di sini harus sama persis dengan yang dipakai
 |  verify_location() di config/helper.php, karena daftar di
 |  situ yang jadi sumber kebenarannya.
 * ================================================================== */

function geoMessage(array $geo): string
{
    $codes = array_column($geo['findings'], 'code');
    $d     = $geo['details']['distanceM'] ?? null;
    $r     = $geo['details']['radius'] ?? null;
    $has   = static fn(string $c): bool => in_array($c, $codes, true);

    if ($has('MISSING_COORD')) {
        return 'Koordinat tidak terkirim. Izinkan akses lokasi pada browser, lalu muat ulang halaman.';
    }
    if ($has('OUT_OF_RANGE')) {
        return 'Koordinat tidak masuk akal untuk bumi. Periksa pengaturan lokasi perangkat.';
    }
    if ($has('NULL_ISLAND')) {
        return 'Koordinat 0,0 terdeteksi, itu default aplikasi fake-GPS. Matikan fake location lalu coba lagi.';
    }
    if ($has('FUTURE_TIMESTAMP')) {
        return 'Jam perangkat tidak sinkron dengan server. Aktifkan waktu otomatis lalu coba lagi.';
    }
    if ($has('OUT_OF_RADIUS') && $d !== null && $r !== null) {
        return sprintf('Anda berada %.0f meter dari sekolah. Harus dalam radius %.0f meter.', $d, $r);
    }
    if ($has('STALE_FIX')) {
        return 'Data GPS sudah basi. Tunggu sinyal GPS stabil, lalu pindai ulang.';
    }
    if ($has('ACCURACY_TOO_LOW')) {
        return 'Akurasi GPS tidak cukup. Pindah ke area terbuka, jangan di dalam gedung, lalu coba lagi.';
    }
    if ($has('UNCERTAIN_POSITION')) {
        return 'Posisi GPS terlalu tidak pasti untuk dipastikan berada di dalam radius sekolah. '
             . 'Tunggu GPS mengunci dengan baik, lalu pindai ulang.';
    }
    if ($has('ACCURACY_TOO_GOOD')) {
        return 'Akurasi GPS terlalu sempurna untuk perangkat biasa, indikasi lokasi dimanipulasi.';
    }
    if ($has('EXCESS_PRECISION')) {
        return 'Koordinat terlalu banyak angka desimal untuk GPS asli. Nonaktifkan emulator_location.';
    }
    if ($has('MOTION_WITHOUT_GPS_MOVEMENT')) {
        return 'Perangkat bergerak tetapi koordinat GPS tidak berubah. Curigakan aplikasi pemalsu lokasi.';
    }
    if ($has('CLIENT_SUSPICIOUS')) {
        return 'Browser melaporkan indikasi lokasi dimanipulasi. Hubungi petugas sekolah.';
    }
    if ($has('NO_ACCURACY') || $has('NO_TIMESTAMP')) {
        return 'Data lokasi belum lengkap. Tunggu GPS terkunci, lalu pindai ulang.';
    }

    return 'Lokasi Anda tidak memenuhi syarat absen. (' . implode(', ', $codes) . ')';
}

/* ==================================================================
 |  1. Metode
 * ================================================================== */

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_fail('METHOD_NOT_ALLOWED', 'Gunakan POST.', 405);
}

if (strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') === false) {
    json_fail('BAD_CONTENT_TYPE', 'Content-Type harus application/json.', 415);
}

/* ==================================================================
 |  2. Sesi
 * ================================================================== */

$user = current_user();
if (!$user || ($user['role'] ?? '') !== 'siswa') {
    json_fail('UNAUTHENTICATED', 'Silakan masuk sebagai siswa.', 401);
}

$userId = (int) $user['id'];

/* ==================================================================
 |  3. Rate limit
 * ================================================================== */

$attempts = (int) ($_SESSION['scan_attempts'] ?? 0);
if ($attempts >= MAX_SCAN_ATTEMPTS) {
    audit_log($userId, 'scan_rate_limited', 'warning', 'Percobaan scan melebihi batas.');
    json_fail('RATE_LIMITED', 'Terlalu banyak percobaan gagal. Tunggu beberapa menit lalu coba lagi.', 429);
}

$fail = static function (string $event, string $code, string $msg, int $status, array $extra = []) use ($userId): void {
    $attempts = (int) ($_SESSION['scan_attempts'] ?? 0) + 1;
    $_SESSION['scan_attempts'] = $attempts;
    audit_log($userId, $event, 'warning', $code);
    $extra['attemptsLeft'] = max(0, MAX_SCAN_ATTEMPTS - $attempts);
    json_fail($code, $msg, $status, $extra);
};

/* ==================================================================
 |  4. Token anti-replay
 * ================================================================== */

$input = body_json();
if ($input === []) {
    json_fail('BAD_PAYLOAD', 'Payload tidak dapat dibaca.', 400);
}

$token = (string) ($input['nonce'] ?? '');
if ($token === '') {
    json_fail('NO_TOKEN', 'Token tidak ditemukan. Muat ulang halaman scan.', 400);
}

[$nonceOk, $nonceErr] = verify_scan_nonce($token, $userId);
if (!$nonceOk) {
    audit_log($userId, 'nonce_rejected', 'warning', $nonceErr);
    json_fail('NONCE_REJECTED', $nonceErr, 403);
}

/* ==================================================================
 |  5. Bentuk descriptor
 * ================================================================== */

$descriptorB64 = (string) ($input['descriptor'] ?? '');
if ($descriptorB64 === '') {
    json_fail('NO_DESCRIPTOR', 'Descriptor wajah tidak terkirim.', 400);
}

/* Bentuk, panjang, nilai tak hingga, rentang, dan variasi diperiksa
 * oleh descriptor_from_input() supaya aturannya sama persis dengan
 * yang dipakai register-face.php. */
$descriptor = descriptor_from_input($descriptorB64);
if ($descriptor === null) {
    json_fail('BAD_DESCRIPTOR',
        'Descriptor tidak valid: harus 128 float, tanpa NaN/Inf, '
        . 'nilai mutlak di bawah 10, dan variasi yang wajar.', 400);
}

/* ==================================================================
 |  6. Cocokkan wajah di server  (authoritative)
 * ================================================================== */

$t0 = microtime(true);

if (($user['role'] ?? '') === 'siswa') {
    /* Verifikasi 1:1 ketat langsung terhadap template pemilik akun */
    $myTemplate = db_one(
        'SELECT descriptor, quality FROM face_templates
          WHERE user_id = ? AND is_centroid = 1 LIMIT 1',
        [$userId]
    );

    if (!$myTemplate) {
        $fail('face_no_template', 'NO_TEMPLATE',
            'Wajah Anda belum terdaftar. Silakan daftarkan wajah terlebih dahulu.', 422);
    }

    $dist = face_distance($descriptor, unpack_descriptor($myTemplate['descriptor']));
    if (!is_finite($dist) || $dist > FACE_MATCH_THRESHOLD) {
        $fail('face_no_match', 'FACE_NOT_FOUND',
            'Wajah tidak cocok dengan akun ini. Pastikan pemilik akun yang melakukan scan.', 422, [
                'distance'  => is_finite($dist) ? round((float) $dist, 4) : 999,
                'threshold' => FACE_MATCH_THRESHOLD,
            ]);
    }

    $bestDistance = (float) $dist;
    $bestId       = $userId;
    $bestStudent  = ['user_id' => $userId, 'nama_lengkap' => $user['nama_lengkap'] ?? ''];
} else {
    /* Mode Kiosk (Guru/Admin): cari di seluruh galeri */
    $rows = db_all(
        'SELECT t.user_id, t.descriptor, u.nama_lengkap
           FROM face_templates t
           JOIN users u ON u.id = t.user_id
          WHERE t.is_centroid = 1
            AND u.is_active = 1'
    );

    if (!$rows) {
        json_fail('NO_GALLERY', 'Belum ada data wajah yang terdaftar.', 503);
    }

    $bestId       = 0;
    $bestDistance = INF;
    $bestStudent  = null;

    foreach ($rows as $row) {
        $d = face_distance($descriptor, unpack_descriptor($row['descriptor']));
        if (!is_finite($d)) {
            continue;
        }
        if ($d < $bestDistance) {
            $bestDistance = (float) $d;
            $bestId       = (int) $row['user_id'];
            $bestStudent  = $row;
        }
    }

    if ($bestDistance > FACE_MATCH_THRESHOLD || $bestId !== $userId) {
        $fail('face_no_match', 'FACE_NOT_FOUND',
            'Wajah tidak cocok dengan akun ini. Pastikan pemilik akun yang melakukan scan.', 422, [
                'distance'  => round($bestDistance, 4),
                'threshold' => FACE_MATCH_THRESHOLD,
            ]);
    }
}

$matchMs    = (int) round((microtime(true) - $t0) * 1000);

if (!is_finite($bestDistance)) {
    $fail('face_undecidable', 'FACE_UNDECIDABLE',
        'Pencocokan wajah tidak menghasilkan hasil yang sah.', 422);
}

$confidence = face_confidence($bestDistance, FACE_MATCH_THRESHOLD);

if ($bestDistance > FACE_MATCH_THRESHOLD) {
    $fail('face_no_match', 'FACE_NOT_FOUND',
        'Wajah tidak dikenali. Pastikan wajah Anda sudah terdaftar.', 422, [
            'distance'  => round($bestDistance, 4),
            'threshold' => FACE_MATCH_THRESHOLD,
        ]);
}

/* Gerbang keyakinan minimum.
 *
 * Yang di atas hanya menolak jarak yang melewati ambang.Kembar atau
 * orang yang sangat mirip masih bisa lolos secara teknis dengan
 * keyakinan rendah, dan itu justru kasus yang paling memalukan untuk
 * absensi: sistem mencatat orang yang salah sebagai hadir. Lantai
 * keyakinan menutup celah itu. */
if ($confidence < FACE_MIN_CONFIDENCE) {
    $fail('face_low_confidence', 'FACE_LOW_CONFIDENCE',
        'Wajah dikenali tetapi keyakinannya terlalu rendah untuk dicatat. '
        . 'Pastikan pencahayaan cukup dan wajah tidak tertutup, lalu pindai ulang.', 422, [
            'distance'     => round($bestDistance, 4),
            'confidence'   => $confidence,
            'minConfidence' => FACE_MIN_CONFIDENCE,
        ]);
}

/* ==================================================================
 |  7. Verifikasi lokasi di server  (authoritative)
 * ================================================================== */

$school = db_one('SELECT * FROM lokasi_sekolah WHERE is_active = 1 ORDER BY id LIMIT 1');
$geo    = verify_location(is_array($input['gps'] ?? null) ? $input['gps'] : [], $school);

/* PERLU_DITINJAU juga ditolak — bukan hanya DITOLAK.
 * Skor mencurigakan berarti ada kejanggalan pada data GPS: koordinat
 * mencurigakan, akurasi terlalu sempurna, atau payload tidak lengkap.
 * Membiarkannya lolos membuka celah bagi yang sengaja memanipulasi
 * nilai non-kritis supaya terhindar dari DITOLAK penuh. */
if ($geo['verdict'] !== 'DITERIMA') {
    $attempts = (int) ($_SESSION['scan_attempts'] ?? 0) + 1;
    $_SESSION['scan_attempts'] = $attempts;
    audit_log($userId, 'location_rejected', 'critical', json_encode([
        'verdict'  => $geo['verdict'],
        'score'    => $geo['score'],
        'distance' => $geo['details']['distanceM'] ?? null,
        'findings' => array_column($geo['findings'], 'code'),
    ], JSON_UNESCAPED_UNICODE));

    json_fail('LOCATION_REJECTED', geoMessage($geo), 422, [
        'verdict'   => $geo['verdict'],
        'score'     => $geo['score'],
        'distanceM' => $geo['details']['distanceM'] ?? null,
        'radiusM'   => $geo['details']['radius'] ?? null,
        'accuracyM' => $geo['details']['accuracyM'] ?? null,
        'findings'  => $geo['findings'],
        'attemptsLeft' => max(0, MAX_SCAN_ATTEMPTS - $attempts),
    ]);
}

/* ==================================================================
 |  8. Jadwal absen
 * ================================================================== */

$now   = new DateTimeImmutable('now', new DateTimeZone(TIMEZONE));
$clock = $now->format('H:i');
$type  = attendance_type($clock);

if ($type === null) {
    $ket = $clock < JAM_MASUK_MULAI
        ? 'Belum jam absen masuk. Jam masuk dibuka pukul ' . JAM_MASUK_MULAI . '.'
        : 'Di luar jam absen. Jam masuk ' . JAM_MASUK_MULAI . '-' . JAM_MASUK_AKHIR
          . ', jam pulang ' . JAM_PULANG_MULAI . '-' . JAM_PULANG_AKHIR . '.';
    audit_log($userId, 'outside_schedule', 'info', 'Jam server ' . $clock . '.');
    json_fail('OUTSIDE_SCHEDULE', $ket, 422, [
        'serverTime' => $clock,
        'windows'    => [
            'masuk'  => JAM_MASUK_MULAI . '-' . JAM_MASUK_AKHIR,
            'pulang' => JAM_PULANG_MULAI . '-' . JAM_PULANG_AKHIR,
        ],
    ]);
}

$isLate = $type === 'masuk' && $clock > JAM_MASUK_AKHIR;  // dihitung ulang di langkah 11

/* ==================================================================
 |  9. Anti duplikat (mode testing: perbarui rekaman lama)
 * ================================================================== */

$today     = $now->format('Y-m-d');
$duplicate = db_one(
    'SELECT id, waktu FROM attendance
      WHERE user_id = ? AND tanggal = ? AND scan_type = ?',
    [$userId, $today, $type]
);

if ($duplicate) {
    /* Bebas uji coba: ganti rekaman sebelumnya dengan hasil scan terbaru */
    db_exec('DELETE FROM attendance WHERE id = ?', [(int) $duplicate['id']]);
}

/* ==================================================================
 |  10. Liveness
 * ================================================================== */

$liveness   = is_array($input['liveness'] ?? null) ? $input['liveness'] : [];
$livenessOk = (bool) ($liveness['ok'] ?? false);
$challenges = array_values(array_filter(
    (array) ($liveness['challenges'] ?? []),
    static fn($c) => is_array($c) && !empty($c['type'])
));

if (!$livenessOk || count($challenges) < LIVENESS_REQUIRED) {
    $fail('liveness_failed', 'LIVENESS_FAILED',
        'Verifikasi kehidupan gagal. Silakan ulangi.', 422, [
            'required'  => LIVENESS_REQUIRED,
            'completed' => count($challenges),
        ]);
}

/* Hanya challenge yang benar-benar ada di daftar resmi yang diterima. */
$valid = array_values(array_filter(
    $challenges,
    static fn($c) => isset(LIVENESS_CHALLENGES[$c['type']])
));

if (count($valid) < LIVENESS_REQUIRED) {
    $fail('liveness_fake', 'LIVENESS_FAKE',
        'Jenis gerakan tidak dikenal.', 422);
}

/* Validasi durasi minimum per challenge.
 *
 * Gerakan wajah nyata butuh waktu: manusia tidak bisa menganggukkan
 * kepala dalam 0 milidetik. Payload yang dikirim tool otomatis sering
 * menyertakan ms=0 atau ms=1. Batas bawah 350 ms masih sangat longgar
 * untuk gerakan manusia tercepat, tetapi sudah cukup memblokir injeksi
 * payload yang tidak melewati kamera sama sekali.
 *
 * Batas atas 30 detik menangkap kasus "rekam satu gerakan, tempel ke
 * payload berbeda menit kemudian", karena liveness 30+ detik berarti
 * challenge sudah sangat lama dimulai sebelum scan. */
$MIN_LIVENESS_MS = 350;
$MAX_LIVENESS_MS = 30000;

foreach ($valid as $c) {
    $ms = isset($c['ms']) && is_numeric($c['ms']) ? (int) $c['ms'] : null;
    if ($ms === null || $ms < $MIN_LIVENESS_MS) {
        $fail('liveness_too_fast', 'LIVENESS_TOO_FAST',
            'Gerakan terlalu cepat. Lakukan gerakan secara natural.', 422, [
                'challenge' => $c['type'],
                'ms'        => $ms,
                'minMs'     => $MIN_LIVENESS_MS,
            ]);
    }
    if ($ms > $MAX_LIVENESS_MS) {
        $fail('liveness_too_slow', 'LIVENESS_TOO_SLOW',
            'Sesi verifikasi gerakan sudah kedaluwarsa. Mulai scan ulang.', 422, [
                'challenge' => $c['type'],
                'ms'        => $ms,
                'maxMs'     => $MAX_LIVENESS_MS,
            ]);
    }
}

/* Log waktu total liveness untuk analisis jika diperlukan */
$livenessTotalMs = isset($liveness['totalMs']) && is_numeric($liveness['totalMs'])
    ? (int) $liveness['totalMs'] : null;
if ($livenessTotalMs !== null && $livenessTotalMs < $MIN_LIVENESS_MS) {
    $fail('liveness_total_too_fast', 'LIVENESS_TOO_FAST',
        'Sesi liveness terlalu singkat. Ulangi secara natural.', 422);
}

/* Cross-validasi temporal: sesi liveness harus dilakukan SETELAH nonce diterbitkan.
 *
 * sessionAt adalah Date.now() klien saat sesi berakhir. Nonce mengandung
 * timestamp penerbitannya. Jika sessionAt lebih kecil dari waktu nonce
 * diterbitkan (minus toleransi 30 detik untuk drift jam klien), berarti
 * sesi liveness ini direkam SEBELUM nonce diminta \u2014 indikasi replay sesi
 * dari percobaan sebelumnya yang disimpan oleh tool otomatis.
 *
 * Hanya berlaku bila klien mengirim sessionAt; klien lama yang tidak
 * mengirimkan field ini tidak diblokir, hanya dicatat. */
$sessionAt = isset($liveness['sessionAt']) && is_numeric($liveness['sessionAt'])
    ? (int) $liveness['sessionAt'] : null;

if ($sessionAt !== null) {
    /* Dekode nonce untuk mendapat waktu penerbitannya */
    $nonceRaw   = base64_decode($token, true);
    $nonceParts = $nonceRaw !== false ? explode('|', $nonceRaw) : [];
    $nonceIssuedAt = count($nonceParts) >= 3 ? (int) $nonceParts[2] - SCAN_NONCE_TTL : 0;

    /* sessionAt dalam milidetik, nonceIssuedAt dalam detik */
    $SESSION_CLOCK_DRIFT_TOLERANCE_MS = 30000; // 30 detik toleransi drift jam klien
    if ($sessionAt < ($nonceIssuedAt * 1000) - $SESSION_CLOCK_DRIFT_TOLERANCE_MS) {
        audit_log($userId, 'liveness_replay_suspected', 'critical', sprintf(
            'sessionAt=%d nonceIssuedAt=%d diff=%dms',
            $sessionAt, $nonceIssuedAt * 1000, ($nonceIssuedAt * 1000) - $sessionAt
        ));
        $fail('liveness_replay', 'LIVENESS_REPLAY',
            'Sesi liveness sudah kedaluwarsa. Mulai scan baru dari halaman scan.', 422);
    }
}

/* ==================================================================
 |  11. Tulis catatan
 * ================================================================== */

/* Keterlambatan dihitung dari selisih menit setelah jam masuk ditutup.
 * Toleransi diperhitungkan terpisah: terlambat dalam batas yang
 * masih dihitung tepat, sehingga catatan tidak berbohong menjadi
 * "tepat" padahal guru tetap ingin tahu berapa menit. */
$lateMinutes = 0;
if ($type === 'masuk') {
    $batasMasuk = new DateTimeImmutable($today . ' ' . JAM_MASUK_AKHIR, new DateTimeZone(TIMEZONE));
    $lateMinutes = max(0, (int) floor(($now->getTimestamp() - $batasMasuk->getTimestamp()) / 60));
    $isLate     = $lateMinutes > TOLERANSI_TELAT_MENIT;
}

$cols = [
    'user_id'            => $userId,
    'tanggal'            => $today,
    'scan_type'          => $type,
    'waktu'              => $now->format('Y-m-d H:i:s'),
    'status'             => 'hadir',
    'lat'                => $geo['details']['lat'] ?? null,
    'lng'                => $geo['details']['lng'] ?? null,
    'accuracy_m'         => $geo['details']['accuracyM'] ?? null,
    'distance_m'         => $geo['details']['distanceM'] ?? null,
    'gps_verdict'        => $geo['verdict'],
    'gps_score'          => $geo['score'],
    'is_late'            => $isLate ? 1 : 0,
    'late_minutes'       => $lateMinutes > 0 ? $lateMinutes : null,
    'face_distance'      => round($bestDistance, 4),
    'face_confidence'    => $confidence,
    'liveness_codes'     => implode(',', array_column($valid, 'type')),
    'device_fingerprint' => substr((string) ($input['fingerprint'] ?? ''), 0, 64) ?: null,
    'ip_address'         => client_ip(),
];

try {
    $names  = array_keys($cols);
    $marks  = array_fill(0, count($names), '?');
    $attend = db_insert(
        'INSERT INTO attendance (' . implode(', ', $names) . ') VALUES (' . implode(', ', $marks) . ')',
        array_values($cols)
    );
} catch (Throwable $e) {
    error_log('[SCAN] ' . $e->getMessage());
    json_fail('WRITE_FAILED', 'Gagal menyimpan absensi. Silakan coba lagi.', 500);
}

$_SESSION['scan_attempts'] = 0;

audit_log($userId, 'attendance_recorded', 'info', sprintf(
    '%s | wajah %.4f | lokasi %s (skor %d) | %s',
    $type, $bestDistance, $geo['verdict'], $geo['score'],
    implode(',', array_column($valid, 'type'))
));

/* ==================================================================
 |  Respons
 * ================================================================== */

$nama = $bestStudent['nama_lengkap'] ?? ($user['nama_lengkap'] ?? '');

json_ok([
    'attendanceId' => $attend,
    'scanType'     => $type,
    'time'         => $now->format('H:i:s'),
    'date'         => $today,
    'isLate'       => $isLate,
    'lateMinutes'  => $lateMinutes,
    'student'      => ['id' => $userId, 'name' => $nama],
    'verification' => [
        'face' => [
            'distance'    => round($bestDistance, 4),
            'threshold'   => FACE_MATCH_THRESHOLD,
            'confidence'  => $confidence,
            'matchMs'     => $matchMs,
            'gallerySize' => isset($rows) ? count($rows) : 1,
        ],
        'location' => [
            'verdict'   => $geo['verdict'],
            'score'     => $geo['score'],
            'distanceM' => $geo['details']['distanceM'] ?? null,
            'radiusM'   => $geo['details']['radius'] ?? null,
            'accuracyM' => $geo['details']['accuracyM'] ?? null,
        ],
        'liveness' => [
            'challenges' => count($valid),
            'types'      => array_column($valid, 'type'),
        ],
    ],
    'message' => ($type === 'masuk' ? 'Selamat datang, ' : 'Absen pulang tercatat, ') . $nama . '!',
], 201);
