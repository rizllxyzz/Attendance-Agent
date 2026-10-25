<?php
/**
 * admin/_bootstrap.php
 * ---------------------------------------------------------------------------
 * Persiapan bersama untuk seluruh halaman panel admin: sesi, peran, identitas,
 * menu, dan angka kecil yang dipakai header.
 *
 * Mirip siswa/_bootstrap.php, tapi sengaja TIDAK memuat file itu. Keduanya
 * punya fungsi dengan nama sama (esc, nama_hari, tgl_panjang, ...) yang
 * isinya berbeda konteks, dan memuat keduanya dalam satu request akan
 * berhenti dengan "cannot redeclare".
 *
 * Halaman pemanggil cukup:
 *   1. require_once __DIR__ . '/_bootstrap.php'
 *   2. set $pageKey, $pageTitle, $pageSub
 *   3. set $aksen (label kecil di header) bila perlu
 *   4. include templates/admin-head.php
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/app-config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helper.php';
require_once __DIR__ . '/../config/laporan.php';
require_once __DIR__ . '/../config/lang.php';
require_once __DIR__ . '/../auth/session.php';

require_login('admin', 'guru');

$adminSesi = current_user();
$adminId   = (int) $adminSesi['id'];
$adminRole = (string) $adminSesi['role'];

/* Guru tidak boleh menyentuh data master. Satu sumber kebenaran supaya tidak
 * ada halaman yang lupa menyembunyikan tombol admin. */
$isAdmin = ($adminRole === 'admin');

/* ------------------------------------------------------------------
 *  IDENTITAS
 *  ------------------------------------------------------------------ */
$barisUser = db_one(
    'SELECT id, username, nama_lengkap, is_active FROM users WHERE id = ? LIMIT 1',
    [$adminId],
    null
);

/* Akun dihapus atau dinonaktifkan di tengah sesi harus langsung dikeluarkan,
 * bukan menampilkan dasbor basi sampai sesi habis. */
if (!$barisUser || (int) $barisUser['is_active'] !== 1) {
    logout_user();
    header('Location: /auth/login.php');
    exit;
}

$adminNama = (string) ($barisUser['nama_lengkap'] ?: $barisUser['username']);
$adminUser = (string) $barisUser['username'];

$initials = '';
foreach (array_slice(preg_split('/\s+/u', trim($adminNama)) ?: [], 0, 2) as $kata) {
    if ($kata !== '') {
        $initials .= mb_substr($kata, 0, 1);
    }
}
$initials = strtoupper($initials ?: 'A');

$peranLabel = [
    'admin' => t('role.admin'),
    'guru'  => t('role.guru'),
][$adminRole] ?? ucfirst($adminRole);

/* ------------------------------------------------------------------
 *  SEKOLAH & WAKTU
 *  ------------------------------------------------------------------ */
$sekolahDb = db_value("SELECT value FROM pengaturan WHERE key_name = 'nama_sekolah'", [], null);
$schoolName = trim((string) ($sekolahDb ?: '')) ?: SCHOOL_NAME;

$tz          = new DateTimeZone(TIMEZONE);
$now         = new DateTimeImmutable('now', $tz);
$today       = $now->format('Y-m-d');
$clockNow    = $now->format('H:i');
$epochServer = (int) $now->format('U');

$jamServer = (int) $now->format('H');
$salam = $jamServer < 11 ? t('greet.morning')
       : ($jamServer < 15 ? t('greet.noon')
       : ($jamServer < 18 ? t('greet.afternoon') : t('greet.evening')));

/* Nilai ambang yang dipakai halaman Uji Akurasi, ditampilkan sebagai
 * informasi supaya admin tahu angka yang sedang berlaku. */
$cfgAmbangWajah = (float) FACE_MATCH_THRESHOLD;
$cfgRadiusGps   = (float) SEKOLAH_RADIUS_M;
$cfgToleransiTelat = (int) TOLERANSI_TELAT_MENIT;

/* ------------------------------------------------------------------
 *  TABEL YANG MUNGKIN BELUM ADA
 *  ------------------------------------------------------------------
 * izin_requests dibuat oleh halaman izin sisi siswa. Panel admin membacanya
 * dan menulisnya, jadi tabel dipastikan ada di sini.
 * face_requests adalah tabel baru milik panel admin: catatan permintaan
 * pindai ulang wajah dari petugas ke siswa.
 *
 * Pola "pastikan tabel hanya saat halaman membutuhkannya" mengikuti
 * izin_pensure_tabel() di siswa/_bootstrap.php. Dashboard tidak boleh punya
 * efek samping hanya karena dibuka, jadi admin_tabel_pakai() dipanggil
 * eksplisit oleh halaman yang benar-benar memakai tabel tersebut.
 */
function admin_izin_tabel_ada(bool $segarkan = false): bool
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

function admin_wajah_tabel_ada(bool $segarkan = false): bool
{
    static $cache = null;
    if (!$segarkan && $cache !== null) {
        return $cache;
    }
    try {
        if (db_driver() === 'sqlite') {
            $ada = (int) db_value(
                "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'face_requests'",
                [],
                0
            ) > 0;
        } else {
            $ada = db_driver() === 'pgsql'
                ? (int) db_value("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = ?", ['face_requests'], 0) > 0
                : (int) db_value("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?", ['face_requests'], 0) > 0;
        }
        $cache = $ada;
    } catch (Throwable $e) {
        $cache = false;
    }
    return $cache;
}

/**
 * Pastikan tabel izin_requests dan face_requests ada.
 * Dipanggil dari halaman yang membutuhkannya, bukan dari dashboard.
 * Kegagalan tidak menjatuhkan halaman: pemanggil akan menampilkan
 * "fitur belum tersedia" alih-alih halaman putih.
 */
function admin_tabel_pakai(): bool
{
    if (isset($GLOBALS['admin_tabel_cache'])) {
        return (bool) $GLOBALS['admin_tabel_cache'];
    }

    $ok = true;
    $kunci = db_driver() === 'sqlite'
        ? 'TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP'
        : 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP';

    if (!admin_izin_tabel_ada()) {
        try {
            db()->exec(
                'CREATE TABLE IF NOT EXISTS izin_requests ('
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
                . ')' . suffix()
            );
        } catch (Throwable $e) {
            error_log('[ADMIN] Gagal menyiapkan izin_requests: ' . $e->getMessage());
            $ok = false;
        }
        admin_izin_tabel_ada(true);
    }

    if (!admin_wajah_tabel_ada()) {
        try {
            $nullable = db_driver() === 'sqlite' ? 'TEXT DEFAULT NULL' : 'DATETIME DEFAULT NULL';
            db()->exec(
                'CREATE TABLE IF NOT EXISTS face_requests ('
                . 'id ' . pk() . ','
                . 'user_id ' . iu() . ' NOT NULL,'
                . 'alasan VARCHAR(300) DEFAULT NULL,'
                . 'status ' . enum_col(['menunggu', 'selesai', 'batal'], 'menunggu') . ','
                . 'diminta_oleh ' . iu() . ' DEFAULT NULL,'
                . 'created_at ' . $kunci . ','
                . 'selesai_at ' . $nullable
                . ')' . suffix()
            );
            db()->exec('CREATE INDEX IF NOT EXISTS ix_wajah_req_user ON face_requests(user_id)');
        } catch (Throwable $e) {
            error_log('[ADMIN] Gagal menyiapkan face_requests: ' . $e->getMessage());
            $ok = false;
        }
        admin_wajah_tabel_ada(true);
    }

    $GLOBALS['admin_tabel_cache'] = $ok;
    return $ok;
}

/* ------------------------------------------------------------------
 *  MENU
 *  ------------------------------------------------------------------
 * butuh = [] berarti semua orang yang sudah masuk boleh. Selain itu daftar
 * peran yang boleh. "mobile" menentukan item yang masuk tab bar HP.
 *
 * Tautan hanya ditampilkan kalau filenya benar-benar ada: admin yang diklik
 * lalu mendapat 404 jauh lebih membingungkan daripada tidak ditautkan.
 */
$menu = [
    ['kunci' => 'beranda', 'href' => '/admin/index.php', 'label' => t('nav.beranda'), 'icon' => 'fa-house', 'mobile' => true, 'butuh' => []],
    ['kunci' => 'siswa', 'href' => '/admin/siswa.php', 'label' => t('nav.siswa'), 'icon' => 'fa-users', 'mobile' => true, 'butuh' => []],
    ['kunci' => 'akun', 'href' => '/admin/akun.php', 'label' => t('nav.akun'), 'icon' => 'fa-user-tie', 'mobile' => false, 'butuh' => ['admin']],
    ['kunci' => 'kelas', 'href' => '/admin/kelas.php', 'label' => t('nav.kelas'), 'icon' => 'fa-chalkboard-user', 'mobile' => false, 'butuh' => []],
    ['kunci' => 'impor', 'href' => '/admin/impor.php', 'label' => t('nav.impor'), 'icon' => 'fa-file-import', 'mobile' => false, 'butuh' => ['admin']],
    ['kunci' => 'izin', 'href' => '/admin/izin.php', 'label' => t('nav.izin'), 'icon' => 'fa-file-signature', 'mobile' => true, 'butuh' => []],
    ['kunci' => 'absensi', 'href' => '/admin/absensi.php', 'label' => t('nav.absensi'), 'icon' => 'fa-clipboard-check', 'mobile' => true, 'butuh' => []],
    ['kunci' => 'wajah', 'href' => '/admin/wajah.php', 'label' => t('nav.wajah'), 'icon' => 'fa-face-smile', 'mobile' => false, 'butuh' => []],
    ['kunci' => 'laporan', 'href' => '/admin/laporan.php', 'label' => t('nav.laporan'), 'icon' => 'fa-shield-heart', 'mobile' => true, 'butuh' => []],
    ['kunci' => 'agent', 'href' => '/admin/agent.php', 'label' => t('nav.agent'), 'icon' => 'fa-robot', 'mobile' => false, 'butuh' => []],
    ['kunci' => 'zetaris', 'href' => '/admin/zetaris.php', 'label' => 'Integrasi Zetaris', 'icon' => 'fa-plug', 'mobile' => false, 'butuh' => ['admin']],
    ['kunci' => 'pengaturan', 'href' => '/admin/pengaturan.php', 'label' => t('nav.pengaturan'), 'icon' => 'fa-gear', 'mobile' => false, 'butuh' => ['admin']],
    ['kunci' => 'backup', 'href' => '/admin/backup.php', 'label' => t('nav.backup'), 'icon' => 'fa-database', 'mobile' => false, 'butuh' => ['admin']],
    ['kunci' => 'reports', 'href' => '/admin/reports.php', 'label' => 'Laporan Ekspor', 'icon' => 'fa-file-export', 'mobile' => false, 'butuh' => ['admin']],
    ['kunci' => 'ai-provider', 'href' => '/admin/ai-provider.php', 'label' => 'Koneksi AI', 'icon' => 'fa-plug', 'mobile' => false, 'butuh' => ['admin']],
];

$menu = array_values(array_filter($menu, static function ($m) use ($adminRole) {
    if ($m['butuh'] !== [] && !in_array($adminRole, $m['butuh'], true)) {
        return false;
    }
    return is_file(__DIR__ . '/' . basename($m['href']));
}));

/* ------------------------------------------------------------------
 *  PENCACAH UNTUK BADGE DI MENU
 *  ------------------------------------------------------------------
 * Semua dibungkus try/catch: instalasi lama belum tentu punya tabelnya,
 * dan badge yang hilang jauh lebih baik daripada halaman error.
 */
$tagIzin = 0;
if (admin_izin_tabel_ada()) {
    try {
        $tagIzin = (int) db_value(
            "SELECT COUNT(*) FROM izin_requests WHERE status = 'menunggu'",
            [],
            0
        );
    } catch (Throwable $e) {
        $tagIzin = 0;
    }
}

$tagWajah = 0;
if (admin_wajah_tabel_ada()) {
    try {
        $tagWajah = (int) db_value(
            "SELECT COUNT(*) FROM face_requests WHERE status = 'menunggu'",
            [],
            0
        );
    } catch (Throwable $e) {
        $tagWajah = 0;
    }
}

/* Laporan "Lapor Aman" yang belum disentuh. Inilah satu-satunya penanda
 * yang benar-benar perlu cepat dilihat petugas: laporan yang menunggu
 * terlalu lama kehilangan gunanya. Tabel dibuat oleh halaman siswa, jadi
 * di sini hanya dibaca. */
$tagLaporan = 0;
if (laporan_tabel_ada()) {
    try {
        $tagLaporan = (int) db_value(
            "SELECT COUNT(*) FROM laporan_aman WHERE status = 'baru'",
            [],
            0
        );
    } catch (Throwable $e) {
        $tagLaporan = 0;
    }
}

/* ------------------------------------------------------------------
 *  FLASH
 *  ------------------------------------------------------------------ */
$flash = null;
if (!empty($_SESSION['admin_flash'])) {
    $flash = $_SESSION['admin_flash'];
    unset($_SESSION['admin_flash']);
}

/** Simpan pesan sekali-pakai untuk halaman berikutnya. */
function admin_flash(string $tipe, string $pesan, ?string $ikon = null): void
{
    $peta = [
        'ok'    => 'fa-circle-check',
        'warn'  => 'fa-triangle-exclamation',
        'error' => 'fa-circle-xmark',
        'info'  => 'fa-circle-info',
    ];
    $_SESSION['admin_flash'] = [
        'tipe'  => $tipe,
        'pesan' => $pesan,
        'ikon'  => $ikon ?? ($peta[$tipe] ?? 'fa-circle-info'),
    ];
}

/** Kembali ke URL yang sedang dibuka supaya posisi scroll tidak hilang. */
function admin_kembali(string $anchor = ''): void
{
    $target = (string) ($_SERVER['REQUEST_URI'] ?? '/admin/index.php');
    if ($anchor !== '' && strpos($target, '#') === false) {
        $target .= '#' . $anchor;
    }
    header('Location: ' . $target);
    exit;
}

/**
 * Kembali ke halaman sekarang dengan query yang diubah.
 *
 * Dipakai setelah validasi POST gagal dan formulirnya ada di drawer:
 * halaman hanya membuka drawer kalau datang lewat ?edit=<id> atau
 * ?aksi=baru. admin_kembali() menambahkan anchor (#daftar) yang tidak
 * pernah dibaca PHP, jadi parameter halaman tidak akan ikut terbawa.
 *
 * Nilai anchor pada REQUEST_URI yang sudah ada dibuang supaya query
 * baru tidak menempel setelah tanda pagar.
 */
function admin_kembali_query(array $ubah): void
{
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/admin/index.php');
    $skrip = strtok($uri, '?#') ?: '/admin/index.php';
    $q = array_filter(array_merge($_GET, $ubah), static function ($v) {
        return $v !== '' && $v !== null;
    });
    header('Location: ' . $skrip . ($q !== [] ? '?' . http_build_query($q) : ''));
    exit;
}

/** Cegah POST dari halaman yang hanya boleh dibaca. */
function admin_hanya_post(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        exit('Metode tidak diizinkan.');
    }
    if (!csrf_check(post('csrf'))) {
        http_response_code(403);
        exit('Token keamanan tidak valid. Muat ulang halaman lalu coba lagi.');
    }
}

/** Menolak permintaan yang bukan POST + admin, dengan pesan yang jelas. */
function admin_hanya_admin(): void
{
    admin_hanya_post();
    if (!has_role('admin')) {
        admin_flash('error', 'Hanya administrator yang boleh melakukan tindakan ini.');
        admin_kembali();
    }
}

/**
 * Kirim CSV lalu berhenti.
 *
 * Dipisah dari halaman supaya filter yang sedang aktif ikut terbawa: file
 * yang diunduh selalu berisi data yang sama persis dengan yang terlihat di
 * layar, bukan versi dengan filter default.
 */
function admin_csv(array $baris, array $kepala, string $namaBerkas): void
{
    /* Berkas yang di-include kadang meninggalkan satu baris kosong di akhir
     * (misalnya karena ada tag ?> di baris terakhir). Satu byte itu sudah
     * cukup untuk membuat BOM tidak lagi di byte pertama, dan Excel akan
     * membuka file dengan pemisah kolom yang salah. Jadi buffer dibuang
     * dulu supaya file yang diunduh benar-benar dimulai dari BOM. */
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    /* BOM UTF-8 ditulis di depan supaya Excel di Windows tidak salah baca
     * karakter non-ASCII pada nama siswa menjadi tanda tanya. */
    $namaAkhir = preg_replace('/[^A-Za-z0-9._-]/', '-', $namaBerkas) ?: 'rekap';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $namaAkhir . '.csv"');
    header('Cache-Control: no-store');

    $keluar = fopen('php://output', 'wb');
    if ($keluar === false) {
        return;
    }
    fwrite($keluar, "\xEF\xBB\xBF");
    fputcsv($keluar, $kepala, ',', '"', '\\');
    foreach ($baris as $b) {
        fputcsv($keluar, array_map(static function ($v) {
            /* Cegah CSV injection: nilai yang diawali =, +, -, atau @ akan
             * dieksekusi sebagai formula kalau file dibuka di Excel. */
            $s = (string) ($v ?? '');
            return preg_match('/^[=+\-@\t\r]/', $s) ? "'" . $s : $s;
        }, $b), ',', '"', '\\');
    }
    fclose($keluar);
    exit;
}

/* ==================================================================
 *  HELPER TAMPILAN
 * ================================================================== */

/** Escape output HTML. Dilindungi function_exists() supaya aman bila
 *  suatu saat halaman admin ikut memuat _bootstrap.php siswa. */
if (!function_exists('esc')) {
    function esc($nilai): string
    {
        return htmlspecialchars((string) $nilai, ENT_QUOTES, 'UTF-8');
    }
}

function admin_nama_hari(int $index): string
{
    return lang_day_name($index);
}

function admin_nama_bulan(int $index): string
{
    return lang_month_name($index);
}

/** "Senin, 29 September 2026" */
function admin_tgl_panjang(?string $tanggal): string
{
    if (empty($tanggal)) {
        return '-';
    }
    $ts = strtotime($tanggal);
    if ($ts === false) {
        return '-';
    }
    return admin_nama_hari((int) date('w', $ts)) . ', ' . date('j', $ts)
        . ' ' . admin_nama_bulan((int) date('n', $ts)) . ' ' . date('Y', $ts);
}

/** "29 Sep 2026" */
function admin_tgl_pendek(?string $tanggal): string
{
    if (empty($tanggal)) {
        return '-';
    }
    $ts = strtotime($tanggal);
    if ($ts === false) {
        return '-';
    }
    $bulan = substr(admin_nama_bulan((int) date('n', $ts)), 0, 3);
    return date('j', $ts) . ' ' . $bulan . ' ' . date('Y', $ts);
}

/** "baru saja", "5 menit lalu", "2 jam lalu", lalu tanggal penuh. */
function admin_lampau(?string $waktu): string
{
    if (empty($waktu)) {
        return '-';
    }
    $ts = strtotime($waktu);
    if ($ts === false) {
        return '-';
    }
    $selisih = time() - $ts;
    if ($selisih < 60) {
        return t('time.now');
    }
    if ($selisih < 3600) {
        return t('time.minutes_ago', ['n' => (int) floor($selisih / 60)]);
    }
    if ($selisih < 86400) {
        return t('time.hours_ago', ['n' => (int) floor($selisih / 3600)]);
    }
    if ($selisih < 172800) {
        return t('time.yesterday');
    }
    return admin_tgl_pendek($waktu);
}

/** "20:07" dari kolom waktu bertipe DATETIME. */
function admin_jam(?string $waktu): string
{
    if (empty($waktu)) {
        return '--:--';
    }
    $ts = strtotime($waktu);
    return $ts === false ? '--:--' : date('H:i', $ts);
}

/** Badge status absensi hasil scan. */
function admin_badge_absen(string $status): string
{
    switch ($status) {
        case 'hadir':
            return '<span class="badge badge--ok"><i class="dot"></i>Hadir</span>';
        case 'izin':
            return '<span class="badge badge--info"><i class="dot"></i>Izin</span>';
        case 'sakit':
            return '<span class="badge badge--info"><i class="dot"></i>Sakit</span>';
        case 'alpha':
            return '<span class="badge badge--danger"><i class="dot"></i>Alpa</span>';
        default:
            return '<span class="badge badge--muted"><i class="dot"></i>' . esc($status) . '</span>';
    }
}

/** Nama kelas dari ID, atau teks netral kalau kelasnya sudah dihapus. */
function admin_nama_kelas(int $id): string
{
    static $cache = [];
    if (!array_key_exists($id, $cache)) {
        $nama = db_value('SELECT nama_kelas FROM kelas WHERE id = ?', [$id], null);
        $cache[$id] = $nama !== null ? (string) $nama : 'Kelas #' . $id;
    }
    return $cache[$id];
}

/**
 * Paginator.
 *
 * Satu fungsi untuk semua halaman supaya nomornya konsisten dan tautannya
 * selalu mempertahankan filter yang sedang aktif. Kalau totalnya masih
 * muat di satu halaman, tidak ada tombol yang sia-sia ditampilkan.
 */
function admin_pager(int $total, int $perHalaman, int $halaman, string $satuan = 'data'): string
{
    $totalHal = max(1, (int) ceil($total / max(1, $perHalaman)));
    if ($totalHal <= 1) {
        return '<div class="card__foot">' . (int) $total . ' ' . esc($satuan) . '</div>';
    }

    $halaman = max(1, min($halaman, $totalHal));
    $mulai   = ($halaman - 1) * $perHalaman;

    /* Jendela halaman:|max 7, dengan elipsis di kiri/kanan kalau masih
     * ada halaman yang terlewat. */
    $mulaiJendela = max(1, min($halaman - 3, $totalHal - 6));
    $selesaiJendela = min($totalHal, max(7, $halaman + 3));

    $html = '<div class="pager">'
        . '<span class="pager__info">Menampilkan ' . ($mulai + 1) . '&ndash;'
        . min($mulai + $perHalaman, $total) . ' dari ' . (int) $total . ' '
        . esc($satuan) . '</span>';

    $html .= $halaman > 1
        ? '<a href="' . esc(admin_ubah_query(['halaman' => $halaman - 1]))
            . '" aria-label="Halaman sebelumnya"><i class="fa-solid fa-chevron-left"></i></a>'
        : '<span class="is-off"><i class="fa-solid fa-chevron-left"></i></span>';

    if ($mulaiJendela > 1) {
        $html .= '<a href="' . esc(admin_ubah_query(['halaman' => 1])) . '">1</a>';
        if ($mulaiJendela > 2) {
            $html .= '<span class="is-off">&hellip;</span>';
        }
    }
    for ($h = $mulaiJendela; $h <= $selesaiJendela; $h++) {
        $html .= $h === $halaman
            ? '<span class="is-on">' . $h . '</span>'
            : '<a href="' . esc(admin_ubah_query(['halaman' => $h])) . '">' . $h . '</a>';
    }
    if ($selesaiJendela < $totalHal) {
        if ($selesaiJendela < $totalHal - 1) {
            $html .= '<span class="is-off">&hellip;</span>';
        }
        $html .= '<a href="' . esc(admin_ubah_query(['halaman' => $totalHal])) . '">' . $totalHal . '</a>';
    }

    $html .= $halaman < $totalHal
        ? '<a href="' . esc(admin_ubah_query(['halaman' => $halaman + 1]))
            . '" aria-label="Halaman berikutnya"><i class="fa-solid fa-chevron-right"></i></a>'
        : '<span class="is-off"><i class="fa-solid fa-chevron-right"></i></span>';

    return $html . '</div>';
}

/** Badge status pengajuan izin. */
function admin_badge_izin(string $status): string
{
    switch ($status) {
        case 'disetujui':
            return '<span class="badge badge--ok"><i class="dot"></i>Disetujui</span>';
        case 'ditolak':
            return '<span class="badge badge--danger"><i class="dot"></i>Ditolak</span>';
        case 'batal':
            return '<span class="badge badge--muted"><i class="dot"></i>Dibatalkan</span>';
        default:
            return '<span class="badge badge--warn"><i class="dot"></i>Menunggu</span>';
    }
}

/** Badge status permintaan pindai ulang wajah. */
function admin_badge_wajah(string $status): string
{
    switch ($status) {
        case 'selesai':
            return '<span class="badge badge--ok"><i class="dot"></i>Sudah didaftarkan</span>';
        case 'batal':
            return '<span class="badge badge--muted"><i class="dot"></i>Dibatalkan</span>';
        default:
            return '<span class="badge badge--warn"><i class="dot"></i>Menunggu siswa</span>';
    }
}

/* ------------------------------------------------------------------
 *  TAHUN AJARAN
 *  ------------------------------------------------------------------
 * Kelas menyimpan tahun_ajaran sebagai teks bebas ("2026/2027"). Nilai
 * default dihitung dari tanggal server dengan patokan Juli, yaitu bulan
 * tahun ajaran baru biasanya dimulai di sekolah Indonesia.
 */
function admin_default_tahun_ajaran(?DateTimeImmutable $ref = null): string
{
    $ref = $ref ?: new DateTimeImmutable('now', new DateTimeZone(TIMEZONE));
    $tahun = (int) $ref->format('Y');
    if ((int) $ref->format('n') >= 7) {
        $tahun++;
    }
    return $tahun . '/' . ($tahun + 1);
}

/** Lima tahun ajaran di sekitar tahun default, untuk select box. */
function admin_daftar_tahun_ajaran(): array
{
    $awal = (int) explode('/', admin_default_tahun_ajaran())[0];
    $daftar = [];
    for ($t = $awal - 2; $t <= $awal + 2; $t++) {
        $daftar[] = $t . '/' . ($t + 1);
    }
    return $daftar;
}

/* ------------------------------------------------------------------
 *  KELAS: TINGKAT & JURUSAN
 *  ------------------------------------------------------------------
 * Kolom tingkat dan jurusan di tabel kelas sudah ada sejak awal, tapi
 * belum pernah diisi kode apa pun. Keduanya disimpan sebagai teks bebas
 * supaya admin bebas menulis "X IPA 1", "10B", atau "XI RPL 2" tanpa
 * application's memaksa format.
 */

/** Angka tingkat dari nama bebas, untuk mengurutkan. "X IPA" -> 10. */
function admin_tingkat_urut(string $tingkat): int
{
    if (preg_match('/\d+/', $tingkat, $m)) {
        return (int) $m[0];
    }
    $romawi = admin_romawi_ke_angka($tingkat);
    return $romawi > 0 ? $romawi : 99;
}

function admin_romawi_ke_angka(string $s): int
{
    $peta = [
        'I' => 1, 'II' => 2, 'III' => 3, 'IV' => 4, 'V' => 5, 'VI' => 6,
        'VII' => 7, 'VIII' => 8, 'IX' => 9, 'X' => 10, 'XI' => 11, 'XII' => 12,
    ];
    $s = strtoupper(trim($s));
    if (isset($peta[$s])) {
        return $peta[$s];
    }
    /* "XII IPA" -> cari awalan romawi yang terpanjang. */
    foreach (['XII', 'VIII', 'VII', 'III', 'II', 'IV', 'IX', 'VI', 'XI', 'X'] as $r) {
        if (strpos($s, $r) === 0) {
            return $peta[$r];
        }
    }
    return 0;
}

/** Pilihan tingkat 1 sampai 12 untuk form kelas. */
function admin_daftar_tingkat(): array
{
    $daftar = [];
    for ($i = 1; $i <= 12; $i++) {
        $daftar[$i] = (string) $i;
    }
    return $daftar;
}

/**
 * Saran jurusan, dipakai sebagai <datalist> supaya admin bisa memilih
 * cepat tapi tetap bebas mengetik jurusan yang belum terdaftar.
 */
function admin_saran_jurusan(): array
{
    return [
        'IPA', 'IPS', 'Bahasa', 'Sains Sosial', 'Ilmu Agama', 'Keuangan',
        'Teknik', 'Elektro', 'Mesin', 'Otomotif', 'Komputer dan Jaringan',
        'Rekayasa Perangkat Lunak', 'Multimedia', 'Desain', 'Busana',
        'Pemasaran', 'Akuntansi', 'Administrasi Perkantoran', 'Perbankan',
        'Manajemen Bisnis', 'Tata Boga', 'Perhotelan', 'Tasikrafi', 'Kriya',
        'Pertanian', 'Peternakan', 'Perkebunan', 'Ternak',
    ];
}

/**
 * Susun nama kelas dari tingkat, jurusan, dan nomor.
 * Nomor dikosongkan kalau admin tidak mengisi, jadi "XI RPL" valid
 * selain "XI RPL 1".
 */
function admin_rakit_nama_kelas(string $tingkat, string $jurusan, string $nomor): string
{
    $bagian = array_filter([
        trim($tingkat),
        trim($jurusan),
        trim($nomor),
    ], static function ($s) {
        return $s !== '';
    });
    return implode(' ', $bagian);
}

/* ------------------------------------------------------------------
 *  PENYARING TABEL
 *  ------------------------------------------------------------------ */

/** Hitung halaman untuk OFFSET, dengan batas atas yang wajar. */
function admin_halaman_aktif(int $total, int $perHalaman): int
{
    $maks = max(1, (int) ceil($total / max(1, $perHalaman)));
    return min(max(1, $maks), $maks);
}

/** Bangun ulang query string dengan satu parameter diganti. */
function admin_ubah_query(array $ubah): string
{
    $q = array_merge($_GET, $ubah);
    $q = array_filter($q, static function ($v) {
        return $v !== '' && $v !== null;
    });
    return '?' . http_build_query($q);
}
