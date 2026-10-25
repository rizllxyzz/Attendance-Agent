<?php
/**
 * siswa/_bootstrap.php
 * ---------------------------------------------------------------------------
 * Persiapan bersama untuk seluruh halaman siswa: sesi, identitas, dan
 * pemformat tampilan.
 *
 * Dipakai oleh index.php, riwayat.php, statistik.php, izin.php, dan
 * profil.php. Semua halaman memuat file ini paling atas, jadi aturan
 * "wajib punya sesi" cukup ditulis satu kali di sini.
 *
 * Data ringan yang dipakai header (nama, kelas, nama sekolah, status Face
 * ID) di sini. Kueri berat per halaman sengaja TIDAK diletakkan di sini,
 * supaya riwayat.php tidak ikut membayar statistik yang tidak dibutuhkannya.
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/app-config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helper.php';
require_once __DIR__ . '/../config/lang.php';
require_once __DIR__ . '/../auth/session.php';

require_login('siswa');

$siswaSesi = current_user();
$userId    = (int) $siswaSesi['id'];

/* ------------------------------------------------------------------
 *  IDENTITAS
 *  ------------------------------------------------------------------
 * Data di $_SESSION sengaja dibuat sekecil mungkin, jadi NIS/NISN dan
 * nama kelas TIDAK ada di sana. Membacanya dari sesi seperti yang
 * dilakukan versi lama menghasilkan "NISN -" selamanya tanpa error,
 * karena kunci itu memang tidak pernah disimpan. Jadi baris lengkap
 * diambil ulang dari database.
 *
 * Sekalian,jika akun dinonaktifkan administrator di tengah sesi, pengguna
 * langsung dikeluarkan, bukan menampilkan halaman yang sudah basi.
 * ------------------------------------------------------------------ */
$barisUser = db_one(
    'SELECT id, username, nama_lengkap, nis, nisn, kelas_id, jenis_kelamin, last_login
       FROM users
      WHERE id = ? AND role = ? AND is_active = 1',
    [$userId, 'siswa'],
    null
);

if (!$barisUser) {
    logout_user();
    header('Location: /auth/login.php');
    exit;
}

$nama      = (string) ($barisUser['nama_lengkap'] ?: $barisUser['username']);
$namaRingkas = explode(' ', trim($nama))[0];
$username  = (string) $barisUser['username'];

/* NISN dipakai sebagai identitas utama, tapi tidak semua sekolah sudah
 * mengisinya. Urutan fallback-nya dari yang paling otoritatif ke yang
 * paling umum ada. */
$nomorIndentitas = '';
foreach (['nisn', 'nis', 'username'] as $kunci) {
    $nilai = trim((string) ($barisUser[$kunci] ?? ''));
    if ($nilai !== '') {
        $nomorIndentitas = $nilai;
        $labelIndentitas = ($kunci === 'nisn') ? 'NISN' : (($kunci === 'nis') ? 'NIS' : 'Username');
        break;
    }
}
$labelIndentitas = $labelIndentitas ?? 'Username';

$jenisKelamin = (string) ($barisUser['jenis_kelamin'] ?? '');
$jkLabel      = $jenisKelamin === 'P' ? t('gender.female')
              : ($jenisKelamin === 'L' ? t('gender.male') : t('gender.none'));

/* Inisial untuk avatar: dua huruf pertama, maksimal dua kata. */
$initials = '';
foreach (array_slice(preg_split('/\s+/u', trim($nama)) ?: [], 0, 2) as $kata) {
    if ($kata !== '') {
        $initials .= mb_substr($kata, 0, 1);
    }
}
$initials = strtoupper($initials ?: 'S');

/* ------------------------------------------------------------------
 *  KELAS
 *  ------------------------------------------------------------------ */
$kelasNama = '';
$kelasId   = $barisUser['kelas_id'] !== null ? (int) $barisUser['kelas_id'] : 0;
if ($kelasId > 0) {
    $kelas = db_one('SELECT nama_kelas FROM kelas WHERE id = ?', [$kelasId], null);
    if ($kelas) {
        $kelasNama = (string) $kelas['nama_kelas'];
    }
}

/* ------------------------------------------------------------------
 *  NAMA SEKOLAH & LOKASI
 *  ------------------------------------------------------------------
 * Nama sekolah diambil dari tabel pengaturan, bukan dari lokasi_sekolah.
 * Isi lokasi_sekolah adalah nama GERBANG/patokan ("Tanjung Juwiring
 * Klaten"), bukan nama sekolah, jadi memakainya sebagai judul membuat
 * dashboard menampilkan nama lokasi di tempat nama sekolah seharusnya.
 * ------------------------------------------------------------------ */
$sekolahDb = db_value(
    "SELECT value FROM pengaturan WHERE key_name = 'nama_sekolah'",
    [],
    null
);
$schoolName = trim((string) ($sekolahDb ?: '')) ?: SCHOOL_NAME;

$lokasi = db_one(
    'SELECT nama, lat, lng, radius_m FROM lokasi_sekolah WHERE is_active = 1 ORDER BY id LIMIT 1',
    [],
    null
);
$lokasiNama   = $lokasi ? (string) $lokasi['nama'] : t('siswa.location_default');
$lokasiRadius = $lokasi ? (int) $lokasi['radius_m'] : (int) SEKOLAH_RADIUS_M;

/* ------------------------------------------------------------------
 *  STATUS PENDAFTARAN WAJAH
 * ------------------------------------------------------------------
 * Wajah yang dipakai pencocokan adalah centroid (is_centroid = 1).
 * ------------------------------------------------------------------ */
$wajah = db_one(
    'SELECT id, quality, created_at FROM face_templates
      WHERE user_id = ? AND is_centroid = 1 ORDER BY id DESC LIMIT 1',
    [$userId],
    null
);
$wajahTerdaftar = $wajah !== null;
$wajahMutu      = $wajah ? (float) $wajah['quality'] : 0.0;
$wajahSejak     = $wajah ? (string) $wajah['created_at'] : '';

/* ------------------------------------------------------------------
 *  WAKTU SAAT INI
 * ------------------------------------------------------------------ */
$tz  = new DateTimeZone(TIMEZONE);
$now = new DateTimeImmutable('now', $tz);

$today   = $now->format('Y-m-d');
$clockNow = $now->format('H:i');

/* Epoch server dalam detik. Dikirim ke browser supaya jam yang
 * ditampilkan mengikuti waktu server, bukan jam perangkat yang bisa
 * saja salah setel. */
$epochServer = (int) $now->format('U');

$jamServer = (int) $now->format('H');
$salam = $jamServer < 11 ? 'Selamat pagi'
       : ($jamServer < 15 ? 'Selamat siang'
       : ($jamServer < 18 ? 'Selamat sore' : 'Selamat malam'));

/* Jadwal absensi hari ini, dipakai untuk memberi tahu siswa sedang
 * berada di jendela masuk, jendela pulang, atau di sela keduanya. */
$faseHari = 'sebelah-pulang';
if (attendance_type($clockNow) === 'masuk') {
    $faseHari = 'masuk';
} elseif (attendance_type($clockNow) === 'pulang') {
    $faseHari = 'pulang';
} elseif (strtotime($clockNow) < strtotime(JAM_MASUK_MULAI)) {
    $faseHari = 'sebelum-masuk';
}

/* ==================================================================
 *  TABEL PENGAJULAN IZIN / SAKIT
 * ================================================================== */

/**
 * True kalau tabel izin_requests sudah ada.
 *
 * Instalasi yang dibuat sebelum fitur ini tidak punya tabel tersebut.
 * Pemanggil yang hanya membaca memakai hasil fungsi ini untuk
 * menampilkan "fitur belum tersedia" alih-alih error SQL. Fungsi ini
 * sengaja tidak pernah membuat tabel, karena dashboard tidak boleh
 * punya efek samping hanya karena dibuka.
 *
 * $segarkan dipakai oleh izin_pastikan_tabel() setelah berhasil membuat
 * tabel: tanpa itu, hasil cache lama (false) akan terus dikembalikan
 * walaupun tabelnya sudah ada.
 */
function izin_tabel_ada(bool $segarkan = false): bool
{
    static $cache = null;
    if (!$segarkan && $cache !== null) {
        return $cache;
    }

    try {
        if (db_driver() === 'sqlite') {
            $ada = (int) db_value(
                "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'izin_requests'",
                [],
                0
            ) > 0;
        } else {
            $ada = db_driver() === 'pgsql'
                ? (int) db_value("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = ?", ['izin_requests'], 0) > 0
                : (int) db_value("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?", ['izin_requests'], 0) > 0;
        }
        $cache = $ada;
    } catch (Throwable $e) {
        $cache = false;
    }

    return $cache;
}

/**
 * Pastikan tabel izin_requests ada, lalu kembalikan true.
 *
 * Dipanggil hanya dari halaman izin, saat siswa benar-benar mengirim
 * pengajuan. Pembuatan tabel dibungkus try/catch karena di hosting
 * dengan hak akses SQL terbatas CREATE TABLE bisa gagal, dan itu tidak
 * boleh menjatuhkan halaman.
 */
function izin_pastikan_tabel(): bool
{
    if (izin_tabel_ada()) {
        return true;
    }

    $kunci = db_driver() === 'sqlite'
        ? "TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP"
        : "DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP";

    try {
        db()->exec(
            'CREATE TABLE IF NOT EXISTS izin_requests ('
            /* pk() sudah memuat klausa PRIMARY KEY di kedua driver,
             * jadi PRIMARY KEY tidak boleh ditulis lagi terpisah. */
            . 'id ' . pk() . ','
            . 'user_id ' . iu() . ' NOT NULL,'
            . 'jenis ' . enum_col(['izin', 'sakit'], 'izin') . ','
            . 'tanggal_mulai DATE NOT NULL,'
            . 'tanggal_selesai DATE NOT NULL,'
            . 'alasan VARCHAR(500) DEFAULT NULL,'
            . 'status ' . enum_col(['menunggu', 'disetujui', 'ditolak'], 'menunggu') . ','
            . 'catatan_petugas VARCHAR(500) DEFAULT NULL,'
            . 'diproses_oleh ' . iu() . ' DEFAULT NULL,'
            . 'created_at ' . $kunci
            . ')'
            . suffix()
        );

        /* Index bersifat tambahan, bukan syarat jalan. Tanpa index,
           riwayat harus membaca seluruh tabel tiap kali dibuka. */
        foreach ([
            'CREATE INDEX IF NOT EXISTS ix_izin_user    ON izin_requests(user_id)',
            'CREATE INDEX IF NOT EXISTS ix_izin_status  ON izin_requests(status)',
            'CREATE INDEX IF NOT EXISTS ix_izin_periode ON izin_requests(tanggal_mulai, tanggal_selesai)',
        ] as $sqlIdx) {
            try {
                db()->exec($sqlIdx);
            } catch (Throwable $eIdx) {
                error_log('[IZIN] Index dilewati: ' . $eIdx->getMessage());
            }
        }
    } catch (Throwable $e) {
        error_log('[IZIN] Gagal menyiapkan tabel: ' . $e->getMessage());
        return false;
    }

    return izin_tabel_ada(true);
}

/* ------------------------------------------------------------------
 *  PERMINTAAN PINDAI ULANG WAJAH
 *  ------------------------------------------------------------------
 * Tabel face_requests dibuat panel admin (admin/_bootstrap.php). Portaly
 * siswa tidak pernah membuatnya: kalau tabelnya belum ada berarti memang
 * belum ada permintaan, dan bukan berarti ada kesalahan. Karena itu
 * pemeriksa hanya membaca, dan tidak pernah menulis ke tabel ini.
 * Closure-nya (permintaan -> selesai) dilakukan endpoint pendaftaran
 * wajah siswa, bukan halaman HTML.
 */

/**
 * Permintaan pindai ulang wajah yang masih menunggu untuk satu siswa.
 *
 * Kembalikan array kosong kalau tabelnya belum ada, sehingga pemanggil
 * bisa memakai hasil ini tanpa pemeriksaan tambahan.
 */
function wajah_permintaan_untuk(int $userId): array
{
    if ($userId <= 0) {
        return [];
    }

    try {
        $ada = db_driver() === 'sqlite'
            ? (int) db_value(
                "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'face_requests'",
                [],
                0
            )
            : (int) db_value("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?", ['face_requests'], 0);
        if ($ada < 1) {
            return [];
        }
    } catch (Throwable $e) {
        return [];
    }

    return db_all(
        "SELECT id, alasan, created_at FROM face_requests
          WHERE user_id = ? AND status = 'menunggu'
       ORDER BY created_at ASC, id ASC
          LIMIT 1",
        [$userId]
    );
}

/**
 * Ringkas user agent jadi nama peramban + sistem yang bisa dibaca.
 * String user agent sepanjang 150+ karakter tidak berguna ditampilkan,
 * tapi juga tidak boleh dipotong di tengah kata.
 */
function ringkas_agent(?string $ua): string
{
    $ua = trim((string) $ua);
    if ($ua === '') {
        return 'Perangkat tidak dikenal';
    }

    $peramban = 'Peramban';
    foreach ([
        'Edg'    => 'Edge',
        'OPR'    => 'Opera',
        'Chrome' => 'Chrome',
        'Firefox' => 'Firefox',
        'Safari' => 'Safari',
    ] as $tanda => $nama) {
        if (strpos($ua, $tanda) !== false) {
            $peramban = $nama;
            break;
        }
    }

    $sistem = 'Sistem tidak dikenal';
    foreach ([
        'Windows'  => 'Windows',
        'Android'  => 'Android',
        'iPhone'   => 'iOS',
        'iPad'     => 'iPadOS',
        'Mac OS X' => 'macOS',
        'Linux'    => 'Linux',
    ] as $tanda => $nama) {
        if (strpos($ua, $tanda) !== false) {
            $sistem = $nama;
            break;
        }
    }

    return $peramban . ' di ' . $sistem;
}

/* ==================================================================
 |  HELPER TAMPILAN
 | ================================================================== */

/** Escape output HTML. Dipakai untuk SEMUA nilai dari database/user. */
function esc($nilai): string
{
    return htmlspecialchars((string) $nilai, ENT_QUOTES, 'UTF-8');
}

function nama_hari(int $index): string
{
    return lang_day_name($index);
}

function nama_bulan(int $index): string
{
    return lang_month_name($index);
}

/** "Senin, 29 September 2026" */
function tgl_panjang(?string $tanggal): string
{
    if (empty($tanggal)) {
        return '-';
    }
    $ts = strtotime($tanggal);
    if ($ts === false) {
        return '-';
    }
    return nama_hari((int) date('w', $ts)) . ', ' . date('j', $ts)
        . ' ' . nama_bulan((int) date('n', $ts)) . ' ' . date('Y', $ts);
}

/** "29 Sep 2026" */
function tgl_pendek(?string $tanggal): string
{
    if (empty($tanggal)) {
        return '-';
    }
    $ts = strtotime($tanggal);
    if ($ts === false) {
        return '-';
    }
    return date('j', $ts) . ' ' . substr(nama_bulan((int) date('n', $ts)), 0, 3) . ' ' . date('Y', $ts);
}

/**
 * Label tanggal yang enak dibaca: "Hari ini", "Kemarin", lalu tanggal.
 * Butuh $today supaya perbandingan tidak bergantung pada zona waktu
 * browser yang bisa berbeda dari zona server.
 */
function lbl_tanggal(?string $tanggal, string $today): string
{
    if (empty($tanggal)) {
        return '-';
    }
    $ts = strtotime($tanggal);
    if ($ts === false) {
        return '-';
    }
    $tsHariIni = strtotime($today);
    if ($ts === $tsHariIni) {
        return t('time.today');
    }
    if ($ts === strtotime('-1 day', $tsHariIni)) {
        return t('time.prev_day');
    }
    return nama_hari((int) date('w', $ts)) . ', ' . date('j', $ts) . ' ' . substr(nama_bulan((int) date('n', $ts)), 0, 3);
}

/** "20:07" dari kolom waktu yang berupa DATETIME. */
function jam_potong(?string $waktu): string
{
    if (empty($waktu)) {
        return '--:--';
    }
    $ts = strtotime($waktu);
    return $ts === false ? '--:--' : date('H:i', $ts);
}

/** Selisih hari antara dua tanggal Y-m-d, tanpa dipengaruhi jam. */
function selisih_hari(string $dari, string $sampai): int
{
    $a = strtotime($dari . ' 00:00:00');
    $b = strtotime($sampai . ' 00:00:00');
    if ($a === false || $b === false) {
        return 0;
    }
    return (int) round(($b - $a) / 86400);
}

/**
 * Badge status absensi.
 * $tipe dipakai untuk membedakan "Hadir" (scan masuk) dari "Tercatat"
 * (scan pulang), karena keduanya berstatus 'hadir' di database.
 */
function badge_absen(?array $baris, string $tipe = 'masuk'): string
{
    if (!$baris) {
        return '<span class="badge badge--muted"><i class="dot"></i>' . te('status.belum') . '</span>';
    }

    if ($tipe === 'masuk' && !empty($baris['is_late'])) {
        $menit = (int) ($baris['late_minutes'] ?? 0);
        return '<span class="badge badge--warn"><i class="dot"></i>' . te('status.terlambat')
            . ($menit > 0 ? ' ' . $menit . '\'\'\'' : '') . '</span>';
    }

    $status = strtolower((string) ($baris['status'] ?? 'hadir'));
    if ($status === 'izin' || $status === 'sakit') {
        return '<span class="badge badge--info"><i class="dot"></i>' . te('status.' . $status) . '</span>';
    }
    if ($status === 'alpa') {
        return '<span class="badge badge--danger"><i class="dot"></i>' . te('status.alpa') . '</span>';
    }

    return '<span class="badge badge--ok"><i class="dot"></i>'
        . ($tipe === 'masuk' ? te('status.hadir') : te('status.tercatat')) . '</span>';
}

/** Badge untuk status pengajuan izin. */
function badge_izin(string $status): string
{
    switch ($status) {
        case 'disetujui':
            return '<span class="badge badge--ok"><i class="dot"></i>' . te('status.disetujui') . '</span>';
        case 'ditolak':
            return '<span class="badge badge--danger"><i class="dot"></i>' . te('status.ditolak') . '</span>';
        default:
            return '<span class="badge badge--warn"><i class="dot"></i>' . te('status.menunggu') . '</span>';
    }
}

/** Keterangan singkat hasil verifikasi lokasi dari baris attendance. */
function ket_gps(?array $baris): string
{
    if (!$baris) {
        return '-';
    }
    $verdict = (string) ($baris['gps_verdict'] ?? '');
    $jarak   = isset($baris['distance_m']) && $baris['distance_m'] !== null
        ? fmt_distance((float) $baris['distance_m'])
        : '-';

    if ($verdict === 'DITERIMA') {
        return t('gps.verified') . ' · ' . $jarak;
    }
    if ($verdict === 'PERLU_DITINJAU') {
        return t('gps.review') . ' · ' . $jarak;
    }
    return t('gps.rejected') . ' · ' . $jarak;
}

/**
 * Berapa hari kerja (Senin-Jumat) antara dua tanggal, inklusif.
 * Menghitung hariEfektif memakai loop, bukan aritmetika, karena batas
 * bulan dan tahun kabur membuat kesalahan propagasi mudah terjadi.
 * Rentang dipakai dashboard hanya satu bulan, jadi biayanya tidak
 * terasa.
 */
function hari_kerja(string $dari, string $sampai): int
{
    $jumlah = 0;
    $d = new DateTimeImmutable($dari . ' 00:00:00', new DateTimeZone(TIMEZONE));
    $batas = new DateTimeImmutable($sampai . ' 00:00:00', new DateTimeZone(TIMEZONE));
    while ($d <= $batas) {
        if ((int) $d->format('N') <= 5) {
            $jumlah++;
        }
        $d = $d->modify('+1 day');
    }
    return $jumlah;
}

/**
 * Hitung rekap satu bulan untuk seorang siswa.
 * Mengembalikan array yang sudah dihitung turunannya, supaya halaman
 * statistik dan dashboard memakai definisi angka yang sama persis.
 *
 * Dua aturan ditegakkan DI DALAM fungsi ini, bukan di pemanggilnya, supaya
 * halaman baru tidak bisa salah diam-diam:
 *
 *  1. Tanggal setelah hari ini tidak pernah dihitung. Tanpa ini, membuka
 *     "riwayat bulan ini" tanggal 3 akan menampilkan alpa 22 hari.
 *  2. Hari yang sudah disetujui sebagai izin/sakit dikeluarkan dari
 *     hitungan hari efektif, jadi tidak dihitung sebagai alpa.
 */
function rekap_bulan(int $userId, string $awal, string $akhir): array
{
    $tz = new DateTimeZone(defined('TIMEZONE') ? TIMEZONE : 'Asia/Jakarta');
    $today = (new DateTimeImmutable('now', $tz))->format('Y-m-d');
    if ($akhir > $today) {
        $akhir = $today;
    }
    if ($akhir < $awal) {
        $akhir = $awal;
    }

    $baris = db_all(
        "SELECT tanggal, scan_type, waktu, status, is_late, late_minutes, gps_verdict
           FROM attendance
          WHERE user_id = ? AND tanggal >= ? AND tanggal <= ?",
        [$userId, $awal, $akhir]
    );

    $perTanggal = [];
    foreach ($baris as $b) {
        $tgl = (string) $b['tanggal'];
        if (!isset($perTanggal[$tgl])) {
            $perTanggal[$tgl] = [
                'tanggal'   => $tgl,
                'masuk'     => null,
                'pulang'    => null,
                'is_late'   => false,
                'late_menit'=> 0,
                'gps'       => null,
            ];
        }
        $slot = $b['scan_type'] === 'pulang' ? 'pulang' : 'masuk';
        $perTanggal[$tgl][$slot] = $b;
        if (!empty($b['is_late'])) {
            $perTanggal[$tgl]['is_late'] = true;
            $perTanggal[$tgl]['late_menit'] = (int) ($b['late_minutes'] ?? 0);
        }
        if ($perTanggal[$tgl]['gps'] === null) {
            $perTanggal[$tgl]['gps'] = (string) ($b['gps_verdict'] ?? '');
        }
    }
    krsort($perTanggal);

    $hadir = $telat = 0;
    foreach ($perTanggal as $harian) {
        if ($harian['masuk'] !== null || $harian['pulang'] !== null) {
            $hadir++;
            if ($harian['is_late']) {
                $telat++;
            }
        }
    }

    /* Aturan 2: hari yang disetujui petugas tidak dihitung sebagai alpa.
     * Pengajuan yang masih menunggu TIDAK ikut dihitung, karena selama
     * statusnya belum disetujui siswa tetap dianggap bertanggung jawab.
     *
     * Peta ini sengaja TIDAK dimasukkan ke $perTanggal: yang di sana
     * selalu berarti "ada scan pada tanggal itu", dan menyampurkannya
     * akan merusak hitungan 'hari' serta pencarian per tanggal di riwayat. */
    $disetujui = hari_izin_disetujui($userId, $awal, $akhir);

    $efektif = hari_kerja($awal, $akhir) - count($disetujui);
    if ($efektif < 0) {
        $efektif = 0;
    }
    $persen   = $efektif > 0 ? (int) round(($hadir / $efektif) * 100) : 0;

    return [
        'perTanggal'  => $perTanggal,
        'izinTanggal' => $disetujui,
        'hari'        => count($perTanggal),
        'hadir'       => $hadir,
        'telat'       => $telat,
        'tepat'       => $hadir - $telat,
        'efektif'     => $efektif,
        'izin'        => count($disetujui),
        'alpa'        => max(0, $efektif - $hadir),
        'persen'      => $persen,
    ];
}

/**
 * Kumpulan tanggal dalam [awal, akhir] yang sudah disetujui sebagai
 * izin/sakit, dipetakan ke jenisnya. Hanya hari kerja yang dihitung:
 * izin di hari Sabtu tidak boleh mengurangi jumlah hari efektif.
 *
 * Pengajuan yang saling tumpang tindih tidak dihitung dua kali, karena
 * hasilnya dipakai untuk mengurangi sebuah angka.
 */
function hari_izin_disetujui(int $userId, string $awal, string $akhir): array
{
    if (!izin_tabel_ada()) {
        return [];
    }

    try {
        $rows = db_all(
            "SELECT jenis, tanggal_mulai, tanggal_selesai
               FROM izin_requests
              WHERE user_id = ? AND status = 'disetujui'
                AND tanggal_mulai <= ? AND tanggal_selesai >= ?",
            [$userId, $akhir, $awal]
        );
    } catch (Throwable $e) {
        /* Instalasi lama atau skema belum siap: perlakukan sebagai tidak
         * ada izin, jangan gagalkan seluruh halaman. */
        return [];
    }

    $tz  = new DateTimeZone(TIMEZONE);
    $hasil = [];

    foreach ($rows as $r) {
        $dari = max($awal, (string) $r['tanggal_mulai']);
        $sampai = min($akhir, (string) $r['tanggal_selesai']);
        if ($sampai < $dari) {
            continue;
        }

        $jenis = $r['jenis'] === 'sakit' ? 'sakit' : 'izin';
        $d = new DateTimeImmutable($dari . ' 00:00:00', $tz);
        $batas = new DateTimeImmutable($sampai . ' 00:00:00', $tz);

        while ($d <= $batas) {
            if ((int) $d->format('N') <= 5) {
                $hasil[$d->format('Y-m-d')] = $jenis;
            }
            $d = $d->modify('+1 day');
        }
    }

    return $hasil;
}
