<?php
/**
 * templates/admin-foot.php
 * ---------------------------------------------------------------------------
 * Penutup halaman panel admin: tab bar (mobile), bottom sheet akun, toast,
 * dan skrip yang dipakai bersama.
 *
 * Pasangan dari templates/admin-head.php. Halaman pemanggil menutup
 * <main class="content"> sendiri sebelum include berkas ini.
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);

if (!defined('APP_NAME') || !function_exists('admin_flash')) {
    http_response_code(500);
    exit('Halaman tidak lengkap dimuat.');
}

$fabHref  = $fabHref ?? '';
$fabLabel = $fabLabel ?? t('shell.add');
$fabIcon  = $fabIcon  ?? 'fa-plus';

$menuIzin    = $tagIzin;
$menuWajah   = $tagWajah;
$menuLaporan = $tagLaporan ?? 0;

/* Ikon SVG untuk tab bar HP. Key sama dengan $pageKey. Sengaja inline
 * (bukan Font Awesome) supaya bentuknya sama persis dengan portal siswa
 * dan tidak bergantung pada glyp Font Awesome yang bisa berubah. */
$svgIcon = static function (string $kunci): string {
    $buka  = 'width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
        . ' stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"';
    $peta = [
        'beranda' => '<path d="M3 10.5L12 3l9 7.5V20a1.5 1.5 0 0 1-1.5 1.5H4.5A1.5 1.5 0 0 1 3 20v-9.5z"/>'
            . '<path d="M9 21v-6a1.5 1.5 0 0 1 1.5-1.5h3A1.5 1.5 0 0 1 15 15v6"/>',
        'siswa'   => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/>'
            . '<path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'kelas'   => '<path d="M3 5.5h18"/><path d="M5 5.5V20"/><path d="M5 11h6v9"/>'
            . '<path d="M11 16h6"/><path d="M11 20h6"/><path d="M19 5.5V20"/>',
        'izin'    => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>'
            . '<polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/>'
            . '<line x1="16" y1="17" x2="8" y2="17"/>',
        'absensi' => '<circle cx="12" cy="12" r="9"/><polyline points="12 7 12 12 15.5 14"/>',
        'wajah'   => '<path d="M8 3H6a2 2 0 0 0-2 2v3"/><path d="M16 3h2a2 2 0 0 1 2 2v3"/>'
            . '<path d="M12 8a4 4 0 0 1 4 4v1"/>'
            . '<circle cx="12" cy="13" r="3"/><path d="M5 21v-1a7 7 0 0 1 14 0v1"/>',
        'laporan' => '<path d="M12 3l7 3v5.5c0 4-2.8 6.7-7 8.5-4.2-1.8-7-4.5-7-8.5V6z"/>'
            . '<path d="M12 11c.9-1.1 2.6-.5 2.6.8 0 1-1.4 2-2.6 2.7-1.2-.7-2.6-1.7-2.6-2.7 0-1.3 1.7-1.9 2.6-.8z"/>',
        'alat'    => '<path d="M12 14a2 2 0 1 0 0-4 2 2 0 0 0 0 4z"/><path d="M13.4 10.6L19 5"/>'
            . '<path d="M20.5 3.5l1 1-2.4 2.4-1-1z"/><path d="M10.6 13.4L5 19"/>'
            . '<path d="M3.5 20.5l1-1 2.4 2.4-1 1z"/>',
        'pengaturan' => '<circle cx="12" cy="12" r="3"/>'
            . '<path d="M19.4 15a1.6 1.6 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.6 1.6 0 0 0-1.8-.3 1.6 1.6 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1A1.6 1.6 0 0 0 9 19.4a1.6 1.6 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.6 1.6 0 0 0 .3-1.8 1.6 1.6 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1A1.6 1.6 0 0 0 4.6 9a1.6 1.6 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.6 1.6 0 0 0 1.8.3H9a1.6 1.6 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.6 1.6 0 0 0 1 1.5 1.6 1.6 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.6 1.6 0 0 0-.3 1.8V9a1.6 1.6 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.6 1.6 0 0 0-1.5 1z"/>',
    ];
    if (!isset($peta[$kunci])) {
        return '<circle cx="12" cy="12" r="6" fill="currentColor"/>';
    }
    return '<svg ' . $buka . '>' . $peta[$kunci] . '</svg>';
};
?>
        </main>
    </div><!-- /.main -->
</div><!-- /.app -->

<!-- ============ BOTTOM NAV: pill bar full lebar (mobile) ============ -->
<div class="botbar" aria-label="Navigasi cepat">
    <nav class="pill-nav" id="pillNav" aria-label="Navigasi utama">
        <span class="pill-nav__indicator" id="pillIndicator"></span>

        <?php foreach ($tabMobile as $m): ?>
            <a class="pill-tab<?= $pageKey === $m['kunci'] ? ' is-active' : '' ?>"
               href="<?= esc($m['href']) ?>"
               data-key="<?= esc($m['kunci']) ?>"
               <?= $pageKey === $m['kunci'] ? 'aria-current="page"' : '' ?>
               aria-label="<?= esc($m['label']) ?>">
                <?= $svgIcon((string) $m['kunci']) ?>
                <span><?= esc($m['label']) ?></span>
            </a>
        <?php endforeach; ?>
    </nav>
</div>

<?php if ($fabHref !== ''): ?>
<!-- ============ FAB: melayang di atas pill bar, pojok kanan (mobile) ============ -->
<a class="fab" href="<?= esc($fabHref) ?>" aria-label="<?= esc($fabLabel) ?>" title="<?= esc($fabLabel) ?>">
    <i class="fa-solid <?= esc($fabIcon) ?>"></i>
</a>
<?php endif; ?>

<!-- ============ BOTTOM SHEET AKUN (mobile) ============ -->
<div class="sheet" id="sheetAkun" role="dialog" aria-modal="true" aria-label="<?= te('shell.account_menu') ?>" hidden>
    <div class="sheet__scrim" data-tutup></div>
    <div class="sheet__panel">
        <div class="sheet__grip"></div>
        <div class="sheet__user">
            <span class="avatar avatar--lg"><?= esc($initials) ?></span>
            <div>
                <p class="sheet__name"><?= esc($adminNama) ?></p>
                <p class="sheet__meta"><?= esc($peranLabel) ?> &middot; <?= esc($adminUser) ?></p>
            </div>
        </div>

        <div class="sheet__list">
            <?php foreach ($menu as $m): ?>
                <a class="sheet__item<?= $pageKey === $m['kunci'] ? ' is-active' : '' ?>"
                   href="<?= esc($m['href']) ?>">
                    <i class="fa-solid <?= esc($m['icon']) ?>"></i>
                    <?= esc($m['label']) ?>
                    <?php if ($m['kunci'] === 'izin' && $menuIzin > 0): ?>
                        <span><?= $menuIzin ?> <?= te('tag.menunggu') ?></span>
                    <?php elseif ($m['kunci'] === 'wajah' && $menuWajah > 0): ?>
                        <span><?= $menuWajah ?> <?= te('tag.permintaan') ?></span>
                    <?php elseif ($m['kunci'] === 'laporan' && $menuLaporan > 0): ?>
                        <span><?= $menuLaporan ?> <?= te('tag.baru') ?></span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>

            <a class="sheet__item" href="<?= esc(lang_switch_url(lang_is('id') ? 'en' : 'id')) ?>">
                <i class="fa-solid fa-language"></i>
                <?= lang_is('id') ? 'English' : 'Bahasa Indonesia' ?>
            </a>

            <form method="post" action="/auth/logout.php" class="sheet-logout-form">
                <input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">
                <button type="submit" class="sheet__item sheet__item--btn">
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

    /* Init: snap tanpa animasi supaya tidak meluncur dari kiri. */
    var active = nav.querySelector('.pill-tab.is-active');
    if (active) {
        ind.style.transition = 'none';
        movePill(active);
        requestAnimationFrame(function () { ind.style.transition = ''; });
    }

    nav.addEventListener('click', function (e) {
        var tab = e.target.closest ? e.target.closest('.pill-tab') : null;
        if (!tab) { return; }
        nav.querySelectorAll('.pill-tab').forEach(function (t) { t.classList.remove('is-active'); });
        tab.classList.add('is-active');
        movePill(tab);
    });

    window.addEventListener('resize', function () {
        var a = nav.querySelector('.pill-tab.is-active');
        if (a) {
            ind.style.transition = 'none';
            movePill(a);
            requestAnimationFrame(function () { ind.style.transition = ''; });
        }
    });

    /* Halaman bisa memindahkan pill lewat window.adminPillLoncat('kunci')
       setelah memuat daftar menu lewat AJAX. */
    window.adminPillLoncat = function (kunci) {
        var tab = nav.querySelector('.pill-tab[data-key="' + kunci + '"]');
        if (tab) { movePill(tab); }
    };
})();

/* ------------------------------------------------------------------
 *  Bottom sheet akun
 *  ------------------------------------------------------------------ */
(function () {
    var sheet = document.getElementById('sheetAkun');
    var btns  = [document.getElementById('btnAkun')].filter(Boolean);
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
    sheet.addEventListener('click', function (e) {
        if (e.target.hasAttribute && e.target.hasAttribute('data-tutup')) { tutup(); }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !sheet.hidden) { tutup(); }
    });
    /* Focus trap sederhana: sheet isinya cuma beberapa tautan, jadi tidak
     * perlu <dialog> yang tidak seragam antar peramban. */
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
        info: ['fa-circle-info', 'is-warn'],
        err:  ['fa-circle-xmark', 'is-warn']
    };
    var cfg = map[jenis] || map.info;
    if (p) { p.textContent = pesan; }
    if (i) { i.className = 'fa-solid ' + cfg[0]; }
    t.className = 'toast ' + cfg[1];
    /* Reflow memaksa ulang transisi supaya toast kedua tetap terlihat. */
    void t.offsetWidth;
    t.classList.add('is-show');
    if (toastTimer) { clearTimeout(toastTimer); }
    toastTimer = setTimeout(function () { t.classList.remove('is-show'); }, 2800);
}

/* ------------------------------------------------------------------
 *  Drawer & modal
 *  ------------------------------------------------------------------
 *  Formulir tambah/ubah livedrawer supaya daftar tetap terlihat di
 *  belakang dan pengguna tidak kehilangan konteks filter. Drawer
 *  dipanggil dari tautan biasa (?aksi=...) supaya tetap jalan tanpa JS.
 */
(function () {
    var fokusSebelum = null;

    function bukaDrawer(el) {
        fokusSebelum = document.activeElement;
        el.hidden = false;
        document.body.classList.add('is-locked');
        var pertama = el.querySelector('input:not([type=hidden]), select, textarea, button');
        if (pertama) { pertama.focus(); }
    }

    function tutupSemua() {
        var ada = false;
        /* Drawer dan modal sama-sama lapisan di atas halaman, jadi
         * keduanya ikut tertutup. Kalau hanya drawer yang ditutup, modal
         * akan menggantung di layar dan tidak bisa ditutup lagi. */
        document.querySelectorAll('.drawer:not([hidden]), .modal:not([hidden])').forEach(function (d) {
            d.hidden = true;
            ada = true;
        });
        if (ada) { document.body.classList.remove('is-locked'); }
        if (fokusSebelum && typeof fokusSebelum.focus === 'function') {
            fokusSebelum.focus();
        }
        fokusSebelum = null;
    }

    /* Buka drawer yang ditandai di URL, lalu bersihkan URL supaya
       tombol "back" tidak mengulang form yang sama. */
    document.querySelectorAll('.drawer[data-autoload]').forEach(function (el) {
        if (el.dataset.autoload === '1') { bukaDrawer(el); }
    });

    document.addEventListener('click', function (e) {
        var pemicu = e.target.closest ? e.target.closest('[data-buka]') : null;
        if (pemicu) {
            var tujuan = document.getElementById(pemicu.dataset.buka);
            if (tujuan) {
                e.preventDefault();
                bukaDrawer(tujuan);
            }
            return;
        }
        var penutup = e.target.closest ? e.target.closest('[data-tutup]') : null;
        if (penutup) {
            /* Jangan zarui tombol submit yang juga memakai data-tutup. */
            if (penutup.matches('button[type=submit]')) { return; }
            e.preventDefault();
            tutupSemua();
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') { return; }
        var terbuka = document.querySelector('.drawer:not([hidden]), .modal:not([hidden])');
        if (!terbuka) { return; }
        /* Modal lebih "dekat" dengan pengguna daripada drawer: kalau
         * keduanya kebetulan terbuka, yang lebih dulu ditutup modal. */
        if (terbuka.classList.contains('modal')) {
            terbuka.hidden = true;
            if (!document.querySelector('.drawer:not([hidden])')) {
                document.body.classList.remove('is-locked');
            }
            return;
        }
        tutupSemua();
    });

    /* Cegah form yang terisi dua kali saat koneksi lambat. */
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form.matches || !form.matches('form')) { return; }
        if (form.dataset.AllowSubmit === '1') { return; }
        var tombol = form.querySelector('button[type=submit]');
        if (!tombol) { return; }
        form.dataset.allowSubmit = '1';
        /* Tombol dinonaktifkan supaya klik kedua tidak mengirim ulang, tapi
           tetap dikembalikan supaya tidak terkunci kalau validasi gagal. */
        setTimeout(function () {
            tombol.disabled = true;
            tombol.setAttribute('aria-disabled', 'true');
        }, 0);
    });
})();
</script>
</body>
</html>
