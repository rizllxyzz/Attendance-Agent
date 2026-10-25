<?php
/**
 * admin/wajah.php
 * ---------------------------------------------------------------------------
 * Kelola Face ID siswa.
 *
 * Alurnya sengaja membagi tanggung jawab:
 *
 *   1. Admin MINTA siswa mendaftarkan ulang dengan membuat permintaan
 *      (face_requests, status "menunggu") dan, jika perlu, MENGHAPUS
 *      template wajah lama.
 *   2. Siswa yang benar-benar completing enrollment di perangkatnya sendiri
 *      lewat /register-face.php. Admin tidak pernah memegang descriptor wajah
 *      di server ini.
 *
 * Jadi yang bisa dilakukan di halaman ini: melihat siapa yang sudah punya
 * wajah, meminta pindaian ulang, membatalkan permintaan, dan menghapus
 * template yang membuat verifikasi face matching gagal terus-menerus.
 *
 * Halaman ini read-only untuk guru. Menghapus template wajah adalah tindakan
 * merusak keaslian identitas, jadi hanya admin.
 */

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_data.php';

/* Permintaan izin dan Face ID tinggal di dua tabel opsional. Kalau belum
 * ada, snuggal dibuat di sini; kalau tidak bisa, halaman tetap tampil dengan
 * fiturnya dimatikan, bukan jadi halaman putih. */
$tabelSiap = admin_tabel_pakai();

$pageKey   = 'wajah';
$pageTitle = 'Face ID';
$pageSub   = 'Status pendaftaran wajah siswa dan permintaan pindaian ulang';

/* ==================================================================
 *  PROSES POST
 * ================================================================== */

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_hanya_post();

    if (!$isAdmin) {
        admin_flash('error', 'Hanya administrator yang boleh mengelola Face ID.');
        admin_kembali();
    }

    if (!$tabelSiap) {
        admin_flash('error', 'Tabel permintaan Face ID belum siap. Periksa izin akses database.');
        admin_kembali();
    }

    $aksi = post('aksi', '');

    /* ---------- Minta pindaian ulang ----------
     *
     * Template lama langsung dihapus. Kalau tidak dihapus, siswa punya dua
     * set descriptor: yang lama dan yang baru, dan face-api akan membandingkan
     * wajah barunya dengan template yang sudah tidak berlaku. */
    if ($aksi === 'minta') {
        $idSiswa = (int) post('id', 0);
        $alasan  = trim((string) post('alasan', ''));

        $siswa = admin_siswa($idSiswa);
        if (!$siswa) {
            admin_flash('error', 'Siswa tidak ditemukan.');
            admin_kembali();
        }
        if ((int) $siswa['is_active'] !== 1) {
            admin_flash('error', 'Akun siswa nonaktif, tidak bisa diminta mendaftarkan wajah.');
            admin_kembali();
        }

        /* Jangan menumpuk permintaan: satu permintaan menunggu sudah cukup
         * sebagai sinyal ke siswa. */
        $menunggu = db_one(
            "SELECT id FROM face_requests WHERE user_id = ? AND status = 'menunggu' ORDER BY id DESC LIMIT 1",
            [$idSiswa],
            null
        );
        if ($menunggu) {
            admin_flash('warn', 'Siswa ini sudah punya permintaan yang menunggu. Tidak dibuat yang baru.');
            admin_kembali();
        }

        $hapus = db_exec('DELETE FROM face_templates WHERE user_id = ?', [$idSiswa]);
        $baruId = db_insert(
            "INSERT INTO face_requests (user_id, alasan, status, diminta_oleh)
             VALUES (?, ?, 'menunggu', ?)",
            [$idSiswa, $alasan !== '' ? mb_substr($alasan, 0, 300) : null, $adminId]
        );

        audit_log($adminId, 'admin.wajah.minta', 'warning',
            'Minta pindaian wajah ' . $siswa['nama_lengkap']
            . ' (' . $siswa['username'] . '), template lama dihapus: ' . $hapus
            . ($alasan !== '' ? ' — ' . mb_substr($alasan, 0, 200) : ''));

        admin_flash('ok', 'Permintaan pindaian wajah untuk ' . $siswa['nama_lengkap']
            . ' dibuat. Siswa akan melihat permintaannya saat membuka halaman Face ID.');
        admin_kembali();
    }

    /* ---------- Batalkan permintaan ---------- */
    if ($aksi === 'batal') {
        $idReq = (int) post('id', 0);
        $req = db_one(
            'SELECT fr.id, fr.user_id, fr.status, s.nama_lengkap, s.username
               FROM face_requests fr
               JOIN users s ON s.id = fr.user_id
              WHERE fr.id = ?',
            [$idReq],
            null
        );
        if (!$req) {
            admin_flash('error', 'Permintaan tidak ditemukan.');
            admin_kembali();
        }
        if ($req['status'] !== 'menunggu') {
            admin_flash('warn', 'Permintaan ini sudah berstatus ' . $req['status'] . '. Tidak diubah.');
            admin_kembali();
        }

        $baru = db_exec(
            "UPDATE face_requests SET status = 'batal' WHERE id = ? AND status = 'menunggu'",
            [$idReq]
        );
        if ($baru < 1) {
            admin_flash('warn', 'Permintaan sudah diproses. Tidak ada yang diubah.');
        } else {
            audit_log($adminId, 'admin.wajah.batal', 'info',
                'Batalkan permintaan pindaian wajah #' . $idReq . ' (' . $req['nama_lengkap'] . ')');
            admin_flash('ok', 'Permintaan ' . $req['nama_lengkap'] . ' dibatalkan.');
        }
        admin_kembali();
    }

    /* ---------- Hapus template wajah saja ---------- */
    if ($aksi === 'hapus') {
        $idSiswa = (int) post('id', 0);
        $siswa = admin_siswa($idSiswa);
        if (!$siswa) {
            admin_flash('error', 'Siswa tidak ditemukan.');
            admin_kembali();
        }
        $jumlah = (int) db_value('SELECT COUNT(*) FROM face_templates WHERE user_id = ?', [$idSiswa], 0);
        if ($jumlah < 1) {
            admin_flash('warn', 'Siswa ini memang belum punya template wajah.');
            admin_kembali();
        }

        db_exec('DELETE FROM face_templates WHERE user_id = ?', [$idSiswa]);
        audit_log($adminId, 'admin.wajah.hapus', 'warning',
            'Hapus ' . $jumlah . ' template wajah ' . $siswa['nama_lengkap']
            . ' (' . $siswa['username'] . '). Siswa harus daftar ulang sebelum bisa absen pakai Face ID.');

        admin_flash('ok', $jumlah . ' template wajah ' . $siswa['nama_lengkap']
            . ' dihapus. Minta pindaian ulang supaya siswa bisa daftar lagi.');
        admin_kembali();
    }

    /* ---------- Ekspor daftar ----------
     *
     * Filter dikirim sebagai hidden field POST, bukan query string, jadi
     * harus dibaca dari POST. Kalau hanya membaca GET, unduhan selalu berisi
     * semua siswa dan filter di layar seperti tidak berlaku. */
    if ($aksi === 'csv') {
        $cariWajah = trim((string) post('cari', ''));
        $filterWajah = (string) post('filter', 'semua');
        if (!in_array($filterWajah, ['semua', 'terdaftar', 'belum'], true)) {
            $filterWajah = 'semua';
        }
        $kepala = ['Nama', 'Username', 'Kelas', 'Status', 'Terdaftar sejak', 'Jumlah sampel', 'Mutu'];
        $baris = [];
        foreach (admin_daftar_wajah($cariWajah, $filterWajah) as $w) {
            $baris[] = [
                $w['nama_lengkap'],
                $w['username'],
                $w['nama_kelas'] ?? 'Tanpa kelas',
                !empty($w['sejak']) ? 'Terdaftar' : 'Belum',
                $w['sejak'] ?? '',
                (int) $w['jumlah_sampel'],
                $w['quality'] !== null ? round((float) $w['quality'], 3) : '',
            ];
        }
        if ($baris === []) {
            $baris[] = ['Tidak ada siswa yang cocok filter', '', '', '', '', '', ''];
        }
        admin_csv($baris, $kepala, 'face-id');
    }
}

/* ==================================================================
 *  QUERY
 * ================================================================== */

$cari   = trim((string) get('cari', ''));
$filter = (string) get('filter', 'semua');
if (!in_array($filter, ['semua', 'terdaftar', 'belum'], true)) {
    $filter = 'semua';
}
$tabPilih = (string) get('tab', 'siswa');
if (!in_array($tabPilih, ['siswa', 'permintaan'], true)) {
    $tabPilih = 'siswa';
}

$halaman    = max(1, (int) get('halaman', 1));
$perHalaman = ROWS_PER_PAGE;
$statistik  = admin_statistik_wajah();

$daftar = [];
$jumlahTotal = 0;
$totalHal = 1;
$permintaan = [];

/* Jumlah permintaan dihitung di semua tab: KPI dan tombol di header
 * harus jujur meskipun admin sedang melihat tab siswa. */
$jumlahPermintaan = $tabelSiap
    ? (int) db_value("SELECT COUNT(*) FROM face_requests WHERE status = 'menunggu'", [], 0)
    : 0;

if ($tabPilih === 'permintaan') {
    if ($tabelSiap) {
        $totalHal = max(1, (int) ceil($jumlahPermintaan / $perHalaman));
        $halaman  = min($halaman, $totalHal);
        $permintaan = db_all(
            "SELECT fr.id, fr.user_id, fr.alasan, fr.status, fr.created_at, fr.diminta_oleh,
                    s.nama_lengkap, s.username, s.is_active, k.nama_kelas,
                    d.nama_lengkap AS diminta_nama
               FROM face_requests fr
               JOIN users s ON s.id = fr.user_id
          LEFT JOIN kelas k ON k.id = s.kelas_id
          LEFT JOIN users d ON d.id = fr.diminta_oleh
              WHERE fr.status = 'menunggu'
           ORDER BY fr.created_at ASC, fr.id ASC
              LIMIT " . $perHalaman . ' OFFSET ' . (($halaman - 1) * $perHalaman)
        );
    }
} else {
    $jumlahTotal = admin_jumlah_daftar_wajah($cari, $filter);
    $totalHal    = max(1, (int) ceil($jumlahTotal / $perHalaman));
    $halaman     = min($halaman, $totalHal);
    $daftar      = admin_daftar_wajah($cari, $filter, $perHalaman, ($halaman - 1) * $perHalaman);
}

$tanpaWajah = admin_siswa_belum_wajah(6);

$pageActionsHtml = $isAdmin
    ? '<a class="btn btn--soft" href="/admin/wajah.php?tab=permintaan">'
        . '<i class="fa-solid fa-bell"></i> Permintaan <span class="pill-num">'
        . $jumlahPermintaan . '</span></a>'
    : '';

$fabHref  = '';
$fabLabel = '';
$fabIcon  = 'fa-id-card';

require __DIR__ . '/../templates/admin-head.php';
?>

<?php if (!$tabelSiap): ?>
<div class="note note--error mb-2">
    <i class="fa-solid fa-triangle-exclamation note__ic"></i>
    <div class="note__body">
        <strong>Tabel permintaan Face ID belum bisa dibuat.</strong>
        Halaman ini masih bisa menampilkan status pendaftaran wajah, tapi
        permintaan pindaian ulang tidak bisa disimpan. Periksa hak akses
        database, lalu muat ulang halaman ini.
    </div>
</div>
<?php endif; ?>

<!-- ==================================================================
     KPI
     ================================================================== -->
<div class="grid grid--kpi mb-2">
    <div class="kpi kpi--slim kpi--brand">
        <p class="kpi__label" style="margin:0">Siswa aktif</p>
        <p class="kpi__value"><?= (int) $statistik['siswa'] ?></p>
        <p class="kpi__hint">penyebut utama</p>
    </div>
    <div class="kpi kpi--slim kpi--ok">
        <p class="kpi__label" style="margin:0">Wajah terdaftar</p>
        <p class="kpi__value"><?= (int) $statistik['terdaftar'] ?></p>
        <p class="kpi__hint"><?= (int) $statistik['persen'] ?>% dari siswa aktif</p>
    </div>
    <div class="kpi kpi--slim kpi--<?= (int) $statistik['belum'] > 0 ? 'warn' : 'muted' ?>">
        <p class="kpi__label" style="margin:0">Belum terdaftar</p>
        <p class="kpi__value"><?= (int) $statistik['belum'] ?></p>
        <p class="kpi__hint">belum bisa absen pakai Face ID</p>
    </div>
    <div class="kpi kpi--slim kpi--info">
        <p class="kpi__label" style="margin:0">Mutu rata-rata</p>
        <p class="kpi__value">
            <?= $statistik['mutu'] === null ? '-' : (int) round((float) $statistik['mutu'] * 100) . '%' ?>
        </p>
        <p class="kpi__hint">dari template centroid</p>
    </div>
    <?php if ($tabelSiap): ?>
    <div class="kpi kpi--slim kpi--<?= $jumlahPermintaan > 0 ? 'warn' : 'muted' ?>">
        <p class="kpi__label" style="margin:0">Menunggu daftar ulang</p>
        <p class="kpi__value"><?= $jumlahPermintaan ?></p>
        <p class="kpi__hint">permintaan dari siswa</p>
    </div>
    <?php endif; ?>
</div>

<div class="card">
    <div class="tabs">
        <a class="tab<?= $tabPilih === 'siswa' ? ' is-on' : '' ?>"
           href="/admin/wajah.php?tab=siswa">Siswa</a>
        <?php if ($tabelSiap): ?>
            <a class="tab<?= $tabPilih === 'permintaan' ? ' is-on' : '' ?>"
               href="/admin/wajah.php?tab=permintaan">
                Permintaan <span class="tab__count"><?= $jumlahPermintaan ?></span>
            </a>
        <?php endif; ?>
    </div>

    <?php if ($tabPilih === 'permintaan'): ?>
        <div class="card__head">
            <div>
                <p class="card__title">Menunggu pendaftaran ulang</p>
                <p class="card__sub">
                    <?= $jumlahPermintaan ?> permintaan dibuat admin. Siswa yang menyelesaikannya
                    akan menutup permintaannya sendiri dari perangkatnya.
                </p>
            </div>
        </div>

        <?php if ($permintaan === []): ?>
            <div class="empty">
                <span class="empty__ic"><i class="fa-solid fa-circle-check"></i></span>
                <p class="empty__title">Tidak ada permintaan menunggu</p>
                <p class="empty__text">
                    Semua permintaan pindaian wajah sudah selesai atau dibatalkan.
                </p>
            </div>
        <?php else: ?>
            <div class="tablewrap">
                <table class="table table--cards">
                    <thead>
                        <tr>
                            <th class="shrink">No</th>
                            <th>Siswa</th>
                            <th>Alasan</th>
                            <th>Diminta</th>
                            <?php if ($isAdmin): ?><th class="actions-cell">Aksi</th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($permintaan as $i => $r): ?>
                        <tr>
                            <td data-label="No" class="num-cell muted"><?= ($halaman - 1) * $perHalaman + $i + 1 ?></td>
                            <td data-label="Siswa">
                                <div class="celluser">
                                    <span class="avatar avatar--sm avatar--plain avatar--warn">
                                        <?= esc(mb_strtoupper(mb_substr((string) $r['nama_lengkap'], 0, 1))) ?>
                                    </span>
                                    <span class="celluser__text">
                                        <span class="celluser__name"><?= esc($r['nama_lengkap']) ?></span>
                                        <span class="celluser__meta">
                                            <?= esc($r['nama_kelas'] ?? 'Tanpa kelas') ?>
                                            <?php if ((int) $r['is_active'] !== 1): ?>
                                                &middot; <span class="danger-text">nonaktif</span>
                                            <?php endif; ?>
                                        </span>
                                    </span>
                                </div>
                            </td>
                            <td data-label="Alasan" class="small"><?= esc($r['alasan'] ?: '-') ?></td>
                            <td data-label="Diminta" class="small">
                                <?= esc(admin_lampau((string) $r['created_at'])) ?>
                                <div class="tiny muted">
                                    oleh <?= esc($r['diminta_nama'] ?? 'sistem') ?>
                                </div>
                            </td>
                            <?php if ($isAdmin): ?>
                                <td data-label="" class="actions-cell">
                                    <form method="post" action="/admin/wajah.php" style="display:contents"
                                          onsubmit="return confirm('Batalkan permintaan pindaian wajah ini?');">
                                        <input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">
                                        <input type="hidden" name="aksi" value="batal">
                                        <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                        <button type="submit" class="iconbtn" title="Batalkan permintaan"
                                                aria-label="Batalkan permintaan <?= esc($r['nama_lengkap']) ?>">
                                            <i class="fa-solid fa-ban"></i>
                                        </button>
                                    </form>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?= admin_pager($jumlahPermintaan, $perHalaman, $halaman, 'permintaan') ?>
        <?php endif; ?>

    <?php else: ?>
        <div class="card__head">
            <div>
                <p class="card__title">Status wajah siswa</p>
                <p class="card__sub"><?= $jumlahTotal ?> siswa cocok dengan filter</p>
            </div>
        </div>

        <!-- FILTER -->
        <form class="filterbar" method="get" action="/admin/wajah.php">
            <input type="hidden" name="tab" value="siswa">
            <div class="field" style="min-width:180px">
                <label class="field__label" for="fCari">Cari</label>
                <input type="search" id="fCari" name="cari" value="<?= esc($cari) ?>"
                       placeholder="nama, username, atau NIS">
            </div>
            <div class="field" style="min-width:160px">
                <label class="field__label" for="fFilter">Status</label>
                <select id="fFilter" name="filter" onchange="this.form.submit()">
                    <option value="semua"<?= $filter === 'semua' ? ' selected' : '' ?>>Semua</option>
                    <option value="terdaftar"<?= $filter === 'terdaftar' ? ' selected' : '' ?>>Sudah terdaftar</option>
                    <option value="belum"<?= $filter === 'belum' ? ' selected' : '' ?>>Belum terdaftar</option>
                </select>
            </div>
            <button type="submit" class="btn btn--soft btn--sm">
                <i class="fa-solid fa-magnifying-glass"></i> Tampilkan
            </button>
        </form>

        <?php if ($isAdmin): ?>
            <form method="post" action="/admin/wajah.php" class="inline-form">
                <input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">
                <input type="hidden" name="aksi" value="csv">
                <input type="hidden" name="cari" value="<?= esc($cari) ?>">
                <input type="hidden" name="filter" value="<?= esc($filter) ?>">
                <button type="submit" class="btn btn--ghost btn--sm">
                    <i class="fa-solid fa-file-arrow-down"></i> <span class="only-desktop">Unduh CSV</span>
                </button>
            </form>
        <?php endif; ?>

        <?php if ($daftar === []): ?>
            <div class="empty">
                <span class="empty__ic"><i class="fa-solid fa-face-smile"></i></span>
                <p class="empty__title">Tidak ada siswa yang cocok</p>
                <p class="empty__text">
                    Ubah kata kunci atau filter status untuk melihat siswa lain.
                </p>
            </div>
        <?php else: ?>
            <div class="tablewrap">
                <table class="table table--cards">
                    <thead>
                        <tr>
                            <th class="shrink">No</th>
                            <th>Siswa</th>
                            <th>Status</th>
                            <th>Terdaftar sejak</th>
                            <th>Mutu</th>
                            <?php if ($isAdmin): ?><th class="actions-cell">Aksi</th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($daftar as $i => $w): ?>
                        <?php
                        $terdaftar = !empty($w['sejak']);
                        $mutu     = $w['quality'] !== null ? (float) $w['quality'] : null;
                        $mutuPersen = $mutu === null ? null : (int) round($mutu * 100);
                        $mutuOk   = $mutuPersen !== null && $mutuPersen >= 60;
                        ?>
                        <tr>
                            <td data-label="No" class="num-cell muted"><?= ($halaman - 1) * $perHalaman + $i + 1 ?></td>
                            <td data-label="Siswa">
                                <div class="celluser">
                                    <span class="avatar avatar--sm avatar--plain">
                                        <?= esc(mb_strtoupper(mb_substr((string) $w['nama_lengkap'], 0, 1))) ?>
                                    </span>
                                    <span class="celluser__text">
                                        <span class="celluser__name"><?= esc($w['nama_lengkap']) ?></span>
                                        <span class="celluser__meta">
                                            <?= esc($w['nama_kelas'] ?? 'Tanpa kelas') ?>
                                            &middot; <?= esc($w['username']) ?>
                                        </span>
                                    </span>
                                </div>
                            </td>
                            <td data-label="Status">
                                <?php if ($terdaftar): ?>
                                    <span class="badge badge--ok"><i class="dot"></i>Terdaftar</span>
                                    <div class="tiny muted"><?= (int) $w['jumlah_sampel'] ?> sampel</div>
                                <?php else: ?>
                                    <span class="badge badge--warn"><i class="dot"></i>Belum</span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Terdaftar sejak" class="small">
                                <?= $terdaftar
                                    ? esc(admin_tgl_pendek((string) $w['sejak']))
                                    : '<span class="muted">-</span>' ?>
                            </td>
                            <td data-label="Mutu" class="num-cell">
                                <?php if ($mutuPersen === null): ?>
                                    <span class="muted">-</span>
                                <?php else: ?>
                                    <span class="<?= $mutuOk ? 'strong' : 'warn-text' ?>"><?= $mutuPersen ?>%</span>
                                <?php endif; ?>
                            </td>
                            <?php if ($isAdmin): ?>
                                <td data-label="" class="actions-cell">
                                    <div class="row row--end" style="gap:6px;flex-wrap:nowrap">
                                        <button type="button" class="iconbtn"
                                                data-minta="<?= (int) $w['id'] ?>"
                                                data-nama="<?= esc($w['nama_lengkap']) ?>"
                                                data-terdaftar="<?= $terdaftar ? '1' : '0' ?>"
                                                title="Minta daftar ulang"
                                                aria-label="Minta daftar ulang wajah <?= esc($w['nama_lengkap']) ?>">
                                            <i class="fa-solid fa-rotate"></i>
                                        </button>
                                        <?php if ($terdaftar): ?>
                                            <form method="post" action="/admin/wajah.php" style="display:contents"
                                                  onsubmit="return confirm('Hapus template wajah <?= esc($w['nama_lengkap']) ?>?\n\nSiswa harus daftar ulang sebelum bisa absen pakai Face ID.');">
                                                <input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">
                                                <input type="hidden" name="aksi" value="hapus">
                                                <input type="hidden" name="id" value="<?= (int) $w['id'] ?>">
                                                <button type="submit" class="iconbtn iconbtn--danger"
                                                        title="Hapus template wajah"
                                                        aria-label="Hapus template wajah <?= esc($w['nama_lengkap']) ?>">
                                                    <i class="fa-solid fa-trash-can"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?= admin_pager($jumlahTotal, $perHalaman, $halaman, 'siswa') ?>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php if ($tanpaWajah !== []): ?>
<div class="grid grid--2 mt-2">
    <div class="card">
        <div class="card__head">
            <div>
                <p class="card__title">Siswa yang perlu didaftarkan</p>
                <p class="card__sub">Belum punya template wajah sama sekali</p>
            </div>
        </div>
        <div class="card__body">
            <ul class="bullets">
                <?php foreach ($tanpaWajah as $s): ?>
                    <li>
                        <a href="/admin/wajah.php?cari=<?= esc(urlencode((string) $s['username'])) ?>&filter=belum">
                            <?= esc($s['nama_lengkap']) ?>
                        </a>
                        <span class="muted"><?= esc($s['nama_kelas'] ?? 'Tanpa kelas') ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>

    <div class="card">
        <div class="card__head">
            <div>
                <p class="card__title">Cara kerja Face ID di sini</p>
                <p class="card__sub">Supaya tidak salah paham</p>
            </div>
        </div>
        <div class="card__body">
            <ul class="bullets">
                <li>Admin <strong>minta</strong>, siswa yang <strong>mendaftarkan</strong>.</li>
                <li>Template wajah siswa diambil di perangkatnya sendiri, dari kamera depannya.</li>
                <li>Minta daftar ulang akan <strong>menghapus</strong> template lama supaya tidak bentrok.</li>
                <li>Setelah completion, siswa absen seperti biasa; Face ID hanya strengthening verifikasi.</li>
                <li>Akun nonaktif tidak bisa diminta mendaftarkan ulang wajah dari halaman ini.</li>
            </ul>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($isAdmin): ?>
<!-- ==================================================================
     MODAL MINTA DAFTAR ULANG
     ================================================================== -->
<div class="modal" id="modalMinta" role="dialog" aria-modal="true"
     aria-labelledby="modalMintaTitle" hidden>
    <div class="modal__scrim" data-tutup></div>
    <div class="modal__panel">
        <form method="post" action="/admin/wajah.php">
            <input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">
            <input type="hidden" name="aksi" value="minta">
            <input type="hidden" name="id" id="mintaId">

            <h2 class="modal__title" id="modalMintaTitle">Minta daftar ulang wajah</h2>
            <p class="modal__body">
                Permintaan dikirim ke <strong id="mintaNama">-</strong>. Siswa melihatnya
                saat membuka halaman Face ID di portalnya.
                <span class="note note--flat" id="mintaPeringatan" hidden>
                    <i class="fa-solid fa-triangle-exclamation note__ic"></i>
                    <div class="note__body">
                        Template wajah yang sekarang akan <strong>dihapus</strong> supaya
                        enrollment baru tidak bentrok dengan descriptor lama.
                    </div>
                </span>
            </p>

            <div class="field mt-2">
                <label class="field__label" for="mintaAlasan">Alasan (opsional)</label>
                <input type="text" id="mintaAlasan" name="alasan" maxlength="300"
                       placeholder="contoh: verifikasi sering gagal, ganti kamera">
                <p class="field__hint">Tercatat di jejak aktivitas dan dilihat siswa.</p>
            </div>

            <div class="modal__actions">
                <button type="button" class="btn btn--ghost" data-tutup>Batal</button>
                <button type="submit" class="btn btn--primary">
                    <i class="fa-solid fa-paper-plane"></i> Kirim Permintaan
                </button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    var modal = document.getElementById('modalMinta');
    if (!modal) { return; }
    var fId    = document.getElementById('mintaId');
    var fNama  = document.getElementById('mintaNama');
    var fAlasan= document.getElementById('mintaAlasan');
    var fPering= document.getElementById('mintaPeringatan');

    document.querySelectorAll('[data-minta]').forEach(function (b) {
        b.addEventListener('click', function () {
            if (fId) { fId.value = b.dataset.minta; }
            if (fNama) { fNama.textContent = b.dataset.nama || '-'; }
            if (fAlasan) { fAlasan.value = ''; }
            /* Peringatan hanya relevan kalau ada template yang akan dihapus. */
            if (fPering) { fPering.hidden = b.dataset.terdaftar !== '1'; }
            modal.hidden = false;
            document.body.classList.add('is-locked');
            if (fAlasan) { fAlasan.focus(); }
        });
    });
})();
</script>
<?php endif; ?>

<?php require __DIR__ . '/../templates/admin-foot.php';
