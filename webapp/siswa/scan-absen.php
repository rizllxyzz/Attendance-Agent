<?php
/**
 * siswa/scan-absen.php
 * ---------------------------------------------------------------------------
 * Authentic Apple iOS Face ID Scanner UI (Clean 1-Screen Layout).
 * ---------------------------------------------------------------------------
 */

require_once __DIR__ . '/../config/app-config.php';
require_once __DIR__ . '/../auth/session.php';

require_login('siswa', 'guru', 'admin');
$user = current_user();

$nama = (string) ($user['nama_lengkap'] ?? $user['nama'] ?? 'Pengguna');
$scope = in_array($user['role'], ['guru', 'admin'], true) ? 'all' : 'self';

$e = function ($v) {
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
};

/* Patokan GPS dibaca dari database, bukan dari konstanta.
 *
 * Nilai di sini hanya untuk tampilan; koordinat yang dipakai verifikasi
 * tetap diambil dari scan-nonce.php supaya tidak ada dua sumber kebenaran.
 * Patokan sendiri ditampilkan di halaman supaya siswa bisa membandingkan
 * dengan lokasi GPS-nya. */
$lokasiPatokan = db_one(
    'SELECT lat, lng, radius_m FROM lokasi_sekolah WHERE is_active = 1 ORDER BY id LIMIT 1',
    [],
    null
) ?: [
    'lat'      => (float) SEKOLAH_LAT,
    'lng'      => (float) SEKOLAH_LNG,
    'radius_m' => (int) SEKOLAH_RADIUS_M,
];
?>
<?php
$isEnroll = (isset($_GET['mode']) && $_GET['mode'] === 'enroll');
$wajahTerdaftar = db_one('SELECT id FROM face_templates WHERE user_id = ? AND is_centroid = 1 LIMIT 1', [(int) $user['id']], null) !== null;
$enrollRequest = $isEnroll ? db_one(
    "SELECT alasan FROM face_requests WHERE user_id = ? AND status = 'menunggu' ORDER BY id DESC LIMIT 1",
    [(int) $user['id']],
    null
) : null;
$enrollIsReRegister = $isEnroll && $enrollRequest !== null;
$pageTitle = $isEnroll ? ($enrollIsReRegister ? 'Daftar Ulang Face ID' : 'Daftar Face ID') : 'Face ID Presensi';
$headlineTitle = $isEnroll ? ($enrollIsReRegister ? 'Daftar Ulang Face ID' : 'Daftar Face ID') : 'Face ID';
$headlineSub = $isEnroll
    ? ($enrollIsReRegister
        ? 'Buat template wajah baru. Pendaftaran ini akan menggantikan template Face ID lama.'
        : 'Buat template wajah pertama Anda dengan lima sampel dari posisi yang berbeda.')
    : ($wajahTerdaftar ? 'Posisikan wajah Anda di dalam lingkaran untuk presensi.' : 'Face ID belum terdaftar. Daftarkan Face ID terlebih dahulu untuk mulai presensi.');
$backLink = $isEnroll ? '/siswa/profil.php' : '/siswa/index.php';
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, maximum-scale=1, user-scalable=no">
<meta name="theme-color" content="#000000">
<title><?= $e($pageTitle) ?></title>
<link rel="stylesheet" href="/assets/css/camera-scan.css?v=1">
<link rel="stylesheet" href="/assets/vendor/fontawesome/css/all.min.css">
</head>
<body class="ios-faceid-body<?= $isEnroll ? ' is-enrollment' : ' is-attendance' ?>">

<div class="ios-faceid-screen">

    <!-- Top Bar (Minimal Apple Style) -->
    <header class="ios-faceid-topbar">
        <a href="<?= $e($backLink) ?>" class="ios-cancel-link" aria-label="Batal">Batal</a>
        <div class="ios-topbar-clock" id="jam">--:--</div>
    </header>

    <!-- Header Headline -->
    <section class="ios-headline">
        <h1 class="ios-main-title"><?= $e($headlineTitle) ?></h1>
        <p class="ios-main-sub" id="petunjuk"><?= $e($headlineSub) ?></p>
        <?php if (!$isEnroll && !$wajahTerdaftar): ?>
        <a class="ios-enroll-link" href="/siswa/scan-absen.php?mode=enroll"><i class="fa-solid fa-face-smile"></i> Daftarkan Face ID sekarang</a>
        <?php endif; ?>
    </section>

    <!-- Center Stage: Apple Face ID Circular Viewfinder with Radial Ring -->
    <?php if ($isEnroll): ?>
    <section class="enroll-info-card" aria-live="polite">
        <span class="enroll-info-card__icon"><i class="fa-solid <?= $enrollIsReRegister ? 'fa-arrows-rotate' : 'fa-face-smile' ?>"></i></span>
        <div><strong><?= $enrollIsReRegister ? 'Mode daftar ulang' : 'Mode pendaftaran awal' ?></strong><p><?= $enrollIsReRegister ? 'Template lama akan diganti setelah sampel baru berhasil disimpan.' : 'Tidak ada absensi yang dikirim pada tahap ini.' ?></p></div>
    </section>
    <?php endif; ?>
    <section class="ios-stage-wrapper">
        <div class="ios-viewfinder" id="viewfinder">
            
            <!-- 64 Radial Ticks Ring (Apple Face ID setup style) -->
            <svg class="ios-radial-svg" viewBox="0 0 320 320" aria-hidden="true">
                <g class="ios-ticks-group" id="radialTicks">
                    <?php for ($i = 0; $i < 64; $i++): $deg = $i * (360 / 64); ?>
                        <line x1="160" y1="6" x2="160" y2="20" transform="rotate(<?= $deg ?> 160 160)" stroke="rgba(255,255,255,0.22)" stroke-width="3" stroke-linecap="round" class="tick tick-<?= $i ?>"/>
                    <?php endfor; ?>
                </g>
            </svg>

            <!-- Video Camera Disc -->
            <div class="ios-camera-disc" id="cameraDisc">
                <video id="video" playsinline muted autoplay></video>
                
                <!-- Apple Corner Brackets -->
                <div class="ios-face-brackets" aria-hidden="true">
                    <svg viewBox="0 0 100 100">
                        <path d="M 22 36 L 22 24 A 4 4 0 0 1 26 20 L 38 20" fill="none" stroke="currentColor" stroke-width="3.2" stroke-linecap="round"/>
                        <path d="M 62 20 L 74 20 A 4 4 0 0 1 78 24 L 78 36" fill="none" stroke="currentColor" stroke-width="3.2" stroke-linecap="round"/>
                        <path d="M 22 64 L 22 76 A 4 4 0 0 0 26 80 L 38 80" fill="none" stroke="currentColor" stroke-width="3.2" stroke-linecap="round"/>
                        <path d="M 62 80 L 74 80 A 4 4 0 0 0 78 76 L 78 64" fill="none" stroke="currentColor" stroke-width="3.2" stroke-linecap="round"/>
                    </svg>
                </div>

                <!-- Minimal Countdown if any -->
                <div class="ios-disc-countdown" id="hitungMundur" hidden></div>
            </div>
        </div>

        <?php if ($isEnroll): ?>
            <!-- Enrollment Sample Dots Indicator (5 Apple Style Dots) -->
            <div class="ios-sample-pills" id="panelSampel">
                <div class="ios-sample-dots" id="sampel">
                    <span class="sample-dot" data-terisi="0">1</span>
                    <span class="sample-dot" data-terisi="0">2</span>
                    <span class="sample-dot" data-terisi="0">3</span>
                    <span class="sample-dot" data-terisi="0">4</span>
                    <span class="sample-dot" data-terisi="0">5</span>
                </div>
            </div>
            <!-- Hidden liveness challenge card in enroll mode -->
            <div id="liveness" data-aktif="0" style="display:none"></div>
        <?php else: ?>
            <!-- Gesture Challenge Indicator (Floating Apple Dynamic Island / Pill) -->
            <div class="ios-challenge-card" id="liveness" data-aktif="0" aria-live="polite">
                <div class="ios-challenge-icon" id="livenessGestureIcon">
                    <i class="fa-solid fa-arrows-spin"></i>
                </div>
                <div class="ios-challenge-body">
                    <div class="ios-challenge-label" id="livenessLangkah">Uji Gerakan</div>
                    <div class="ios-challenge-title" id="livenessInstruksi">Ikuti gerakan di layar</div>
                    <div class="ios-challenge-detail" id="livenessRincian"></div>
                </div>
            </div>
            <div class="ios-sample-pills" id="panelSampel" hidden>
                <div class="ios-sample-dots" id="sampel"></div>
            </div>
        <?php endif; ?>
    </section>

    <!-- Bottom Actions Area -->
    <footer class="ios-action-footer">
        <?php if ($isEnroll): ?>
            <button type="button" class="ios-primary-btn" id="tombolDaftar" disabled>
                <i class="fa-solid fa-camera"></i> Mulai Daftar Wajah
            </button>
            <button type="button" class="ios-primary-btn" id="tombolKirimSampel" style="display:none;background-color:var(--ios-green)">
                <i class="fa-solid fa-cloud-arrow-up"></i> Simpan Wajah
            </button>
            <a href="/siswa/scan-absen.php" class="ios-retry-btn" style="text-align:center;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;gap:6px">
                Buka Scan Presensi
            </a>
            <!-- Compatibility hidden elements -->
            <button type="button" id="tombolMulai" style="display:none"></button>
            <button type="button" id="tombolUlang" style="display:none"></button>
            <p class="ios-caption-info" id="jadwalNow">1. Pastikan cahaya cukup &nbsp; 2. Ikuti posisi wajah &nbsp; 3. Simpan template</p>
        <?php else: ?>
            <button type="button" class="ios-primary-btn" id="tombolMulai" disabled>
                <i class="fa-solid fa-expand"></i> Mulai Scan Face ID
            </button>
            <button type="button" class="ios-retry-btn" id="tombolUlang" hidden>
                Coba Lagi
            </button>
            <!-- Compatibility hidden elements -->
            <button type="button" id="tombolDaftar" style="display:none"></button>
            <button type="button" id="tombolKirimSampel" style="display:none"></button>
            <p class="ios-caption-info" id="jadwalNow">Memverifikasi lokasi dan Face ID untuk presensi</p>
        <?php endif; ?>
    </footer>

    <!-- Native iOS Result Modal Sheet -->
    <div class="ios-result-backdrop" id="hasilBackdrop" hidden>
        <div class="ios-result-dialog" id="hasil" data-hasil="ok" aria-live="assertive">
            <div class="ios-dialog-icon">
                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M4 8V6a2 2 0 0 1 2-2h2M4 16v2a2 2 0 0 0 2 2h2M16 4h2a2 2 0 0 1 2 2v2M16 20h2a2 2 0 0 0 2-2v-2"></path>
                    <circle cx="9" cy="10" r="1.2" fill="currentColor"></circle>
                    <circle cx="15" cy="10" r="1.2" fill="currentColor"></circle>
                    <path d="M9.5 15a3.5 3.5 0 0 0 5 0"></path>
                </svg>
            </div>
            <h2 class="ios-dialog-title" id="hasilJudul">Presensi Berhasil</h2>
            <p class="ios-dialog-desc" id="hasilRincian"></p>
            <button type="button" class="ios-primary-btn ios-dialog-btn" id="tombolTutupHasil">
                Selesai
            </button>
        </div>
    </div>

    <!-- Hidden elements for app.js backwards compatibility -->
    <div id="pengumuman" hidden>
        <div id="pengumumanJudul"></div>
        <div id="pengumumanIsi"></div>
    </div>
    <div id="progres" style="display:none"></div>
    <div id="progresTeks" style="display:none"></div>
    <div id="prosesPersen" style="display:none"></div>
    <span id="titik" data-siang="siap" style="display:none"></span>
    <span id="statusKamera" style="display:none"></span>
    <span id="hasilIkon" style="display:none"></span>
    <div id="rijek" hidden><div id="rijekIsi"></div></div>
    <p class="sr-only" id="umat" aria-live="polite"></p>
</div>

<script src="<?= $e(FACE_API_BASE) ?>" defer></script>
<script src="/assets/js/gps-verifier.js" defer></script>
<script src="/assets/js/liveness-detection.js" defer></script>
<script src="/assets/js/scan-engine.js" defer></script>
<script>
window.PRESENSI = <?= json_encode([
    'apiBase'            => '/api/v1',
    'scope'              => $scope,
    'userId'             => (int) $user['id'],
    'userName'           => $nama,
    'role'               => $user['role'],
    'csrf'               => csrf_token(),
    'modelPath'          => FACE_MODEL_BASE,
    'threshold'          => FACE_MATCH_THRESHOLD,
    'thresholdStrict'    => FACE_MATCH_STRICT,
    'samples'            => FACE_SAMPLE_COUNT,
    'enrollMaxSamples'   => FACE_ENROLL_MAX_SAMPLES,
    'enrollMinAge'       => FACE_ENROLL_MIN_SESSION_AGE,
    'schoolLat'          => $lokasiPatokan['lat'],
    'schoolLng'          => $lokasiPatokan['lng'],
    'schoolRadiusM'      => $lokasiPatokan['radius_m'],
    'enrollReRegister'   => $enrollIsReRegister,
], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="/assets/js/app.js?v=2" defer></script>

</body>
</html>
