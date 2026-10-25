<?php
/**
 * POST /api/v1/student/register-face.php
 *
 * Daftarkan atau perbarui template wajah untuk pengguna yang login.
 *
 * Endpoint ini menerima beberapa sampel lalu menyimpan centroid-nya.
 * Menyimpan semua sampel mentah tidak membantu: yang dipakai hanya
 * satu vektor centroid, dan menyimpan semuanya berarti menggandakan
 * data biometrik yang tidak perlu.
 *
 * Template wajah bisa ditulis ulang berulang kali, dan setiap
 * penulisan ulang berpeluang menggeser batas pencocokan orang itu. Dua
 * pengaman dipakai:
 *   1. Batas jumlah pendaftaran ulang per jendela waktu.
 *   2. Sesi harus sudah cukup tua, supaya akun yang baru saja dibajak
 *      tidak langsung bisa menimpa template orang lain.
 *
 * Verifikasi akhir tetap di server; endpoint ini hanya menyimpan.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../auth/session.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_fail('METHOD_NOT_ALLOWED', 'Gunakan POST.', 405);
}

$ct = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
if (strpos($ct, 'application/json') === false) {
    json_fail('UNSUPPORTED_MEDIA_TYPE', 'Body harus application/json.', 415);
}

$user = current_user();
if (!$user) {
    json_fail('UNAUTHENTICATED', 'Silakan masuk terlebih dahulu.', 401);
}
if (($user['role'] ?? '') !== 'siswa') {
    json_fail('FORBIDDEN', 'Hanya siswa yang dapat mendaftarkan wajah.', 403);
}

$userId = (int) $user['id'];

/* Sesi terlalu muda: kalau akun baru saja dipakai, mendaftarkan ulang
 * wajah berarti ada yang mungkin besar baru saja membajaknya. */
if (!session_is_old_enough(FACE_ENROLL_MIN_SESSION_AGE)) {
    /* Sisa waktu biasanya di bawah satu menit, jadi menyebutnya "menit"
     * saja akan membulatkan ke atas dan membuat siswa menunggu lebih lama
     * dari yang perlu. Ambasikan satuan yang benar. */
    $sisa = max(1, FACE_ENROLL_MIN_SESSION_AGE - session_age());
    $tunggu = $sisa < 60
        ? $sisa . ' detik'
        : ceil($sisa / 60) . ' menit';
    json_fail('SESSION_TOO_NEW',
        'Sesi login belum cukup lama untuk mendaftarkan ulang wajah. '
        . 'Tunggu ' . $tunggu . ' lagi, lalu muat ulang halaman.', 403,
        ['retryAfter' => $sisa]);
}

/* Batas penulisan template, bukan batas percobaan.
 *
 * throttle sengaja dipanggil SETELAH validasi lolos, tepat sebelum
 * menulis ke database, dengan dua alasan:
 *
 *   1. Kalau dihitung dari awal, tiga percobaan yang gagal validasi
 *      sudah menghabiskan kuota harian siswa. Padahal tidak ada yang
 *      berubah di database, jadi student yang salah ketik akan terkunci
 *      24 jam tanpa sebab yang jelas.
 *   2. Yang mahal dan yang perlu dibatasi adalah tulisan ke database,
 *      bukan pemrosesan JSON yang gagal di awal.
 *
 * Karena itu slot hanya terpakai ketika penulisan benar-benar
 * terjadi, dan TIDAK pernah dikembalikan setelah berhasil. Kalau
 * kuota direset setelah sukses, batas ini jadi tidak berarti sama
 * sekali: pendaftaran berulang akan selalu mengembalikan counter ke nol. */
$data = body_json();

/* Terima "descriptors" (beberapa sampel) atau "descriptor" (satu). */
$samples = [];
if (isset($data['descriptors']) && is_array($data['descriptors'])) {
    foreach ($data['descriptors'] as $d) {
        $samples[] = $d;
    }
} elseif (isset($data['descriptor'])) {
    $samples[] = $data['descriptor'];
}

if (!$samples) {
    json_fail('NO_DESCRIPTOR', 'Tidak ada descriptor yang dikirim.', 400);
}
if (count($samples) > FACE_ENROLL_MAX_SAMPLES) {
    json_fail('TOO_MANY_SAMPLES',
        'Maksimal ' . FACE_ENROLL_MAX_SAMPLES . ' sampel per pendaftaran.', 400);
}

$vecs = [];
foreach ($samples as $i => $raw) {
    $v = descriptor_from_input($raw);
    if ($v === null) {
        json_fail('BAD_DESCRIPTOR',
            'Sampel #' . ($i + 1) . ' bukan descriptor 128 float yang sah: '
            . 'panjang tepat 128, tanpa NaN/Inf, nilai mutlak di bawah 10, '
            . 'dan variasi yang wajar.', 400);
    }
    $vecs[] = $v;
}

/* Semua sampel harus cukup mirip satu sama lain. Kalau jauh berbeda,
 * capture-nya tidak konsisten (bergerak, buram, atau ada orang lain di
 * frame) dan centroidnya tidak berarti apa-apa.
 *
 * Ambangnya FACE_MATCH_STRICT, bukan FACE_ENROLL_MAX_SPREAD, supaya
 * satu angka governs seberapa mirip dua capture wajah dianggap
 * orang yang sama. FACE_ENROLL_MAX_SPREAD sengaja mengikuti nilai itu
 * supaya tidak ada dua tempat yang bisa berbeda pendapat. */
$spread     = 0.0;
$centroid   = count($vecs) === 1 ? $vecs[0] : centroid_of($vecs);

if (count($vecs) > 1) {
    foreach ($vecs as $v) {
        $spread = max($spread, face_distance($v, $centroid));
    }
}
if ($spread > FACE_ENROLL_MAX_SPREAD) {
    audit_log($userId, 'face_enroll_inconsistent', 'warning',
        'Sebaran ' . round($spread, 4) . ' melebihi batas ' . FACE_ENROLL_MAX_SPREAD . '.');
    json_fail('SAMPLES_INCONSISTENT',
        'Sampel terlalu berbeda satu sama lain (jarak ' . round($spread, 3)
        . ', batas ' . FACE_ENROLL_MAX_SPREAD . '). Pastikan wajah terlihat jelas, '
        . 'cahaya cukup, dan hanya ada satu orang di depan kamera.', 422,
        ['spread' => round($spread, 4)]);
}

/* Verifikasi 1:1 tiap sampel terhadap centroid yang akan disimpan.
 * Ini yang menangkap kasus "satu sampel benar, sisanya orang lain":
 * sebaran bisa tetap kecil kalau sampel cukup banyak, tapi setiap
 * sampel individual harus cocok dengan centroid. */
if (count($vecs) > 1) {
    foreach ($vecs as $i => $v) {
        $d = face_distance($v, $centroid);
        if ($d > FACE_MATCH_STRICT) {
            audit_log($userId, 'face_enroll_sample_rejected', 'warning',
                'Sampel #' . ($i + 1) . ' jarak ' . round($d, 4) . ' dari centroid.');
            json_fail('SAMPLE_MISMATCH',
                'Sampel #' . ($i + 1) . ' tidak cocok dengan sampel lain '
                . '(jarak ' . round($d, 3) . ', batas ' . FACE_MATCH_STRICT . '). '
                . 'Pastikan hanya satu orang di depan kamera dan wajah tidak bergerak.', 422);
        }
    }
}

/* Kalau centroid dinonaktifkan, cukup simpan sampel tunggal apa
 * adanya. Jalur ini hanya untuk keperluan debugging. */
if (!FACE_CENTROID_ENABLED && count($vecs) > 1) {
    $centroid = $vecs[0];
}

$blob    = pack_descriptor($centroid);
$quality = descriptor_quality($centroid);

/* Semua sudah lolos; baru sekarang slot kuota dipakai, tepat sebelum
 * menyentuh database. Lihat catatan panjang di bagian atas file. */
$gate = throttle('face_enroll', FACE_ENROLL_MAX_PER_DAY, FACE_ENROLL_WINDOW_SEC);
if (!$gate['allowed']) {
    audit_log($userId, 'face_enroll_rate_limited', 'warning',
        'Melebihi batas ' . FACE_ENROLL_MAX_PER_DAY . ' pendaftaran ulang per '
        . FACE_ENROLL_WINDOW_SEC . ' detik.');

    /* Sama seperti penolakan sesi muda: sisa waktu bisa jauh kurang dari satu
     * jam, jadi "1 jam" akan membuat siswa menunggu lebih lama dari yang perlu
     * tanpa tahu kapan harus mencoba lagi. */
    $sisa = max(1, (int) $gate['retryAfter']);
    if ($sisa < 60) {
        $tunggu = $sisa . ' detik';
    } elseif ($sisa < 3600) {
        $tunggu = ceil($sisa / 60) . ' menit';
    } else {
        $tunggu = ceil($sisa / 3600) . ' jam';
    }

    json_fail('RATE_LIMITED',
        'Terlalu banyak pendaftaran ulang wajah. Batas ' . FACE_ENROLL_MAX_PER_DAY
        . ' kali per ' . (int) (FACE_ENROLL_WINDOW_SEC / 3600) . ' jam. '
        . 'Coba lagi dalam ' . $tunggu . '.', 429,
        ['retryAfter' => $sisa]);
}

/* Ganti template lama. Hapus dulu supaya tidak ada dua centroid untuk
 * satu orang, yang membuat hasil pencocokan ambigu. */
db_exec('DELETE FROM face_templates WHERE user_id = ?', [$userId]);
db_exec(
    'INSERT INTO face_templates (user_id, descriptor, is_centroid, sample_no, quality, created_at)
     VALUES (?, ?, 1, 0, ?, ?)',
    [$userId, $blob, $quality, db_now()]
);

audit_log($userId, 'face_enrolled', 'info',
    count($vecs) . ' sampel, sebaran ' . round($spread, 4)
    . ', kualitas ' . $quality . '.');

/* Tutup permintaan pindai ulang dari admin, kalau ada.
 *
 * Ini yang membuat permintaan itu selesai: admin menghapus template lama
 * dan siswa mendaftarkan ulang. Menandai "selesai" di sini, bukan saat
 * admin menekan tombolnya, karena sampai enroll ini berhasil siswa
 * masih tidak bisa absen dengan wajah.
 *
 * Semua dibungkus try/catch: tabel face_requests milik panel admin dan
 * bisa belum ada di instalasi lama. Pendaftaran wajah yang berhasil
 * tidak boleh gagal gara-gara tabel pendukung tidak ada. */
$reqSelesai = null;
try {
    $tabelAda = db_driver() === 'sqlite'
        ? (int) db_value(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'face_requests'",
            [],
            0
        )
        : (int) db_value('SHOW TABLES LIKE ?', ['face_requests'], 0);

    if ($tabelAda > 0) {
        $reqSelesai = db_exec(
            "UPDATE face_requests
                SET status = 'selesai', selesai_at = ?
              WHERE user_id = ? AND status = 'menunggu'",
            [db_now(), $userId]
        );
        if ($reqSelesai > 0) {
            audit_log($userId, 'face_request_done', 'info',
                $reqSelesai . ' permintaan pindai ulang ditandai selesai.');
        }
    }
} catch (Throwable $e) {
    error_log('[FACE] Gagal menutup permintaan pindai ulang: ' . $e->getMessage());
}

/* Slot tidak dikembalikan di sini: pendaftaran yang berhasil harus tetap
 * memakai kuota, kalau tidak batas ini tidak berarti. */

json_ok([
    'enrolled'   => true,
    'samples'    => count($vecs),
    'spread'     => round($spread, 4),
    'quality'    => $quality,
    'descriptor' => base64_encode($blob),
    'remaining'  => $gate['remaining'],
    'requestClosed' => (int) $reqSelesai,
    'message'    => $reqSelesai > 0
        ? 'Wajah berhasil didaftarkan. Permintaan pindai ulang sudah selesai.'
        : 'Wajah berhasil didaftarkan.',
], 201);
