<?php
/**
 * siswa/izin.php
 * ---------------------------------------------------------------------------
 * Pengajuan izin dan sakit.
 *
 * Alurnya sengaja dibuat satu arah dan tanpa jalan pintas:
 *   siswa mengajukan  ->  status "menunggu"
 *   petugas/wali kelas memutuskan lewat panel admin  ->  status berubah
 *
 * Siswa boleh MEMBATALKAN pengajuan yang masih menunggu, karena
 * kesalahan tanggal adalah hal yang wajar terjadi. Pengajuan yang
 * sudah diputuskan tidak bisa diubah atau dihapus dari sini: arsip
 * keputusan perlu tetap utuh.
 *
 * Yang TIDAK dilakukan halaman ini: menulis baris di tabel attendance.
 * Kehadiran tetap dihitung dari scan. Rekap hanya berubah karena keputusan
 * petugas di panel admin, bukan karena siswa menekan tombol di sini.
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

/* Halaman ini boleh membuat tabel (dipakai oleh izin_pastikan_tabel),
   tidak seperti halaman lain yang hanya membacanya. */
$tabelSiap = izin_pastikan_tabel();

/* ------------------------------------------------------------------
 *  PROSES FORMULIR
 * ------------------------------------------------------------------
 * Semua pemeriksaan dilakukan di server. Validasi peramban hanya
 * kenyamanan, bukan jaminan.
 * ------------------------------------------------------------------ */
$error   = [];
$old     = ['jenis' => 'izin', 'mulai' => $today, 'selesai' => $today, 'alasan' => ''];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {

    /* --- Batalkan pengajuan yang masih menunggu ------------------- */
    if (($_POST['aksi'] ?? '') === 'batal') {
        $idBatal = (int) ($_POST['id'] ?? 0);

        if (!csrf_check($_POST['csrf'] ?? null)) {
            $_SESSION['siswa_flash'] = [
                'tipe'  => 'danger',
                'ikon'  => 'fa-triangle-exclamation',
                'pesan' => 'Sesi formulir kedaluwarsa. Muat ulang halaman lalu coba lagi.',
            ];
            header('Location: ' . strtok($_SERVER['REQUEST_URI'] ?? '/siswa/izin.php', '?'));
            exit;
        }

        if ($tabelSiap && $idBatal > 0) {
            /* Syarat "masih menunggu" ditulis di dalam WHERE, bukan
             * dicek terpisah lalu dihapus. Kalau dicek terpisah, dua
             * permintaan bersamaan bisa sama-sama lolos dan
             * keputusan petugas hilang. */
            $terhapus = db_exec(
                "DELETE FROM izin_requests
                  WHERE id = ? AND user_id = ? AND status = 'menunggu'",
                [$idBatal, $userId]
            );
            audit_log($userId, 'izin_dibatalkan', 'info', 'Pengajuan #' . $idBatal . ' dibatalkan siswa.');

            $_SESSION['siswa_flash'] = [
                'tipe'  => $terhapus > 0 ? 'ok' : 'warn',
                'ikon'  => $terhapus > 0 ? 'fa-circle-check' : 'fa-triangle-exclamation',
                'pesan' => $terhapus > 0
                    ? 'Pengajuan dibatalkan.'
                    : 'Pengajuan tidak bisa dibatalkan. Mungkin sudah diputuskan petugas.',
            ];
        }

        header('Location: ' . strtok($_SERVER['REQUEST_URI'] ?? '/siswa/izin.php', '?'));
        exit;
    }

    /* --- Kirim pengajuan baru -------------------------------------- */
    if (!csrf_check($_POST['csrf'] ?? null)) {
        $error[] = 'Sesi formulir kedaluwarsa. Muat ulang halaman lalu coba lagi.';
    }

    $jenis  = (string) ($_POST['jenis'] ?? 'izin');
    $mulai  = trim((string) ($_POST['tanggal_mulai'] ?? ''));
    $selesai = trim((string) ($_POST['tanggal_selesai'] ?? ''));
    $alasan = trim((string) ($_POST['alasan'] ?? ''));

    $old = ['jenis' => $jenis, 'mulai' => $mulai, 'selesai' => $selesai, 'alasan' => $alasan];

    if (!in_array($jenis, ['izin', 'sakit'], true)) {
        $error[] = 'Jenis pengajuan tidak dikenal.';
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $mulai) || strtotime($mulai) === false) {
        $error[] = 'Tanggal mulai tidak valid.';
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selesai) || strtotime($selesai) === false) {
        $error[] = 'Tanggal selesai tidak valid.';
    }

    $jumlahHari = 0;
    if (!$error) {
        if ($selesai < $mulai) {
            $error[] = 'Tanggal selesai tidak boleh lebih dulu dari tanggal mulai.';
        } else {
            $jumlahHari = selisih_hari($mulai, $selesai) + 1;
            if ($jumlahHari > 30) {
                $error[] = 'Pengajuan maksimal 30 hari sekaligus.';
            }
        }
        if ($mulai < $today) {
            $error[] = 'Tanggal mulai tidak boleh di masa lalu. Pengajuan hari yang sudah lewat harus dibuat hari itu juga.';
        }
        if ($mulai > $now->modify('+1 year')->format('Y-m-d')) {
            $error[] = 'Tanggal mulai terlalu jauh di masa depan.';
        }
    }

    if (mb_strlen($alasan) > 400) {
        $error[] = 'Alasan maksimal 400 karakter.';
    }
    if ($alasan === '') {
        $error[] = 'Alasan wajib diisi agar petugas bisa menilai pengajuan.';
    }

    /* Tidak boleh ada dua pengajuan menunggu yang beririsan tanggal.
     * Pengajuan yang SUDAH diputuskan tidak ikut dihitung, jadi siswa
     * tetap bisa mengajukan izin lagi untuk tanggal yang sama setelah
     * pengajuan sebelumnya selesai. */
    $bentrok = null;
    if (!$error && $tabelSiap) {
        $bentrok = db_one(
            "SELECT id, tanggal_mulai, tanggal_selesai FROM izin_requests
              WHERE user_id = ? AND status = 'menunggu'
                AND tanggal_mulai <= ? AND tanggal_selesai >= ?
              ORDER BY id LIMIT 1",
            [$userId, $selesai, $mulai]
        );
        if ($bentrok) {
            $error[] = 'Sudah ada pengajuan menunggu yang mencakup tanggal tersebut'
                . ' (' . tgl_pendek((string) $bentrok['tanggal_mulai'])
                . ' - ' . tgl_pendek((string) $bentrok['tanggal_selesai']) . ').'
                . ' Batalkan dulu kalau ingin mengubah.';
        }
    }

    if (!$error && $tabelSiap) {
        try {
            $idBaru = db_insert(
                'INSERT INTO izin_requests
                    (user_id, jenis, tanggal_mulai, tanggal_selesai, alasan, status)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [$userId, $jenis, $mulai, $selesai, $alasan, 'menunggu']
            );
            audit_log($userId, 'izin_diajukan', 'info',
                'Pengajuan #' . $idBaru . ' ' . $jenis . ' ' . $mulai . ' s/d ' . $selesai . '.');

            $_SESSION['siswa_flash'] = [
                'tipe'  => 'ok',
                'ikon'  => 'fa-circle-check',
                'pesan' => 'Pengajuan ' . ($jenis === 'sakit' ? 'sakit' : 'izin')
                    . ' untuk ' . $jumlahHari . ' hari sudah dikirim. '
                    . 'Statusnya menunggu keputusan petugas.',
            ];
            header('Location: /siswa/izin.php');
            exit;
        } catch (Throwable $e) {
            error_log('[IZIN] Gagal menyimpan: ' . $e->getMessage());
            $error[] = 'Pengajuan gagal disimpan. Coba lagi sebentar lagi.';
        }
    } elseif (!$tabelSiap && !$error) {
        $error[] = 'Fitur ini belum tersedia di instalasi ini. Hubungi administrator sekolah.';
    }

    if ($error && !$tabelSiap) {
        $error[] = 'Tabel pengajuan belum siap. Hubungi administrator sekolah.';
    }
}

/* ------------------------------------------------------------------
 *  DAFTAR PENGAJULAN
 * ------------------------------------------------------------------ */
$daftarIzin = [];
if ($tabelSiap) {
    $daftarIzin = db_all(
        'SELECT * FROM izin_requests WHERE user_id = ? ORDER BY id DESC LIMIT 20',
        [$userId]
    );
}

$jumlahMenunggu  = 0;
$jumlahDisetujui = 0;
$jumlahDitolak   = 0;
foreach ($daftarIzin as $iz) {
    if ($iz['status'] === 'disetujui') {
        $jumlahDisetujui++;
    } elseif ($iz['status'] === 'ditolak') {
        $jumlahDitolak++;
    } else {
        $jumlahMenunggu++;
    }
}

$pageKey   = 'izin';
$pageTitle = 'Izin & Sakit';
$pageSub   = 'Ajukan izin, sakit, atau lihat status pengajuan';

require __DIR__ . '/../templates/siswa-head.php';
?>

<?php if (!$tabelSiap): ?>
    <div class="note note--danger rise rise-1">
        <i class="fa-solid fa-triangle-exclamation note__ic"></i>
        <div>
            <p class="note__title">Fitur pengajuan belum siap</p>
            <p style="margin-top:2px">
                Tabel pengajuan izin tidak dapat dibuat otomatis pada instalasi ini.
                Jalankan <code>/install.php</code> sebagai admin, atau minta
                administrator membuat tabel <code>izin_requests</code>.
            </p>
        </div>
    </div>
<?php else: ?>

<!-- ============ RINGKASAN ============ -->
<section class="grid grid--kpi rise rise-1">
    <div class="kpi kpi--warn">
        <span class="kpi__ic"><i class="fa-solid fa-hourglass-half"></i></span>
        <div class="kpi__body">
            <p class="kpi__value"><?= $jumlahMenunggu ?></p>
            <p class="kpi__label">Menunggu</p>
        </div>
    </div>
    <div class="kpi kpi--ok">
        <span class="kpi__ic"><i class="fa-solid fa-circle-check"></i></span>
        <div class="kpi__body">
            <p class="kpi__value"><?= $jumlahDisetujui ?></p>
            <p class="kpi__label">Disetujui</p>
        </div>
    </div>
    <div class="kpi kpi--muted">
        <span class="kpi__ic"><i class="fa-solid fa-circle-xmark"></i></span>
        <div class="kpi__body">
            <p class="kpi__value"><?= $jumlahDitolak ?></p>
            <p class="kpi__label">Ditolak</p>
        </div>
    </div>
    <div class="kpi kpi--info">
        <span class="kpi__ic"><i class="fa-solid fa-file-lines"></i></span>
        <div class="kpi__body">
            <p class="kpi__value"><?= count($daftarIzin) ?></p>
            <p class="kpi__label">Total pengajuan</p>
            <p class="kpi__hint">20 terakhir</p>
        </div>
    </div>
</section>

<section class="grid" style="margin-top:16px">

    <!-- ============ FORMULIR ============ -->
    <div class="col-5 rise rise-2">
        <div class="card card--pad">
            <h2 class="card__title">Ajukan baru</h2>
            <p class="card__sub" style="margin-bottom:16px">
                Pengajuan dikirim ke petugas untuk diputuskan.
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

            <form method="post" action="/siswa/izin.php" class="form" novalidate>
                <input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">
                <input type="hidden" name="aksi" value="kirim">

                <div class="field">
                    <span class="field__label">Jenis pengajuan</span>
                    <div class="choices">
                        <label class="choice">
                            <input type="radio" name="jenis" value="izin"
                                   <?= $old['jenis'] === 'izin' ? 'checked' : '' ?>>
                            <span class="choice__box">
                                <i class="fa-solid fa-envelope-open-text"></i>Izin
                            </span>
                        </label>
                        <label class="choice">
                            <input type="radio" name="jenis" value="sakit"
                                   <?= $old['jenis'] === 'sakit' ? 'checked' : '' ?>>
                            <span class="choice__box">
                                <i class="fa-solid fa-heart-pulse"></i>Sakit
                            </span>
                        </label>
                    </div>
                </div>

                <div class="formrow">
                    <label class="field">
                        <span class="field__label">Dari tanggal</span>
                        <input type="date" name="tanggal_mulai" required
                               min="<?= esc($today) ?>"
                               max="<?= esc($now->modify('+1 year')->format('Y-m-d')) ?>"
                               value="<?= esc($old['mulai']) ?>"
                               <?= in_array('Tanggal mulai tidak valid.', $error, true) ? 'aria-invalid="true"' : '' ?>>
                    </label>
                    <label class="field">
                        <span class="field__label">Sampai tanggal</span>
                        <input type="date" name="tanggal_selesai" required
                               min="<?= esc($old['mulai'] ?: $today) ?>"
                               max="<?= esc($now->modify('+1 year')->format('Y-m-d')) ?>"
                               value="<?= esc($old['selesai']) ?>"
                               <?= in_array('Tanggal selesai tidak valid.', $error, true) ? 'aria-invalid="true"' : '' ?>>
                    </label>
                </div>

                <label class="field">
                    <span class="field__label">Alasan</span>
                    <textarea name="alasan" required maxlength="400" rows="4"
                              placeholder="Contoh: menemani orang tua berobat, atau: demam sejak kemarin."
                              <?= in_array('Alasan wajib diisi agar petugas bisa menilai pengajuan.', $error, true) ? 'aria-invalid="true"' : '' ?>><?= esc($old['alasan']) ?></textarea>
                    <span class="field__hint">
                        Maksimal 400 karakter. Sebutkan alasan yang bisa diverifikasi,
                        misalnya nama dokter atau acara yang diikuti.
                    </span>
                </label>

                <button type="submit" class="btn btn--primary btn--block">
                    <i class="fa-solid fa-paper-plane"></i>Kirim pengajuan
                </button>
            </form>
        </div>
    </div>

    <!-- ============ DAFTAR ============ -->
    <div class="col-7 rise rise-3">
        <div class="card card--flush">
            <div class="card__head">
                <div>
                    <h2 class="card__title">Pengajuan terbaru</h2>
                    <p class="card__sub">20 pengajuan terakhir</p>
                </div>
            </div>

            <?php if ($daftarIzin === []): ?>
                <div class="empty">
                    <span class="empty__ic"><i class="fa-solid fa-file-signature"></i></span>
                    <p class="empty__title">Belum ada pengajuan</p>
                    <p class="empty__text">
                        Gunakan formulir di samping untuk mengajukan izin atau sakit.
                    </p>
                </div>
            <?php else: ?>
                <div class="feed">
                    <?php foreach ($daftarIzin as $iz): ?>
                        <?php
                        $jenisIzin = $iz['jenis'] === 'sakit' ? 'Sakit' : 'Izin';
                        $jmlHari   = selisih_hari((string) $iz['tanggal_mulai'], (string) $iz['tanggal_selesai']) + 1;
                        $menunggu  = $iz['status'] === 'menunggu';
                        ?>
                        <div class="feed__item" style="align-items:flex-start">
                            <span class="feed__ic <?= $iz['jenis'] === 'sakit' ? 'feed__ic--late' : 'feed__ic--in' ?>">
                                <i class="fa-solid <?= $iz['jenis'] === 'sakit' ? 'fa-heart-pulse' : 'fa-envelope-open-text' ?>"></i>
                            </span>
                            <div class="feed__body">
                                <p class="feed__title" style="white-space:normal">
                                    <?= esc($jenisIzin) ?> &middot; <?= $jmlHari ?> hari
                                </p>
                                <p class="feed__meta" style="white-space:normal">
                                    <?= esc(tgl_pendek((string) $iz['tanggal_mulai'])) ?>
                                    &ndash;
                                    <?= esc(tgl_pendek((string) $iz['tanggal_selesai'])) ?>
                                    &middot; diajukan <?= esc(tgl_pendek((string) $iz['created_at'])) ?>
                                </p>
                                <?php if (!empty($iz['alasan'])): ?>
                                    <p style="font-size:12px;color:var(--text-2);margin-top:5px;line-height:1.5">
                                        <?= esc($iz['alasan']) ?>
                                    </p>
                                <?php endif; ?>
                                <?php if (!empty($iz['catatan_petugas'])): ?>
                                    <p style="font-size:12px;color:var(--text-3);margin-top:4px;line-height:1.5">
                                        <strong>Catatan petugas:</strong> <?= esc($iz['catatan_petugas']) ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                            <div style="display:flex;flex-direction:column;align-items:flex-end;gap:8px;flex:none">
                                <?= badge_izin((string) $iz['status']) ?>
                                <?php if ($menunggu): ?>
                                    <form method="post" action="/siswa/izin.php"
                                          data-konfirmasi="Batalkan pengajuan ini?">
                                        <input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">
                                        <input type="hidden" name="aksi" value="batal">
                                        <input type="hidden" name="id" value="<?= (int) $iz['id'] ?>">
                                        <button type="submit" class="btn btn--sm btn--ghost">
                                            Batalkan
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="note note--info" style="margin-top:16px">
            <i class="fa-solid fa-circle-info note__ic"></i>
            <div>
                <p class="note__title">Bagaimana pengajuan ini dipakai?</p>
                <p style="margin-top:2px">
                    Pengajuan yang disetujui akan membebaskan hari tersebut dari
                    hitungan "tanpa catatan" di halaman riwayat. Selama menunggu
                    keputusan petugas, hari itu masih dihitung sebagai belum absen
                    dan Anda tetap bisa absen seperti biasa.
                </p>
            </div>
        </div>
    </div>
</section>

<?php endif; ?>

<?php require __DIR__ . '/../templates/siswa-foot.php'; ?>
<script>
/* Konfirmasi pembatalan. Tombol yang salah ditekan akan menghapus
   pengajuan yang mungkin sudah dibutuhkan, jadi diminta konfirmasi
   dulu. Dibiarkan native (confirm) karena tidak ada waktu untuk
   membangun dialog sendiri, dan hasilnya konsisten di semua
   peramban. */
document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form.hasAttribute('data-konfirmasi')) { return; }
    if (!window.confirm(form.getAttribute('data-konfirmasi'))) {
        e.preventDefault();
    }
});

/* Tanggal selesai ikut menyesuaikan tanggal mulai, supaya tidak ada
   field yang isiannya mustahil (selesai sebelum mulai). */
(function () {
    var mulai = document.querySelector('input[name="tanggal_mulai"]');
    var selesai = document.querySelector('input[name="tanggal_selesai"]');
    if (!mulai || !selesai) { return; }
    mulai.addEventListener('change', function () {
        selesai.min = mulai.value || selesai.min;
        if (selesai.value && selesai.value < mulai.value) {
            selesai.value = mulai.value;
        }
    });
})();
</script>
