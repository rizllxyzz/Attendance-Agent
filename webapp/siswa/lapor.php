<?php
/**
 * siswa/lapor.php
 * ---------------------------------------------------------------------------
 * "Lapor Aman": kanal bagi siswa untuk melaporkan hal yang membuat mereka
 * tidak nyaman atau tidak aman.
 *
 * ATURAN YANG DIPEGANG HALAMAN INI
 * --------------------------------
 *  1. Laporan boleh dikirim TANPA nama. Kalau anonim, kolom user_id sengaja
 *     dibiarkan kosong dan laporan TIDAK ditulis ke jejak audit, supaya
 *     tidak ada jejak IP yang bisa membongkar siapa pelapornya.
 *  2. Yang anonim adalah pengirimnya, bukan isinya. Guru tetap bisa
 *     menindaklanjuti kejadian yang dilaporkan.
 *  3. Pelapor menerima "kode akses" (mis. AMAN-7A2F9C) untuk memantau
 *     status laporannya tanpa perlu membuka nama.
 *  4. Halaman ini BUKAN layanan darurat. Untuk keadaan yang mengancam nyawa
 *     segera, nomor bantuan ditampilkan, bukan formulir.
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../config/laporan.php';

$tabelSiap = laporan_pastikan_tabel();

$error    = [];
$old      = ['kategori' => 'perundungan', 'urgensi_pilih' => 'biasa', 'isi' => '', 'anonim' => 0];
$cekHasil = null;
$cekKode  = '';

/* ------------------------------------------------------------------
 *  PROSES FORMULIR
 * ------------------------------------------------------------------ */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {

    if (!csrf_check($_POST['csrf'] ?? null)) {
        $_SESSION['siswa_flash'] = [
            'tipe'  => 'danger',
            'ikon'  => 'fa-triangle-exclamation',
            'pesan' => 'Sesi formulir kedaluwarsa. Muat ulang halaman lalu coba lagi.',
        ];
        header('Location: /siswa/lapor.php');
        exit;
    }

    $aksi = (string) ($_POST['aksi'] ?? 'kirim');

    /* ---------- Kirim laporan baru --------------------------------- */
    if ($aksi === 'kirim') {
        $kategori = (string) ($_POST['kategori'] ?? 'lainnya');
        $urgensiPilih = (string) ($_POST['urgensi_pilih'] ?? 'biasa');
        $isi      = trim((string) ($_POST['isi'] ?? ''));
        $anonim   = isset($_POST['anonim']) ? 1 : 0;

        $old = ['kategori' => $kategori, 'urgensi_pilih' => $urgensiPilih, 'isi' => $isi, 'anonim' => $anonim];

        if (!array_key_exists($kategori, laporan_kategori())) {
            $error[] = 'Kategori tidak dikenal.';
        }
        if (!in_array($urgensiPilih, ['biasa', 'perhatian', 'darurat'], true)) {
            $error[] = 'Tingkat urgensi tidak dikenal.';
        }
        if (mb_strlen($isi) < 5) {
            $error[] = 'Ceritakan sedikit lebih lengkap minimal 5 karakter.';
        }
        if (mb_strlen($isi) > 2000) {
            $error[] = 'Laporan maksimal 2000 karakter.';
        }

        if (!$error && $tabelSiap) {
            $urgensi = laporan_urgensi(['urgensi_pilih' => $urgensiPilih, 'isi' => $isi]);
            $kode    = laporan_kode_baru();

            try {
                db_insert(
                    'INSERT INTO laporan_aman
                        (kode_akses, user_id, anonim, kategori, urgensi, isi, status)
                     VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [
                        $kode,
                        $anonim === 1 ? null : $userId,
                        $anonim,
                        $kategori,
                        $urgensi,
                        $isi,
                        'baru',
                    ]
                );

                /* Laporan anonim sengaja TIDAK ditulis ke audit_log. Tabel
                 * audit menyimpan alamat IP, dan satu baris saja sudah cukup
                 * untuk menautkan pelapor dengan laporannya. Isi laporan di
                 * tabel laporan_aman sudah menjadi catatan yang diperlukan. */
                if ($anonim === 0) {
                    audit_log($userId, 'laporan_dikirim', 'info',
                        'Laporan ' . $kategori . ' (urgensi ' . $urgensi . ').');
                }

                $_SESSION['laporan_sukses'] = ['kode' => $kode, 'anonim' => $anonim === 1];
                header('Location: /siswa/lapor.php');
                exit;
            } catch (Throwable $e) {
                error_log('[LAPORAN] Gagal menyimpan: ' . $e->getMessage());
                $error[] = 'Laporan gagal disimpan. Coba lagi sebentar lagi.';
            }
        } elseif (!$tabelSiap && !$error) {
            $error[] = 'Fitur ini belum tersedia di instalasi ini. Hubungi administrator sekolah.';
        }
    }

    /* ---------- Cek status lewat kode ------------------------------ */
    if ($aksi === 'cek') {
        $cekKode = strtoupper(trim((string) ($_POST['kode'] ?? '')));

        if (!preg_match('/^AMAN-[0-9A-F]{6}$/', $cekKode)) {
            $error[] = 'Format kode tidak sesuai. Contoh yang benar: AMAN-7A2F9C.';
        } elseif (!$tabelSiap) {
            $error[] = 'Fitur ini belum tersedia di instalasi ini.';
        } else {
            $gate = throttle('laporan_cek', 20, 60);
            if (!$gate['allowed']) {
                $error[] = 'Terlalu banyak percobaan. Tunggu ' . $gate['retryAfter'] . ' detik lagi.';
            } else {
                $cekHasil = db_one(
                    'SELECT kode_akses, kategori, urgensi, isi, status, catatan_bk, created_at
                       FROM laporan_aman WHERE kode_akses = ? LIMIT 1',
                    [$cekKode],
                    null
                );
                if (!$cekHasil) {
                    $error[] = 'Kode tidak ditemukan. Pastikan tidak ada salah ketik.';
                }
            }
        }
    }
}

/* ------------------------------------------------------------------
 *  PESAN SUKSES SETELAH KIRIM (PRG)
 * ------------------------------------------------------------------ */
$sukses = null;
if (!empty($_SESSION['laporan_sukses'])) {
    $sukses = $_SESSION['laporan_sukses'];
    unset($_SESSION['laporan_sukses']);
}

/* ------------------------------------------------------------------
 *  DAFTAR LAPORAN MILIK SENDIRI
 * ------------------------------------------------------------------
 * Hanya laporan yang TIDAK anonim tampil di sini. Laporan anonim tidak
 * punya user_id, jadi memang tidak bisa ditautkan kembali ke penghuni
 * akun ini; pemantauannya lewat kode akses.
 * ------------------------------------------------------------------ */
$daftarSaya = [];
if ($tabelSiap) {
    $daftarSaya = db_all(
        'SELECT kode_akses, kategori, urgensi, isi, status, catatan_bk, created_at
           FROM laporan_aman
          WHERE user_id = ? AND anonim = 0
       ORDER BY id DESC LIMIT 20',
        [$userId]
    );
}

$pageKey   = 'lapor';
$pageTitle = 'Lapor Aman';
$pageSub   = 'Ceritakan hal yang membuatmu tidak nyaman. Rahasiamu dijaga.';

require __DIR__ . '/../templates/siswa-head.php';
?>

<?php if (!$tabelSiap): ?>
    <div class="note note--danger rise rise-1">
        <i class="fa-solid fa-triangle-exclamation note__ic"></i>
        <div>
            <p class="note__title">Fitur belum siap</p>
            <p style="margin-top:2px">
                Tabel laporan tidak dapat dibuat otomatis pada instalasi ini.
                Hubungi administrator sekolah.
            </p>
        </div>
    </div>
<?php else: ?>

<!-- ============ KODE AKSES SETELAH KIRIM ============ -->
<?php if ($sukses !== null): ?>
    <div class="card card--pad rise rise-1" style="border-left:4px solid var(--ok,#15803d)">
        <h2 class="card__title" style="margin-bottom:4px">
            <i class="fa-solid fa-circle-check" style="color:#15803d"></i>
            Laporan terkirim
        </h2>
        <p class="card__sub" style="margin-bottom:12px">
            <?= !empty($sukses['anonim'])
                ? 'Laporanmu dikirim tanpa nama. Simpan kode di bawah ini untuk memantau statusnya.'
                : 'Laporanmu sudah masuk ke guru BK. Pantau perkembangannya di daftar bawah.' ?>
        </p>
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
            <code style="font-size:20px;font-weight:700;letter-spacing:1px;background:#f1f5f9;padding:8px 14px;border-radius:8px">
                <?= esc((string) $sukses['kode']) ?>
            </code>
            <button type="button" class="btn btn--sm btn--soft" id="salinKode"
                    data-kode="<?= esc((string) $sukses['kode']) ?>">
                <i class="fa-regular fa-copy"></i> Salin kode
            </button>
        </div>
        <p class="field__hint" style="margin-top:10px">
            Kode ini satu-satunya cara membuka laporan tanpa nama. Kalau hilang,
            guru tetap bisa menindaklanjuti isinya, tapi kamu tidak bisa melihat
            perkembangannya dari sini.
        </p>
    </div>
<?php endif; ?>

<!-- ============ NOMOR BANTUAN (KESELAMATAN) ============ -->
<div class="note note--danger rise rise-1" style="margin-bottom:16px">
    <i class="fa-solid fa-phone-volume note__ic"></i>
    <div>
        <p class="note__title">Kalau kamu dalam bahaya sekarang</p>
        <p style="margin-top:2px">
            Halaman ini bukan layanan darurat. Untuk keadaan yang mengancam
            keselamatan segera, hubungi <strong>119</strong> (ekstensi 8) atau
            <strong>KPAI 021-31901556</strong>. Kalau bisa, temui guru BK atau
            orang dewasa yang kamu percaya hari ini.
        </p>
    </div>
</div>

<section class="grid" style="margin-top:16px">

    <!-- ============ FORMULIR ============ -->
    <div class="col-5 rise rise-2">
        <div class="card card--pad">
            <h2 class="card__title">Tulis laporan</h2>
            <p class="card__sub" style="margin-bottom:16px">
                Tulis apa adanya. Kamu bisa memilih untuk tidak menulis nama.
            </p>

            <?php if ($error !== []): ?>
                <div class="note note--danger" role="alert" style="margin-bottom:16px">
                    <i class="fa-solid fa-triangle-exclamation note__ic"></i>
                    <div>
                        <?php foreach ($error as $e): ?>
                            <p><?= esc($e) ?></p>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <form method="post" action="/siswa/lapor.php" class="form" novalidate>
                <input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">
                <input type="hidden" name="aksi" value="kirim">

                <label class="field">
                    <span class="field__label">Ini tentang apa?</span>
                    <select name="kategori">
                        <?php foreach (laporan_kategori() as $kunci => $label): ?>
                            <option value="<?= esc($kunci) ?>"
                                <?= $old['kategori'] === $kunci ? 'selected' : '' ?>>
                                <?= esc($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <div class="field">
                    <span class="field__label">Seberapa mendesak menurutmu?</span>
                    <div class="choices">
                        <?php
                        $pilihanUrgensi = [
                            'biasa'     => ['Biasa', 'fa-mug-hot', 'Bisa dibicarakan santai'],
                            'perhatian' => ['Perlu perhatian', 'fa-hand', 'Sudah mengganggu'],
                            'darurat'   => ['Darurat', 'fa-triangle-exclamation', 'Perlu segera'],
                        ];
                        foreach ($pilihanUrgensi as $kunci => $info): ?>
                            <label class="choice">
                                <input type="radio" name="urgensi_pilih" value="<?= esc($kunci) ?>"
                                    <?= $old['urgensi_pilih'] === $kunci ? 'checked' : '' ?>>
                                <span class="choice__box">
                                    <i class="fa-solid <?= esc($info[1]) ?>"></i><?= esc($info[0]) ?>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <span class="field__hint">Pilihanmu membantu guru memilah. Kamu tidak akan dihakimi.</span>
                </div>

                <label class="field">
                    <span class="field__label">Ceritakan</span>
                    <textarea name="isi" required rows="6" maxlength="2000"
                              placeholder="Tulis apa yang terjadi, di mana, dan siapa yang terlibat kalau kamu tahu. Tidak perlu rapi."><?= esc($old['isi']) ?></textarea>
                    <span class="field__hint">Maksimal 2000 karakter.</span>
                </label>

                <label class="choice" style="display:flex;gap:10px;align-items:flex-start;margin:14px 0">
                    <input type="checkbox" name="anonim" value="1"
                        <?= $old['anonim'] === 1 ? 'checked' : '' ?>>
                    <span style="font-size:13px;line-height:1.5">
                        <strong>Kirim tanpa nama (anonim)</strong><br>
                        Nama dan akunmu tidak dicatat pada laporan ini. Kamu akan
                        menerima kode untuk memantau status.
                    </span>
                </label>

                <button type="submit" class="btn btn--primary btn--block">
                    <i class="fa-solid fa-paper-plane"></i> Kirim laporan
                </button>
            </form>
        </div>
    </div>

    <!-- ============ CEK KODE + DAFTAR ============ -->
    <div class="col-7 rise rise-3">

        <div class="card card--pad" style="margin-bottom:16px">
            <h2 class="card__title">Cek status dengan kode</h2>
            <p class="card__sub" style="margin-bottom:12px">
                Untuk laporan tanpa nama. Masukkan kode yang kamu terima.
            </p>
            <form method="post" action="/siswa/lapor.php" class="form" novalidate
                  style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
                <input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">
                <input type="hidden" name="aksi" value="cek">
                <label class="field" style="flex:1;min-width:180px;margin:0">
                    <span class="field__label">Kode akses</span>
                    <input type="text" name="kode" value="<?= esc($cekKode) ?>"
                           placeholder="AMAN-7A2F9C" style="text-transform:uppercase">
                </label>
                <button type="submit" class="btn btn--soft">
                    <i class="fa-solid fa-magnifying-glass"></i> Cek
                </button>
            </form>

            <?php if ($cekHasil !== null): ?>
                <div class="note note--info" style="margin-top:14px">
                    <i class="fa-solid <?= esc(laporan_kategori_ikon((string) $cekHasil['kategori'])) ?> note__ic"></i>
                    <div>
                        <p class="note__title">
                            <?= esc(laporan_kategori_label((string) $cekHasil['kategori'])) ?>
                            &middot; <?= esc(laporan_status_label((string) $cekHasil['status'])) ?>
                        </p>
                        <p style="margin-top:4px;font-size:13px;line-height:1.55"><?= esc((string) $cekHasil['isi']) ?></p>
                        <?php if (!empty($cekHasil['catatan_bk'])): ?>
                            <p style="margin-top:6px;font-size:13px">
                                <strong>Catatan guru BK:</strong> <?= esc((string) $cekHasil['catatan_bk']) ?>
                            </p>
                        <?php endif; ?>
                        <p class="field__hint" style="margin-top:6px">
                            Dikirim <?= esc(tgl_pendek((string) $cekHasil['created_at'])) ?>
                        </p>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <div class="card card--flush">
            <div class="card__head">
                <div>
                    <h2 class="card__title">Laporan saya</h2>
                    <p class="card__sub">Yang kamu kirim dengan nama (20 terakhir)</p>
                </div>
            </div>

            <?php if ($daftarSaya === []): ?>
                <div class="empty">
                    <span class="empty__ic"><i class="fa-solid fa-shield-heart"></i></span>
                    <p class="empty__title">Belum ada laporan dengan nama</p>
                    <p class="empty__text">
                        Laporan tanpa nama tidak tampil di sini. Pantau lewat kode akses.
                    </p>
                </div>
            <?php else: ?>
                <div class="feed">
                    <?php foreach ($daftarSaya as $lp): ?>
                        <div class="feed__item" style="align-items:flex-start">
                            <span class="feed__ic">
                                <i class="fa-solid <?= esc(laporan_kategori_ikon((string) $lp['kategori'])) ?>"></i>
                            </span>
                            <div class="feed__body">
                                <p class="feed__title" style="white-space:normal">
                                    <?= esc(laporan_kategori_label((string) $lp['kategori'])) ?>
                                </p>
                                <p class="feed__meta" style="white-space:normal">
                                    <?= esc(tgl_pendek((string) $lp['created_at'])) ?>
                                    &middot; kode <?= esc((string) $lp['kode_akses']) ?>
                                </p>
                                <p style="font-size:12px;color:var(--text-2);margin-top:5px;line-height:1.5">
                                    <?= esc(mb_strimwidth((string) $lp['isi'], 0, 160, '...')) ?>
                                </p>
                                <?php if (!empty($lp['catatan_bk'])): ?>
                                    <p style="font-size:12px;color:var(--text-3);margin-top:4px;line-height:1.5">
                                        <strong>Catatan guru BK:</strong> <?= esc((string) $lp['catatan_bk']) ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                            <div style="flex:none">
                                <span class="badge <?= $lp['status'] === 'selesai' ? 'badge--ok' : ($lp['status'] === 'diproses' ? 'badge--info' : 'badge--warn') ?>">
                                    <i class="dot"></i><?= esc(laporan_status_label((string) $lp['status'])) ?>
                                </span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="note note--info" style="margin-top:16px">
            <i class="fa-solid fa-lock note__ic"></i>
            <div>
                <p class="note__title">Siapa yang melihat laporanmu?</p>
                <p style="margin-top:2px">
                    Hanya guru BK yang menangani. Laporan tanpa nama tidak menyimpan
                    akunmu sama sekali, jadi permintaan maaf atau balas dendam tidak
                    mungkin menyasar pelapor.
                </p>
            </div>
        </div>
    </div>
</section>

<?php endif; ?>

<?php require __DIR__ . '/../templates/siswa-foot.php'; ?>
<script>
(function () {
    var b = document.getElementById('salinKode');
    if (!b) { return; }
    b.addEventListener('click', function () {
        var kode = b.getAttribute('data-kode') || '';
        if (navigator.clipboard) {
            navigator.clipboard.writeText(kode).then(function () {
                b.innerHTML = '<i class="fa-solid fa-check"></i> Tersalin';
            });
        }
    });
})();
</script>
