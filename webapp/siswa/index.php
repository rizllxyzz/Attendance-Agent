<?php
/**
 * siswa/index.php
 * ---------------------------------------------------------------------------
 * Beranda portal siswa.
 *
 * Halaman ini menjawab tiga pertanyaan dalam sekali lihat:
 *   1. Apakah saya sudah absen hari ini?
 *   2. Seberapa baik kehadiran saya bulan ini?
 *   3. Apa yang harus saya kerjakan berikutnya?
 *
 * Semua angka diambil dari database, tidak ada yang dihitung di peramban.
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

/* ------------------------------------------------------------------
 *  ABSENSI HARI INI
 * ------------------------------------------------------------------ */
$absenMasuk  = db_one(
    "SELECT * FROM attendance
      WHERE user_id = ? AND tanggal = ? AND scan_type = 'masuk'
      ORDER BY id DESC LIMIT 1",
    [$userId, $today]
);
$absenPulang = db_one(
    "SELECT * FROM attendance
      WHERE user_id = ? AND tanggal = ? AND scan_type = 'pulang'
      ORDER BY id DESC LIMIT 1",
    [$userId, $today]
);

/* Verifikasi lokasi yang perlu-che-perhatian ditampilkan terpisah dari
 * badge absensi, karena "hadir tapi lokasi ditolak" adalah kondisi yang
 * berbeda dari "tidak hadir". */
$gpsPerluPerhatian = [];
foreach ([['masuk', $absenMasuk], ['pulang', $absenPulang]] as [$tipe, $baris]) {
    if ($baris && (string) $baris['gps_verdict'] !== 'DITERIMA') {
        $gpsPerluPerhatian[] = ['tipe' => $tipe, 'baris' => $baris];
    }
}

/* ------------------------------------------------------------------
 *  REKAP BULAN INI
 * ------------------------------------------------------------------ */
$bulanIni   = $now->format('Y-m');
$awalBulan  = $bulanIni . '-01';

/* Rekap dihitung sampai HARI INI, bukan sampai akhir bulan. Kalau
 * dihitung sampai tanggal 30/31 sementara hari ini tanggal 3, hampir
 * seluruh hari kerja akan terhitung alpa dan persentasenya selalu
 * benam. */
$rekap = rekap_bulan($userId, $awalBulan, $today);

/* ------------------------------------------------------------------
 *  RIWAYAT TERBARU
 * ------------------------------------------------------------------ */
$riwayat = db_all(
    'SELECT tanggal, scan_type, waktu, status, is_late, late_minutes, gps_verdict, distance_m
       FROM attendance
      WHERE user_id = ?
      ORDER BY id DESC LIMIT 6',
    [$userId]
);

/* ------------------------------------------------------------------
 *  PENGUMUMAN / PETUNJUK
 * ------------------------------------------------------------------ */
$petunjuk = [];

/* Permintaan pindai ulang dari admin lebih spesifik daripada
 * "Face ID belum terdaftar": admin sudah tahu masalahnya, dan alasannya
 * perlu dibaca siswa sebelum mendaftarkan ulang. */
$permintaanWajah = wajah_permintaan_untuk($userId);
if ($permintaanWajah !== []) {
    $alasan = trim((string) ($permintaanWajah[0]['alasan'] ?? ''));
    $keterangan = 'Template wajah lama sudah dihapus, jadi Face ID tidak bisa dipakai '
        . 'absen sampai Anda daftar ulang. Selagi itu, absen dengan GPS atau kode manual.';
    if ($alasan !== '') {
        $keterangan = 'Alasan dari admin: "' . $alasan . '". ' . $keterangan;
    }
    $petunjuk[] = [
        'tipe'  => 'danger',
        'ikon'  => 'fa-id-card',
        'judul' => 'Admin meminta Anda mendaftarkan ulang wajah',
        'isi'   => $keterangan,
        'aksi'  => ['label' => 'Daftarkan ulang wajah', 'href' => '/siswa/scan-absen.php?mode=enroll'],
    ];
}

if (!$wajahTerdaftar) {
    $petunjuk[] = [
        'tipe'  => 'warn',
        'ikon'  => 'fa-face-viewfinder',
        'judul' => 'Face ID Belum Terdaftar',
        'isi'   => 'Kamu wajib mendaftarkan wajah untuk bisa absen. Daftarin sekarang biar bisa scan masuk/pulang.',
        'aksi'  => ['label' => 'Daftarkan Face ID Sekarang', 'href' => '/siswa/scan-absen.php?mode=enroll']
    ];
}
if ($gpsPerluPerhatian !== []) {
    $petunjuk[] = [
        'tipe'  => 'danger',
        'ikon'  => 'fa-location-crosshairs',
        'judul' => 'Verifikasi lokasi bermasalah',
        'isi'   => 'Ada absensi hari ini yang lokasi GPS-nya tidak diterima server. '
                 . 'Pastikan Anda berada di dalam area sekolah dan GPS perangkat menyala.',
        'aksi'  => null,
    ];
}
if ($faseHari === 'pulang' && $absenMasuk && !$absenPulang) {
    $petunjuk[] = [
        'tipe'  => 'warn',
        'ikon'  => 'fa-person-walking-arrow-right',
        'judul' => 'Waktu pulang sudah dibuka',
        'isi'   => 'Anda sudah tercatat masuk. Jika pulang lebih cepat karena izin resmi, pastikan pengajuan sudah disetujui dan lakukan scan pulang saat meninggalkan sekolah.',
        'aksi'  => ['label' => 'Buka scan pulang', 'href' => '/siswa/scan-absen.php'],
    ];
}
if ($faseHari === 'sebelum-masuk' && !$absenMasuk) {
    $petunjuk[] = [
        'tipe'  => 'info',
        'ikon'  => 'fa-clock',
        'judul' => 'Belum waktunya absen',
        'isi'   => 'Jendela absen masuk dibuka pukul ' . JAM_MASUK_MULAI . '. '
                 . 'Anda masih bisa absen masuk sampai ' . JAM_MASUK_AKHIR . '.',
        'aksi'  => null,
    ];
}

/* ------------------------------------------------------------------
 *  KONDISI TOMBOL SCAN
 * ------------------------------------------------------------------ */
$scanLabel = 'Buka Kamera Presensi';
$scanSub   = 'Scan Face ID & verifikasi lokasi GPS';
$scanSelesai = false;

if ($absenMasuk && $absenPulang) {
    $scanSelesai = true;
    $scanLabel = 'Presensi hari ini lengkap';
    $scanSub   = 'Masuk ' . jam_potong($absenMasuk['waktu'])
               . ' · pulang ' . jam_potong($absenPulang['waktu'])
               . ' · tetap bisa cek ulang';
} elseif ($absenPulang) {
    $scanLabel = 'Absen pulang sudah tercatat';
    $scanSub   = 'Masuk pukul ' . jam_potong($absenMasuk['waktu'] ?? null)
               . '. Anda bisa absen pulang lagi kalau salah.';
}

/* ------------------------------------------------------------------
 *  VARIABEL UNTUK TEMPLATE
 * ------------------------------------------------------------------ */
$pageKey   = 'beranda';
$pageTitle = 'Beranda';
$pageSub   = tgl_panjang($today);

require __DIR__ . '/../templates/siswa-head.php';
?>

<!-- ============ HERO GREETING CARD (Sketch: 2-Column Split) ============ -->
<section class="grid">
    <div class="col-8 rise rise-2">
        <div class="hero">
            <span class="hero__greet">
                <i class="fa-solid <?= $salam === 'Selamat Malam' ? 'fa-moon' : ($salam === 'Selamat Pagi' ? 'fa-sun' : 'fa-cloud-sun') ?>"></i>
                <?= esc($salam) ?>
            </span>
            <h2 class="hero__name"><?= esc($namaRingkas) ?></h2>

            <div class="hero__grid">
                <!-- Kiri: Hari & Identitas Siswa (Sketch: Selasa + Username) -->
                <div class="hero__col">
                    <div class="hero__item-header">
                        <i class="fa-regular fa-calendar text-brand"></i>
                        <span><?= esc(tgl_panjang($today)) ?></span>
                    </div>
                    <div class="hero__chips">
                        <span class="chip chip--user">
                            <i class="fa-solid fa-id-card"></i><?= esc($labelIndentitas) ?> <?= esc($nomorIndentitas) ?>
                        </span>
                        <span class="chip<?= $wajahTerdaftar ? ' chip--ok' : '' ?>">
                            <i class="fa-solid <?= $wajahTerdaftar ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"></i>
                            <?= $wajahTerdaftar ? 'Face ID Aktif' : 'Belum Face ID' ?>
                        </span>
                    </div>
                </div>

                <!-- Kanan: Lokasi Sekolah & Kelas (Sketch: Tanjung + Kelas) -->
                <div class="hero__col">
                    <div class="hero__item-header">
                        <i class="fa-solid fa-location-dot text-emerald"></i>
                        <span><?= esc($lokasiNama) ?></span>
                    </div>
                    <div class="hero__chips">
                        <span class="chip chip--brand">
                            <i class="fa-solid fa-users"></i><?= esc($kelasNama !== '' ? $kelasNama : 'Belum ada kelas') ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-4 rise rise-2">
        <div class="card card--pad" style="height:100%;display:flex;align-items:center;gap:18px">
            <div class="ring" style="width:104px;height:104px">
                <svg width="104" height="104" viewBox="0 0 100 100" aria-hidden="true">
                    <circle cx="50" cy="50" r="42" fill="none" stroke="#eef0f6" stroke-width="9"/>
                    <circle id="cincin" cx="50" cy="50" r="42" fill="none" stroke="url(#gradCincin)"
                            stroke-width="9" stroke-linecap="round"
                            stroke-dasharray="263.9" stroke-dashoffset="263.9"/>
                    <defs>
                        <linearGradient id="gradCincin" x1="0" y1="0" x2="1" y2="1">
                            <stop offset="0%" stop-color="#4f46e5"/>
                            <stop offset="100%" stop-color="#10b981"/>
                        </linearGradient>
                    </defs>
                </svg>
                <div class="ring__label">
                    <span class="ring__value" id="persenTampil">0%</span>
                    <span class="ring__cap">Bulan ini</span>
                </div>
            </div>

            <div style="flex:1;min-width:0">
                <p class="card__sub" style="margin:0 0 10px">
                    Kehadiran bulan <?= esc(nama_bulan((int) $now->format('n'))) ?>
                </p>
                <div class="ministat">
                    <div class="ministat__row">
                        <span class="ministat__key">Hadir</span>
                        <span class="ministat__val num"><?= $rekap['hadir'] ?> / <?= $rekap['efektif'] ?></span>
                    </div>
                    <div class="ministat__row">
                        <span class="ministat__key">Tepat waktu</span>
                        <span class="ministat__val num ministat__val--ok"><?= $rekap['tepat'] ?></span>
                    </div>
                    <div class="ministat__row">
                        <span class="ministat__key">Terlambat</span>
                        <span class="ministat__val num ministat__val--warn"><?= $rekap['telat'] ?></span>
                    </div>
                    <div class="ministat__row">
                        <span class="ministat__key">Tanpa catatan</span>
                        <span class="ministat__val num"><?= $rekap['alpa'] ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ============ STATUS HARI INI ============ -->
<section class="grid grid--2" style="margin-top:16px">
    <div class="rise rise-2">
        <div class="att<?= $absenMasuk ? (!empty($absenMasuk['is_late']) ? ' is-late' : ' is-done') : '' ?>">
            <div class="att__head">
                <span class="att__label">Absen Masuk</span>
                <?= badge_absen($absenMasuk, 'masuk') ?>
            </div>
            <?php if ($absenMasuk): ?>
                <p class="att__time"><?= esc(jam_potong($absenMasuk['waktu'])) ?></p>
                <p class="att__note <?= !empty($absenMasuk['is_late']) ? 'is-warn' : 'is-ok' ?>">
                    <?php if (!empty($absenMasuk['is_late'])): ?>
                        Terlambat <?= (int) ($absenMasuk['late_minutes'] ?? 0) ?> menit
                        dari batas <?= esc(JAM_MASUK_AKHIR) ?>
                    <?php else: ?>
                        Tepat waktu, sebelum batas <?= esc(JAM_MASUK_AKHIR) ?>
                    <?php endif; ?>
                </p>
            <?php else: ?>
                <p class="att__time-empty">--:--</p>
                <p class="att__note">Jendela masuk <?= esc(JAM_MASUK_MULAI) ?> &ndash; <?= esc(JAM_MASUK_AKHIR) ?></p>
            <?php endif; ?>
        </div>
    </div>

    <div class="rise rise-3">
        <div class="att<?= $absenPulang ? ' is-done' : '' ?>">
            <div class="att__head">
                <span class="att__label">Absen Pulang</span>
                <?= badge_absen($absenPulang, 'pulang') ?>
            </div>
            <?php if ($absenPulang): ?>
                <p class="att__time"><?= esc(jam_potong($absenPulang['waktu'])) ?></p>
                <p class="att__note is-ok">Presensi pulang selesai</p>
            <?php else: ?>
                <p class="att__time-empty">--:--</p>
                <p class="att__note">Jendela pulang <?= esc(JAM_PULANG_MULAI) ?> &ndash; <?= esc(JAM_PULANG_AKHIR) ?></p>
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- ============ TOMBOL UTAMA ============ -->
<section class="grid rise rise-3" style="margin-top:16px">
    <div class="col-12">
        <a class="cta<?= $scanSelesai ? ' cta--done' : '' ?>" href="/siswa/scan-absen.php">
            <span class="cta__ic">
                <i class="fa-solid <?= $scanSelesai ? 'fa-circle-check' : 'fa-camera' ?>"></i>
            </span>
            <span class="cta__body">
                <span class="cta__title"><?= $scanLabel ?></span>
                <span class="cta__sub"><?= $scanSub ?></span>
            </span>
            <span class="cta__go"><i class="fa-solid fa-arrow-right"></i></span>
        </a>
    </div>
</section>

<!-- ============ PETUNJUK / PENGUMUMAN ============ -->
<?php if ($petunjuk !== []): ?>
<section class="grid rise rise-4" style="margin-top:16px">
    <div class="col-12">
        <div style="display:grid;gap:12px">
            <?php foreach ($petunjuk as $p): ?>
                <div class="note note--<?= esc($p['tipe']) ?>" style="padding:20px;gap:16px;border-radius:20px;box-shadow:0 8px 24px rgba(0,0,0,.08);display:flex;align-items:center">
                    <div style="width:56px;height:56px;border-radius:16px;background:linear-gradient(135deg,#fff,#f1f5f9);display:flex;align-items:center;justify-content:center;flex-shrink:0;box-shadow:0 4px 12px rgba(0,0,0,.06)">
                        <i class="fa-solid <?= esc($p['ikon']) ?>" style="font-size:22px;color:var(--warn)"></i>
                    </div>
                    <div style="flex:1;min-width:0">
                        <p class="note__title" style="font-size:16px;font-weight:700;margin-bottom:4px"><?= esc($p['judul']) ?></p>
                        <p style="margin:0;font-size:13.5px;line-height:1.55;color:var(--text-2)"><?= esc($p['isi']) ?></p>
                        <?php if (!empty($p['aksi'])): ?>
                            <p style="margin-top:12px">
                                <a class="btn btn--primary" href="<?= esc($p['aksi']['href']) ?>" style="padding:10px 22px;border-radius:12px;font-weight:600;font-size:14px;box-shadow:0 4px 14px rgba(29,78,216,.25)">
                                    <i class="fa-solid fa-camera" style="margin-right:8px"></i><?= esc($p['aksi']['label']) ?>
                                </a>
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ============ AKSI CEPAT ============ -->
<section class="grid rise rise-5" style="margin-top:16px">
    <div class="col-12">
        <div class="quick">
            <a class="quick__item quick__item--brand" href="/siswa/scan-absen.php">
                <span class="quick__ic"><i class="fa-solid fa-camera"></i></span>
                Scan Face
            </a>
            <?php if (!$wajahTerdaftar): ?>
            <a class="quick__item quick__item--warn" href="/siswa/scan-absen.php?mode=enroll">
                <span class="quick__ic"><i class="fa-solid fa-face-smile"></i></span>
                Daftar Face ID
            </a>
            <?php endif; ?>
            <a class="quick__item quick__item--info" href="/siswa/riwayat.php">
                <span class="quick__ic"><i class="fa-solid fa-clock-rotate-left"></i></span>
                Riwayat
            </a>
            <a class="quick__item quick__item--ok" href="/siswa/izin.php">
                <span class="quick__ic"><i class="fa-solid fa-file-signature"></i></span>
                Izin / Sakit
            </a>
            <a class="quick__item quick__item--muted" href="/siswa/profil.php">
                <span class="quick__ic"><i class="fa-solid fa-user-gear"></i></span>
                Profil Saya
            </a>
        </div>
    </div>
</section>

<!-- ============ AKTIVITAS TERBARU ============ -->
<section class="grid" style="margin-top:16px">
    <div class="col-12 rise rise-5">
        <div class="card card--flush">
            <div class="card__head">
                <div>
                    <h2 class="card__title">Aktivitas terbaru</h2>
                    <p class="card__sub">6 scan terakhir</p>
                </div>
                <a class="card__link" href="/siswa/riwayat.php">Semua riwayat</a>
            </div>

            <?php if ($riwayat === []): ?>
                <div class="empty">
                    <span class="empty__ic"><i class="fa-regular fa-calendar-xmark"></i></span>
                    <p class="empty__title">Belum ada presensi</p>
                    <p class="empty__text">
                        Riwayat akan muncul setelah absen masuk pertama kali.
                    </p>
                    <a class="btn btn--sm btn--primary" href="/siswa/scan-absen.php">
                        <i class="fa-solid fa-camera"></i>Buka Kamera
                    </a>
                </div>
            <?php else: ?>
                <div class="feed">
                    <?php foreach ($riwayat as $r): ?>
                        <?php
                        $isMasuk = $r['scan_type'] === 'masuk';
                        $telat   = !empty($r['is_late']);
                        $kelasIc = $telat ? 'feed__ic--late' : ($isMasuk ? 'feed__ic--in' : 'feed__ic--out');
                        ?>
                        <div class="feed__item">
                            <span class="feed__ic <?= $kelasIc ?>">
                                <i class="fa-solid <?= $isMasuk ? 'fa-right-to-bracket' : 'fa-right-from-bracket' ?>"></i>
                            </span>
                            <div class="feed__body">
                                <p class="feed__title">
                                    <?= $isMasuk ? 'Absen masuk' : 'Absen pulang' ?>
                                </p>
                                <p class="feed__meta">
                                    <?= esc(lbl_tanggal((string) $r['tanggal'], $today)) ?>
                                    · <?= esc(ket_gps($r)) ?>
                                </p>
                            </div>
                            <span class="feed__time"><?= esc(jam_potong((string) $r['waktu'])) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- ============ CATATAN PENTING ============ -->
<section class="grid rise rise-6" style="margin-top:16px;padding-bottom:4px">
    <div class="col-12">
        <div class="note note--info">
            <i class="fa-solid fa-circle-info note__ic"></i>
            <div>
                <p class="note__title">Sebelum absen, perhatikan ini</p>
                <p style="margin-top:2px">
                    Wajah harus terlihat jelas di dalam bingkai, dan GPS perangkat
                    harus menyala &mdash; verifikasi lokasi dilakukan server memakai
                    radius <?= esc(fmt_distance((float) $lokasiRadius)) ?> dari
                    <?= esc($lokasiNama) ?>. Presensi tidak bisa diubah sendiri
                    setelah tercatat.
                </p>
            </div>
        </div>
    </div>
</section>

<?php
/* ------------------------------------------------------------------
 *  Animasi cincin persentase
 *  ------------------------------------------------------------------ */
$targetPersen = max(0, min(100, (int) $rekap['persen']));
require __DIR__ . '/../templates/siswa-foot.php';
?>
<script>
/* Cincin dan angka persentase diisi setelah halaman dirender supaya angka
   tidak terlihat melompat dari 0 saat layout masih dihitung. */
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
        var sekarang = 0;
        var penghitung = setInterval(function () {
            sekarang += 2;
            if (sekarang >= target) {
                sekarang = target;
                clearInterval(penghitung);
            }
            teks.textContent = sekarang + '%';
        }, 22);
    }, 220);
})();
</script>
