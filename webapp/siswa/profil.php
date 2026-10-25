<?php
/**
 * siswa/profil.php
 * ---------------------------------------------------------------------------
 * Data akun siswa, status Face ID, dan perangkat yang pernah dipakai.
 *
 * Kolom yang ditampilkan di sini dibaca dari database, bukan dari sesi.
 * Sesi sengaja hanya menyimpan seperlunya saja, dan tidak cocok untuk
 * ditampilkan sebagai identitas resmi.
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

/* ------------------------------------------------------------------
 *  REKAP KESELURUHAN (seumur akun, bukan per bulan)
 * ------------------------------------------------------------------ */
$semuaHari = db_all(
    "SELECT DISTINCT tanggal FROM attendance
      WHERE user_id = ? AND scan_type = 'masuk'",
    [$userId]
);
$totalHadir = count($semuaHari);
$totalScan  = (int) db_value(
    'SELECT COUNT(*) FROM attendance WHERE user_id = ?',
    [$userId],
    0
);
$totalTelat = (int) db_value(
    "SELECT COUNT(*) FROM attendance WHERE user_id = ? AND is_late = 1 AND scan_type = 'masuk'",
    [$userId],
    0
);
$pertamaMasuk = db_value(
    "SELECT MIN(waktu) FROM attendance WHERE user_id = ? AND scan_type = 'masuk'",
    [$userId],
    null
);
$terakhirMasuk = db_value(
    "SELECT MAX(waktu) FROM attendance WHERE user_id = ? AND scan_type = 'masuk'",
    [$userId],
    null
);

/* ------------------------------------------------------------------
 *  KEAMANAN
 * ------------------------------------------------------------------ */
$perangkat = db_all(
    'SELECT fingerprint, user_agent, ip_address, seen_at
       FROM device_log
      WHERE user_id = ?
      ORDER BY seen_at DESC LIMIT 5',
    [$userId]
);

$loginTerakhir = $barisUser['last_login'] !== null
    ? (string) $barisUser['last_login']
    : '';

/* Peringatan yang perlu ditampilkan, dikumpulkan di satu tempat supaya
 * urutannya dari yang paling penting. */
$peringatan = [];

/* Permintaan dari admin lebih penting daripada "belum terdaftar" biasa:
 * alasannya perlu dibaca, dan template lama inilah yang membuat
 * Face ID tidak bisa dipakai absen. */
$permintaanWajah = wajah_permintaan_untuk($userId);
if ($permintaanWajah !== []) {
    $alasan = trim((string) ($permintaanWajah[0]['alasan'] ?? ''));
    $keterangan = 'Template wajah lama sudah dihapus oleh admin, jadi Face ID tidak '
        . 'bisa dipakai absen sampai Anda daftar ulang dengan kamera Anda sendiri.';
    if ($alasan !== '') {
        $keterangan = 'Alasan dari admin: "' . $alasan . '". ' . $keterangan;
    }
    $peringatan[] = [
        'tipe'  => 'danger',
        'ikon'  => 'fa-id-card',
        'judul' => 'Admin meminta pendaftaran ulang wajah',
        'isi'   => $keterangan,
        'aksi'  => ['label' => 'Daftarkan ulang', 'href' => '/siswa/scan-absen.php?mode=enroll'],
    ];
} elseif (!$wajahTerdaftar) {
    $peringatan[] = [
        'tipe'  => 'warn',
        'ikon'  => 'fa-face-smile',
        'judul' => 'Face ID belum terdaftar',
        'isi'   => 'Tanpa template wajah, absen tidak akan bisa diverifikasi. '
                 . 'Daftarkan dari halaman scan memakai kamera Anda sendiri.',
        'aksi'  => ['label' => 'Daftarkan wajah', 'href' => '/siswa/scan-absen.php?mode=enroll'],
    ];
} elseif ($wajahMutu < 0.30) {
    $peringatan[] = [
        'tipe'  => 'info',
        'ikon'  => 'fa-face-smile',
        'judul' => 'Kualitas Face ID masih rendah',
        'isi'   => 'Template wajah kamu tercatat kurang jelas (mutu '
                 . round($wajahMutu * 100) . '%). Pendaftaran ulang di tempat '
                 . 'terang biasanya membuat pencocokan lebih akurat.',
        'aksi'  => ['label' => 'Daftarkan ulang', 'href' => '/siswa/scan-absen.php?mode=enroll'],
    ];
}

if ($totalHadir === 0) {
    $peringatan[] = [
        'tipe'  => 'info',
        'ikon'  => 'fa-camera',
        'judul' => 'Belum pernah absen',
        'isi'   => 'Belum ada scan masuk yang tercatat untuk akun ini. '
                 . 'Pastikan Anda absen sesuai jadwal.',
        'aksi'  => ['label' => 'Buka kamera', 'href' => '/siswa/scan-absen.php'],
    ];
}

$pageKey   = 'profil';
$pageTitle = 'Profil Saya';
$pageSub   = $nomorIndentitas !== '' ? $labelIndentitas . ' ' . $nomorIndentitas : $username;

require __DIR__ . '/../templates/siswa-head.php';
?>

<!-- ============ KARTU IDENTITAS ============ -->
<section class="grid">
    <div class="col-5 rise rise-1">
        <div class="card card--pad">
            <div style="display:flex;align-items:center;gap:16px">
                <span class="avatar" style="width:64px;height:64px;font-size:22px"><?= esc($initials) ?></span>
                <div style="min-width:0">
                    <h2 style="font-size:17px;letter-spacing:-.02em"><?= esc($nama) ?></h2>
                    <p style="font-size:12.5px;color:var(--text-3);margin-top:2px">
                        <?= esc($kelasNama !== '' ? $kelasNama : 'Belum terdaftar di kelas') ?>
                    </p>
                    <p style="margin-top:8px">
                        <?php if ($wajahTerdaftar): ?>
                            <span class="badge badge--ok"><i class="dot"></i>Face ID aktif</span>
                        <?php else: ?>
                            <span class="badge badge--warn"><i class="dot"></i>Face ID belum</span>
                        <?php endif; ?>
                    </p>
                </div>
            </div>

            <div class="detail" style="margin-top:18px">
                <div class="detail__row">
                    <span class="detail__key">Nama lengkap</span>
                    <span class="detail__val"><?= esc($nama) ?></span>
                </div>
                <div class="detail__row">
                    <span class="detail__key">Username</span>
                    <span class="detail__val"><?= esc($username) ?></span>
                </div>
                <div class="detail__row">
                    <span class="detail__key"><?= esc($labelIndentitas) ?></span>
                    <span class="detail__val"><?= esc($nomorIndentitas !== '' ? $nomorIndentitas : '-') ?></span>
                </div>
                <div class="detail__row">
                    <span class="detail__key">Kelas</span>
                    <span class="detail__val"><?= esc($kelasNama !== '' ? $kelasNama : '-') ?></span>
                </div>
                <div class="detail__row">
                    <span class="detail__key">Jenis kelamin</span>
                    <span class="detail__val"><?= esc($jkLabel) ?></span>
                </div>
            </div>
        </div>
    </div>

    <div class="col-7 rise rise-2">
        <!-- grid--pair: 2x2, bukan 4 kesamping. Grid ini duduk di
             dalam kolom .col-7, jadi empat kartu dalam satu baris
             akan jauh lebih sempit daripada kartu KPI di halaman lain. -->
        <div class="grid grid--kpi grid--pair">
            <div class="kpi kpi--ok">
                <span class="kpi__ic"><i class="fa-solid fa-calendar-check"></i></span>
                <div class="kpi__body">
                    <p class="kpi__value"><?= $totalHadir ?></p>
                    <p class="kpi__label">Hari hadir</p>
                    <p class="kpi__hint">sejak mulai absen</p>
                </div>
            </div>
            <div class="kpi kpi--warn">
                <span class="kpi__ic"><i class="fa-solid fa-triangle-exclamation"></i></span>
                <div class="kpi__body">
                    <p class="kpi__value"><?= $totalTelat ?></p>
                    <p class="kpi__label">Kali terlambat</p>
                </div>
            </div>
            <div class="kpi kpi--brand">
                <span class="kpi__ic"><i class="fa-solid fa-qrcode"></i></span>
                <div class="kpi__body">
                    <p class="kpi__value"><?= $totalScan ?></p>
                    <p class="kpi__label">Total scan</p>
                </div>
            </div>
            <div class="kpi kpi--info">
                <span class="kpi__ic"><i class="fa-solid fa-clock-rotate-left"></i></span>
                <div class="kpi__body">
                    <p class="kpi__value" style="font-size:15px;line-height:1.4">
                        <?= esc($pertamaMasuk ? lbl_tanggal(substr((string) $pertamaMasuk, 0, 10), $today) : '-') ?>
                    </p>
                    <p class="kpi__label">Presensi pertama</p>
                </div>
            </div>
        </div>

        <div class="card card--pad" style="margin-top:16px">
            <h2 class="card__title">Face ID</h2>
            <p class="card__sub" style="margin-bottom:14px">
                Data yang dipakai untuk memverifikasi wajah saat absen
            </p>

            <div class="detail">
                <div class="detail__row">
                    <span class="detail__key">Status</span>
                    <span class="detail__val">
                        <?= $wajahTerdaftar ? 'Terdaftar' : 'Belum terdaftar' ?>
                    </span>
                </div>
                <div class="detail__row">
                    <span class="detail__key">Tanggal pendaftaran</span>
                    <span class="detail__val"><?= esc($wajahTerdaftar ? tgl_pendek($wajahSejak) : '-') ?></span>
                </div>
                <div class="detail__row">
                    <span class="detail__key">Mutu template</span>
                    <span class="detail__val"><?= $wajahTerdaftar ? round($wajahMutu * 100) . ' / 100' : '-' ?></span>
                </div>
                <div class="detail__row">
                    <span class="detail__key">Ambang pencocokan</span>
                    <span class="detail__val num"><?= esc((string) FACE_MATCH_THRESHOLD) ?></span>
                </div>
            </div>

            <?php if ($wajahTerdaftar): ?>
                <div style="margin-top:14px">
                    <div class="meter">
                        <div class="meter__track">
                            <div class="meter__fill" style="width:<?= (int) round(min(1, $wajahMutu) * 100) ?>%"></div>
                        </div>
                        <p class="meter__cap">
                            Mutu di atas 60 dianggap baik. Semakin tinggi, template
                            semakin konsisten mewakili wajah Anda.
                        </p>
                    </div>
                </div>
            <?php endif; ?>

            <p style="margin-top:14px">
                <a class="btn btn--sm <?= $wajahTerdaftar ? 'btn--ghost' : 'btn--primary' ?>"
                   href="/siswa/scan-absen.php?mode=enroll">
                    <i class="fa-solid fa-camera"></i>
                    <?= $wajahTerdaftar ? 'Daftarkan ulang wajah' : 'Daftarkan wajah sekarang' ?>
                </a>
            </p>
        </div>
    </div>
</section>

<!-- ============ PERINGATAN ============ -->
<?php if ($peringatan !== []): ?>
<section class="grid rise rise-3" style="margin-top:16px">
    <div class="col-12">
        <div style="display:grid;gap:10px">
            <?php foreach ($peringatan as $p): ?>
                <div class="note note--<?= esc($p['tipe']) ?>">
                    <i class="fa-solid <?= esc($p['ikon']) ?> note__ic"></i>
                    <div style="flex:1;min-width:0">
                        <p class="note__title"><?= esc($p['judul']) ?></p>
                        <p style="margin-top:2px"><?= esc($p['isi']) ?></p>
                        <?php if (!empty($p['aksi'])): ?>
                            <p style="margin-top:8px">
                                <a class="btn btn--sm btn--primary" href="<?= esc($p['aksi']['href']) ?>">
                                    <?= esc($p['aksi']['label']) ?>
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

<!-- ============ AKUN & KEAMANAN ============ -->
<section class="grid" style="margin-top:16px">
    <div class="col-6 rise rise-4">
        <div class="card card--flush">
            <div class="card__head">
                <div>
                    <h2 class="card__title">Akun &amp; keamanan</h2>
                    <p class="card__sub">Informasi sesi dan perangkat</p>
                </div>
            </div>

            <div class="detail">
                <div class="detail__row">
                    <span class="detail__key">Login terakhir</span>
                    <span class="detail__val"><?= esc($loginTerakhir !== '' ? fmt_datetime($loginTerakhir) : 'tidak tercatat') ?></span>
                </div>
                <div class="detail__row">
                    <span class="detail__key">Presensi terakhir</span>
                    <span class="detail__val"><?= esc($terakhirMasuk ? fmt_datetime((string) $terakhirMasuk) : 'belum pernah') ?></span>
                </div>
                <div class="detail__row">
                    <span class="detail__key">Lokasi presensi</span>
                    <span class="detail__val">
                        <?= esc($lokasiNama) ?>
                        <span style="display:block;font-weight:500;color:var(--text-3);font-size:11.5px">
                            radius <?= esc(fmt_distance((float) $lokasiRadius)) ?>
                        </span>
                    </span>
                </div>
            </div>
        </div>
    </div>

    <div class="col-6 rise rise-5">
        <div class="card card--flush">
            <div class="card__head">
                <div>
                    <h2 class="card__title">Perangkat terdaftar</h2>
                    <p class="card__sub">5 perangkat terakhir yang dipakai absen</p>
                </div>
            </div>

            <?php if ($perangkat === []): ?>
                <div class="empty" style="padding:28px 20px">
                    <span class="empty__ic"><i class="fa-solid fa-mobile-screen"></i></span>
                    <p class="empty__title">Belum ada catatan perangkat</p>
                    <p class="empty__text">
                        Catatan muncul setelah Anda absen dari perangkat ini.
                    </p>
                </div>
            <?php else: ?>
                <div class="feed">
                    <?php foreach ($perangkat as $d): ?>
                        <div class="feed__item">
                            <span class="feed__ic feed__ic--out">
                                <i class="fa-solid fa-mobile-screen"></i>
                            </span>
                            <div class="feed__body">
                                <p class="feed__title">
                                    <?= esc(ringkas_agent((string) $d['user_agent'])) ?>
                                </p>
                                <p class="feed__meta">
                                    <?= esc(($d['ip_address'] ?? '-')) ?>
                                    &middot; <?= esc(tgl_pendek((string) $d['seen_at'])) ?>
                                </p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- ============ CATATAN PRIVASI ============ -->
<section class="grid rise rise-6" style="margin-top:16px;padding-bottom:4px">
    <div class="col-12">
        <div class="note note--info">
            <i class="fa-solid fa-shield-halved note__ic"></i>
            <div>
                <p class="note__title">Soal data wajah Anda</p>
                <p style="margin-top:2px">
                    Yang disimpan adalah 128 angka cirikan wajah (512 byte), bukan foto.
                    Data ini tidak bisa diubah kembali menjadi gambar wajah, dan tidak
                    pernah dikirim ke pihak ketiga. Setiap absen yang Anda lakukan
                    tercatat lengkap dengan waktu, lokasi, dan sidik perangkat.
                </p>
            </div>
        </div>

        <div class="note note--warn" style="margin-top:10px">
            <i class="fa-solid fa-triangle-exclamation note__ic"></i>
            <div>
                <p class="note__title">Keluar dari akun</p>
                <p style="margin-top:2px">
                    Sesi di perangkat publik sebaiknya ditutup setelah selesai.
                    <form method="post" action="/auth/logout.php" style="display:inline">
                        <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>">
                        <button type="submit" style="background:none;border:none;padding:0;color:inherit;text-decoration:underline;cursor:pointer;font:inherit">Keluar sekarang</button>
                    </form>
                </p>
            </div>
        </div>
    </div>
</section>

<?php require __DIR__ . '/../templates/siswa-foot.php'; ?>
