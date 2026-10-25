<?php
/**
 * siswa/riwayat.php
 * ---------------------------------------------------------------------------
 * Riwayat presensi siswa lengkap, rapi, dan mudah dibaca (terbaru di atas).
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

/* ------------------------------------------------------------------
 *  PILIHAN BULAN
 * ------------------------------------------------------------------ */
$bulanSekarang = $now->format('Y-m');

$pilihanBulan = [];
$kursor = new DateTimeImmutable($bulanSekarang . '-01 00:00:00', $tz);
for ($i = 0; $i < 12; $i++) {
    $m = $kursor->modify('-' . $i . ' month');
    $pilihanBulan[$m->format('Y-m')] = [
        'label'  => nama_bulan((int) $m->format('n')) . ' ' . $m->format('Y'),
        'pendek' => substr(nama_bulan((int) $m->format('n')), 0, 3) . ' ' . $m->format('y'),
    ];
}

$bulan = (string) ($_GET['bulan'] ?? $bulanSekarang);
if (!preg_match('/^\d{4}-\d{2}$/', $bulan) || !isset($pilihanBulan[$bulan])) {
    $bulan = $bulanSekarang;
}

$halaman    = max(1, (int) ($_GET['hal'] ?? 1));
$perHalaman = 15;

$periodeAwal  = $bulan . '-01';
$periodeAkhir = (new DateTimeImmutable($periodeAwal . ' 00:00:00', $tz))->format('Y-m-t');

/* ------------------------------------------------------------------
 *  DATA REKAP & IZIN
 * ------------------------------------------------------------------ */
$rekap = rekap_bulan($userId, $periodeAwal, $periodeAkhir);

$izinPerTanggal = [];
if (izin_tabel_ada()) {
    foreach (db_all(
        "SELECT tanggal_mulai, tanggal_selesai, jenis, status
           FROM izin_requests
          WHERE user_id = ?
            AND tanggal_selesai >= ? AND tanggal_mulai <= ?",
        [$userId, $periodeAwal, $periodeAkhir]
    ) as $iz) {
        $dari = strtotime((string) $iz['tanggal_mulai']);
        $sampai = strtotime((string) $iz['tanggal_selesai']);
        if ($dari === false || $sampai === false) {
            continue;
        }
        for ($d = $dari, $aman = 0; $d <= $sampai && $aman < 60; $d = strtotime('+1 day', $d), $aman++) {
            $izinPerTanggal[date('Y-m-d', $d)] = $iz;
        }
    }
}

/* ------------------------------------------------------------------
 *  SUSUN DAFTAR HARI (Terbaru di atas / Descending)
 * ------------------------------------------------------------------ */
$hariList = [];
$tglAkhirLoop = $bulan === $bulanSekarang ? min($today, $periodeAkhir) : $periodeAkhir;
$d = new DateTimeImmutable($tglAkhirLoop . ' 00:00:00', $tz);
$batasAwal = new DateTimeImmutable($periodeAwal . ' 00:00:00', $tz);

while ($d >= $batasAwal) {
    $tgl = $d->format('Y-m-d');
    $isWorkday = (int) $d->format('N') <= 5;
    $harian = $rekap['perTanggal'][$tgl] ?? null;
    $izin   = $izinPerTanggal[$tgl] ?? null;

    /* Masukkan jika hari kerja, atau jika ada data scan/izin pada hari libur */
    if ($isWorkday || $harian !== null || $izin !== null) {
        $hariList[] = [
            'tanggal'   => $tgl,
            'lewat'     => $tgl <= $today,
            'isToday'   => $tgl === $today,
            'isWorkday' => $isWorkday,
            'harian'    => $harian,
            'izin'      => $izin,
            'namaHari'  => nama_hari((int) $d->format('N')),
        ];
    }

    $d = $d->modify('-1 day');
}

$totalHari  = count($hariList);
$totalHal   = max(1, (int) ceil($totalHari / $perHalaman));
$halaman    = min($halaman, $totalHal);
$offset     = ($halaman - 1) * $perHalaman;
$potongan   = array_slice($hariList, $offset, $perHalaman);
$adaHalamanLain = $totalHal > 1;

/* ------------------------------------------------------------------
 *  RINGKASAN IZIN
 * ------------------------------------------------------------------ */
$izinDisetujui = 0;
$izinMenunggu  = 0;
foreach ($izinPerTanggal as $iz) {
    if ($iz['status'] === 'disetujui') {
        $izinDisetujui++;
    } elseif ($iz['status'] === 'menunggu') {
        $izinMenunggu++;
    }
}

$pageKey   = 'riwayat';
$pageTitle = 'Riwayat Presensi';
$pageSub   = 'Rekap kehadiranmu tiap hari. Pilih periode untuk melihat bulan lain.';
$pageActionsHtml = '<div class="history-period"><span>Periode</span><label class="sr" for="filterBulan">Pilih periode</label><span class="monthpick__field"><select id="filterBulan" class="monthpick__select" onchange="window.location.href=\'?bulan=\' + encodeURIComponent(this.value)" aria-label="Periode">';
foreach ($pilihanBulan as $kunci => $p) {
    $pageActionsHtml .= '<option value="' . esc($kunci) . '"' . ($bulan === $kunci ? ' selected' : '') . '>' . esc($p['label']) . '</option>';
}
$pageActionsHtml .= '</select><i class="fa-solid fa-chevron-down monthpick__chev"></i></span></div>';

require __DIR__ . '/../templates/siswa-head.php';
?>

<!-- ============ RINGKASAN PERIODE (KPI) ============ -->
<section class="grid grid--kpi rise rise-1">
    <div class="kpi kpi--ok">
        <span class="kpi__ic"><i class="fa-solid fa-calendar-check"></i></span>
        <div class="kpi__body">
            <p class="kpi__value"><?= $rekap['hadir'] ?><small>/<?= $rekap['efektif'] ?></small></p>
            <p class="kpi__label">Hari hadir</p>
        </div>
    </div>
    <div class="kpi kpi--warn">
        <span class="kpi__ic"><i class="fa-solid fa-triangle-exclamation"></i></span>
        <div class="kpi__body">
            <p class="kpi__value"><?= $rekap['telat'] ?></p>
            <p class="kpi__label">Hari terlambat</p>
        </div>
    </div>
    <div class="kpi kpi--brand">
        <span class="kpi__ic"><i class="fa-solid fa-percent"></i></span>
        <div class="kpi__body">
            <p class="kpi__value"><?= $rekap['persen'] ?><small>%</small></p>
            <p class="kpi__label">Tingkat kehadiran</p>
        </div>
    </div>
    <div class="kpi kpi--info">
        <span class="kpi__ic"><i class="fa-solid fa-file-signature"></i></span>
        <div class="kpi__body">
            <p class="kpi__value"><?= $izinDisetujui + $izinMenunggu ?></p>
            <p class="kpi__label">Hari izin / sakit</p>
            <p class="kpi__hint">
                <?= $izinMenunggu > 0 ? $izinMenunggu . ' masih menunggu' : 'Semua disetujui' ?>
            </p>
        </div>
    </div>
</section>

<!-- ============ PILIH BULAN (form tersembunyi untuk dropdown di header) ============ -->
<form id="monthForm" method="get" action="/siswa/riwayat.php" style="display:none">
    <input type="hidden" name="bulan" id="monthHidden" value="<?= esc($bulan) ?>">
    <input type="hidden" name="hal" value="1">
</form>
<script>
document.addEventListener('change', function(e){
    if(e.target && e.target.id === 'filterBulan'){
        document.getElementById('monthHidden').value = e.target.value;
        document.getElementById('monthForm').submit();
    }
});
</script>

<!-- ============ DAFTAR RIWAYAT (TIMELINE CARDS) ============ -->
<section class="grid rise rise-3" style="margin-top:16px">
    <div class="col-12">
        <div class="card card--pad">
            <div class="card__head" style="margin-bottom:16px">
                <div>
                    <h2 class="card__title" style="font-size:18px;font-weight:700">Daftar Kehadiran</h2>
                    <p class="card__sub" style="font-size:13px;margin-top:2px">
                        <?= esc($pilihanBulan[$bulan]['label']) ?> &middot; <?= $totalHari ?> hari tercatat
                    </p>
                </div>
            </div>

            <?php if (empty($potongan)): ?>
                <div class="empty">
                    <span class="empty__ic"><i class="fa-regular fa-calendar-xmark"></i></span>
                    <p class="empty__title">Belum ada riwayat presensi</p>
                    <p class="empty__text">
                        Data presensi untuk bulan <?= esc($pilihanBulan[$bulan]['label']) ?> belum tersedia.
                    </p>
                    <a class="btn btn--sm btn--primary" href="/siswa/scan-absen.php">
                        <i class="fa-solid fa-camera"></i>Buka Kamera Presensi
                    </a>
                </div>
            <?php else: ?>
                <div class="r-list">
                    <?php foreach ($potongan as $h): ?>
                        <?php
                        $harian  = $h['harian'];
                        $izin    = $h['izin'];
                        $isToday = $h['isToday'];

                        /* Tentukan Badge & Status */
                        if ($izin !== null) {
                            $badge = badge_izin((string) $izin['status']);
                        } elseif ($harian === null) {
                            if ($h['lewat']) {
                                $badge = '<span class="badge badge--danger" style="background:#fee2e2;color:#991b1b;font-weight:700;padding:6px 14px;border-radius:999px;box-shadow:0 2px 8px rgba(220,38,38,.15)"><i class="dot" style="background:#dc2626"></i>Tanpa Keterangan</span>';
                            } else {
                                $badge = '<span class="badge badge--muted" style="background:#f1f5f9;color:#475569;font-weight:600;padding:6px 14px;border-radius:999px"><i class="dot" style="background:#94a3b8"></i>Mendatang</span>';
                            }
                        } elseif (!empty($harian['is_late'])) {
                            $badge = '<span class="badge badge--warn" style="background:#fef3c7;color:#92400e;font-weight:700;padding:6px 14px;border-radius:999px;box-shadow:0 2px 8px rgba(245,158,11,.15)"><i class="dot" style="background:#f59e0b"></i>Terlambat ' . (int) $harian['late_menit'] . ' m</span>';
                        } elseif ($harian['masuk'] !== null && $harian['pulang'] !== null) {
                            $badge = '<span class="badge badge--ok" style="background:#dcfce7;color:#166534;font-weight:700;padding:6px 14px;border-radius:999px;box-shadow:0 2px 8px rgba(34,197,94,.15)"><i class="dot" style="background:#22c55e"></i>Hadir Lengkap</span>';
                        } elseif ($harian['masuk'] !== null) {
                            $badge = '<span class="badge badge--brand" style="background:#dbeafe;color:#1e40af;font-weight:700;padding:6px 14px;border-radius:999px;box-shadow:0 2px 8px rgba(59,130,246,.15)"><i class="dot" style="background:#3b82f6"></i>Sudah Masuk</span>';
                        } else {
                            $badge = '<span class="badge badge--info"><i class="dot"></i>Absen Pulang</span>';
                        }

                        $jamMasuk  = $harian && $harian['masuk']  ? jam_potong($harian['masuk']['waktu']) . ' WIB' : '--:--';
                        $jamPulang = $harian && $harian['pulang'] ? jam_potong($harian['pulang']['waktu']) . ' WIB' : '--:--';

                        $gpsVerdict = '';
                        if ($harian) {
                            $gpsVerdict = ($harian['masuk']['gps_verdict'] ?? $harian['pulang']['gps_verdict'] ?? '');
                        }
                        ?>
                        <div class="r-card<?= $isToday ? ' is-today' : '' ?>" style="border-radius:16px;box-shadow:0 4px 16px rgba(0,0,0,.06);border:1px solid var(--line);transition:all .2s ease">
                            <div class="r-card__top">
                                <div class="r-card__date">
                                    <span class="r-card__day"><?= esc($h['namaHari']) ?></span>
                                    <span class="r-card__num"><?= esc(tgl_panjang($h['tanggal'])) ?></span>
                                    <?php if ($isToday): ?>
                                        <span class="chip chip--brand" style="padding:2px 8px;font-size:10.5px">Hari Ini</span>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <?= $badge ?>
                                </div>
                            </div>

                            <div class="r-card__details">
                                <div class="r-card__time">
                                    <span class="r-card__time-label">
                                        <i class="fa-solid fa-arrow-right-to-bracket text-brand"></i> Masuk
                                    </span>
                                    <span class="r-card__time-val<?= $jamMasuk === '--:--' ? ' is-empty' : '' ?>">
                                        <?= esc($jamMasuk) ?>
                                    </span>
                                </div>

                                <div class="r-card__time">
                                    <span class="r-card__time-label">
                                        <i class="fa-solid fa-arrow-right-from-bracket text-emerald"></i> Pulang
                                    </span>
                                    <span class="r-card__time-val<?= $jamPulang === '--:--' ? ' is-empty' : '' ?>">
                                        <?= esc($jamPulang) ?>
                                    </span>
                                </div>

                                <div class="r-card__time">
                                    <span class="r-card__time-label">
                                        <i class="fa-solid fa-location-dot"></i> GPS
                                    </span>
                                    <span class="r-card__time-val<?= empty($gpsVerdict) ? ' is-empty' : '' ?>" style="font-size:12.5px">
                                        <?= esc($gpsVerdict !== '' ? $gpsVerdict : 'Belum scan') ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php if ($adaHalamanLain): ?>
                    <div class="pager" style="margin-top:18px">
                        <span class="pager__info">
                            Halaman <?= $halaman ?> dari <?= $totalHal ?> &middot; <?= $totalHari ?> hari
                        </span>
                        <span class="pager__nav">
                            <?php if ($halaman > 1): ?>
                                <a class="btn btn--sm btn--ghost" href="?bulan=<?= esc($bulan) ?>&amp;hal=<?= $halaman - 1 ?>">
                                    <i class="fa-solid fa-chevron-left"></i>Sebelumnya
                                </a>
                            <?php endif; ?>
                            <?php if ($halaman < $totalHal): ?>
                                <a class="btn btn--sm btn--ghost" href="?bulan=<?= esc($bulan) ?>&amp;hal=<?= $halaman + 1 ?>">
                                    Berikutnya<i class="fa-solid fa-chevron-right"></i>
                                </a>
                            <?php endif; ?>
                        </span>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- ============ CATATAN ============ -->
<section class="grid rise rise-4" style="margin-top:16px;padding-bottom:12px">
    <div class="col-12">
        <div class="note note--info">
            <i class="fa-solid fa-circle-info note__ic"></i>
            <div>
                <p class="note__title">Informasi Presensi</p>
                <p style="margin-top:2px">
                    Presensi tercatat otomatis saat Anda melakukan scan wajah & GPS di sekolah.
                    Jika Anda sakit atau berhalangan, silakan ajukan surat izin pada menu <strong>Izin / Sakit</strong> agar tidak terhitung sebagai alpa.
                </p>
            </div>
        </div>
    </div>
</section>

<?php require __DIR__ . '/../templates/siswa-foot.php'; ?>
