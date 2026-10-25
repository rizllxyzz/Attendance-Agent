/**
 * Halaman uji akurasi wajah.
 *
 * Empat pengukuran yang saling melengkapi:
 *   1. jarak wajah sekarang ke descriptor yang tersimpan di server
 *   2. sebaran antarsampel wajah yang sama saat pendaftaran
 *   3. penolakan orang lain terhadap seluruh galeri
 *   4. sebaran jarak orang yang sama, dari banyak tangkapan
 *
 * Tujuannya menyetel FACE_MATCH_THRESHOLD, FACE_MATCH_STRICT, dan
 * FACE_ENROLL_MAX_SPREAD dengan angka dari sekolah ini, bukan dari
 * tebakan umum. Halaman ini tidak bisa mencatat kehadiran dan tidak
 * menyimpan foto: yang diproses hanya descriptor di memori peramban.
 */
(function () {
    'use strict';

    var U = window.UJI || {};
    var AMBANG = U.ambang || {
        match: 0.55, strict: 0.45, minConf: 0.2, spread: 0.45, samples: 5
    };

    /* Ujung meter. Jarak descriptor face-api jarang melewati ini, jadi
     *SHOW nilai di atas SKALA tidak punya tempat yang berarti. */
    var SKALA = 1.2;

    function el(id) { return document.getElementById(id); }

    var video = el('video');
    var titik = el('titik');
    var statusKamera = el('statusKamera');
    var petunjuk = el('petunjuk');
    var progres = el('progres');
    var progresTeks = el('progresTeks');
    var persen = el('prosesPersen');
    var rijek = el('rijek');
    var rijekIsi = el('rijekIsi');
    var jam = el('jam');

    var stream = null;
    var pumping = false;
    var sibuk = false;
    /* Kamera bisa tampil sebelum model selesai diunduh, jadi tombol uji
     * harus tahu bedanya: kamera siap bukan berarti pengukuran siap. */
    var modelSiap = false;
    var lastDetect = null;
    var galeri = null;

    var sampel2 = [];
    var sampelA = [];
    var sampelB = [];

    var hasil = {
        pasanganOrangSama: [],
        pasanganOrangBeda: [],
        sebaranDaftar: [],
    };

    /* =================================================================
     |  Tampilan dasar
     * ================================================================= */

    function progresSet(p, teks) {
        progres.style.width = Math.max(0, Math.min(100, p)) + '%';
        persen.textContent = Math.round(p) + '%';
        if (teks) progresTeks.textContent = teks;
    }

    function siang(state, teks) {
        titik.setAttribute('data-siang', state);
        if (teks) statusKamera.textContent = teks;
    }

    function tampilAngka(elNilai, teks, ton) {
        elNilai.textContent = teks;
        elNilai.className = 'angka__nilai' + (ton ? ' angka__nilai--' + ton : '');
    }

    function tampilPutusan(elBox, ton, judul, isi) {
        elBox.setAttribute('data-ton', ton);
        elBox.querySelector('.putusan__judul').textContent = judul;
        elBox.querySelector('.putusan__isi').textContent = isi;
    }

    function jalankanJam() {
        function tick() {
            var d = new Date();
            function p(n) { return (n < 10 ? '0' : '') + n; }
            jam.textContent = p(d.getHours()) + ':' + p(d.getMinutes()) + ':' + p(d.getSeconds());
        }
        tick();
        setInterval(tick, 1000);
    }

    /* =================================================================
     |  Statistika
     * ================================================================= */

    /* Perhitungan ada di uji-akurasi-math.js supaya bisa diuji tanpa
     * kamera dan tanpa peramban. Di sini hanya diberi nama pendek. */
    var M = window.UjiMath;
    if (!M) {
        throw new Error('uji-akurasi-math.js gagal dimuat');
    }

    var mean = M.mean;
    var stdev = M.stdev;
    var nilaiMin = M.nilaiMin;
    var nilaiMax = M.nilaiMax;
    var persentil = M.persentil;
    var f = M.f;

    function kolom(teks, kelas) {
        var td = document.createElement('td');
        td.textContent = teks;
        if (kelas) td.className = kelas;
        return td;
    }

    /* =================================================================
     |  Jarak descriptor
     * ================================================================= */

    var jarak = M.jarak;
    var keyakinan = M.keyakinan;

    /* Data siswa dari galeri. match() hanya mengembalikan id, nama, dan
     * nis, jadi kelas diambil dari galeri sendiri. */
    function infoSiswa(id) {
        var g = ScanEngine.getGallery() || [];
        for (var i = 0; i < g.length; i++) {
            if (String(g[i].id) === String(id)) return g[i];
        }
        return null;
    }

    function keVektor(id) {
        var g = ScanEngine.getGallery() || [];
        for (var i = 0; i < g.length; i++) {
            if (String(g[i].id) === String(id)) {
                var m = ScanEngine.getMatrix();
                var out = new Float32Array(128);
                var off = g[i].index * 128;
                for (var k = 0; k < 128; k++) out[k] = m[off + k];
                return out;
            }
        }
        return null;
    }

    /* =================================================================
     |  Kamera
     * ================================================================= */

    async function bukaKamera() {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            rijek.hidden = false;
            rijekIsi.textContent = 'Peramban ini tidak mendukung kamera. Gunakan HTTPS atau peramban modern.';
            siang('gagal', 'Tidak didukung');
            throw new Error('getUserMedia tidak tersedia');
        }
        try {
            stream = await navigator.mediaDevices.getUserMedia({
                video: { facingMode: 'user', width: { ideal: 640 }, height: { ideal: 480 } },
                audio: false,
            });
            video.srcObject = stream;
            await video.play();
            siang('siap', 'Kamera siap');
        } catch (err) {
            rijek.hidden = false;
            var nama = err && err.name ? err.name : 'error';
            rijekIsi.textContent = nama === 'NotAllowedError'
                ? 'Izinkan akses kamera di pengaturan peramban, lalu muat ulang halaman.'
                : 'Kamera tidak dapat dibuka (' + nama + '). Pastikan tidak dipakai aplikasi lain.';
            siang('gagal', 'Kamera gagal');
            throw err;
        }
    }

    async function pompa() {
        if (pumping) return;
        pumping = true;
        try {
            while (stream) {
                try {
                    lastDetect = await ScanEngine.detectFromVideo(video);
                } catch (e) {
                    lastDetect = null;
                }
                if (!sibuk && lastDetect) {
                    petunjuk.textContent = lastDetect.ok
                        ? 'Wajah terbaca, siap diukur'
                        : lastDetect.reason;
                }
                await new Promise(function (r) {
                    requestAnimationFrame(function () { r(); });
                });
            }
        } finally {
            pumping = false;
        }
    }

    /* Tangkap satu descriptor, atau lempar dengan alasan yang jelas
     * supaya pengguna tahu apa yang harus diperbaiki. */
    async function tangkap() {
        var d = await ScanEngine.detectFromVideo(video);
        if (!d.ok) throw new Error(d.reason);
        return d;
    }

    /* Bungkus setiap tombol supaya tombol lain tidak bisa ditekan
     * di tengah pengukuran, dan error selalu sampai ke layar. */
    function saatKerja(fn, labelTeks) {
        return async function () {
            if (sibuk) return;
            if (!modelSiap) {
                petunjuk.textContent = 'Bobot model masih diunduh. Tunggu sampai '
                    + 'persentase mencapai 100%.';
                return;
            }
            sibuk = true;
            siang('jalan', labelTeks);
            petunjuk.textContent = 'Tahan wajah tetap, jangan bergerak';
            try {
                await fn();
            } catch (e) {
                petunjuk.textContent = e.message || String(e);
                tampilPutusan(el('putusan1'), 'gagal', 'Gagal mengukur', e.message || String(e));
                console.warn('[UJI]', e);
            } finally {
                sibuk = false;
                siang('siap', 'Siap');
                petunjuk.textContent = 'Posisikan wajah di dalam bingkai';
            }
        };
    }

    /* =================================================================
     |  Galeri
     * ================================================================= */

    async function muatGaleri() {
        try {
            /* Id admin asli dipakai sebagai pemilik gallery, bukan 0.
             * ScanEngine menandai gallery dengan "<userId>:<scope>" dan
             * menyimpannya ke cache; memakai 0 membuat penanda itu
             * tidak dimiliki akun mana pun. */
            await ScanEngine.loadGallery(true, 'all', U.admin);
        } catch (e) {
            galeri = [];
            isiDaftarSiswa();
            return galeri;
        }
        galeri = ScanEngine.getGallery() || [];
        isiDaftarSiswa();
        return galeri;
    }

    function isiDaftarSiswa() {
        var pilih = el('pilihSiswa');
        if (!pilih) return;
        pilih.innerHTML = '';
        if (!galeri || !galeri.length) {
            var o0 = document.createElement('option');
            o0.value = '';
            o0.textContent = '(belum ada descriptor siswa)';
            pilih.appendChild(o0);
            return;
        }
        galeri.forEach(function (g) {
            var o = document.createElement('option');
            o.value = String(g.id);
            o.textContent = g.name
                + (g.kls ? ' - kelas ' + g.kls : '')
                + (g.nis ? ' - ' + g.nis : '');
            pilih.appendChild(o);
        });
    }

    /* =================================================================
     |  Meter jarak
     * ================================================================= */

    function gambarMeter() {
        var zTerima = el('zonaTerima');
        var zTolak = el('zonaTolak');
        var aMatch = el('ambangMatch');
        var aStrict = el('ambangStrict');
        var posisi = function (v) { return (v / SKALA * 100) + '%'; };

        if (zTerima) zTerima.style.width = posisi(AMBANG.match);
        if (zTolak) {
            zTolak.style.left = posisi(AMBANG.match);
            zTolak.style.right = '0';
        }
        if (aMatch) aMatch.style.left = posisi(AMBANG.match);
        if (aStrict) aStrict.style.left = posisi(AMBANG.strict);
    }

    function geserMeter(nilai) {
        var p = Math.max(0, Math.min(1, nilai / SKALA));
        el('jarakMeter').style.left = (p * 100) + '%';
    }

    /* =================================================================
     |  UJI 1: jarak ke descriptor tersimpan
     * ================================================================= */

    var uji1 = saatKerja(async function () {
        var id = el('pilihSiswa').value;
        if (!id) throw new Error('Galeri descriptor kosong. Daftarkan wajah siswa lebih dulu.');

        var vektor = keVektor(id);
        if (!vektor) throw new Error('Descriptor siswa itu tidak ada di galeri.');

        var d = await tangkap();
        var j = jarak(d.descriptor, vektor);
        var key = keyakinan(j, AMBANG.match);

        geserMeter(j);
        tampilAngka(el('nilaiJarak'), f(j, 4));
        tampilAngka(el('nilaiKeyakinan'), f(key * 100, 1) + '%', key > 0 ? 'ok' : 'gagal');

        var box = el('putusan1');
        if (j <= AMBANG.strict) {
            tampilPutusan(box, 'ok', 'Sangat mirip',
                'Jarak ' + f(j, 4) + ' masih di bawah strict (' + AMBANG.strict
                + '). Pendaftaran ulang wajah ini akan lolos.');
        } else if (j <= AMBANG.match) {
            tampilPutusan(box, 'kuning', 'Diterima, tapi tidak rapat',
                'Jarak ' + f(j, 4) + ' lolos di bawah match (' + AMBANG.match
                + ') tapi sudah melewati strict (' + AMBANG.strict
                + '). Wajah seperti ini akan sering gagal saat didaftarkan ulang. '
                + 'Perbaiki pencahayaan saat pendaftaran.');
        } else {
            tampilPutusan(box, 'gagal', 'Tidak dianggap orang yang sama',
                'Jarak ' + f(j, 4) + ' melewati match (' + AMBANG.match
                + '). Server akan menolak dengan FACE_NOT_FOUND.');
        }
    }, 'Mengukur');

    /* =================================================================
     |  UJI 2: sebaran sampel pendaftaran
     * ================================================================= */

    function gambarKotak2() {
        var box = el('sampel2');
        if (!box) return;
        box.innerHTML = '';
        for (var i = 0; i < AMBANG.samples; i++) {
            var k = document.createElement('div');
            k.className = 'sampel__kotak';
            k.setAttribute('data-terisi', sampel2[i] ? '1' : '0');
            k.textContent = String(i + 1);
            box.appendChild(k);
        }
    }

    function hitungSebaran() {
        return M.sebaranSampel(sampel2);
    }

    var uji2 = saatKerja(async function () {
        /* Satu pendaftaran adalah satu sesi 5 sampel. Menumpuk sesi
         * lama dan baru membuat sebaran yang diukur bukan sebaran yang
         * akan dilihat server saat pendaftaran. */
        sampel2 = [];
        gambarKotak2();

        var s = await ScanEngine.captureEnrollSamples(
            video, AMBANG.samples, function (i, total, d, ok) {
                /* Pemanggil sukses hanya mengirim tiga argumen, jadi flag
                 * yang menentukan adalah "bukan false". */
                var masuk = ok !== false;
                petunjuk.textContent = masuk
                    ? 'Sampel ' + i + ' dari ' + total + ' - masuk'
                    : 'Sampel ' + i + ' dilewati, ' + (d && d.reason ? d.reason : 'tidak terbaca');
                var kotak = el('sampel2').children[i - 1];
                if (masuk && kotak) kotak.setAttribute('data-terisi', '1');
            }
        );
        if (s.length) {
            sampel2 = sampel2.concat(s);
            gambarKotak2();
        }
        if (!sampel2.length) {
            throw new Error('Tidak ada sampel yang terbaca. Pastikan wajah terlihat jelas.');
        }
        perbaruiSebaran();
    }, 'Mengambil sampel');

    function perbaruiSebaran() {
        var r = hitungSebaran();
        if (!r) {
            tampilAngka(el('nilaiSebaran'), '-');
            tampilAngka(el('nilaiJarakMaks'), '-');
            return;
        }
        hasil.sebaranDaftar.push(r.sebaran);
        tampilAngka(el('nilaiSebaran'), f(r.sebaran, 4),
            r.sebaran <= AMBANG.spread ? 'ok' : 'gagal');
        tampilAngka(el('nilaiJarakMaks'), f(r.maks, 4));

        var box = el('putusan2');
        if (r.sebaran <= AMBANG.spread * 0.7) {
            tampilPutusan(box, 'ok', 'Sangat konsisten',
                'Sebaran ' + f(r.sebaran, 4) + ' jauh di bawah batas ' + AMBANG.spread
                + '. Wajah seperti ini mudah didaftarkan.');
        } else if (r.sebaran <= AMBANG.spread) {
            tampilPutusan(box, 'kuning', 'Cukup konsisten',
                'Sebaran ' + f(r.sebaran, 4) + ' masih di bawah batas ' + AMBANG.spread
                + ', tapi ruangnya tinggal sedikit. Tangkapan dengan purposely');
        } else {
            tampilPutusan(box, 'gagal', 'Terlalu tidak stabil',
                'Sebaran ' + f(r.sebaran, 4) + ' melewati batas ' + AMBANG.spread
                + '. Server akan menolak dengan SAMPLES_INCONSISTENT. Pastikan '
                + 'pencahayaan merata dan wajah tidak bergerak saat diambil.');
        }
        hitungRekomendasi();
    }

    /* =================================================================
     |  UJI 3: penolakan orang lain
     * ================================================================= */

    var uji3 = saatKerja(async function () {
        if (!galeri || !galeri.length) {
            throw new Error('Galeri kosong. Daftarkan wajah minimal satu siswa dulu.');
        }
        var d = await tangkap();
        var kandidat = ScanEngine.match(d.descriptor, 5);

        var tbody = el('tabel3');
        tbody.innerHTML = '';
        kandidat.forEach(function (k) {
            /* match() hanya menandai baris pertama sebagai matched, jadi
             * status setiap baris dihitung ulang dari jaraknya sendiri. */
            var lolos = k.distance <= AMBANG.match;
            var info = infoSiswa(k.id);
            var tr = document.createElement('tr');
            tr.appendChild(kolom(info ? info.name : k.name));
            tr.appendChild(kolom(info && info.kls != null ? String(info.kls) : '-'));
            tr.appendChild(kolom(f(k.distance, 4), 'angka-kolom'));
            tr.appendChild(kolom(lolos ? 'lolos' : 'ditolak', lolos ? 'ok' : 'gagal'));
            tbody.appendChild(tr);
        });
        el('tabel3Wrap').hidden = false;

        if (!kandidat.length) {
            tampilPutusan(el('putusan3'), 'kuning', 'Tidak ada yang dicocokkan', 'Galeri kosong.');
            return;
        }

        var terdekat = kandidat[0].distance;
        var selisih = kandidat.length > 1 ? kandidat[0].distance - kandidat[1].distance : null;
        var teksSelisih = selisih != null ? ', selisih ke kandidat kedua ' + f(selisih, 4) : '';

        if (terdekat <= AMBANG.match) {
            /* Wajah ini benar-benar milik salah satu siswa di galeri, jadi
             * jaraknya adalah jarak orang yang sama, bukan orang berbeda. */
            tampilPutusan(el('putusan3'), 'kuning',
                'Wajah ini cocok dengan ' + kandidat[0].name,
                'Jarak ' + f(terdekat, 4) + ' lolos di bawah match (' + AMBANG.match + ')'
                + teksSelisih + '. Ini bukan uji penolakan: untuk mengukur penolakan, '
                + 'pakai wajah yang tidak terdaftar.');
        } else {
            /* Descriptor ini milik orang yang tidak di galeri, jadi jarak
             * ke kandidat terdekat adalah jarak orang berbeda yang sah. */
            hasil.pasanganOrangBeda.push(terdekat);
            tampilPutusan(el('putusan3'), 'ok', 'Ditolak dengan benar',
                'Jarak terdekat ' + f(terdekat, 4) + ' melewati match (' + AMBANG.match
                + '). Kandidat terdekat: ' + kandidat[0].name + teksSelisih + '.');
        }
        hitungRekomendasi();
    }, 'Mencocokkan');

    /* =================================================================
     |  UJI 4: sebaran jarak orang yang sama
     * ================================================================= */

    function gambarKotak4() {
        var a = el('kotakA');
        var b = el('kotakB');
        if (a) {
            a.textContent = 'A ' + sampelA.length;
            a.setAttribute('data-terisi', sampelA.length ? '1' : '0');
        }
        if (b) {
            b.textContent = 'B ' + sampelB.length;
            b.setAttribute('data-terisi', sampelB.length ? '1' : '0');
        }
    }

    /* Setiap t_incapture menambah satu pasangan baru ke daftar orang
     * sama. Semua pasangan dihitung ulang dari awal supaya tidak ada
     * angka yang tertinggal setelah sampel dihapus. */
    function tambahSampel(daftar, label) {
        return saatKerja(async function () {
            var d = await tangkap();
            daftar.push(new Float32Array(d.descriptor));
            if (daftar.length > 40) daftar.shift();
            gambarKotak4();
            perbaruiPasangan();
            petunjuk.textContent = label + ' tersimpan, total ' + daftar.length;
        }, 'Menambah sampel');
    }

    function perbaruiPasangan() {
        var pasangan = M.pasanganSilang(sampelA, sampelB);

        tampilAngka(el('nilaiPasangan'), String(pasangan.length));

        if (!pasangan.length) {
            tampilAngka(el('nilaiRentang'), '-');
            tampilPutusan(el('putusan4'), 'kuning', 'Belum diukur',
                'Tambah minimal satu sampel A dan satu sampel B.');
            return;
        }

        hasil.pasanganOrangSama = pasangan.slice();
        tampilAngka(el('nilaiRentang'),
            f(nilaiMin(pasangan), 3) + ' / ' + f(mean(pasangan), 3)
            + ' / ' + f(nilaiMax(pasangan), 3));

        var box = el('putusan4');
        var p95 = persentil(pasangan, 0.95);

        if (nilaiMax(pasangan) <= AMBANG.match) {
            tampilPutusan(box, 'ok', 'Semua pasangan lolos',
                'Jarak terbesar ' + f(nilaiMax(pasangan), 4) + ' masih di bawah match ('
                + AMBANG.match + '). p95 ' + f(p95, 4) + '.');
        } else if (p95 <= AMBANG.match) {
            tampilPutusan(box, 'kuning', 'Sebagian pasangan akan ditolak',
                'p95 ' + f(p95, 4) + ' masih lolos, tapi jarak terburuk '
                + f(nilaiMax(pasangan), 4) + ' melewati match ('
                + AMBANG.match + '). Kondisi yang paling buruk perlu ditangani.');
        } else {
            tampilPutusan(box, 'gagal', 'Terlalu banyak yang gagal',
                'p95 ' + f(p95, 4) + ' sudah melewati match (' + AMBANG.match
                + '). Sebagian besar tangkapan akan ditolak. Perbaiki kondisi pengambilan '
                + 'wajah, jangan menaikkan ambang dulu.');
        }
        hitungRekomendasi();
    }

    var uji4A = tambahSampel(sampelA, 'Sampel A');
    var uji4B = tambahSampel(sampelB, 'Sampel B');

    function reset4() {
        sampelA = [];
        sampelB = [];
        gambarKotak4();
        perbaruiPasangan();
    }

    /* =================================================================
     |  Rekomendasi ambang
     * ================================================================= */

    function hitungRekomendasi() {
        var box = el('rekomendasiIsi');
        var catatan = el('rekomendasiCatatan');
        if (!box) return;

        var sama = hasil.pasanganOrangSama;
        var beda = hasil.pasanganOrangBeda;

        if (!sama.length) {
            box.innerHTML = '<div class="kosong">Belum ada data cukup.</div>';
            catatan.textContent = 'Jalankan uji 4 dulu, butuh jarak antar-wajah orang yang sama.';
            return;
        }

        /* Seluruh keputusan diambil di satu fungsi yang sudah diuji:
         *M.saranAmbang(). Halaman ini hanya menampilkan hasilnya. */
        var r = M.saranAmbang(sama, beda, AMBANG.match);
        var p95 = r.p95;
        var saran = r.saran;
        var sumber = r.sumber;
        var ton = r.ton;
        var tumpangTindih = r.posisi === 'tumpangTindih';
        var html = '';

        html += '<div class="angka__kotak"><div class="angka__label">Jarak orang yang sama</div>';
        html += '<div class="angka__nilai angka__nilai--' + (tumpangTindih ? 'gagal' : 'ok') + '">';
        html += f(mean(sama), 4) + ' <span class="angka__satuan">rata</span></div>';
        html += '<div class="angka__label">min ' + f(nilaiMin(sama), 4)
            + ' - maks ' + f(nilaiMax(sama), 4)
            + ' - p95 ' + f(p95, 4)
            + ' - simpangan ' + f(stdev(sama), 4) + '</div></div>';

        html += '<div class="angka__kotak"><div class="angka__label">Jarak orang berbeda</div>';
        html += '<div class="angka__nilai">'
            + (beda.length ? f(nilaiMin(beda), 4) : '-')
            + ' <span class="angka__satuan">terdekat</span></div>';
        html += '<div class="angka__label">'
            + (beda.length ? beda.length + ' tangkapan sudah diukur' : 'belum ada, jalankan uji 3')
            + '</div></div>';

        html += '<div class="angka__kotak"><div class="angka__label">Rekomendasi match threshold</div>';
        html += '<div class="angka__nilai angka__nilai--' + ton + '">'
            + f(saran, 4) + '</div>';
        html += '<div class="angka__label">dihitung ' + sumber + '</div></div>';

        box.innerHTML = html;

        if (tumpangTindih) {
            catatan.textContent =
                'Jarak orang sama dan orang berbeda saling bertumpuk, jadi tidak ada '
                + 'ambang yang bisa memisahkannya. Penyebab yang paling mungkin: '
                + 'pencahayaan berubah tajam, wajah masih terlalu kecil di frame, atau '
                + 'galeri masih sedikit. Perbaiki kondisi pengambilan wajah lebih dulu. '
                + 'Menaikkan ambang di keadaan ini hanya menukar penolakan salah menjadi '
                + 'penerimaan salah, dan penerimaan salah jauh lebih buruk untuk presensi.';
        } else if (!beda.length) {
            catatan.textContent =
                'Rekomendasi ini belum final karena belum ada data orang berbeda. Jalankan '
                + 'uji 3 dengan wajah yang tidak terdaftar, kalau tidak angka ini bisa '
                + 'terlalu rendah dan membuat siswa yang sah ikut ditolak.';
        } else if (r.posisi === 'terlaluLonggar') {
            catatan.textContent =
                'Ambang sekarang (' + AMBANG.match + ') sudah menerima orang berbeda '
                + 'yang hanya berjarak ' + f(r.batasAtas, 4) + ', padahal orang yang sah '
                + 'tidak pernah melewati ' + f(r.batasBawah, 4) + '. Ini kondisi yang paling '
                + 'berbahaya: presensi bisa tercatat untuk orang yang salah. Turunkan ambang '
                + 'ke sekitar ' + f(saran, 4) + ' atau perbaiki pendaftaran wajah.';
        } else if (r.posisi === 'terlaluKetat') {
            catatan.textContent =
                'Ambang sekarang (' + AMBANG.match + ') sudah lebih ketat daripada yang '
                + 'terukur: orang yang sah pernah sampai ' + f(r.batasBawah, 4) + '. '
                + 'Siswa seperti itu akan gagal absen padahal wajahnya benar. Naikkan '
                + 'sekitar ' + f(saran, 4) + '.';
        } else {
            catatan.textContent =
                'Ambang sekarang (' + AMBANG.match + ') berada di dalam interval aman '
                + f(r.batasBawah, 4) + ' sampai ' + f(r.batasAtas, 4) + ', jadi semua '
                + 'sampel yang diamati diklasifikasikan dengan benar. Angka ini belum '
                + 'menjamin apa pun untuk wajah yang belum diuji: tambah lebih banyak '
                + 'siswa dan lebih banyak orang berbeda sebelum dianggap final.';
        }
    }

    /* =================================================================
     |  Ekspor dan hapus
     * ================================================================= */

    function ekspor() {
        var data = {
            dibuatPada: new Date().toISOString(),
            diujiOleh: U.namaAdmin,
            ambangAktif: AMBANG,
            catatan: 'Jarak Euclid descriptor 128 dimensi. Tidak ada foto yang disertakan.',
            jarakOrangSama: hasil.pasanganOrangSama,
            jarakOrangBeda: hasil.pasanganOrangBeda,
            sebaranPendaftaran: hasil.sebaranDaftar,
            ringkasan: {
                orangSama: {
                    jumlah: hasil.pasanganOrangSama.length,
                    min: nilaiMin(hasil.pasanganOrangSama),
                    rata: mean(hasil.pasanganOrangSama),
                    maks: nilaiMax(hasil.pasanganOrangSama),
                    p95: persentil(hasil.pasanganOrangSama, 0.95),
                    simpangan: stdev(hasil.pasanganOrangSama),
                },
                orangBeda: {
                    jumlah: hasil.pasanganOrangBeda.length,
                    terdekat: nilaiMin(hasil.pasanganOrangBeda),
                },
            },
        };

        var teks = JSON.stringify(data, null, 2);
        var blob = new Blob([teks], { type: 'application/json' });
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = 'uji-akurasi-wajah-' + new Date().toISOString().slice(0, 10) + '.json';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    }

    function hapusSemua() {
        sampel2 = [];
        sampelA = [];
        sampelB = [];
        hasil.pasanganOrangSama = [];
        hasil.pasanganOrangBeda = [];
        hasil.sebaranDaftar = [];
        gambarKotak2();
        gambarKotak4();
        el('tabel3').innerHTML = '';
        el('tabel3Wrap').hidden = true;
        tampilAngka(el('nilaiJarak'), '-');
        tampilAngka(el('nilaiKeyakinan'), '-');
        tampilAngka(el('nilaiSebaran'), '-');
        tampilAngka(el('nilaiJarakMaks'), '-');
        tampilAngka(el('nilaiPasangan'), '0');
        tampilAngka(el('nilaiRentang'), '-');
        tampilPutusan(el('putusan1'), 'kuning', 'Belum diukur',
            'Tekan tombol tangkap untuk mengukur.');
        tampilPutusan(el('putusan2'), 'kuning', 'Belum diukur',
            'Tangkap minimal 3 sampel.');
        tampilPutusan(el('putusan3'), 'kuning', 'Belum diukur',
            'Butuh galeri descriptor siswa yang sudah terdaftar.');
        tampilPutusan(el('putusan4'), 'kuning', 'Belum diukur',
            'Tambah minimal satu sampel A dan satu sampel B.');
        hitungRekomendasi();
    }

    /* =================================================================
     |  Start
     * ================================================================= */

    async function boot() {
        jalankanJam();
        gambarMeter();
        gambarKotak2();
        gambarKotak4();

        if (typeof window.faceapi === 'undefined') {
            petunjuk.textContent = 'Library face-api gagal dimuat dari CDN.';
            siang('gagal', 'Library gagal');
            return;
        }
        if (typeof ScanEngine === 'undefined') {
            petunjuk.textContent = 'scan-engine.js gagal dimuat.';
            siang('gagal', 'Mesin scan gagal');
            return;
        }

        ScanEngine.configure({
            apiBase: U.apiBase,
            modelPath: U.modelPath,
            threshold: AMBANG.match,
            scope: 'all',
            userId: U.admin,
        });

        /* Kamera tidak memerlukan model, sedangkan bobot
         * face_recognition sekitar 6 MB. Menunggu model dulu membuat
         * kamera baru muncul setelah belasan detik di koneksi seluler,
         * padahal getUserMedia hanya butuh persetujuan izin. Jadi
         * keduanya dijalankan beriringan: kamera langsung dibuka,
         * model diunduh di belakangnya. */
        var kamera = bukaKamera();
        /* Penolakan sudah ditangani di dalam bukaKamera (pesan untuk
         * pengguna sudah ditampilkan). Handler di sini hanya menahan
         * penolakan supaya tidak menjadi unhandled rejection. */
        kamera.catch(function () { });

        try {
            await ScanEngine.loadModels(function (nama, p) {
                progresSet(p, 'Memuat ' + nama);
            });
            modelSiap = true;
        } catch (e) {
            petunjuk.textContent = 'Bobot model gagal dimuat: ' + (e.message || e);
            siang('gagal', 'Model gagal');
            return;
        }

        try {
            await kamera;
        } catch (e) {
            /* bukaKamera sudah memberi tahu alasannya. */
            return;
        }

        pompa();
        progresSet(100, 'Siap');

        var g = await muatGaleri();
        progresSet(100, g.length + ' descriptor siswa dimuat');
        if (!g.length) {
            petunjuk.textContent = 'Galeri kosong, daftarkan wajah siswa dulu.';
        }

        hitungRekomendasi();
    }

    /* =================================================================
     |  Pemasangan
     * ================================================================= */

    el('tombol1').addEventListener('click', uji1);
    el('tombol2').addEventListener('click', uji2);
    el('tombol3').addEventListener('click', uji3);
    el('tombol4a').addEventListener('click', uji4A);
    el('tombol4b').addEventListener('click', uji4B);
    el('tombolReset4').addEventListener('click', reset4);
    el('tombolReset2').addEventListener('click', function () {
        sampel2 = [];
        gambarKotak2();
        perbaruiSebaran();
    });
    el('tombolEkspor').addEventListener('click', ekspor);
    el('tombolHapus').addEventListener('click', hapusSemua);

    window.addEventListener('beforeunload', function () {
        if (stream) {
            stream.getTracks().forEach(function (t) { t.stop(); });
        }
    });

    boot().catch(function (e) {
        petunjuk.textContent = 'Gagal memulai: ' + (e.message || e);
        siang('gagal', 'Gagal');
    });
})();
