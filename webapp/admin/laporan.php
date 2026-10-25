<?php
/**
 * admin/laporan.php
 * ---------------------------------------------------------------------------
 * Panel guru BK untuk menindaklanjuti laporan "Lapor Aman".
 *
 * ATURAN YANG DIPEGANG HALAMAN INI
 * --------------------------------
 *  1. Pelapor anonim tetap anonim. Tabel tidak menyimpan akun, IP, atau
 *     jejak apa pun yang bisa membongkar identitasnya. Halaman ini karena
 *     itu TIDAK pernah menampilkan "siapa yang mengirim" untuk laporan
 *     anonim, bahkan kepada admin, karena datanya memang tidak ada.
 *  2. Siapa pun yang bisa membuka halaman ini bisa membaca seluruh isi
 *     laporan. Panel admin hanya dikunci untuk admin dan guru, jadi
 *     pembatasan lebih ketat (khusus guru BK) belum bisa ditegakkan oleh
 *     sistem peran yang ada. Hal ini diingatkan lewat catatan di halaman.
 *  3. Catatan guru BK bisa dibaca pelapor lewat kode akses, jadi harus
 *     ditulis dengan bahasa yang menenangkan, bukan catatan internal.
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$pageKey   = 'laporan';
$pageTitle = 'Lapor Aman';
$pageSub   = 'Tindak lanjuti laporan siswa soal perundungan, keluarga, dan tekanan emosi';

$tabelSiap = laporan_pastikan_tabel();

/* ==================================================================
 *  PROSES POST
 * ================================================================== */

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_hanya_post();

    if (!$tabelSiap) {
        admin_flash('error', 'Tabel laporan belum siap. Muat ulang halaman.');
        admin_kembali();
    }

    $aksi = post('aksi', '');

    if ($aksi === 'simpan') {
        $id      = (int) post('id', 0);
        $status  = post('status', 'baru');
        $catatan = trim((string) post('catatan', ''));

        if (!in_array($status, ['baru', 'diproses', 'selesai'], true)) {
            admin_flash('error', 'Status tidak dikenal.');
            admin_kembali();
        }
        if (mb_strlen($catatan) > 500) {
            $catatan = mb_substr($catatan, 0, 500);
        }

        $laporan = db_one(
            'SELECT id, kode_akses, status FROM laporan_aman WHERE id = ? LIMIT 1',
            [$id],
            null
        );
        if (!$laporan) {
            admin_flash('error', 'Laporan tidak ditemukan.');
            admin_kembali();
        }

        /* ditangani_at diisi saat pertama kali disentuh, dan tidak pernah
         * direset ke kosong walau status dikembalikan menjadi "baru".
         * Jejak "kapan ditangani" itu untuk arsip BK, bukan status saat ini. */
        $ditanganiAt = $status === 'baru' ? null : date('Y-m-d H:i:s');

        db_exec(
            'UPDATE laporan_aman
                SET status = ?, catatan_bk = ?, ditangani_oleh = ?,
                    ditangani_at = COALESCE(?, ditangani_at)
              WHERE id = ?',
            [$status, $catatan !== '' ? $catatan : null, $adminId, $ditanganiAt, $id]
        );

        audit_log(
            $adminId,
            'admin.laporan.' . $status,
            'info',
            'Laporan ' . $laporan['kode_akses'] . ' -> status ' . $status . '.'
        );

        admin_flash('ok', 'Laporan ' . $laporan['kode_akses'] . ' diperbarui.');
        admin_kembali();
    }
}

/* ==================================================================
 *  FILTER
 * ================================================================== */

$statusPilih = get('status', 'baru');
$statusList  = ['baru', 'diproses', 'selesai', 'semua'];
if (!in_array($statusPilih, $statusList, true)) {
    $statusPilih = 'baru';
}

$kategoriPilih = get('kategori', '');
if ($kategoriPilih !== '' && !array_key_exists($kategoriPilih, laporan_kategori())) {
    $kategoriPilih = '';
}
$urgensiPilih = get('urgensi', '');
if (!in_array($urgensiPilih, ['biasa', 'perhatian', 'darurat'], true)) {
    $urgensiPilih = '';
}
$cari     = trim((string) get('cari', ''));
$halaman  = max(1, (int) get('halaman', 1));
$perHal   = ROWS_PER_PAGE;

/* Bangun WHERE sekali, dipakai untuk daftar maupun hitungan. */
$where  = ['1 = 1'];
$args   = [];
if ($statusPilih !== 'semua') {
    $where[] = 'l.status = ?';
    $args[]  = $statusPilih;
}
if ($kategoriPilih !== '') {
    $where[] = 'l.kategori = ?';
    $args[]  = $kategoriPilih;
}
if ($urgensiPilih !== '') {
    $where[] = 'l.urgensi = ?';
    $args[]  = $urgensiPilih;
}
if ($cari !== '') {
    $where[] = '(l.kode_akses LIKE ? OR l.isi LIKE ? OR u.nama_lengkap LIKE ? OR u.nis LIKE ?)';
    $needle  = '%' . $cari . '%';
    array_push($args, $needle, $needle, $needle, $needle);
}
$syarat = implode(' AND ', $where);

$jumlahHal = max(1, (int) ceil(
    (int) db_value(
        'SELECT COUNT(*) FROM laporan_aman l
           LEFT JOIN users u ON u.id = l.user_id
          WHERE ' . $syarat,
        $args,
        0
    ) / $perHal
));
$halaman = min($halaman, $jumlahHal);
$offset  = ($halaman - 1) * $perHal;

$daftar = $tabelSiap ? db_all(
    'SELECT l.id, l.kode_akses, l.anonim, l.kategori, l.urgensi, l.isi, l.status,
            l.catatan_bk, l.created_at, l.ditangani_at,
            u.nama_lengkap, u.nis, k.nama_kelas, p.nama_lengkap AS petugas
       FROM laporan_aman l
       LEFT JOIN users u ON u.id = l.user_id
       LEFT JOIN kelas k ON k.id = u.kelas_id
       LEFT JOIN users p ON p.id = l.ditangani_oleh
      WHERE ' . $syarat . '
      ORDER BY CASE l.urgensi WHEN \'darurat\' THEN 0 WHEN \'perhatian\' THEN 1 ELSE 2 END ASC,
               CASE l.status WHEN \'baru\' THEN 0 WHEN \'diproses\' THEN 1 ELSE 2 END ASC,
               l.id DESC
      LIMIT ' . (int) $perHal . ' OFFSET ' . (int) $offset,
    $args
) : [];

$jumlahFilter = $tabelSiap ? (int) db_value(
    'SELECT COUNT(*) FROM laporan_aman l
       LEFT JOIN users u ON u.id = l.user_id
      WHERE ' . $syarat,
    $args,
    0
) : 0;

/* Angka ringkas (selalu seluruh data, bukan hasil filter, supaya petugas
 * tahu beban kerja nyata, bukan yang kebetulan sedang ditampilkan). */
$stat = ['baru' => 0, 'diproses' => 0, 'selesai' => 0, 'darurat' => 0, 'perhatian' => 0];
if ($tabelSiap) {
    foreach (db_all('SELECT status, COUNT(*) AS n FROM laporan_aman GROUP BY status') as $b) {
        $stat[(string) $b['status']] = (int) $b['n'];
    }
    $stat['darurat'] = (int) db_value(
        "SELECT COUNT(*) FROM laporan_aman WHERE urgensi = 'darurat' AND status <> 'selesai'", [], 0);
    $stat['perhatian'] = (int) db_value(
        "SELECT COUNT(*) FROM laporan_aman WHERE urgensi = 'perhatian' AND status <> 'selesai'", [], 0);
}

$totalSemua = $tabelSiap
    ? (int) db_value('SELECT COUNT(*) FROM laporan_aman', [], 0)
    : 0;

$headStats = [
    ['label' => 'Laporan baru',     'value' => number_format($stat['baru']),      'tone' => $stat['baru'] > 0 ? 'warn' : 'muted', 'hint' => 'belum disentuh'],
    ['label' => 'Perlu segera',     'value' => number_format($stat['darurat']),   'tone' => $stat['darurat'] > 0 ? 'brand' : 'muted', 'hint' => 'perkiraan darurat'],
    ['label' => 'Perlu perhatian',  'value' => number_format($stat['perhatian']), 'tone' => 'info', 'hint' => 'perkiraan'],
    ['label' => 'Total laporan',    'value' => number_format($totalSemua),        'tone' => 'muted', 'hint' => 'sejak fitur aktif'],
];

$fabHref = '';

require __DIR__ . '/../templates/admin-head.php';
?>

<?php if (!$tabelSiap): ?>
    <div class="card">
        <div class="empty">
            <span class="empty__ic"><i class="fa-solid fa-triangle-exclamation"></i></span>
            <p class="empty__title">Tabel laporan belum bisa dipakai</p>
            <p class="empty__text">
                Tabel <code>laporan_aman</code> tidak berhasil disiapkan.
                Biasanya tabel ini dibuat otomatis saat siswa pertama membuka
                halaman Lapor Aman. Cek izin tulis berkas database lalu muat ulang.
            </p>
        </div>
    </div>
<?php else: ?>

<!-- ==================================================================
     PERINGATAN PRIVASI
     ================================================================== -->
<div class="note note--flat mb-2">
    <i class="fa-solid fa-user-shield note__ic"></i>
    <div class="note__body">
        Halaman ini memuat laporan pribadi siswa. Buka hanya bila perlu, dan
        sebaiknya diakses guru BK. Laporan <strong>anonim</strong> memang tidak
        memiliki identitas pelapor di sistem: itu bukan data yang disembunyikan,
        melainkan data yang tidak pernah direkam.
    </div>
</div>

<!-- ==================================================================
     TAB STATUS
     ================================================================== -->
<div class="tabs mb-2">
    <?php
    $labelStatus = [
        'baru'     => 'Baru',
        'diproses' => 'Sedang ditangani',
        'selesai'  => 'Selesai',
        'semua'    => 'Semua',
    ];
    foreach ($statusList as $s):
        $n = $s === 'semua' ? $totalSemua : (int) ($stat[$s] ?? 0);
    ?>
        <a class="tab<?= $statusPilih === $s ? ' is-on' : '' ?>"
           href="<?= esc(admin_ubah_query(['status' => $s, 'halaman' => 1])) ?>">
            <?= esc($labelStatus[$s]) ?>
            <span class="tab__count"><?= $n ?></span>
        </a>
    <?php endforeach; ?>
</div>

<!-- ==================================================================
     FILTER
     ================================================================== -->
<div class="card mb-2">
    <form class="filterbar" method="get" action="/admin/laporan.php">
        <input type="hidden" name="status" value="<?= esc($statusPilih) ?>">

        <div class="field" style="min-width:160px">
            <label class="field__label" for="fKategori">Kategori</label>
            <select id="fKategori" name="kategori" onchange="this.form.submit()">
                <option value="">Semua kategori</option>
                <?php foreach (laporan_kategori() as $kunci => $label): ?>
                    <option value="<?= esc($kunci) ?>"<?= $kategoriPilih === $kunci ? ' selected' : '' ?>>
                        <?= esc($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field" style="min-width:150px">
            <label class="field__label" for="fUrgensi">Urgensi</label>
            <select id="fUrgensi" name="urgensi" onchange="this.form.submit()">
                <option value="">Semua urgensi</option>
                <?php foreach (['darurat', 'perhatian', 'biasa'] as $u): ?>
                    <option value="<?= esc($u) ?>"<?= $urgensiPilih === $u ? ' selected' : '' ?>>
                        <?= esc(laporan_urgensi_label($u)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field field--grow">
            <label class="field__label" for="fCari">Cari</label>
            <div class="search">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="search" id="fCari" name="cari" value="<?= esc($cari) ?>"
                       placeholder="Kode, isi laporan, atau nama (bila tidak anonim)">
            </div>
        </div>

        <button type="submit" class="btn btn--soft btn--sm">
            <i class="fa-solid fa-filter"></i> Terapkan
        </button>
        <?php if ($cari !== '' || $kategoriPilih !== '' || $urgensiPilih !== ''): ?>
            <a class="btn btn--ghost btn--sm"
               href="/admin/laporan.php?status=<?= esc($statusPilih) ?>">
                <i class="fa-solid fa-xmark"></i> Bersihkan
            </a>
        <?php endif; ?>
    </form>
</div>

<!-- ==================================================================
     DAFTAR
     ================================================================== -->
<?php if ($daftar === []): ?>
    <div class="card">
        <div class="empty">
            <span class="empty__ic">
                <i class="fa-solid <?= $statusPilih === 'baru' ? 'fa-circle-check' : 'fa-inbox' ?>"></i>
            </span>
            <p class="empty__title">
                <?= $statusPilih === 'baru' ? 'Tidak ada laporan baru' : 'Tidak ada laporan' ?>
            </p>
            <p class="empty__text">
                <?= $statusPilih === 'baru'
                    ? 'Semua laporan sudah ditangani. Laporan baru dari siswa akan muncul di sini.'
                    : 'Coba ganti tab status atau bersihkan filter.' ?>
            </p>
        </div>
    </div>
<?php else: ?>
    <div class="grid" style="grid-template-columns:1fr;gap:14px">
    <?php foreach ($daftar as $lp): ?>
        <?php
        $anonim   = (int) $lp['anonim'] === 1 || empty($lp['user_id']);
        $urgensi  = (string) $lp['urgensi'];
        $toneUrg  = ['darurat' => 'danger', 'perhatian' => 'warn', 'biasa' => 'muted'][$urgensi] ?? 'muted';
        $toneSt   = ['baru' => 'warn', 'diproses' => 'info', 'selesai' => 'ok'][(string) $lp['status']] ?? 'muted';
        $warna    = ['darurat' => '#c0392b', 'perhatian' => '#b7791f'][$urgensi] ?? '#e2e5ea';
        ?>
        <article class="card" style="border-left:4px solid <?= esc($warna) ?>">
            <div class="card__head" style="align-items:flex-start">
                <div style="min-width:0">
                    <div class="row" style="gap:6px;flex-wrap:wrap;align-items:center">
                        <span class="badge badge--brand">
                            <i class="fa-solid <?= esc(laporan_kategori_ikon((string) $lp['kategori'])) ?>"></i>
                            <?= esc(laporan_kategori_label((string) $lp['kategori'])) ?>
                        </span>
                        <span class="badge badge--<?= esc($toneUrg) ?>">
                            <i class="dot"></i><?= esc(laporan_urgensi_label($urgensi)) ?>
                        </span>
                        <span class="badge badge--<?= esc($toneSt) ?>">
                            <i class="dot"></i><?= esc(laporan_status_label((string) $lp['status'])) ?>
                        </span>
                    </div>
                    <p class="card__sub" style="margin-top:7px">
                        <?= esc(admin_tgl_pendek((string) $lp['created_at'])) ?>
                        &middot; kode <code><?= esc((string) $lp['kode_akses']) ?></code>
                        <?php if ($anonim): ?>
                            &middot; <span class="muted"><i class="fa-solid fa-user-secret"></i> Anonim</span>
                        <?php endif; ?>
                    </p>
                </div>
            </div>

            <?php if (!$anonim): ?>
                <div style="padding:0 16px 4px">
                    <p class="tiny">
                        <i class="fa-solid fa-user"></i>
                        <strong><?= esc((string) $lp['nama_lengkap']) ?></strong>
                        <?= !empty($lp['nama_kelas']) ? ' &middot; ' . esc((string) $lp['nama_kelas']) : '' ?>
                        <?= !empty($lp['nis']) ? ' &middot; NIS ' . esc((string) $lp['nis']) : '' ?>
                    </p>
                </div>
            <?php endif; ?>

            <div style="padding:6px 16px 10px">
                <p style="white-space:pre-wrap;line-height:1.6;font-size:14px;margin:0"><?= esc((string) $lp['isi']) ?></p>
            </div>

            <?php if (!empty($lp['catatan_bk'])): ?>
                <div class="note note--info" style="margin:0 16px 10px">
                    <i class="fa-solid fa-comment-medical note__ic"></i>
                    <div class="note__body">
                        <strong>Catatan untuk pelapor:</strong> <?= esc((string) $lp['catatan_bk']) ?>
                        <?php if (!empty($lp['petugas'])): ?>
                            <span class="tiny muted"> &middot; oleh <?= esc((string) $lp['petugas']) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <details style="padding:0 16px 14px">
                <summary class="btn btn--soft btn--sm" style="display:inline-flex;cursor:pointer">
                    <i class="fa-solid fa-pen-to-square"></i> Tangani laporan ini
                </summary>
                <form method="post" action="/admin/laporan.php" class="form" style="margin-top:12px">
                    <input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">
                    <input type="hidden" name="aksi" value="simpan">
                    <input type="hidden" name="id" value="<?= (int) $lp['id'] ?>">

                    <div class="formrow">
                        <label class="field">
                            <span class="field__label">Status</span>
                            <select name="status">
                                <?php foreach (['baru', 'diproses', 'selesai'] as $s): ?>
                                    <option value="<?= esc($s) ?>"
                                        <?= (string) $lp['status'] === $s ? 'selected' : '' ?>>
                                        <?= esc(laporan_status_label($s)) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="field" style="flex:2">
                            <span class="field__label">Catatan untuk pelapor (opsional)</span>
                            <textarea name="catatan" rows="2" maxlength="500"
                                      placeholder="Ditulis dengan bahasa yang menenangkan. Pelapor membacanya lewat kode akses."><?= esc((string) ($lp['catatan_bk'] ?? '')) ?></textarea>
                            <span class="field__hint">
                                Jangan menulis dugaan identitas pelapor anonim di sini,
                                walau untuk catatan internal. Pernah dibaca pelapornya.
                            </span>
                        </label>
                    </div>

                    <button type="submit" class="btn btn--primary btn--sm">
                        <i class="fa-solid fa-floppy-disk"></i> Simpan
                    </button>
                </form>
            </details>
        </article>
    <?php endforeach; ?>
    </div>

    <?php if ($jumlahFilter > $perHal): ?>
        <div class="pager">
            <span class="pager__info">
                Menampilkan <?= $offset + 1 ?>&ndash;<?= min($offset + $perHal, $jumlahFilter) ?>
                dari <?= $jumlahFilter ?> laporan
            </span>
            <?php if ($halaman > 1): ?>
                <a href="<?= esc(admin_ubah_query(['halaman' => $halaman - 1])) ?>"
                   aria-label="Halaman sebelumnya"><i class="fa-solid fa-chevron-left"></i></a>
            <?php else: ?>
                <span class="is-off"><i class="fa-solid fa-chevron-left"></i></span>
            <?php endif; ?>

            <?php for ($h = 1; $h <= $jumlahHal; $h++): ?>
                <?php if ($h === $halaman): ?>
                    <span class="is-on"><?= $h ?></span>
                <?php else: ?>
                    <a href="<?= esc(admin_ubah_query(['halaman' => $h])) ?>"><?= $h ?></a>
                <?php endif; ?>
            <?php endfor; ?>

            <?php if ($halaman < $jumlahHal): ?>
                <a href="<?= esc(admin_ubah_query(['halaman' => $halaman + 1])) ?>"
                   aria-label="Halaman berikutnya"><i class="fa-solid fa-chevron-right"></i></a>
            <?php else: ?>
                <span class="is-off"><i class="fa-solid fa-chevron-right"></i></span>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="card__foot">
            <?= $jumlahFilter ?> laporan<?= $cari !== '' || $kategoriPilih !== '' || $urgensiPilih !== ''
                ? ' sesuai filter aktif' : '' ?>.
        </div>
    <?php endif; ?>

    <div class="note note--flat mt-2">
        <i class="fa-solid fa-phone-volume note__ic"></i>
        <div class="note__body">
            Untuk laporan bertanda <strong>Darurat</strong>, jangan tunda.
            Kalau ada ancaman nyawa, hubungi <strong>119</strong> (ekstensi 8)
            atau pihak berwenang, lalu buka kembali laporannya di sini.
        </div>
    </div>

<?php endif; ?>

<?php endif; ?>

<?php require __DIR__ . '/../templates/admin-foot.php'; ?>
