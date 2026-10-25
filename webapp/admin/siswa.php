<?php
/**
 * admin/siswa.php
 * ---------------------------------------------------------------------------
 * Manajemen akun siswa: tambah, ubah, pindah kelas, nonaktifkan, atur ulang
 * password, dan hapus.
 *
 * Password memakai password_hash() dengan cost yang sama seperti
 * auth/login.php, supaya hash yang dibuat di sini langsung bisa dipakai
 * login dan tidak perlu langkah tambahan.
 *
 * Akun yang punya riwayat absensi atau wajah TIDAK bisa dihapus. Yang bisa
 * adalah dinonaktifkan: students yang salah masuk atau lulus tetap punya
 * riwayat yang harus bisa dibaca.
 */

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_data.php';

$pageKey   = 'siswa';
$pageTitle = 'Siswa';
$pageSub   = 'Kelola akun siswa dan penempatan kelas';

/* ==================================================================
 *  PROSES POST
 * ================================================================== */

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_hanya_post();

    if (!$isAdmin) {
        admin_flash('error', 'Hanya administrator yang boleh mengubah akun siswa.');
        admin_kembali();
    }

    $aksi = post('aksi', '');

    /* ---------- Tambah atau ubah akun ---------- */
    if ($aksi === 'simpan') {
        $id         = (int) post('id', 0);
        $username   = post('username', '');
        $nama       = post('nama_lengkap', '');
        $nis        = post('nis', '');
        $nisn       = post('nisn', '');
        $kelasId    = (int) post('kelas_id', 0);
        $jk         = post('jenis_kelamin', '');
        $aktif      = post('is_active', '1') === '1' ? 1 : 0;
        $password   = (string) ($_POST['password'] ?? '');
        $passwordU2 = (string) ($_POST['password_ulang'] ?? '');

        $galat = [];

        if ($username === '') {
            $galat['username'] = 'Username wajib diisi.';
        } elseif (!preg_match('/^[a-z0-9._-]{3,32}$/i', $username)) {
            $galat['username'] = 'Username 3-32 karakter, hanya huruf, angka, titik, garis, dan garis bawah.';
        }
        if ($nama === '') {
            $galat['nama_lengkap'] = 'Nama lengkap wajib diisi.';
        } elseif (mb_strlen($nama) > 100) {
            $galat['nama_lengkap'] = 'Nama maksimal 100 karakter.';
        }
        if ($nis !== '' && !preg_match('/^[0-9]{1,20}$/', $nis)) {
            $galat['nis'] = 'NIS hanya boleh berisi angka.';
        }
        if ($nisn !== '' && !preg_match('/^[0-9]{1,20}$/', $nisn)) {
            $galat['nisn'] = 'NISN hanya boleh berisi angka.';
        }
        if ($jk !== '' && !in_array($jk, ['L', 'P'], true)) {
            $galat['jenis_kelamin'] = 'Jenis kelamin tidak dikenal.';
        }

        /* Password wajib saat membuat, opsional saat mengubah. */
        if ($id === 0 && $password === '') {
            $galat['password'] = 'Password wajib diisi untuk akun baru.';
        }
        if ($password !== '') {
            if (strlen($password) < PASSWORD_MIN_LENGTH) {
                $galat['password'] = 'Password minimal ' . PASSWORD_MIN_LENGTH . ' karakter.';
            } elseif ($passwordU2 !== '' && $password !== $passwordU2) {
                /* Konfirmasi yang dikosongkan dikira "tidak diisi", bukan
                 * "tidak sama". Kalau tidak, satu pesan menyesatkan
                 * ("harus sama") muncul padahal yang dikosongkan tidak
                 * sengaja. Password yang diketik sendiri tetap dipakai
                 * apa adanya, jadi admin boleh menyimpan tanpa mengetik
                 * dua kali. */
                $galat['password'] = 'Ulangi password harus sama.';
            }
        }

        /* Username dan NIS harus unik di seluruh tabel, bukan hanya di
         * antara siswa: orang bisa saja memakai username yang sama dengan
         * akun guru, dan itu akan membingungkan saat mencari. */
        if (!isset($galat['username'])) {
            $adaUsername = db_one(
                'SELECT id FROM users WHERE LOWER(username) = LOWER(?) AND id <> ? LIMIT 1',
                [$username, $id],
                null
            );
            if ($adaUsername) {
                $galat['username'] = 'Username itu sudah dipakai akun lain.';
            }
        }
        if ($nis !== '') {
            $adaNis = db_one(
                "SELECT id FROM users WHERE nis = ? AND id <> ? LIMIT 1",
                [$nis, $id],
                null
            );
            if ($adaNis) {
                $galat['nis'] = 'NIS itu sudah dipakai akun lain.';
            }
        }
        if ($nisn !== '') {
            $adaNisn = db_one(
                'SELECT id FROM users WHERE nisn = ? AND id <> ? LIMIT 1',
                [$nisn, $id],
                null
            );
            if ($adaNisn) {
                $galat['nisn'] = 'NISN itu sudah dipakai akun lain.';
            }
        }

        /* Kelas yang dipilih harus ada dan aktif. Kelas nonaktif tidak
         * dipakai karena siswa yang masuk ke sana tidak akan muncul di
         * daftar absensi. */
        if ($kelasId > 0) {
            $cekKelas = db_one('SELECT id FROM kelas WHERE id = ? AND is_active = 1 LIMIT 1', [$kelasId], null);
            if (!$cekKelas) {
                $galat['kelas_id'] = 'Kelas yang dipilih tidak ada atau sedang nonaktif.';
            }
        }

        if ($galat !== []) {
            $_SESSION['admin_form_siswa'] = [
                'id' => $id, 'username' => $username, 'nama_lengkap' => $nama,
                'nis' => $nis, 'nisn' => $nisn, 'kelas_id' => $kelasId,
                'jenis_kelamin' => $jk, 'is_active' => $aktif, 'galat' => $galat,
            ];
            admin_flash('error', 'Periksa kembali isian akun.');
            /* Kembali ke form yang sama, bukan ke daftar kosong: isian yang
             * sudah diketik disimpan di session supaya tidak hilang, jadi
             * membuka daftar saja akan membuang kerja pengguna. Parameter
             * dikirim lewat query, bukan anchor: browser tidak pernah
             * membaca ?edit= yang ditulis setelah tanda pagar. */
            admin_kembali_query($id > 0 ? ['edit' => $id] : ['aksi' => 'baru']);
        }

        $kelasSql = $kelasId > 0 ? $kelasId : null;

        if ($id > 0) {
            $lama = admin_siswa($id);
            if (!$lama) {
                admin_flash('error', 'Akun siswa tidak ditemukan.');
                admin_kembali();
            }

            if ($password !== '') {
                db_exec(
                    'UPDATE users
                        SET username = ?, nama_lengkap = ?, nis = ?, nisn = ?, kelas_id = ?,
                            jenis_kelamin = ?, is_active = ?, password_hash = ?, updated_at = ?
                      WHERE id = ?',
                    [
                        $username, $nama, $nis !== '' ? $nis : null, $nisn !== '' ? $nisn : null,
                        $kelasSql, $jk !== '' ? $jk : null, $aktif,
                        password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
                        db_now(), $id,
                    ]
                );
                audit_log($adminId, 'admin.siswa.password', 'warning',
                    'Password akun #' . $id . ' (' . $username . ') diatur ulang oleh admin');
            } else {
                db_exec(
                    'UPDATE users
                        SET username = ?, nama_lengkap = ?, nis = ?, nisn = ?, kelas_id = ?,
                            jenis_kelamin = ?, is_active = ?, updated_at = ?
                      WHERE id = ?',
                    [
                        $username, $nama, $nis !== '' ? $nis : null, $nisn !== '' ? $nisn : null,
                        $kelasSql, $jk !== '' ? $jk : null, $aktif, db_now(), $id,
                    ]
                );
            }

            /* Berpindah kelas dicatat terpisah supaya jejak audit bisa
             * menjelaskan kenapa rekap per kelas berubah. */
            $kelasLama = (int) ($lama['kelas_id'] ?? 0);
            if ($kelasLama !== $kelasId) {
                audit_log($adminId, 'admin.siswa.pindah_kelas', 'info',
                    $username . ' dipindah dari kelas #' . $kelasLama . ' ke #' . $kelasId);
            }

            audit_log($adminId, 'admin.siswa.ubah', 'info', 'Akun #' . $id . ' diubah: ' . $username);
            admin_flash('ok', 'Akun "' . $nama . '" berhasil diperbarui.');
        } else {
            $idBaru = db_insert(
                "INSERT INTO users (username, password_hash, nama_lengkap, role, nis, nisn,
                                    kelas_id, jenis_kelamin, is_active)
                 VALUES (?, ?, ?, 'siswa', ?, ?, ?, ?, ?)",
                [
                    $username,
                    password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
                    $nama, $nis !== '' ? $nis : null, $nisn !== '' ? $nisn : null,
                    $kelasSql, $jk !== '' ? $jk : null, $aktif,
                ]
            );
            audit_log($adminId, 'admin.siswa.tambah', 'info',
                'Akun #' . $idBaru . ' dibuat: ' . $username . ' (' . $nama . ')');
            admin_flash('ok', 'Akun "' . $nama . '" berhasil dibuat.');
        }

        unset($_SESSION['admin_form_siswa']);
        admin_kembali();
    }

    /* ---------- Aktif / nonaktif ---------- */
    if ($aksi === 'status') {
        $idSiswa = (int) post('id', 0);
        $siswa   = admin_siswa($idSiswa);
        if (!$siswa) {
            admin_flash('error', 'Akun siswa tidak ditemukan.');
            admin_kembali();
        }
        /* Menonaktifkan akun yang sedang dipakai untuk masuk perlu
         * remember: begitu is_active diubah, sesi berikutnya langsung
         * ditolak oleh _bootstrap.php. */
        $baru = (int) $siswa['is_active'] === 1 ? 0 : 1;
        db_exec('UPDATE users SET is_active = ?, updated_at = ? WHERE id = ?', [$baru, db_now(), $idSiswa]);
        audit_log($adminId, 'admin.siswa.status', $baru === 1 ? 'info' : 'warning',
            'Akun ' . $siswa['username'] . ' ' . ($baru === 1 ? 'diaktifkan' : 'dinonaktifkan'));
        admin_flash('ok', 'Akun "' . $siswa['nama_lengkap'] . '" berhasil '
            . ($baru === 1 ? 'diaktifkan.' : 'dinonaktifkan.'));
        admin_kembali();
    }

    /* ---------- Atur ulang password ---------- */
    if ($aksi === 'reset_password') {
        $idSiswa = (int) post('id', 0);
        $siswa   = admin_siswa($idSiswa);
        if (!$siswa) {
            admin_flash('error', 'Akun siswa tidak ditemukan.');
            admin_kembali();
        }
        $passwordBaru = (string) ($_POST['password_baru'] ?? '');
        if (strlen($passwordBaru) < PASSWORD_MIN_LENGTH) {
            admin_flash('error', 'Password baru minimal ' . PASSWORD_MIN_LENGTH . ' karakter.');
            admin_kembali();
        }
        db_exec(
            'UPDATE users SET password_hash = ?, login_attempts = 0, locked_until = NULL, updated_at = ? WHERE id = ?',
            [password_hash($passwordBaru, PASSWORD_BCRYPT, ['cost' => 12]), db_now(), $idSiswa]
        );
        audit_log($adminId, 'admin.siswa.password', 'warning',
            'Password akun #' . $idSiswa . ' (' . $siswa['username'] . ') diatur ulang oleh admin');
        admin_flash('ok', 'Password ' . $siswa['nama_lengkap'] . ' berhasil diganti. '
            . 'Kunci akun juga dibuka.');
        admin_kembali();
    }

    /* ---------- Hapus ---------- */
    if ($aksi === 'hapus') {
        $idHapus = (int) post('id', 0);
        $siswa   = admin_siswa($idHapus);
        if (!$siswa) {
            admin_flash('error', 'Akun siswa tidak ditemukan.');
            admin_kembali();
        }

        $jumlahAbsen = (int) db_value(
            'SELECT COUNT(*) FROM attendance WHERE user_id = ?',
            [$idHapus],
            0
        );
        $punyaWajah = (int) db_value(
            'SELECT COUNT(*) FROM face_templates WHERE user_id = ?',
            [$idHapus],
            0
        );
        if ($jumlahAbsen > 0 || $punyaWajah > 0) {
            admin_flash('error', 'Akun "' . $siswa['nama_lengkap'] . '" punya riwayat '
                . $jumlahAbsen . ' absensi dan ' . $punyaWajah . ' template wajah. '
                . 'Nonaktifkan saja supaya riwayatnya tetap bisa dibaca.');
            admin_kembali();
        }

        /* Pengajuan izin yang sudah disetujui ikut hilang kalau akunnya
         * dihapus, dan itu akan mengubah hitungan hari efektif siswa lain
         * di kelasnya. Karena itu izin yang ada ikut Dicegah. */
        $jumlahIzin = (int) db_value(
            'SELECT COUNT(*) FROM izin_requests WHERE user_id = ?',
            [$idHapus],
            0
        );
        if ($jumlahIzin > 0) {
            admin_flash('error', 'Akun "' . $siswa['nama_lengkap'] . '" punya '
                . $jumlahIzin . ' pengajuan izin. Nonaktifkan saja supaya '
                . 'riwayat persensinya tidak berubah.');
            admin_kembali();
        }

        db_exec('DELETE FROM users WHERE id = ?', [$idHapus]);
        audit_log($adminId, 'admin.siswa.hapus', 'warning',
            'Akun #' . $idHapus . ' dihapus: ' . $siswa['username']);
        admin_flash('ok', 'Akun "' . $siswa['nama_lengkap'] . '" berhasil dihapus.');
        admin_kembali();
    }
}

/* ==================================================================
 *  QUERY & TAMPILAN
 * ================================================================== */

$kelasPilih   = (int) get('kelas', 0);
$kelasPilih   = $kelasPilih > 0 ? $kelasPilih : null;
$tahunPilih   = get('tahun', '');
$statusFilter = get('status', 'aktif');
$cari         = get('cari', '');
$urut         = get('urut', 'nama');
$arah         = get('arah', 'asc');
$halaman      = max(1, (int) get('halaman', 1));
$perHalaman   = ROWS_PER_PAGE;

if (!in_array($statusFilter, ['aktif', 'nonaktif', 'semua'], true)) {
    $statusFilter = 'aktif';
}
if (!in_array($urut, ['nama', 'kelas', 'absen', 'status', 'sekolah'], true)) {
    $urut = 'nama';
}

$daftarKelas  = admin_daftar_kelas(false, $tahunPilih !== '' ? $tahunPilih : null);
$tahunFilter  = $tahunPilih !== '' ? $tahunPilih : null;
$statusSql    = $statusFilter === 'semua' ? null : $statusFilter;

/* Penghitung memakai filter yang sama persis dengan daftarnya. Pakai
 * admin_jumlah_siswa() di sini akan salah: fungsi itu hanya bisa
 * menyaring aktif/semua, jadi filter "nonaktif" akan menampilkan total
 * siswa aktif dan paginator membuat halaman kosong. */
$jumlahTotal  = admin_hitung_siswa($kelasPilih, $cari, $tahunFilter, $statusSql);
$totalHal     = max(1, (int) ceil($jumlahTotal / $perHalaman));
$halaman      = min($halaman, $totalHal);
$offset       = ($halaman - 1) * $perHalaman;

$daftar = admin_daftar_siswa(
    $kelasPilih,
    $cari,
    $urut,
    $arah,
    $perHalaman,
    $offset,
    $tahunFilter,
    $statusSql
);

$totalAktif  = admin_jumlah_siswa(null, '', true);
$totalSemua  = admin_jumlah_siswa(null, '', false);
$tanpaKelas  = (int) db_value(
    "SELECT COUNT(*) FROM users WHERE role = 'siswa' AND is_active = 1 AND kelas_id IS NULL",
    [],
    0
);
$terkunci    = (int) db_value(
    "SELECT COUNT(*) FROM users WHERE role = 'siswa' AND is_active = 1 AND locked_until IS NOT NULL AND locked_until > ?",
    [db_now()],
    0
);

/* Nilai form terakhir yang gagal disimpan. */
$formulir = $_SESSION['admin_form_siswa'] ?? null;
unset($_SESSION['admin_form_siswa']);

/* Drawer bisa dibuka dengan tiga cara: tombol "Tambah Siswa" (data-buka),
 * tautan "Ubah" di tabel (?edit=), dan tombol melayang di HP (?aksi=baru).
 * Yang terakhir harus ditangani di sini, kalau tidak tombolnya menuju URL
 * yang tetap menampilkan halaman tanpa form. */
$bukaBaru = (string) get('aksi', '') === 'baru';
$idEdit   = (int) get('edit', 0);
$edit     = $idEdit > 0 ? admin_siswa($idEdit) : null;
if ($idEdit > 0 && !$edit) {
    admin_flash('warn', 'Akun yang mau diubah tidak ditemukan.');
    admin_kembali('daftar');
}

/* Guru tidak boleh mengubah apa pun. Tombolnya dihilangkan dari tampilan
 * (bukan cuma ditolak di server) supaya halaman yang dia lihat sama
 * dengan kemampuannya. */
$bukaOtomatis = $isAdmin && ($bukaBaru || $idEdit > 0);

$pageActionsHtml = $isAdmin
    ? '<a class="btn btn--ghost" href="/admin/impor.php">'
        . '<i class="fa-solid fa-file-import"></i> Impor CSV</a>'
      . '<button type="button" class="btn btn--primary" data-buka="drawerSiswa">'
        . '<i class="fa-solid fa-user-plus"></i> Tambah Siswa</button>'
    : '';

$fabHref  = $isAdmin ? '/admin/siswa.php?aksi=baru' : '';
$fabLabel = 'Tambah Siswa';
$fabIcon  = 'fa-user-plus';

require __DIR__ . '/../templates/admin-head.php';
?>

<!-- ==================================================================
     RINGKASAN
     ================================================================== -->
<div class="grid grid--kpi mb-2">
    <div class="kpi kpi--slim kpi--brand">
        <p class="kpi__label" style="margin:0">Siswa aktif</p>
        <p class="kpi__value"><?= $totalAktif ?></p>
        <p class="kpi__hint"><?= $totalSemua - $totalAktif ?> akun nonaktif</p>
    </div>
    <div class="kpi kpi--slim kpi--info">
        <p class="kpi__label" style="margin:0">Kelas aktif</p>
        <p class="kpi__value"><?= count(array_filter($daftarKelas, static fn($k) => (int) $k['is_active'] === 1)) ?></p>
        <p class="kpi__hint"><?= count($daftarKelas) ?> kelas terdaftar</p>
    </div>
    <div class="kpi kpi--slim kpi--<?= $tanpaKelas > 0 ? 'warn' : 'muted' ?>">
        <p class="kpi__label" style="margin:0">Tanpa kelas</p>
        <p class="kpi__value"><?= $tanpaKelas ?></p>
        <p class="kpi__hint"><?= $tanpaKelas > 0 ? 'tidak muncul di rekap kelas' : 'semuanya sudah punya kelas' ?></p>
    </div>
    <div class="kpi kpi--slim kpi--<?= $terkunci > 0 ? 'warn' : 'muted' ?>">
        <p class="kpi__label" style="margin:0">Akun terkunci</p>
        <p class="kpi__value"><?= $terkunci ?></p>
        <p class="kpi__hint">gagal login berulang kali</p>
    </div>
</div>

<?php if ($tanpaKelas > 0): ?>
<div class="note note--warn mb-2">
    <i class="fa-solid fa-triangle-exclamation note__ic"></i>
    <div class="note__body">
        <span class="note__title"><?= $tanpaKelas ?> siswa belum punya kelas.</span>
        Siswa tanpa kelas tetap tercatat di jumlah siswa aktif, tapi tidak
        muncul di rekap per kelas maupun di daftar absensi kelas tertentu.
    </div>
</div>
<?php endif; ?>

<!-- ==================================================================
     DAFTAR SISWA
     ================================================================== -->
<div class="card" id="daftar">

    <form class="filterbar" method="get" action="/admin/siswa.php">
        <div class="field" style="min-width:170px">
            <label class="field__label" for="fKelas">Kelas</label>
            <select id="fKelas" name="kelas" onchange="this.form.submit()">
                <option value="0">Semua kelas</option>
                <?php foreach ($daftarKelas as $k): ?>
                    <option value="<?= (int) $k['id'] ?>"
                        <?= $kelasPilih === (int) $k['id'] ? ' selected' : '' ?>>
                        <?= esc($k['nama_kelas']) ?><?= (int) $k['is_active'] === 0 ? ' (nonaktif)' : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field" style="min-width:140px">
            <label class="field__label" for="fStatus">Status</label>
            <select id="fStatus" name="status" onchange="this.form.submit()">
                <option value="aktif"   <?= $statusFilter === 'aktif' ? ' selected' : '' ?>>Aktif</option>
                <option value="semua"   <?= $statusFilter === 'semua' ? ' selected' : '' ?>>Semua</option>
                <option value="nonaktif"<?= $statusFilter === 'nonaktif' ? ' selected' : '' ?>>Nonaktif</option>
            </select>
        </div>

        <div class="field field--grow">
            <label class="field__label" for="fCari">Cari</label>
            <div class="search">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="search" id="fCari" name="cari" value="<?= esc($cari) ?>"
                       placeholder="Nama, username, NIS, atau NISN">
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

    <?php if ($daftar === []): ?>
        <div class="empty">
            <span class="empty__ic"><i class="fa-solid fa-users"></i></span>
            <p class="empty__title">Tidak ada siswa</p>
            <p class="empty__text">
                <?php if ($cari !== '' || $kelasPilih !== null): ?>
                    Tidak ada siswa yang cocok dengan filter ini.
                <?php else: ?>
                    Belum ada akun siswa sama sekali. Tambahkan satu per satu,
                    atau impor dari daftar resmi sekolah.
                <?php endif; ?>
            </p>
            <?php if ($isAdmin && $cari === '' && $kelasPilih === null): ?>
                <button type="button" class="btn btn--primary btn--sm" data-buka="drawerSiswa">
                    <i class="fa-solid fa-user-plus"></i> Tambah Siswa
                </button>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="tablewrap">
            <table class="table table--cards">
                <thead>
                    <tr>
                        <th class="shrink">No</th>
                        <th>
                            <a class="table__sort<?= $urut === 'nama' ? ' is-on' : '' ?>"
                               href="<?= esc(admin_ubah_query(['urut' => 'nama', 'arah' => $urut === 'nama' && $arah === 'asc' ? 'desc' : 'asc'])) ?>">
                                Siswa <i class="fa-solid fa-sort"></i>
                            </a>
                        </th>
                        <th>
                            <a class="table__sort<?= $urut === 'kelas' ? ' is-on' : '' ?>"
                               href="<?= esc(admin_ubah_query(['urut' => 'kelas', 'arah' => $urut === 'kelas' && $arah === 'asc' ? 'desc' : 'asc'])) ?>">
                                Kelas <i class="fa-solid fa-sort"></i>
                            </a>
                        </th>
                        <th>NIS / NISN</th>
                        <th>
                            <a class="table__sort<?= $urut === 'absen' ? ' is-on' : '' ?>"
                               href="<?= esc(admin_ubah_query(['urut' => 'absen', 'arah' => $urut === 'absen' && $arah === 'asc' ? 'desc' : 'asc'])) ?>">
                                Absen terakhir <i class="fa-solid fa-sort"></i>
                            </a>
                        </th>
                        <th>Status</th>
                        <?php if ($isAdmin): ?>
                            <th class="actions-cell">Aksi</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($daftar as $i => $s): ?>
                    <?php
                    $aktif     = (int) $s['is_active'] === 1;
                    $terkunci  = !empty($s['locked_until']) && strtotime((string) $s['locked_until']) > time();
                    $kelasAda  = $s['kelas_id'] !== null;
                    ?>
                    <tr>
                        <td data-label="No" class="num-cell muted"><?= $offset + $i + 1 ?></td>
                        <td data-label="Siswa">
                            <div class="celluser">
                                <span class="avatar avatar--sm avatar--plain<?= $aktif ? '' : ' muted' ?>">
                                    <?= esc(mb_strtoupper(mb_substr((string) $s['nama_lengkap'], 0, 1))) ?>
                                </span>
                                <span class="celluser__text">
                                    <span class="celluser__name"><?= esc($s['nama_lengkap']) ?></span>
                                    <span class="celluser__meta"><?= esc($s['username']) ?></span>
                                </span>
                            </div>
                        </td>
                        <td data-label="Kelas">
                            <?php if ($kelasAda): ?>
                                <a class="small" href="/admin/siswa.php?kelas=<?= (int) $s['kelas_id'] ?>">
                                    <?= esc($s['nama_kelas'] ?? '-') ?>
                                </a>
                            <?php else: ?>
                                <span class="badge badge--warn"><i class="dot"></i>Belum ada</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="NIS" class="small num-cell">
                            <?= esc($s['nis'] ?: '-') ?>
                            <?php if ($s['nisn']): ?>
                                <div class="tiny muted"><?= esc($s['nisn']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td data-label="Absen terakhir" class="small">
                            <?php if (!empty($s['absen_terakhir'])): ?>
                                <?= esc(admin_lampau($s['absen_terakhir'])) ?>
                            <?php else: ?>
                                <span class="muted">belum pernah</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Status">
                            <?php if (!$aktif): ?>
                                <span class="badge badge--muted"><i class="dot"></i>Nonaktif</span>
                            <?php elseif ($terkunci): ?>
                                <span class="badge badge--warn"><i class="dot"></i>Terkunci</span>
                            <?php else: ?>
                                <span class="badge badge--ok"><i class="dot"></i>Aktif</span>
                            <?php endif; ?>
                        </td>
                        <?php if ($isAdmin): ?>
                        <td data-label="" class="actions-cell">
                            <div class="row row--end" style="gap:6px;flex-wrap:nowrap">
                                <a class="iconbtn" href="/admin/siswa.php?edit=<?= (int) $s['id'] ?>"
                                   title="Ubah akun" aria-label="Ubah akun <?= esc($s['nama_lengkap']) ?>">
                                    <i class="fa-solid fa-pen"></i>
                                </a>
                                <button type="button" class="iconbtn"
                                            data-reset="<?= (int) $s['id'] ?>"
                                            data-nama="<?= esc($s['nama_lengkap']) ?>"
                                            title="Atur ulang password"
                                            aria-label="Atur ulang password <?= esc($s['nama_lengkap']) ?>">
                                        <i class="fa-solid fa-key"></i>
                                    </button>
                                    <form method="post" action="/admin/siswa.php" style="display:contents">
                                        <input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">
                                        <input type="hidden" name="aksi" value="status">
                                        <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                                        <button type="submit" class="iconbtn"
                                                title="<?= $aktif ? 'Nonaktifkan' : 'Aktifkan' ?>"
                                                aria-label="<?= $aktif ? 'Nonaktifkan' : 'Aktifkan' ?> akun <?= esc($s['nama_lengkap']) ?>">
                                            <i class="fa-solid <?= $aktif ? 'fa-pause' : 'fa-play' ?>"></i>
                                        </button>
                                    </form>
                                    <form method="post" action="/admin/siswa.php" style="display:contents"
                                          onsubmit="return hapusSiswa(event, <?= (int) $s['id'] ?>);">
                                        <input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">
                                        <input type="hidden" name="aksi" value="hapus">
                                        <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                                        <button type="submit" class="iconbtn iconbtn--danger"
                                                title="Hapus akun" aria-label="Hapus akun <?= esc($s['nama_lengkap']) ?>">
                                            <i class="fa-solid fa-trash-can"></i>
                                        </button>
                                    </form>
                            </div>
                        </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($jumlahTotal > $perHalaman): ?>
            <div class="pager">
                <span class="pager__info">
                    Menampilkan <?= $offset + 1 ?>&ndash;<?= min($offset + $perHalaman, $jumlahTotal) ?>
                    dari <?= $jumlahTotal ?> siswa
                </span>
                <?php if ($halaman > 1): ?>
                    <a href="<?= esc(admin_ubah_query(['halaman' => $halaman - 1])) ?>"
                       aria-label="Halaman sebelumnya"><i class="fa-solid fa-chevron-left"></i></a>
                <?php else: ?>
                    <span class="is-off"><i class="fa-solid fa-chevron-left"></i></span>
                <?php endif; ?>

                <?php for ($h = 1; $h <= $totalHal; $h++): ?>
                    <?php if ($h === $halaman): ?>
                        <span class="is-on"><?= $h ?></span>
                    <?php else: ?>
                        <a href="<?= esc(admin_ubah_query(['halaman' => $h])) ?>"><?= $h ?></a>
                    <?php endif; ?>
                <?php endfor; ?>

                <?php if ($halaman < $totalHal): ?>
                    <a href="<?= esc(admin_ubah_query(['halaman' => $halaman + 1])) ?>"
                       aria-label="Halaman berikutnya"><i class="fa-solid fa-chevron-right"></i></a>
                <?php else: ?>
                    <span class="is-off"><i class="fa-solid fa-chevron-right"></i></span>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="card__foot">
                <?= $jumlahTotal ?> siswa
                <?= $cari !== '' || $kelasPilih !== null ? 'sesuai filter aktif' : 'dari seluruh kelas' ?>.
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php if (!$isAdmin): ?>
<div class="note note--flat mt-2">
    <i class="fa-solid fa-lock note__ic"></i>
    <div class="note__body">
        Kamu masuk sebagai <strong><?= esc($peranLabel) ?></strong>, jadi data siswa
        bisa dilihat tapi tidak bisa diubah. Minta administrator sekolah untuk
        menambah atau memindahkan siswa.
    </div>
</div>
<?php endif; ?>

<!-- ==================================================================
     DRAWER: TAMBAH / UBAH
     ================================================================== -->
<?php if ($isAdmin): ?>
<div class="drawer" id="drawerSiswa" role="dialog" aria-modal="true" aria-label="Form akun siswa"
     data-autoload="<?= $bukaOtomatis ? '1' : '0' ?>" <?= $bukaOtomatis ? '' : 'hidden' ?>>
    <div class="drawer__scrim" data-tutup></div>
    <div class="drawer__panel">
        <div class="drawer__head">
            <div style="flex:1;min-width:0">
                <p class="drawer__title"><?= $edit ? 'Ubah Akun Siswa' : 'Tambah Siswa' ?></p>
                <p class="drawer__sub">
                    <?= $edit ? esc($edit['username']) : 'Buat akun login untuk siswa' ?>
                </p>
            </div>
            <button type="button" class="iconbtn" data-tutup aria-label="Tutup">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="post" action="/admin/siswa.php" class="drawer__body">
            <input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">
            <input type="hidden" name="aksi" value="simpan">
            <input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">

            <?php
            $f = $formulir ?? [
                'id' => $edit['id'] ?? 0,
                'username' => $edit['username'] ?? '',
                'nama_lengkap' => $edit['nama_lengkap'] ?? '',
                'nis' => $edit['nis'] ?? '',
                'nisn' => $edit['nisn'] ?? '',
                'kelas_id' => $edit['kelas_id'] ?? 0,
                'jenis_kelamin' => $edit['jenis_kelamin'] ?? '',
                'is_active' => $edit['is_active'] ?? 1,
                'galat' => [],
            ];
            $galat = $f['galat'] ?? [];
            $baru  = (int) $f['id'] === 0;
            ?>

            <div class="form">
                <div class="field<?= isset($galat['nama_lengkap']) ? ' field--error' : '' ?>">
                    <label class="field__label" for="sNama">Nama lengkap <span class="field__req">*</span></label>
                    <input type="text" id="sNama" name="nama_lengkap" required maxlength="100"
                           value="<?= esc($f['nama_lengkap']) ?>"
                           placeholder="Nama lengkap sesuai ijazah" autofocus>
                    <?php if (isset($galat['nama_lengkap'])): ?>
                        <p class="field__err"><?= esc($galat['nama_lengkap']) ?></p>
                    <?php endif; ?>
                </div>

                <div class="form--grid">
                    <div class="field<?= isset($galat['username']) ? ' field--error' : '' ?>">
                        <label class="field__label" for="sUsername">Username <span class="field__req">*</span></label>
                        <input type="text" id="sUsername" name="username" required maxlength="32"
                               autocapitalize="none" autocorrect="off" spellcheck="false"
                               value="<?= esc($f['username']) ?>" placeholder="contoh: andi01">
                        <?php if (isset($galat['username'])): ?>
                            <p class="field__err"><?= esc($galat['username']) ?></p>
                        <?php else: ?>
                            <p class="field__hint">Dipakai untuk login. 3-32 karakter.</p>
                        <?php endif; ?>
                    </div>

                    <div class="field<?= isset($galat['kelas_id']) ? ' field--error' : '' ?>">
                        <label class="field__label" for="sKelas">Kelas</label>
                        <select id="sKelas" name="kelas_id">
                            <option value="0">Belum ditentukan</option>
                            <?php foreach ($daftarKelas as $k): ?>
                                <?php if ((int) $k['is_active'] === 0) { continue; } ?>
                                <option value="<?= (int) $k['id'] ?>"
                                    <?= (int) $f['kelas_id'] === (int) $k['id'] ? ' selected' : '' ?>>
                                    <?= esc($k['nama_kelas']) ?> (<?= esc($k['tahun_ajaran']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (isset($galat['kelas_id'])): ?>
                            <p class="field__err"><?= esc($galat['kelas_id']) ?></p>
                        <?php else: ?>
                            <p class="field__hint">
                                <a href="/admin/kelas.php">Kelola daftar kelas</a>
                            </p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="form--grid">
                    <div class="field<?= isset($galat['nis']) ? ' field--error' : '' ?>">
                        <label class="field__label" for="sNis">NIS</label>
                        <input type="text" id="sNis" name="nis" inputmode="numeric" maxlength="20"
                               value="<?= esc($f['nis']) ?>" placeholder="Nomor induk siswa">
                        <?php if (isset($galat['nis'])): ?>
                            <p class="field__err"><?= esc($galat['nis']) ?></p>
                        <?php endif; ?>
                    </div>

                    <div class="field<?= isset($galat['nisn']) ? ' field--error' : '' ?>">
                        <label class="field__label" for="sNisn">NISN</label>
                        <input type="text" id="sNisn" name="nisn" inputmode="numeric" maxlength="20"
                               value="<?= esc($f['nisn']) ?>" placeholder="Nomor induk siswa nasional">
                        <?php if (isset($galat['nisn'])): ?>
                            <p class="field__err"><?= esc($galat['nisn']) ?></p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="field">
                    <label class="field__label" for="sJk">Jenis kelamin</label>
                    <div class="choices">
                        <label class="choice">
                            <input type="radio" name="jenis_kelamin" value="L"
                                <?= (string) $f['jenis_kelamin'] === 'L' ? 'checked' : '' ?>>
                            <span class="choice__box">
                                <i class="fa-solid fa-mars"></i> Laki-laki
                            </span>
                        </label>
                        <label class="choice">
                            <input type="radio" name="jenis_kelamin" value="P"
                                <?= (string) $f['jenis_kelamin'] === 'P' ? 'checked' : '' ?>>
                            <span class="choice__box">
                                <i class="fa-solid fa-venus"></i> Perempuan
                            </span>
                        </label>
                    </div>
                    <?php if (isset($galat['jenis_kelamin'])): ?>
                        <p class="field__err"><?= esc($galat['jenis_kelamin']) ?></p>
                    <?php endif; ?>
                </div>

                <?php if ($baru): ?>
                    <div class="card card--flat">
                        <div class="card__head card__head--plain">
                            <p class="card__title">Password</p>
                        </div>
                        <div class="card__body" style="padding-top:0">
                            <div class="form--grid">
                                <div class="field<?= isset($galat['password']) ? ' field--error' : '' ?>">
                                    <label class="field__label" for="sPass">Password <span class="field__req">*</span></label>
                                    <input type="password" id="sPass" name="password" required
                                           minlength="<?= PASSWORD_MIN_LENGTH ?>"
                                           autocomplete="new-password">
                                </div>
                                <div class="field">
                                    <label class="field__label" for="sPass2">Ulangi password</label>
                                    <input type="password" id="sPass2" name="password_ulang"
                                           minlength="<?= PASSWORD_MIN_LENGTH ?>"
                                           autocomplete="new-password">
                                </div>
                            </div>
                            <?php if (isset($galat['password'])): ?>
                                <p class="field__err mt-1"><?= esc($galat['password']) ?></p>
                            <?php else: ?>
                                <p class="field__hint mt-1">
                                    Minimal <?= PASSWORD_MIN_LENGTH ?> karakter. Beri tahu siswa
                                    sendiri lewat jalan lain, jangan dikirim lewat chat yang
                                    bisa dibuka siapa saja.
                                </p>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="note note--flat">
                        <i class="fa-solid fa-circle-info note__ic"></i>
                        <div class="note__body">
                            Password tidak diubah dari form ini. Kalau siswa lupa,
                            pakai tombol kunci di daftar untuk mengatur ulang.
                        </div>
                    </div>
                <?php endif; ?>

                <div class="switchrow">
                    <div class="switchrow__text">
                        <p class="switchrow__title">Akun aktif</p>
                        <p class="switchrow__hint">
                            Akun nonaktif tidak bisa login dan langsung dikeluarkan
                            dari sesi yang sedang berjalan.
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
                    <?= $baru ? 'Buat Akun' : 'Simpan Perubahan' ?>
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if ($isAdmin): ?>
<!-- ==================================================================
     MODAL ATUR ULANG PASSWORD
     ================================================================== -->
<div class="modal" id="modalReset" role="dialog" aria-modal="true"
     aria-labelledby="modalResetTitle" hidden>
    <div class="modal__scrim" data-tutup></div>
    <div class="modal__panel">
        <form method="post" action="/admin/siswa.php">
            <input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">
            <input type="hidden" name="aksi" value="reset_password">
            <input type="hidden" name="id" id="resetId">

            <h2 class="modal__title" id="modalResetTitle">Atur ulang password</h2>
            <p class="modal__body">
                Password untuk <strong id="resetNama">-</strong> akan diganti.
                Kunci akun juga ikut dibuka, jadi siswa bisa langsung mencoba login.
            </p>

            <div class="field mt-2">
                <label class="field__label" for="resetPass">Password baru</label>
                <input type="password" id="resetPass" name="password_baru" required
                       minlength="<?= PASSWORD_MIN_LENGTH ?>" autocomplete="new-password">
                <p class="field__hint">Minimal <?= PASSWORD_MIN_LENGTH ?> karakter.</p>
            </div>

            <div class="modal__actions">
                <button type="button" class="btn btn--ghost" data-tutup>Batal</button>
                <button type="submit" class="btn btn--primary">
                    <i class="fa-solid fa-key"></i> Ganti Password
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
<?php if ($isAdmin): ?>
/* Atur ulang password lewat modal: nama siswa diisi dari tombol yang
   diklik, jadi tidak ada field yang bisa diubah diam-diam. */
(function () {
    var modal = document.getElementById('modalReset');
    if (!modal) { return; }
    var fId   = document.getElementById('resetId');
    var fNama = document.getElementById('resetNama');
    var fPass = document.getElementById('resetPass');

    document.querySelectorAll('[data-reset]').forEach(function (b) {
        b.addEventListener('click', function () {
            if (fId) { fId.value = b.dataset.reset; }
            if (fNama) { fNama.textContent = b.dataset.nama || '-'; }
            if (fPass) { fPass.value = ''; }
            modal.hidden = false;
            document.body.classList.add('is-locked');
            if (fPass) { fPass.focus(); }
        });
    });
})();

/* Hapus akun. Penjaga yang sesungguhnya ada di PHP: akun yang punya
   absensi, wajah, atau pengajuan izin akan ditolak di server. */
function hapusSiswa(ev, id) {
    var nama = '';
    if (ev && ev.submitter && ev.submitter.closest) {
        var sel = ev.submitter.closest('tr').querySelector('.celluser__name');
        if (sel) { nama = sel.textContent; }
    }
    return window.confirm('Hapus akun ' + (nama || 'ini')
        + '?\n\nHanya bisa untuk akun yang belum pernah absen, belum daftar '
        + 'wajah, dan belum punya pengajuan izin. Kalau gagal, nonaktifkan saja.');
}
<?php endif; ?>
</script>

<?php require __DIR__ . '/../templates/admin-foot.php'; 