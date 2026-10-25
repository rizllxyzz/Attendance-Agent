<?php
/**
 * admin/index.php
 * ---------------------------------------------------------------------------
 * Dashboard panel admin.
 *
 * Semua angka di halaman ini diambil dari admin/_data.php, yang juga dipakai
 * halaman absensi dan izin. Kalau suatu saat angka di dashboard berbeda dari
 * halaman detail, itu berarti bug di _data.php, bukan di halaman ini.
 */

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_data.php';

$pageKey   = 'beranda';
$pageTitle = t('dash.title');
$pageSub   = $salam . ', ' . $adminNama;

/* ==================================================================
 *  ANGKA
 * ================================================================== */

$tahundefault = admin_default_tahun_ajaran();

$rekapHariIni  = admin_rekap_tanggal($today);
$tren          = admin_tren_kehadiran(14);
$perKelas      = admin_rekap_per_kelas($today, $tahundefault);
$absensiAkhir  = admin_absensi_terakhir(7);
$wajah         = admin_statistik_wajah();
$aktivitas     = admin_aktivitas_24jam();
$audit         = admin_audit_terbaru(6);
$totalKelas    = count(admin_daftar_kelas(true, $tahundefault));
$totalSiswa    = admin_total_siswa_aktif();

$izinAktif  = admin_izin_tabel_ada();
$perStatus  = $izinAktif ? admin_izin_per_status() : ['menunggu' => 0, 'disetujui' => 0, 'ditolak' => 0, 'batal' => 0];
$izinMasuk  = $perStatus['menunggu'];

/* Ringkasan per kelas: ambil yang paling perlu dilihat. */
usort($perKelas, static function ($a, $b) {
    /* Kelas dengan porsi siswa yang belum absen tertinggi naik ke atas.
     * Kelas tanpa siswa ditaruh paling bawah supaya tidak memenuhi daftar. */
    $aKosong = $a['siswa'] === 0 ? 1 : 0;
    $bKosong = $b['siswa'] === 0 ? 1 : 0;
    if ($aKosong !== $bKosong) {
        return $aKosong <=> $bKosong;
    }
    $aBelum = $a['siswa'] > 0 ? $a['belum'] / max(1, $a['siswa']) : 0;
    $bBelum = $b['siswa'] > 0 ? $b['belum'] / max(1, $b['siswa']) : 0;
    return $bBelum <=> $aBelum;
});
$kelasPerluPerhatian = array_slice($perKelas, 0, 6);

$belumWajah = admin_siswa_belum_wajah(5);

/* Peringatan untuk pita di bawah KPI. */
$peringatan = [];
if ($izinMasuk > 0) {
    $peringatan[] = [
        'tone' => 'warn',
        'ikon' => 'fa-file-signature',
        'teks' => t('dash.warn_izin', ['n' => $izinMasuk]),
        'href' => '/admin/izin.php?status=menunggu',
    ];
}
if ($rekapHariIni['belum'] > 0) {
    $peringatan[] = [
        'tone' => 'danger',
        'ikon' => 'fa-user-clock',
        'teks' => t('dash.warn_belum_absen', ['n' => $rekapHariIni['belum']]),
        'href' => '/admin/absensi.php?tanggal=' . $today . '&status=belum',
    ];
}
if ($wajah['belum'] > 0) {
    $peringatan[] = [
        'tone' => 'info',
        'ikon' => 'fa-face-smile',
        'teks' => t('dash.warn_belum_wajah', ['n' => $wajah['belum']]),
        'href' => '/admin/wajah.php?filter=belum',
    ];
}
if ($aktivitas['peringatan'] > 0) {
    $peringatan[] = [
        'tone' => 'warn',
        'ikon' => 'fa-triangle-exclamation',
        'teks' => t('dash.warn_security', ['n' => $aktivitas['peringatan']]),
        'href' => '#aktivitas',
    ];
}

/* Pita peringatan: paling penting paling atas, jadi tiga yang pertama. */
if (count($peringatan) > 3) {
    $urut = ['danger' => 0, 'warn' => 1, 'info' => 2];
    usort($peringatan, static fn($a, $b) => $urut[$a['tone']] <=> $urut[$b['tone']]);
    $peringatan = array_slice($peringatan, 0, 3);
}

$pageActionsHtml = '<a class="btn btn--ghost" href="/admin/absensi.php">'
    . '<i class="fa-solid fa-clock-rotate-left"></i> ' . te('dash.rekap_absensi') . '</a>';

$fabHref  = '/admin/izin.php?status=menunggu';
$fabLabel = t('dash.periksa_izin');
$fabIcon  = 'fa-file-signature';

require __DIR__ . '/../templates/admin-head.php';
?>

<!-- ==================================================================
     PITA PERINGATAN
     ================================================================== -->
<?php if ($peringatan !== []): ?>
<div class="grid grid--3 mb-2">
    <?php foreach ($peringatan as $p): ?>
        <a class="note note--<?= esc($p['tone']) ?> hoverable" href="<?= esc($p['href']) ?>">
            <i class="fa-solid <?= esc($p['ikon']) ?> note__ic"></i>
            <div class="note__body"><?= esc($p['teks']) ?></div>
            <i class="fa-solid fa-chevron-right" style="opacity:.4;align-self:center"></i>
        </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ==================================================================
     KPI UTAMA
     ================================================================== -->
<div class="grid grid--kpi mb-2">
    <a class="kpi" href="/admin/siswa.php">
        <span class="kpi__ic"><i class="fa-solid fa-users"></i></span>
        <span class="kpi__body">
            <span class="kpi__value"><?= $totalSiswa ?></span>
            <span class="kpi__label"><?= te('dash.siswa_aktif') ?></span>
            <span class="kpi__hint"><?= te('dash.kelas_tahun', ['n' => $totalKelas, 'tahun' => $tahundefault]) ?></span>
        </span>
    </a>

    <a class="kpi" href="/admin/absensi.php?tanggal=<?= esc($today) ?>">
        <span class="kpi__ic" style="background:var(--ok-soft);color:var(--ok)">
            <i class="fa-solid fa-user-check"></i>
        </span>
        <span class="kpi__body">
            <span class="kpi__value">
                <?= $rekapHariIni['hadir'] ?><small>/<?= $rekapHariIni['total'] ?></small>
            </span>
            <span class="kpi__label"><?= te('dash.hadir_hari_ini') ?></span>
            <span class="kpi__hint"><?= $rekapHariIni['persen'] ?>% dari siswa aktif</span>
        </span>
    </a>

    <a class="kpi" href="/admin/absensi.php?tanggal=<?= esc($today) ?>&status=telat">
        <span class="kpi__ic" style="background:var(--warn-soft);color:var(--warn)">
            <i class="fa-solid fa-clock"></i>
        </span>
        <span class="kpi__body">
            <span class="kpi__value"><?= $rekapHariIni['telat'] ?></span>
            <span class="kpi__label"><?= te('status.terlambat') ?></span>
            <span class="kpi__hint"><?= te('dash.toleransi_hint', ['n' => $cfgToleransiTelat]) ?></span>
        </span>
    </a>

    <a class="kpi" href="/admin/absensi.php?tanggal=<?= esc($today) ?>&status=belum">
        <span class="kpi__ic" style="background:var(--brand-soft);color:var(--brand)">
            <i class="fa-solid fa-user-clock"></i>
        </span>
        <span class="kpi__body">
            <span class="kpi__value"><?= $rekapHariIni['belum'] ?></span>
            <span class="kpi__label"><?= te('dash.belum_absen') ?></span>
            <span class="kpi__hint">
                <?= $rekapHariIni['izin'] > 0
                    ? te('dash.berizin_dikecualikan', ['n' => $rekapHariIni['izin']])
                    : te('dash.tidak_ada_berizin') ?>
            </span>
        </span>
    </a>

    <a class="kpi" href="/admin/izin.php?status=menunggu">
        <span class="kpi__ic" style="background:var(--info-soft);color:var(--info)">
            <i class="fa-solid fa-file-signature"></i>
        </span>
        <span class="kpi__body">
            <span class="kpi__value"><?= $izinMasuk ?></span>
            <span class="kpi__label"><?= te('dash.izin_menunggu') ?></span>
            <span class="kpi__hint">
                <?= $perStatus['disetujui'] > 0
                    ? te('dash.sudah_disetujui', ['n' => $perStatus['disetujui']])
                    : te('dash.belum_disetujui') ?>
            </span>
        </span>
    </a>

    <a class="kpi" href="/admin/wajah.php">
        <span class="kpi__ic" style="background:var(--ok-soft);color:var(--ok)">
            <i class="fa-solid fa-face-smile"></i>
        </span>
        <span class="kpi__body">
            <span class="kpi__value"><?= $wajah['persen'] ?><small>%</small></span>
            <span class="kpi__label"><?= te('dash.face_terdaftar') ?></span>
            <span class="kpi__hint"><?= te('dash.siswa_belum_daftar', ['n' => $wajah['belum']]) ?></span>
        </span>
    </a>
</div>

<!-- ==================================================================
     GRAFIK 14 HARI + RINGKAS PER KELAS
     ================================================================== -->
<div class="grid grid--side mb-2">

    <div class="card">
        <div class="card__head">
            <div>
                <p class="card__title"><?= te('dash.tren_title') ?></p>
                <p class="card__sub"><?= te('dash.tren_sub') ?></p>
            </div>
            <div class="card__head-actions">
                <span class="badge badge--muted"><?= esc(admin_tgl_pendek($tren[0]['tanggal'])) ?>
                    &ndash; <?= esc(admin_tgl_pendek($tren[count($tren) - 1]['tanggal'])) ?></span>
            </div>
        </div>
        <div class="card__body">
            <?php $puncak = max(1, max(array_column($tren, 'hadir'))); ?>
            <div class="chart">
                <?php foreach ($tren as $t): ?>
                    <?php
                    /* Batang bertumpuk: tepat waktu + terlambat. Tinggi
                     * relatif ke hari teramai pada rentang ini. */
                    $kosong = (int) $t['hadir'] <= 0;
                    $tinggi = $kosong
                        ? 4
                        : max(7, (int) round(($t['hadir'] / $puncak) * 100));
                    ?>
                    <div class="chart__col">
                        <span class="chart__cap" style="font-weight:400">
                            <?= $t['hadir'] > 0 ? (int) $t['hadir'] : '' ?>
                        </span>
                        <span class="chart__stack<?= $kosong ? ' is-empty' : '' ?>"
                              style="height:<?= $tinggi ?>%"
                              title="<?= te('dash.chart_tip', ['tgl' => admin_tgl_pendek($t['tanggal']), 'tepat' => $t['tepat'], 'telat' => $t['telat']]) ?>">
                            <?php if (!$kosong): ?>
                                <span class="cs-telat" style="flex:<?= (int) $t['telat'] ?>"></span>
                                <span class="cs-tepat" style="flex:<?= (int) $t['tepat'] ?>"></span>
                            <?php endif; ?>
                        </span>
                        <span class="chart__cap"><?= esc(admin_nama_hari($t['hari'])) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="legend mt-2" style="padding-top:14px;border-top:1px solid var(--line)">
                <span class="legend__item">
                    <span class="legend__sw" style="background:var(--ok)"></span> <?= te('dash.tepat_waktu') ?>
                </span>
                <span class="legend__item">
                    <span class="legend__sw" style="background:var(--warn)"></span> <?= te('status.terlambat') ?>
                </span>
                <span class="legend__item">
                    <span class="legend__sw" style="background:var(--line-strong)"></span> <?= te('dash.tidak_ada_scan') ?>
                </span>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card__head">
            <div>
                <p class="card__title"><?= te('dash.kelas_title') ?></p>
                <p class="card__sub"><?= te('dash.kelas_sub') ?></p>
            </div>
            <div class="card__head-actions">
                <a class="btn btn--xs btn--ghost" href="/admin/absensi.php?tanggal=<?= esc($today) ?>"><?= te('common.detail') ?></a>
            </div>
        </div>
        <div class="card__body">
            <?php if ($kelasPerluPerhatian === []): ?>
                <div class="empty" style="padding:26px 10px">
                    <span class="empty__ic"><i class="fa-solid fa-chalkboard-user"></i></span>
                    <p class="empty__title"><?= te('dash.empty_kelas_title') ?></p>
                    <p class="empty__text"><?= te('dash.empty_kelas_text') ?></p>
                    <a class="btn btn--soft btn--sm" href="/admin/kelas.php">
                        <i class="fa-solid fa-plus"></i> <?= te('dash.kelola_kelas') ?>
                    </a>
                </div>
            <?php else: ?>
                <div class="komposisi">
                    <?php foreach ($kelasPerluPerhatian as $k): ?>
                        <?php
                        /* Satu baris = satu kelas. Lebar tiap segmen
                         * proporsional terhadap jumlah siswa, jadi panjang
                         * total bar selalu penuh. Kelas tanpa siswa tidak
                         * digambar (bar abu-abu kosong). */
                        $siswaK = max(0, (int) $k['siswa']);
                        $adaIsi = $siswaK > 0;
                        ?>
                        <a class="komposisi__row"
                           href="/admin/absensi.php?kelas=<?= (int) $k['id'] ?>&tanggal=<?= esc($today) ?>"
                           title="<?= te('dash.row_tip', ['nama' => $k['nama'], 'tepat' => (int) $k['tepat'], 'telat' => (int) $k['telat'], 'izin' => (int) $k['izin'], 'belum' => (int) $k['belum']]) ?>">
                            <span class="komposisi__label">
                                <span class="komposisi__name"><?= esc($k['nama']) ?></span>
                                <span class="komposisi__sub"><?= te('dash.n_siswa', ['n' => $siswaK]) ?></span>
                            </span>
                            <span class="komposisi__stack<?= $adaIsi ? '' : ' is-kosong' ?>">
                                <?php if ($adaIsi): ?>
                                    <span class="ks-tepat" style="flex:<?= (int) $k['tepat'] ?>"></span>
                                    <span class="ks-telat" style="flex:<?= (int) $k['telat'] ?>"></span>
                                    <span class="ks-izin" style="flex:<?= (int) $k['izin'] ?>"></span>
                                    <span class="ks-belum" style="flex:<?= (int) $k['belum'] ?>"></span>
                                <?php endif; ?>
                            </span>
                            <span class="komposisi__pct<?= $adaIsi && (int) $k['persen'] < 70 ? ' is-low' : '' ?>">
                                <?= $adaIsi ? (int) $k['persen'] . '%' : '&ndash;' ?>
                            </span>
                        </a>
                    <?php endforeach; ?>
                </div>

                <div class="legend mt-2" style="padding-top:14px;border-top:1px solid var(--line)">
                    <span class="legend__item">
                        <span class="legend__sw" style="background:var(--ok)"></span> <?= te('dash.tepat_waktu') ?>
                    </span>
                    <span class="legend__item">
                        <span class="legend__sw" style="background:var(--warn)"></span> <?= te('status.terlambat') ?>
                    </span>
                    <span class="legend__item">
                        <span class="legend__sw" style="background:var(--info)"></span> <?= te('dash.izin_sakit') ?>
                    </span>
                    <span class="legend__item">
                        <span class="legend__sw" style="background:var(--brand)"></span> <?= te('dash.belum_absen') ?>
                    </span>
                </div>

                <p class="field__hint mt-2">
                    <?= te('dash.kelas_hint', ['extra' => $izinAktif ? t('dash.kelas_hint_extra') : '']) ?>
                </p>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ==================================================================
     IZIN TERBARU + ABSENSI TERBARU
     ================================================================== -->
<div class="grid grid--2 mb-2">

    <div class="card">
        <div class="card__head">
            <div>
                <p class="card__title"><?= te('dash.izin_terbaru') ?></p>
                <p class="card__sub"><?= $izinMasuk > 0 ? te('dash.masih_menunggu', ['n' => $izinMasuk]) : te('dash.tidak_ada_antrean') ?></p>
            </div>
            <div class="card__head-actions">
                <a class="btn btn--xs btn--ghost" href="/admin/izin.php"><?= te('dash.lihat_semua') ?></a>
            </div>
        </div>

        <?php if (!$izinAktif): ?>
            <div class="empty" style="padding:30px 16px">
                <span class="empty__ic"><i class="fa-solid fa-file-signature"></i></span>
                <p class="empty__title"><?= te('dash.izin_no_table_title') ?></p>
                <p class="empty__text">
                    <?= te('dash.izin_no_table_a') ?> <code>izin_requests</code> <?= te('dash.izin_no_table_b') ?>
                </p>
            </div>
        <?php else: ?>
            <?php $izinBaru = admin_daftar_izin('menunggu', null, '', 5); ?>
            <?php if ($izinBaru === []): ?>
                <div class="empty" style="padding:30px 16px">
                    <span class="empty__ic"><i class="fa-solid fa-circle-check"></i></span>
                    <p class="empty__title"><?= te('dash.izin_kosong_title') ?></p>
                    <p class="empty__text"><?= te('dash.izin_kosong_text') ?></p>
                </div>
            <?php else: ?>
                <div class="card__body card__body--tight">
                    <div class="feed">
                        <?php foreach ($izinBaru as $z): ?>
                            <div class="feed__item">
                                <span class="feed__ic feed__ic--warn">
                                    <i class="fa-solid fa-file-signature"></i>
                                </span>
                                <div class="feed__body">
                                    <p class="feed__title">
                                        <b><?= esc($z['nama_lengkap']) ?></b>
                                        <?= $z['jenis'] === 'sakit' ? te('dash.mengaju_sakit') : te('dash.mengaju_izin') ?>
                                    </p>
                                    <p class="feed__meta">
                                        <?= esc(admin_tgl_pendek($z['tanggal_mulai'])) ?>
                                        &ndash; <?= esc(admin_tgl_pendek($z['tanggal_selesai'])) ?>
                                        &middot; <?= esc($z['nama_kelas'] ?? t('dash.tanpa_kelas')) ?>
                                    </p>
                                </div>
                                <div class="feed__side">
                                    <?= admin_badge_izin((string) $z['status']) ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="card__foot">
                    <a class="btn btn--sm btn--primary" href="/admin/izin.php?status=menunggu">
                                <i class="fa-solid fa-gavel"></i> <?= te('dash.proses', ['n' => $izinMasuk]) ?>
                    </a>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card__head">
            <div>
                <p class="card__title"><?= te('dash.scan_terakhir') ?></p>
                <p class="card__sub"><?= te('dash.scan_sub') ?></p>
            </div>
            <div class="card__head-actions">
                <a class="btn btn--xs btn--ghost" href="/admin/absensi.php">Buka rekap</a>
            </div>
        </div>

        <?php if ($absensiAkhir === []): ?>
            <div class="empty" style="padding:30px 16px">
                <span class="empty__ic"><i class="fa-solid fa-clock-rotate-left"></i></span>
                <p class="empty__title"><?= te('dash.belum_scan_title') ?></p>
                <p class="empty__text"><?= te('dash.belum_scan_text') ?></p>
            </div>
        <?php else: ?>
            <div class="tablewrap">
                <table class="table table--cards">
                    <thead>
                        <tr>
                            <th><?= te('nav.siswa') ?></th>
                            <th><?= te('nav.kelas') ?></th>
                            <th><?= te('common.time') ?></th>
                            <th><?= te('common.status') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($absensiAkhir as $a): ?>
                        <tr>
                            <td data-label="<?= te('nav.siswa') ?>">
                                <div class="celluser">
                                    <span class="celluser__text">
                                        <span class="celluser__name"><?= esc($a['nama_lengkap']) ?></span>
                                        <span class="celluser__meta celluser__meta--plain">
                                            <?= $a['scan_type'] === 'pulang' ? te('dash.pulang') : te('dash.masuk') ?>
                                            <?= $a['distance_m'] !== null
                                                ? ' &middot; ' . number_format((float) $a['distance_m'], 0) . ' m'
                                                : '' ?>
                                        </span>
                                    </span>
                                </div>
                            </td>
                            <td data-label="<?= te('nav.kelas') ?>" class="small"><?= esc($a['nama_kelas'] ?? '-') ?></td>
                            <td data-label="<?= te('common.time') ?>" class="small num-cell">
                                <?= esc(admin_jam($a['waktu'])) ?>
                                <div class="tiny muted"><?= esc(admin_tgl_pendek($a['tanggal'])) ?></div>
                            </td>
                            <td data-label="<?= te('common.status') ?>">
                                <?php if (!empty($a['is_late'])): ?>
                                    <span class="badge badge--warn"><i class="dot"></i><?= te('dash.badge_telat') ?></span>
                                <?php elseif (($a['gps_verdict'] ?? '') === 'DITERIMA'): ?>
                                    <span class="badge badge--ok"><i class="dot"></i><?= te('dash.badge_tepat') ?></span>
                                <?php else: ?>
                                    <span class="badge badge--muted"><i class="dot"></i><?= te('dash.badge_normal') ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ==================================================================
     FACE ID + AKSI CEPAT + JEJAK AKTIVITAS
     ================================================================== -->
<div class="grid grid--3 mb-2">

    <div class="card">
        <div class="card__head">
            <div>
                <p class="card__title">Face ID</p>
                <p class="card__sub"><?= te('dash.face_sub') ?></p>
            </div>
            <div class="card__head-actions">
                <a class="btn btn--xs btn--ghost" href="/admin/wajah.php"><?= te('dash.kelola') ?></a>
            </div>
        </div>
        <div class="card__body">
            <div class="row" style="gap:18px;align-items:center">
                <span class="ring">
                    <?php
                    $r      = 34;
                    $ling  = 2 * M_PI * $r;
                    $isi   = (int) round($ling * $wajah['persen'] / 100);
                    ?>
                    <svg width="88" height="88" viewBox="0 0 88 88" role="img"
                         aria-label="<?= te('dash.ring_aria', ['n' => $wajah['persen']]) ?>">
                        <circle cx="44" cy="44" r="<?= $r ?>" fill="none"
                                stroke="var(--line)" stroke-width="9"></circle>
                        <circle cx="44" cy="44" r="<?= $r ?>" fill="none"
                                stroke="var(--ok)" stroke-width="9" stroke-linecap="round"
                                stroke-dasharray="<?= round($ling, 2) ?>"
                                stroke-dashoffset="<?= round($ling - $isi, 2) ?>"></circle>
                    </svg>
                    <span class="ring__label">
                        <span class="ring__value num"><?= $wajah['persen'] ?>%</span>
                        <span class="ring__cap"><?= te('dash.terdaftar') ?></span>
                    </span>
                </span>

                <div style="flex:1;min-width:0">
                    <div class="ministat">
                        <span class="ministat__label">
                            <i class="fa-solid fa-circle-check" style="color:var(--ok)"></i> <?= te('dash.terdaftar') ?>
                        </span>
                        <span class="ministat__val ministat__val--ok"><?= $wajah['terdaftar'] ?></span>
                    </div>
                    <div class="ministat">
                        <span class="ministat__label">
                            <i class="fa-solid fa-circle-exclamation" style="color:var(--warn)"></i> <?= te('dash.belum') ?>
                        </span>
                        <span class="ministat__val ministat__val--warn"><?= $wajah['belum'] ?></span>
                    </div>
                    <div class="ministat">
                        <span class="ministat__label"><?= te('dash.mutu') ?></span>
                        <span class="ministat__val">
                            <?= $wajah['mutu'] === null ? '-' : number_format($wajah['mutu'], 3) ?>
                        </span>
                    </div>
                </div>
            </div>

            <?php if ($belumWajah !== []): ?>
                <p class="field__hint mt-2"><?= te('dash.belum_daftar_contoh') ?></p>
                <div class="row mt-1" style="gap:6px">
                    <?php foreach ($belumWajah as $b): ?>
                        <a class="chip" href="/admin/siswa.php?cari=<?= esc(urlencode($b['username'])) ?>">
                            <?= esc($b['nama_lengkap']) ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="note note--flat mt-2">
                <i class="fa-solid fa-circle-info note__ic"></i>
                <div class="note__body tiny">
                    <?= te('dash.ambang_pre') ?>
                    <strong><?= esc(number_format($cfgAmbangWajah, 2)) ?></strong>.
                    <?= te('dash.ambang_post') ?>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card__head">
            <div>
                <p class="card__title"><?= te('dash.aksi_cepat') ?></p>
                <p class="card__sub"><?= te('dash.aksi_sub') ?></p>
            </div>
        </div>
        <div class="card__body">
            <div class="quick">
                <a class="quick__item" href="/admin/siswa.php?aksi=baru">
                    <span class="quick__ic"><i class="fa-solid fa-user-plus"></i></span>
                    <?= te('dash.tambah_siswa') ?>
                </a>
                <a class="quick__item quick__item--warn" href="/admin/izin.php?status=menunggu">
                    <span class="quick__ic"><i class="fa-solid fa-gavel"></i></span>
                    <?= te('dash.setujui_izin') ?>
                </a>
                <a class="quick__item quick__item--ok" href="/admin/kelas.php">
                    <span class="quick__ic"><i class="fa-solid fa-chalkboard-user"></i></span>
                    <?= te('dash.kelola_kelas') ?>
                </a>
                <a class="quick__item quick__item--info" href="/admin/wajah.php?filter=belum">
                    <span class="quick__ic"><i class="fa-solid fa-face-smile"></i></span>
                    Face ID
                </a>
                <?php if ($isAdmin): ?>
                    <a class="quick__item" href="/admin/pengaturan.php">
                        <span class="quick__ic"><i class="fa-solid fa-gear"></i></span>
                        <?= te('nav.pengaturan') ?>
                    </a>
                    <a class="quick__item" href="/admin/akun.php">
                        <span class="quick__ic"><i class="fa-solid fa-user-tie"></i></span>
                        <?= te('dash.akun_guru') ?>
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="card" id="aktivitas">
        <div class="card__head">
            <div>
                <p class="card__title"><?= te('dash.aktivitas') ?></p>
                <p class="card__sub"><?= te('dash.aktivitas_sub', ['n' => $aktivitas['peringatan']]) ?></p>
            </div>
        </div>
        <div class="card__body card__body--tight">
            <?php if ($audit === []): ?>
                <div class="empty" style="padding:26px 10px">
                    <span class="empty__ic"><i class="fa-solid fa-clock-rotate-left"></i></span>
                    <p class="empty__title"><?= te('dash.jejak_kosong_title') ?></p>
                    <p class="empty__text"><?= te('dash.jejak_kosong_text') ?></p>
                </div>
            <?php else: ?>
                <div class="feed">
                    <?php foreach ($audit as $a): ?>
                        <?php
                        $sev = (string) ($a['severity'] ?? 'info');
                        $warna = match ($sev) {
                            'error'   => 'feed__ic--brand',
                            'warning' => 'feed__ic--warn',
                            default   => 'feed__ic--ok',
                        };
                        $ikon = match ($sev) {
                            'error'   => 'fa-triangle-exclamation',
                            'warning' => 'fa-triangle-exclamation',
                            default   => 'fa-check',
                        };
                        ?>
                        <div class="feed__item">
                            <span class="feed__ic <?= $warna ?>">
                                <i class="fa-solid <?= $ikon ?>"></i>
                            </span>
                            <div class="feed__body">
                                <p class="feed__title">
                                    <b><?= esc($a['nama_lengkap'] ?? t('dash.sistem')) ?></b>
                                    <?= esc(admin_lampau($a['created_at'])) ?>
                                </p>
                                <p class="feed__meta">
                                    <?= esc($a['event_type']) ?>
                                    <?= $a['ip_address'] ? ' &middot; ' . esc($a['ip_address']) : '' ?>
                                </p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../templates/admin-foot.php'; 