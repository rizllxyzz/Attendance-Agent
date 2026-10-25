<?php
/**
 * admin/kelas.php
 * ---------------------------------------------------------------------------
 * Manajemen kelas: menambah, mengubah, menonaktifkan, dan menghapus kelas.
 *
 * Kolom kelas sudah ada sejak awal (nama_kelas, tingkat, jurusan,
 * tahun_ajaran, wali_kelas, is_active) tapi belum pernah diisi kode, jadi
 * halaman ini tidak perlu migrasi apa pun.
 *
 * Dua hal yang dijaga supaya data kelas tidak rusak:
 *   1. Kelas yang masih punya siswa TIDAK bisa dihapus. Yang bisa adalah
 *      dinonaktifkan, supaya riwayat absensi lamanya tetap punya wali yang
 *      masih bisa dibaca.
 *   2. Nama kelas unik per tahun ajaran, bukan unik global: "X IPA 1" boleh
 *      dipakai lagi tahun depan.
 */

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_data.php';

$pageKey   = 'kelas';
$pageTitle = 'Kelas';
$pageSub   = 'Atur tingkatan, jurusan, wali kelas, dan tahun ajaran';

/* ==================================================================
 *  PROSES POST
 * ================================================================== */

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_hanya_post();

    /* Guru boleh membuka halaman ini untuk melihat data, jadi penjaga
     * di sini yang jadi penjaga sebenarnya. Tombolnya memang disembunyikan,
     * tapi POST tetap harus ditolak kalau role tidak cocok. */
    if (!$isAdmin) {
        admin_flash('error', 'Hanya administrator yang boleh mengubah data kelas.');
        admin_kembali();
    }

    $aksi = post('aksi', '');

    /* ---------- Simpan satu kelas ---------- */
    if ($aksi === 'simpan') {
        $id        = (int) post('id', 0);
        $nama      = post('nama_kelas', '');
        $tingkat   = post('tingkat', '');
        $jurusan   = post('jurusan', '');
        $nomor     = post('nomor', '');
        $tahun     = post('tahun_ajaran', '');
        $wali      = (int) post('wali_kelas', 0);
        $aktif     = post('is_active', '1') === '1' ? 1 : 0;

        $galat = [];

        /* Nama wajib selalu, termasuk saat mengubah. Kalau tidak, kelas
         * bisa tersimpan tanpa nama hanya dengan mengosongkan field. */
        if ($nama === '') {
            $galat['nama_kelas'] = 'Nama kelas wajib diisi.';
        }
        if ($nama !== '' && mb_strlen($nama) > 100) {
            $galat['nama_kelas'] = 'Nama kelas maksimal 100 karakter.';
        }
        if ($tingkat === '') {
            $galat['tingkat'] = 'Tingkat wajib dipilih.';
        }
        if (!preg_match('/^\d{4}\/\d{4}$/', $tahun)) {
            $galat['tahun_ajaran'] = 'Tahun ajaran harus ditulis format 2026/2027.';
        }
        if ($wali > 0) {
            $cekWali = db_one(
                "SELECT id FROM users WHERE id = ? AND role IN ('guru', 'admin') LIMIT 1",
                [$wali],
                null
            );
            if (!$cekWali) {
                $galat['wali_kelas'] = 'Wali kelas yang dipilih tidak ada atau bukan guru.';
            }
        }

        /* Kelas yang diubah harus benar-benar ada. Kalau tidak, UPDATE
         * diam-diam tidak mengubah apa pun dan pengguna mengira
         * simpan berhasil padahal tidak ada yang berubah. */
        if ($id > 0 && !admin_kelas($id)) {
            $galat['id'] = 'Kelas yang mau diubah tidak ditemukan.';
        }

        /* Nama kelas ganda di tahun yang sama akan membuat rekap per kelas
         * ambigu. Dicek di sini, bukan dengan UNIQUE di database, supaya
         * pesan errornya bisa spesifik. */
        if ($galat === [] && $nama !== '') {
            $cekKembar = db_one(
                'SELECT id FROM kelas WHERE nama_kelas = ? AND tahun_ajaran = ? AND id <> ? LIMIT 1',
                [$nama, $tahun, $id],
                null
            );
            if ($cekKembar) {
                $galat['nama_kelas'] = 'Kelas dengan nama itu sudah ada pada tahun ajaran ' . $tahun . '.';
            }
        }

        if ($galat !== []) {
            $_SESSION['admin_form_kelas'] = [
                'id' => $id, 'nama_kelas' => $nama, 'tingkat' => $tingkat,
                'jurusan' => $jurusan, 'nomor' => $nomor, 'tahun_ajaran' => $tahun,
                'wali_kelas' => $wali, 'is_active' => $aktif, 'galat' => $galat,
            ];
            admin_flash('error', 'Periksa kembali isian kelas.');
            /* Kembalikan ke drawer yang sama supaya nilai yang diketik
             * masih terlihat di depan mata, bukan hilang begitu saja.
             * Parameter lewat query, bukan anchor: browser tidak pernah
             * membaca ?edit= yang ditulis setelah tanda pagar. */
            admin_kembali_query($id > 0 ? ['edit' => $id] : ['aksi' => 'baru']);
        }

        if ($id > 0) {
            db_exec(
                'UPDATE kelas
                    SET nama_kelas = ?, tingkat = ?, jurusan = ?, tahun_ajaran = ?,
                        wali_kelas = ?, is_active = ?
                  WHERE id = ?',
                [$nama, $tingkat, $jurusan !== '' ? $jurusan : null, $tahun,
                 $wali > 0 ? $wali : null, $aktif, $id]
            );
            audit_log($adminId, 'admin.kelas.ubah', 'info', 'Kelas #' . $id . ' diubah: ' . $nama);
            admin_flash('ok', 'Kelas "' . $nama . '" berhasil diperbarui.');
        } else {
            $idBaru = db_insert(
                'INSERT INTO kelas (nama_kelas, tingkat, jurusan, tahun_ajaran, wali_kelas, is_active)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [$nama, $tingkat, $jurusan !== '' ? $jurusan : null, $tahun,
                 $wali > 0 ? $wali : null, $aktif]
            );
            audit_log($adminId, 'admin.kelas.tambah', 'info', 'Kelas #' . $idBaru . ' dibuat: ' . $nama);
            admin_flash('ok', 'Kelas "' . $nama . '" berhasil dibuat.');
        }

        unset($_SESSION['admin_form_kelas']);
        admin_kembali();
    }

    /* ---------- Tambah banyak kelas sekaligus ---------- */
    if ($aksi === 'bulk') {
        $tingkatMulai = (int) post('tingkat_mulai', 1);
        $tingkatAkhir = (int) post('tingkat_akhir', 12);
        $jumlahKelas  = (int) post('jumlah_kelas', 1);
        $jurusan      = post('jurusan', '');
        $tahun        = post('tahun_ajaran', '');
        $wali         = (int) post('wali_kelas', 0);
        $pakaiNomor   = post('pakai_nomor', '1') === '1';

        $tingkatMulai = max(1, min(12, $tingkatMulai));
        $tingkatAkhir  = max(1, min(12, $tingkatAkhir));
        if ($tingkatAkhir < $tingkatMulai) {
            [$tingkatMulai, $tingkatAkhir] = [$tingkatAkhir, $tingkatMulai];
        }
        $jumlahKelas = max(1, min(20, $jumlahKelas));

        $galat = [];
        if (!preg_match('/^\d{4}\/\d{4}$/', $tahun)) {
            $galat[] = 'Tahun ajaran harus ditulis format 2026/2027.';
        }
        if ($wali > 0) {
            $cekWali = db_one(
                "SELECT id FROM users WHERE id = ? AND role IN ('guru', 'admin') LIMIT 1",
                [$wali],
                null
            );
            if (!$cekWali) {
                $galat[] = 'Wali kelas yang dipilih tidak valid.';
            }
        }
        if ($galat !== []) {
            admin_flash('error', implode(' ', $galat));
            admin_kembali();
        }

        /* Kumpulkan dulu, baru tulis sekaligus, supaya satu kelas
         * yang bentrok tidak membuat kelas lain hilang. */
        $namaBaru = [];        $lolos    = [];
        $lewati   = [];
        for ($t = $tingkatMulai; $t <= $tingkatAkhir; $t++) {
            $jumlah = $pakaiNomor ? $jumlahKelas : 1;
            for ($i = 1; $i <= $jumlah; $i++) {
                $nama = admin_rakit_nama_kelas((string) $t, $jurusan, $pakaiNomor ? (string) $i : '');
                $ada = db_one(
                    'SELECT id FROM kelas WHERE nama_kelas = ? AND tahun_ajaran = ? LIMIT 1',
                    [$nama, $tahun],
                    null
                );
                if ($ada) {
                    $lewati[] = $nama;
                    continue;
                }
                $namaBaru[] = [$nama, (string) $t, $jurusan !== '' ? $jurusan : null, $tahun,
                               $wali > 0 ? $wali : null, 1];
                $lolos[]   = $nama;
            }
        }

        if ($namaBaru !== []) {
            db()->beginTransaction();
            try {
                foreach ($namaBaru as $row) {
                    db_exec(
                        'INSERT INTO kelas (nama_kelas, tingkat, jurusan, tahun_ajaran, wali_kelas, is_active)
                         VALUES (?, ?, ?, ?, ?, ?)',
                        $row
                    );
                }
                db()->commit();
            } catch (Throwable $e) {
                db()->rollBack();
                error_log('[ADMIN] Gagal tambah kelas massal: ' . $e->getMessage());
                admin_flash('error', 'Kelas gagal dibuat. Tidak ada kelas yang tersimpan.');
                admin_kembali();
            }
            audit_log($adminId, 'admin.kelas.massal', 'info',
                count($lolos) . ' kelas dibuat untuk tahun ajaran ' . $tahun);
        }

        $pesan = count($lolos) . ' kelas dibuat';
        if ($lewati !== []) {
            $pesan .= ', ' . count($lewati) . ' dilewati karena nama sudah dipakai';
        }
        admin_flash($lolos !== [] ? 'ok' : 'warn', $pesan . '.');
        admin_kembali();
    }

    /* ---------- Aktif / nonaktif ---------- */
    if ($aksi === 'status') {
        $idBaru = (int) post('id', 0);
        $kelas  = admin_kelas($idBaru);
        if (!$kelas) {
            admin_flash('error', 'Kelas tidak ditemukan.');
            admin_kembali();
        }
        $baru = (int) $kelas['is_active'] === 1 ? 0 : 1;
        db_exec('UPDATE kelas SET is_active = ? WHERE id = ?', [$baru, $idBaru]);
        audit_log($adminId, 'admin.kelas.status', 'info',
            'Kelas "' . $kelas['nama_kelas'] . '" ' . ($baru === 1 ? 'diaktifkan' : 'dinonaktifkan'));
        admin_flash('ok', 'Kelas "' . $kelas['nama_kelas'] . '" berhasil '
            . ($baru === 1 ? 'diaktifkan.' : 'dinonaktifkan.'));
        admin_kembali();
    }

    /* ---------- Hapus ---------- */
    if ($aksi === 'hapus') {
        $idHapus = (int) post('id', 0);
        $kelas   = admin_kelas($idHapus);
        if (!$kelas) {
            admin_flash('error', 'Kelas tidak ditemukan.');
            admin_kembali();
        }

        $jumlahSiswa = (int) db_value(
            "SELECT COUNT(*) FROM users WHERE kelas_id = ? AND role = 'siswa'",
            [$idHapus],
            0
        );
        if ($jumlahSiswa > 0) {
            admin_flash('error', 'Kelas "' . $kelas['nama_kelas'] . '" masih ada ' . $jumlahSiswa
                . ' siswa. Pindahkan siswa dulu, lalu hapus kelasnya.');
            admin_kembali();
        }

        db_exec('DELETE FROM kelas WHERE id = ?', [$idHapus]);
        audit_log($adminId, 'admin.kelas.hapus', 'warning',
            'Kelas #' . $idHapus . ' dihapus: ' . $kelas['nama_kelas']);
        admin_flash('ok', 'Kelas "' . $kelas['nama_kelas'] . '" berhasil dihapus.');
        admin_kembali();
    }
}

/* ==================================================================
 *  QUERY & TAMPILAN
 * ================================================================== */

$tahunPilih = get('tahun', '');
$statusPilih = get('status', 'aktif');
$cari        = get('cari', '');

/* Daftar tahun ajaran: gabungan yang sudah dipakai di database dan lima
 * tahun sekitar sekarang, supaya tahun yang baru bisa langsung dipilih. */
$tahunTersedia = admin_daftar_tahun_ajaran();
foreach (admin_daftar_kelas(false) as $k) {
    $t = (string) $k['tahun_ajaran'];
    if ($t !== '' && !in_array($t, $tahunTersedia, true)) {
        $tahunTersedia[] = $t;
    }
}
rsort($tahunTersedia);

if ($tahunPilih === '' || !in_array($tahunPilih, $tahunTersedia, true)) {
    $tahunPilih = admin_default_tahun_ajaran();
}

if (!in_array($statusPilih, ['aktif', 'nonaktif', 'semua'], true)) {
    $statusPilih = 'aktif';
}

/* Query hanya bisa memfilter "aktif", jadi "nonaktif" disaring di PHP.
 * Kalau diteruskan sebagai "semua", tab Nonaktif akan ikut menampilkan
 * kelas aktif dan pemakainya mengira filternya tidak bekerja. */
$daftar = admin_daftar_kelas($statusPilih !== 'nonaktif', $tahunPilih);
if ($statusPilih === 'nonaktif') {
    $daftar = array_values(array_filter($daftar, static fn($k) => (int) $k['is_active'] === 0));
}

$totalKelas    = count(admin_daftar_kelas(false, $tahunPilih));
$kelasAktif    = count(array_filter($daftar, static fn($k) => (int) $k['is_active'] === 1));
$totalSiswa    = array_sum(array_map(static fn($k) => (int) $k['jumlah_siswa'], $daftar));
$tanpaWali     = count(array_filter($daftar, static fn($k) => (int) ($k['wali_kelas'] ?? 0) === 0));
$waliTersedia  = admin_daftar_wali();

/* Nilai form terakhir yang gagal disimpan, supaya tidak perlu mengetik ulang. */
$formulir = $_SESSION['admin_form_kelas'] ?? null;
unset($_SESSION['admin_form_kelas']);

$idEdit = (int) get('edit', 0);
$edit   = $idEdit > 0 ? admin_kelas($idEdit) : null;
if ($idEdit > 0 && !$edit) {
    admin_flash('warn', 'Kelas yang mau diubah tidak ditemukan.');
    admin_kembali('daftar');
}

$pageActionsHtml = ($isAdmin
        ? '<a class="btn btn--ghost" href="/admin/impor.php">'
            . '<i class="fa-solid fa-file-import"></i> Impor CSV</a>'
        : '')
    . '<button type="button" class="btn btn--ghost" data-buka="drawerBulk">'
    . '<i class="fa-solid fa-layer-group"></i> Tambah Massal</button>'
    . '<button type="button" class="btn btn--primary" data-buka="drawerBaru">'
    . '<i class="fa-solid fa-plus"></i> Tambah Kelas</button>';

$fabHref  = '';
$fabLabel = 'Kelola Kelas';

require __DIR__ . '/../templates/admin-head.php';
?>

<!-- ==================================================================
     RINGKASAN
     ================================================================== -->
<div class="grid grid--kpi mb-2">
    <div class="kpi kpi--slim kpi--brand">
        <p class="kpi__label" style="margin:0">Kelas tahun <?= esc($tahunPilih) ?></p>
        <p class="kpi__value"><?= $totalKelas ?></p>
        <p class="kpi__hint"><?= $kelasAktif ?> aktif, <?= $totalKelas - $kelasAktif ?> nonaktif</p>
    </div>
    <div class="kpi kpi--slim kpi--info">
        <p class="kpi__label" style="margin:0">Siswa terdaftar</p>
        <p class="kpi__value"><?= $totalSiswa ?></p>
        <p class="kpi__hint">dari kelas yang ditampilkan</p>
    </div>
    <div class="kpi kpi--slim kpi--ok">
        <p class="kpi__label" style="margin:0">Rata-rata per kelas</p>
        <p class="kpi__value"><?= $totalKelas > 0 ? round($totalSiswa / $totalKelas, 1) : 0 ?></p>
        <p class="kpi__hint">siswa</p>
    </div>
    <div class="kpi kpi--slim kpi--<?= $tanpaWali > 0 ? 'warn' : 'muted' ?>">
        <p class="kpi__label" style="margin:0">Belum ada wali</p>
        <p class="kpi__value"><?= $tanpaWali ?></p>
        <p class="kpi__hint"><?= $tanpaWali > 0 ? 'perlu diisi' : 'semuanya sudah ada' ?></p>
    </div>
</div>

<?php if ($tanpaWali > 0): ?>
<div class="note note--warn mb-2">
    <i class="fa-solid fa-triangle-exclamation note__ic"></i>
    <div class="note__body">
        <span class="note__title"><?= $tanpaWali ?> kelas belum punya wali.</span>
        Wali kelas dipakai saat menampilkan kontak di halaman absensi dan izin,
        jadi kelas tanpa wali akan tampil sebagai "-".
    </div>
</div>
<?php endif; ?>

<!-- ==================================================================
     DAFTAR KELAS
     ================================================================== -->
<div class="card" id="daftar">

    <form class="filterbar" method="get" action="/admin/kelas.php">
        <div class="field" style="min-width:150px">
            <label class="field__label" for="fTahun">Tahun ajaran</label>
            <select id="fTahun" name="tahun" onchange="this.form.submit()">
                <?php foreach ($tahunTersedia as $t): ?>
                    <option value="<?= esc($t) ?>"<?= $t === $tahunPilih ? ' selected' : '' ?>>
                        <?= esc($t) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field" style="min-width:140px">
            <label class="field__label" for="fStatus">Status</label>
            <select id="fStatus" name="status" onchange="this.form.submit()">
                <option value="aktif"   <?= $statusPilih === 'aktif' ? ' selected' : '' ?>>Aktif</option>
                <option value="semua"   <?= $statusPilih === 'semua' ? ' selected' : '' ?>>Semua</option>
                <option value="nonaktif"<?= $statusPilih === 'nonaktif' ? ' selected' : '' ?>>Nonaktif</option>
            </select>
        </div>

        <div class="field field--grow">
            <label class="field__label" for="fCari">Cari kelas</label>
            <div class="search">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="search" id="fCari" name="cari" value="<?= esc($cari) ?>"
                       placeholder="Nama kelas, tingkat, atau jurusan">
            </div>
        </div>

        <button type="submit" class="btn btn--soft btn--sm">
            <i class="fa-solid fa-filter"></i> Terapkan
        </button>
        <?php if ($cari !== ''): ?>
            <a class="btn btn--ghost btn--sm" href="<?= esc(admin_ubah_query(['cari' => ''])) ?>">
                <i class="fa-solid fa-xmark"></i> Bersihkan
            </a>
        <?php endif; ?>
    </form>

    <?php
    /* Pencarian kelas cukup di dalam daftar yang sudah difilter tahun
       ajaran, jadi tidak perlu query kedua. */
    $tampil = $daftar;
    if ($cari !== '') {
        $needle = mb_strtolower($cari);
        $tampil = array_values(array_filter($daftar, static function ($k) use ($needle) {
            $gabung = mb_strtolower(implode(' ', [
                (string) $k['nama_kelas'], (string) $k['tingkat'],
                (string) ($k['jurusan'] ?? ''), (string) $k['wali_nama'],
            ]));
            return mb_strpos($gabung, $needle) !== false;
        }));
    }
    ?>

    <?php if ($tampil === []): ?>
        <div class="empty">
            <span class="empty__ic"><i class="fa-solid fa-chalkboard-user"></i></span>
            <p class="empty__title">Belum ada kelas</p>
            <p class="empty__text">
                Tahun ajaran <?= esc($tahunPilih) ?> belum punya kelas.
                Buat satu per satu, atau pakai Tambah Massal untuk langsung
                membuat kelas tingkat 1 sampai 12 sekaligus.
            </p>
            <div class="row" style="justify-content:center">
                <button type="button" class="btn btn--ghost btn--sm" data-buka="drawerBulk">
                    <i class="fa-solid fa-layer-group"></i> Tambah Massal
                </button>
                <button type="button" class="btn btn--primary btn--sm" data-buka="drawerBaru">
                    <i class="fa-solid fa-plus"></i> Tambah Kelas
                </button>
            </div>
        </div>
    <?php else: ?>
        <div class="tablewrap">
            <table class="table table--cards">
                <thead>
                    <tr>
                        <th>Kelas</th>
                        <th>Tingkat</th>
                        <th>Jurusan</th>
                        <th>Wali Kelas</th>
                        <th class="num-cell">Siswa</th>
                        <th>Status</th>
                        <th class="actions-cell">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($tampil as $k): ?>
                    <?php $aktif = (int) $k['is_active'] === 1; ?>
                    <tr>
                        <td data-label="Kelas">
                            <div class="celluser">
                                <span class="avatar avatar--sm avatar--plain<?= $aktif ? '' : ' muted' ?>">
                                    <i class="fa-solid fa-chalkboard-user"></i>
                                </span>
                                <span class="celluser__text">
                                    <span class="celluser__name"><?= esc($k['nama_kelas']) ?></span>
                                    <span class="celluser__meta celluser__meta--plain">
                                        tahun <?= esc($k['tahun_ajaran']) ?>
                                    </span>
                                </span>
                            </div>
                        </td>
                        <td data-label="Tingkat">
                            <span class="badge badge--muted"><?= esc($k['tingkat']) ?></span>
                        </td>
                        <td data-label="Jurusan"><?= esc($k['jurusan'] ?? '-') ?></td>
                        <td data-label="Wali">
                            <?php if ((int) ($k['wali_kelas'] ?? 0) > 0): ?>
                                <span class="small"><?= esc($k['wali_nama'] ?? '-') ?></span>
                            <?php else: ?>
                                <span class="badge badge--warn"><i class="dot"></i>Belum ada</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Siswa" class="num-cell">
                            <a href="/admin/siswa.php?kelas=<?= (int) $k['id'] ?>">
                                <?= (int) $k['jumlah_siswa'] ?>
                            </a>
                        </td>
                        <td data-label="Status">
                            <?= $aktif
                                ? '<span class="badge badge--ok"><i class="dot"></i>Aktif</span>'
                                : '<span class="badge badge--muted"><i class="dot"></i>Nonaktif</span>' ?>
                        </td>
                        <td data-label="" class="actions-cell">
                            <div class="row row--end" style="gap:6px;flex-wrap:nowrap">
                                <a class="iconbtn" href="/admin/kelas.php?edit=<?= (int) $k['id'] ?>"
                                   title="Ubah kelas" aria-label="Ubah kelas <?= esc($k['nama_kelas']) ?>">
                                    <i class="fa-solid fa-pen"></i>
                                </a>
                                <?php if ($isAdmin): ?>
                                    <form method="post" action="/admin/kelas.php" style="display:contents">
                                        <input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">
                                        <input type="hidden" name="aksi" value="status">
                                        <input type="hidden" name="id" value="<?= (int) $k['id'] ?>">
                                        <button type="submit" class="iconbtn"
                                                title="<?= $aktif ? 'Nonaktifkan' : 'Aktifkan' ?>"
                                                aria-label="<?= $aktif ? 'Nonaktifkan' : 'Aktifkan' ?> kelas <?= esc($k['nama_kelas']) ?>">
                                            <i class="fa-solid <?= $aktif ? 'fa-pause' : 'fa-play' ?>"></i>
                                        </button>
                                    </form>
                                    <form method="post" action="/admin/kelas.php" style="display:contents"
                                          onsubmit="return konfirmasiHapusKelas(event, <?= (int) $k['jumlah_siswa'] ?>, <?= (int) $k['id'] ?>);">
                                        <input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">
                                        <input type="hidden" name="aksi" value="hapus">
                                        <input type="hidden" name="id" value="<?= (int) $k['id'] ?>">
                                        <button type="submit" class="iconbtn iconbtn--danger"
                                                title="Hapus kelas" aria-label="Hapus kelas <?= esc($k['nama_kelas']) ?>">
                                            <i class="fa-solid fa-trash-can"></i>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="card__foot">
            Menampilkan <strong><?= count($tampil) ?></strong> dari
            <strong><?= count($daftar) ?></strong> kelas pada tahun ajaran
            <?= esc($tahunPilih) ?>.
            <?php if ($cari !== ''): ?>
                Filter pencarian: &ldquo;<strong><?= esc($cari) ?></strong>&rdquo;.
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<?php if (!$isAdmin): ?>
<div class="note note--flat mt-2">
    <i class="fa-solid fa-lock note__ic"></i>
    <div class="note__body">
        Kamu masuk sebagai <strong><?= esc($peranLabel) ?></strong>, jadi kelas bisa
        dilihat tapi tidak bisa ditambah, diubah, atau dihapus. Minta admin
        sekolah kalau ada kelas yang perlu diperbaiki.
    </div>
</div>
<?php endif; ?>

<!-- ==================================================================
     DRAWER: TAMBAH / UBAH KELAS
     ================================================================== -->
<div class="drawer" id="drawerBaru" role="dialog" aria-modal="true" aria-label="Form kelas"
     data-autoload="<?= $edit ? '1' : '0' ?>" <?= $edit ? '' : 'hidden' ?>>
    <div class="drawer__scrim" data-tutup></div>
    <div class="drawer__panel">
        <div class="drawer__head">
            <div style="flex:1;min-width:0">
                <p class="drawer__title"><?= $edit ? 'Ubah Kelas' : 'Tambah Kelas' ?></p>
                <p class="drawer__sub">
                    <?= $edit
                        ? esc($edit['nama_kelas'])
                        : 'Satu kelas. Untuk banyak kelas sekaligus pakai Tambah Massal.' ?>
                </p>
            </div>
            <button type="button" class="iconbtn" data-tutup aria-label="Tutup">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="post" action="/admin/kelas.php" class="drawer__body">
            <input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">
            <input type="hidden" name="aksi" value="simpan">
            <input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">

            <?php
            $f = $formulir ?? [
                'id' => $edit['id'] ?? 0,
                'nama_kelas' => $edit['nama_kelas'] ?? '',
                'tingkat' => $edit['tingkat'] ?? '',
                'jurusan' => $edit['jurusan'] ?? '',
                'nomor' => '',
                'tahun_ajaran' => $edit['tahun_ajaran'] ?? admin_default_tahun_ajaran(),
                'wali_kelas' => $edit['wali_kelas'] ?? 0,
                'is_active' => $edit['is_active'] ?? 1,
                'galat' => [],
            ];
            $galat = $f['galat'] ?? [];
            ?>

            <div class="form">
                <div class="field<?= isset($galat['nama_kelas']) ? ' field--error' : '' ?>">
                    <label class="field__label" for="fNama">Nama kelas <span class="field__req">*</span></label>
                    <input type="text" id="fNama" name="nama_kelas" required
                           maxlength="100" value="<?= esc($f['nama_kelas']) ?>"
                           placeholder="Contoh: X IPA 1" autofocus>
                    <?php if (isset($galat['nama_kelas'])): ?>
                        <p class="field__err"><?= esc($galat['nama_kelas']) ?></p>
                    <?php else: ?>
                        <p class="field__hint">Tercetak di sertifikat dan laporan. Boleh diisi bebas.</p>
                    <?php endif; ?>
                </div>

                <div class="form--grid">
                    <div class="field<?= isset($galat['tingkat']) ? ' field--error' : '' ?>">
                        <label class="field__label" for="fTingkat">Tingkat <span class="field__req">*</span></label>
                        <select id="fTingkat" name="tingkat" required>
                            <option value="">Pilih tingkat</option>
                            <?php foreach (admin_daftar_tingkat() as $angka): ?>
                                <option value="<?= esc($angka) ?>"
                                    <?= (string) $f['tingkat'] === $angka ? ' selected' : '' ?>>
                                    <?= esc($angka) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (isset($galat['tingkat'])): ?>
                            <p class="field__err"><?= esc($galat['tingkat']) ?></p>
                        <?php endif; ?>
                    </div>

                    <div class="field<?= isset($galat['tahun_ajaran']) ? ' field--error' : '' ?>">
                        <label class="field__label" for="fTahunAjaran">Tahun ajaran <span class="field__req">*</span></label>
                        <input type="text" id="fTahunAjaran" name="tahun_ajaran" required
                               pattern="\d{4}/\d{4}" maxlength="9"
                               value="<?= esc($f['tahun_ajaran']) ?>" placeholder="2026/2027">
                        <?php if (isset($galat['tahun_ajaran'])): ?>
                            <p class="field__err"><?= esc($galat['tahun_ajaran']) ?></p>
                        <?php else: ?>
                            <p class="field__hint">Format: tahun/tahun, contoh 2026/2027.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="form--grid">
                    <div class="field">
                        <label class="field__label" for="fJurusan">Jurusan</label>
                        <input type="text" id="fJurusan" name="jurusan" list="dlJurusan" maxlength="60"
                               value="<?= esc($f['jurusan']) ?>" placeholder="Contoh: IPA, RPL, Akuntansi">
                        <datalist id="dlJurusan">
                            <?php foreach (admin_saran_jurusan() as $s): ?>
                                <option value="<?= esc($s) ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                        <p class="field__hint">Kosongkan kalau kelas tidak punya jurusan (mis. kelas satu).</p>
                    </div>

                    <div class="field">
                        <label class="field__label" for="fNomor">Nomor urut</label>
                        <input type="text" id="fNomor" name="nomor" maxlength="10"
                               value="<?= esc($f['nomor']) ?>" placeholder="1">
                        <p class="field__hint">
                            Dipakai untuk merakit nama kelas dari tingkat + jurusan + nomor.
                            Nama kelas di atas yang sudah diisi tetap yang tersimpan.
                        </p>
                    </div>
                </div>

                <div class="field<?= isset($galat['wali_kelas']) ? ' field--error' : '' ?>">
                    <label class="field__label" for="fWali">Wali kelas</label>
                    <select id="fWali" name="wali_kelas">
                        <option value="0">Belum ditentukan</option>
                        <?php foreach ($waliTersedia as $w): ?>
                            <option value="<?= (int) $w['id'] ?>"
                                <?= (int) $f['wali_kelas'] === (int) $w['id'] ? ' selected' : '' ?>>
                                <?= esc($w['nama_lengkap']) ?> (<?= esc($w['username']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (isset($galat['wali_kelas'])): ?>
                        <p class="field__err"><?= esc($galat['wali_kelas']) ?></p>
                    <?php else: ?>
                        <p class="field__hint">Boleh kosong. Yang bisa dipilih hanya akun guru dan admin aktif.</p>
                    <?php endif; ?>
                </div>

                <div class="switchrow">
                    <div class="switchrow__text">
                        <p class="switchrow__title">Kelas aktif</p>
                        <p class="switchrow__hint">
                            Kelas nonaktif tidak muncul lagi saat menambah atau memindahkan siswa,
                            tapi riwayat absensinya tetap tersimpan.
                        </p>
                    </div>
                    <label class="switch">
                        <input type="checkbox" name="is_active" value="1"
                            <?= (int) $f['is_active'] === 1 ? 'checked' : '' ?>>
                        <span class="switch__track"></span>
                    </label>
                </div>
            </div>

            <div class="drawer__foot">
                <button type="button" class="btn btn--ghost" data-tutup>Batal</button>
                <button type="submit" class="btn btn--primary">
                    <i class="fa-solid fa-check"></i>
                    <?= $edit ? 'Simpan Perubahan' : 'Tambah Kelas' ?>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ==================================================================
     DRAWER: TAMBAH MASSAL
     ================================================================== -->
<div class="drawer" id="drawerBulk" role="dialog" aria-modal="true" aria-label="Tambah kelas massal" hidden>
    <div class="drawer__scrim" data-tutup></div>
    <div class="drawer__panel">
        <div class="drawer__head">
            <div style="flex:1;min-width:0">
                <p class="drawer__title">Tambah Massal</p>
                <p class="drawer__sub">Buat banyak kelas sekaligus untuk satu tahun ajaran</p>
            </div>
            <button type="button" class="iconbtn" data-tutup aria-label="Tutup">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="post" action="/admin/kelas.php" class="drawer__body">
            <input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">
            <input type="hidden" name="aksi" value="bulk">

            <div class="form">
                <div class="form--grid">
                    <div class="field">
                        <label class="field__label" for="bMulai">Dari tingkat</label>
                        <select id="bMulai" name="tingkat_mulai">
                            <?php foreach (admin_daftar_tingkat() as $angka): ?>
                                <option value="<?= esc($angka) ?>"<?= $angka === '10' ? ' selected' : '' ?>>
                                    <?= esc($angka) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="field__hint">Tingkat terkecil yang dibuat.</p>
                    </div>

                    <div class="field">
                        <label class="field__label" for="bAkhir">Sampai tingkat</label>
                        <select id="bAkhir" name="tingkat_akhir">
                            <?php foreach (admin_daftar_tingkat() as $angka): ?>
                                <option value="<?= esc($angka) ?>"<?= $angka === '12' ? ' selected' : '' ?>>
                                    <?= esc($angka) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="field__hint">Tingkat terbesar yang dibuat.</p>
                    </div>
                </div>

                <div class="field">
                    <label class="field__label" for="fJurusanBulk">Jurusan</label>
                    <input type="text" id="fJurusanBulk" name="jurusan" list="dlJurusanBulk" maxlength="60"
                           placeholder="Contoh: IPA">
                    <datalist id="dlJurusanBulk">
                        <?php foreach (admin_saran_jurusan() as $s): ?>
                            <option value="<?= esc($s) ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                    <p class="field__hint">
                        Jurusan yang sama dipakai untuk semua tingkat yang dipilih.
                        Kosongkan untuk kelas satu tanpa jurusan.
                    </p>
                </div>

                <div class="form--grid">
                    <div class="field">
                        <label class="field__label" for="fTahunBulk">Tahun ajaran <span class="field__req">*</span></label>
                        <input type="text" id="fTahunBulk" name="tahun_ajaran" required
                               pattern="\d{4}/\d{4}" maxlength="9"
                               value="<?= esc(admin_default_tahun_ajaran()) ?>" placeholder="2026/2027">
                    </div>

                    <div class="field">
                        <label class="field__label" for="bJumlah">Jumlah kelas per tingkat</label>
                        <input type="number" id="bJumlah" name="jumlah_kelas" min="1" max="20" value="2">
                        <p class="field__hint">1 sampai 20. Dipakai kalau penomoran di bawah aktif.</p>
                    </div>
                </div>

                <div class="choices">
                    <label class="choice">
                        <input type="radio" name="pakai_nomor" value="1" checked>
                        <span class="choice__box">
                            <i class="fa-solid fa-hashtag"></i> Pakai nomor
                        </span>
                    </label>
                    <label class="choice">
                        <input type="radio" name="pakai_nomor" value="0">
                        <span class="choice__box">
                            <i class="fa-solid fa-ban"></i> Tanpa nomor
                        </span>
                    </label>
                </div>

                <div class="field">
                    <label class="field__label" for="bWali">Wali kelas</label>
                    <select id="bWali" name="wali_kelas">
                        <option value="0">Belum ditentukan</option>
                        <?php foreach ($waliTersedia as $w): ?>
                            <option value="<?= (int) $w['id'] ?>"><?= esc($w['nama_lengkap']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="field__hint">Wali yang sama dipasang ke semua kelas yang dibuat.</p>
                </div>

                <div class="note note--flat">
                    <i class="fa-solid fa-circle-info note__ic"></i>
                    <div class="note__body">
                        Nama kelas dirakit dari <strong>tingkat + jurusan + nomor</strong>.
                        Contoh: tingkat 10, jurusan IPA, 2 kelas, nomor aktif
                        menghasilkan <strong>10 IPA 1</strong> dan <strong>10 IPA 2</strong>.
                        <br>
                        Kelas yang namanya sudah dipakai di tahun ajaran ini
                        <strong>dilewati</strong>, bukan ditimpa.
                    </div>
                </div>

                <div class="card card--flat">
                    <div class="card__head card__head--plain">
                        <p class="card__title">Contoh hasil</p>
                    </div>
                    <div class="card__body card__body--tight">
                        <p class="small muted" id="bulkPratinjau">
                            Diperbarui sendiri saat isian berubah.
                        </p>
                    </div>
                </div>
            </div>

            <div class="drawer__foot">
                <button type="button" class="btn btn--ghost" data-tutup>Batal</button>
                <button type="submit" class="btn btn--primary">
                    <i class="fa-solid fa-layer-group"></i> Buat Kelas
                </button>
            </div>
        </form>
    </div>
</div>

<script>
/* Pratinjau nama kelas: bentuk yang sama persis dengan admin_rakit_nama_kelas()
   di PHP, supaya yang tampil sebelum menekan tombol memang yang akan disimpan. */
(function () {
    var form = document.getElementById('drawerBulk');
    if (!form) { return; }
    var mulai  = form.querySelector('#bMulai');
    var akhir  = form.querySelector('#bAkhir');
    var jurusan = form.querySelector('#fJurusanBulk');
    var jumlah = form.querySelector('#bJumlah');
    var nomor  = form.querySelectorAll('input[name=pakai_nomor]');
    var keluar = form.querySelector('#bulkPratinjau');
    if (!mulai || !keluar) { return; }

    function nilai() {
        return {
            a: parseInt(mulai.value, 10) || 1,
            b: parseInt(akhir.value, 10) || 12,
            j: cleanse(jurusan.value),
            n: parseInt(jumlah.value, 10) || 1,
            p: form.querySelector('input[name=pakai_nomor]:checked').value === '1'
        };
    }
    function cleanse(s) { return (s || '').trim().replace(/\s+/g, ' '); }
    function rakit(t, j, n) {
        return [t, j, n].filter(function (x) { return x !== ''; }).join(' ');
    }

    function gambar() {
        var v = nilai();
        if (v.b < v.a) { var t = v.a; v.a = v.b; v.b = t; }
        var contoh = [];
        var total = 0;
        for (var t = v.a; t <= v.b; t++) {
            var n = v.p ? v.n : 1;
            for (var i = 1; i <= n; i++) {
                total++;
                if (contoh.length < 6) {
                    contoh.push(rakit(String(t), v.j, v.p ? String(i) : ''));
                }
            }
        }
        keluar.textContent = 'Total ' + total + ' kelas. Contoh: ' + (contoh.join(', ') || '-')
            + (contoh.length < total ? ', ...' : '');
    }

    [mulai, akhir, jurusan, jumlah].forEach(function (el) {
        el.addEventListener('input', gambar);
        el.addEventListener('change', gambar);
    });
    nomor.forEach(function (el) { el.addEventListener('change', gambar); });
    gambar();
})();

/* Hapus kelas: dicek di sisi klien supaya tidak perlu konfirmasi kalau
   kelas masih punya siswa. Penjaga yang sesungguhnya tetap ada di PHP,
   karena nilai di form bisa diubah sendiri. */
function konfirmasiHapusKelas(ev, jumlahSiswa, idKelas) {
    if (jumlahSiswa > 0) {
        ev.preventDefault();
        beriToast('Kelas ini masih ada ' + jumlahSiswa + ' siswa. Pindahkan siswa dulu.', 'warn');
        return false;
    }
    var nama = event && event.submitter
        ? (event.submitter.closest('tr').querySelector('.celluser__name').textContent)
        : 'kelas ini';
    return window.confirm('Hapus ' + nama + '? Tindakan ini tidak bisa dibatalkan.');
}
</script>

<?php require __DIR__ . '/../templates/admin-foot.php'; 