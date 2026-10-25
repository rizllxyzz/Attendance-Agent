<?php
/**
 * config/laporan.php
 * ---------------------------------------------------------------------------
 * Fitur "Lapor Aman": kanal bagi siswa untuk melaporkan hal yang membuat
 * mereka tidak nyaman atau tidak aman (perundungan, masalah keluarga,
 * tekanan mental, dan sejenisnya).
 *
 * KENAPA DIPISAH
 * --------------
 * Tabel dan aturannya dipakai DUA dunia sekaligus: portal siswa (mengirim
 * laporan) dan panel guru/BK (menindaklanjuti). Kedua bootstrap sengaja
 * tidak saling memuat karena punya fungsi dengan nama sama, jadi aturan
 * bersama ditaruh di satu berkas netral ini. Semua fungsi di sini memakai
 * awalan laporan_ supaya tidak bentrok dengan fungsi mana pun.
 *
 * PRINSIP
 * -------
 *  1. Laporan boleh dikirim TANPA identitas. Ini pintu utama: korban
 *     perundungan sering tidak berani melapor kalau namanya tercatat.
 *  2. Yang anonim itu PENGIRIMNYA, bukan isinya. Isi laporan tetap utuh,
 *     jadi guru tetap bisa menindaklanjuti kejadiannya.
 *  3. Jangan pernah menyimpan IP, user agent, atau sidik perangkat di
 *     tabel ini. Satu kolom jejak saja sudah cukup membongkar identitas
 *     pelapor anonim, dan seluruh gunanya fitur ini hilang.
 *  4. Tingkat urgensi yang dihitung di sini hanya PERKIRAAN AWAL dari
 *     kata kunci. Ini alat bantu memilah, bukan penilaian ahli. Halaman
 *     guru menampilkannya apa adanya supaya tidak disalahartikan.
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/helper.php';

/* ==================================================================
 |  KATEGORI & URGENSI
 | ================================================================== */

/**
 * Kategori laporan. Kunci disimpan di database, label dipakai di layar.
 * Urutannya sengaja seperti ini: yang paling sering dilaporkan lebih dulu.
 */
function laporan_kategori(): array
{
    return [
        'perundungan' => 'Perundungan / Bullying',
        'keluarga'    => 'Masalah Keluarga',
        'kesehatan'   => 'Kesehatan / Tekanan Emosi',
        'keselamatan' => 'Merasa Tidak Aman',
        'lainnya'     => 'Lainnya',
    ];
}

function laporan_kategori_label(string $k): string
{
    $daftar = laporan_kategori();
    return $daftar[$k] ?? 'Lainnya';
}

/** Nama berkas ikon Font Awesome untuk tiap kategori. */
function laporan_kategori_ikon(string $k): string
{
    return [
        'perundungan' => 'fa-hand-fist',
        'keluarga'    => 'fa-house-crack',
        'kesehatan'   => 'fa-heart-pulse',
        'keselamatan' => 'fa-shield-halved',
        'lainnya'     => 'fa-comment-dots',
    ][$k] ?? 'fa-comment-dots';
}

/**
 * Tingkat urgensi. Urutan tingkat dipakai untuk "naikkan, jangan turunkan":
 * pilihan siswa dan deteksi kata kunci digabung dengan mengambil yang
 * paling tinggi, bukan yang terakhir diproses.
 */
function laporan_urgensi_tingkat(string $u): int
{
    return ['biasa' => 0, 'perhatian' => 1, 'darurat' => 2][$u] ?? 0;
}

function laporan_urgensi_label(string $u): string
{
    return [
        'biasa'     => 'Biasa',
        'perhatian' => 'Perlu Perhatian',
        'darurat'   => 'Darurat',
    ][$u] ?? 'Biasa';
}

/**
 * Perkiraan urgensi dari kata kunci.
 *
 * Sengaja TIDAK memakai model AI: laporan harus tetap bisa dikirim dan
 * ditandai walau server agent sedang mati. Kata kunci hanya menaikkan
 * tingkat, tidak pernah menurunkannya.
 *
 * Daftar ini tidak lengkap dan tidak akan pernah lengkap. Tujuannya
 * menangkap sinyal paling jelas (kata tentang menyakiti diri, kekerasan
 * fisik, ancaman) supaya laporan seperti itu tidak tenggelam di tumpukan.
 */
function laporan_urgensi_keyword(string $isi): string
{
    $teks = ' ' . mb_strtolower($isi) . ' ';
    $teks = str_replace(['-', '_', '/'], ' ', $teks);

    $darurat = [
        'bunuh diri', 'mengakhiri hidup', 'akhiri hidup', 'ingin mati', 'pengen mati',
        'mau mati', 'menyakiti diri', 'sakiti diri', 'melukai diri', 'self harm',
        'nyawa', 'darah', 'bersimbah', 'senjata', 'pisau', 'diancam', 'ancaman',
        'dipukul', 'dihajar', 'dianiaya', 'pemerkosaan', 'diperkosa', 'pelecehan',
        'disentuh', 'dipaksa', 'overdosis', 'kabur dari rumah', 'diculik',
        'keselamatan', 'mengancam', 'menoda', 'melukai',
    ];
    $perhatian = [
        'bully', 'bullying', 'bulli', 'perundungan', 'rundung', 'diejek', 'ejek',
        'dihina', 'hina', 'dikucilkan', 'kucil', 'dipalak', 'palak', 'diperas',
        'diganggu', 'ganggu', 'diteror', 'teror', 'ditakuti', 'takut',
        'sedih', 'menangis', 'nangis', 'depresi', 'cemas', 'stress', 'stres',
        'putus asa', 'sendirian', 'kesepian', 'dijauhi', 'rumah', 'bertengkar',
        'berantem', 'cerai', 'kdrrt', 'kekerasan', 'ekonomi', 'biaya',
    ];

    foreach ($darurat as $kata) {
        if (mb_strpos($teks, $kata) !== false) {
            return 'darurat';
        }
    }
    foreach ($perhatian as $kata) {
        if (mb_strpos($teks, $kata) !== false) {
            return 'perhatian';
        }
    }

    return 'biasa';
}

/**
 * Gabungkan tingkat yang dipilih siswa dengan perkiraan kata kunci.
 * Yang dipakai selalu yang lebih tinggi.
 */
function laporan_urgensi(array $laporan): string
{
    $pilih = (string) ($laporan['urgensi_pilih'] ?? 'biasa');
    $pilih = in_array($pilih, ['biasa', 'perhatian', 'darurat'], true) ? $pilih : 'biasa';

    $kata = laporan_urgensi_keyword((string) ($laporan['isi'] ?? ''));

    return laporan_urgensi_tingkat($kata) > laporan_urgensi_tingkat($pilih) ? $kata : $pilih;
}

/* ==================================================================
 |  TABEL
 | ================================================================== */

function laporan_tabel_ada(bool $segarkan = false): bool
{
    static $cache = null;
    if (!$segarkan && $cache !== null) {
        return $cache;
    }

    try {
        if (db_driver() === 'sqlite') {
            $ada = (int) db_value(
                "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'laporan_aman'",
                [],
                0
            ) > 0;
        } else {
            $ada = (int) db_value(
                "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?",
                ['laporan_aman'],
                0
            ) > 0;
        }
        $cache = $ada;
    } catch (Throwable $e) {
        $cache = false;
    }

    return $cache;
}

/**
 * Pastikan tabel laporan_aman ada.
 *
 * Dibuat oleh sisi siswa maupun sisi guru, sesuai pola "pastikan tabel
 * hanya saat halaman membutuhkannya". Kegagalan tidak menjatuhkan halaman.
 */
function laporan_pastikan_tabel(): bool
{
    if (laporan_tabel_ada()) {
        return true;
    }

    $kunci    = db_driver() === 'sqlite'
        ? 'TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP'
        : 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP';
    $nullable = db_driver() === 'sqlite' ? 'TEXT DEFAULT NULL' : 'DATETIME DEFAULT NULL';

    try {
        db()->exec(
            'CREATE TABLE IF NOT EXISTS laporan_aman ('
            . 'id ' . pk() . ','
            . 'kode_akses VARCHAR(12) NOT NULL,'
            . 'user_id ' . iu() . ' DEFAULT NULL,'
            . 'anonim INTEGER NOT NULL DEFAULT 0,'
            . 'kategori ' . enum_col(['perundungan', 'keluarga', 'kesehatan', 'keselamatan', 'lainnya'], 'lainnya') . ','
            . 'urgensi ' . enum_col(['biasa', 'perhatian', 'darurat'], 'biasa') . ','
            . 'isi TEXT NOT NULL,'
            . 'status ' . enum_col(['baru', 'diproses', 'selesai'], 'baru') . ','
            . 'catatan_bk VARCHAR(500) DEFAULT NULL,'
            . 'ditangani_oleh ' . iu() . ' DEFAULT NULL,'
            . 'created_at ' . $kunci . ','
            . 'ditangani_at ' . $nullable
            . ')' . suffix()
        );

        foreach ([
            'CREATE UNIQUE INDEX IF NOT EXISTS uq_laporan_kode ON laporan_aman(kode_akses)',
            'CREATE INDEX IF NOT EXISTS ix_laporan_status ON laporan_aman(status)',
            'CREATE INDEX IF NOT EXISTS ix_laporan_urgensi ON laporan_aman(urgensi)',
            'CREATE INDEX IF NOT EXISTS ix_laporan_user ON laporan_aman(user_id)',
        ] as $sqlIdx) {
            try {
                db()->exec($sqlIdx);
            } catch (Throwable $eIdx) {
                error_log('[LAPORAN] Index dilewati: ' . $eIdx->getMessage());
            }
        }
    } catch (Throwable $e) {
        error_log('[LAPORAN] Gagal menyiapkan tabel: ' . $e->getMessage());
        return false;
    }

    return laporan_tabel_ada(true);
}

/* ==================================================================
 |  UTILITAS
 | ================================================================== */

/**
 * Kode akses anonim, mis. "AMAN-7A2F9C".
 *
 * Dipakai pelapor untuk memantau laporan tanpa membuka identitas. Kode
 * dibuat acak dari random_bytes, bukan dari id/user_id, supaya tidak bisa
 * ditebak dan tidak menautkan ke siapa pun.
 */
function laporan_kode_baru(): string
{
    return 'AMAN-' . strtoupper(bin2hex(random_bytes(3)));
}

function laporan_status_label(string $s): string
{
    return [
        'baru'     => 'Baru',
        'diproses' => 'Sedang Ditangani',
        'selesai'  => 'Selesai',
    ][$s] ?? 'Baru';
}
