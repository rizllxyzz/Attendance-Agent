/**
 * Perhitungan murni untuk halaman uji akurasi wajah.
 *
 * Dipisah dari DOM supaya bisa diuji tanpa kamera dan tanpa peramban.
 * Tidak menyentuh window, document, atau jaringan.
 */
(function (root) {
    'use strict';

    var D = 128;

    function jarak(a, b) {
        var s = 0;
        for (var i = 0; i < D; i++) {
            var d = a[i] - b[i];
            s += d * d;
        }
        return Math.sqrt(s);
    }

    function keVektor(x) {
        if (x instanceof Float32Array) return x;
        return new Float32Array(x);
    }

    function mean(a) {
        if (!a.length) return 0;
        var s = 0;
        for (var i = 0; i < a.length; i++) s += a[i];
        return s / a.length;
    }

    /* Simpangan sampel (n-1), bukan populasi: setiap tangkapan adalah
     * satu pengamatan baru, bukan bagian dari himpunan tetap. */
    function stdev(a) {
        if (a.length < 2) return 0;
        var m = mean(a);
        var s = 0;
        for (var i = 0; i < a.length; i++) s += (a[i] - m) * (a[i] - m);
        return Math.sqrt(s / (a.length - 1));
    }

    function nilaiMin(a) { return a.length ? Math.min.apply(null, a) : 0; }
    function nilaiMax(a) { return a.length ? Math.max.apply(null, a) : 0; }

    /* Interpolasi linier antar indeks, jadi p95 tidak melompat ke
     * nilai sampel yang kebetulan ada tepat di posisi itu. */
    function persentil(a, p) {
        if (!a.length) return 0;
        var urut = a.slice().sort(function (x, y) { return x - y; });
        var pos = (urut.length - 1) * p;
        var bawah = Math.floor(pos);
        var atas = Math.ceil(pos);
        if (bawah === atas) return urut[bawah];
        return urut[bawah] + (urut[atas] - urut[bawah]) * (pos - bawah);
    }

    /* Keyakinan dinormalkan terhadap ambang, bukan terhadap 1. Jarak
     * descriptor face-api tidak pernah mendekati 1, jadi membandingkan
     * langsung dengan 1 selalu menghasilkan angka yang tidak berguna. */
    function keyakinan(d, ambang) {
        if (d >= ambang) return 0;
        return 1 - (d / ambang);
    }

    function f(x, d) {
        if (x == null || isNaN(x)) return '-';
        return Number(x).toFixed(d == null ? 3 : d);
    }

    /* Jarak tiap sampel ke centroid, dan jarak terbesar antarpasang. */
    function sebaranSampel(samples) {
        if (!samples || samples.length < 2) return null;
        var c = new Float32Array(D);
        var i, j, k;
        for (i = 0; i < samples.length; i++) {
            var s = keVektor(samples[i].descriptor || samples[i]);
            for (j = 0; j < D; j++) c[j] += s[j];
        }
        for (j = 0; j < D; j++) c[j] /= samples.length;

        var keCentroid = [];
        for (i = 0; i < samples.length; i++) {
            keCentroid.push(jarak(keVektor(samples[i].descriptor || samples[i]), c));
        }

        var maks = 0;
        for (i = 0; i < samples.length; i++) {
            for (j = i + 1; j < samples.length; j++) {
                var d = jarak(
                    keVektor(samples[i].descriptor || samples[i]),
                    keVektor(samples[j].descriptor || samples[j])
                );
                if (d > maks) maks = d;
            }
        }
        return {
            jumlah: samples.length,
            centroid: c,
            sebaran: nilaiMax(keCentroid),
            maks: maks,
        };
    }

    /* Semua jarak silang A x B: inilah yang diukur server saat siswa
     * yang sama absen dihari berbeda. */
    function pasanganSilang(a, b) {
        var out = [];
        if (!a.length || !b.length) return out;
        for (var i = 0; i < a.length; i++) {
            for (var j = 0; j < b.length; j++) {
                out.push(jarak(keVektor(a[i]), keVektor(b[j])));
            }
        }
        return out;
    }

    /**
     * Angka ambang hasil pengukuran, bukan hasil tebakan.
     *
     * Yang dipakai bukan satu titik, tapi interval aman: semua ambang
     * antara batas bawah (p95 orang sama) dan batas atas (jarak orang
     * berbeda terdekat) akan mengklasifikasikan semua sampel yang
     * diamati dengan benar. Dari situ baru dilihat di mana ambang
     * sekarang berada, sehingga jawabannya langsung bisa dipakai:
     * sudah pas, terlalu longgar, atau terlalu ketat.
     *
     * @param {number[]} sama   jarak orang yang sama dengan dirinya
     * @param {number[]} beda   jarak orang berbeda ke galeri terdekat
     * @param {number} match     ambang match yang sekarang dipakai
     * @returns {Object} posisi, batasBawah, batasAtas, saran, ton
     */
    function saranAmbang(sama, beda, match) {
        var dasar = {
            cukup: false,
            posisi: 'belumTerukur',
            ton: 'kuning',
            saran: 0,
            p95: 0,
            maks: 0,
            min: 0,
            mean: 0,
            stdev: 0,
            batasBawah: 0,
            batasAtas: null,
            lebar: 0,
            jumlahSama: 0,
            jumlahBeda: 0,
            sumber: 'belum ada data',
        };

        if (!sama || !sama.length) return dasar;

        dasar.cukup = true;
        dasar.jumlahSama = sama.length;
        dasar.jumlahBeda = beda && beda.length ? beda.length : 0;
        dasar.p95 = persentil(sama, 0.95);
        dasar.maks = nilaiMax(sama);
        dasar.min = nilaiMin(sama);
        dasar.mean = mean(sama);
        dasar.stdev = stdev(sama);
        dasar.batasBawah = dasar.p95;

        if (!dasar.jumlahBeda) {
            /* Tanpa data orang berbeda, angka mana pun bisa diterima
             * dan tidak ada batas atas yang bisa dipercaya. */
            dasar.saran = dasar.p95 * 1.25;
            dasar.sumber = 'p95 orang sama (' + f(dasar.p95, 4) + ') dikali 1,25';
            return dasar;
        }

        dasar.batasAtas = nilaiMin(beda);
        dasar.lebar = dasar.batasAtas - dasar.batasBawah;
        dasar.saran = (dasar.batasBawah + dasar.batasAtas) / 2;
        dasar.sumber = 'di antara p95 orang sama (' + f(dasar.batasBawah, 4)
            + ') dan jarak orang berbeda terdekat (' + f(dasar.batasAtas, 4) + ')';

        if (dasar.lebar <= 0) {
            /* Orang berbeda yang paling dekat sudah sedekat p95 orang
             * sama. Tidak ada ambang yang memisahkan keduanya, dan itu
             * harus dilaporkan apa adanya. Menaikkan ambang supaya
             * kelihatan rapi hanya menukar penolakan salah menjadi
             * penerimaan salah. */
            dasar.posisi = 'tumpangTindih';
            dasar.ton = 'gagal';
            return dasar;
        }

        if (match >= dasar.batasAtas) {
            /* Ambang sekarang sudah diterima orang berbeda. */
            dasar.posisi = 'terlaluLonggar';
            dasar.ton = 'gagal';
        } else if (match < dasar.batasBawah) {
            /* Ambang sekarang sudah menolak orang yang sah. */
            dasar.posisi = 'terlaluKetat';
            dasar.ton = 'kuning';
        } else {
            dasar.posisi = 'dalam';
            dasar.ton = 'ok';
        }

        return dasar;
    }

    var M = {
        D: D,
        jarak: jarak,
        keVektor: keVektor,
        mean: mean,
        stdev: stdev,
        nilaiMin: nilaiMin,
        nilaiMax: nilaiMax,
        persentil: persentil,
        keyakinan: keyakinan,
        f: f,
        sebaranSampel: sebaranSampel,
        pasanganSilang: pasanganSilang,
        saranAmbang: saranAmbang,
    };

    root.UjiMath = M;

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = M;
    }
})(typeof window !== 'undefined' ? window : globalThis);
