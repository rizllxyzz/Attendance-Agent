<?php
/**
 * admin/absensi.php
 * ---------------------------------------------------------------------------
 * Rekap absensi satu hari, bisa dipersempit ke satu kelas, plus daftar
 * WHOIS di balik setiap scan.
 *
 * Halaman ini READ-ONLY untuk guru. Yang boleh ditulis di sini hanya satu
 * hal: perbaikan status scan yang keliru, dan itu tetap khusus admin.
 *
 * Angka "belum" memakai definisi yang sama dengan dashboard dan dengan
 * rekap_bulan() di sisi siswa: siswa yang izinnya sudah disetujui
 * dikeluarkan dari hitungan, sedangkan yang masih menunggu tetap dihitung.
 */

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_data.php';

$pageKey   = 'absensi';
$pageTitle = t('abs.title');
$pageSub   = t('abs.sub');

/* ==================================================================
 *  PROSES POST
 * ================================================================== */

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_hanya_post();

    if (!$isAdmin) {
        admin_flash('error', t('abs.err_admin_only'));
        admin_kembali();
    }

    $aksi = post('aksi', '');

    /* ---------- Koreksi status satu scan ----------
     *
     * Scan bermasalah (GPS ditolak, wajah tidak cocok) tetap masuk tabel
     * supaya bisa ditelusuri. Kadang keputusan scan itu keliru, jadi
     * admin boleh menandainya sah — dan itu tercatat di audit. */
    if ($aksi === 'status') {
        $idScan  = (int) post('id', 0);
        $status  = post('status', '');
        $catatan = post('catatan', '');

        $scan = db_one(
            'SELECT a.id, a.user_id, a.status, a.gps_verdict, s.nama_lengkap
               FROM attendance a
               JOIN users s ON s.id = a.user_id
              WHERE a.id = ?',
            [$idScan],
            null
        );
        if (!$scan) {
            admin_flash('error', t('abs.err_notfound'));
            admin_kembali();
        }
        if (!in_array($status, ['hadir', 'izin', 'sakit', 'alpha'], true)) {
            admin_flash('error', t('abs.err_unknown_status'));
            admin_kembali();
        }

        $baru = db_exec(
            'UPDATE attendance SET status = ? WHERE id = ? AND status <> ?',
            [$status, $idScan, $status]
        );
        if ($baru < 1) {
            admin_flash('warn', t('abs.warn_same', ['status' => $status]));
        } else {
            audit_log($adminId, 'admin.absensi.status', 'warning',
                'Scan #' . $idScan . ' (' . $scan['nama_lengkap'] . ') diubah dari '
                . $scan['status'] . ' menjadi ' . $status
                . ($catatan !== '' ? ' — ' . mb_substr($catatan, 0, 200) : ''));
            admin_flash('ok', t('abs.ok_changed', ['nama' => $scan['nama_lengkap'], 'status' => $status]));
        }
        admin_kembali();
    }

    /* ---------- Ekspor CSV ----------
     *
     * Diekspor lewat halaman yang sama supaya filter yang aktif ikut terbawa
     * — file yang diunduh selalu berisi data yang sama persis dengan yang
     * terlihat di layar, bukan versi dengan filter default. */
    if ($aksi === 'csv') {
        admin_hanya_admin();
        admin_csv_absensi();
    }
}

/* ==================================================================
 *  QUERY
 * ================================================================== */

$tz        = new DateTimeZone(TIMEZONE);
$hariIni   = (new DateTimeImmutable('now', $tz))->format('Y-m-d');

/* Tanggal tidak boleh di masa depan: rekap hari yang belum terjadi akan
 * menampilkan semua siswa sebagai "belum absen" dan terlihat seperti
 * masalah sekolah. */
$tanggal = get('tanggal', '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
    $tanggal = $hariIni;
} elseif ($tanggal > $hariIni) {
    $tanggal = $hariIni;
}

$kelasPilih = (int) get('kelas', 0);
$kelasPilih = $kelasPilih > 0 ? $kelasPilih : null;

/* Tab harus salah satu dari tiga yang dikenal. Nilai apa pun yang lain
 * (termasuk hasil ketik atau URL lama) dianggap "rekap", supaya variabel
 * daftar yang dipakai tiap cabang selalu terisi. */
$tabPilih = (string) get('tab', 'rekap');
if (!in_array($tabPilih, ['rekap', 'scan', 'belum'], true)) {
    $tabPilih = 'rekap';
}

$halaman    = max(1, (int) get('halaman', 1));
$perHalaman = ROWS_PER_PAGE;

$scan       = [];
$belum      = [];
$jumlahScan = 0;
$jumlahBelum = 0;

$daftarKelas = admin_daftar_kelas(false);
$kelasOpsi   = [];
foreach ($daftarKelas as $k) {
    $kelasOpsi[] = $k;
}

/* Angka di blok KPI tidak bergantung pada tab yang aktif: selalu untuk
 * tanggal dan kelas yang sedang dipilih, supaya berpindah tab tidak
 * membuat angka di atas berubah-ubah. */
$rekap       = admin_rekap_tanggal($tanggal, $kelasPilih);
$perKelas    = admin_rekap_per_kelas($tanggal);
$tren        = admin_tren_kehadiran(14, $kelasPilih);
$izinHariIni = admin_jumlah_izin_disetujui($tanggal, $kelasPilih);
$isHariLibur = (int) date('N', strtotime($tanggal)) > 5;

/* Hanya query tab yang sedang dibuka, tapi $jumlahScan/$jumlahBelum sudah
 * diinisialisasi di atas supaya tidak ada variabel tak terdefinisi. */
if ($tabPilih === 'scan') {
    $jumlahScan = admin_jumlah_scan($tanggal, $kelasPilih);
    $totalHal   = max(1, (int) ceil($jumlahScan / $perHalaman));
    $halaman    = min($halaman, $totalHal);
    $scan       = admin_daftar_scan($tanggal, $kelasPilih, $perHalaman, ($halaman - 1) * $perHalaman);
} elseif ($tabPilih === 'belum') {
    $jumlahBelum = admin_jumlah_belum_absen($tanggal, $kelasPilih);
    $totalHal    = max(1, (int) ceil($jumlahBelum / $perHalaman));
    $halaman     = min($halaman, $totalHal);
    $belum       = admin_daftar_belum_absen($tanggal, $kelasPilih, $perHalaman, ($halaman - 1) * $perHalaman);
} else {
    $halaman = 1;
}

$labelTanggal = admin_tgl_panjang($tanggal);
$hariKe      = (int) date('N', strtotime($tanggal));

$pageActionsHtml = '<a class="btn btn--ghost" href="/admin/absensi.php?kelas='
    . (int) $kelasPilih . '&tanggal=' . admin_tgl_pendek(date('Y-m-d', strtotime($tanggal . ' -1 day')))
    . '"><i class="fa-solid fa-chevron-left"></i> <span class="only-desktop">' . te('abs.prev_day') . '</span></a>'
    . '<a class="btn btn--soft" href="/admin/absensi.php?tanggal=' . $hariIni
    . ($kelasPilih ? '&kelas=' . $kelasPilih : '') . '">'
    . '<i class="fa-regular fa-calendar-day"></i> ' . te('time.today') . '</a>'
    . '<a class="btn btn--ghost" href="/admin/absensi.php?kelas='
    . (int) $kelasPilih . '&tanggal=' . admin_tgl_pendek(date('Y-m-d', strtotime($tanggal . ' +1 day')))
    . '"><span class="only-desktop">' . te('abs.next_day') . '</span> <i class="fa-solid fa-chevron-right"></i></a>'
    . ($isAdmin
        ? '<form method="post" action="/admin/absensi.php" class="inline-form">'
            . '<input type="hidden" name="csrf" value="' . esc(csrf_token()) . '">'
            . '<input type="hidden" name="aksi" value="csv">'
            . '<input type="hidden" name="tanggal" value="' . esc($tanggal) . '">'
            . '<input type="hidden" name="kelas" value="' . (int) $kelasPilih . '">'
            . '<button type="submit" class="btn btn--soft">'
            . '<i class="fa-solid fa-file-arrow-down"></i> <span class="only-desktop">' . te('abs.download_csv') . '</span></button>'
            . '</form>'
        : '');

$fabHref  = '';
$fabLabel = '';

require __DIR__ . '/../templates/admin-head.php';
?>

<!-- ==================================================================
     FILTER TANGGAL + KELAS
     ================================================================== -->
<div class="card mb-2">
    <form class="filterbar" method="get" action="/admin/absensi.php">
        <div class="field" style="min-width:160px">
            <label class="field__label" for="fTanggal"><?= te('common.date') ?></label>
            <input type="date" id="fTanggal" name="tanggal" value="<?= esc($tanggal) ?>"
                   max="<?= esc($hariIni) ?>" onchange="this.form.submit()">
        </div>

        <div class="field" style="min-width:170px">
            <label class="field__label" for="fKelas"><?= te('nav.kelas') ?></label>
            <select id="fKelas" name="kelas" onchange="this.form.submit()">
                <option value="0"><?= te('abs.all_classes') ?></option>
                <?php foreach ($kelasOpsi as $k): ?>
                    <option value="<?= (int) $k['id'] ?>"
                        <?= $kelasPilih === (int) $k['id'] ? ' selected' : '' ?>>
                        <?= esc($k['nama_kelas']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <input type="hidden" name="tab" value="<?= esc($tabPilih) ?>">

        <button type="submit" class="btn btn--soft btn--sm">
            <i class="fa-solid fa-magnifying-glass"></i> <?= te('abs.show') ?>
        </button>
    </form>
</div>

<?php if ($isHariLibur): ?>
<div class="note note--flat mb-2">
    <i class="fa-solid fa-circle-info note__ic"></i>
    <div class="note__body">
        <strong><?= esc($labelTanggal) ?></strong> <?= te('abs.holiday_is') ?> <?= esc(admin_nama_hari($hariKe)) ?>.
        <?= te('abs.holiday_rest') ?>
    </div>
</div>
<?php endif; ?>

<!-- ==================================================================
     KPI
     ================================================================== -->
<div class="grid grid--kpi mb-2">
    <div class="kpi kpi--slim kpi--ok">
        <p class="kpi__label" style="margin:0"><?= te('status.hadir') ?></p>
        <p class="kpi__value"><?= (int) $rekap['hadir'] ?></p>
        <p class="kpi__hint"><?= te('abs.tepat_n', ['n' => (int) $rekap['tepat']]) ?></p>
    </div>
    <div class="kpi kpi--slim kpi--<?= $rekap['telat'] > 0 ? 'warn' : 'muted' ?>">
        <p class="kpi__label" style="margin:0"><?= te('status.terlambat') ?></p>
        <p class="kpi__value"><?= (int) $rekap['telat'] ?></p>
        <p class="kpi__hint"><?= te('dash.toleransi_hint', ['n' => (int) TOLERANSI_TELAT_MENIT]) ?></p>
    </div>
    <div class="kpi kpi--slim kpi--info">
        <p class="kpi__label" style="margin:0"><?= te('abs.izin_disetujui') ?></p>
        <p class="kpi__value"><?= $izinHariIni ?></p>
        <p class="kpi__hint"><?= te('abs.tidak_alpa') ?></p>
    </div>
    <div class="kpi kpi--slim kpi--<?= $rekap['belum'] > 0 ? 'danger' : 'muted' ?>">
        <p class="kpi__label" style="margin:0"><?= te('dash.belum_absen') ?></p>
        <p class="kpi__value"><?= (int) $rekap['belum'] ?></p>
        <p class="kpi__hint"><?= te('abs.dari_n_siswa', ['n' => (int) $rekap['total']]) ?></p>
    </div>
    <div class="kpi kpi--slim kpi--muted">
        <p class="kpi__label" style="margin:0"><?= te('abs.sudah_pulang') ?></p>
        <p class="kpi__value"><?= (int) $rekap['pulang'] ?></p>
        <p class="kpi__hint"><?= te('abs.scan_pulang') ?></p>
    </div>
</div>

<!-- ==================================================================
     TAB
     ================================================================== -->
<div class="card">
    <div class="tabs">
        <a class="tab<?= $tabPilih === 'rekap' ? ' is-on' : '' ?>"
           href="/admin/absensi.php?tanggal=<?= esc($tanggal) ?><?= $kelasPilih ? '&kelas=' . $kelasPilih : '' ?>&tab=rekap">
            <?= te('abs.tab_rekap') ?>
        </a>
        <a class="tab<?= $tabPilih === 'scan' ? ' is-on' : '' ?>"
           href="/admin/absensi.php?tanggal=<?= esc($tanggal) ?><?= $kelasPilih ? '&kelas=' . $kelasPilih : '' ?>&tab=scan">
            <?= te('abs.tab_scan') ?>
        </a>
        <a class="tab<?= $tabPilih === 'belum' ? ' is-on' : '' ?>"
           href="/admin/absensi.php?tanggal=<?= esc($tanggal) ?><?= $kelasPilih ? '&kelas=' . $kelasPilih : '' ?>&tab=belum">
            <?= te('dash.belum_absen') ?> <span class="tab__count"><?= (int) $rekap['belum'] ?></span>
        </a>
    </div>

    <?php if ($tabPilih === 'rekap'): ?>
        <div class="card__head">
            <div>
                <p class="card__title"><?= esc($labelTanggal) ?></p>
                <p class="card__sub">
                    <?= $kelasPilih
                        ? esc($kelasOpsi[array_search($kelasPilih, array_map(static fn($x) => (int) $x['id'], $kelasOpsi), true)]['nama_kelas'] ?? t('abs.kelas_terpilih'))
                        : te('abs.seluruh_sekolah') ?>
                </p>
            </div>
            <?php if ($kelasPilih): ?>
                <p class="card__sub">
                    <?= te('abs.rekap_note') ?>
                </p>
            <?php endif; ?>
        </div>

        <?php if ($perKelas === []): ?>
            <div class="empty">
                <span class="empty__ic"><i class="fa-solid fa-chalkboard"></i></span>
                <p class="empty__title"><?= te('abs.empty_kelas_title') ?></p>
                <p class="empty__text">
                    <?= te('abs.empty_kelas_text') ?>
                </p>
                <a class="btn btn--primary btn--sm" href="/admin/kelas.php">
                    <i class="fa-solid fa-plus"></i> <?= te('dash.kelola_kelas') ?>
                </a>
            </div>
        <?php else: ?>
            <div class="tablewrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th class="shrink"><?= te('abs.th_no') ?></th>
                            <th><?= te('nav.kelas') ?></th>
                            <th class="num-cell"><?= te('nav.siswa') ?></th>
                            <th class="num-cell"><?= te('abs.th_hadir') ?></th>
                            <th class="num-cell"><?= te('abs.th_tepat') ?></th>
                            <th class="num-cell"><?= te('abs.th_telat') ?></th>
                            <th class="num-cell"><?= te('abs.th_izin') ?></th>
                            <th class="num-cell"><?= te('abs.th_belum') ?></th>
                            <th><?= te('abs.th_kehadiran') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($perKelas as $i => $k): ?>
                        <?php
                        $persen = $k['siswa'] > 0
                            ? (int) round(($k['hadir'] / $k['siswa']) * 100)
                            : 0;
                        ?>
                        <tr>
                            <td class="num-cell muted"><?= $i + 1 ?></td>
                            <td>
                                <a class="strong" href="/admin/absensi.php?kelas=<?= (int) $k['id'] ?>&tanggal=<?= esc($tanggal) ?>&tab=scan">
                                    <?= esc($k['nama']) ?>
                                </a>
                                <?php if (!$k['aktif']): ?>
                                    <span class="badge badge--muted"><i class="dot"></i>Nonaktif</span>
                                <?php endif; ?>
                            </td>
                            <td class="num-cell"><?= (int) $k['siswa'] ?></td>
                            <td class="num-cell strong"><?= (int) $k['hadir'] ?></td>
                            <td class="num-cell muted"><?= (int) $k['tepat'] ?></td>
                            <td class="num-cell">
                                <?= (int) $k['telat'] > 0
                                    ? '<span class="num-cell warn-text">' . (int) $k['telat'] . '</span>'
                                    : '0' ?>
                            </td>
                            <td class="num-cell"><?= (int) $k['izin'] ?></td>
                            <td class="num-cell">
                                <?php if ((int) $k['belum'] > 0): ?>
                                    <a class="num-cell danger-text"
                                       href="/admin/absensi.php?kelas=<?= (int) $k['id'] ?>&tanggal=<?= esc($tanggal) ?>&tab=belum">
                                        <?= (int) $k['belum'] ?>
                                    </a>
                                <?php else: ?>
                                    0
                                <?php endif; ?>
                            </td>
                            <td style="min-width:120px">
                                <span class="mini-bar">
                                    <span class="mini-bar__fill<?= $persen >= 90 ? '' : ($persen >= 70 ? ' mini-bar__fill--warn' : ' mini-bar__fill--danger') ?>"
                                          style="width:<?= $persen ?>%"></span>
                                </span>
                                <span class="tiny muted"><?= $persen ?>%</span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

    <?php elseif ($tabPilih === 'scan'): ?>
        <div class="card__head">
            <div>
                <p class="card__title"><?= te('abs.tab_scan') ?></p>
                <p class="card__sub"><?= te('abs.scan_count', ['n' => $jumlahScan, 'tanggal' => $labelTanggal]) ?></p>
            </div>
        </div>

        <?php if ($scan === []): ?>
            <div class="empty">
                <span class="empty__ic"><i class="fa-solid fa-qrcode"></i></span>
                <p class="empty__title"><?= te('abs.empty_scan_title') ?></p>
                <p class="empty__text">
                    <?= te('abs.empty_scan_text', ['scope' => $kelasPilih ? t('abs.scope_class') : t('abs.scope_school')]) ?>
                </p>
            </div>
        <?php else: ?>
            <div class="tablewrap">
                <table class="table table--cards">
                    <thead>
                        <tr>
                            <th class="shrink"><?= te('abs.th_no') ?></th>
                            <th><?= te('nav.siswa') ?></th>
                            <th><?= te('common.time') ?></th>
                            <th><?= te('abs.th_tipe') ?></th>
                            <th><?= te('common.status') ?></th>
                            <th><?= te('abs.th_gps') ?></th>
                            <th><?= te('abs.th_wajah') ?></th>
                            <?php if ($isAdmin): ?><th><?= te('abs.th_aksi') ?></th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($scan as $i => $s): ?>
                        <?php
                        $verdict = (string) ($s['gps_verdict'] ?? '');
                        $gpsOk   = in_array(strtoupper($verdict), ['DITERIMA', 'TERIMA', 'OK'], true);
                        $gpsOff  = $s['distance_m'] !== null ? round((float) $s['distance_m']) . ' m' : '-';
                        $fc      = $s['face_confidence'] !== null ? round((float) $s['face_confidence'] * 100) . '%' : '-';
                        ?>
                        <tr>
                            <td data-label="<?= te('abs.th_no') ?>" class="num-cell muted"><?= ($halaman - 1) * $perHalaman + $i + 1 ?></td>
                            <td data-label="<?= te('nav.siswa') ?>">
                                <div class="celluser">
                                    <span class="avatar avatar--sm avatar--plain">
                                        <?= esc(mb_strtoupper(mb_substr((string) $s['nama_lengkap'], 0, 1))) ?>
                                    </span>
                                    <span class="celluser__text">
                                        <span class="celluser__name"><?= esc($s['nama_lengkap']) ?></span>
                                        <span class="celluser__meta"><?= esc($s['nama_kelas'] ?? 'Tanpa kelas') ?></span>
                                    </span>
                                </div>
                            </td>
                            <td data-label="<?= te('common.time') ?>" class="num-cell"><?= esc(admin_jam((string) $s['waktu'])) ?></td>
                            <td data-label="<?= te('abs.th_tipe') ?>">
                                <?php if ($s['scan_type'] === 'pulang'): ?>
                                    <span class="badge badge--ok"><i class="dot"></i><?= te('dash.pulang') ?></span>
                                <?php else: ?>
                                    <span class="badge badge--muted"><i class="dot"></i><?= te('dash.masuk') ?></span>
                                <?php endif; ?>
                            </td>
                            <td data-label="<?= te('common.status') ?>">
                                <?= admin_badge_absensi((string) $s['status']) ?>
                            </td>
                            <td data-label="<?= te('abs.th_gps') ?>" class="small">
                                <?php if ($gpsOk): ?>
                                    <span class="badge badge--ok"><i class="dot"></i>OK</span>
                                    <div class="tiny muted"><?= esc($gpsOff) ?></div>
                                <?php else: ?>
                                    <span class="badge badge--warn"><i class="dot"></i><?= esc($verdict ?: t('abs.gps_none')) ?></span>
                                    <div class="tiny muted"><?= esc($gpsOff) ?></div>
                                <?php endif; ?>
                            </td>
                            <td data-label="<?= te('abs.th_wajah') ?>" class="small">
                                <?php if ($s['face_confidence'] !== null): ?>
                                    <span class="badge <?= $fc >= (int) (FACE_MIN_CONFIDENCE * 100) ? 'badge--ok' : 'badge--warn' ?>">
                                        <i class="dot"></i><?= esc($fc) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="muted">-</span>
                                <?php endif; ?>
                            </td>
                            <?php if ($isAdmin): ?>
                                <td data-label="" class="actions-cell">
                                    <button type="button" class="iconbtn" data-koreksi="<?= (int) $s['id'] ?>"
                                            data-nama="<?= esc($s['nama_lengkap']) ?>"
                                            data-sekarang="<?= esc($s['status']) ?>"
                                            title="<?= te('abs.koreksi_title') ?>"
                                            aria-label="<?= te('abs.koreksi_aria', ['nama' => $s['nama_lengkap']]) ?>">
                                        <i class="fa-solid fa-pen-to-square"></i>
                                    </button>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($jumlahScan > $perHalaman): ?>
                <?= admin_pager($jumlahScan, $perHalaman, $halaman, 'scan') ?>
            <?php endif; ?>
        <?php endif; ?>

    <?php else: ?>
            <div class="card__head">
                <div>
                    <p class="card__title"><?= te('dash.belum_absen') ?></p>
                    <p class="card__sub">
                        <?= te('abs.belum_count', ['n' => $jumlahBelum, 'tanggal' => $labelTanggal]) ?>
                    </p>
                </div>
            </div>

            <?php if ($belum === []): ?>
                <div class="empty">
                    <span class="empty__ic"><i class="fa-solid fa-circle-check"></i></span>
                    <p class="empty__title"><?= te('abs.empty_belum_title') ?></p>
                    <p class="empty__text">
                        <?= te('abs.empty_belum_text', ['scope' => $kelasPilih ? t('abs.scope_class') : t('abs.scope_school')]) ?>
                    </p>
                </div>
            <?php else: ?>
                <div class="tablewrap">
                    <table class="table table--cards">
                        <thead>
                        <tr>
                            <th class="shrink"><?= te('abs.th_no') ?></th>
                            <th><?= te('nav.siswa') ?></th>
                            <th><?= te('nav.kelas') ?></th>
                            <th><?= te('abs.th_absen_terakhir') ?></th>
                            <th><?= te('common.status') ?></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($belum as $i => $s): ?>
                            <tr>
                                <td data-label="<?= te('abs.th_no') ?>" class="num-cell muted"><?= ($halaman - 1) * $perHalaman + $i + 1 ?></td>
                                <td data-label="<?= te('nav.siswa') ?>">
                                    <div class="celluser">
                                        <span class="avatar avatar--sm avatar--plain avatar--warn">
                                            <?= esc(mb_strtoupper(mb_substr((string) $s['nama_lengkap'], 0, 1))) ?>
                                        </span>
                                        <span class="celluser__text">
                                            <span class="celluser__name"><?= esc($s['nama_lengkap']) ?></span>
                                            <span class="celluser__meta"><?= esc($s['username']) ?></span>
                                        </span>
                                    </div>
                                </td>
                                <td data-label="<?= te('nav.kelas') ?>">
                                    <?php if ($s['nama_kelas']): ?>
                                        <?= esc($s['nama_kelas']) ?>
                                    <?php else: ?>
                                        <span class="badge badge--warn"><i class="dot"></i><?= te('dash.tanpa_kelas') ?></span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="<?= te('abs.th_absen_terakhir') ?>" class="small">
                                    <?php if (!empty($s['absen_terakhir'])): ?>
                                        <?= esc(admin_lampau((string) $s['absen_terakhir'])) ?>
                                    <?php else: ?>
                                        <span class="muted"><?= te('abs.never') ?></span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="<?= te('common.status') ?>">
                                    <?php if (!empty($s['ada_izin'])): ?>
                                        <span class="badge badge--warn"><i class="dot"></i><?= te('abs.waiting_leave') ?></span>
                                    <?php elseif (!empty($s['sudah_pulang'])): ?>
                                        <span class="badge badge--ok"><i class="dot"></i><?= te('abs.sudah_pulang') ?></span>
                                    <?php else: ?>
                                        <span class="badge badge--danger"><i class="dot"></i><?= te('dash.belum_absen') ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($jumlahBelum > $perHalaman): ?>
                    <?= admin_pager($jumlahBelum, $perHalaman, $halaman, 'siswa') ?>
                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>
</div>

<?php if ($tabPilih === 'rekap' && $perKelas !== []): ?>
<div class="grid grid--2 mt-2">
    <div class="card">
        <div class="card__head">
            <div>
                <p class="card__title"><?= te('abs.tren_title') ?></p>
                <p class="card__sub">
                    <?= $kelasPilih ? te('abs.kelas_terpilih') : te('abs.seluruh_sekolah') ?>
                </p>
            </div>
        </div>
        <div class="card__body">
            <?php $puncak = max(1, max(array_column($tren, 'hadir'))); ?>
            <div class="chart">
                <?php foreach ($tren as $t): ?>
                    <?php
                    $tinggi = $puncak > 0 ? (int) round(($t['hadir'] / $puncak) * 100) : 0;
                    $kelasWarna = $t['persen'] >= 90 ? 'is-ok' : ($t['persen'] >= 70 ? 'is-warn' : 'is-bad');
                    ?>
                    <span class="chart__col<?= $t['hadir'] === 0 ? ' is-empty' : '' ?>"
                          title="<?= te('abs.chart_tip', ['tgl' => admin_tgl_pendek($t['tanggal']), 'n' => (int) $t['hadir']]) ?>">
                        <span class="chart__bar <?= $kelasWarna ?>" style="height:<?= max(3, $tinggi) ?>%"></span>
                        <span class="chart__cap"><?= esc(admin_nama_hari($t['hari'])) ?></span>
                    </span>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card__head">
            <div>
                <p class="card__title"><?= te('abs.catatan_title') ?></p>
                <p class="card__sub"><?= te('abs.catatan_sub') ?></p>
            </div>
        </div>
        <div class="card__body">
            <ul class="bullets">
                <li>
                    <strong><?= te('dash.belum_absen') ?></strong> <?= te('abs.note1_b') ?>
                </li>
                <li>
                    <?= te('abs.note2_a') ?> <strong><?= te('status.menunggu') ?></strong>
                    <?= te('abs.note2_b') ?>
                </li>
                <li>
                    <strong><?= te('status.terlambat') ?></strong> <?= te('abs.note3_a') ?>
                    <?= (int) TOLERANSI_TELAT_MENIT ?> <?= te('abs.note3_b') ?>
                    (<?= esc(JAM_MASUK_MULAI) ?>).
                </li>
                <li>
                    <?= te('abs.note4_a') ?>
                    <a href="/admin/index.php"><?= te('abs.trail_link') ?></a>.
                </li>
            </ul>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ==================================================================
     MODAL KOREKSI STATUS
     ================================================================== -->
<?php if ($isAdmin): ?>
<div class="modal" id="modalKoreksi" role="dialog" aria-modal="true"
     aria-labelledby="modalKoreksiTitle" hidden>
    <div class="modal__scrim" data-tutup></div>
    <div class="modal__panel">
        <form method="post" action="/admin/absensi.php">
            <input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">
            <input type="hidden" name="aksi" value="status">
            <input type="hidden" name="id" id="koreksiId">
            <input type="hidden" name="tanggal" value="<?= esc($tanggal) ?>">

            <h2 class="modal__title" id="modalKoreksiTitle"><?= te('abs.modal_title') ?></h2>
            <p class="modal__body">
                <?= te('abs.modal_body_a') ?> <strong id="koreksiNama">-</strong>
                <?= te('abs.modal_body_b') ?>
            </p>

            <div class="field mt-2">
                <label class="field__label" for="koreksiStatus"><?= te('abs.status_baru') ?></label>
                <select id="koreksiStatus" name="status" required>
                    <option value="hadir"><?= te('status.hadir') ?></option>
                    <option value="izin"><?= te('status.izin') ?></option>
                    <option value="sakit"><?= te('status.sakit') ?></option>
                    <option value="alpha"><?= te('status.alpa') ?></option>
                </select>
            </div>

            <div class="field">
                <label class="field__label" for="koreksiCatatan"><?= te('abs.alasan') ?></label>
                <input type="text" id="koreksiCatatan" name="catatan" maxlength="200"
                       placeholder="<?= te('abs.alasan_ph') ?>">
            </div>

            <div class="modal__actions">
                <button type="button" class="btn btn--ghost" data-tutup><?= te('common.cancel') ?></button>
                <button type="submit" class="btn btn--primary">
                    <i class="fa-solid fa-check"></i> <?= te('common.save') ?>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    var modal  = document.getElementById('modalKoreksi');
    if (!modal) { return; }
    var fId    = document.getElementById('koreksiId');
    var fNama  = document.getElementById('koreksiNama');
    var fStatus= document.getElementById('koreksiStatus');

    document.querySelectorAll('[data-koreksi]').forEach(function (b) {
        b.addEventListener('click', function () {
            if (fId) { fId.value = b.dataset.koreksi; }
            if (fNama) { fNama.textContent = b.dataset.nama || '-'; }
            /* Status lama langsung jadi pilihan yang terpilih supaya yang
             * terlihat di form sama dengan yang sedang berlaku. */
            if (fStatus) { fStatus.value = b.dataset.sekarang || 'hadir'; }
            modal.hidden = false;
            document.body.classList.add('is-locked');
            if (fStatus) { fStatus.focus(); }
        });
    });
})();
</script>
<?php endif; ?>

<?php require __DIR__ . '/../templates/admin-foot.php'; 