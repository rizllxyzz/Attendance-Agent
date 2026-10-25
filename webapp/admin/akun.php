<?php
/**
 * admin/akun.php
 * ---------------------------------------------------------------------------
 * Manajemen akun staf: guru dan administrator.
 *
 * KENAPA HALAMAN INI ADA
 * ----------------------
 * Sebelumnya akun hanya bisa dibuat untuk siswa (admin/siswa.php). Padahal
 * peran "guru" dipakai di mana-mana: login panel, jadi wali kelas, dan
 * memutuskan izin. Tanpa halaman ini, akun guru harus dibuat lewat SQL
 * manual - merepotkan dan rawan salah.
 *
 * BEDANYA DENGAN SISWA
 * --------------------
 *   - Role-nya guru atau admin, bukan siswa.
 *   - Tidak punya NIS/NISN/kelas/jenis kelamin.
 *   - Penghapusan dijaga ketat: akun yang masih menjadi wali kelas tidak
 *     boleh dihapus, dan administrator terakhir tidak boleh dihapus atau
 *     dinonaktifkan supaya panel tidak terkunci selamanya.
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$pageKey   = 'akun';
$pageTitle = 'Akun Guru';
$pageSub   = 'Kelola akun guru dan administrator';

if (!$isAdmin) {
    admin_flash('warn', 'Manajemen akun staf hanya untuk administrator.');
    header('Location: /admin/index.php');
    exit;
}

/** Label peran untuk tampilan. */
function akun_label(string $role): string
{
    return $role === 'admin' ? 'Administrator' : 'Guru';
}

/** Daftar staf sesuai filter. */
function akun_daftar(string $cari, string $status): array
{
    $sql = "SELECT * FROM users WHERE role IN ('guru', 'admin')";
    $p   = [];
    if ($cari !== '') {
        $sql .= ' AND (nama_lengkap LIKE ? OR username LIKE ?)';
        $p[] = '%' . $cari . '%';
        $p[] = '%' . $cari . '%';
    }
    if ($status === 'aktif') {
        $sql .= ' AND is_active = 1';
    } elseif ($status === 'nonaktif') {
        $sql .= ' AND is_active = 0';
    }
    $sql .= ' ORDER BY role ASC, nama_lengkap ASC';
    return db_all($sql, $p);
}

/** Satu akun staf berdasarkan id (null bila bukan staf). */
function akun_staf(int $id): ?array
{
    return db_one("SELECT * FROM users WHERE id = ? AND role IN ('guru', 'admin') LIMIT 1", [$id], null);
}

/** Berapa administrator aktif yang tersisa. */
function akun_admin_aktif(int $kecuali = 0): int
{
    return (int) db_value(
        "SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1 AND id <> ?",
        [$kecuali],
        0
    );
}

/* ==================================================================
 |  PROSES POST
 * ================================================================== */

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_hanya_post();

    $aksi = post('aksi', '');

    /* ---------- Tambah / ubah akun ---------- */
    if ($aksi === 'simpan') {
        $id         = (int) post('id', 0);
        $username   = post('username', '');
        $nama       = post('nama_lengkap', '');
        $role       = post('role', 'guru');
        $aktif      = post('is_active', '0') === '1' ? 1 : 0;
        $password   = (string) ($_POST['password'] ?? '');
        $passwordU2 = (string) ($_POST['password_ulang'] ?? '');

        if (!in_array($role, ['guru', 'admin'], true)) {
            $role = 'guru';
        }

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
        if ($id === 0 && $password === '') {
            $galat['password'] = 'Password wajib diisi untuk akun baru.';
        }
        if ($password !== '') {
            if (strlen($password) < PASSWORD_MIN_LENGTH) {
                $galat['password'] = 'Password minimal ' . PASSWORD_MIN_LENGTH . ' karakter.';
            } elseif ($passwordU2 !== '' && $password !== $passwordU2) {
                $galat['password'] = 'Ulangi password harus sama.';
            }
        }

        /* Username unik di seluruh tabel, bukan hanya antar staf. */
        if (!isset($galat['username'])) {
            $ada = db_one('SELECT id FROM users WHERE LOWER(username) = LOWER(?) AND id <> ? LIMIT 1', [$username, $id], null);
            if ($ada) {
                $galat['username'] = 'Username itu sudah dipakai akun lain.';
            }
        }

        /* Jangan sampai administrator terakhir diturunkan jadi guru atau
         * dinonaktifkan - panel akan terkunci. */
        if ($id > 0 && $id === $adminId && $role !== 'admin' && akun_admin_aktif($id) === 0) {
            $galat['role'] = 'Ini akun administrator terakhir; peran tidak boleh diubah.';
        }
        if ($galat === []) {
            $lama = $id > 0 ? akun_staf($id) : null;
            if ($id > 0 && !$lama) {
                admin_flash('error', 'Akun staf tidak ditemukan.');
                admin_kembali();
            }
            $roleLama = (string) ($lama['role'] ?? '');
            if ($id > 0 && $roleLama === 'admin' && $role === 'guru' && akun_admin_aktif($id) === 0) {
                $galat['role'] = 'Ini administrator terakhir, tidak bisa diturunkan jadi guru.';
            }
            if ($id > 0 && $roleLama === 'admin' && $aktif === 0 && akun_admin_aktif($id) === 0) {
                $galat['role'] = 'Ini administrator terakhir, tidak bisa dinonaktifkan.';
            }
        }

        if ($galat !== []) {
            $_SESSION['admin_form_akun'] = [
                'id' => $id, 'username' => $username, 'nama_lengkap' => $nama,
                'role' => $role, 'is_active' => $aktif, 'galat' => $galat,
            ];
            admin_flash('error', 'Periksa kembali isian akun.');
            admin_kembali_query($id > 0 ? ['edit' => $id] : ['aksi' => 'baru']);
        }

        if ($id > 0) {
            if ($password !== '') {
                db_exec(
                    'UPDATE users SET username = ?, nama_lengkap = ?, role = ?, is_active = ?,
                            password_hash = ?, updated_at = ? WHERE id = ?',
                    [
                        $username, $nama, $role, $aktif,
                        password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
                        db_now(), $id,
                    ]
                );
                audit_log($adminId, 'admin.akun.password', 'warning',
                    'Password akun staf #' . $id . ' (' . $username . ') diatur ulang.');
            } else {
                db_exec(
                    'UPDATE users SET username = ?, nama_lengkap = ?, role = ?, is_active = ?,
                            updated_at = ? WHERE id = ?',
                    [$username, $nama, $role, $aktif, db_now(), $id]
                );
            }
            audit_log($adminId, 'admin.akun.ubah', 'info', 'Akun staf #' . $id . ' diubah: ' . $username);
            admin_flash('ok', 'Akun "' . $nama . '" berhasil diperbarui.');
        } else {
            $idBaru = db_insert(
                'INSERT INTO users (username, password_hash, nama_lengkap, role, is_active)
                 VALUES (?, ?, ?, ?, ?)',
                [$username, password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]), $nama, $role, $aktif]
            );
            audit_log($adminId, 'admin.akun.tambah', 'info',
                'Akun staf #' . $idBaru . ' dibuat: ' . $username . ' (' . akun_label($role) . ')');
            admin_flash('ok', 'Akun "' . $nama . '" berhasil dibuat.');
        }

        unset($_SESSION['admin_form_akun']);
        admin_kembali();
    }

    /* ---------- Aktif / nonaktif ---------- */
    if ($aksi === 'status') {
        $idStaf = (int) post('id', 0);
        $staf   = akun_staf($idStaf);
        if (!$staf) {
            admin_flash('error', 'Akun staf tidak ditemukan.');
            admin_kembali();
        }
        if ($idStaf === $adminId) {
            admin_flash('warn', 'Tidak bisa menonaktifkan akun sendiri.');
            admin_kembali();
        }
        $baru = (int) $staf['is_active'] === 1 ? 0 : 1;
        if ($baru === 0 && $staf['role'] === 'admin' && akun_admin_aktif($idStaf) === 0) {
            admin_flash('warn', 'Ini administrator aktif terakhir, tidak bisa dinonaktifkan.');
            admin_kembali();
        }
        db_exec('UPDATE users SET is_active = ?, updated_at = ? WHERE id = ?', [$baru, db_now(), $idStaf]);
        audit_log($adminId, 'admin.akun.status', $baru === 1 ? 'info' : 'warning',
            'Akun staf ' . $staf['username'] . ' -> ' . ($baru === 1 ? 'aktif' : 'nonaktif'));
        admin_flash('ok', 'Akun "' . $staf['nama_lengkap'] . '" ' . ($baru === 1 ? 'diaktifkan.' : 'dinonaktifkan.'));
        admin_kembali();
    }

    /* ---------- Hapus ---------- */
    if ($aksi === 'hapus') {
        $idStaf = (int) post('id', 0);
        $staf   = akun_staf($idStaf);
        if (!$staf) {
            admin_flash('error', 'Akun staf tidak ditemukan.');
            admin_kembali();
        }
        if ($idStaf === $adminId) {
            admin_flash('warn', 'Tidak bisa menghapus akun sendiri.');
            admin_kembali();
        }
        if ($staf['role'] === 'admin' && akun_admin_aktif($idStaf) === 0) {
            admin_flash('warn', 'Ini administrator terakhir, tidak bisa dihapus.');
            admin_kembali();
        }
        $wali = (int) db_value('SELECT COUNT(*) FROM kelas WHERE wali_kelas = ?', [$idStaf], 0);
        if ($wali > 0) {
            admin_flash('warn', 'Akun ini masih wali di ' . $wali . ' kelas. Ganti walinya dulu di halaman Kelas.');
            admin_kembali();
        }
        db_exec('DELETE FROM users WHERE id = ?', [$idStaf]);
        audit_log($adminId, 'admin.akun.hapus', 'warning',
            'Akun staf #' . $idStaf . ' (' . $staf['username'] . ') dihapus.');
        admin_flash('ok', 'Akun "' . $staf['nama_lengkap'] . '" dihapus.');
        admin_kembali();
    }

    admin_kembali();
}

/* ==================================================================
 |  DATA UNTUK TAMPILAN
 * ================================================================== */

$cari   = trim((string) get('cari', ''));
$status = (string) get('status', 'semua');
if (!in_array($status, ['semua', 'aktif', 'nonaktif'], true)) {
    $status = 'semua';
}

$daftar = akun_daftar($cari, $status);

$totalSemua  = (int) db_value("SELECT COUNT(*) FROM users WHERE role IN ('guru', 'admin')", [], 0);
$totalGuru   = (int) db_value("SELECT COUNT(*) FROM users WHERE role = 'guru' AND is_active = 1", [], 0);
$totalAdmin  = (int) db_value("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1", [], 0);
$totalNonakt = (int) db_value("SELECT COUNT(*) FROM users WHERE role IN ('guru', 'admin') AND is_active = 0", [], 0);

$idEdit  = (int) get('edit', 0);
$bukaBaru = get('aksi', '') === 'baru';
$edit    = $idEdit > 0 ? akun_staf($idEdit) : null;

$formulir = $_SESSION['admin_form_akun'] ?? null;
unset($_SESSION['admin_form_akun']);

$bukaOtomatis = $isAdmin && ($bukaBaru || $edit !== null);

$pageActionsHtml = $isAdmin
    ? '<button type="button" class="btn btn--primary" data-buka="drawerAkun">'
        . '<i class="fa-solid fa-user-plus"></i> Tambah Akun</button>'
    : '';
$fabHref  = '';
$fabLabel = 'Akun Guru';

require __DIR__ . '/../templates/admin-head.php';
?>

<!-- ================= RINGKASAN ================= -->
<div class="grid grid--kpi mb-2">
    <div class="kpi kpi--slim kpi--brand">
        <p class="kpi__label" style="margin:0">Total staf</p>
        <p class="kpi__value"><?= $totalSemua ?></p>
        <p class="kpi__hint"><?= $totalNonakt ?> nonaktif</p>
    </div>
    <div class="kpi kpi--slim kpi--ok">
        <p class="kpi__label" style="margin:0">Guru aktif</p>
        <p class="kpi__value"><?= $totalGuru ?></p>
        <p class="kpi__hint">bisa masuk panel</p>
    </div>
    <div class="kpi kpi--slim kpi--info">
        <p class="kpi__label" style="margin:0">Admin aktif</p>
        <p class="kpi__value"><?= $totalAdmin ?></p>
        <p class="kpi__hint">akses penuh</p>
    </div>
</div>

<div class="note note--flat mb-2">
    <i class="fa-solid fa-circle-info note__ic"></i>
    <div class="note__body">
        Akun guru bisa masuk ke panel, tapi tidak boleh mengubah data master
        (siswa, kelas, pengaturan). Wali kelas ditetapkan di halaman
        <a href="/admin/kelas.php">Kelas</a>.
    </div>
</div>

<!-- ================= DAFTAR STAF ================= -->
<div class="card" id="daftar">
    <form class="filterbar" method="get" action="/admin/akun.php">
        <div class="field" style="min-width:140px">
            <label class="field__label" for="fStatus">Status</label>
            <select id="fStatus" name="status" onchange="this.form.submit()">
                <option value="semua"    <?= $status === 'semua' ? ' selected' : '' ?>>Semua</option>
                <option value="aktif"    <?= $status === 'aktif' ? ' selected' : '' ?>>Aktif</option>
                <option value="nonaktif" <?= $status === 'nonaktif' ? ' selected' : '' ?>>Nonaktif</option>
            </select>
        </div>
        <div class="field field--grow">
            <label class="field__label" for="fCari">Cari</label>
            <div class="search">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="search" id="fCari" name="cari" value="<?= esc($cari) ?>"
                       placeholder="Nama atau username">
            </div>
        </div>
        <button type="submit" class="btn btn--soft btn--sm">
            <i class="fa-solid fa-filter"></i> Terapkan
        </button>
    </form>

    <?php if ($daftar === []): ?>
        <div class="empty">
            <span class="empty__ic"><i class="fa-solid fa-user-tie"></i></span>
            <p class="empty__title">Belum ada akun staf</p>
            <p class="empty__text">Tambahkan akun guru supaya mereka bisa masuk ke panel.</p>
            <button type="button" class="btn btn--primary btn--sm" data-buka="drawerAkun">
                <i class="fa-solid fa-user-plus"></i> Tambah Akun
            </button>
        </div>
    <?php else: ?>
        <div class="tablewrap">
            <table class="table table--cards">
                <thead>
                    <tr>
                        <th class="shrink">No</th>
                        <th>Nama</th>
                        <th>Username</th>
                        <th>Peran</th>
                        <th>Status</th>
                        <th>Login terakhir</th>
                        <th class="actions-cell">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($daftar as $i => $u): ?>
                        <?php $aktif = (int) $u['is_active'] === 1; ?>
                        <tr>
                            <td data-label="No" class="num-cell"><?= $i + 1 ?></td>
                            <td data-label="Nama">
                                <span class="celluser">
                                    <span class="avatar avatar--plain"><?= esc(mb_substr($u['nama_lengkap'], 0, 1)) ?></span>
                                    <span><?= esc($u['nama_lengkap']) ?></span>
                                </span>
                            </td>
                            <td data-label="Username"><code><?= esc($u['username']) ?></code></td>
                            <td data-label="Peran">
                                <?php if ($u['role'] === 'admin'): ?>
                                    <span class="badge badge--brand">Administrator</span>
                                <?php else: ?>
                                    <span class="badge">Guru</span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Status">
                                <?php if ($aktif): ?>
                                    <span class="badge badge--ok">Aktif</span>
                                <?php else: ?>
                                    <span class="badge badge--muted">Nonaktif</span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Login terakhir" class="small">
                                <?= esc($u['last_login'] ? admin_tgl_panjang(substr((string) $u['last_login'], 0, 10)) : 'Belum pernah') ?>
                            </td>
                            <td data-label="Aksi" class="actions-cell">
                                <div class="row">
                                    <a class="btn btn--ghost btn--xs" href="/admin/akun.php?edit=<?= (int) $u['id'] ?>#edit">
                                        <i class="fa-solid fa-pen"></i> Ubah
                                    </a>
                                    <form method="post" action="/admin/akun.php" style="display:inline">
                                        <input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">
                                        <input type="hidden" name="aksi" value="status">
                                        <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                                        <button class="btn btn--soft btn--xs" type="submit"
                                                <?= (int) $u['id'] === $adminId ? 'disabled title="Akun sendiri"' : '' ?>>
                                            <i class="fa-solid <?= $aktif ? 'fa-toggle-off' : 'fa-toggle-on' ?>"></i>
                                            <?= $aktif ? 'Nonaktifkan' : 'Aktifkan' ?>
                                        </button>
                                    </form>
                                    <form method="post" action="/admin/akun.php" style="display:inline"
                                          onsubmit="return confirm('Hapus akun <?= esc($u['username']) ?>?');">
                                        <input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">
                                        <input type="hidden" name="aksi" value="hapus">
                                        <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                                        <button class="btn btn--danger btn--xs" type="submit"
                                                <?= (int) $u['id'] === $adminId ? 'disabled title="Akun sendiri"' : '' ?>>
                                            <i class="fa-solid fa-trash"></i> Hapus
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- ==================================================================
     DRAWER: TAMBAH / UBAH
     ================================================================== -->
<div class="drawer" id="drawerAkun" role="dialog" aria-modal="true" aria-label="Form akun staf"
     data-autoload="<?= $bukaOtomatis ? '1' : '0' ?>" <?= $bukaOtomatis ? '' : 'hidden' ?>>
    <div class="drawer__scrim" data-tutup></div>
    <div class="drawer__panel">
        <div class="drawer__head">
            <div style="flex:1;min-width:0">
                <p class="drawer__title"><?= $edit ? 'Ubah Akun' : 'Tambah Akun' ?></p>
                <p class="drawer__sub"><?= $edit ? esc($edit['username']) : 'Buat akun login untuk guru / admin' ?></p>
            </div>
            <button type="button" class="iconbtn" data-tutup aria-label="Tutup">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="post" action="/admin/akun.php" class="drawer__body">
            <input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">
            <input type="hidden" name="aksi" value="simpan">
            <input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">

            <?php
            $f = $formulir ?? [
                'id' => $edit['id'] ?? 0,
                'username' => $edit['username'] ?? '',
                'nama_lengkap' => $edit['nama_lengkap'] ?? '',
                'role' => $edit['role'] ?? 'guru',
                'is_active' => $edit['is_active'] ?? 1,
                'galat' => [],
            ];
            $galat = $f['galat'] ?? [];
            $baru  = (int) $f['id'] === 0;
            ?>

            <div class="form">
                <div class="field<?= isset($galat['nama_lengkap']) ? ' field--error' : '' ?>">
                    <label class="field__label" for="aNama">Nama lengkap <span class="field__req">*</span></label>
                    <input type="text" id="aNama" name="nama_lengkap" required maxlength="100"
                           value="<?= esc($f['nama_lengkap']) ?>" placeholder="Nama lengkap guru" autofocus>
                    <?php if (isset($galat['nama_lengkap'])): ?>
                        <p class="field__err"><?= esc($galat['nama_lengkap']) ?></p>
                    <?php endif; ?>
                </div>

                <div class="form--grid">
                    <div class="field<?= isset($galat['username']) ? ' field--error' : '' ?>">
                        <label class="field__label" for="aUser">Username <span class="field__req">*</span></label>
                        <input type="text" id="aUser" name="username" required maxlength="32"
                               value="<?= esc($f['username']) ?>" placeholder="mis. budi.guru">
                        <?php if (isset($galat['username'])): ?>
                            <p class="field__err"><?= esc($galat['username']) ?></p>
                        <?php else: ?>
                            <p class="field__hint">3-32 karakter, untuk login.</p>
                        <?php endif; ?>
                    </div>

                    <div class="field<?= isset($galat['role']) ? ' field--error' : '' ?>">
                        <label class="field__label" for="aRole">Peran</label>
                        <select id="aRole" name="role">
                            <option value="guru"  <?= $f['role'] === 'guru' ? ' selected' : '' ?>>Guru</option>
                            <option value="admin" <?= $f['role'] === 'admin' ? ' selected' : '' ?>>Administrator</option>
                        </select>
                        <?php if (isset($galat['role'])): ?>
                            <p class="field__err"><?= esc($galat['role']) ?></p>
                        <?php else: ?>
                            <p class="field__hint">Guru tidak bisa ubah data master.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="form--grid">
                    <div class="field<?= isset($galat['password']) ? ' field--error' : '' ?>">
                        <label class="field__label" for="aPass">
                            Password <?= $baru ? '<span class="field__req">*</span>' : '' ?>
                        </label>
                        <input type="password" id="aPass" name="password" minlength="<?= PASSWORD_MIN_LENGTH ?>"
                               autocomplete="new-password" placeholder="<?= $baru ? 'Minimal ' . PASSWORD_MIN_LENGTH . ' karakter' : 'Kosongkan bila tidak diubah' ?>">
                        <?php if (isset($galat['password'])): ?>
                            <p class="field__err"><?= esc($galat['password']) ?></p>
                        <?php endif; ?>
                    </div>

                    <div class="field">
                        <label class="field__label" for="aUlang">Ulangi password</label>
                        <input type="password" id="aUlang" name="password_ulang"
                               autocomplete="new-password" placeholder="Sama dengan di atas">
                    </div>
                </div>

                <div class="switchrow">
                    <div class="switchrow__text">
                        <p class="switchrow__title">Akun aktif</p>
                        <p class="switchrow__hint">Nonaktif = tidak bisa masuk, tapi datanya tetap tersimpan.</p>
                    </div>
                    <label class="switch">
                        <input type="checkbox" name="is_active" value="1"
                            <?= (int) $f['is_active'] === 1 ? 'checked' : '' ?>>
                        <span class="switch__track"></span>
                    </label>
                </div>

                <button type="submit" class="btn btn--primary">
                    <i class="fa-solid fa-floppy-disk"></i> <?= $edit ? 'Simpan Perubahan' : 'Buat Akun' ?>
                </button>
            </div>
        </form>
    </div>
</div>

<?php require __DIR__ . '/../templates/admin-foot.php';
