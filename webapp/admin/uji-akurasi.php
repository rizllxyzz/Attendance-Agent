<?php
/**
 * Halaman uji akurasi pengenalan wajah.
 *
 * Alat ukur, bukan bagian dari alur presensi. Halaman ini tidak pernah
 * memanggil scan-check.php, tidak bisa mencatat kehadiran, dan tidak
 * menyimpan foto: yang diukur hanya descriptor 128 dimensi di memori
 * peramban, yang hilang begitu tab ditutup.
 *
 * Kenapa perlu: FACE_MATCH_THRESHOLD, FACE_MATCH_STRICT, dan
 * FACE_MIN_CONFIDENCE awalnya dipilih dari Considerations umum, bukan
 * dari pengukuran. Halaman ini redefine angka itu dari data wajah
 * asli sekolah, sehingga ambang bisa disetel dengan bukti, bukan
 * tebakan.
 *
 * Akses hanya admin. Nilai ambang yang dipakai server ditampilkan
 * sebagai baca-saja supaya tidak bisa diubah dari peramban: kalau
 * diubah harus lewat app-config.php, satu-satunya sumber kebenaran.
 */

require_once __DIR__ . '/../config/app-config.php';
require_once __DIR__ . '/../auth/session.php';

require_login('admin');
$user = current_user();

$e = function ($v) {
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
};

/* Nilai ambang yang sedang dipakai server, dikirim ke peramban sebagai
 * angka tetap supaya hasil uji dibandingkan terhadap konfigurasi yang
 * benar-benar aktif, bukan terhadap asumsi. */
$ambang = [
    'match'  => FACE_MATCH_THRESHOLD,
    'strict' => FACE_MATCH_STRICT,
    'minConf'=> FACE_MIN_CONFIDENCE,
    'spread' => FACE_ENROLL_MAX_SPREAD,
    'samples'=> FACE_ENROLL_MAX_SAMPLES,
];

?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0f172a">
<title>Uji Akurasi Wajah - <?= $e(APP_NAME) ?></title>
<link rel="stylesheet" href="/assets/css/camera-scan.css?v=1">
<link rel="stylesheet" href="/assets/css/uji-akurasi.css?v=1">
</head>
<body>

<div class="bungkus">

    <header class="pulsa">
        <div>
            <div class="pulsa__nama">Uji Akurasi Wajah</div>
            <div class="pulsa__jam">admin <?= $e($user['nama_lengkap']) ?></div>
        </div>
        <div style="text-align:right">
                        <div class="pulsa__nama"><?= $e(APP_NAME) ?></div>
            <div class="pulsa__jam" id="jam">--:--:--</div>
        </div>
    </header>

    <div class="pengumuman" data-jenis="kuning">
        <div class="pengumuman__judul">Alat ukur, bukan alat bobol</div>
        <div class="pengumuman__isi">
            Halaman ini tidak mencatat kehadiran dan tidak bisa dipakai
            sebagai alat bypass. Tidak ada foto yang disimpan: yang diukur
            hanya descriptor di memori peramban, hilang saat tab ditutup.
        </div>
    </div>

    <!-- ============ KONFIGURASI AKTIF ============ -->
    <section class="kartu">
        <div class="kartu__judul">Ambang yang sedang aktif</div>
        <div class="kartu__keterangan">
            Dibaca dari app-config.php. Semua hasil di bawah dibandingkan
            terhadap angka ini, bukan terhadap angka yang diingat.
        </div>
        <div class="angka" style="margin-top:0.75rem">
            <div class="angka__kotak">
                <div class="angka__label">Match threshold (verifikasi 1:N)</div>
                <div class="angka__nilai" id="aMatch"><?= $e($ambang['match']) ?></div>
            </div>
            <div class="angka__kotak">
                <div class="angka__label">Match strict (1:1 saat daftar)</div>
                <div class="angka__nilai" id="aStrict"><?= $e($ambang['strict']) ?></div>
            </div>
            <div class="angka__kotak">
                <div class="angka__label">Min confidence</div>
                <div class="angka__nilai" id="aConf"><?= $e($ambang['minConf']) ?></div>
            </div>
        </div>
    </section>

    <!-- ============ KAMERA ============ -->
    <div class="panggung">
        <video id="video" playsinline muted autoplay></video>
        <div class="panggung__kisi" aria-hidden="true"></div>
        <div class="panggung__lencana">
            <span class="titik" id="titik" data-siang="siap"></span>
            <span id="statusKamera">Kamera</span>
        </div>
        <div class="panggung__petunjuk">
            <strong id="petunjuk">Posisikan wajah di dalam bingkai</strong>
        </div>
    </div>

    <div>
        <div class="progres"><div class="progres__isi" id="progres"></div></div>
        <div class="baris-muat">
            <span id="progresTeks">Menyiapkan model wajah</span>
            <span id="prosesPersen">0%</span>
        </div>
    </div>

    <div class="rijek" id="rijek" hidden>
        <div>
            <strong>Kamera ditolak</strong>
            <div id="rijekIsi">Izinkan akses kamera di pengaturan peramban, lalu muat ulang halaman.</div>
        </div>
    </div>

    <!-- ============ UJI 1: JARAK KE WAJAH TERSIMPAN ============ -->
    <section class="kartu">
        <div class="kartu__judul">
            <span class="kartu__nomor">1</span> Jarak ke wajah yang tersimpan
        </div>
        <div class="kartu__keterangan">
            Tangkap wajah lalu bandingkan dengan descriptor yang tersimpan di
            server. Mengukur seberapa jauh wajah asli berbeda dari centroid
            yang didaftarkan: ini angka yang menentukan apakah ambang
            terlalu longgar.
        </div>
        <div class="kartu__isi">
            <div class="baris-alat">
                <select class="pilih" id="pilihSiswa"></select>
            </div>
            <div class="meter">
                <div class="meter__zona" id="zonaTerima"></div>
                <div class="meter__zona meter__zona--tolak" id="zonaTolak"></div>
                <div class="meter__ambang" id="ambangMatch" data-label="match"></div>
                <div class="meter__ambang" id="ambangStrict" data-label="strict"></div>
                <div class="meter__jarak" id="jarakMeter" style="left:0%"></div>
            </div>
            <div class="meter__sumbu">
                <span>0,0 mirip</span>
                <span>1,2 jauh</span>
            </div>
            <div class="angka">
                <div class="angka__kotak">
                    <div class="angka__label">Jarak descriptor</div>
                    <div class="angka__nilai" id="nilaiJarak">-</div>
                </div>
                <div class="angka__kotak">
                    <div class="angka__label">Keyakinan</div>
                    <div class="angka__nilai" id="nilaiKeyakinan">-</div>
                </div>
            </div>
            <div class="putusan" id="putusan1" data-ton="kuning">
                <div class="putusan__judul">Belum diukur</div>
                <div class="putusan__isi">Tekan tombol tangkap untuk mengukur.</div>
            </div>
            <button class="tombol" id="tombol1">Tangkap dan Bandingkan</button>
        </div>
    </section>

    <!-- ============ UJI 2: SEBARAN SAMPLES ============ -->
    <section class="kartu">
        <div class="kartu__judul">
            <span class="kartu__nomor">2</span> Sebaran sampel pendaftaran
        </div>
        <div class="kartu__keterangan">
            Tangkap beberapa sampel wajah yang sama lalu lihat seberapa jauh
            mereka saling bersimpangan. Ini yang diukur server saat pendaftaran
            dengan FACE_ENROLL_MAX_SPREAD.
        </div>
        <div class="kartu__isi">
            <div class="sampel" id="sampel2"></div>
            <div class="baris-alat">
                <button class="tombol tombol--halus" id="tombol2">Tangkap Sampel</button>
                <button class="tombol tombol--halus" id="tombolReset2">Reset</button>
            </div>
            <div class="angka">
                <div class="angka__kotak">
                    <div class="angka__label">Sebaran ke centroid</div>
                    <div class="angka__nilai" id="nilaiSebaran">-</div>
                </div>
                <div class="angka__kotak">
                    <div class="angka__label">Jarak terbesar antar sampel</div>
                    <div class="angka__nilai" id="nilaiJarakMaks">-</div>
                </div>
            </div>
            <div class="putusan" id="putusan2" data-ton="kuning">
                <div class="putusan__judul">Belum diukur</div>
                <div class="putusan__isi">Tangkap minimal 3 sampel.</div>
            </div>
        </div>
    </section>

    <!-- ============ UJI 3: ORANG LAIN ============ -->
    <section class="kartu">
        <div class="kartu__judul">
            <span class="kartu__nomor">3</span> Penolakan orang lain
        </div>
        <div class="kartu__keterangan">
            Tangkap satu wajah, lalu bandingkan dengan seluruh galeri siswa.
            Menguji apakah orang yang berbeda bisa lolos. Ini yang paling
            menentukan: sistem presensi lebih rela menolak orang salah
            daripada menerima orang salah.
        </div>
        <div class="kartu__isi">
            <button class="tombol tombol--halus" id="tombol3">Tangkap dan Cocokkan ke Galeri</button>
            <div class="putusan" id="putusan3" data-ton="kuning">
                <div class="putusan__judul">Belum diukur</div>
                <div class="putusan__isi">Butuh galeri descriptor siswa yang sudah terdaftar.</div>
            </div>
            <div class="tabel__bungkus" id="tabel3Wrap" hidden>
                <table class="tabel">
                    <thead>
                        <tr>
                            <th>Calon</th>
                            <th>Kelas</th>
                            <th class="angka-kolom">Jarak</th>
                            <th>Putusan</th>
                        </tr>
                    </thead>
                    <tbody id="tabel3"></tbody>
                </table>
            </div>
        </div>
    </section>

    <!-- ============ UJI 4: ULANG ORANG YANG SAMA ============ -->
    <section class="kartu">
        <div class="kartu__judul">
            <span class="kartu__nomor">4</span> Ulang tangkapan wajah sendiri
        </div>
        <div class="kartu__keterangan">
            Tangkap wajah yang sama berulang kali, lalu bandingkan
            setiap pasangan yang terbentuk. Semua hasil disimpan, jadi
            sebaran jarak orang yang sama bisa dilihat langsung. Jalankan
            ini saat pencahayaan, jarak, dan sudut berbeda karena itu
            kondisi nyata di kelas.
        </div>
        <div class="kartu__isi">
            <div class="baris-alat">
                <button class="tombol tombol--halus" id="tombol4a">Tambah sampel A</button>
                <button class="tombol tombol--halus" id="tombol4b">Tambah sampel B</button>
                <button class="tombol tombol--halus" id="tombolReset4">Reset</button>
            </div>
            <div class="sampel">
                <div class="sampel__kotak" id="kotakA">A 0</div>
                <div class="sampel__kotak" id="kotakB">B 0</div>
            </div>
            <div class="angka">
                <div class="angka__kotak">
                    <div class="angka__label">Pasangan dihitung</div>
                    <div class="angka__nilai" id="nilaiPasangan">0</div>
                </div>
                <div class="angka__kotak">
                    <div class="angka__label">Jarak min / rata / maks</div>
                    <div class="angka__nilai" id="nilaiRentang">-</div>
                </div>
            </div>
            <div class="putusan" id="putusan4" data-ton="kuning">
                <div class="putusan__judul">Belum diukur</div>
                <div class="putusan__isi">Tambah minimal satu sampel A dan satu sampel B.</div>
            </div>
        </div>
    </section>

    <!-- ============ REKOMENDASI ============ -->
    <section class="kartu">
        <div class="kartu__judul">Rekomendasi ambang</div>
        <div class="kartu__keterangan">
            Dihitung dari semua pasangan yang terkumpul. Ambang yang baik
            terletak jauh di atas jarak orang yang sama, dan jauh di bawah
            jarak orang berbeda. Jarak orang berbeda diambil dari uji 3,
            jadi rekomendasi baru berarti kalau uji 3 sudah dijalankan.
        </div>
        <div class="kartu__isi">
            <div class="angka" id="rekomendasiIsi">
                <div class="kosong">Belum ada data cukup.</div>
            </div>
            <div class="catatan" id="rekomendasiCatatan"></div>
            <div class="baris-alat">
                <button class="tombol tombol--halus" id="tombolEkspor">Unduh Hasil (.json)</button>
                <button class="tombol tombol--halus tombol--bahaya" id="tombolHapus">Hapus Semua Data</button>
            </div>
        </div>
    </section>

    <p class="catatan">
        Untuk hasil yang berguna, jalankan uji 4 pada beberapa orang dan
        beberapa kondisi, lalu uji 3 dengan wajah yang berbeda dari yang
        terdaftar. Angka dari satu orang saja tidak cukup untuk
        menetapkan ambang.
    </p>

</div>

<script src="<?= $e(FACE_API_BASE) ?>" defer></script>
<script src="/assets/js/uji-akurasi-math.js" defer></script>
<script src="/assets/js/scan-engine.js" defer></script>
<script>
window.UJI = <?= json_encode([
    'apiBase'  => '/api/v1',
    'modelPath'=> FACE_MODEL_BASE,
    'ambang'   => $ambang,
    'admin'    => (int) $user['id'],
    'namaAdmin'=> $user['nama_lengkap'],
], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="/assets/js/uji-akurasi.js" defer></script>

</body>
</html>
