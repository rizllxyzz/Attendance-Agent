<?php
/**
 * templates/siswa-foot.php
 * ---------------------------------------------------------------------------
 * Penutup halaman siswa: tab bar (mobile), bottom sheet akun, toast, dan
 * skrip yang dipakai bersama.
 *
 * Pasangan dari templates/siswa-head.php. Halaman pemanggil menutup
 * <main class="content"> sendiri sebelum include berkas ini.
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);

if (!defined('APP_NAME')) {
    http_response_code(500);
    exit('Halaman tidak lengkap dimuat.');
}

/* Tab bar hanya memuat item berlabel mobile. Scanabsen diletakkan sebagai
 * tombol tengah yang menonjol, seperti pada aplikasi peta/dompet.
 *
 * Susunan bar bawah (lihat templates/siswa-foot.php):
 *   kiri  = tombol bulat (.side-fab) yang membuka panel Riwayat + Statistik
 *   tengah= tab Beranda | Izin dengan indikator geser
 *   kanan = pil lingkar kamera
 *
 * Profil tidak ada di sini: sudah ada di tombol akun header atas dan di
 * bottom sheet, jadi memaksanya ke bar bawah membuat tiga grup terasa
 * sempit. */
$tabMobile = array_values(array_filter($menu, static function ($m) {
    return !empty($m['mobile']);
}));

/* Pencarian berdasar kunci, bukan indeks, supaya urutan di $menu boleh
 * diubah tanpa membuat tombol navigate ke halaman yang salah. */
$menuCari = [];
foreach ($menu as $m) {
    $menuCari[(string) $m['kunci']] = $m;
}

/* Dua tombol tengah: Beranda dan Izin. */
$tabTengah = [];
foreach (['beranda', 'izin'] as $kunci) {
    if (isset($menuCari[$kunci])) {
        $tabTengah[] = $menuCari[$kunci];
    }
}

/* Isi panel gantung: Riwayat, Statistik, dan Lapor Aman.
 *
 * Lapor Aman sengaja ditaruh di sini, bukan di pil tengah: pil bawah
 * sudah penuh oleh dua tujuan harian, sedangkan kanal laporan harus
 * tetap bisa dijangkau dari HP tanpa memakan tempat. */
$tabSamping = [];
foreach (['riwayat', 'statistik', 'lapor'] as $kunci) {
    if (isset($menuCari[$kunci])) {
        $tabSamping[] = $menuCari[$kunci];
    }
}

/* Apakah halaman yang sedang dibuka milik kelompok panel ini.
 *
 * Dulu tiap tombol di pil split menandai dirinya sendiri lewat is-active.
 * Sekarang tombolnya hanya satu, jadi tidak bisa tahu halaman mana yang
 * aktif dari tombol itu; harus dicek dari $pageKey. Tanpa ini, membuka
 * Statistik dari panel tidak akan terlihat "sedang berada di sini". */
$sampingAktif = false;
foreach ($tabSamping as $m) {
    if ((string) $pageKey === (string) $m['kunci']) {
        $sampingAktif = true;
        break;
    }
}

$svgPaket = [
    'beranda'  => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"><path d="M3 10.5L12 3l9 7.5V20a1.5 1.5 0 0 1-1.5 1.5H4.5A1.5 1.5 0 0 1 3 20v-9.5z"/><path d="M9 21v-6a1.5 1.5 0 0 1 1.5-1.5h3A1.5 1.5 0 0 1 15 15v6"/></svg>',
    'riwayat'  => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><polyline points="12 7 12 12 15.5 14"/></svg>',
    'izin'     => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>',
    'statistik' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>',
];
$svgDefault = '<svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="12" r="6"/></svg>';

/* Ikon untuk pil lingkar kiri.
 *
 * Dua halaman di panel ini tidak punya satu ikon bersama, jadi yang
 * dipakai ikon menu (dua garis bertumpuk) yang tidak menyiratkan salah
 * satu halaman tertentu. */
$svgMenu = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><line x1="4" y1="7" x2="20" y2="7"/><line x1="4" y1="12" x2="20" y2="12"/><line x1="4" y1="17" x2="14" y2="17"/></svg>';

/* Label pendek khusus untuk pil tengah. Label menu aslinya sengaja lengkap
 * ("Izin/Sakit") karena dipakai di sidebar dan bottom sheet yang ruangnya
 * longgar; di pil bawah space-nya sedikit dan teks panjang bikin dua tombol
 * tidak seimbang. Label penuh tetap dipakai untuk aria-label. */
$labelPendek = [
    'izin' => t('status.izin'),
];

/* Keterangan singkat di panel gantung. Tujuannya jelas dan sedikit,
 * jadi kalimatnya pendek: supaya pengguna tahu harus memilih apa tanpa
 * perlu kembali ke halaman ini. */
$ketSamping = [
    'riwayat'   => t('siswa.side_riwayat'),
    'statistik' => t('siswa.side_statistik'),
    'lapor'     => t('siswa.side_lapor'),
];
?>
        </main>
    </div><!-- /.main -->
</div><!-- /.app -->

<!-- ============ BOTTOM NAV: pil lingkar (kiri) + pil tengah + pil lingkar kamera (kanan) ============ -->
<div class="botbar" id="botbar" aria-label="<?= te('shell.quick_nav') ?>">

  <!-- Kiri: satu tombol lingkaran, sama seperti kamera di kanan. Satu
       ketukan membuka panel berisi Riwayat dan Statistik, jadi dua
       halaman laporan yang jarang dipakai tidak memakan lebar bar tengah. -->
  <?php if ($tabSamping): ?>
  <button type="button"
          class="side-fab<?= $sampingAktif ? ' is-active' : '' ?>"
          id="sideFab"
          aria-expanded="false"
          aria-controls="flySamping"
          aria-label="<?= te('shell.side_panel') ?>">
    <?= $svgMenu ?>
  </button>
  <?php endif; ?>

  <!-- Tengah: pil Beranda | Izin dengan indikator putih yang geser -->
  <div class="pill-nav" id="pillNav">
    <span class="pill-nav__indicator" id="pillIndicator"></span>

    <?php foreach ($tabTengah as $m): ?>
    <a class="pill-tab<?= $pageKey === $m['kunci'] ? ' is-active' : '' ?>"
       href="<?= esc($m['href']) ?>"
       data-key="<?= esc($m['kunci']) ?>"
       <?= $pageKey === $m['kunci'] ? 'aria-current="page"' : '' ?>
       aria-label="<?= esc($m['label']) ?>">
      <?= $svgPaket[$m['kunci']] ?? $svgDefault ?>
      <span><?= esc($labelPendek[$m['kunci']] ?? $m['label']) ?></span>
      <?php if ($m['kunci'] === 'izin' && $tagIzin > 0): ?>
        <span class="pill-tab__dot" aria-hidden="true"></span>
      <?php endif; ?>
    </a>
    <?php endforeach; ?>
  </div>

  <!-- Panel ggantung isi pil split. Hidden dipakai, bukan display:none
       di CSS, supaya transisi buka-tutup tetap bisa diputar. -->
  <div class="pill-flyout" id="flySamping" role="group" aria-label="<?= te('shell.side_panel') ?>" hidden>
    <span class="pill-flyout__grip" aria-hidden="true"></span>
    <?php foreach ($tabSamping as $m): ?>
      <a class="pill-flyout__item<?= $pageKey === $m['kunci'] ? ' is-active' : '' ?>"
         href="<?= esc($m['href']) ?>"
         data-fly-item="<?= esc($m['kunci']) ?>"
         <?= $pageKey === $m['kunci'] ? 'aria-current="page"' : '' ?>>
        <span class="pill-flyout__ic"><i class="fa-solid <?= esc($m['icon']) ?>"></i></span>
        <span class="pill-flyout__teks">
          <strong><?= esc($m['label']) ?></strong>
          <small><?= esc($ketSamping[$m['kunci']] ?? '') ?></small>
        </span>
      </a>
    <?php endforeach; ?>
  </div>

  <!-- Kanan: pil lingkar kamera -->
  <a class="cam-fab" href="/siswa/scan-absen.php" aria-label="<?= te('shell.scan') ?>">
    <span class="cam-fab__ring"></span>
    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor"
         stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
      <path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8
               a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/>
      <circle cx="12" cy="13" r="4"/>
    </svg>
  </a>

</div>

<!-- ============ BOTTOM SHEET AKUN (mobile) ============ -->
<div class="sheet" id="sheetAkun" role="dialog" aria-modal="true" aria-label="<?= te('shell.account_menu') ?>" hidden>
    <div class="sheet__scrim" data-tutup></div>
    <div class="sheet__panel">
        <div class="sheet__grip"></div>
        <div class="sheet__user">
            <span class="avatar avatar--lg"><?= esc($initials) ?></span>
            <div>
                <p class="sheet__name"><?= esc($nama) ?></p>
                <p class="sheet__meta">
                    <?= esc($kelasNama !== '' ? $kelasNama : $labelIndentitas . ' ' . $nomorIndentitas) ?>
                    <?= $kelasNama !== '' ? ' &middot; ' . esc($labelIndentitas . ' ' . $nomorIndentitas) : '' ?>
                </p>
            </div>
        </div>

        <div class="sheet__list">
            <?php foreach ($menu as $m): ?>
                <a class="sheet__item<?= $pageKey === $m['kunci'] ? ' is-active' : '' ?>"
                   href="<?= esc($m['href']) ?>">
                    <i class="fa-solid <?= esc($m['icon']) ?>"></i>
                    <?= esc($m['label']) ?>
                    <?php if ($m['kunci'] === 'izin' && $tagIzin > 0): ?>
                        <span><?= te('siswa.sheet_waiting', ['n' => $tagIzin]) ?></span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>

            <a class="sheet__item" href="/siswa/scan-absen.php">
                <i class="fa-solid fa-camera"></i>
                <?= te('nav.kamera') ?>
            </a>
            <form method="post" action="/auth/logout.php" style="display:contents">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>">
                <button type="submit" class="sheet__item sheet__item--danger sheet__item--btn">
                    <i class="fa-solid fa-arrow-right-from-bracket"></i>
                    <?= te('shell.logout') ?>
                </button>
            </form>
        </div>
    </div>
</div>

<!-- Toast dipakai halaman yang punya aksi di sisi klien. -->
<div class="toast" id="toast" role="status" aria-live="polite">
    <i class="fa-solid fa-circle-check"></i>
    <span id="toastPesan"></span>
</div>

<!-- ============ PETUNJUK PERTAMA KALI ============
     Muncul di HP sekali saja (lihat skrip di bawah). Lubang bening
     (.tour__hole) dipindah mengikuti elemen yang sedang dijelaskan. -->
<div class="tour" id="tour" role="dialog" aria-modal="true" aria-label="<?= te('shell.tour') ?>" hidden>
    <div class="tour__hole" id="tourHole" aria-hidden="true"></div>
    <div class="tour__tip" id="tourTip" role="document">
        <p class="tour__step" id="tourStep"></p>
        <h3 class="tour__title" id="tourTitle"></h3>
        <p class="tour__text" id="tourText"></p>
        <div class="tour__actions">
            <button type="button" class="tour__skip" id="tourSkip"><?= te('shell.skip_tour') ?></button>
            <button type="button" class="tour__next" id="tourNext"><?= te('shell.next') ?></button>
        </div>
    </div>
</div>

<script>
/* ------------------------------------------------------------------
 *  Sliding pill nav
 *  ------------------------------------------------------------------ */
(function () {
    var nav  = document.getElementById('pillNav');
    var ind  = document.getElementById('pillIndicator');
    if (!nav || !ind) { return; }

    function movePill(el) {
        if (!el) { return; }
        var navR = nav.getBoundingClientRect();
        var r    = el.getBoundingClientRect();
        ind.style.left  = (r.left - navR.left) + 'px';
        ind.style.width = r.width + 'px';
    }

    /* Init: snap without animation */
    var active = nav.querySelector('.pill-tab.is-active');
    if (active) {
        ind.style.transition = 'none';
        movePill(active);
        requestAnimationFrame(function () { ind.style.transition = ''; });
    }

    nav.querySelectorAll('.pill-tab').forEach(function (tab) {
        tab.addEventListener('click', function () {
            nav.querySelectorAll('.pill-tab').forEach(function (t) { t.classList.remove('is-active'); });
            tab.classList.add('is-active');
            movePill(tab);
        });
    });

    window.addEventListener('resize', function () {
        var a = nav.querySelector('.pill-tab.is-active');
        if (a) {
            ind.style.transition = 'none';
            movePill(a);
            requestAnimationFrame(function () { ind.style.transition = ''; });
        }
    });
})();

/* ------------------------------------------------------------------
 *  Pil lingkar kiri + panel gantung
 *  ------------------------------------------------------------------
 *  Menekan pil lingkar tidak memindah halaman, melainkan membuka panel
 *  di atasnya. Alasannya: halaman yang dibuka dari panel adalah halaman
 *  laporan yang jarang dipakai, sedangkan Beranda dan Izin yang harus
 *  selalu satu ketukan.
 *
 *  Tiga aturan yang dijaga supaya panel tidak menggantung sendirian:
 *    1. Membuka lewat keyboard harus punya jalan keluar, jadi Esc dan
 *       Enter pada tombol yang sama harus menutup/membuka.
 *    2. Klik di luar menutup, tapi klik di dalam pil tidak, supaya
 *       menekan tombol lain tidak membiarkan panel terbuka.
 *    3. hidden dilepas sebelum animasi, dipasang lagi setelah animasi
 *       menutup selesai. Kalau hidden dipasang duluan, animasi keluar
 *       tidak terlihat sama sekali.
 */
(function () {
    var fab    = document.getElementById('sideFab');
    var flyout = document.getElementById('flySamping');
    if (!fab || !flyout) { return; }

    /* Pil tengah ikut menyingkir selama panel terbuka, supaya tidak ada
       dua navigasi yang bersaing di bar yang sama. */
    var nav = document.getElementById('pillNav');

    var timerTutup = null;

    function buka() {
        if (timerTutup) {
            clearTimeout(timerTutup);
            timerTutup = null;
        }
        flyout.classList.remove('is-tutup');
        flyout.hidden = false;
        fab.setAttribute('aria-expanded', 'true');
        if (nav) { nav.classList.add('is-hidden'); }
    }

    function tutup() {
        /* Ditunda sedikit supaya klik yang sama tidak memicu buka lagi
           setelah panel selesai menutup. */
        timerTutup = setTimeout(function () {
            flyout.classList.add('is-tutup');
            fab.setAttribute('aria-expanded', 'false');
            if (nav) { nav.classList.remove('is-hidden'); }
            setTimeout(function () {
                if (flyout.classList.contains('is-tutup')) {
                    flyout.hidden = true;
                }
            }, 170);
        }, 90);
    }

    var aberto = false;

    fab.addEventListener('click', function (e) {
        e.stopPropagation();
        if (aberto) {
            tutup();
            aberto = false;
        } else {
            buka();
            aberto = true;
        }
    });

    /* Klik di dalam panel tidak boleh menutup: isinya adalah link, dan
       menekan link harus langsung pindah halaman. */
    flyout.addEventListener('click', function (e) { e.stopPropagation(); });

    document.addEventListener('click', function () {
        if (aberto) {
            tutup();
            aberto = false;
        }
    });

    document.addEventListener('keydown', function (e) {
        if ((e.key === 'Escape' || e.key === 'Esc') && aberto) {
            tutup();
            aberto = false;
            fab.focus();
        }
    });
})();

/* ------------------------------------------------------------------
 *  Bottom sheet akun
 *  ------------------------------------------------------------------ */
(function () {
    var sheet = document.getElementById('sheetAkun');
    var btns = [document.getElementById('btnAkun')].filter(Boolean);
    if (!sheet) { return; }

    var fokusSebelum = null;

    function buka() {
        fokusSebelum = document.activeElement;
        sheet.hidden = false;
        document.body.classList.add('is-locked');
        var pertama = sheet.querySelector('a, button');
        if (pertama) { pertama.focus(); }
    }

    function tutup() {
        sheet.hidden = true;
        document.body.classList.remove('is-locked');
        if (fokusSebelum && typeof fokusSebelum.focus === 'function') {
            fokusSebelum.focus();
        }
    }

    btns.forEach(function (b) { b.addEventListener('click', buka); });
    var btnNotif = document.getElementById('btnNotif');
    if (btnNotif) {
        btnNotif.addEventListener('click', function () {
            if (typeof beriToast === 'function') {
                beriToast(<?= json_encode(t('shell.no_notif')) ?>, 'info');
            }
        });
    }
    sheet.addEventListener('click', function (e) {
        if (e.target.hasAttribute('data-tutup')) { tutup(); }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !sheet.hidden) { tutup(); }
    });
    /* Memakai elemen <dialog> tidak dipakai supaya gaya tetap sama di
       semua peramban; focus trap sederhana di bawah ini cukup untuk
       sheet yang isinya cuma beberapa tautan. */
    sheet.addEventListener('keydown', function (e) {
        if (e.key !== 'Tab' || sheet.hidden) { return; }
        var item = Array.prototype.slice.call(
            sheet.querySelectorAll('a[href], button:not([disabled])')
        );
        if (!item.length) { return; }
        var pertama = item[0];
        var terakhir = item[item.length - 1];
        if (e.shiftKey && document.activeElement === pertama) {
            e.preventDefault(); terakhir.focus();
        } else if (!e.shiftKey && document.activeElement === terakhir) {
            e.preventDefault(); pertama.focus();
        }
    });
})();

/* ------------------------------------------------------------------
 *  Toast
 *  ------------------------------------------------------------------ */
var toastTimer = null;
function beriToast(pesan, jenis) {
    var t = document.getElementById('toast');
    if (!t) { return; }
    var p = document.getElementById('toastPesan');
    var i = t.querySelector('i');
    var map = {
        ok:   ['fa-circle-check', 'is-ok'],
        warn: ['fa-triangle-exclamation', 'is-warn'],
        info: ['fa-circle-info', 'is-info'],
        err:  ['fa-circle-xmark', 'is-warn']
    };
    var cfg = map[jenis] || map.info;
    if (p) { p.textContent = pesan; }
    if (i) { i.className = 'fa-solid ' + cfg[0]; }
    t.className = 'toast ' + cfg[1];
    /* Reflow memaksa ulang transisi supaya toast kedua tetap terlihat
       walau toast pertama baru saja hilang. */
    void t.offsetWidth;
    t.classList.add('is-show');
    if (toastTimer) { clearTimeout(toastTimer); }
    toastTimer = setTimeout(function () { t.classList.remove('is-show'); }, 2800);
}

/* ------------------------------------------------------------------
 *  Petunjuk pertama kali (coachmark)
 *  ------------------------------------------------------------------
 *  Tampil sekali saja di HP lalu ditandai di localStorage. Kalau
 *  localStorage diblokir, petunjuk dilewati — lebih baik tidak pernah
 *  tampil daripada muncul di setiap halaman dan mengganggu.
 */
(function () {
    var el = document.getElementById('tour');
    if (!el) { return; }

    /* Hanya di HP: di desktop navigasinya sidebar, bukan bar bawah. */
    if (!window.matchMedia('(max-width: 1023.98px)').matches) { return; }

    var KEY = 'absensipro.tour.v1';
    var sudah = false;
    try { sudah = window.localStorage.getItem(KEY) === '1'; }
    catch (e) { sudah = true; }
    if (sudah) { return; }

    var langkah = [
        { sel: '.side-fab', judul: <?= json_encode(t('siswa.tour_1_title')) ?>,
          teks: <?= json_encode(t('siswa.tour_1_text')) ?> },
        { sel: '#pillNav',  judul: <?= json_encode(t('siswa.tour_2_title')) ?>,
          teks: <?= json_encode(t('siswa.tour_2_text')) ?> },
        { sel: '.cam-fab',  judul: <?= json_encode(t('siswa.tour_3_title')) ?>,
          teks: <?= json_encode(t('siswa.tour_3_text')) ?> }
    ];
    var LBL_STEP = <?= json_encode(t('shell.step', ['n' => '__N__', 'total' => '__T__'])) ?>;
    var LBL_GOT_IT = <?= json_encode(t('shell.got_it')) ?>;
    var LBL_NEXT = <?= json_encode(t('shell.next')) ?>;

    var hole    = document.getElementById('tourHole');
    var tip     = document.getElementById('tourTip');
    var elStep  = document.getElementById('tourStep');
    var elJudul = document.getElementById('tourTitle');
    var elTeks  = document.getElementById('tourText');
    var btnNext = document.getElementById('tourNext');
    var btnSkip = document.getElementById('tourSkip');

    var i = 0;

    function tempat() {
        var s = langkah[i];
        var t = document.querySelector(s.sel);
        if (!t) { return; }

        var r   = t.getBoundingClientRect();
        var pad = 8;
        hole.style.left   = (r.left - pad) + 'px';
        hole.style.top    = (r.top  - pad) + 'px';
        hole.style.width  = (r.width  + pad * 2) + 'px';
        hole.style.height = (r.height + pad * 2) + 'px';

        elStep.textContent  = LBL_STEP.replace('__N__', i + 1).replace('__T__', langkah.length);
        elJudul.textContent = s.judul;
        elTeks.textContent  = s.teks;
        btnNext.textContent = (i === langkah.length - 1) ? LBL_GOT_IT : LBL_NEXT;

        /* Balon ditaruh di sisi berlawanan dengan target: kalau target di
           paruh bawah layar, balon naik ke atasnya. Ukur dulu setelah
           lebarnya dipatok, baru tentukan posisi. */
        var vw = window.innerWidth, vh = window.innerHeight;
        tip.style.width = Math.min(320, vw - 32) + 'px';
        tip.style.left  = '0px';
        tip.style.top   = '0px';
        var tr   = tip.getBoundingClientRect();
        var left = Math.max(16, Math.min(r.left + r.width / 2 - tr.width / 2, vw - tr.width - 16));
        tip.style.left = left + 'px';
        var top = (r.top > vh / 2) ? (r.top - tr.height - 14) : (r.bottom + 14);
        top = Math.max(16, Math.min(top, vh - tr.height - 16));
        tip.style.top = top + 'px';
    }

    function beres() {
        if (el.hidden) { return; }
        try { window.localStorage.setItem(KEY, '1'); } catch (e) {}
        el.hidden = true;
        window.removeEventListener('resize', tempat);
        window.removeEventListener('scroll', tempat, true);
    }

    function lanjut() {
        i++;
        if (i >= langkah.length) { beres(); return; }
        tempat();
    }

    btnNext.addEventListener('click', lanjut);
    btnSkip.addEventListener('click', beres);
    el.addEventListener('click', function (e) {
        /* Ketukan di area gelap (bukan balon) juga menutup, supaya tidak
           terasa menjebak. */
        if (e.target === el || e.target === hole) { beres(); }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { beres(); }
    });
    window.addEventListener('resize', tempat);
    window.addEventListener('scroll', tempat, true);

    /* Tampilkan setelah tata letak siap, supaya ukuran target sudah final
       saat lubang digambar. */
    function mulai() { el.hidden = false; tempat(); btnNext.focus(); }
    if (document.readyState === 'complete') {
        setTimeout(mulai, 350);
    } else {
        window.addEventListener('load', function () { setTimeout(mulai, 350); });
    }
})();
</script>
</body>
</html>
