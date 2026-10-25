<?php
/**
 * admin/pengaturan.php
 * ---------------------------------------------------------------------------
 * Pengaturan yang boleh diubah lewat panel: nama sekolah saja.
 *
 * Semua ambang Face ID, GPS, jam masuk/pulang, dan kebijakan keamanan
 * dibaca dari config/app-config.php, bukan dari database. Mengubahnya lewat
 * form berarti menulis file PHP di server, jadi ditampilkan sebagai baca
 * saja supaya tidak ada yang mengira angka di sana bisa diedit di sini.
 */

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_data.php';

/* ==================================================================
 *  PROSES POST
 * ================================================================== */

/* Hasil alat cek jarak. NULL = belum dipakai, NULL lagi dengan flag
 * $cekJarakSah == false berarti koordinatnya tidak terbaca. */
$cekJarak = null;
$cekJarakSah = true;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_hanya_post();
    admin_hanya_admin();

    $formulir = (string) post('form', 'nama');

    /* --------------------------------------------------------------
     *  CEK JARAK (alat bantu, tidak menulis apa pun)
     * --------------------------------------------------------------
     * Sengaja tidak memanggil admin_kembali(): hasil dihitung di halaman
     * yang sama supaya koordinat yang diketik tidak hilang.
     */
    if ($formulir === 'cek-jarak') {
        $latUji = parse_koordinat(post('lat', ''));
        $lngUji = parse_koordinat(post('lng', ''));
        $patokan = admin_lokasi_aktif();
        $latP = $patokan !== null ? (float) $patokan['lat'] : (float) SEKOLAH_LAT;
        $lngP = $patokan !== null ? (float) $patokan['lng'] : (float) SEKOLAH_LNG;
        $radiusP = $patokan !== null ? (int) $patokan['radius_m'] : (int) SEKOLAH_RADIUS_M;

        if ($latUji !== null && $lngUji !== null
            && $latUji >= -90.0 && $latUji <= 90.0
            && $lngUji >= -180.0 && $lngUji <= 180.0
        ) {
            $meter = haversine_distance($latP, $lngP, $latUji, $lngUji);
            $cekJarak = ['meter' => $meter, 'lolos' => $meter <= $radiusP];
        } else {
            $cekJarakSah = false;
        }
    }

    /* --------------------------------------------------------------
     *  LOKASI SEKOLAH (patokan GPS)
     * --------------------------------------------------------------
     * Yang diedit adalah tabel lokasi_sekolah, bukan konstanta
     * SEKOLAH_LAT/LNG/RADIUS_M. Alasannya: runtime membaca patokan dari
     * database (scan-nonce.php untuk klien, scan-check.php untuk
     * verifikasi server), jadi hanya baris database yang benar-benar
     * mengubah siapa yang boleh absen. Konstanta cuma cadangan kalau
     * tabelnya kosong.
     */
    if ($formulir === 'lokasi') {
        $namaLokasi = trim((string) post('nama_lokasi', ''));
        $namaLokasi = preg_replace('/\s+/u', ' ', $namaLokasi) ?? '';

        /* Koma sebagai pemisah desimal lazim dipakai di Indonesia
         * ("-7,63436"), jadi diterima lalu dinormalkan. Tanpa ini admin
         * yang mengetik dengan keyboard lokal selalu gagal simpan. */
        $lat = parse_koordinat(post('lat', ''));
        $lng = parse_koordinat(post('lng', ''));
        $radius = trim((string) post('radius_m', ''));

        $lama = admin_lokasi_aktif();

        if ($namaLokasi === '' || mb_strlen($namaLokasi) < 3 || mb_strlen($namaLokasi) > 120) {
            admin_flash('error', t('flash.location_name_len'));
            admin_kembali();
        }
        if ($lat === null) {
            admin_flash('error', t('flash.lat_invalid'));
            admin_kembali();
        }
        if ($lng === null) {
            admin_flash('error', t('flash.lng_invalid'));
            admin_kembali();
        }
        if ($lat < -90.0 || $lat > 90.0) {
            admin_flash('error', t('flash.lat_range'));
            admin_kembali();
        }
        if ($lng < -180.0 || $lng > 180.0) {
            admin_flash('error', t('flash.lng_range'));
            admin_kembali();
        }
        /* 0,0 adalah koordinat GPS palsu paling umum, dan aplikasi
         * sendiri menolaknya sebagai anti-spoofing. Patokan sekolah
         * tidak mungkin di titik nol, jadi tolak lebih awal dengan
         * pesan yang menyebut alasannya. */
        if (abs($lat) < 0.0001 && abs($lng) < 0.0001) {
            admin_flash('error', t('flash.coord_zero'));
            admin_kembali();
        }
        if (!ctype_digit(preg_replace('/\s+/', '', $radius) ?? '')) {
            admin_flash('error', t('flash.radius_int'));
            admin_kembali();
        }
        $radius = (int) $radius;
        if ($radius < 50 || $radius > 20000) {
            admin_flash('error', t('flash.radius_range'));
            admin_kembali();
        }

        /* 6 desimal ~= 0,1 meter. Lebih dari itu hanya noise float. */
        $lat = round($lat, 6);
        $lng = round($lng, 6);

        $sama = $lama !== null
            && (string) $lama['nama'] === $namaLokasi
            && abs((float) $lama['lat'] - $lat) < 0.0000005
            && abs((float) $lama['lng'] - $lng) < 0.0000005
            && (int) $lama['radius_m'] === $radius;

        if ($sama) {
            admin_flash('warn', t('flash.location_same'));
            admin_kembali();
        }

        $keteranganLama = $lama === null
            ? 'belum ada patokan'
            : sprintf(
                '%s (%.6f, %.6f, %d m)',
                (string) $lama['nama'],
                (float) $lama['lat'],
                (float) $lama['lng'],
                (int) $lama['radius_m']
            );

        if ($lama !== null) {
            db_exec(
                'UPDATE lokasi_sekolah
                    SET nama = ?, lat = ?, lng = ?, radius_m = ?, updated_at = ?
                  WHERE id = ?',
                [$namaLokasi, $lat, $lng, $radius, db_now(), (int) $lama['id']]
            );
        } else {
            db_insert(
                'INSERT INTO lokasi_sekolah (nama, lat, lng, radius_m, is_active, updated_at)
                 VALUES (?, ?, ?, ?, 1, ?)',
                [$namaLokasi, $lat, $lng, $radius, db_now()]
            );
        }

        /* Level warning, sama seperti nama sekolah: ini menentukan siapa
         * yang bisa absen, jadi perubahannya harus kelihatan di jejak
         * audit dan bukan dianggap hal biasa. */
        audit_log($adminId, 'admin.pengaturan.lokasi', 'warning',
            sprintf(
                'Lokasi sekolah: %s -> %s (%.6f, %.6f, %d m)',
                $keteranganLama,
                $namaLokasi,
                $lat,
                $lng,
                $radius
            ));

        admin_flash('ok', t('flash.location_saved'));
        admin_kembali();
    }

    /* --------------------------------------------------------------
     *  NAMA SEKOLAH
     * -------------------------------------------------------------- */
    $nama = trim((string) post('nama_sekolah', ''));
    $nama = preg_replace('/\s+/u', ' ', $nama) ?? '';

    if ($nama === '') {
        admin_flash('error', t('flash.school_empty'));
        admin_kembali();
    }
    if (mb_strlen($nama) > 120) {
        admin_flash('error', t('flash.school_long'));
        admin_kembali();
    }
    if (mb_strlen($nama) < 3) {
        admin_flash('error', t('flash.school_short'));
        admin_kembali();
    }

    $semuaLama = admin_semua_pengaturan();
    $lama = trim((string) ($semuaLama['nama_sekolah'] ?? ''));

    if ($nama === $lama) {
        admin_flash('warn', t('flash.school_same'));
        admin_kembali();
    }

    /* UPSERT supaya tidak bergantung pada sudah atau belum adanya baris. */
    $ada = (int) db_value("SELECT COUNT(*) FROM pengaturan WHERE key_name = 'nama_sekolah'", [], 0);
    if ($ada > 0) {
        db_exec("UPDATE pengaturan SET value = ? WHERE key_name = 'nama_sekolah'", [$nama]);
    } else {
        db_insert("INSERT INTO pengaturan (key_name, value) VALUES ('nama_sekolah', ?)", [$nama]);
    }

    audit_log($adminId, 'admin.pengaturan.nama_sekolah', 'warning',
        'Nama sekolah diubah: "' . $lama . '" -> "' . $nama . '"');

    admin_flash('ok', t('flash.school_saved'));
    admin_kembali();
}

/* ==================================================================
 *  QUERY & TAMPILAN
 * ================================================================== */

$semua = admin_semua_pengaturan();
$namaSekolah = trim((string) ($semua['nama_sekolah'] ?? '')) ?: (string) SCHOOL_NAME;

/* Patokan GPS yang berlaku. Kalau tabelnya kosong, nilai konstanta
 * ditampilkan supaya form tetap terisi dan admin punya tempat mengubah. */
$lokasiAktif = admin_lokasi_aktif();
$lokasiAktifSemua = admin_lokasi_aktif_semua();
$lokasi = [
    'nama'     => $lokasiAktif !== null ? (string) $lokasiAktif['nama'] : $namaSekolah,
    'lat'      => $lokasiAktif !== null ? (float) $lokasiAktif['lat'] : (float) SEKOLAH_LAT,
    'lng'      => $lokasiAktif !== null ? (float) $lokasiAktif['lng'] : (float) SEKOLAH_LNG,
    'radius_m' => $lokasiAktif !== null ? (int) $lokasiAktif['radius_m'] : (int) SEKOLAH_RADIUS_M,
];
$lokasiDariKonstanta = $lokasiAktif === null;
$jumlahLokasiAktif = count($lokasiAktifSemua);

/* Kelompok konfigurasi yang ditampilkan sebagai baca saja. Nilai diambil
 * dari konstanta, bukan dari $_POST, jadi tidak ada jalur yang bisa
 * menulis angka ini lewat peramban.
 *
 * Lintang/bujur/radius tidak ada di sini: ketiganya pindah ke kartu
 * "Lokasi sekolah" karena sudah bisa diedit dari panel. */
$kelompok = [
    t('settings.group_jam') => [
        ['JAM_MASUK_MULAI', t('settings.jam_open_in'), (string) JAM_MASUK_MULAI],
        ['JAM_MASUK_AKHIR', t('settings.jam_close_in'), (string) JAM_MASUK_AKHIR],
        ['JAM_PULANG_MULAI', t('settings.jam_open_out'), (string) JAM_PULANG_MULAI],
        ['JAM_PULANG_AKHIR', t('settings.jam_close_out'), (string) JAM_PULANG_AKHIR],
        ['TOLERANSI_TELAT_MENIT', t('settings.jam_late'), (int) TOLERANSI_TELAT_MENIT],
    ],
    t('settings.group_faceid') => [
        ['FACE_MATCH_THRESHOLD', t('settings.face_threshold'), number_format((float) FACE_MATCH_THRESHOLD, 2)],
        ['FACE_MIN_CONFIDENCE', t('settings.face_confidence'), number_format((float) FACE_MIN_CONFIDENCE, 2)],
        ['FACE_SAMPLE_COUNT', t('settings.face_samples'), (int) FACE_SAMPLE_COUNT],
        ['FACE_CENTROID_ENABLED', t('settings.face_centroid'), FACE_CENTROID_ENABLED ? t('settings.aktif') : t('settings.nonaktif')],
        ['LIVENESS_REQUIRED', t('settings.face_liveness'), (int) LIVENESS_REQUIRED > 0 ? t('settings.wajib') : t('settings.opsional')],
    ],
    t('settings.group_gps') => [
        ['SEKOLAH_LAT', t('settings.gps_lat'), (string) $lokasi['lat']],
        ['SEKOLAH_LNG', t('settings.gps_lng'), (string) $lokasi['lng']],
        ['RADIUS', t('settings.gps_radius'), (int) $lokasi['radius_m'] . ' m'],
        ['GPS_MAX_ACCURACY_M', t('settings.gps_accuracy'), (int) GPS_MAX_ACCURACY_M],
        ['SEKOLAH_RADIUS_M', t('settings.gps_fallback'), (int) SEKOLAH_RADIUS_M . ' m'],
    ],
    t('settings.group_security') => [
        ['SESSION_LIFETIME', t('settings.sec_session'), (int) SESSION_LIFETIME],
        ['CSRF_TOKEN_LIFETIME', t('settings.sec_csrf'), (int) CSRF_TOKEN_LIFETIME],
        ['PASSWORD_MIN_LENGTH', t('settings.sec_pw'), (int) PASSWORD_MIN_LENGTH],
        ['LOGIN_MAX_ATTEMPTS', t('settings.sec_attempts'), (int) LOGIN_MAX_ATTEMPTS],
        ['LOGIN_LOCKOUT_SECONDS', t('settings.sec_lockout'), (int) LOGIN_LOCKOUT_SECONDS],
    ],
];

$pageKey    = 'pengaturan';
$pageTitle  = t('settings.title');
$pageSub    = t('settings.sub');
$pageActionsHtml = '';
$fabHref  = '';
$fabLabel = '';
$fabIcon  = 'fa-gear';

require __DIR__ . '/../templates/admin-head.php';
?>

<!-- ==============================================================
     BAHASA APLIKASI
     ============================================================== -->
<div class="card">
    <div class="card__head">
        <div>
            <p class="card__title"><?= te('settings.lang_title') ?></p>
            <p class="card__sub"><?= te('settings.lang_sub') ?></p>
        </div>
    </div>
    <div class="card__body">
        <div class="row" style="gap:8px;flex-wrap:wrap">
            <?php foreach (lang_supported() as $kode => $nama): $aktif = lang_is($kode); ?>
                <a class="btn <?= $aktif ? 'btn--primary' : 'btn--soft' ?>"
                   href="<?= esc(lang_switch_url($kode) . '&global=1') ?>">
                    <i class="fa-solid <?= $aktif ? 'fa-circle-check' : 'fa-language' ?>"></i>
                    <?= esc($nama) ?>
                </a>
            <?php endforeach; ?>
        </div>
        <p class="field__hint" style="margin-top:10px">
            <?php if ($isAdmin): ?>
                <?= te('settings.lang_note') ?>
            <?php else: ?>
                <?= te('settings.lang_use', ['nama' => esc(lang_supported()[lang_active()] ?? lang_active())]) ?>
            <?php endif; ?>
        </p>
    </div>
</div>

<div class="grid grid--2">
    <!-- ==============================================================
         YANG BISA DIUBAH
         ============================================================== -->
    <div class="card">
        <div class="card__head">
            <div>
                <p class="card__title"><?= te('settings.school_title') ?></p>
                <p class="card__sub"><?= te('settings.school_sub') ?></p>
            </div>
        </div>

        <?php if (!$isAdmin): ?>
            <div class="note note--flat card__body">
                <i class="fa-solid fa-lock note__ic"></i>
                <div class="note__body">
                    <?= te('settings.readonly_note') ?>
                </div>
            </div>
        <?php endif; ?>

        <form method="post" action="/admin/pengaturan.php" class="form form--grid card__body">
            <input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">
            <input type="hidden" name="form" value="nama">

            <div class="field field--grow">
                <label class="field__label" for="pNama"><?= te('settings.school_label') ?></label>
                <input type="text" id="pNama" name="nama_sekolah"
                       value="<?= esc($namaSekolah) ?>"
                       maxlength="120" <?= $isAdmin ? '' : 'readonly' ?>
                       placeholder="<?= te('settings.school_ph') ?>">
                <p class="field__hint">
                    <?= t('settings.school_hint') ?>
                </p>
            </div>

            <?php if ($isAdmin): ?>
                <div class="form__full row row--end">
                    <button type="submit" class="btn btn--primary">
                        <i class="fa-solid fa-floppy-disk"></i> <?= te('settings.save_school') ?>
                    </button>
                </div>
            <?php endif; ?>
        </form>

        <div class="card__head">
            <div>
                <p class="card__title"><?= te('settings.all_title') ?></p>
                <p class="card__sub"><?= t('settings.all_sub') ?></p>
            </div>
        </div>
        <div class="tablewrap">
            <table class="table table--cards">
                <thead>
                    <tr>
                        <th><?= te('settings.col_key') ?></th>
                        <th><?= te('settings.col_value') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($semua === []): ?>
                        <tr>
                            <td colspan="2" class="muted"><?= te('settings.no_rows') ?></td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($semua as $kunci => $nilai): ?>
                            <tr>
                                <td data-label="<?= te('settings.col_key') ?>"><code><?= esc((string) $kunci) ?></code></td>
                                <td data-label="<?= te('settings.col_value') ?>"><?= esc((string) $nilai === '' ? t('settings.empty_value') : (string) $nilai) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if (isset($semua['gps_radius_m'])): ?>
            <div class="card__body">
                <p class="field__hint">
                    Kunci <code>gps_radius_m</code> di tabel ini tidak dipakai
                    saat ini. Radius yang benar-benar berlaku ada di kartu
                    "Lokasi sekolah" di sebelah.
                </p>
            </div>
        <?php endif; ?>
    </div>

    <!-- ==============================================================
         LOKASI SEKOLAH
         ============================================================== -->
    <div class="card">
        <div class="card__head">
            <div>
                <p class="card__title"><?= te('settings.location_title') ?></p>
                <p class="card__sub"><?= te('settings.location_sub') ?></p>
            </div>
        </div>

        <?php if (!$isAdmin): ?>
            <div class="note note--flat card__body">
                <i class="fa-solid fa-lock note__ic"></i>
                <div class="note__body">
                    <?= te('settings.readonly_loc') ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($lokasiDariKonstanta): ?>
            <div class="note note--warn card__body">
                <i class="fa-solid fa-triangle-exclamation note__ic"></i>
                <div class="note__body">
                    <?= t('settings.loc_from_const') ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($jumlahLokasiAktif > 1): ?>
            <div class="note note--warn card__body">
                <i class="fa-solid fa-triangle-exclamation note__ic"></i>
                <div class="note__body">
                    <?= te('settings.loc_multi', ['jumlah' => (int) $jumlahLokasiAktif]) ?>
                </div>
            </div>
        <?php endif; ?>

        <form method="post" action="/admin/pengaturan.php" class="form form--grid card__body">
            <input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">
            <input type="hidden" name="form" value="lokasi">

            <div class="form__full field field--grow">
                <label class="field__label" for="lNama"><?= te('settings.location_label') ?></label>
                <input type="text" id="lNama" name="nama_lokasi"
                       value="<?= esc($lokasi['nama']) ?>"
                       maxlength="120" <?= $isAdmin ? '' : 'readonly' ?>
                       placeholder="<?= te('settings.location_ph') ?>">
                <p class="field__hint">
                    <?= te('settings.location_hint') ?>
                </p>
            </div>

            <div class="field">
                <label class="field__label" for="lLat"><?= te('settings.lat_label') ?></label>
                <input type="text" id="lLat" name="lat" inputmode="decimal"
                       value="<?= esc(number_format($lokasi['lat'], 6, '.', '')) ?>"
                       <?= $isAdmin ? '' : 'readonly' ?> placeholder="-7.634360">
                <p class="field__hint"><?= te('settings.lat_hint') ?></p>
            </div>

            <div class="field">
                <label class="field__label" for="lLng"><?= te('settings.lng_label') ?></label>
                <input type="text" id="lLng" name="lng" inputmode="decimal"
                       value="<?= esc(number_format($lokasi['lng'], 6, '.', '')) ?>"
                       <?= $isAdmin ? '' : 'readonly' ?> placeholder="110.700091">
                <p class="field__hint"><?= te('settings.lng_hint') ?></p>
            </div>

            <div class="form__full field">
                <label class="field__label" for="lRadius"><?= te('settings.radius_label') ?></label>
                <input type="number" id="lRadius" name="radius_m" step="1" min="50" max="20000"
                       value="<?= (int) $lokasi['radius_m'] ?>"
                       <?= $isAdmin ? '' : 'readonly' ?> placeholder="5000">
                <p class="field__hint">
                    <?= te('settings.radius_hint', ['km' => number_format($lokasi['radius_m'] / 1000, 2, ',', '.')]) ?>
                </p>
            </div>

            <?php if ($isAdmin): ?>
                <div class="form__full row row--end">
                    <button type="submit" class="btn btn--primary">
                        <i class="fa-solid fa-location-dot"></i> <?= te('settings.save_location') ?>
                    </button>
                </div>
            <?php endif; ?>
        </form>

        <div class="card__head">
            <div>
                <p class="card__title"><?= te('settings.check_title') ?></p>
                <p class="card__sub"><?= te('settings.check_sub') ?></p>
            </div>
        </div>
        <form method="post" action="/admin/pengaturan.php#lokasi" class="form form--grid card__body">
            <input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">
            <input type="hidden" name="form" value="cek-jarak">

            <div class="field">
                <label class="field__label" for="cLat"><?= te('settings.check_lat') ?></label>
                <input type="text" id="cLat" name="lat" inputmode="decimal"
                       value="<?= esc((string) post('lat', '')) ?>" placeholder="-7.634360">
            </div>
            <div class="field">
                <label class="field__label" for="cLng"><?= te('settings.check_lng') ?></label>
                <input type="text" id="cLng" name="lng" inputmode="decimal"
                       value="<?= esc((string) post('lng', '')) ?>" placeholder="110.700091">
            </div>

            <div class="form__full row row--end">
                <button type="submit" class="btn btn--soft">
                    <i class="fa-solid fa-ruler"></i> <?= te('settings.check_btn') ?>
                </button>
            </div>

            <?php if (isset($cekJarak) && $cekJarak !== null): ?>
                <div class="form__full">
                    <div class="note note--<?= $cekJarak['lolos'] ? 'ok' : 'warn' ?>">
                        <i class="fa-solid <?= $cekJarak['lolos'] ? 'fa-circle-check' : 'fa-triangle-exclamation' ?> note__ic"></i>
                        <div class="note__body">
                            <p class="note__title">
                                <?= te('settings.check_dist', ['jarak' => fmt_distance($cekJarak['meter'])]) ?>
                            </p>
                            <p>
                                <?= te('settings.check_radius', ['radius' => fmt_distance($lokasi['radius_m'])]) ?>
                                <?= $cekJarak['lolos']
                                    ? te('settings.check_accept')
                                    : te('settings.check_reject') ?>
                            </p>
                        </div>
                    </div>
                </div>
            <?php elseif ($cekJarak === null && !$cekJarakSah): ?>
                <div class="form__full">
                    <div class="note note--error">
                        <i class="fa-solid fa-circle-exclamation note__ic"></i>
                        <div class="note__body">
                            <?= te('settings.check_bad') ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </form>
    </div>

    <!-- ==============================================================
         BACA SAJA
         ============================================================== -->
    <div class="card">
        <div class="card__head">
            <div>
                <p class="card__title"><?= te('settings.config_title') ?></p>
                <p class="card__sub"><?= t('settings.config_sub') ?></p>
            </div>
        </div>

        <div class="card__body">
            <div class="note note--info">
                <i class="fa-solid fa-screwdriver-wrench note__ic"></i>
                <div class="note__body">
                    <?= t('settings.config_note') ?>
                </div>
            </div>

            <?php foreach ($kelompok as $judul => $baris): ?>
                <div class="mt-2">
                    <p class="ministat__label"><?= esc($judul) ?></p>
                    <div class="deflist">
                        <?php foreach ($baris as [$konst, $ket, $nilai]): ?>
                            <div class="deflist__row">
                                <span class="deflist__key" title="<?= esc($konst) ?>"><?= esc($ket) ?></span>
                                <span class="deflist__val<?= $nilai === '' ? ' deflist__val--muted' : '' ?>">
                                    <?= esc((string) $nilai) ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../templates/admin-foot.php';
