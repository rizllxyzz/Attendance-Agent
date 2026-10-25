<?php
/**
 * admin/izin.php
 * ---------------------------------------------------------------------------
 * Memproses pengajuan izin dan sakit dari siswa.
 *
 * Dua aturan yang tidak bisa dilanggar di halaman ini:
 *   1. Hanya akun dengan peran admin yang boleh menyetujui atau menolak.
 *      Guru bisa melihat daftar, tapi tidak bisa memprosessnya.
 *   2. Pengajuan yang sudah diproses tidak bisa diproses lagi. Menyetujui
 *      dua kali akan menghitung yang sama dua kali, jadi yang kedua harus
 *      ditolak, bukan diam-diam menimpa catatan petugas sebelumnya.
 */

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_data.php';

$pageKey   = 'izin';
$pageTitle = 'Izin & Sakit';
$pageSub   = 'Setujui atau tolak pengajuan dari siswa';

$tabelIzin = admin_tabel_pakai();

/* ==================================================================
 *  PROSES POST
 * ================================================================== */

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_hanya_post();

    /* Guru boleh membaca, tapi proses tetap butuh admin. */
    if (!$isAdmin) {
        admin_flash('error', 'Hanya administrator yang boleh memproses pengajuan izin.');
        admin_kembali();
    }

    if (!$tabelIzin) {
        admin_flash('error', 'Tabel izin belum siap. Muat ulang halaman.');
        admin_kembali();
    }

    $aksi = post('aksi', '');

    /* ---------- Setujui / tolak satu pengajuan ---------- */
    if ($aksi === 'proses') {
        $id       = (int) post('id', 0);
        $keputusan = post('keputusan', '');
        $catatan  = post('catatan', '');

        if (!in_array($keputusan, ['disetujui', 'ditolak'], true)) {
            admin_flash('error', 'Keputusan tidak dikenal.');
            admin_kembali();
        }
        if (mb_strlen($catatan) > 500) {
            $catatan = mb_substr($catatan, 0, 500);
        }

        $pengajuan = db_one(
            'SELECT ir.id, ir.user_id, ir.status, ir.tanggal_mulai, ir.tanggal_selesai, ir.jenis,
                    s.nama_lengkap
               FROM izin_requests ir
               JOIN users s ON s.id = ir.user_id
              WHERE ir.id = ? LIMIT 1',
            [$id],
            null
        );
        if (!$pengajuan) {
            admin_flash('error', 'Pengajuan tidak ditemukan.');
            admin_kembali();
        }
        if ($pengajuan['status'] !== 'menunggu') {
            admin_flash('warn', 'Pengajuan dari ' . $pengajuan['nama_lengkap']
                . ' sudah pernah diproses. Muat ulang halaman.');
            admin_kembali();
        }

        /* Tabel izin_requests tidak punya kolom waktu proses, jadi kapan
         * diproses dicatat di jejak audit (lihat di bawah). Syarat
         * "status = 'menunggu'" di dalam UPDATE yang menjaga agar dua
         * petugas yang klik bersamaan tidak sama-sama berhasil. */
        /* db_exec mengembalikan jumlah baris yang benar-benar berubah. Kalau
         * nol, berarti permintaan lain yang lebih dulu memprosesnya. */
        $barisUbah = db_exec(
            "UPDATE izin_requests
                SET status = ?, catatan_petugas = ?, diproses_oleh = ?
              WHERE id = ? AND status = 'menunggu'",
            [$keputusan, $catatan !== '' ? $catatan : null, $adminId, $id]
        );

        /* db_exec mengembalikan jumlah baris yang benar-benar berubah. Kalau
         * nol, berarti permintaan lain yang lebih dulu memprosesnya. */
        if ($barisUbah < 1) {
            admin_flash('warn', 'Pengajuan itu baru saja diproses oleh petugas lain.');
            admin_kembali();
        }

        audit_log(
            $adminId,
            'admin.izin.' . $keputusan,
            $keputusan === 'disetujui' ? 'info' : 'warning',
            'Izin #' . $id . ' (' . $pengajuan['nama_lengkap'] . ', '
            . $pengajuan['tanggal_mulai'] . ' s/d ' . $pengajuan['tanggal_selesai'] . ') '
            . $keputusan . ($catatan !== '' ? ' dengan catatan: ' . $catatan : '')
        );

        admin_flash('ok', 'Pengajuan ' . $pengajuan['nama_lengkap'] . ' berhasil '
            . ($keputusan === 'disetujui' ? 'disetujui.' : 'ditolak.'));
        admin_kembali();
    }

    /* ---------- Proses beberapa sekaligus ---------- */
    if ($aksi === 'proses_banyak') {
        $daftar    = post('pilih', []);
        $keputusan = post('keputusan', '');
        $catatan   = post('catatan', '');

        if (!is_array($daftar) || $daftar === []) {
            admin_flash('warn', 'Tidak ada pengajuan yang dipilih.');
            admin_kembali();
        }
        if (!in_array($keputusan, ['disetujui', 'ditolak'], true)) {
            admin_flash('error', 'Keputusan tidak dikenal.');
            admin_kembali();
        }

        $sukses = 0;
        $gagal  = 0;
        foreach ($daftar as $v) {
            $id = (int) $v;
            if ($id <= 0) {
                continue;
            }
            $b = db_exec(
                "UPDATE izin_requests
                    SET status = ?, catatan_petugas = ?, diproses_oleh = ?
                  WHERE id = ? AND status = 'menunggu'",
                [$keputusan, $catatan !== '' ? mb_substr($catatan, 0, 500) : null, $adminId, $id]
            );
            if ($b > 0) {
                $sukses++;
                audit_log($adminId, 'admin.izin.' . $keputusan, 'info',
                    'Izin #' . $id . ' diproses massal: ' . $keputusan);
            } else {
                $gagal++;
            }
        }

        $pesan = $sukses . ' pengajuan berhasil ' . ($keputusan === 'disetujui' ? 'disetujui' : 'ditolak');
        if ($gagal > 0) {
            $pesan .= ', ' . $gagal . ' sudah diproses sebelumnya';
        }
        admin_flash($sukses > 0 ? 'ok' : 'warn', $pesan . '.');
        admin_kembali();
    }
}

/* ==================================================================
 *  QUERY & TAMPILAN
 * ================================================================== */

$statusPilih = get('status', 'menunggu');
$statusList   = ['menunggu', 'disetujui', 'ditolak', 'batal', 'semua'];
if (!in_array($statusPilih, $statusList, true)) {
    $statusPilih = 'menunggu';
}

$kelasPilih = (int) get('kelas', 0);
$kelasPilih = $kelasPilih > 0 ? $kelasPilih : null;
$cari       = get('cari', '');
$halaman    = max(1, (int) get('halaman', 1));
$perHalaman = ROWS_PER_PAGE;

$daftarKelas = admin_daftar_kelas(true);
$perStatus   = $tabelIzin ? admin_izin_per_status($kelasPilih) : [];

/* Hitung ulang jumlah untuk tab, karena angka tab ikut berubah saat kelas
   yang difilter berubah. */
$jumlahTotal = 0;
if ($tabelIzin) {
    foreach ($statusList as $s) {
        $jumlahTotal += admin_jumlah_izin($s, $kelasPilih, $cari);
    }
}

$jumlahHal = $tabelIzin
    ? admin_jumlah_izin($statusPilih, $kelasPilih, $cari)
    : 0;
$totalHal  = max(1, (int) ceil($jumlahHal / $perHalaman));
$halaman   = min($halaman, $totalHal);
$offset    = ($halaman - 1) * $perHalaman;

$daftar = $tabelIzin
    ? admin_daftar_izin($statusPilih, $kelasPilih, $cari, $perHalaman, $offset)
    : [];

/* Sisipkan nomor urut supaya bisa dipakai "pilih semua" di halaman. */
$urutan = $offset;
foreach ($daftar as $i => $d) {
    $daftar[$i]['no'] = ++$urutan;
}

$pageActionsHtml = '';
$fabHref  = '';
$fabLabel = 'Izin';

require __DIR__ . '/../templates/admin-head.php';
?>

<?php if (!$tabelIzin): ?>
    <div class="card">
        <div class="empty">
            <span class="empty__ic"><i class="fa-solid fa-triangle-exclamation"></i></span>
            <p class="empty__title">Tabel izin belum bisa dipakai</p>
            <p class="empty__text">
                Tabel <code>izin_requests</code> tidak berhasil disiapkan.
                Cek izin tulis untuk berkas database, lalu muat ulang halaman ini.
            </p>
        </div>
    </div>
<?php else: ?>

<!-- ==================================================================
     TAB STATUS
     ================================================================== -->
<div class="tabs mb-2">
    <?php
    $labelStatus = [
        'menunggu'  => 'Menunggu',
        'disetujui' => 'Disetujui',
        'ditolak'   => 'Ditolak',
        'batal'     => 'Dibatalkan',
        'semua'     => 'Semua',
    ];
    foreach ($statusList as $s):
        $n = $s === 'semua'
            ? array_sum($perStatus)
            : (int) ($perStatus[$s] ?? 0);
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
    <form class="filterbar" method="get" action="/admin/izin.php">
        <input type="hidden" name="status" value="<?= esc($statusPilih) ?>">

        <div class="field" style="min-width:160px">
            <label class="field__label" for="fKelas">Kelas</label>
            <select id="fKelas" name="kelas" onchange="this.form.submit()">
                <option value="0">Semua kelas</option>
                <?php foreach ($daftarKelas as $k): ?>
                    <option value="<?= (int) $k['id'] ?>"
                        <?= $kelasPilih === (int) $k['id'] ? ' selected' : '' ?>>
                        <?= esc($k['nama_kelas']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field field--grow">
            <label class="field__label" for="fCari">Cari</label>
            <div class="search">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="search" id="fCari" name="cari" value="<?= esc($cari) ?>"
                       placeholder="Nama siswa, NIS, atau alasan">
            </div>
        </div>

        <button type="submit" class="btn btn--soft btn--sm">
            <i class="fa-solid fa-filter"></i> Terapkan
        </button>
        <?php if ($cari !== '' || $kelasPilih !== null): ?>
            <a class="btn btn--ghost btn--sm" href="/admin/izin.php?status=<?= esc($statusPilih) ?>">
                <i class="fa-solid fa-xmark"></i> Bersihkan
            </a>
        <?php endif; ?>
    </form>
</div>

<!-- ==================================================================
     DAFTAR
     ================================================================== -->
<form method="post" action="/admin/izin.php" id="formIzin">
    <input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">
    <input type="hidden" name="aksi" value="proses_banyak">
    <input type="hidden" name="keputusan" value="disetujui" id="keputusanBanyak">

    <div class="card" id="daftar">

        <div class="bulkbar" id="bulkbar" hidden>
            <span class="bulkbar__count" id="bulkCount">0 dipilih</span>
            <span class="spacer"></span>
            <?php if ($isAdmin): ?>
                <button type="button" class="btn btn--light" data-keputusan="disetujui">
                    <i class="fa-solid fa-check"></i> Setujui yang dipilih
                </button>
                <button type="button" class="btn" data-keputusan="ditolak">
                    <i class="fa-solid fa-xmark"></i> Tolak yang dipilih
                </button>
            <?php endif; ?>
            <button type="button" class="btn" id="bulkBatal">Batal pilih</button>
        </div>

        <?php if ($daftar === []): ?>
            <div class="empty">
                <span class="empty__ic">
                    <i class="fa-solid <?= $statusPilih === 'menunggu' ? 'fa-circle-check' : 'fa-inbox' ?>"></i>
                </span>
                <p class="empty__title">
                    <?= $statusPilih === 'menunggu'
                        ? 'Tidak ada pengajuan menunggu'
                        : 'Tidak ada pengajuan' ?>
                </p>
                <p class="empty__text">
                    <?= $statusPilih === 'menunggu'
                        ? 'Semua pengajuan sudah diproses. Pengajuan baru dari siswa akan muncul di sini.'
                        : 'Coba ganti tab status atau bersihkan filter pencarian.' ?>
                </p>
            </div>
        <?php else: ?>
            <div class="tablewrap">
                <table class="table table--cards">
                    <thead>
                        <tr>
                            <?php if ($isAdmin && $statusPilih === 'menunggu'): ?>
                                <th class="shrink" style="width:36px">
                                    <input type="checkbox" class="cb" id="pilihSemua"
                                           aria-label="Pilih semua di halaman ini">
                                </th>
                            <?php endif; ?>
                            <th class="shrink">No</th>
                            <th>Siswa</th>
                            <th>Jenis</th>
                            <th>Periode</th>
                            <th>Alasan</th>
                            <th>Status</th>
                            <?php if ($isAdmin && $statusPilih === 'menunggu'): ?>
                                <th class="actions-cell">Aksi</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($daftar as $z): ?>
                        <tr>
                            <?php if ($isAdmin && $statusPilih === 'menunggu'): ?>
                                <td data-label="" class="shrink">
                                    <input type="checkbox" class="cb pilih-siswa"
                                           name="pilih[]" value="<?= (int) $z['id'] ?>"
                                           aria-label="Pilih pengajuan <?= (int) $z['no'] ?> dari <?= esc($z['nama_lengkap']) ?>">
                                </td>
                            <?php endif; ?>
                            <td data-label="No" class="num-cell muted"><?= (int) $z['no'] ?></td>
                            <td data-label="Siswa">
                                <div class="celluser">
                                    <span class="celluser__text">
                                        <span class="celluser__name"><?= esc($z['nama_lengkap']) ?></span>
                                        <span class="celluser__meta celluser__meta--plain">
                                            <?= esc($z['nama_kelas'] ?? 'tanpa kelas') ?>
                                            <?= $z['nis'] ? ' &middot; NIS ' . esc($z['nis']) : '' ?>
                                        </span>
                                    </span>
                                </div>
                            </td>
                            <td data-label="Jenis">
                                <span class="badge <?= $z['jenis'] === 'sakit' ? 'badge--danger' : 'badge--brand' ?>">
                                    <?= $z['jenis'] === 'sakit' ? 'Sakit' : 'Izin' ?>
                                </span>
                            </td>
                            <td data-label="Periode" class="small nowrap">
                                <?= esc(admin_tgl_pendek($z['tanggal_mulai'])) ?>
                                <div class="tiny muted">s/d <?= esc(admin_tgl_pendek($z['tanggal_selesai'])) ?></div>
                            </td>
                            <td data-label="Alasan" class="small" style="max-width:280px">
                                <?= esc(mb_strimwidth((string) ($z['alasan'] ?? '-'), 0, 120, '...')) ?>
                            </td>
                            <td data-label="Status">
                                <?= admin_badge_izin((string) $z['status']) ?>
                                <?php if ($z['catatan_petugas']): ?>
                                    <div class="tiny muted" style="margin-top:3px">
                                        &ldquo;<?= esc(mb_strimwidth((string) $z['catatan_petugas'], 0, 80, '...')) ?>&rdquo;
                                    </div>
                                <?php endif; ?>
                                <?php if ($z['petugas'] && $z['status'] !== 'menunggu'): ?>
                                    <div class="tiny muted">oleh <?= esc($z['petugas']) ?></div>
                                <?php endif; ?>
                            </td>
                            <?php if ($isAdmin && $statusPilih === 'menunggu'): ?>
                                <td data-label="" class="actions-cell">
                                    <div class="row row--end" style="gap:6px;flex-wrap:nowrap">
                                        <button type="button" class="iconbtn iconbtn--ok"
                                                data-proses="disetujui" data-id="<?= (int) $z['id'] ?>"
                                                data-nama="<?= esc($z['nama_lengkap']) ?>"
                                                title="Setujui" aria-label="Setujui pengajuan dari <?= esc($z['nama_lengkap']) ?>">
                                            <i class="fa-solid fa-check"></i>
                                        </button>
                                        <button type="button" class="iconbtn iconbtn--danger"
                                                data-proses="ditolak" data-id="<?= (int) $z['id'] ?>"
                                                data-nama="<?= esc($z['nama_lengkap']) ?>"
                                                title="Tolak" aria-label="Tolak pengajuan dari <?= esc($z['nama_lengkap']) ?>">
                                            <i class="fa-solid fa-xmark"></i>
                                        </button>
                                    </div>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($jumlahHal > $perHalaman): ?>
                <div class="pager">
                    <span class="pager__info">
                        Menampilkan <?= $offset + 1 ?>&ndash;<?= min($offset + $perHalaman, $jumlahHal) ?>
                        dari <?= $jumlahHal ?> pengajuan
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
                    <?= $jumlahHal ?> pengajuan
                    <?= $cari !== '' || $kelasPilih !== null
                        ? 'sesuai filter aktif'
                        : 'dari seluruh kelas' ?>.
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</form>

<?php if (!$isAdmin): ?>
<div class="note note--flat mt-2">
    <i class="fa-solid fa-lock note__ic"></i>
    <div class="note__body">
        Kamu masuk sebagai <strong><?= esc($peranLabel) ?></strong>, jadi pengajuan
        izin hanya bisa dilihat. Penyetujuan tetap dilakukan administrator.
    </div>
</div>
<?php endif; ?>

<!-- ==================================================================
     MODAL PROSES SATU
     ================================================================== -->
<div class="modal" id="modalProses" role="dialog" aria-modal="true"
     aria-labelledby="modalProsesTitle" hidden>
    <div class="modal__scrim" data-tutup></div>
    <div class="modal__panel">
        <form method="post" action="/admin/izin.php">
            <input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">
            <input type="hidden" name="aksi" value="proses">
            <input type="hidden" name="id" id="modalId">
            <input type="hidden" name="keputusan" id="modalKeputusan">

            <h2 class="modal__title" id="modalProsesTitle">Setujui pengajuan</h2>
            <p class="modal__body" id="modalTeks">
                Pengajuan dari <strong id="modalNama">-</strong> akan disetujui.
            </p>

            <div class="field mt-2">
                <label class="field__label" for="modalCatatan">Catatan petugas (opsional)</label>
                <textarea id="modalCatatan" name="catatan" maxlength="500"
                          placeholder="Contoh: ada surat keterangan dokter, diizinkan 1 hari."></textarea>
                <p class="field__hint">
                    Catatan ini dilihat siswa di halaman izinnya, jadi tulis dalam
                    bahasa yang mudah dipahami.
                </p>
            </div>

            <div class="modal__actions">
                <button type="button" class="btn btn--ghost" data-tutup>Batal</button>
                <button type="submit" class="btn btn--primary" id="modalKirim">
                    <i class="fa-solid fa-check"></i> Setujui
                </button>
            </div>
        </form>
    </div>
</div>

<?php endif; ?>

<script>
/* ------------------------------------------------------------------
 *  Pilih banyak & proses
 *  ------------------------------------------------------------------ */
(function () {
    var form      = document.getElementById('formIzin');
    if (!form) { return; }
    var bar       = document.getElementById('bulkbar');
    var hitung    = document.getElementById('bulkCount');
    var semua     = document.getElementById('pilihSemua');
    var item      = Array.prototype.slice.call(form.querySelectorAll('.pilih-siswa'));

    function perbarui() {
        var n = item.filter(function (c) { return c.checked; }).length;
        if (!bar) { return; }
        bar.hidden = n === 0;
        if (hitung) { hitung.textContent = n + ' dipilih'; }
        if (semua) {
            semua.checked = n > 0 && n === item.length;
            semua.indeterminate = n > 0 && n < item.length;
        }
    }

    item.forEach(function (c) { c.addEventListener('change', perbarui); });

    if (semua) {
        semua.addEventListener('change', function () {
            item.forEach(function (c) { c.checked = semua.checked; });
            perbarui();
        });
    }

    var batal = document.getElementById('bulkBatal');
    if (batal) {
        batal.addEventListener('click', function () {
            item.forEach(function (c) { c.checked = false; });
            perbarui();
        });
    }

    /* Dua tombol punya keputusan berbeda; yang ditekan menentukan nilai
       input tersembunyi sebelum form dikirim. */
    document.querySelectorAll('[data-keputusan]').forEach(function (b) {
        b.addEventListener('click', function () {
            var k = document.getElementById('keputusanBanyak');
            if (k) { k.value = b.dataset.keputusan; }
            if (!window.confirm(
                (b.dataset.keputusan === 'disetujui' ? 'Setujui' : 'Tolak')
                + ' pengajuan yang dipilih?')) {
                return;
            }
            form.submit();
        });
    });

    perbarui();
})();

/* ------------------------------------------------------------------
 *  Proses satu pengajuan lewat modal
 *  ------------------------------------------------------------------ */
(function () {
    var modal = document.getElementById('modalProses');
    if (!modal) { return; }

    var fId        = document.getElementById('modalId');
    var fKeputusan = document.getElementById('modalKeputusan');
    var fCatatan   = document.getElementById('modalCatatan');
    var elJudul    = document.getElementById('modalProsesTitle');
    var elTeks     = document.getElementById('modalTeks');
    var elNama     = document.getElementById('modalNama');
    var elKirim    = document.getElementById('modalKirim');

    document.querySelectorAll('[data-proses]').forEach(function (b) {
        b.addEventListener('click', function () {
            var k = b.dataset.proses;
            var nama = b.dataset.nama || '-';
            if (fId) { fId.value = b.dataset.id; }
            if (fKeputusan) { fKeputusan.value = k; }
            if (fCatatan) { fCatatan.value = ''; }
            if (elNama) { elNama.textContent = nama; }
            if (elJudul) {
                elJudul.textContent = k === 'disetujui' ? 'Setujui pengajuan' : 'Tolak pengajuan';
            }
            if (elTeks) {
                elTeks.textContent = k === 'disetujui'
                    ? 'Pengajuan dari ' + nama + ' akan disetujui. Siswa langsung melihat Status berubah setelah halaman dimuat ulang.'
                    : 'Pengajuan dari ' + nama + ' akan ditolak. Jangan lupa menulis alasan penolakan agar siswa tahu apa yang harus dilakukan.';
            }
            if (elKirim) {
                elKirim.className = 'btn ' + (k === 'disetujui' ? 'btn--primary' : 'btn--danger');
                elKirim.innerHTML = (k === 'disetujui'
                    ? '<i class="fa-solid fa-check"></i> Setujui'
                    : '<i class="fa-solid fa-xmark"></i> Tolak');
            }
            modal.hidden = false;
            document.body.classList.add('is-locked');
            if (fCatatan) { fCatatan.focus(); }
        });
    });
})();
</script>

<?php require __DIR__ . '/../templates/admin-foot.php'; 