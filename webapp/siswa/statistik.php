<?php
/**
 * siswa/statistik.php
 * ---------------------------------------------------------------------------
 * Analitik kehadiran siswa.
 *
 * Semua angka memakai definisi yang sama dengan dashboard dan riwayat
 * (lihat rekap_bulan() di _bootstrap.php), supaya tiga halaman itu tidak
 * pernah menampilkan angka yang berbeda untuk hal yang sama.
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

/* ------------------------------------------------------------------
 *  PERIODE
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

$periodeAwal = $bulan . '-01';
$periodeAkhir = (new DateTimeImmutable($periodeAwal . ' 00:00:00', $tz))->format('Y-m-t');
$bulanIniAktif = ($bulan === $bulanSekarang);

/* Untuk bulan berjalan, jangan hitung hari yang belum terjadi. Kalau
 * tidak, Warsa hari 3 akan terbaca "alpa 22 hari". */
$periodeHitung = $bulanIniAktif ? $today : $periodeAkhir;

$rekap = rekap_bulan($userId, $periodeAwal, $periodeHitung);

/* ------------------------------------------------------------------
 *  TREN 6 BULAN
 * ------------------------------------------------------------------ */
$tren = [];
$kursor = new DateTimeImmutable($bulan . '-01 00:00:00', $tz);
for ($i = 5; $i >= 0; $i--) {
    $m = $kursor->modify('-' . $i . ' month');
    $mulaiBulan = $m->format('Y-m-01');
    $batasBulan = $m->format('Y-m-t');
    $r = rekap_bulan($userId, $mulaiBulan, min($batasBulan, $today));
    $tren[] = [
        'label'   => substr(nama_bulan((int) $m->format('n')), 0, 3),
        'tahun'   => $m->format('Y'),
        'persen'  => $r['persen'],
        'hadir'   => $r['hadir'],
        'efektif' => $r['efektif'],
        'telat'   => $r['telat'],
    ];
}

$rataTren = 0;
$cobaTren = 0;
foreach ($tren as $t) {
    if ($t['efektif'] > 0) {
        $rataTren += $t['persen'];
        $cobaTren++;
    }
}
$rataTren = $cobaTren > 0 ? (int) round($rataTren / $cobaTren) : 0;

/* ==================================================================
 *  KATEGORI KEHADIRAN
 *  ==================================================================
 *  Ambang dipilih supaya warnanya tidak menyesatkan: 80% dan 70%
 *  dibedakan dengan warna, bukan hanya dengan angka, karena keduanya
 *  terlihat mirip di layar tapi artinya jauh berbeda.
 * ================================================================== */
$kategori = 'ok';
$kategoriLabel = 'Baik';
$kategoriKet   = 'Kehadiranmu konsisten di atas 85%.';

if ($rekap['efektif'] === 0) {
    $kategori = 'muted';
    $kategoriLabel = 'Belum ada data';
    $kategoriKet = 'Belum ada hari kerja yang bisa dihitung pada periode ini.';
} elseif ($rekap['persen'] >= 90) {
    $kategori = 'ok';
    $kategoriLabel = 'Sangat baik';
    $kategoriKet = 'Kehadiran di atas 90%. Pertahankan.';
} elseif ($rekap['persen'] >= 80) {
    $kategori = 'ok';
    $kategoriLabel = 'Baik';
    $kategoriKet = 'Kehadiran di atas 80%. Masih di zona aman.';
} elseif ($rekap['persen'] >= 70) {
    $kategori = 'warn';
    $kategoriLabel = 'Perlu perbaikan';
    $kategoriKet = 'Tingkat kehadiran di bawah 80%. Kurangi ketidakhadiran.';
} else {
    $kategori = 'danger';
    $kategoriLabel = 'Perlu tindakan';
    $kategoriKet = 'Tingkat kehadiran di bawah 70%. Bicarakan dengan wali kelas.';
}

/* ------------------------------------------------------------------
 *  RATA-RATA KEDATANGAN
 * ------------------------------------------------------------------ */
$waktuMasuk = [];
foreach ($rekap['perTanggal'] as $harian) {
    if ($harian['masuk'] !== null) {
        $waktuMasuk[] = (int) substr(jam_potong($harian['masuk']['waktu']), 0, 2);
    }
}
$rataJamMasuk = $waktuMasuk ? (int) round(array_sum($waktuMasuk) / count($waktuMasuk)) : 0;
$jamTepatRata = sprintf('%02d:00', $rataJamMasuk);

/* ------------------------------------------------------------------
 *  PEMBAGIAN KATEGORI
 * ------------------------------------------------------------------ */
$pembagian = [
    ['label' => 'Tepat waktu', 'nilai' => $rekap['tepat'],         'kelas' => 'ok'],
    ['label' => 'Terlambat',   'nilai' => $rekap['telat'],         'kelas' => 'warn'],
    ['label' => 'Tanpa catatan', 'nilai' => $rekap['alpa'],        'kelas' => 'muted'],
];

/* ------------------------------------------------------------------
 *  HARI DALAM MINGGU
 * ------------------------------------------------------------------ */
$sebaran = array_fill(0, 7, 0);
foreach ($rekap['perTanggal'] as $harian) {
    if ($harian['masuk'] === null) {
        continue;
    }
    $ts = strtotime((string) $harian['tanggal']);
    if ($ts !== false) {
        $sebaran[(int) date('w', $ts)]++;
    }
}
$sebaranMaks = 1;
foreach ($sebaran as $j) {
    $sebaranMaks = max($sebaranMaks, $j);
}

/* ------------------------------------------------------------------
 *  RINCIAN HARI
 * ------------------------------------------------------------------ */
$urutHari = array_keys($rekap['perTanggal']);
$jumlahHari = count($urutHari);

$pageKey   = 'statistik';
$pageTitle = 'Statistik Kehadiran';
$pageSub   = $pilihanBulan[$bulan]['label'];

require __DIR__ . '/../templates/siswa-head.php';
?>

<!-- ============ KARTU UTAMA ============ -->
<section class="grid">
    <div class="col-5 rise rise-1">
        <div class="card card--pad" style="height:100%;display:flex;flex-direction:column;justify-content:center">
            <div style="display:flex;align-items:center;gap:18px">
                <div class="ring" style="width:96px;height:96px">
                    <svg width="96" height="96" viewBox="0 0 100 100" aria-hidden="true">
                        <circle cx="50" cy="50" r="42" fill="none" stroke="#eef0f6" stroke-width="10"/>
                        <circle id="cincin" cx="50" cy="50" r="42" fill="none" stroke="url(#gradStat)"
                                stroke-width="10" stroke-linecap="round"
                                stroke-dasharray="263.9" stroke-dashoffset="263.9"/>
                        <defs>
                            <linearGradient id="gradStat" x1="0" y1="0" x2="1" y2="1">
                                <stop offset="0%" stop-color="#4f46e5"/>
                                <stop offset="100%" stop-color="#10b981"/>
                            </linearGradient>
                        </defs>
                    </svg>
                    <div class="ring__label">
                        <span class="ring__value" id="persenTampil">0%</span>
                        <span class="ring__cap">Kehadiran</span>
                    </div>
                </div>

                <div style="flex:1;min-width:0">
                    <span class="badge badge--<?= esc($kategori === 'muted' ? 'muted' : $kategori) ?>" style="font-size:12px;padding:4px 11px">
                        <i class="dot"></i><?= esc($kategoriLabel) ?>
                    </span>
                    <p class="hero__name" style="font-size:20px;margin-top:10px">
                        <?= $rekap['hadir'] ?> dari <?= $rekap['efektif'] ?> hari
                    </p>
                    <p style="font-size:12.5px;color:var(--text-3);margin-top:4px;line-height:1.5">
                        <?= esc($kategoriKet) ?>
                    </p>
                </div>
            </div>
        </div>
    </div>

    <div class="col-7 rise rise-2">
        <div class="card card--flush" style="height:100%;display:flex;flex-direction:column">
            <div class="card__head">
                <div>
                    <h2 class="card__title">Tren 6 bulan</h2>
                    <p class="card__sub">Rata-rata <?= $rataTren ?>% &middot; bulan berjalan dihitung sampai hari ini</p>
                </div>
            </div>
            <div class="card--pad" style="flex:1;display:flex;flex-direction:column;justify-content:center">
                <div class="chart" style="height:150px">
                    <?php foreach ($tren as $t): ?>
                        <div class="chart__col">
                            <div class="chart__bar<?= $t['persen'] >= 85 ? ' is-high' : ($t['persen'] < 60 ? ' is-low' : '') ?>"
                                 style="height:<?= max(3, $t['persen']) ?>%"
                                 title="<?= esc($t['label'] . ' ' . $t['tahun'] . ': ' . $t['persen'] . '% (' . $t['hadir'] . '/' . $t['efektif'] . ' hari, ' . $t['telat'] . ' terlambat)') ?>"></div>
                            <span class="chart__cap"><?= esc($t['label']) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ============ PILIH BULAN ============ -->
<section class="grid rise rise-2" style="margin-top:16px">
    <div class="col-12">
        <form method="get" action="/siswa/statistik.php" class="monthpick">
            <label class="monthpick__label" for="filterBulan">Periode</label>
            <span class="monthpick__field">
                <select id="filterBulan" name="bulan" class="monthpick__select" onchange="this.form.submit()">
                    <?php foreach ($pilihanBulan as $kunci => $p): ?>
                        <option value="<?= esc($kunci) ?>"<?= $bulan === $kunci ? ' selected' : '' ?>><?= esc($p['label']) ?></option>
                    <?php endforeach; ?>
                </select>
                <i class="fa-solid fa-chevron-down monthpick__chev"></i>
            </span>
            <noscript><button type="submit" class="btn btn--sm btn--primary">Terapkan</button></noscript>
        </form>
    </div>
</section>

<!-- ============ RINCIANG ============ -->
<section class="grid grid--kpi rise rise-3" style="margin-top:16px">
    <?php foreach ($pembagian as $p): ?>
        <div class="kpi kpi--<?= $p['kelas'] === 'muted' ? 'muted' : $p['kelas'] ?>">
            <span class="kpi__ic">
                <i class="fa-solid <?= $p['kelas'] === 'ok' ? 'fa-circle-check'
                    : ($p['kelas'] === 'warn' ? 'fa-triangle-exclamation' : 'fa-circle-question') ?>"></i>
            </span>
            <div class="kpi__body">
                <p class="kpi__value"><?= $p['nilai'] ?><small>/<?= $rekap['efektif'] ?></small></p>
                <p class="kpi__label"><?= esc($p['label']) ?></p>
            </div>
        </div>
    <?php endforeach; ?>
</section>

<!-- ============ SEBARAN MINGGUAN + CATATAN ============ -->
<section class="grid" style="margin-top:16px">
    <div class="col-7 rise rise-4">
        <div class="card card--pad">
            <h2 class="card__title">Hari yang sering hadir</h2>
            <p class="card__sub" style="margin-bottom:16px">
                Sebaran <?= $jumlahHari ?> hari yang tercatat pada <?= esc($pilihanBulan[$bulan]['label']) ?>
            </p>

            <?php if ($jumlahHari === 0): ?>
                <div class="empty" style="padding:24px 0">
                    <span class="empty__ic"><i class="fa-regular fa-calendar"></i></span>
                    <p class="empty__title">Belum ada data pada bulan ini</p>
                </div>
            <?php else: ?>
                <div class="bars">
                    <?php foreach ([1, 2, 3, 4, 5, 6, 0] as $idx): ?>
                        <div class="bar__row">
                            <span class="bar__name"><?= esc(nama_hari($idx)) ?></span>
                            <span class="bar__track">
                                <span class="bar__fill" style="width:<?= max($sebaran[$idx] > 0 ? 4 : 0, (int) round($sebaran[$idx] / $sebaranMaks * 100)) ?>%"></span>
                            </span>
                            <span class="bar__val"><?= $sebaran[$idx] ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-5 rise rise-5">
        <div style="display:grid;gap:16px;height:100%">
            <div class="card card--pad">
                <h2 class="card__title">Pola kedatangan</h2>
                <p class="card__sub" style="margin-bottom:14px">Rata-rata jam absen masuk</p>
                <?php if ($waktuMasuk === []): ?>
                    <p style="font-size:13px;color:var(--text-3)">Belum ada data absen masuk.</p>
                <?php else: ?>
                    <div class="ministat">
                        <div class="ministat__row">
                            <span class="ministat__key">Rata-rata datang</span>
                            <span class="ministat__val num"><?= esc($jamTepatRata) ?></span>
                        </div>
                        <div class="ministat__row">
                            <span class="ministat__key">Batas tidak terlambat</span>
                            <span class="ministat__val num"><?= esc(JAM_MASUK_AKHIR) ?></span>
                        </div>
                        <div class="ministat__row">
                            <span class="ministat__key">Jumlah hari tercatat</span>
                            <span class="ministat__val num"><?= count($waktuMasuk) ?></span>
                        </div>
                    </div>

                    <div style="margin-top:16px">
                        <div class="meter">
                            <div class="meter__track">
                                <div class="meter__fill" style="width:<?= $rekap['persen'] ?>%"></div>
                            </div>
                            <p class="meter__cap"><?= $rekap['persen'] ?>% dari hari kerja yang dihitung</p>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <div class="card card--pad">
                <h2 class="card__title">Rincian per hari</h2>
                <div class="detail" style="margin-top:12px">
                    <div class="detail__row">
                        <span class="detail__key">Hari kerja dihitung</span>
                        <span class="detail__val num"><?= $rekap['efektif'] ?></span>
                    </div>
                    <?php if ($rekap['izin'] > 0): ?>
                        <div class="detail__row">
                            <span class="detail__key">
                                Dikurangi hari izin/sakit disetujui
                            </span>
                            <span class="detail__val num">&minus;<?= $rekap['izin'] ?></span>
                        </div>
                    <?php endif; ?>
                    <div class="detail__row">
                        <span class="detail__key">Hari ada scan</span>
                        <span class="detail__val num"><?= $jumlahHari ?></span>
                    </div>
                    <div class="detail__row">
                        <span class="detail__key">Total scan tercatat</span>
                        <span class="detail__val num"><?= (int) db_value(
                            'SELECT COUNT(*) FROM attendance WHERE user_id = ? AND tanggal >= ? AND tanggal <= ?',
                            [$userId, $periodeAwal, $periodeHitung],
                            0
                        ) ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ============ CATATAN ============ -->
<section class="grid rise rise-6" style="margin-top:16px;padding-bottom:4px">
    <div class="col-12">
        <div class="note note--info">
            <i class="fa-solid fa-circle-info note__ic"></i>
            <div>
                <p class="note__title">Perhitungan ini belum memperhitungkan hari libur</p>
                <p style="margin-top:2px">
                    Hanya hari kerja Senin&ndash;Jumat yang dihitung. Hari libur nasional
                    dan tugas sekolah belum dikecualikan, sehingga angka pada bulan
                    dengan banyak hari libur bisa terlihat lebih rendah dari kenyataan.
                </p>
            </div>
        </div>
    </div>
</section>

<?php
$targetPersen = max(0, min(100, (int) $rekap['persen']));
require __DIR__ . '/../templates/siswa-foot.php';
?>
<script>
/* Sama seperti beranda: cincin diisi setelah render, dan shortcut
   prefers-reduced-motion dihormati. */
(function () {
    var cincin = document.getElementById('cincin');
    var teks   = document.getElementById('persenTampil');
    var target = <?= $targetPersen ?>;
    if (!cincin || !teks) { return; }

    var KELILING = 2 * Math.PI * 42;
    cincin.style.strokeDasharray = KELILING;

    if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        cincin.style.strokeDashoffset = KELILING - (KELILING * target / 100);
        teks.textContent = target + '%';
        return;
    }

    setTimeout(function () {
        cincin.style.strokeDashoffset = KELILING - (KELILING * target / 100);
        var s = 0;
        var timer = setInterval(function () {
            s += 2;
            if (s >= target) { s = target; clearInterval(timer); }
            teks.textContent = s + '%';
        }, 22);
    }, 220);
})();
</script>
