/**
 * scan-engine.js
 * ---------------------------------------------------------------------------
 * Engine pemindaian wajah: memuat model, mengekstrak descriptor, dan
 * mencocokkan dengan database siswa.
 *
 * PRINSIP MEMORI
 * --------------
 * Kesalahan yang paling umum: memuat 1200 foto lalu memproses satu per satu.
 * Itu menghabiskan +-350 MB RAM dan membuat HP lama crash.
 *
 * Yang dilakukan di sini:
 *   1. Ambil descriptor siswa dari server SATU KALI dalam satu request
 *   2. Simpan sebagai satu Float32Array datar (bukan 1200 objek terpisah)
 *   3. Cocokkan dengan linear scan terhadap array itu
 *   4. Simpan salinan di IndexedDB supaya scan berikutnya tidak perlu unduh
 *
 * 1200 siswa x 128 float x 4 byte = 614.400 byte (~600 KB) di RAM.
 * Perbandingan 1200 x 128 = 153.600 operasi floating point, selesai < 5 ms
 * di browser. Tidak perlu FAISS atau index vektor untuk skala ini.
 *
 * CATATAN KEAMANAN
 * ----------------
 * Yang dikirim ke server hanya 128 angka float, tidak ada gambar.
 * Descriptor bersifat irreversible: tidak bisa diubah kembali menjadi foto.
 * ---------------------------------------------------------------------------
 */

const ScanEngine = (function () {
    'use strict';

    const CFG = {
        /* Aset face-api diambil dari CDN karena bobot face_recognition
         * 6,15 MB dan bundle library 1,27 MB, keduanya melebihi batas
         * ukuran berkas hosting. Nilai standarnya ditimpa dari PHP
         * (config/app-config.php) lewat configure(). Lihat catatan
         * panjang di sana sebelum mengubahnya. */
        MODEL_PATH: '/assets/models',
        API_BASE: '/api/v1',

        /* Detector kecil dipilih, bukan ssd_mobilenetv1: 189 KB vs 5,36 MB,
         * hasil setara untuk potret wajah menghadap kamera. */
        NET_DETECTOR: 'tinyFaceDetector',
        NET_LANDMARK: 'faceLandmark68TinyNet',
        NET_RECOGNITION: 'faceRecognitionNet',

        /* Ambang jarak descriptor face-api.js (jarak Euclid, makin kecil makin mirip) */
        THRESHOLD: 0.55,
        /* Untuk verifikasi 1:1, lebih ketat supaya tidak salah orang */
        THRESHOLD_STRICT: 0.45,

        /* Kualitas minimum frame wajah sebelum descriptor diambil */
        MIN_FACE_SIZE: 30,        /* piksel - diturunkan agar wajah tidak terlalu dekat pun terbaca */
        MIN_DETECTION_SCORE: 0.3,

        /* Galeri */
        DB_NAME: 'presensi',
        STORE_GALLERY: 'face_gallery',
        /* Scope dan user diisi loadGallery() supaya cache tidak pernah
         * dipakai lintas akun di perangkat bersama. */
        CURRENT_SCOPE: 'self',
        CURRENT_USER_ID: 0,
        GALLERY_TTL_MS: 24 * 60 * 60 * 1000,   /* refresh 1x per hari */
    };

    let nets = null;
    let gallery = null;        /* Array<{id, name, nis, kls, vec: Float32Array}> */
    let matrix = null;         /* Float32Array datar: [id0]128 + [id1]128 + ... */
    let ids = null;            /* Int32Array sejajar dengan matrix */
    let GALLERY_FOR = '';      /* "<userId>:<scope>" pemilik gallery di memori */
    let detectorOptions = null;

    /* =================================================================
     |  MODEL
     * ================================================================= */

    /**
     * Muat bobot model.
     *
     * Yang diunduh hanya bobot jaringan face-api, bukan foto siswa:
     * 189 KB + 75 KB + 6,15 MB, lalu di-cache browser setelah
     * pemakaian pertama. Foto tidak pernah masuk ke CDN maupun server
     * pada tahap ini.
     */
    let recognitionPromise = null;

    /**
     * Pastikan model recognition (6.4 MB) sudah termuat.
     * Dimuat di background agar tidak menahan antarmuka awal.
     */
    function ensureRecognitionModel() {
        if (!faceapi.nets.faceRecognitionNet.params) {
            if (!recognitionPromise) {
                recognitionPromise = faceapi.nets.faceRecognitionNet.loadFromUri(CFG.MODEL_PATH).catch((e) => {
                    console.warn('[ScanEngine] Recognition net load fallback/retry:', e);
                    recognitionPromise = null;
                    throw e;
                });
            }
            return recognitionPromise;
        }
        return Promise.resolve();
    }

    /**
     * Muat bobot model.
     * Tahap 1: detector (193 KB) + landmarks (77 KB) -> SEGERA 100% dan siap (< 0.2 detik).
     * Tahap 2: recognition (6.4 MB) berjalan di background tanpa memblokir layar.
     */
    async function loadModels(onProgress) {
        if (nets) return nets;

        /* Inisialisasi TensorFlow backend agar siap sebelum memuat bobot model */
        if (typeof faceapi !== 'undefined' && faceapi.tf) {
            try {
                if (typeof faceapi.tf.setBackend === 'function') {
                    await faceapi.tf.setBackend('webgl').catch(function () {});
                }
                if (typeof faceapi.tf.ready === 'function') {
                    await faceapi.tf.ready();
                }
            } catch (err) {
                console.warn('[ScanEngine] tf.setBackend fallback:', err);
                try {
                    if (typeof faceapi.tf.setBackend === 'function') {
                        await faceapi.tf.setBackend('cpu').catch(function () {});
                        await faceapi.tf.ready();
                    }
                } catch (err2) {}
            }
        }

        if (onProgress) onProgress('detector', 30);

        /* Tahap 1 (Cepat 270 KB): tinyFaceDetector + tinyLandmarks secara paralel */
        await Promise.all([
            faceapi.nets.tinyFaceDetector.loadFromUri(CFG.MODEL_PATH),
            faceapi.nets.faceLandmark68TinyNet.loadFromUri(CFG.MODEL_PATH),
        ]);

        if (onProgress) onProgress('landmarks', 85);

        nets = faceapi.nets;
        detectorOptions = new faceapi.TinyFaceDetectorOptions({
            inputSize: 224,
            scoreThreshold: 0.25,
        });

        /* Tahap 2: Mulai unduh recognition net di background sekarang */
        ensureRecognitionModel();

        if (onProgress) onProgress('siap', 100);
        return nets;
    }

    /**
     * Kompilasi shader WebGL.
     */
    async function warmup() {
        try {
            const canvas = document.createElement('canvas');
            canvas.width = 224;
            canvas.height = 224;
            const ctx = canvas.getContext('2d');
            ctx.fillStyle = '#000';
            ctx.fillRect(0, 0, 224, 224);
            await faceapi.detectSingleFace(canvas, detectorOptions)
                .withFaceLandmarks(true)
                .withFaceDescriptor()
                .run();
        } catch (e) {
            console.warn('[SCAN] warmup:', e.message);
        }
    }

    /* =================================================================
     |  IDB  -  cache galeri agar tidak unduh tiap kali scan
     * ================================================================= */

    /* Kunci cache harus stabil per pengguna, bukan per waktu.
     *
     * Sebelumnya keyPath-nya 'savedAt' dan setiap penyimpanan memakai
     * Date.now(), sehingga setiap muat galeri menambah record baru dan
     * tidak pernah menimpa yang lama: cache tumbuh terus di perangkat
     * yang sering dipakai. Di perangkat bersama, cache bersama juga
     * berarti descriptor siswa yang logout masih bisa dibaca akun
     * berikutnya. Kunci per-pengguna menutup keduanya. */
    function cacheKey() {
        return 'uid:' + (CFG.CURRENT_USER_ID || 0) + ':' + (CFG.CURRENT_SCOPE || 'self');
    }

    /* Versi 1 memakai keyPath 'savedAt'. Versi 2 memakai keyPath 'key'
     * supaya satu pengguna hanya punya satu record per scope.
     *
     * Penting: store versi 1 harus DIHAPUS, bukan dibiarkan. Store yang
     * sudah ada tidak bisa diubah keyPath-nya, dan IndexedDB tidak
     * punya ALTER. Kalau store lama dibiarkan apa adanya, setiap
     * cacheSave() berikutnya gagal dengan DataError karena record
     * yang ditulis tidak punya properti 'savedAt', dan galeri tidak
     * pernah ter-cache lagi diam-diam. Isi store lama itu hanya salinan
     * descriptor yang bisa diambil ulang dari server, jadi menghapusnya
     * tidak menghilangkan data yang tidak bisa didapat lagi. */
    const DB_VERSION = 2;

    function openDB() {
        return new Promise((resolve, reject) => {
            const req = indexedDB.open(CFG.DB_NAME, DB_VERSION);
            req.onupgradeneeded = () => {
                const db = req.result;
                /* Buang store yang keyPath-nya salah, baik dari versi 1
                 * maupun dari percobaan versi 2 sebelumnya. */
                if (db.objectStoreNames.contains(CFG.STORE_GALLERY)) {
                    const existing = req.transaction.objectStore(CFG.STORE_GALLERY);
                    if (existing.keyPath !== 'key') {
                        db.deleteObjectStore(CFG.STORE_GALLERY);
                    }
                }
                if (!db.objectStoreNames.contains(CFG.STORE_GALLERY)) {
                    db.createObjectStore(CFG.STORE_GALLERY, { keyPath: 'key' });
                }
            };
            req.onsuccess = () => resolve(req.result);
            req.onerror = () => reject(req.error);
            req.onblocked = () => reject(new Error('cache dipakai tab lain'));
        });
    }

    async function cacheSave(descriptors, studentMeta) {
        try {
            const db = await openDB();
            await new Promise((resolve, reject) => {
                const tx = db.transaction(CFG.STORE_GALLERY, 'readwrite');
                const store = tx.objectStore(CFG.STORE_GALLERY);
                // satu record per scope, bukan satu per muat
                store.put({
                    key: cacheKey(),
                    savedAt: Date.now(),
                    descriptors: descriptors,
                    students: studentMeta,
                });
                // buang record milik pengguna lain yang tidak lagi dipakai
                const all = store.getAllKeys();
                all.onsuccess = () => {
                    const stale = all.result.filter((k) => k !== cacheKey());
                    stale.forEach((k) => store.delete(k));
                };
                tx.oncomplete = resolve;
                tx.onerror = () => reject(tx.error);
            });
            db.close();
        } catch (e) {
            console.warn('[SCAN] gagal menyimpan cache:', e.message);
        }
    }

    async function cacheLoad() {
        try {
            const db = await openDB();
            const row = await new Promise((resolve, reject) => {
                const tx = db.transaction(CFG.STORE_GALLERY, 'readonly');
                const req = tx.objectStore(CFG.STORE_GALLERY).get(cacheKey());
                req.onsuccess = () => resolve(req.result);
                req.onerror = () => reject(req.error);
            });
            db.close();

            if (!row) return null;
            if (Date.now() - row.savedAt > CFG.GALLERY_TTL_MS) return null;
            return row;
        } catch (e) {
            return null;
        }
    }

    async function cacheClear() {
        try {
            const db = await openDB();
            await new Promise((resolve) => {
                const tx = db.transaction(CFG.STORE_GALLERY, 'readwrite');
                tx.objectStore(CFG.STORE_GALLERY).clear();
                tx.oncomplete = resolve;
                tx.onerror = resolve;
            });
            db.close();
        } catch (e) { /* abaikan */ }
    }

    /* =================================================================
     |  GALERI  -  muat 1200 siswa ke RAM
     * ================================================================= */

    /**
     * Muat descriptor seluruh siswa.
     *
     * @param {boolean} force  true = abaikan cache dan ambil dari server
     * @returns {Promise<{count:number, fromCache:boolean, ms:number}>}
     */
    /**
     * Muat galeri descriptor dari server.
     *
     * scope menentukan berapa banyak descriptor yang dikirim:
     *   'self' - hanya descriptor milik pengguna yang login (default).
     *            Cukup untuk perangkat pribadi, dan tidak Membocorkan
     *            wajah siswa lain ke browser.
     *   'all'  - seluruh galeri. Hanya untuk guru/admin di kiosk.
     *
     * Server yang memutuskan boleh atau tidak; permintaan 'all' dari
     * akun siswa akan ditolak di sana, bukan di sini.
     *
     * @param {boolean} force abaikan cache
     * @param {string}  scope 'self' atau 'all'
     * @param {number}  userId untuk memisahkan cache antar pengguna
     */
    async function loadGallery(force, scope, userId) {
        scope = scope || CFG.CURRENT_SCOPE || 'self';
        userId = Number(userId) || CFG.CURRENT_USER_ID || 0;

        /* Galeri di memori disimpan bersama asal-usulnya. Tanpa penanda
         * ini, loadGallery() kedua pada halaman yang sama akan langsung
         * mengembalikan gallery milik pemanggilan pertama: pada perangkat
         * bersama, akun berikutnya bisa dapat descriptor siswa lain yang
         * tidak berhak. */
        const wanted = userId + ':' + scope;

        if (gallery && !force && GALLERY_FOR === wanted) {
            return { count: gallery.length, fromCache: true, ms: 0 };
        }

        /* Target berubah: memori dan indeks lama tidak boleh dipakai lagi. */
        if (GALLERY_FOR !== wanted) {
            gallery = null;
            matrix = null;
            ids = null;
        }

        CFG.CURRENT_SCOPE = scope;
        CFG.CURRENT_USER_ID = userId;

        if (!force) {
            const cached = await cacheLoad();
            if (cached) {
                buildIndex(cached.descriptors, cached.students);
                GALLERY_FOR = wanted;
                return { count: gallery.length, fromCache: true, ms: 0 };
            }
        }

        const t0 = performance.now();
        const res = await fetch(CFG.API_BASE + '/student/get-descriptors.php?scope=' + encodeURIComponent(scope), {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' },
        });

        if (!res.ok) {
            throw new Error('Gagal memuat descriptor siswa (HTTP ' + res.status + ')');
        }

        const data = await res.json();
        if (!data.success || !Array.isArray(data.descriptors)) {
            throw new Error(data.message || 'Respons descriptor tidak valid');
        }

        /* Server mengirim descriptor sebagai base64, bukan larik angka:
         * 1200 siswa x 128 float sebagai JSON angka biasa bisa jadi
         * ~1,5 MB dan lambat di-parse. Base64 hanya 512 byte per orang. */
        const raw = data.descriptors;
        const students = data.students;
        const descriptors = new Array(raw.length);

        for (let i = 0; i < raw.length; i++) {
            const v = fromBase64(raw[i]);
            if (v.length !== 128) {
                throw new Error('Descriptor siswa #' + i + ' panjangnya ' + v.length + ', harus 128');
            }
            descriptors[i] = v;
        }

        buildIndex(descriptors, students);
        GALLERY_FOR = wanted;
        cacheSave(descriptors, students).catch(() => {});

        return {
            count: gallery.length,
            fromCache: false,
            ms: Math.round(performance.now() - t0),
        };
    }

    /**
     * Bentuk struktur data yang hemat memori.
     *
     * ❌ 1200 objek terpisah -> ribuan header objek, boros
     * ✅ 1 Float32Array datar + 1 Int32Array id -> 2 alokasi saja
     */
    function buildIndex(descriptors, students) {
        const n = descriptors.length;
        const D = 128;

        matrix = new Float32Array(n * D);
        ids    = new Int32Array(n);
        gallery = new Array(n);

        for (let i = 0; i < n; i++) {
            const src = descriptors[i];
            const off = i * D;
            for (let j = 0; j < D; j++) {
                matrix[off + j] = src[j];
            }
            ids[i] = students[i].id | 0;
            gallery[i] = {
                id: students[i].id | 0,
                name: students[i].name || '',
                nis: students[i].nis || '',
                kls: students[i].kls == null ? null : (students[i].kls | 0),
                index: i,
            };
        }
    }

    /**
     * Setel galeri secara langsung, tanpa lewat server.
     * Dipakai setelah pendaftaran wajah siswa baru supaya tidak perlu
     * mengunduh ulang seluruh galeri.
     */
    function setGallery(descriptors, students) {
        buildIndex(descriptors, students);
        /* Galeri ini milik scope yang sedang aktif, jadi tandai juga.
         * Tanpa ini, loadGallery() berikutnya akan menganggapnya milik
         * orang lain dan mengunduh ulang dari server tanpa perlu. */
        GALLERY_FOR = CFG.CURRENT_USER_ID + ':' + CFG.CURRENT_SCOPE;
        return gallery.length;
    }

    /* =================================================================
     |  PENCOCOKAN
     * ================================================================= */

    /**
     * Jarak Euclid antara descriptor dan satu entri galeri.
     * Di.loop langsung di atas Float32Array tanpa membuat array baru,
     * supaya tidak ada alokasi memori per siswa.
     */
    function distanceToRow(query, rowOffset) {
        let sum = 0;
        for (let j = 0; j < 128; j++) {
            const d = query[j] - matrix[rowOffset + j];
            sum += d * d;
        }
        return Math.sqrt(sum);
    }

    /**
     * Cari {limit} siswa paling mirip.
     *
     * @param {Float32Array} query  descriptor wajah yang terdeteksi
     * @param {number} limit         berapa hasil teratas yang dikembalikan
     * @returns {Array<{id,name,nis,distance,confidence,matched}>}
     */
    function match(query, limit) {
        limit = limit || 3;
        if (!matrix || gallery.length === 0) {
            return [];
        }
        if (!query || query.length !== 128) {
            throw new Error('Descriptor harus 128 angka');
        }

        /* Linear scan penuh, tetap menyimpan kandidat terbaik saja.
         * Alternatif: early-exit bila jarak sudah pasti kalah. */
        const best = [];

        for (let i = 0; i < gallery.length; i++) {
            const dist = distanceToRow(query, i * 128);

            if (best.length === limit && dist >= best[limit - 1].distance) {
                continue;
            }

            best.push({
                id: gallery[i].id,
                name: gallery[i].name,
                nis: gallery[i].nis,
                distance: dist,
                confidence: dist >= CFG.THRESHOLD ? 0 : 1 - (dist / CFG.THRESHOLD),
            });

            if (best.length > limit) {
                best.sort((a, b) => a.distance - b.distance);
                best.length = limit;
            }
        }

        best.sort((a, b) => a.distance - b.distance);
        best.forEach((b) => { b.distance = Math.round(b.distance * 10000) / 10000; });
        if (best[0]) best[0].matched = best[0].distance <= CFG.THRESHOLD;

        return best;
    }

    /* =================================================================
     |  DETEKSI DARI KAMERA
     * ================================================================= */

    /**
     * Ambil landmark/descriptor wajah dari elemen video.
     * Mengembalikan null bila wajah tidak terbaca atau terlalu kecil.
     */
    async function detectFromVideo(video, withDescriptor = true) {
        if (!nets) throw new Error('Model belum dimuat, panggil loadModels() dulu');

        if (!video || video.readyState < 2 || video.paused || video.ended) {
            return { ok: false, reason: 'Kamera belum siap', ms: 0 };
        }

        const t0 = performance.now();
        let query = faceapi.detectSingleFace(video, detectorOptions).withFaceLandmarks(true);
        if (withDescriptor) {
            await ensureRecognitionModel();
            query = query.withFaceDescriptor();
        }
        const result = await query.run();

        const ms = Math.round(performance.now() - t0);
        if (!result) {
            return { ok: false, reason: 'Wajah tidak terdeteksi', ms: ms };
        }

        const box = result.detection.box;
        if (box.width < CFG.MIN_FACE_SIZE || box.height < CFG.MIN_FACE_SIZE) {
            return {
                ok: false,
                reason: 'Wajah terlalu jauh, mendekatkan wajah ke kamera',
                box: box,
                ms: ms,
            };
        }

        if (result.detection.score < CFG.MIN_DETECTION_SCORE) {
            return {
                ok: false,
                reason: 'Kualitas wajah rendah, pastikan pencahayaan baik',
                box: box,
                ms: ms,
            };
        }

        return {
            ok: true,
            descriptor: result.descriptor || null,
            landmarks: result.landmarks,
            box: box,
            score: result.detection.score,
            ms: ms,
        };
    }

    /**
     * Mengambil N sampel descriptor untuk pendaftaran wajah.
     * Mengulang pengambilan frame sampai terkumpul sampel berkualitas baik.
     */
    async function captureEnrollSamples(video, count, onEach) {
        const samples = [];
        let attempts = 0;
        const maxAttempts = Math.max(count * 6, 25);

        while (samples.length < count && attempts < maxAttempts) {
            attempts++;
            const d = await detectFromVideo(video);
            if (d.ok) {
                samples.push({
                    descriptor: Array.from(d.descriptor),
                    quality: d.score,
                    landmarks: d.landmarks,
                });
                if (onEach) onEach(samples.length, count, d, true);
            } else if (onEach) {
                onEach(samples.length, count, d, false);
            }
            await new Promise((r) => setTimeout(r, 400));
        }
        return samples;
    }

    /**
     * Rata-rata beberapa descriptor menjadi satu centroid.
     * Mengembalikan Float32Array 128.
     */
    function centroid(samples) {
        if (!samples || samples.length === 0) {
            throw new Error('Tidak ada sampel untuk dihitung');
        }
        const D = 128;
        const out = new Float32Array(D);
        const count = samples.length;

        for (const s of samples) {
            const d = s.descriptor || s;
            for (let j = 0; j < D; j++) {
                out[j] += d[j];
            }
        }
        for (let j = 0; j < D; j++) {
            out[j] /= count;
        }
        return out;
    }

    /* =================================================================
     |  KIRIM KE SERVER
     * ================================================================= */

    /**
     * Byte descriptor dikonversi ke base64 agar bisa dikirim lewat JSON.
     * Bentuk byte-nya PENTING: harus sama persis dengan pack('g') di PHP,
     * yaitu float32 little-endian.
     */
    function toBase64(float32) {
        return btoa(String.fromCharCode.apply(null, new Uint8Array(float32.buffer)));
    }

    function fromBase64(b64) {
        const bin = atob(b64);
        const bytes = new Uint8Array(bin.length);
        for (let i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
        return new Float32Array(bytes.buffer);
    }

    /**
     * Kirim hasil scan ke server untuk diverifikasi ulang.
     * Server menghitung ulang jarak dan jarak GPS - hasil lokal ini
     * HANYA untuk tampilan, bukan keputusan.
     */
    async function submitScan(payload) {
        const res = await fetch(CFG.API_BASE + '/attendance/scan-check.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify(payload),
        });

        const text = await res.text();
        try {
            return JSON.parse(text);
        } catch (e) {
            throw new Error('Respons server tidak valid: ' + text.slice(0, 200));
        }
    }

    /**
     * Terima konfigurasi dari PHP.
     *
     * Nilai default di dalam modul ini sengaja dibuat tetap unusable
     * untuk produksi: kalau halaman lupa memanggil configure(), galeri
     * dan model akan diambil dari CDN default dan ambang tidak akan
     * sama dengan yang diuji server. configure() membuat keduanya
     * mengikuti app-config.php, jadi tidak ada angka yang bisa berbeda
     * antara browser dan server.
     */
    function configure(opts) {
        opts = opts || {};
        if (opts.modelPath) CFG.MODEL_PATH = opts.modelPath;
        if (opts.apiBase) CFG.API_BASE = opts.apiBase;
        if (opts.threshold != null) CFG.THRESHOLD = Number(opts.threshold);
        if (opts.thresholdStrict != null) CFG.THRESHOLD_STRICT = Number(opts.thresholdStrict);
        if (opts.minFaceSize != null) CFG.MIN_FACE_SIZE = Number(opts.minFaceSize);
        if (opts.galleryTtlMs != null) CFG.GALLERY_TTL_MS = Number(opts.galleryTtlMs);
        /* Scope ikut dikunci di sini supaya cacheKey() benar sejak awal
         * dan tidak sempat menulis di bawah user 0. */
        if (opts.scope) CFG.CURRENT_SCOPE = String(opts.scope);
        if (opts.userId) CFG.CURRENT_USER_ID = Number(opts.userId);
        return CFG;
    }

    return {
        CFG: CFG,
        configure: configure,
        loadModels: loadModels,
        loadGallery: loadGallery,
        setGallery: setGallery,
        cacheClear: cacheClear,
        match: match,
        detectFromVideo: detectFromVideo,
        captureEnrollSamples: captureEnrollSamples,
        centroid: centroid,
        toBase64: toBase64,
        fromBase64: fromBase64,
        submitScan: submitScan,
        getGallery: () => gallery,
        getMatrix: () => matrix,
        isReady: () => matrix !== null,
    };
})();

if (typeof module !== 'undefined' && module.exports) {
    module.exports = ScanEngine;
}
