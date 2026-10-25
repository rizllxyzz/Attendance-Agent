<?php
/**
 * admin/impor.php
 * ---------------------------------------------------------------------------
 * Impor massal KELAS & SISWA dalam SATU berkas CSV.
 *
 * CARA KERJA
 * ----------
 * Setiap baris mewakili satu siswa. Kolom kelas (nama_kelas, tingkat,
 * jurusan) di baris yang sama sekaligus mendefinisikan kelasnya: kalau
 * kelas dengan nama itu belum ada, sistem membuatnya otomatis. Jadi
 * pengguna cukup menyiapkan SATU berkas berisi daftar siswa; kelas ikut
 * terbentuk tanpa langkah terpisah.
 *
 * KENAPA CSV, BUKAN XLSX
 * -----------------------
 * Membaca .xlsx butuh pustaka pihak ketiga (PhpSpreadsheet) yang tidak
 * tersedia dan berat di hosting gratis. CSV bisa dibuka & disimpan dari
 * Excel maupun Google Sheets, dan cukup dibaca dengan fungsi bawaan PHP.
 *
 * KEAMANAN
 * --------
 *   - Hanya administrator yang boleh membuka halaman ini.
 *   - Ukuran berkas dan jumlah baris dibatasi.
 *   - Password di-hash dengan cost yang sama seperti login.
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_data.php';

const IMPOR_MAX_BYTE    = 2097152;   /* 2 MB */
const IMPOR_MAX_BARIS   = 5000;
const IMPOR_TAMPIL_MAKS = 100;       /* baris galat/pesan yang ditampilkan */

$pageKey   = 'impor';
$pageTitle = 'Impor Kelas & Siswa';
$pageSub   = 'Unggah satu berkas CSV berisi daftar siswa; kelasnya dibuat otomatis';

if (!$isAdmin) {
    admin_flash('warn', 'Impor massal hanya untuk administrator.');
    header('Location: /admin/index.php');
    exit;
}

/* ==================================================================
 |  FUNGSI BANTU
 * ================================================================== */

/** Normalisasi nama kolom: huruf kecil, hanya a-z0-9. */
function impor_norm(string $s): string
{
    return (string) preg_replace('/[^a-z0-9]/', '', mb_strtolower(trim($s)));
}

/** Nama kolom yang dikenali (beserta alias umum dari Excel/Sheets). */
function impor_alias(): array
{
    return [
        'nama_lengkap'  => ['namalengkap', 'nama', 'namasiswa', 'namapesertadidik', 'name'],
        'username'      => ['username', 'user', 'userid', 'akun', 'login', 'idlogin'],
        'password'      => ['password', 'sandi', 'pass', 'katasandi'],
        'kelas'         => ['kelas', 'namakelas', 'rombel', 'class', 'rombonganbelajar'],
        'tingkat'       => ['tingkat', 'grade', 'kelastingkat'],
        'jurusan'       => ['jurusan', 'program', 'programkeahlian', 'kompetensikeahlian', 'kompetensi', 'prodi'],
        'nis'           => ['nis', 'nomorinduk', 'noinduk', 'nomorinduksiswa'],
        'nisn'          => ['nisn', 'nomorinduksiswanasional'],
        'jenis_kelamin' => ['jeniskelamin', 'jk', 'gender', 'lp', 'jeniskelaminsiswa'],
        'aktif'         => ['aktif', 'isactive', 'status', 'statusaktif'],
    ];
}

/**
 * Petakan indeks kolom ke nama field: [field => indeks].
 * Berdasarkan NAMA kolom (baris judul), sehingga urutan kolom bebas dan
 * kolom yang tidak dikenal diabaikan.
 */
function impor_peta(array $header, array $alias): array
{
    $peta = [];
    foreach ($header as $i => $h) {
        $k = impor_norm((string) $h);
        if ($k === '') {
            continue;
        }
        foreach ($alias as $field => $nama) {
            if (in_array($k, $nama, true)) {
                $peta[$field] = (int) $i;
                break;
            }
        }
    }
    return $peta;
}

/** Ambil nilai satu field dari baris, berdasarkan peta kolom. */
function impor_nilai(array $kolom, array $peta, string $field): string
{
    if (!isset($peta[$field])) {
        return '';
    }
    return trim((string) ($kolom[$peta[$field]] ?? ''));
}

/** Tebak pemisah kolom dari baris judul. */
function impor_tebak_pemisah(string $baris): string
{
    $hitung = [
        ','  => substr_count($baris, ','),
        ';'  => substr_count($baris, ';'),
        "\t" => substr_count($baris, "\t"),
    ];
    arsort($hitung);
    $kunci = (string) array_key_first($hitung);
    return $hitung[$kunci] > 0 ? $kunci : ',';
}

/** Ubah isi berkas CSV menjadi daftar baris (array of array). */
function impor_urai(string $isi): array
{
    $isi = str_replace(["\r\n", "\r"], "\n", $isi);

    $pemisah = ',';
    foreach (explode("\n", $isi) as $ln) {
        if (trim($ln) !== '') {
            $pemisah = impor_tebak_pemisah($ln);
            break;
        }
    }

    $fh = fopen('php://temp', 'r+');
    if ($fh === false) {
        return [];
    }
    fwrite($fh, $isi);
    rewind($fh);
    $baris = [];
    while (($row = fgetcsv($fh, 0, $pemisah, '"', '\\')) !== false) {
        if ($row === [null] || (count($row) === 1 && trim((string) $row[0]) === '')) {
            continue;
        }
        $baris[] = array_map(static fn($v) => (string) ($v ?? ''), $row);
    }
    fclose($fh);
    return $baris;
}

/** Jenis kelamin dari berbagai penulisan. */
function impor_jk(string $v): ?string
{
    $s = mb_strtolower(trim($v));
    if ($s === '') {
        return null;
    }
    if (in_array($s, ['l', 'lk', 'laki-laki', 'laki laki', 'pria', 'male', 'm'], true)) {
        return 'L';
    }
    if (in_array($s, ['p', 'pr', 'perempuan', 'wanita', 'female', 'f'], true)) {
        return 'P';
    }
    return null;
}

/** Status aktif: kosong dianggap aktif. */
function impor_aktif(string $v): int
{
    $s = mb_strtolower(trim($v));
    if (in_array($s, ['0', 'nonaktif', 'tidak', 'no', 'n', 'false', 'off', 'non-aktif'], true)) {
        return 0;
    }
    return 1;
}

/** Perkirakan tingkat dari nama kelas, mis. "X IPA 1" -> "10". */
function impor_tingkat_dari_nama(string $nama): string
{
    $nama = trim($nama);
    if (preg_match('/^(\d{1,2})/', $nama, $m)) {
        return $m[1];
    }
    $romawi = admin_romawi_ke_angka($nama);
    if ($romawi > 0) {
        return (string) $romawi;
    }
    return '';
}

/** Cari id kelas (nama + tahun ajaran). */
function impor_cari_kelas(string $nama, string $tahun): ?int
{
    $row = db_one(
        'SELECT id FROM kelas WHERE LOWER(nama_kelas) = LOWER(?) AND tahun_ajaran = ? LIMIT 1',
        [$nama, $tahun],
        null
    );
    return $row ? (int) $row['id'] : null;
}

/**
 * Ambil isi berkas yang diunggah, dengan validasi ramah.
 * @return array{ok:bool, isi:string, pesan:string}
 */
function impor_ambil_isi(): array
{
    $up = $_FILES['berkas'] ?? null;
    if (!is_array($up) || ($up['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'isi' => '', 'pesan' => 'Berkas belum dipilih.'];
    }
    if (($up['error'] ?? 0) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'isi' => '', 'pesan' => 'Gagal mengunggah berkas (kode ' . (int) $up['error'] . ').'];
    }

    $tmp = (string) ($up['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['ok' => false, 'isi' => '', 'pesan' => 'Berkas unggahan tidak sah.'];
    }

    $size = (int) ($up['size'] ?? 0);
    if ($size <= 0) {
        return ['ok' => false, 'isi' => '', 'pesan' => 'Berkas kosong.'];
    }
    if ($size > IMPOR_MAX_BYTE) {
        return ['ok' => false, 'isi' => '', 'pesan' => 'Berkas lebih dari 2 MB. Pecah menjadi beberapa bagian.'];
    }

    $isi = (string) file_get_contents($tmp);

    if (strncmp($isi, "\xEF\xBB\xBF", 3) === 0) {
        $isi = substr($isi, 3);
    }
    if (strncmp($isi, "PK\x03\x04", 4) === 0) {
        return ['ok' => false, 'isi' => '',
            'pesan' => 'Berkas ini Excel (.xlsx). Buka di Excel lalu File > Save As > CSV, lalu unggah berkas CSV-nya.'];
    }
    if (strncmp($isi, "\xD0\xCF\x11\xE0", 4) === 0) {
        return ['ok' => false, 'isi' => '', 'pesan' => 'Berkas ini Excel lama (.xls). Simpan sebagai CSV lalu unggah lagi.'];
    }
    if (!mb_check_encoding($isi, 'UTF-8')) {
        $isi = mb_convert_encoding($isi, 'UTF-8', 'Windows-1252');
    }

    return ['ok' => true, 'isi' => $isi, 'pesan' => ''];
}

/**
 * Proses impor: satu baris = satu siswa; kelas dibuat/dicari otomatis.
 * @return array hasil ringkas + daftar galat/lewati
 */
function impor_proses(array $baris, array $opsi): array
{
    $hasil = [
        'dibuat' => 0, 'diperbarui' => 0, 'dilewati' => 0, 'kelas_baru' => 0,
        'gagal_total' => 0, 'gagal' => [],
        'lewati_total' => 0, 'lewati' => [],
    ];
    if ($baris === []) {
        $hasil['gagal_total'] = 1;
        $hasil['gagal'][] = ['baris' => 1, 'pesan' => 'Berkas tidak berisi baris apa pun.'];
        return $hasil;
    }

    $header = array_shift($baris);
    $peta   = impor_peta($header, impor_alias());
    if (!isset($peta['nama_lengkap'])) {
        $hasil['gagal_total'] = 1;
        $hasil['gagal'][] = ['baris' => 1, 'pesan' => 'Kolom "nama_lengkap" tidak ada di baris judul. Unduh template supaya nama kolomnya pas.'];
        return $hasil;
    }

    $tahun       = (string) $opsi['tahun_ajaran'];
    $passDefault = (string) $opsi['password_default'];
    $bukaKelas   = !empty($opsi['buat_kelas']);
    $perbarui    = !empty($opsi['perbarui']);

    $cacheKelas = [];
    $no = 1;

    $catatGagal = static function (int $no, string $pesan) use (&$hasil): void {
        $hasil['gagal_total']++;
        if (count($hasil['gagal']) < IMPOR_TAMPIL_MAKS) {
            $hasil['gagal'][] = ['baris' => $no, 'pesan' => $pesan];
        }
    };

    foreach ($baris as $kolom) {
        $no++;
        if ($no > IMPOR_MAX_BARIS + 1) {
            $catatGagal($no, 'Batas ' . IMPOR_MAX_BARIS . ' baris tercapai; sisa baris tidak diproses.');
            break;
        }

        $nama      = impor_nilai($kolom, $peta, 'nama_lengkap');
        $username  = impor_nilai($kolom, $peta, 'username');
        $password  = impor_nilai($kolom, $peta, 'password');
        $namaKelas = impor_nilai($kolom, $peta, 'kelas');
        $tingkat   = impor_nilai($kolom, $peta, 'tingkat');
        $jurusan   = impor_nilai($kolom, $peta, 'jurusan');
        $nis       = impor_nilai($kolom, $peta, 'nis');
        $nisn      = impor_nilai($kolom, $peta, 'nisn');
        $jk        = impor_jk(impor_nilai($kolom, $peta, 'jenis_kelamin'));
        $aktif     = impor_aktif(impor_nilai($kolom, $peta, 'aktif'));

        if ($nama === '') {
            $catatGagal($no, 'Nama lengkap kosong.');
            continue;
        }
        if (mb_strlen($nama) > 100) {
            $catatGagal($no, 'Nama lebih dari 100 karakter.');
            continue;
        }
        if ($username === '') {
            $username = $nis;
        }
        if ($username === '') {
            $catatGagal($no, 'Username kosong dan NIS tidak diisi untuk dipakai sebagai username.');
            continue;
        }
        if (!preg_match('/^[a-z0-9._-]{3,32}$/i', $username)) {
            $catatGagal($no, 'Username "' . $username . '" tidak sah (3-32 karakter: huruf, angka, titik, garis, garis bawah).');
            continue;
        }
        if ($nis !== '' && !preg_match('/^[0-9]{1,20}$/', $nis)) {
            $catatGagal($no, 'NIS hanya boleh angka.');
            continue;
        }
        if ($nisn !== '' && !preg_match('/^[0-9]{1,20}$/', $nisn)) {
            $catatGagal($no, 'NISN hanya boleh angka.');
            continue;
        }

        $sandi = $password !== '' ? $password : $passDefault;
        if (strlen($sandi) < PASSWORD_MIN_LENGTH) {
            $catatGagal($no, 'Password minimal ' . PASSWORD_MIN_LENGTH . ' karakter (kolom password kosong memakai password default).');
            continue;
        }

        /* Kelas: cari; kalau belum ada & opsi aktif, buat baru. */
        $kelasId = null;
        if ($namaKelas !== '') {
            $kunci = mb_strtolower($namaKelas);
            if (array_key_exists($kunci, $cacheKelas)) {
                $kelasId = $cacheKelas[$kunci];
            } else {
                $kelasId = impor_cari_kelas($namaKelas, $tahun);
                if ($kelasId === null && $bukaKelas) {
                    $tk = $tingkat !== '' ? $tingkat : impor_tingkat_dari_nama($namaKelas);
                    if ($tk === '') {
                        $tk = '-';
                    }
                    $kelasId = db_insert(
                        'INSERT INTO kelas (nama_kelas, tingkat, jurusan, tahun_ajaran, is_active)
                         VALUES (?, ?, ?, ?, 1)',
                        [$namaKelas, $tk, $jurusan !== '' ? $jurusan : null, $tahun]
                    );
                    $hasil['kelas_baru']++;
                }
                $cacheKelas[$kunci] = $kelasId;
            }
            if ($kelasId === null) {
                $catatGagal($no, 'Kelas "' . $namaKelas . '" tidak ditemukan (pembuatan kelas otomatis sedang mati).');
                continue;
            }
        }

        /* Akun dengan username ini sudah ada? */
        $lama = db_one('SELECT id, role FROM users WHERE LOWER(username) = LOWER(?) LIMIT 1', [$username], null);
        if ($lama) {
            if ((string) $lama['role'] !== 'siswa') {
                $catatGagal($no, 'Username "' . $username . '" sudah dipakai akun ' . $lama['role'] . '.');
                continue;
            }
            if (!$perbarui) {
                $hasil['lewati_total']++;
                if (count($hasil['lewati']) < IMPOR_TAMPIL_MAKS) {
                    $hasil['lewati'][] = ['baris' => $no, 'pesan' => 'Username "' . $username . '" sudah ada (dilewati).'];
                }
                $hasil['dilewati']++;
                continue;
            }
            $idLama = (int) $lama['id'];
            if ($nis !== '' && db_one('SELECT id FROM users WHERE nis = ? AND id <> ? LIMIT 1', [$nis, $idLama], null)) {
                $catatGagal($no, 'NIS ' . $nis . ' sudah dipakai akun lain.');
                continue;
            }
            if ($nisn !== '' && db_one('SELECT id FROM users WHERE nisn = ? AND id <> ? LIMIT 1', [$nisn, $idLama], null)) {
                $catatGagal($no, 'NISN ' . $nisn . ' sudah dipakai akun lain.');
                continue;
            }
            db_exec(
                'UPDATE users
                    SET nama_lengkap = ?, nis = ?, nisn = ?, kelas_id = ?, jenis_kelamin = ?,
                        is_active = ?, password_hash = ?, updated_at = ?
                  WHERE id = ?',
                [
                    $nama, $nis !== '' ? $nis : null, $nisn !== '' ? $nisn : null,
                    $kelasId, $jk, $aktif,
                    password_hash($sandi, PASSWORD_BCRYPT, ['cost' => 12]),
                    db_now(), $idLama,
                ]
            );
            $hasil['diperbarui']++;
            continue;
        }

        /* Akun baru: pastikan NIS/NISN belum dipakai. */
        if ($nis !== '' && db_one('SELECT id FROM users WHERE nis = ? LIMIT 1', [$nis], null)) {
            $catatGagal($no, 'NIS ' . $nis . ' sudah dipakai akun lain.');
            continue;
        }
        if ($nisn !== '' && db_one('SELECT id FROM users WHERE nisn = ? LIMIT 1', [$nisn], null)) {
            $catatGagal($no, 'NISN ' . $nisn . ' sudah dipakai akun lain.');
            continue;
        }

        db_insert(
            "INSERT INTO users (username, password_hash, nama_lengkap, role, nis, nisn,
                                kelas_id, jenis_kelamin, is_active)
             VALUES (?, ?, ?, 'siswa', ?, ?, ?, ?, ?)",
            [
                $username,
                password_hash($sandi, PASSWORD_BCRYPT, ['cost' => 12]),
                $nama, $nis !== '' ? $nis : null, $nisn !== '' ? $nisn : null,
                $kelasId, $jk, $aktif,
            ]
        );
        $hasil['dibuat']++;
    }

    return $hasil;
}

/* ==================================================================
 |  UNDUH TEMPLATE (GET ?template=1)
 * ================================================================== */
if (get('template', '') !== '') {
    if (!$isAdmin) {
        http_response_code(403);
        exit('Hanya administrator.');
    }
    admin_csv(
        [
            ['Ahmad Fauzi', 'ahmad.fauzi', 'ahmad1234', 'X IPA 1', '10', 'IPA', '24001', '0012345678', 'L', '1'],
            ['Siti Nurhaliza', 'siti.nur', 'siti1234', 'X IPA 1', '10', 'IPA', '24002', '0012345679', 'P', '1'],
            ['Budi Santoso', 'budi.s', 'budi1234', 'X IPA 1', '10', 'IPA', '24003', '0012345680', 'L', '1'],
            ['Citra Ayu', 'citra.a', '', 'XI RPL 1', '11', 'Rekayasa Perangkat Lunak', '23001', '', 'P', '1'],
            ['Dimas Adi', 'dimas.a', '', 'XI RPL 1', '11', 'Rekayasa Perangkat Lunak', '23002', '', 'L', '1'],
            ['Eka Putri', 'eka.p', '', 'XII IPS 1', '12', 'IPS', '22001', '', 'P', '1'],
        ],
        ['nama_lengkap', 'username', 'password', 'kelas', 'tingkat', 'jurusan', 'nis', 'nisn', 'jenis_kelamin', 'aktif'],
        'template-impor-siswa'
    );
}

/* ==================================================================
 |  PROSES POST
 * ================================================================== */
$hasilImpor = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_hanya_post();

    $berkas = impor_ambil_isi();
    if (!$berkas['ok']) {
        admin_flash('error', $berkas['pesan']);
        admin_kembali();
    }

    $tahun = post('tahun_ajaran', admin_default_tahun_ajaran());
    if (!preg_match('/^\d{4}\/\d{4}$/', $tahun)) {
        $tahun = admin_default_tahun_ajaran();
    }

    $passDefault = (string) post('password_default', '');
    if (strlen($passDefault) < PASSWORD_MIN_LENGTH) {
        $passDefault = '12345678';
    }

    $hasilImpor = impor_proses(impor_urai($berkas['isi']), [
        'tahun_ajaran'     => $tahun,
        'password_default' => $passDefault,
        'buat_kelas'       => post('buat_kelas', '') === '1',
        'perbarui'         => post('perbarui', '') === '1',
    ]);

    audit_log(
        $adminId,
        'admin.impor',
        'info',
        'Impor: ' . $hasilImpor['dibuat'] . ' siswa dibuat, '
            . $hasilImpor['kelas_baru'] . ' kelas baru, '
            . $hasilImpor['diperbarui'] . ' diperbarui, '
            . $hasilImpor['dilewati'] . ' dilewati, '
            . $hasilImpor['gagal_total'] . ' gagal.'
    );
}

$r = $hasilImpor;

require __DIR__ . '/../templates/admin-head.php';
?>

<?php if ($hasilImpor !== null): ?>
<!-- ==================================================================
     HASIL IMPOR
     ================================================================== -->
<div class="card mb-2">
    <div class="card__head card__head--plain">
        <p class="card__title"><i class="fa-solid fa-flag-checkered"></i> Hasil impor</p>
        <p class="card__sub tiny muted">
            <?= (int) $r['dibuat'] ?> siswa dibuat &middot;
            <?= (int) $r['kelas_baru'] ?> kelas baru &middot;
            <?= (int) $r['diperbarui'] ?> diperbarui &middot;
            <?= (int) $r['dilewati'] ?> dilewati &middot;
            <?= (int) $r['gagal_total'] ?> gagal
        </p>
    </div>

    <div class="card__body">
        <div class="grid grid--kpi mb-2">
            <div class="kpi kpi--slim kpi--ok">
                <p class="kpi__label" style="margin:0">Siswa dibuat</p>
                <p class="kpi__value"><?= (int) $r['dibuat'] ?></p>
            </div>
            <div class="kpi kpi--slim kpi--info">
                <p class="kpi__label" style="margin:0">Kelas baru</p>
                <p class="kpi__value"><?= (int) $r['kelas_baru'] ?></p>
            </div>
            <div class="kpi kpi--slim kpi--muted">
                <p class="kpi__label" style="margin:0">Dilewati</p>
                <p class="kpi__value"><?= (int) $r['dilewati'] ?></p>
            </div>
            <div class="kpi kpi--slim kpi--<?= $r['gagal_total'] > 0 ? 'warn' : 'muted' ?>">
                <p class="kpi__label" style="margin:0">Gagal</p>
                <p class="kpi__value"><?= (int) $r['gagal_total'] ?></p>
            </div>
        </div>

        <?php if ($r['gagal'] !== []): ?>
            <p class="field__label mt-2">Baris yang gagal</p>
            <div class="tablewrap">
                <table class="table table--cards">
                    <thead><tr><th class="shrink">Baris</th><th>Alasan</th></tr></thead>
                    <tbody>
                        <?php foreach ($r['gagal'] as $g): ?>
                            <tr>
                                <td data-label="Baris" class="num-cell"><?= (int) $g['baris'] ?></td>
                                <td data-label="Alasan" class="small"><?= esc($g['pesan']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($r['gagal_total'] > count($r['gagal'])): ?>
                <p class="tiny muted mt-1">
                    Menampilkan <?= count($r['gagal']) ?> dari <?= (int) $r['gagal_total'] ?> baris gagal.
                </p>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($r['lewati'] !== []): ?>
            <p class="field__label mt-2">Baris yang dilewati</p>
            <div class="tablewrap">
                <table class="table table--cards">
                    <thead><tr><th class="shrink">Baris</th><th>Keterangan</th></tr></thead>
                    <tbody>
                        <?php foreach ($r['lewati'] as $g): ?>
                            <tr>
                                <td data-label="Baris" class="num-cell"><?= (int) $g['baris'] ?></td>
                                <td data-label="Keterangan" class="small"><?= esc($g['pesan']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <div class="row mt-2">
            <a class="btn btn--primary btn--sm" href="/admin/siswa.php">
                <i class="fa-solid fa-users"></i> Lihat siswa
            </a>
            <a class="btn btn--soft btn--sm" href="/admin/kelas.php">
                <i class="fa-solid fa-chalkboard-user"></i> Lihat kelas
            </a>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ==================================================================
     FORM UNGGAH + PANDUAN
     ================================================================== -->
<div class="grid grid--2">
    <div class="card">
        <div class="card__head card__head--plain">
            <p class="card__title"><i class="fa-solid fa-file-arrow-up"></i> Unggah berkas</p>
            <p class="card__sub tiny muted">Format CSV. Kolom dikenali dari baris judul, jadi urutan bebas.</p>
        </div>
        <div class="card__body">
            <form method="post" action="/admin/impor.php" enctype="multipart/form-data" class="form">
                <input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">

                <label class="unggah" for="sBerkas" id="kotakUnggah">
                    <i class="fa-solid fa-cloud-arrow-up unggah__ic"></i>
                    <p class="unggah__title">Pilih berkas CSV</p>
                    <p class="unggah__hint">atau tarik berkas ke sini &middot; maksimal 2 MB</p>
                    <p class="unggah__file" id="unggahNama" hidden></p>
                    <input type="file" id="sBerkas" name="berkas" accept=".csv,.txt,text/csv" required>
                </label>

                <div class="form--grid">
                    <div class="field">
                        <label class="field__label" for="sTahun">Tahun ajaran</label>
                        <input type="text" id="sTahun" name="tahun_ajaran"
                               pattern="\d{4}/\d{4}" maxlength="9"
                               value="<?= esc(admin_default_tahun_ajaran()) ?>">
                        <p class="field__hint">Dipakai saat kelas dibuat otomatis.</p>
                    </div>
                    <div class="field">
                        <label class="field__label" for="sPass">Password default</label>
                        <input type="text" id="sPass" name="password_default"
                               maxlength="72" value="12345678">
                        <p class="field__hint">Untuk baris yang kolom password-nya kosong.</p>
                    </div>
                </div>

                <div class="switchrow">
                    <div class="switchrow__text">
                        <p class="switchrow__title">Buat kelas otomatis</p>
                        <p class="switchrow__hint">Kelas yang belum ada dibuat dari kolom <code>kelas</code> (tingkat ditebak dari nama kalau kolom tingkat kosong).</p>
                    </div>
                    <label class="switch">
                        <input type="checkbox" name="buat_kelas" value="1" checked>
                        <span class="switch__track"></span>
                    </label>
                </div>

                <div class="switchrow">
                    <div class="switchrow__text">
                        <p class="switchrow__title">Perbarui yang sudah ada</p>
                        <p class="switchrow__hint">Kalau mati, baris dengan username yang sudah dipakai akan dilewati, bukan ditimpa.</p>
                    </div>
                    <label class="switch">
                        <input type="checkbox" name="perbarui" value="1">
                        <span class="switch__track"></span>
                    </label>
                </div>

                <button type="submit" class="btn btn--primary">
                    <i class="fa-solid fa-file-import"></i> Mulai Impor
                </button>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card__head card__head--plain">
            <p class="card__title"><i class="fa-solid fa-circle-info"></i> Panduan kolom</p>
            <p class="card__sub tiny muted">Satu baris = satu siswa; kelas ikut dibuat dari baris itu.</p>
        </div>
        <div class="card__body">
            <a class="btn btn--soft btn--sm mb-1" href="/admin/impor.php?template=1">
                <i class="fa-solid fa-download"></i> Unduh template
            </a>

            <div class="note note--flat mb-1">
                <i class="fa-solid fa-wand-magic-sparkles note__ic"></i>
                <div class="note__body">
                    Kolom <strong>nama_lengkap</strong> wajib. Kolom <strong>kelas</strong> cukup nama
                    (mis. "X IPA 1"); kalau kelas belum ada, otomatis dibuat. Kolom lain boleh dikosongkan.
                </div>
            </div>

            <div class="tablewrap">
                <table class="table">
                    <thead><tr><th>Kolom</th><th>Wajib</th><th>Keterangan</th></tr></thead>
                    <tbody>
                        <tr><td><code>nama_lengkap</code></td><td><span class="badge badge--danger"><i class="dot"></i>Ya</span></td><td class="small">Nama siswa.</td></tr>
                        <tr><td><code>kelas</code></td><td><span class="badge badge--muted"><i class="dot"></i>-</span></td><td class="small">Nama kelas, mis. "X IPA 1". Dibuat otomatis bila belum ada.</td></tr>
                        <tr><td><code>username</code></td><td><span class="badge badge--muted"><i class="dot"></i>-</span></td><td class="small">Untuk login. Kosong &rarr; dipakai NIS.</td></tr>
                        <tr><td><code>password</code></td><td><span class="badge badge--muted"><i class="dot"></i>-</span></td><td class="small">Kosong &rarr; pakai password default.</td></tr>
                        <tr><td><code>tingkat</code></td><td><span class="badge badge--muted"><i class="dot"></i>-</span></td><td class="small">Mis. 10 / 11 / 12. Untuk kelas baru.</td></tr>
                        <tr><td><code>jurusan</code></td><td><span class="badge badge--muted"><i class="dot"></i>-</span></td><td class="small">Mis. IPA, RPL.</td></tr>
                        <tr><td><code>nis</code></td><td><span class="badge badge--muted"><i class="dot"></i>-</span></td><td class="small">Angka, unik. Bisa jadi username.</td></tr>
                        <tr><td><code>nisn</code></td><td><span class="badge badge--muted"><i class="dot"></i>-</span></td><td class="small">Angka, unik.</td></tr>
                        <tr><td><code>jenis_kelamin</code></td><td><span class="badge badge--muted"><i class="dot"></i>-</span></td><td class="small">L / P.</td></tr>
                        <tr><td><code>aktif</code></td><td><span class="badge badge--muted"><i class="dot"></i>-</span></td><td class="small">1 / 0. Kosong = aktif.</td></tr>
                    </tbody>
                </table>
            </div>

            <p class="field__label mt-2">Contoh isi CSV</p>
            <pre class="small" style="overflow:auto">nama_lengkap,username,password,kelas,tingkat,jurusan,nis,nisn,jenis_kelamin,aktif
Ahmad Fauzi,ahmad.fauzi,ahmad1234,X IPA 1,10,IPA,24001,0012345678,L,1
Siti Nurhaliza,siti.nur,siti1234,X IPA 1,10,IPA,24002,0012345679,P,1
Budi Santoso,budi.s,budi1234,XI RPL 1,11,RPL,23001,,L,1</pre>

            <div class="note note--warn mt-1">
                <i class="fa-solid fa-triangle-exclamation note__ic"></i>
                <div class="note__body">
                    Dari Excel: <strong>File &rarr; Save As &rarr; CSV</strong>. Berkas .xlsx
                    tidak bisa dibaca langsung.
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var input = document.getElementById('sBerkas');
    var kotak = document.getElementById('kotakUnggah');
    var nama  = document.getElementById('unggahNama');
    if (!input) { return; }
    input.addEventListener('change', function () {
        if (input.files && input.files.length) {
            if (nama) { nama.textContent = input.files[0].name; nama.hidden = false; }
            if (kotak) { kotak.classList.add('is-ada'); }
        }
    });
})();
</script>

<?php require __DIR__ . '/../templates/admin-foot.php';
