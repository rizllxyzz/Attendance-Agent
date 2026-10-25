<?php
/**
 * database.php
 * ---------------------------------------------------------------------------
 * Koneksi PDO dengan dukungan dua driver.
 *
 *   DB_DRIVER = 'sqlite'  -> pengembangan lokal, tanpa setup apa pun
 *   DB_DRIVER = 'mysql'   -> hosting InfinityFree / cPanel
 *   DB_DRIVER = 'pgsql'   -> PostgreSQL Zetaris
 *
 * Kode PDO di seluruh aplikasi tidak perlu berubah saat driver diganti.
 * Hanya nilai konstanta di bawah yang perlu diubah.
 * CATATAN BATAS INFINITYFREE (free hosting)
 * ----------------------------------------
 * Hak akses SQL yang diberikan hanya: SELECT, INSERT, UPDATE, DELETE,
 * CREATE, DROP, ALTER, INDEX, LOCK TABLES. Karena itu skema TIDAK memakai
 * foreign key, trigger, stored procedure, maupun temporary table.
 * Relasi dijaga di level aplikasi, bukan oleh mesin database.
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);

require_once __DIR__ . '/app-config.php';

/* ------------------------------------------------------------------
 |  PILIH DRIVER
 |
 |  Saat ini: 'sqlite' untuk pengembangan lokal, 'mysql' untuk hosting.
 * ------------------------------------------------------------------ */
define('DB_DRIVER', getenv('DB_DRIVER') !== false ? getenv('DB_DRIVER') : 'mysql');

/* ------------------------------------------------------------------
 |  KREDENSIAL
 |
 |  LOKAL (Laragon)
 |    Host 127.0.0.1, user root, password kosong, port 3306
 |    Nama database bebas, misalnya db_presensi
 |
 |  HOSTING (InfinityFree)
 |    Buka Client Panel -> MySQL Databases -> Create Database
 |    Salin 4 nilai ini:
 |      DB_HOST     sql123.epizy.com   (bukan namadomain-mu!)
 *      DB_NAME     epiz_12345678_presensi
 *      DB_USER     epiz_12345678
 *      DB_PASS     password dari client panel
 | ------------------------------------------------------------------ */
define('DB_HOST', getenv('DB_HOST') !== false ? getenv('DB_HOST') : '127.0.0.1');
define('DB_PORT', getenv('DB_PORT') !== false ? (int) getenv('DB_PORT') : 3306);
define('DB_NAME', getenv('DB_NAME') !== false ? getenv('DB_NAME') : 'presensi');
define('DB_USER', getenv('DB_USER') !== false ? getenv('DB_USER') : 'root');
define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');

/* ------------------------------------------------------------------
 |  PATH DATABASE SQLITE
 |
 |  PENTING untuk hosting: file .sqlite HARUS berada di dalam webroot
 |  (PHP tidak boleh mengakses lokasi lain), jadi file ini bisa saja
 |  terunduh lewat browser. Karena itu foldernya dikunci oleh .htaccess
 *  berisi "Require all denied". Jangan pernah menghapus berkas itu.
 |
 |  Pakai BLOB untuk descriptor wajah, bukan teks, supaya hemat kuota:
 |  1200 siswa x 128 float x 4 byte = 614 KB (BLOB)
 *  1200 siswa x 128 float x 12 byte teks = 1,8 MB (JSON)
 | ------------------------------------------------------------------ */
define('DB_SQLITE_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR . 'database'
    . DIRECTORY_SEPARATOR . 'presensi.sqlite');

/* ==================================================================
 |  KONEKSI
 | ================================================================== */

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $driver = DB_DRIVER;

    try {
        if ($driver === 'sqlite') {
            ensure_sqlite_dir();
            $pdo = new PDO('sqlite:' . DB_SQLITE_PATH, null, null, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
            // PRAGMA wajib di-set tiap koneksi
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec('PRAGMA busy_timeout = 5000');
        } elseif ($driver === 'pgsql') {
            $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s',
                DB_HOST, DB_PORT, DB_NAME);
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } else {
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                DB_HOST, DB_PORT, DB_NAME);
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ]);
        }
    } catch (PDOException $e) {
        db_fail($e);
    }

    return $pdo;
}

function ensure_sqlite_dir(): void
{
    $dir = dirname(DB_SQLITE_PATH);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $guard = $dir . DIRECTORY_SEPARATOR . '.htaccess';
    if (!is_file($guard)) {
        @file_put_contents($guard, "Require all denied\n");
    }
}

function db_driver(): string
{
    return DB_DRIVER;
}

/**
 * Gagal koneksi tidak boleh membocorkan host, user, atau password.
 * Detail disimpan di log server, pesan ke pengguna dibuat generik.
 */
function db_fail(PDOException $e): void
{
    error_log('[DB] ' . $e->getMessage());

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }

    $isCli = PHP_SAPI === 'cli';
    $msg = $isCli
        ? 'Koneksi database gagal: ' . $e->getMessage()
        : 'Koneksi database gagal. Periksa config/database.php.';

    echo json_encode([
        'success' => false,
        'code'    => 'DB_UNAVAILABLE',
        'message' => $msg,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ==================================================================
 |  HELPER QUERY
 | ================================================================== */

/**
 * Jalankan query dan kembalikan statement-nya.
 *
 * Semua helper di bawah memakai fungsi ini supaya penangkapan error ada
 * di satu tempat. Tanpa itu, PDOException yang tidak tertangkap akan
 * sampai ke PHP sebagai halaman error, dan PHP defaultnya menampilkan
 * stack trace lengkap beserta path absolut server. Itu membocorkan
 * struktur direktori dan nama file ke siapa pun yang provoke error,
 * cukup dengan mengirim parameter yang salah.
 *
 * Detail asli tetap ditulis ke error log server, jadi operator masih
 * bisa menelusuri penyebabnya.
 */
function db_run(string $sql, array $params = [])
{
    try {
        $st = db()->prepare($sql);
        $st->execute($params);
        return $st;
    } catch (Throwable $e) {
        error_log('[DB] ' . $e->getMessage() . ' | SQL: ' . preg_replace('/\s+/', ' ', $sql));
        throw new RuntimeException('Kueri basis data gagal.', 0, $e);
    }
}

/** Ambil satu baris. */
function db_one(string $sql, array $params = [], $default = null)
{
    $row = db_run($sql, $params)->fetch();
    return $row === false ? $default : $row;
}

/** Ambil semua baris. */
function db_all(string $sql, array $params = []): array
{
    return db_run($sql, $params)->fetchAll();
}

/** Ambil satu nilai skalar. */
function db_value(string $sql, array $params = [], $default = null)
{
    $v = db_run($sql, $params)->fetchColumn();
    return $v === false ? $default : $v;
}

/** Jalankan INSERT/UPDATE/DELETE, kembalikan jumlah baris. */
function db_exec(string $sql, array $params = []): int
{
    return db_run($sql, $params)->rowCount();
}

/** Sisipkan satu baris, kembalikan id yang baru dibuat. */
function db_insert(string $sql, array $params = []): int
{
    db_exec($sql, $params);
    return (int) db()->lastInsertId();
}

/**
 * Waktu sekarang sebagai NILAI, siap disimpan.
 *
 * Dipakai sebagai parameter bind (VALUES (?, ?)) maupun di dalam
 * array params. Mengembalikan waktu sungguhan, bukan potongan SQL.
 *
 * Kalau butuh waktu yang dihitung database, pakai db_now_sql().
 */
function db_now(): string
{
    return date('Y-m-d H:i:s');
}

/**
 * Waktu sekarang sebagai EKSPRESSI SQL, untuk disisipkan langsung ke
 * teks query, bukan sebagai parameter.
 *
 * Hanya untuk SQLite dan MySQL. Jangan dipakai sebagai parameter bind:
 * potongan teksnya akan tersimpan apa adanya di kolom, bukan
 * dieksekusi database.
 */
function db_now_sql(): string
{
    if (db_driver() === 'sqlite') {
        /* Penting: datetime('now') di SQLite selalu menghasilkan waktu
         * UTC, sedangkan kolom lain di aplikasi ini memakai zona waktu
         * yang dikonfigurasi (Asia/Jakarta). Tanpa offset di bawah ini,
         * last_login dan masa berlaku nonce tercatat tujuh jam lebih
         * awal daripada jam absensi, dan pengurutan berdasarkan waktu
         * jadi keliru.
         *
         * Offset diambil dari PHP, jadi ikut zona waktu yang sedang
         * aktif, bukan asumsi tetap +07:00. */
        return "datetime('now', '" . date('P') . "')";
    }

    /* MySQL memakai zona waktu sesinya sendiri, bukan zona waktu PHP.
     * Supaya sinkron dengan db_now(), session time_zone MySQL harus
     * disetel sama dengan APP_TIMEZONE di konfigurasi koneksi. */
    return 'NOW()';
}

/**
 * Sisipkan nilai unik; true bila baris baru berhasil masuk,
 * false bila bentrok dengan baris yang sudah ada.
 *
 * Dipakai untuk anti-replay:Attempt kedua pada nilai unik akan
 * ditolak oleh database, bukan oleh pengecekan manual yang bisa
 * diserang balapan (race condition).
 */
function db_insert_unique(string $sql, array $params = []): bool
{
    try {
        db_exec($sql, $params);
        return true;
    } catch (PDOException $e) {
        /* Kode driver berbeda-beda, jadi deteksi dari pesan teks. */
        $msg = strtolower($e->getMessage());
        $isDuplicate = strpos($msg, 'unique') !== false
            || strpos($msg, 'duplicate') !== false;
        if ($isDuplicate) {
            return false;
        }
        throw $e;
    }
}

/* ==================================================================
 |  PENANGANAN ERROR YANG AMAN
 * ================================================================== */

/**
 * Cegah PHP membocorkan stack trace ke browser.
 *
 * Tanpa handler ini, satu PDOException yang tidak tertangkap membuat
 * PHP menampilkan halaman fatal yang memuat path absolut server, nama
 * file, dan nomor baris. Siapa pun bisa memancingnya hanya dengan
 * mengirim parameter yang salah, dan itu cukup untuk memetakan
 * struktur direktori aplikasi.
 *
 * Yang dikembalikan ke klien sengaja dibuat umum. Detail aslinya
 * masuk ke error log server untuk penelusuran.
 */
set_exception_handler(static function (Throwable $e): void {
    error_log('[FATAL] ' . $e::class . ': ' . $e->getMessage()
        . ' @ ' . $e->getFile() . ':' . $e->getLine());

    if (headers_sent()) {
        echo "\n/* kesalahan internal */\n";
        exit;
    }

    http_response_code(500);

    $wantsJson = strpos((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false
        || strpos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== false
        || strpos((string) ($_SERVER['REQUEST_URI'] ?? ''), '/api/') !== false;

    if ($wantsJson) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'code'    => 'SERVER_ERROR',
            'message' => 'Terjadi kesalahan di server. Silakan coba lagi.',
        ], JSON_UNESCAPED_UNICODE);
    } else {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><meta charset="utf-8">'
           . '<title>Kesalahan</title>'
           . '<p style="font:16px system-ui;padding:2rem">Terjadi kesalahan di server. '
           . 'Silakan coba lagi.</p>';
    }

    exit;
});

/* ==================================================================
 |  BLATABUR PER DRIVER
 *  Dipakai install.php untuk membuat skema di kedua driver.
 * ================================================================== */

/**
 * Placeholder untuk driver aktif.
 * MySQL memakai AUTO_INCREMENT, SQLite memakai AUTOINCREMENT.
 */
function pk(): string
{
    return db_driver() === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY';
}

function suffix(): string
{
    return db_driver() === 'sqlite' ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
}

/** INTEGER vs INT UNSIGNED. */
function iu(): string
{
    return db_driver() === 'sqlite' ? 'INTEGER' : 'INT UNSIGNED';
}

/** TINYINT(1) disederhanakan. */
function tf(): string
{
    return db_driver() === 'sqlite' ? 'INTEGER NOT NULL DEFAULT 0' : 'TINYINT(1) NOT NULL DEFAULT 0';
}

/** ENUM tidak ada di SQLite, disederhanakan menjadi VARCHAR. */
function enum_col(array $values, string $default = ''): string
{
    $list = implode(', ', array_map(static fn($v) => "'" . $v . "'", $values));
    if (db_driver() === 'sqlite') {
        return "VARCHAR(20) NOT NULL DEFAULT '$default'";
    }
    return "ENUM($list) NOT NULL DEFAULT '$default'";
}
