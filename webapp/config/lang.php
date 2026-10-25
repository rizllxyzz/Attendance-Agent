<?php
/**
 * config/lang.php
 * ---------------------------------------------------------------------------
 * Mesin bahasa (i18n) sederhana: dua bahasa, Indonesia dan Inggris.
 *
 * PRINSIP
 * -------
 * - Kode tetap satu bahasa (kunci pendek), teks disimpan di lang/{kode}.php.
 * - Bahasa aktif diambil dari sesi pengguna; kalau belum ada, dari tabel
 *   pengaturan (kunci 'bahasa'), lalu jatuh ke 'id'.
 * - t() TIDAK pernah melempar error: kunci yang belum diterjemahkan
 *   dikembalikan sebagai teks Indonesia-nya, terakhir sebagai kunci itu
 *   sendiri. Jadi halaman tidak pernah blank hanya karena kamus kurang.
 *
 * Tidak ada efek samping saat file ini dimuat (session & DB hanya disentuh
 * ketika fungsinya benar-benar dipanggil), supaya aman di-require di mana
 * saja tanpa mengubah urutan bootstrap yang sudah ada.
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);

/** Kode bahasa yang didukung => label tampilannya. */
function lang_supported(): array
{
    return [
        'id' => 'Bahasa Indonesia',
        'en' => 'English',
    ];
}

/** Bersihkan kode bahasa; apa pun yang tak dikenal menjadi 'id'. */
function lang_normalize(?string $kode): string
{
    $kode = strtolower(trim((string) $kode));
    return array_key_exists($kode, lang_supported()) ? $kode : 'id';
}

/** Bahasa aktif untuk request ini. */
function lang_active(): string
{
    if (isset($_SESSION['lang']) && $_SESSION['lang'] !== '') {
        return lang_normalize((string) $_SESSION['lang']);
    }
    $db = lang_db_default();
    return $db ?? 'id';
}

/** True bila bahasa aktif sama dengan yang diminta. */
function lang_is(string $kode): bool
{
    return lang_active() === lang_normalize($kode);
}

/** Bahasa bawaan global dari tabel pengaturan (kunci 'bahasa'), atau null. */
function lang_db_default(): ?string
{
    static $dibaca = false;
    static $nilai  = null;
    if ($dibaca) {
        return $nilai;
    }
    $dibaca = true;

    try {
        require_once __DIR__ . '/database.php';
        if (!function_exists('db_value')) {
            return $nilai = null;
        }
        $v = db_value("SELECT value FROM pengaturan WHERE key_name = 'bahasa' LIMIT 1", [], null);
        if (is_string($v) && trim($v) !== '') {
            $nilai = lang_normalize($v);
        }
    } catch (Throwable $e) {
        $nilai = null;
    }
    return $nilai;
}

/**
 * Simpan pilihan bahasa.
 *
 * @param bool $global simpan juga sebagai bawaan seluruh aplikasi
 *                     (dipakai hanya untuk admin di halaman setelan).
 */
function lang_set(string $kode, bool $global = false): string
{
    $kode = lang_normalize($kode);

    if (function_exists('start_session')) {
        start_session();
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['lang'] = $kode;
    }
    if ($global) {
        lang_save_default($kode);
    }
    return $kode;
}

/** Tulis bawaan global ke tabel pengaturan (upsert). */
function lang_save_default(string $kode): void
{
    $kode = lang_normalize($kode);
    try {
        require_once __DIR__ . '/database.php';
        if (!function_exists('db_value')) {
            return;
        }
        $ada = (int) db_value("SELECT COUNT(*) FROM pengaturan WHERE key_name = 'bahasa'", [], 0);
        if ($ada > 0) {
            db_exec("UPDATE pengaturan SET value = ? WHERE key_name = 'bahasa'", [$kode]);
        } else {
            db_insert("INSERT INTO pengaturan (key_name, value) VALUES ('bahasa', ?)", [$kode]);
        }
    } catch (Throwable $e) {
        error_log('[LANG] Gagal menyimpan bawaan bahasa: ' . $e->getMessage());
    }
}

/** Kamus untuk satu kode bahasa (di-cache per request). */
function lang_dict(?string $kode = null): array
{
    static $cache = [];
    $kode = lang_normalize($kode ?? lang_active());
    if (isset($cache[$kode])) {
        return $cache[$kode];
    }
    $berkas = dirname(__DIR__) . '/lang/' . $kode . '.php';
    $isi = is_file($berkas) ? require $berkas : [];
    return $cache[$kode] = is_array($isi) ? $isi : [];
}

/**
 * Terjemahkan kunci.
 *
 * @param array $ganti pasangan :nama => nilai untuk diisikan ke teks.
 */
function t(string $kunci, array $ganti = []): string
{
    $dict = lang_dict();
    $teks = $dict[$kunci] ?? null;

    if ($teks === null) {
        /* Fallback ke Indonesia supaya kunci yang belum diterjemahkan tetap
         * tampil sebagai kalimat, bukan "nav.beranda". */
        $id = lang_dict('id');
        $teks = $id[$kunci] ?? $kunci;
    }

    if ($ganti !== []) {
        foreach ($ganti as $k => $v) {
            $teks = str_replace(':' . $k, (string) $v, $teks);
        }
    }
    return (string) $teks;
}

/** t() lalu di-escape untuk dipakai langsung di HTML. */
function te(string $kunci, array $ganti = []): string
{
    return htmlspecialchars(t($kunci, $ganti), ENT_QUOTES, 'UTF-8');
}

/** URL endpoint ganti bahasa. $pulang = halaman yang dituju setelah ganti. */
function lang_switch_url(string $kode, ?string $pulang = null): string
{
    $kode = lang_normalize($kode);
    $pulang = $pulang ?? (string) ($_SERVER['REQUEST_URI'] ?? '/');
    return '/lang/switch.php?to=' . urlencode($kode) . '&next=' . urlencode($pulang);
}

/** Nama hari 0=Minggu..6=Sabtu sesuai bahasa aktif. */
function lang_day_name(int $index): string
{
    $daftar = lang_is('en')
        ? ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday']
        : ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
    return $daftar[$index] ?? '-';
}

/** Nama bulan 1=Januari..12=Desember sesuai bahasa aktif. */
function lang_month_name(int $index): string
{
    $daftar = lang_is('en')
        ? ['', 'January', 'February', 'March', 'April', 'May', 'June',
            'July', 'August', 'September', 'October', 'November', 'December']
        : ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
            'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    return $daftar[$index] ?? '-';
}
