<?php
/**
 * admin/backup.php
 * ---------------------------------------------------------------------------
 * Backup & Data: cadangkan, pulihkan, dan ekspor isi database.
 *
 * MENGAPA FITUR INI
 * -----------------
 * Data absensi tidak bisa dibuat ulang. Satu kali tabel terhapus atau
 * server bermasalah, riwayat kehadiran hilang permanen. Halaman ini
 * menyediakan:
 *   1. Unduh backup .sql  (teks, bisa dipulihkan di driver mana pun)
 *   2. Unduh berkas database mentah khusus SQLite
 *   3. Pulihkan dari berkas .sql (dengan backup pengaman otomatis)
 *   4. Ekspor cepat CSV: siswa, kelas, absensi, izin
 *
 * CATATAN TEKNIS
 * --------------
 * Tidak memakai mysqldump / CLI SQLite karena hosting gratis sering tidak
 * menyediakannya. Seluruh dump dibangun lewat PDO sehingga jalan di driver
 * sqlite maupun mysql. Kolom biner (mis. vektor Face ID) ditulis sebagai
 * literal heksadesimal supaya byte-nya bulat saat dipulihkan.
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_data.php';

const BACKUP_MAX_BYTE   = 20971520; /* 20 MB berkas .sql yang diterima */
const BACKUP_UNDUH_MAKS = 52428800; /* 50 MB batas unduh mentah */

$pageKey   = 'backup';
$pageTitle = 'Backup & Data';
$pageSub   = 'Cadangkan, pulihkan, dan ekspor isi database';

if (!$isAdmin) {
    admin_flash('warn', 'Backup & data hanya untuk administrator.');
    header('Location: /admin/index.php');
    exit;
}

/* ==================================================================
 |  FUNGSI BANTU
 * ================================================================== */

/** Folder tempat berkas backup disimpan (folder database). */
function backup_dir(): string
{
    return dirname(DB_SQLITE_PATH);
}

/** Ukuran dalam bentuk enak dibaca. */
function backup_ukuran(int $byte): string
{
    if ($byte >= 1048576) {
        return number_format($byte / 1048576, 1, ',', '.') . ' MB';
    }
    if ($byte >= 1024) {
        return number_format($byte / 1024, 1, ',', '.') . ' KB';
    }
    return $byte . ' B';
}

/** Nama driver aktif. */
function backup_drv(): string
{
    return (string) db()->getAttribute(PDO::ATTR_DRIVER_NAME);
}

/** Kutip nama identifier sesuai driver. */
function backup_ide(string $nama): string
{
    return backup_drv() === 'mysql' ? '`' . $nama . '`' : '"' . $nama . '"';
}

/** Daftar tabel aplikasi (tanpa tabel internal sqlite_%). */
function backup_daftar_tabel(): array
{
    if (backup_drv() === 'sqlite') {
        $rows = db_all(
            "SELECT name FROM sqlite_master
              WHERE type = 'table' AND name NOT LIKE 'sqlite_%'
              ORDER BY name"
        );
    } else {
        $rows = db_all('SHOW TABLES');
    }
    $tabel = [];
    foreach ($rows as $r) {
        $tabel[] = (string) array_values($r)[0];
    }
    return $tabel;
}

/** Daftar kolom satu tabel: [['nama'=>..., 'tipe'=>...], ...]. */
function backup_kolom(string $tabel): array
{
    $kolom = [];
    if (backup_drv() === 'sqlite') {
        foreach (db_all('PRAGMA table_info(' . backup_ide($tabel) . ')') as $c) {
            $kolom[] = ['nama' => (string) $c['name'], 'tipe' => (string) $c['type']];
        }
    } else {
        foreach (db_all('SHOW COLUMNS FROM ' . backup_ide($tabel)) as $c) {
            $kolom[] = ['nama' => (string) $c['Field'], 'tipe' => (string) $c['Type']];
        }
    }
    return $kolom;
}

/** Apakah tipe kolom menyimpan data biner (butuh literal heksadesimal). */
function backup_biner(string $tipe): bool
{
    return stripos($tipe, 'blob') !== false || stripos($tipe, 'binary') !== false;
}

/** Perintah CREATE TABLE sebuah tabel. */
function backup_create(string $tabel): string
{
    if (backup_drv() === 'sqlite') {
        $row = db_one("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?", [$tabel]);
        return trim((string) ($row['sql'] ?? ''));
    }
    $row = db_one('SHOW CREATE TABLE ' . backup_ide($tabel));
    $nilai = $row ? array_values($row) : [];
    return trim((string) ($nilai[1] ?? ''));
}

/** Literal SQL satu nilai. */
function backup_literal(PDO $pdo, ?string $nilai, bool $biner): string
{
    if ($nilai === null) {
        return 'NULL';
    }
    if ($biner) {
        $hex = bin2hex($nilai);
        return $hex === '' ? "''" : (backup_drv() === 'sqlite' ? "X'{$hex}'" : '0x' . $hex);
    }
    return $pdo->quote($nilai);
}

/** Tulis seluruh dump .sql ke sebuah stream (unduhan atau berkas). */
function backup_tulis($fh): void
{
    $pdo = db();
    $drv = backup_drv();

    fwrite($fh, "-- =======================================================\n");
    fwrite($fh, "-- Backup AbsensiPro\n");
    fwrite($fh, '-- Tanggal : ' . date('Y-m-d H:i:s') . "\n");
    fwrite($fh, '-- Driver  : ' . $drv . "\n");
    fwrite($fh, "-- Pulihkan lewat menu Backup & Data di panel admin.\n");
    fwrite($fh, "-- -------------------------------------------------------\n\n");

    fwrite($fh, $drv === 'mysql'
        ? "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n"
        : "PRAGMA foreign_keys = OFF;\n\n");

    $tabel = backup_daftar_tabel();

    /* 1. Struktur tabel. */
    foreach ($tabel as $t) {
        $create = backup_create($t);
        if ($create === '') {
            continue;
        }
        fwrite($fh, 'DROP TABLE IF EXISTS ' . backup_ide($t) . ";\n");
        fwrite($fh, rtrim($create) . ";\n\n");
    }

    /* 2. Isi tabel. */
    foreach ($tabel as $t) {
        $kolom = backup_kolom($t);
        if ($kolom === []) {
            continue;
        }
        $nama = array_map(static fn($c) => $c['nama'], $kolom);
        $daftar = implode(', ', array_map(static fn($n) => backup_ide($n), $nama));
        $binerMap = [];
        foreach ($kolom as $c) {
            $binerMap[$c['nama']] = backup_biner($c['tipe']);
        }

        $stmt = db()->query('SELECT ' . $daftar . ' FROM ' . backup_ide($t));
        if ($stmt === false) {
            continue;
        }
        $ada = false;
        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            $nilai = [];
            foreach ($nama as $n) {
                $v = $row[$n] ?? null;
                $nilai[] = backup_literal(db(), $v === null ? null : (string) $v, $binerMap[$n]);
            }
            fwrite($fh, 'INSERT INTO ' . backup_ide($t) . ' (' . $daftar . ') VALUES ('
                . implode(', ', $nilai) . ");\n");
            $ada = true;
        }
        if ($ada) {
            fwrite($fh, "\n");
        }
    }

    /* 3. Indeks. Pada SQLite index adalah objek terpisah; pada MySQL sudah
     *    ikut di dalam CREATE TABLE. */
    if (backup_drv() === 'sqlite') {
        foreach ($tabel as $t) {
            foreach (db_all(
                "SELECT sql FROM sqlite_master
                  WHERE type = 'index' AND tbl_name = ? AND sql IS NOT NULL",
                [$t]
            ) as $ix) {
                fwrite($fh, rtrim((string) $ix['sql']) . ";\n");
            }
        }
        fwrite($fh, "\nPRAGMA foreign_keys=ON;\n");
    } else {
        fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
    }

    fwrite($fh, "-- Selesai.\n");
}

/**
 * Pecah isi berkas .sql menjadi perintah-perintah terpisah.
 * Pemisah ';' di dalam tanda kutip diabaikan.
 */
function backup_pecah_sql(string $sql): array
{
    $perintah = [];
    $buf = '';
    $len = strlen($sql);
    $dalam = false;

    for ($i = 0; $i < $len; $i++) {
        $ch = $sql[$i];

        if ($dalam) {
            $buf .= $ch;
            if ($ch === '\\' && $i + 1 < $len) {
                $buf .= $sql[++$i];
            } elseif ($ch === "'") {
                if ($i + 1 < $len && $sql[$i + 1] === "'") {
                    $buf .= $sql[++$i];
                } else {
                    $dalam = false;
                }
            }
            continue;
        }

        if ($ch === "'") {
            $dalam = true;
            $buf .= $ch;
            continue;
        }
        if ($ch === '-' && $i + 1 < $len && $sql[$i + 1] === '-') {
            while ($i < $len && $sql[$i] !== "\n") {
                $i++;
            }
            continue;
        }
        if ($ch === ';') {
            $s = trim($buf);
            if ($s !== '') {
                $perintah[] = $s;
            }
            $buf = '';
            continue;
        }
        $buf .= $ch;
    }

    $s = trim($buf);
    if ($s !== '') {
        $perintah[] = $s;
    }
    return $perintah;
}

/**
 * Pulihkan database dari isi berkas .sql.
 * @return array{0:bool, 1:string}
 */
function backup_pulihkan(string $isi): array
{
    $pdo = db();
    $drv = backup_drv();
    $perintah = backup_pecah_sql($isi);
    if ($perintah === []) {
        return [false, 'Berkas SQL tidak berisi perintah yang bisa dijalankan.'];
    }

    try {
        if ($drv === 'sqlite') {
            /* SQLite bisa membatalkan DDL di dalam transaksi, jadi kegagalan
             * di tengah jalan mengembalikan database ke kondisi semula. */
            $pdo->exec('PRAGMA foreign_keys = OFF');
            $pdo->beginTransaction();
        } else {
            /* MySQL menyimpan perubahan DDL seketika (implicit commit), jadi
             * transaksi tidak menolong. Bergantung pada backup pengaman. */
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        }

        $jml = 0;
        foreach ($perintah as $p) {
            $pdo->exec($p);
            $jml++;
        }

        if ($pdo->inTransaction()) {
            $pdo->commit();
        }
        $pdo->exec($drv === 'sqlite' ? 'PRAGMA foreign_keys = ON' : 'SET FOREIGN_KEY_CHECKS = 1');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[BACKUP] Gagal memulihkan: ' . $e->getMessage());
        return [false, 'Gagal memulihkan: ' . $e->getMessage()];
    }

    return [true, $jml . ' perintah berhasil dijalankan.'];
}

/** Simpan isi dump ke berkas kerja. Kembalikan nama berkas. */
function backup_simpan(string $isi, string $awalan): string
{
    $nama = $awalan . date('Ymd-His') . '.sql';
    @file_put_contents(backup_dir() . DIRECTORY_SEPARATOR . $nama, $isi);
    return $nama;
}

/** Ringkasan jumlah baris tiap tabel. */
function backup_ringkas(): array
{
    $hasil = [];
    foreach (backup_daftar_tabel() as $t) {
        $n = (int) db_value('SELECT COUNT(*) FROM ' . backup_ide($t), [], 0);
        $hasil[] = ['nama' => $t, 'baris' => $n];
    }
    return $hasil;
}

/* ==================================================================
 |  EKSPOR CSV
 * ================================================================== */

function backup_csv_siswa(): void
{
    $baris = [];
    foreach (db_all(
        "SELECT u.nama_lengkap, u.username, u.nis, u.nisn, k.nama_kelas,
                u.jenis_kelamin, u.is_active, u.last_login, u.created_at
           FROM users u
           LEFT JOIN kelas k ON k.id = u.kelas_id
          WHERE u.role = 'siswa'
          ORDER BY k.nama_kelas, u.nama_lengkap"
    ) as $s) {
        $baris[] = [
            $s['nama_lengkap'], $s['username'], $s['nis'], $s['nisn'],
            $s['nama_kelas'] ?? 'Tanpa kelas', $s['jenis_kelamin'],
            (int) $s['is_active'] === 1 ? 'Aktif' : 'Nonaktif',
            $s['last_login'], $s['created_at'],
        ];
    }
    admin_csv($baris, ['Nama', 'Username', 'NIS', 'NISN', 'Kelas', 'JK', 'Status', 'Login Terakhir', 'Dibuat'], 'data-siswa');
}

function backup_csv_kelas(): void
{
    $baris = [];
    foreach (db_all(
        "SELECT k.nama_kelas, k.tingkat, k.jurusan, k.tahun_ajaran,
                w.nama_lengkap AS wali, k.is_active
           FROM kelas k
           LEFT JOIN users w ON w.id = k.wali_kelas
          ORDER BY k.tahun_ajaran, k.nama_kelas"
    ) as $k) {
        $baris[] = [
            $k['nama_kelas'], $k['tingkat'], $k['jurusan'], $k['tahun_ajaran'],
            $k['wali'] ?? '', (int) $k['is_active'] === 1 ? 'Aktif' : 'Nonaktif',
        ];
    }
    admin_csv($baris, ['Kelas', 'Tingkat', 'Jurusan', 'Tahun Ajaran', 'Wali Kelas', 'Status'], 'data-kelas');
}

function backup_csv_izin(): void
{
    try {
        $rows = db_all(
            "SELECT i.id, u.nama_lengkap, u.username, k.nama_kelas, i.jenis,
                    i.tanggal_mulai, i.tanggal_selesai, i.alasan, i.status,
                    i.catatan_petugas, i.created_at
               FROM izin_requests i
               LEFT JOIN users u ON u.id = i.user_id
               LEFT JOIN kelas k ON k.id = u.kelas_id
              ORDER BY i.created_at DESC"
        );
    } catch (Throwable $e) {
        $rows = [];
    }
    $baris = [];
    foreach ($rows as $i) {
        $baris[] = [
            (int) $i['id'], $i['nama_lengkap'] ?? '', $i['username'] ?? '',
            $i['nama_kelas'] ?? 'Tanpa kelas', $i['jenis'],
            $i['tanggal_mulai'], $i['tanggal_selesai'], $i['alasan'],
            $i['status'], $i['catatan_petugas'], $i['created_at'],
        ];
    }
    admin_csv(
        $baris,
        ['ID', 'Nama', 'Username', 'Kelas', 'Jenis', 'Mulai', 'Selesai', 'Alasan', 'Status', 'Catatan', 'Diajukan'],
        'data-izin'
    );
}

/* ==================================================================
 |  UNDUHAN (GET)
 * ================================================================== */
$unduh = (string) get('unduh', '');

if ($unduh === 'sql') {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="backup-absensipro-' . date('Ymd-His') . '.sql"');
    header('Cache-Control: no-store');
    $keluar = fopen('php://output', 'wb');
    if ($keluar !== false) {
        backup_tulis($keluar);
        fclose($keluar);
    }
    audit_log($adminId, 'admin.backup.unduh', 'info', 'Unduh backup .sql.');
    exit;
}

if ($unduh === 'db') {
    if (backup_drv() !== 'sqlite' || !is_file(DB_SQLITE_PATH)) {
        admin_flash('warn', 'Unduh berkas mentah hanya tersedia untuk SQLite.');
        admin_kembali();
    }
    if ((int) filesize(DB_SQLITE_PATH) > BACKUP_UNDUH_MAKS) {
        admin_flash('warn', 'Berkas database terlalu besar untuk diunduh di sini.');
        admin_kembali();
    }
    try {
        db()->exec('PRAGMA wal_checkpoint(FULL)');
    } catch (Throwable $e) {
        /* checkpoint gagal bukan alasan membatalkan unduhan */
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="presensi-' . date('Ymd-His') . '.sqlite"');
    header('Content-Length: ' . filesize(DB_SQLITE_PATH));
    header('Cache-Control: no-store');
    readfile(DB_SQLITE_PATH);
    audit_log($adminId, 'admin.backup.unduh_mentah', 'info', 'Unduh berkas .sqlite.');
    exit;
}

if ($unduh === 'file') {
    $bn = basename((string) get('f', ''));
    if (!preg_match('/^backup-[A-Za-z0-9._-]+\.sql$/', $bn)) {
        http_response_code(400);
        exit('Nama berkas tidak sah.');
    }
    $path = backup_dir() . DIRECTORY_SEPARATOR . $bn;
    if (!is_file($path)) {
        admin_flash('warn', 'Berkas backup tidak ditemukan.');
        admin_kembali();
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $bn . '"');
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: no-store');
    readfile($path);
    exit;
}

switch ($unduh) {
    case 'siswa':
        backup_csv_siswa();
        break;
    case 'kelas':
        backup_csv_kelas();
        break;
    case 'izin':
        backup_csv_izin();
        break;
    case 'absensi':
        admin_csv_absensi();
        break;
}

/* ==================================================================
 |  PULIHKAN (POST)
 * ================================================================== */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_hanya_post();

    if (post('aksi', '') !== 'pulihkan') {
        admin_kembali();
    }
    if (post('setuju', '') !== '1') {
        admin_flash('warn', 'Centang pernyataan konfirmasi dulu sebelum memulihkan.');
        admin_kembali();
    }

    $up = $_FILES['berkas'] ?? null;
    if (!is_array($up) || ($up['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        admin_flash('error', 'Berkas backup tidak terkirim. Coba pilih ulang.');
        admin_kembali();
    }
    if ((int) $up['size'] > BACKUP_MAX_BYTE) {
        admin_flash('error', 'Berkas lebih dari 20 MB.');
        admin_kembali();
    }
    if (!str_ends_with(strtolower((string) ($up['name'] ?? '')), '.sql')) {
        admin_flash('error', 'Berkas harus berakhiran .sql.');
        admin_kembali();
    }

    $isi = (string) file_get_contents((string) $up['tmp_name']);
    if (strncmp($isi, "\xEF\xBB\xBF", 3) === 0) {
        $isi = substr($isi, 3);
    }

    /* Backup pengaman dulu supaya data lama bisa dikembalikan bila pulih gagal. */
    ob_start();
    backup_tulis(fopen('php://output', 'wb'));
    $pengaman = backup_simpan((string) ob_get_clean(), 'backup-sebelum-pulih-');

    [$ok, $pesan] = backup_pulihkan($isi);

    audit_log($adminId, $ok ? 'admin.backup.pulih' : 'admin.backup.pulih_gagal', $ok ? 'warning' : 'critical',
        'Pulih dari berkas. ' . $pesan);
    admin_flash($ok ? 'ok' : 'error', $ok
        ? 'Data berhasil dipulihkan (' . $pesan . '). Cadangan pengaman: ' . $pengaman . '.'
        : 'Gagal: ' . $pesan . ' Cadangan pengaman disimpan sebagai ' . $pengaman . '.');
    admin_kembali();
}

/* ==================================================================
 |  DATA UNTUK TAMPILAN
 * ================================================================== */
$drv         = backup_drv();
$ringkas     = backup_ringkas();
$totalBaris  = array_sum(array_map(static fn($x) => $x['baris'], $ringkas));
$ukuranDb    = ($drv === 'sqlite' && is_file(DB_SQLITE_PATH)) ? (int) filesize(DB_SQLITE_PATH) : 0;

/* Cadangan pengaman yang tersimpan di folder database. */
$daftarBackup = [];
foreach (glob(backup_dir() . DIRECTORY_SEPARATOR . 'backup-*.sql') ?: [] as $path) {
    $bn = basename($path);
    if (!preg_match('/^backup-[A-Za-z0-9._-]+\.sql$/', $bn)) {
        continue;
    }
    $daftarBackup[] = [
        'nama'   => $bn,
        'ukuran' => (int) (@filesize($path) ?: 0),
        'waktu'  => (int) (@filemtime($path) ?: 0),
    ];
}
usort($daftarBackup, static fn($a, $b) => $b['waktu'] <=> $a['waktu']);
$daftarBackup = array_slice($daftarBackup, 0, 12);

require __DIR__ . '/../templates/admin-head.php';
?>

<!-- ================= RINGKASAN ================= -->
<div class="grid grid--kpi mb-2">
    <div class="kpi kpi--slim kpi--info">
        <p class="kpi__label" style="margin:0">Tabel</p>
        <p class="kpi__value"><?= count($ringkas) ?></p>
    </div>
    <div class="kpi kpi--slim kpi--ok">
        <p class="kpi__label" style="margin:0">Total baris</p>
        <p class="kpi__value"><?= number_format($totalBaris, 0, ',', '.') ?></p>
    </div>
    <div class="kpi kpi--slim kpi--muted">
        <p class="kpi__label" style="margin:0">Ukuran DB</p>
        <p class="kpi__value"><?= $ukuranDb > 0 ? esc(backup_ukuran($ukuranDb)) : 'MySQL' ?></p>
    </div>
    <div class="kpi kpi--slim kpi--muted">
        <p class="kpi__label" style="margin:0">Driver</p>
        <p class="kpi__value" style="font-size:18px"><?= esc($drv) ?></p>
    </div>
</div>

<div class="grid grid--2">
    <!-- ================= CADANGKAN ================= -->
    <div class="card">
        <div class="card__head card__head--plain">
            <p class="card__title"><i class="fa-solid fa-download"></i> Cadangkan</p>
            <p class="card__sub tiny muted">Simpan salinan data ke komputer.</p>
        </div>
        <div class="card__body">
            <p class="small muted mb-1">
                Berkas <strong>.sql</strong> berisi seluruh tabel beserta isinya
                (termasuk vektor Face ID) dan bisa dipulihkan di driver sqlite maupun mysql.
            </p>
            <div class="row">
                <a class="btn btn--primary" href="/admin/backup.php?unduh=sql">
                    <i class="fa-solid fa-file-code"></i> Unduh backup (.sql)
                </a>
                <?php if ($drv === 'sqlite'): ?>
                    <a class="btn btn--soft" href="/admin/backup.php?unduh=db">
                        <i class="fa-solid fa-database"></i> Unduh berkas .sqlite
                    </a>
                <?php endif; ?>
            </div>

            <p class="field__label mt-2">Isi database</p>
            <div class="tablewrap">
                <table class="table">
                    <thead><tr><th>Tabel</th><th class="shrink">Baris</th></tr></thead>
                    <tbody>
                        <?php foreach ($ringkas as $t): ?>
                            <tr>
                                <td><code><?= esc($t['nama']) ?></code></td>
                                <td class="num-cell"><?= number_format($t['baris'], 0, ',', '.') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ================= EKSPOR ================= -->
    <div class="card">
        <div class="card__head card__head--plain">
            <p class="card__title"><i class="fa-solid fa-file-csv"></i> Ekspor CSV</p>
            <p class="card__sub tiny muted">Buka di Excel/Spreadsheet. Tidak mengubah data.</p>
        </div>
        <div class="card__body">
            <div class="row">
                <a class="btn btn--soft" href="/admin/backup.php?unduh=siswa"><i class="fa-solid fa-users"></i> Siswa</a>
                <a class="btn btn--soft" href="/admin/backup.php?unduh=kelas"><i class="fa-solid fa-chalkboard-user"></i> Kelas</a>
                <a class="btn btn--soft" href="/admin/backup.php?unduh=absensi"><i class="fa-solid fa-clock-rotate-left"></i> Absensi hari ini</a>
                <a class="btn btn--soft" href="/admin/backup.php?unduh=izin"><i class="fa-solid fa-file-signature"></i> Izin &amp; Sakit</a>
            </div>
            <div class="note note--flat mt-2">
                <i class="fa-solid fa-circle-info note__ic"></i>
                <div class="note__body">
                    Rekap absensi per tanggal/kelas ada di halaman
                    <a href="/admin/absensi.php">Absensi</a>.
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ================= PULIHKAN ================= -->
<div class="card mt-2">
    <div class="card__head card__head--plain">
        <p class="card__title"><i class="fa-solid fa-rotate-left"></i> Pulihkan dari backup</p>
        <p class="card__sub tiny muted">Kembalikan data dari berkas .sql yang pernah diunduh.</p>
    </div>
    <div class="card__body">
        <div class="note note--warn mb-2">
            <i class="fa-solid fa-triangle-exclamation note__ic"></i>
            <div class="note__body">
                <strong>Data saat ini akan ditimpa.</strong> Sistem otomatis menyimpan cadangan
                pengaman ke folder database sebelum menimpa, jadi masih bisa dikembalikan.
            </div>
        </div>

        <form method="post" action="/admin/backup.php" enctype="multipart/form-data" class="form">
            <input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">
            <input type="hidden" name="aksi" value="pulihkan">

            <label class="unggah" for="sBerkas" id="kotakUnggah">
                <i class="fa-solid fa-file-import unggah__ic"></i>
                <p class="unggah__title">Pilih berkas .sql</p>
                <p class="unggah__hint">hasil backup sebelumnya &middot; maksimal 20 MB</p>
                <p class="unggah__file" id="unggahNama" hidden></p>
                <input type="file" id="sBerkas" name="berkas" accept=".sql,application/sql,text/plain" required>
            </label>

            <label class="switchrow" style="cursor:pointer">
                <span class="switchrow__text">
                    <span class="switchrow__title" style="display:block">Saya mengerti data saat ini akan ditimpa</span>
                    <span class="switchrow__hint">Centang untuk mengaktifkan tombol pulihkan.</span>
                </span>
                <span class="switch">
                    <input type="checkbox" name="setuju" value="1" id="setuju">
                    <span class="switch__track"></span>
                </span>
            </label>

            <button type="submit" class="btn btn--danger" id="tombolPulih" disabled>
                <i class="fa-solid fa-rotate-left"></i> Pulihkan sekarang
            </button>
        </form>

        <?php if ($daftarBackup !== []): ?>
            <p class="field__label mt-2">Cadangan tersimpan di server</p>
            <div class="tablewrap">
                <table class="table">
                    <thead>
                        <tr><th>Berkas</th><th class="shrink">Ukuran</th><th class="shrink">Waktu</th><th class="shrink"></th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($daftarBackup as $b): ?>
                            <tr>
                                <td><code class="small"><?= esc($b['nama']) ?></code></td>
                                <td class="num-cell"><?= esc(backup_ukuran($b['ukuran'])) ?></td>
                                <td class="small"><?= esc($b['waktu'] > 0 ? date('d/m/Y H:i', $b['waktu']) : '-') ?></td>
                                <td class="shrink">
                                    <a class="btn btn--ghost btn--sm" title="Unduh"
                                       href="/admin/backup.php?unduh=file&amp;f=<?= esc(urlencode($b['nama'])) ?>">
                                        <i class="fa-solid fa-download"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
(function () {
    var input = document.getElementById('sBerkas');
    var kotak = document.getElementById('kotakUnggah');
    var nama  = document.getElementById('unggahNama');
    var setuju = document.getElementById('setuju');
    var tombol = document.getElementById('tombolPulih');

    if (input) {
        input.addEventListener('change', function () {
            if (input.files && input.files.length) {
                if (nama) { nama.textContent = input.files[0].name; nama.hidden = false; }
                if (kotak) { kotak.classList.add('is-ada'); }
            }
        });
    }
    if (setuju && tombol) {
        var sinkron = function () { tombol.disabled = !setuju.checked; };
        setuju.addEventListener('change', sinkron);
    }
})();
</script>

<?php require __DIR__ . '/../templates/admin-foot.php';
