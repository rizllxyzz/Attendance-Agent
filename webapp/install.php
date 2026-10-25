<?php
/**
 * install.php
 * ---------------------------------------------------------------------------
 * Pemasang skema + akun admin pertama.
 *
 * CARA PAKAI
 *   1. Upload semua file ke hosting
 *   2. Buka  https://domainmu.epizy.com/install.php   di browser
 *   3. Ikuti wizard, lalu HAPUS file ini
 *
 * Untuk MySQL skema juga bisa diimport lewat phpMyAdmin (Import -> database.sql)
 * dan file ini hanya akan memverifikasi hasilnya.
 *
 * Keamanan: file ini membuat akun admin. Setelah selesai, HAPUS file ini dari
 * server. Kalau tidak, siapa pun bisa memanggilnya ulang.
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/helper.php';

$step  = $_GET['step'] ?? '1';
$error = null;
$done  = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === '3') {
    $username = post('username', 'admin');
    $password = (string) ($_POST['password'] ?? '');
    $confirm  = (string) ($_POST['password_confirm'] ?? '');
    $nama     = post('nama_lengkap', 'Administrator');

    if (strlen($password) < PASSWORD_MIN_LENGTH) {
        $error = 'Password minimal ' . PASSWORD_MIN_LENGTH . ' karakter.';
    } elseif ($password !== $confirm) {
        $error = 'Konfirmasi password tidak sama.';
    } else {
        try {
            $exists = db_value('SELECT id FROM users WHERE username = ?', [$username]);
            if ($exists !== null) {
                $error = 'Username sudah dipakai.';
            } else {
                db_insert(
                    'INSERT INTO users (username, password_hash, nama_lengkap, role, is_active)
                     VALUES (?, ?, ?, ?, 1)',
                    [$username, password_hash($password, PASSWORD_DEFAULT), $nama, 'admin']
                );
                audit_log(null, 'INSTALL_ADMIN_CREATED', 'info',
                    'Akun admin awal dibuat: ' . $username);
                $done = true;
            }
        } catch (Throwable $e) {
            $error = 'Gagal membuat akun: ' . $e->getMessage();
        }
    }
}

function table_exists(string $name): bool
{
    try {
        if (db_driver() === 'sqlite') {
            return db_value(
                "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=?",
                [$name]
            ) > 0;
        }
        if (db_driver() === 'pgsql') {
            return (int) db_value(
                'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = ?',
                [$name],
                0
            ) > 0;
        }
        return (int) db_value(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            [$name],
            0
        ) > 0;
    } catch (Throwable $e) {
        return false;
    }
}

$tables = ['users', 'kelas', 'face_templates', 'lokasi_sekolah',
           'attendance', 'scan_nonces', 'audit_log', 'pengaturan', 'device_log',
           'izin_requests', 'laporan_aman'];

$existing = array_values(array_filter($tables, 'table_exists'));
$missing  = array_values(array_diff($tables, $existing));

/* ------------------------------------------------------------------
 |  Langkah 2: buat skema
 * ------------------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === '2') {
    try {
        if (db_driver() === 'sqlite') {
            run_sqlite_schema();
        } else {
            run_mysql_schema();
        }
        $step = '3';
    } catch (Throwable $e) {
        $error = 'Gagal membuat skema: ' . $e->getMessage();
    }
    $existing = array_values(array_filter($tables, 'table_exists'));
    $missing  = array_values(array_diff($tables, $existing));
}

/**
 * Skema SQLite. Sengaja memakai tipe longgar agar PDO::PARAM_STR
 * bisa menerima BLOB tanpa konversi.
 */
function run_sqlite_schema(): void
{
    $pdo = db();
    $sql = <<<'SQL'
CREATE TABLE IF NOT EXISTS users (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  username      TEXT NOT NULL UNIQUE,
  password_hash TEXT NOT NULL,
  nama_lengkap  TEXT NOT NULL,
  role          TEXT NOT NULL DEFAULT 'siswa',
  nis           TEXT,
  nisn          TEXT,
  kelas_id      INTEGER,
  jenis_kelamin TEXT,
  is_active     INTEGER NOT NULL DEFAULT 1,
  login_attempts INTEGER NOT NULL DEFAULT 0,
  locked_until  TEXT,
  last_login    TEXT,
  created_at    TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS kelas (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  nama_kelas   TEXT NOT NULL,
  tingkat      TEXT NOT NULL,
  jurusan      TEXT,
  tahun_ajaran TEXT NOT NULL,
  wali_kelas   INTEGER,
  is_active    INTEGER NOT NULL DEFAULT 1,
  created_at   TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS face_templates (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id     INTEGER NOT NULL,
  descriptor  BLOB NOT NULL,
  is_centroid INTEGER NOT NULL DEFAULT 0,
  sample_no   INTEGER NOT NULL DEFAULT 0,
  quality     REAL NOT NULL DEFAULT 0,
  created_at  TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS ix_face_user ON face_templates(user_id);
CREATE INDEX IF NOT EXISTS ix_face_centroid ON face_templates(is_centroid);
CREATE INDEX IF NOT EXISTS ix_face_user_centroid ON face_templates(user_id, is_centroid);

CREATE TABLE IF NOT EXISTS lokasi_sekolah (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  nama       TEXT NOT NULL,
  lat        REAL NOT NULL,
  lng        REAL NOT NULL,
  radius_m   INTEGER NOT NULL DEFAULT 100,
  is_active  INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS attendance (
  id              INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id         INTEGER NOT NULL,
  tanggal         TEXT NOT NULL,
  scan_type       TEXT NOT NULL DEFAULT 'masuk',
  waktu           TEXT NOT NULL,
  status          TEXT NOT NULL DEFAULT 'hadir',
  lat             REAL,
  lng             REAL,
  accuracy_m      REAL,
  distance_m      REAL,
  gps_verdict     TEXT NOT NULL DEFAULT 'DITOLAK',
  gps_score       INTEGER NOT NULL DEFAULT 0,
  is_late         INTEGER NOT NULL DEFAULT 0,
  late_minutes    INTEGER,
  face_distance   REAL,
  face_confidence REAL,
  liveness_codes  TEXT,
  device_fingerprint TEXT,
  ip_address      TEXT,
  created_at      TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE(user_id, tanggal, scan_type)
);
CREATE INDEX IF NOT EXISTS ix_absen_tanggal ON attendance(tanggal);
CREATE INDEX IF NOT EXISTS ix_absen_status ON attendance(tanggal, status);

CREATE TABLE IF NOT EXISTS scan_nonces (
  id      INTEGER PRIMARY KEY AUTOINCREMENT,
  nonce   TEXT NOT NULL UNIQUE,
  user_id INTEGER NOT NULL,
  used_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS audit_log (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id    INTEGER,
  event_type TEXT NOT NULL,
  severity   TEXT NOT NULL DEFAULT 'info',
  details    TEXT,
  ip_address TEXT,
  user_agent TEXT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS ix_audit_user ON audit_log(user_id);
CREATE INDEX IF NOT EXISTS ix_audit_event ON audit_log(event_type);
CREATE INDEX IF NOT EXISTS ix_audit_created ON audit_log(created_at);

CREATE TABLE IF NOT EXISTS pengaturan (
  key_name   TEXT NOT NULL PRIMARY KEY,
  value      TEXT,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS device_log (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id     INTEGER NOT NULL,
  fingerprint TEXT NOT NULL,
  user_agent  TEXT,
  ip_address  TEXT,
  seen_at     TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE(user_id, fingerprint)
);
CREATE INDEX IF NOT EXISTS ix_device_fp ON device_log(fingerprint);

-- Pengajuan izin / sakit oleh siswa. Terpisah dari attendance karena
-- attendance adalah hasil scan (bukti kehadiran), sedangkan ini adalah
-- permintaan yang belum tentu disetujui petugas.
CREATE TABLE IF NOT EXISTS izin_requests (
  id              INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id         INTEGER NOT NULL,
  jenis           TEXT NOT NULL DEFAULT 'izin',      -- izin | sakit
  tanggal_mulai   TEXT NOT NULL,
  tanggal_selesai TEXT NOT NULL,
  alasan          TEXT,
  status          TEXT NOT NULL DEFAULT 'menunggu', -- menunggu | disetujui | ditolak
  catatan_petugas TEXT,
  diproses_oleh   INTEGER,
  created_at      TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS ix_izin_user    ON izin_requests(user_id);
CREATE INDEX IF NOT EXISTS ix_izin_status  ON izin_requests(status);
CREATE INDEX IF NOT EXISTS ix_izin_periode ON izin_requests(tanggal_mulai, tanggal_selesai);

-- Laporan "Lapor Aman": kanal siswa melaporkan perundungan, masalah keluarga,
-- dan tekanan emosi. user_id boleh NULL (laporan anonim). Tabel ini SENGAJA
-- tidak menyimpan IP atau user agent: satu jejak saja cukup membongkar
-- identitas pelapor anonim, dan fiturnya kehilangan gunanya.
CREATE TABLE IF NOT EXISTS laporan_aman (
  id             INTEGER PRIMARY KEY AUTOINCREMENT,
  kode_akses     TEXT NOT NULL,
  user_id        INTEGER,                            -- NULL bila anonim
  anonim         INTEGER NOT NULL DEFAULT 0,
  kategori       TEXT NOT NULL DEFAULT 'lainnya',    -- perundungan|keluarga|kesehatan|keselamatan|lainnya
  urgensi        TEXT NOT NULL DEFAULT 'biasa',      -- biasa | perhatian | darurat
  isi            TEXT NOT NULL,
  status         TEXT NOT NULL DEFAULT 'baru',       -- baru | diproses | selesai
  catatan_bk     TEXT,
  ditangani_oleh INTEGER,
  created_at     TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ditangani_at   TEXT
);
CREATE UNIQUE INDEX IF NOT EXISTS uq_laporan_kode    ON laporan_aman(kode_akses);
CREATE INDEX IF NOT EXISTS ix_laporan_status  ON laporan_aman(status);
CREATE INDEX IF NOT EXISTS ix_laporan_urgensi ON laporan_aman(urgensi);
CREATE INDEX IF NOT EXISTS ix_laporan_user    ON laporan_aman(user_id);
SQL;

    $pdo->exec($sql);

    // Benih data, sama dengan database.sql
    $pdo->exec("INSERT OR IGNORE INTO lokasi_sekolah (nama, lat, lng, radius_m)
                VALUES ('Gerbang Utama', -6.2000000, 106.8166660, 100)");
    $pdo->exec("INSERT OR IGNORE INTO pengaturan (key_name, value) VALUES
                ('nama_sekolah','SMA Negeri Contoh'),
                ('face_threshold','0.55'),
                ('gps_radius_m','100'),
                ('allow_offline','0')");
}

/**
 * Skema MySQL: pecah database.sql per pernyataan.
 * Transaction dibungkus karena MySQL otomatis meng-commit tiap DDL.
 */
function run_mysql_schema(): void
{
    $path = __DIR__ . '/database.sql';
    if (!is_file($path)) {
        throw new RuntimeException('database.sql tidak ditemukan');
    }

    $raw = file_get_contents($path);
    if ($raw === false) {
        throw new RuntimeException('database.sql tidak terbaca');
    }

    // Buang komentar di awal baris
    $raw = preg_replace('/^\s*--.*$/m', '', $raw);

    $statements = array_filter(
        array_map('trim', explode(';', $raw)),
        static fn($s) => $s !== ''
    );

    $pdo = db();
    foreach ($statements as $stmt) {
        $pdo->exec($stmt);
    }
}
?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Pemasangan Sistem Presensi</title>
<style>
  body { font-family: system-ui, -apple-system, sans-serif; background: #f1f5f9;
         margin: 0; padding: 2rem 1rem; color: #0f172a; }
  .card { max-width: 640px; margin: 0 auto; background: #fff; border-radius: 12px;
          padding: 2rem; box-shadow: 0 4px 20px rgba(0,0,0,.08); }
  h1 { font-size: 1.4rem; margin: 0 0 .25rem; }
  .sub { color: #64748b; font-size: .9rem; margin-bottom: 1.5rem; }
  .badge { display: inline-block; padding: .2rem .6rem; border-radius: 999px;
           font-size: .75rem; font-weight: 600; }
  .b-sqlite { background: #dbeafe; color: #1e40af; }
  .b-mysql  { background: #fef3c7; color: #92400e; }
  table { width: 100%; border-collapse: collapse; font-size: .875rem; margin: 1rem 0; }
  th, td { text-align: left; padding: .45rem .6rem; border-bottom: 1px solid #e2e8f0; }
  .ok { color: #15803d; }
  .no { color: #b91c1c; }
  label { display: block; font-size: .8rem; font-weight: 600; margin: .8rem 0 .25rem; }
  input { width: 100%; padding: .55rem .7rem; border: 1px solid #cbd5e1;
          border-radius: 6px; font-size: .95rem; box-sizing: border-box; }
  button { margin-top: 1.4rem; width: 100%; padding: .7rem; border: 0;
           border-radius: 6px; background: #2563eb; color: #fff; font-size: .95rem;
           font-weight: 600; cursor: pointer; }
  button:hover { background: #1d4ed8; }
  .alert { padding: .7rem .9rem; border-radius: 6px; font-size: .875rem; margin: 1rem 0; }
  .err  { background: #fee2e2; color: #991b1b; }
  .warn { background: #fef3c7; color: #92400e; }
  .done { background: #dcfce7; color: #166534; }
  code { background: #f1f5f9; padding: .1rem .3rem; border-radius: 4px; font-size: .85em; }
</style>
</head>
<body>
<div class="card">

<h1>Pemasangan Sistem Presensi</h1>
<div class="sub">
  Driver aktif:
  <span class="badge <?= db_driver() === 'sqlite' ? 'b-sqlite' : 'b-mysql' ?>">
    <?= htmlspecialchars(db_driver()) ?>
  </span>
</div>

<?php if ($error): ?>
  <div class="alert err"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<?php if ($done): ?>
  <div class="alert done">
    <strong>Akun admin berhasil dibuat.</strong><br>
    Sekarang <strong>hapus file install.php</strong> dari server, lalu login.
  </div>
  <p><a href="index.php">Ke halaman login</a></p>

<?php elseif ($missing): ?>
  <h2 style="font-size:1.05rem">Langkah 1 — Buat skema</h2>
  <p style="font-size:.9rem">
    Ditemukan <strong><?= count($existing) ?> dari <?= count($tables) ?></strong> tabel.
    Schema belum lengkap.
  </p>
  <table>
    <tr><th>Tabel</th><th>Status</th></tr>
    <?php foreach ($tables as $t): $ok = in_array($t, $existing, true); ?>
    <tr>
      <td><code><?= htmlspecialchars($t) ?></code></td>
      <td class="<?= $ok ? 'ok' : 'no' ?>"><?= $ok ? 'ada' : 'belum ada' ?></td>
    </tr>
    <?php endforeach; ?>
  </table>

  <?php if (db_driver() === 'mysql'): ?>
    <div class="alert warn">
      Untuk MySQL, skema juga bisa diimport manual:<br>
      phpMyAdmin &rarr; pilih database &rarr; tab <strong>Import</strong>
      &rarr; pilih <code>database.sql</code> &rarr; <strong>Go</strong>.<br>
      Tombol di bawah melakukan hal yang sama secara otomatis.
    </div>
  <?php endif; ?>

  <form method="post" action="?step=2">
    <button type="submit">Buat skema database</button>
  </form>

<?php else: ?>
  <div class="alert done">Semua tabel sudah siap.</div>

  <h2 style="font-size:1.05rem">Langkah 2 — Akun admin</h2>
  <form method="post" action="?step=3">
    <label>Nama lengkap</label>
    <input name="nama_lengkap" value="<?= htmlspecialchars(post('nama_lengkap', 'Administrator')) ?>" required>

    <label>Username</label>
    <input name="username" value="<?= htmlspecialchars(post('username', 'admin')) ?>" required>

    <label>Password (min <?= PASSWORD_MIN_LENGTH ?> karakter)</label>
    <input name="password" type="password" minlength="<?= PASSWORD_MIN_LENGTH ?>" required>

    <label>Ulangi password</label>
    <input name="password_confirm" type="password" minlength="<?= PASSWORD_MIN_LENGTH ?>" required>

    <button type="submit">Buat akun admin</button>
  </form>
<?php endif; ?>

</div>
</body>
</html>
