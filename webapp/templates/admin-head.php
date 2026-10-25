<?php
/**
 * templates/admin-head.php
 * ---------------------------------------------------------------------------
 * Kerangka atas halaman panel admin: <head>, sidebar desktop, header gaya
 * Dashboard, dan pembuka konten.
 *
 * Halaman pemanggil harus:
 *   1. require_once __DIR__ . '/../admin/_bootstrap.php'
 *   2. set $pageKey   (kunci menu: beranda|siswa|kelas|izin|absensi|wajah|
 *                      alat|pengaturan)
 *   3. set $pageTitle, $pageSub
 *   4. set $pageActions (HTML tombol di kanan, opsional) atau $pageActionsHtml
 *   5. set $fabHref    (tombol aksi melayang di HP, opsional)
 *   6. include file ini
 *
 * Berkas ini hanya membuka tag; seluruh penutup ada di admin-foot.php.
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);

if (!defined('APP_NAME') || !function_exists('admin_flash')) {
    http_response_code(500);
    exit('Halaman tidak lengkap dimuat.');
}

$pageKey   = $pageKey   ?? 'beranda';
$pageTitle = $pageTitle ?? 'Beranda';
$pageSub   = $pageSub   ?? '';

/* Tombol aksi di kanan kepala halaman. Dipakai sebagai HTML mentah supaya
 * pemanggil bebas menaruh <a> maupun <form>. */
$pageActionsHtml = $pageActionsHtml ?? '';

/* Tombol aksi melayang di HP. Kosongkan $fabHref untuk menyembunyikannya. */
$fabHref    = $fabHref ?? '';
$fabLabel   = $fabLabel   ?? 'Tambah';
$fabIcon    = $fabIcon    ?? 'fa-plus';

/* Kartu ringkasan kecil di bawah judul, khusus dashboard. */
$headStats  = $headStats  ?? [];

$menuIzin    = $tagIzin;
$menuWajah   = $tagWajah;
$menuLaporan = $tagLaporan ?? 0;

/* Item tab bar HP: keys yang dipakai untuk ikon SVG di admin-foot.php. */
$tabMobile = array_values(array_filter($menu, static function ($m) {
    return !empty($m['mobile']);
}));
?>
<!DOCTYPE html>
<html lang="<?= esc(lang_active()) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#a3241a">
<meta name="color-scheme" content="light">
<title><?= esc($pageTitle) ?> &middot; <?= te('shell.panel') ?> <?= esc(APP_NAME) ?></title>
<meta name="description" content="<?= te('shell.panel') ?> <?= esc(APP_NAME) ?>">
<meta name="robots" content="noindex, nofollow">
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='8' fill='%23a3241a'/%3E%3Cpath d='M16 8l10 4.5-10 4.5-10-4.5L16 8z' fill='white'/%3E%3Cpath d='M10 15v4c0 1.7 2.7 3 6 3s6-1.3 6-3v-4' stroke='white' stroke-width='2' fill='none' stroke-linecap='round'/%3E%3C/svg%3E">
<!--
  Font Awesome dikunci versinya supaya tampilan tidak berubah diam-diam saat
  Font Awesome rilis versi baru. Tanpa atribut integrity, sama seperti
  halaman siswa: hash yang diketik tangan justru membuat stylesheet ditolak.
-->
<link rel="stylesheet" href="/assets/vendor/fontawesome/css/all.min.css">
<link rel="stylesheet" href="/assets/css/admin.css?v=7">
</head>
<body>
<a class="skip-link" href="#utama"><?= te('shell.skip') ?></a>

<div class="app">

    <!-- ============ SIDEBAR (desktop >= 1024px) ============ -->
    <aside class="sidebar" aria-label="<?= te('shell.nav_main') ?>">
        <div class="sidebar__inner">

            <a class="sb-brand" href="/admin/index.php">
                <span class="logo"><i class="fa-solid fa-shield-halved"></i></span>
                <span class="sb-brand__text">
                    <span class="sb-brand__name"><?= esc(APP_NAME) ?></span>
                    <span class="sb-brand__sub"><?= te('shell.panel') ?></span>
                </span>
            </a>

            <nav class="sb-nav">
                <?php
                /* Menu dikelompokkan supaya mata cepat menemukan yang dicari.
                 * Item yang tidak ada / tidak berhak sudah difilter di
                 * _bootstrap.php, jadi grup yang kosong cukup dilewati.
                 * Sisa item yang belum terdaftar di grup tetap ditampilkan
                 * agar tidak ada menu yang hilang tanpa sengaja. */
                $kelompok = [
                    'group.utama'   => ['beranda', 'absensi', 'izin', 'laporan'],
                    'group.kelola'  => ['siswa', 'akun', 'kelas', 'impor', 'wajah'],
                    'group.lainnya' => ['agent', 'reports', 'ai-provider', 'whatsapp', 'pengaturan', 'backup'],
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
                    <p class="sb-nav__label"><?= te($judulGrup) ?></p>
                    <?php foreach ($anggota as $m): ?>
                        <a class="sb-link<?= $pageKey === $m['kunci'] ? ' is-active' : '' ?>"
                           href="<?= esc($m['href']) ?>"
                           <?= $pageKey === $m['kunci'] ? 'aria-current="page"' : '' ?>>
                            <i class="fa-solid <?= esc($m['icon']) ?>"></i>
                            <span><?= esc($m['label']) ?></span>
                            <?php if ($m['kunci'] === 'izin' && $menuIzin > 0): ?>
                                <span class="sb-link__tag"><?= $menuIzin ?></span>
                            <?php elseif ($m['kunci'] === 'wajah' && $menuWajah > 0): ?>
                                <span class="sb-link__tag sb-link__tag--soft"><?= $menuWajah ?></span>
                            <?php elseif ($m['kunci'] === 'laporan' && $menuLaporan > 0): ?>
                                <span class="sb-link__tag"><?= $menuLaporan ?></span>
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
                <div class="sb-account">
                    <span class="avatar avatar--plain"><?= esc($initials) ?></span>
                    <span class="sb-account__text">
                        <span class="sb-account__name"><?= esc($adminNama) ?></span>
                        <span class="sb-account__meta"><?= esc($peranLabel) ?></span>
                    </span>
                    <form method="post" action="/auth/logout.php" class="sb-logout-form">
                        <input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">
                        <button type="submit" class="sb-iconbtn" title="Keluar" aria-label="Keluar">
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
                 Satu-satunya kepala halaman panel. Sengaja tidak sticky:
                 seperti di dashboard, header ikut ter-scroll bersama
                 konten. -->
            <header class="dash-topbar">
                <div class="dash-topbar__actions">
                    <?php if ($pageActionsHtml !== ''): ?>
                        <?= $pageActionsHtml ?>
                    <?php endif; ?>
                    <a class="icon-btn lang-quick"
                       href="<?= esc(lang_switch_url(lang_is('id') ? 'en' : 'id')) ?>"
                       aria-label="<?= te('shell.lang_label') ?>"
                       title="<?= lang_is('id') ? 'English' : 'Bahasa Indonesia' ?>">
                        <i class="fa-solid fa-language"></i>
                    </a>
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

            <?php if ($headStats !== []): ?>
                <div class="grid grid--kpi mb-2">
                    <?php foreach ($headStats as $s): ?>
                        <div class="kpi kpi--slim kpi--<?= esc($s['tone'] ?? 'muted') ?>">
                            <p class="kpi__label" style="margin:0"><?= esc($s['label']) ?></p>
                            <p class="kpi__value"><?= esc($s['value']) ?></p>
                            <?php if (!empty($s['hint'])): ?>
                                <p class="kpi__hint"><?= esc($s['hint']) ?></p>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($flash !== null): ?>
                <div class="note note--<?= esc($flash['tipe'] ?? 'info') ?> mb-2" role="status">
                    <i class="fa-solid <?= esc($flash['ikon'] ?? 'fa-circle-info') ?> note__ic"></i>
                    <div class="note__body"><?= esc($flash['pesan'] ?? '') ?></div>
                </div>
            <?php endif; 