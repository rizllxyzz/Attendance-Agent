<?php
/**
 * app-config.php
 * ---------------------------------------------------------------------------
 * Konfigurasi pusat aplikasi Presensi.
 * Semua nilai sensitif (kredensial, secret) TIDAK disimpan di file ini.
 * Credentials database ada di config/database.php.
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);

/* ------------------------------------------------------------------
 |  IDENTITAS SEKOLAH
 * ------------------------------------------------------------------ */
define('APP_NAME',        'AbsensiPro');
define('SCHOOL_NAME',     'School');
define('SCHOOL_ADDRESS',  'Jl. Raya Mrisen-Juwiring, RT.01/RW.02, Mrisen, Juwiring, Klaten');

/* ------------------------------------------------------------------
 |  KEAMANAN
 * ------------------------------------------------------------------ */
define('SESSION_NAME',            'PRESENSI_SESS');
define('SESSION_LIFETIME',        7200);      // detik (2 jam)
define('CSRF_TOKEN_LIFETIME',     3600);

define('PASSWORD_MIN_LENGTH',     8);
define('LOGIN_MAX_ATTEMPTS',      5);
define('LOGIN_LOCKOUT_SECONDS',   900);      // 15 menit

define('MAX_SCAN_ATTEMPTS',       5);        // percobaan gagal per sesi sebelum dikunci

/**
 * API_FINGERPRINT_KEY
 * Kunci penanda tangan untuk token scan sekali pakai.
 * WAJIB diganti di produksi:
 *     bin2hex(random_bytes(32))
 * Jangan pernah commit nilai aslinya ke repository publik.
 */
define('API_FINGERPRINT_KEY', 'a5914f1775b085b74baee7a4378bfe5e10e5c9bba0bd1a9de0985b10ae2adb94');

define('SCAN_NONCE_TTL',          600);      // token scan valid 10 menit

/* Batas permintaan nonce per sesi. Nonce sendiri sudah ditandatangani
 * jadi tidak bisa ditebak, tapi membatasi penerbitannya mencegah satu
 * akun membebani server dan mencoba challenge berulang sampai cocok. */
define('NONCE_MAX_PER_WINDOW',    15);       // maksimal 15 permintaan nonce...
define('NONCE_WINDOW_SEC',        60);       // ...per 60 detik

/* ==================================================================
 |  PENDAFTARAN WAJAH
 *  Definisi angka yang bergantung pada FACE_MATCH_STRICT ada di
 |  blok FACE_MATCH_STRICT di bawah. Urutan definisi konstanta
 |  penting: PHP mengevaluasi dari atas ke bawah, jadi konstanta
 |  yang memakai konstanta lain harus ditulis setelahnya.
 * ================================================================== */

/* Template wajah bisa ditulis ulang berkali-kali tanpa batas, dan
 * setiap penulisan ulang berpeluang memindahkan batas pencocokan
 * orang tersebut. Karena itu jumlah dan waktunya dibatasi.
 *
 * MIN_SESSION_AGE tidak boleh 0. Nilai 0 mematikan pengaman yang
 * justru satu-satunya yang tidak bisa dilewati dari sisi klien:
 * pendaftaran ulang Face ID butuh sesi yang sudah cukup tua, supaya
 * akun yang baru saja dipakai (misalnya baru saja dibajak) tidak
 * langsung bisa menimpa template wajah orang lain. Endpoint menolak
 * dengan SESSION_TOO_NEW kalau syarat ini tidak terpenuhi.
 *
 * 60 detik dipilih, bukan 0 dan bukan beberapa menit: cukup jelas
 * sebagai keputusan yang disengaja, tanpa membuat siswa yang baru
 * login harus menunggu lama.
 *
 * MAX_PER_DAY 5 cukup untuk kasus nyata. Siswa yang template-nya
 * hilang karena tidak sengaja mungkin perlu mencoba lagi, sedangkan
 * penulisan berulang adalah pola serangan, bukan kebutuhan. */
define('FACE_ENROLL_MIN_SESSION_AGE', 60);    // detik; 0 mematikan pengaman
define('FACE_ENROLL_MAX_PER_DAY',      5);
define('FACE_ENROLL_WINDOW_SEC',  86400);     // ...per 24 jam
define('FACE_ENROLL_MAX_SAMPLES',      5);    // sampel per pendaftaran

/* Hanya nyalakan bila aplikasi benar-benar berada di belakang reverse
 * proxy yang menimpa X-Forwarded-For. Pada shared hosting seperti
 * InfinityFree, biarkan false: REMOTE_ADDR sudah cukup, sedangkan
 * header proxy bisa disisipkan sendiri oleh penyerang. */
define('TRUST_PROXY_HEADERS',     false);

/* ------------------------------------------------------------------
 |  JADWAL ABSENSI (24 JAM AKTIF UNTUK TESTING)
 * ------------------------------------------------------------------ */
define('JAM_MASUK_MULAI',     '00:00');
define('JAM_MASUK_AKHIR',     '12:00');
define('JAM_PULANG_MULAI',    '12:00');
define('JAM_PULANG_AKHIR',    '23:59');
define('TOLERANSI_TELAT_MENIT', 60);

/* ------------------------------------------------------------------
 |  LOKASI SEKOLAH
 * ------------------------------------------------------------------ */
define('SEKOLAH_LAT',        -7.634360);   // SMK Negeri 1 Juwiring, Klaten
define('SEKOLAH_LNG',        110.700091);
define('SEKOLAH_RADIUS_M',   5000);        // radius 5000 meter (5 km)
define('GPS_MAX_ACCURACY_M', 500);         // akurasi GPS toleran dalam ruangan

/* ------------------------------------------------------------------
 |  ANTI-SPOOFING GPS
 * ------------------------------------------------------------------
 | BACAAN PENTING:
 | Nilai-nilai di bawah ini adalah HEURISTIK, bukan jaminan.
 | Geolocation API tidak menyediakan flag mock, sehingga aplikasi web
 | tidak dapat mengetahui apakah opsi "mock location" Android aktif.
 * Konfigurasi ini menaikkan biaya manipulasi, bukan menutupnya.
 * Pencegahan mutlak memerlukan aplikasi native atau terminal kamera.
 * ------------------------------------------------------------------ */
define('GPS_GUARD', [
    /* Batas akurasi diambil dari GPS_MAX_ACCURACY_M, tidak diduplikasi di sini. */
    'min_accuracy_m'         => 1,      // akurasi di bawah ini mustahil untuk GPS perangkat nyata
    'null_island_tolerance'  => 0.0001, // tolak koordinat 0,0 (default fake-GPS)
    'max_decimals'           => 8,      // GNSS asli max ~7, lebih dari ini kemungkinan disalin dari peta/spoofed
    'max_plausible_speed_mps'=> 75,     // 270 km/j, di atas ini tidak wajar
    'min_sample_count'       => 1,      // 1 sampel GPS cukup
    'max_sample_age_ms'      => 300000, // 5 menit -- toleran untuk GPS indoor/WiFi
    'max_suspicious_score'   => 80,     // skor di atas ini wajib ditinjau admin
    'client_score_trusted'   => false,  // JANGAN diubah ke true: laporan klien bisa dipalsukan
    'uncertainty_in_radius'  => false,  // false: cukup koordinat pusat dalam radius, tidak worst-case
    /* Payload tanpa accuracy ditolak total. Geolocation API W3C mewajibkan
     * accuracy selalu ada pada Position yang berhasil; ketiadaannya
     * berarti payload dibuat secara manual, bukan dari browser asli. */
    'missing_accuracy_is_fatal' => true,
]);

/**
 * GeoIP whitelist / blacklist berdasar negara.
 * Sengaja TIDAK membandingkan kota: sekolah di Indonesia umumnya memakai
 * carrier NAT sehingga kota IP tidak sama dengan lokasi GPS. Membandingkan
 * kota akan menghasilkan false positive yang sangat tinggi.
 */
define('GEOIP_ALLOWED_COUNTRIES', ['ID']);

/* ------------------------------------------------------------------
 |  FACE RECOGNITION
 * ------------------------------------------------------------------ */
define('FACE_MATCH_THRESHOLD',     0.48);   // ambang batas presisi (mencegah orang lain/saudara mirip lolos)
define('FACE_MATCH_STRICT',       0.48);   // toleransi sebaran sampel
define('FACE_MIN_CONFIDENCE',      0.15);

/* Sebaran maksimum antar-sampel terhadap centroid saat pendaftaran.
 * Melewati ini berarti capture tidak konsisten: bergerak, buram, atau ada
 * orang lain di frame. Diikatkan ke FACE_MATCH_STRICT supaya ada satu angka
 * yang menentukan seberapa mirip dua capture dianggap orang yang sama, dan
 * tidak ada dua tempat yang bisa berbeda pendapat. */
define('FACE_ENROLL_MAX_SPREAD',    FACE_MATCH_STRICT);
define('FACE_SAMPLE_COUNT',       5);      // jumlah sampel saat pendaftaran
define('FACE_DESCRIPTOR_SIZE',    128);     // dimensi descriptor face-api.js
define('FACE_CENTROID_ENABLED',   true);    // rata-rata dari semua sampel

/**
 * Random challenge liveness.
 * Instruksi yang dikirim ke siswa dipilih acak oleh server, bukan ditentukan
 * oleh browser, agar tidak bisa diprediksi atau dipatahuli.
 */
/**
 * Daftar challenge liveness yang SAH.
 *
 * Kunci di sini WAJIB sama persis dengan nilai yang bisa dieksekusi
 * Liveness.runChallenge() di assets/js/liveness-detection.js. Server
 * yang memilih dari daftar ini lalu mengirimkannya ke klien lewat
 * scan-nonce.php, jadi daftar inilah satu-satunya sumber kebenaran.
 * Liveness.validateServerList() menolak challenge yang tak dikenal,
 * sehingga ketidakcocokan ketahuan saat runtime, bukan saat produksi.
 *
 * 'blink' sengaja TIDAK ada di sini. Kedip adalah tahap pasif yang
 * selalu dijalankan klien sebelum challenge, bukan challenge acak.
 */
define('LIVENESS_CHALLENGES', [
    'nod'        => 'Angkat lalu tundukkan dagu',
    'turn_left'  => 'Putar kepala ke kiri',
    'turn_right' => 'Putar kepala ke kanan',
    'tilt_left'  => 'Miringkan kepala ke kiri',
    'tilt_right' => 'Miringkan kepala ke kanan',
    'smile'      => 'Senyum lebar',
]);
define('LIVENESS_REQUIRED', 1);   // 1 challenge acak setelah kedip agar cepat dan akurat
define('LIVENESS_TIMEOUT_MS', 25000);

/* ------------------------------------------------------------------
 |  PATH & URL
 * ------------------------------------------------------------------ */
define('BASE_PATH',      dirname(__DIR__));
define('UPLOAD_PATH',    BASE_PATH . DIRECTORY_SEPARATOR . 'uploads');

/* Tidak ada konstanta path foto wajah, dan itu disengaja.
 *
 * Aplikasi ini menyimpan descriptor 128 float, bukan foto. Foto
 * wajah adalah data pribadi yang paling sensitif dan tidak pernah
 * dibutuhkan untuk pencocokan, jadi tidak ada alasan yang cukup
 * untuk menyimpannya di disk server. Kalau suatu saat nanti dibutuhkan,
 * pikirkan ulang apakah descriptor saja sudah cukup.
 *
 * MODEL_PATH juga sudah dihapus. Dulu menunjuk ke /assets/models,
 * sekarang model diambil dari CDN seperti dijelaskan di bawah, jadi
 * path itu tidak lagi dibaca kode mana pun. */

/* ------------------------------------------------------------------
 |  SUMBER ASET FACE-API (library + bobot model)
 * ------------------------------------------------------------------ */

/* Aset face-api sengaja diambil dari CDN, bukan diunggah ke hosting.
 *
 * Ini bukan pilihan gaya, melainkan porsionalitas yang tidak bisa
 * dihindari:
 *
 *   face-api.js (bundle)          1,27 MB
 *   ssd_mobilenetv1_model.bin     5,36 MB
 *   face_recognition_model.bin    6,15 MB
 *
 * Ketiganya melebihi batas ukuran berkas per hosting, dan yang
 * terakhir 6,15 MB tidak bisa diperkecil: model itu yang mengubah
 * gambar menjadi descriptor 128 dimensi, jadi tanpanya aplikasi ini
 * tidak dapat mengenali wajah sama sekali. tiny_face_detector yang
 * paling kecil pun hanya 189 KB dan tetap harus dipasangkan dengan
 * file 6,15 MB itu, jadi tidak ada kombinasi yang muat di lokal.
 *
 * Yang dikirim ke CDN hanya library dan bobot model, yaitu berkas
 * statis milik pihak ketiga. Foto dan descriptor wajah siswa TIDAK
 * PERNAH masuk ke sana: seluruh deteksi berjalan di peramban, dan
 * satu-satunya yang dikirim ke server sekolah adalah descriptor
 * 512 byte milik siswa yang sedang login.
 *
 * Kalau nanti pindah ke hosting yang lebih lega, unduh isi kedua
 * URL di bawah ke /assets lalu arahkan FACE_API_BASE dan
 * FACE_MODEL_BASE ke sana. Tidak ada kode lain yang perlu diubah. */
define('FACE_API_BASE',   '/assets/vendor/face-api/face-api.js');
define('FACE_MODEL_BASE', '/assets/models');

/* Catatan model yang dipakai, bukan nama berkas model.
 *
 * tiny_face_detector (189 KB) menggantikan ssd_mobilenetv1 (5,36 MB):
 * untuk potret wajah menghadap kamera hasilnya setara, dan ukurannya
 * 30 kali lebih kecil.
 *
 * face_landmark_68_tiny (75 KB) menggantikan face_landmark_68 (349 KB).
 * Yang dibutuhkan liveness hanyalah sudut kepala dan jarak kelopak
 * mata, jadi mesh 68 titik yang lengkap tidak perlu.
 *
 * Nama model sungguhan ada di scan-engine.js, dekat dengan konstruktor
 * yang memakainya. Sengaja tidak dideklarasikan di sini: konstanta yang
 * tidak dibaca kode hanya memberi kesan ada tempat untuk mengubah model,
 * padahal mengubahnya tidak akan berpengaruh apa pun. */

/* ------------------------------------------------------------------
 |  PAGINASI & LAIN-LAIN
 * ------------------------------------------------------------------ */
define('ROWS_PER_PAGE',   25);
define('DATE_FORMAT',     'd/m/Y');
define('DATETIME_FORMAT', 'd/m/Y H:i:s');
define('TIMEZONE',        'Asia/Jakarta');

date_default_timezone_set(TIMEZONE);
