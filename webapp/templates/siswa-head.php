<?php
/**
 * templates/siswa-head.php
 * ---------------------------------------------------------------------------
 * Kerangka atas halaman siswa: <head>, sidebar desktop, header gaya
 * Dashboard, dan pembuka konten.
 *
 * Halaman pemanggil harus:
 *   1. require_once __DIR__ . '/../siswa/_bootstrap.php'
 *   2. set $pageKey   (kunci menu: beranda|riwayat|statistik|izin|lapor|profil)
 *   3. set $pageTitle dan $pageSub
 *   4. set variabel kendali tampilan yang mau dipakai (mis. $jumlahIzin)
 *   5. include file ini
 *
 * Berkas ini hanya membuka tag, seluruh </div> penutup ada di
 * siswa-foot.php. Jangan sampai tidak berpasangan.
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);

if (!defined('APP_NAME')) {
    /* Included tanpa bootstrap: hentikan daripada menampilkan halaman
     * separuh jadi yang tidak punya sesi. */
    http_response_code(500);
    exit('Halaman tidak lengkap dimuat.');
}

$pageKey   = $pageKey   ?? 'beranda';
$pageTitle = $pageTitle ?? t('nav.beranda');
$pageSub   = $pageSub   ?? '';
$pageActionsHtml = $pageActionsHtml ?? '';

/* Notifikasi sekali-pakai dari POST (mis. setelah kirim pengajuan izin). */
$flash = null;
if (!empty($_SESSION['siswa_flash'])) {
    $flash = $_SESSION['siswa_flash'];
    unset($_SESSION['siswa_flash']);
}

/**
 * Menu siswa. $kunci dipakai untuk menandai aktif, $mobile menentukan
 * item mana yang masuk ke tab bar HP (tab bar tidak muat 6 item).
 */
$menu = [
    ['kunci' => 'beranda',   'href' => '/siswa/index.php',      'label' => t('nav.beranda'),    'icon' => 'fa-house',            'mobile' => true],
    ['kunci' => 'riwayat',   'href' => '/siswa/riwayat.php',    'label' => t('nav.riwayat'),    'icon' => 'fa-clock-rotate-left', 'mobile' => true],
    ['kunci' => 'izin',      'href' => '/siswa/izin.php',       'label' => t('nav.izin_sakit'), 'icon' => 'fa-file-signature',  'mobile' => true],
    ['kunci' => 'statistik', 'href' => '/siswa/statistik.php',  'label' => t('nav.statistik'),  'icon' => 'fa-chart-simple',     'mobile' => true],
    /* Lapor Aman ada di sidebar dan sheet akun, plus panel gantung di bar
       bawah (lihat siswa-foot.php). Sengaja TIDAK ditambahkan ke pil tengah:
       pil itu sudah pas untuk dua tujuan yang dipakai harian, sedangkan
       laporan sifatnya sekali-sekali tapi penting untuk selalu bisa dijangkau. */
    ['kunci' => 'lapor',     'href' => '/siswa/lapor.php',      'label' => t('nav.lapor_aman'), 'icon' => 'fa-shield-heart',   'mobile' => true],
    // ['kunci' => 'agent',     'href' => '/siswa/agent.php',      'label' => 'Asisten Presensi', 'icon' => 'fa-robot',            'mobile' => false],
    ['kunci' => 'profil',    'href' => '/siswa/profil.php',     'label' => t('nav.profil'),     'icon' => 'fa-user-gear',         'mobile' => false],
];

/* Jumlah pengajuan izin yang masih menunggu, untuk penanda di menu. */
$tagIzin = 0;
try {
    $tagIzin = (int) db_value(
        "SELECT COUNT(*) FROM izin_requests WHERE user_id = ? AND status = 'menunggu'",
        [$userId],
        0
    );
} catch (Throwable $e) {
    /* Tabel izin belum ada pada instalasi lama. Menu tetap tampil, hanya
     * penandainya yang dilewati. */
    $tagIzin = 0;
}
?>
<!DOCTYPE html>
<html lang="<?= esc(lang_active()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#a3241a">
<meta name="color-scheme" content="light">
<title><?= esc($pageTitle) ?> &middot; <?= esc(APP_NAME) ?></title>
<meta name="description" content="<?= te('siswa.portal_meta') ?> <?= esc(APP_NAME) ?>">
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='8' fill='%23a3241a'/%3E%3Cpath d='M16 8l10 4.5-10 4.5-10-4.5L16 8z' fill='white'/%3E%3Cpath d='M10 15v4c0 1.7 2.7 3 6 3s6-1.3 6-3v-4' stroke='white' stroke-width='2' fill='none' stroke-linecap='round'/%3E%3C/svg%3E">
<!--
  Ikon memakai Font Awesome. Versi dikunci supaya tampilan tidak berubah
  diam-diam saat Font Awesome rilis versi baru.

  Tanpa atribut integrity: hash-nya harus dihitung dari berkas asli, dan
  hash yang diketik tangan justru membuat stylesheet ditolak browser.
  Kalau proyek ini nanti dijalankan tanpa internet, unduh sekali lalu
  taruh di assets/vendor/fontawesome/ dan ganti href di bawah dengan
  path lokal — tidak ada bagian lain yang perlu berubah, karena semua
  ikon memakai kelas .fa-*.

  Kotak ikon punya ukuran tetap (lihat .cta__ic / .feed__ic / .logo di
  siswa.css), jadi kalau stylesheet ini gagal dimuat, tata letak tetap
  rapi: hanya simbolnya yang kosong.
-->
<link rel="stylesheet" href="/assets/vendor/fontawesome/css/all.min.css">
<link rel="stylesheet" href="/assets/css/siswa.css?v=9">
<!-- Pre-fetch Face ID models di background agar saat buka kamera langsung 100% instan -->
<link rel="prefetch" href="/assets/vendor/face-api/face-api.js" as="script">
<link rel="prefetch" href="/assets/models/tiny_face_detector_model-weights_manifest.json" as="fetch" crossorigin>
<link rel="prefetch" href="/assets/models/tiny_face_detector_model.bin" as="fetch" crossorigin>
<link rel="prefetch" href="/assets/models/face_landmark_68_tiny_model-weights_manifest.json" as="fetch" crossorigin>
<link rel="prefetch" href="/assets/models/face_landmark_68_tiny_model.bin" as="fetch" crossorigin>
<link rel="prefetch" href="/assets/models/face_recognition_model-weights_manifest.json" as="fetch" crossorigin>
<link rel="prefetch" href="/assets/models/face_recognition_model.bin" as="fetch" crossorigin>
</head>
<body>
<a class="skip-link" href="#utama"><?= te('shell.skip') ?></a>

<div class="app">

    <!-- ============ SIDEBAR (desktop >= 1024px) ============ -->
    <aside class="sidebar" aria-label="<?= te('shell.nav_main') ?>">
        <div class="sidebar__inner">

            <a class="sb-brand" href="/siswa/index.php">
                <span class="logo"><i class="fa-solid fa-graduation-cap"></i></span>
                <span class="sb-brand__text">
                    <span class="sb-brand__name"><?= esc(APP_NAME) ?></span>
                    <span class="sb-brand__sub"><?= te('shell.portal_siswa') ?></span>
                </span>
            </a>

            <nav class="sb-nav">
                <?php
                /* Menu siswa dikelompokkan agar terbaca cepat. Item di luar
                 * daftar grup tetap ditampilkan supaya menu baru tidak
                 * hilang tanpa sengaja. */
                $kelompok = [
                    t('group.utama')     => ['beranda', 'riwayat', 'statistik'],
                    t('group.pengajuan') => ['izin', 'lapor'],
                    t('group.akun')      => ['profil'],
                ];
                $petaMenu = [];
                foreach ($menu as $m) {
                    $petaMenu[$m['kunci']] = $m;
                }
                $tergambar = [];
                foreach ($kelompok as $judulGrup => $kunciGrup):
                    $anggota = [];
                    foreach ($kunciGrup as $kunci) {
                        if (isset($petaMenu[$kunci])) {
                            $anggota[] = $petaMenu[$kunci];
                            $tergambar[] = $kunci;
                        }
                    }
                    if ($anggota === []) {
                        continue;
                    }
                    ?>
                    <p class="sb-nav__label"><?= esc($judulGrup) ?></p>
                    <?php foreach ($anggota as $m): ?>
                        <a class="sb-link<?= $pageKey === $m['kunci'] ? ' is-active' : '' ?>"
                           href="<?= esc($m['href']) ?>"
                           <?= $pageKey === $m['kunci'] ? 'aria-current="page"' : '' ?>>
                            <i class="fa-solid <?= esc($m['icon']) ?>"></i>
                            <span><?= esc($m['label']) ?></span>
                            <?php if ($m['kunci'] === 'izin' && $tagIzin > 0): ?>
                                <span class="sb-link__tag"><?= $tagIzin ?></span>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                <?php endforeach; ?>

                <?php foreach ($menu as $m): ?>
                    <?php if (in_array($m['kunci'], $tergambar, true)) { continue; } ?>
                    <a class="sb-link<?= $pageKey === $m['kunci'] ? ' is-active' : '' ?>"
                       href="<?= esc($m['href']) ?>"
                       <?= $pageKey === $m['kunci'] ? 'aria-current="page"' : '' ?>>
                        <i class="fa-solid <?= esc($m['icon']) ?>"></i>
                        <span><?= esc($m['label']) ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>

            <div class="sb-foot">
                <div class="facecard<?= $wajahTerdaftar ? ' is-ok' : '' ?>">
                    <p class="facecard__title">
                        <i class="fa-solid <?= $wajahTerdaftar ? 'fa-circle-check' : 'fa-face-smile' ?>"></i>
                        Face ID <?= $wajahTerdaftar ? te('siswa.face_active') : te('siswa.face_none') ?>
                    </p>
                    <p class="facecard__text">
                        <?php if ($wajahTerdaftar): ?>
                            <?= te('siswa.face_registered', ['tgl' => tgl_pendek($wajahSejak)]) ?>
                        <?php else: ?>
                            <?= te('siswa.face_register_hint') ?>
                        <?php endif; ?>
                    </p>
                </div>

                <a class="sb-link" href="/siswa/scan-absen.php">
                    <i class="fa-solid fa-camera"></i>
                    <span><?= te('siswa.open_camera') ?></span>
                </a>

                <div class="sb-account">
                    <span class="avatar avatar--plain"><?= esc($initials) ?></span>
                    <span class="sb-account__text">
                        <span class="sb-account__name"><?= esc($nama) ?></span>
                        <span class="sb-account__meta">
                            <?= esc($kelasNama !== '' ? $kelasNama : $labelIndentitas . ' ' . $nomorIndentitas) ?>
                        </span>
                    </span>
                    <form method="post" action="/auth/logout.php" class="sb-logout-form">
                        <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>">
                        <button type="submit" class="sb-iconbtn" title="<?= te('shell.logout') ?>" aria-label="<?= te('shell.logout') ?>">
                            <i class="fa-solid fa-arrow-right-from-bracket"></i>
                        </button>
                    </form>
                </div>
            </div>

        </div>
    </aside>

    <!-- ============ KOLOM UTAMA ============ -->
    <div class="main">

        <main class="content" id="utama">

            <!-- ============ HEADER GAYA DASHBOARD ============
                 Satu header untuk semua halaman siswa, termasuk beranda.
                 Sengaja tidak sticky: seperti di dashboard, header ikut
                 ter-scroll bersama konten. -->
            <header class="dash-topbar rise rise-1">
                <div class="dash-topbar__actions">
                    <?php if ($pageActionsHtml !== ''): ?>
                        <?= $pageActionsHtml ?>
                    <?php endif; ?>
                    <a class="icon-btn" href="<?= esc(lang_switch_url(lang_is('en') ? 'id' : 'en')) ?>"
                       aria-label="<?= te('shell.lang_label') ?>" title="<?= te('shell.lang_label') ?>">
                        <i class="fa-solid fa-language"></i>
                    </a>
                    <button type="button" class="icon-btn" id="btnNotif"
                            aria-label="<?= te('shell.notif') ?>" title="<?= te('shell.notif') ?>">
                        <i class="fa-regular fa-bell"></i>
                        <span class="badge-dot"></span>
                    </button>
                    <button type="button" class="icon-btn" id="btnAkun"
                            aria-haspopup="dialog" aria-controls="sheetAkun"
                            aria-label="<?= te('shell.open_account') ?>" title="<?= te('shell.account') ?>">
                        <i class="fa-regular fa-user"></i>
                    </button>
                </div>
                <div class="dash-topbar__text">
                    <h1 class="dash-topbar__title"><?= esc($pageTitle) ?></h1>
                    <?php if ($pageSub !== ''): ?>
                        <p class="dash-topbar__sub"><?= esc($pageSub) ?></p>
                    <?php endif; ?>
                </div>
            </header>

            <?php if ($flash !== null): ?>
                <div class="note note--<?= esc($flash['tipe'] ?? 'info') ?>" role="status" style="margin-bottom:14px">
                    <i class="fa-solid <?= esc($flash['ikon'] ?? 'fa-circle-info') ?> note__ic"></i>
                    <div><?= esc($flash['pesan'] ?? '') ?></div>
                </div>
            <?php endif; ?>
