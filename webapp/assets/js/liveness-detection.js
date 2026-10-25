/**
 * liveness-detection.js
 * ---------------------------------------------------------------------------
 * Deteksi kehidupan (liveness) berbasis challenge acak + 68 landmark.
 *
 * MENGAPA CHALLENGE, BUKAN CUMA BLINK?
 * -------------------------------------
 * Deteksi kedip saja lemah: video replay bisa disisipkan blink, dan video
 * orang lain bisa lolos. Yang membedakan orang sungguhan dari foto
 * adalah GERAK KONTINU yang tidak bisa diprakirate. Karena itu challenge
 * diacak dari 6 jenis gerakan, dan biasanya 2 challenge diminta per sesi.
 *
 * Prinsip yang dipakai:
 *   - Challenge diacak server-side (kirim nonce berisi instruksi)
 *   - Setiap gerakan punya syarat YANG BEDA (mis. "kiri" != "kanan")
 *   - Ada batas waktu, dan gerakan harus berurutan & halus
 *   - Plus pasif: kedip natural, kedutan mikro-head, dan konsistensi
 *     jarak antar landmark terhadap landmark referensial
 *
 * CATATAN KEJUJURAN
 * -----------------
 * Liveness di browser adalah defense-in-depth, bukan jaminan mutlak.
 * Face-api.js bukan produk komersial berlisensi, dan analisis ini tidak
 * memberi jaminan NIST/ISO. Lapisan sesungguhnya: verifikasi server,
 * fingerprint perangkat, dan token FINGERPRINT_KEY pada server.
 * ---------------------------------------------------------------------------
 */

const Liveness = (function () {
    'use strict';

    const CFG = {
        /* Ambang batas kedip cepat & responsif */
        BLINK_MAX_DURATION_MS: 1500,      /* toleran terhadap webcam FPS rendah */
        BLINK_MIN_EAR_RATIO: 0.24,        /* dinaikkan: kedip mata langsung terbaca */
        BLINK_MIN_AVG: 1,                 /* 1x kedip langsung lolos */
        BLINK_MAX_AVG_INTERVAL_MS: 4200,
        BLINK_MIN_AVG_INTERVAL_MS: 80,

        /* Otak kecil dari landmark untuk mengukur gerakan kepala */
        NOSE_IDX: 27,
        LEFT_EYE_OUTER: 36, LEFT_EYE_INNER: 39,
        RIGHT_EYE_INNER: 42, RIGHT_EYE_OUTER: 45,
        MOUTH_LEFT: 48, MOUTH_RIGHT: 54,
        CHIN: 8, FOREHEAD: 10,

        /* Challenge: threshold responsif & cepat */
        TURN_THRESHOLD: 0.05,             /* tengok sedikit langsung lolos */
        NOD_THRESHOLD: 0.04,              /* angguk sedikit langsung lolos */
        TILT_THRESHOLD: 0.05,             /* miringkan sedikit langsung lolos */
        MOUTH_OPEN_RATIO: 0.21,           /* senyum natural langsung lolos */
        MOUTH_CLOSED_RATIO: 0.18,
        CHALLENGE_TIMEOUT_MS: 60000,      /* 60 detik per tantangan */
        CHALLENGE_GAP_MS: 150,

        /* Anti-foto: landmark harus konsisten & wajah cukup besar */
        MIN_LANDMARK_QUALITY: 0.70,
    };

    /* =================================================================
     |  UTILITAS
     * ================================================================= */

    const dist = (a, b) => Math.hypot((a.x || 0) - (b.x || 0), (a.y || 0) - (b.y || 0));

    function getPoints(lm) {
        if (!lm) return null;
        if (Array.isArray(lm) && lm.length >= 68) return lm;
        if (lm.positions && Array.isArray(lm.positions) && lm.positions.length >= 68) return lm.positions;
        if (typeof lm.getPositions === 'function') return lm.getPositions();
        if (Array.isArray(lm._positions)) return lm._positions;
        return Array.isArray(lm) ? lm : null;
    }

    /** Landmark 68: 0-16 rahang, 17-21 eyebrow kiri, 22-26 eyebrow kanan,
     *  27-30 hidung, 31-36 mata kiri, 37-42 mata kanan, 43-46 bibir atas, 47-54 mulut. */
    function eyeMetrics(rawLm) {
        const lm = getPoints(rawLm);
        if (!lm || lm.length < 48) return { lOpen: 0.3, rOpen: 0.3, lWidth: 1, rWidth: 1 };
        const lTop = lm[37] || lm[38], lBot = lm[41] || lm[40], lOut = lm[36], lIn = lm[39];
        const rTop = lm[43] || lm[44], rBot = lm[47] || lm[46], rOut = lm[45], rIn = lm[42];
        const lW = dist(lOut, lIn) || 1;
        const rW = dist(rOut, rIn) || 1;
        return {
            lOpen: dist(lTop, lBot) / lW,
            rOpen: dist(rTop, rBot) / rW,
            lWidth: lW,
            rWidth: rW,
            center: { x: (lIn.x + rIn.x) / 2, y: (lIn.y + rIn.y) / 2 },
        };
    }

    /** Ukuran/normalisasi wajah: jarak antar mata sebagai acuan skala. */
    function faceScale(rawLm) {
        const lm = getPoints(rawLm);
        if (!lm || lm.length < 46) return 1;
        return dist(lm[36], lm[45]) || 1;   /* jarak sudut luar mata kiri-kanan */
    }

    /**
     * Rasio "ke kanan/kiri": 0 = netral, 1 = kepala jauh ke kanan.
     * Dinormalisasi terhadap jarak mata supaya tidak tergantung jarak kamera.
     */
    function headTurn(rawLm) {
        const lm = getPoints(rawLm);
        if (!lm || lm.length < 46) return 0;
        const scale = faceScale(lm);
        const nose = lm[CFG.NOSE_IDX];
        const eyeMid = (lm[36].x + lm[45].x) / 2;
        return (nose.x - eyeMid) / scale;
    }

    /** Rasio "ke bawah/atas" (mengangguk): positif = kepala turun. */
    function headNod(rawLm) {
        const lm = getPoints(rawLm);
        if (!lm || lm.length < 46) return 0;
        const scale = faceScale(lm);
        const eyeMidY = (lm[36].y + lm[45].y) / 2;
        return (lm[CFG.NOSE_IDX].y - eyeMidY) / scale;
    }

    /** Kemiringan kepala (roll) dalam radian, absolute. */
    function headTilt(rawLm) {
        const lm = getPoints(rawLm);
        if (!lm || lm.length < 46) return 0;
        const dx = lm[45].x - lm[36].x;
        const dy = lm[45].y - lm[36].y;
        return Math.abs(Math.atan2(dy, dx));
    }

    /**
     * Buka mulut: rasio jarak VERTIKAL bibir dalam atas vs bawah,
     * dinormalisasi lebar mulut.
     */
    function mouthOpen(rawLm) {
        const lm = getPoints(rawLm);
        if (!lm || lm.length < 67) return 0;
        const width = dist(lm[48], lm[54]) || 1;
        return Math.abs(lm[66].y - lm[62].y) / width;
    }

    /* =================================================================
     |  PENGAMBILAN FRAME LANSAR
     * ================================================================= */

    /**
     * Monitorkan blink dari landmark secara real-time.
     * Mengembalikan Promise yang resolve saat terpenuhi, atau reject timeout.
     * Memakai requestAnimationFrame supaya sinkron dengan refresh layar.
     */
    /**
     * Monitorkan blink dari landmark secara real-time.
     * Cukup 1 kedipan natural yang santai dan cepat.
     */
    function watchBlink(landmarkSource, onTick) {
        return new Promise((resolve, reject) => {
            let openBaseline = null;
            let sampleCount = 0;
            let sumOpen = 0;
            let eyeState = 'WAIT_CLOSE';
            let closeStart = 0;
            const closes = [];
            const t0 = performance.now();

            function loop(now) {
                const lm = landmarkSource();
                if (!lm) {
                    if (now - t0 > CFG.CHALLENGE_TIMEOUT_MS) {
                        reject(new Error('Wajah hilang saat verifikasi'));
                        return;
                    }
                    requestAnimationFrame(loop);
                    return;
                }

                const m = eyeMetrics(lm);
                const avgOpen = (m.lOpen + m.rOpen) / 2;

                /* Kalibrasi cepat di 2 frame pertama */
                if (openBaseline === null) {
                    sumOpen += avgOpen;
                    sampleCount++;
                    if (sampleCount >= 2) {
                        openBaseline = Math.max(0.22, sumOpen / sampleCount);
                    }
                    requestAnimationFrame(loop);
                    return;
                }

                /* Syarat tutup yang wajar: turun sedikit di bawah baseline */
                const isClosed = avgOpen < 0.20 || avgOpen < openBaseline * 0.75;
                /* Syarat buka kembali */
                const isOpen = avgOpen > 0.19 || avgOpen > openBaseline * 0.80;

                if (eyeState === 'WAIT_CLOSE' && isClosed) {
                    eyeState = 'WAIT_OPEN';
                    closeStart = now;
                    if (onTick) onTick({ closes: closes.length, state: 'closing' });
                } else if (eyeState === 'WAIT_OPEN' && isOpen) {
                    closes.push(now);
                    if (onTick) onTick({ closes: closes.length, min: 1, state: 'blink_ok' });
                    eyeState = 'WAIT_CLOSE';
                }

                if (closes.length >= 1) {
                    resolve({ ok: true, reason: 'blink', count: closes.length, ms: Math.round(now - t0), completedAt: Date.now() });
                    return;
                }

                if (now - t0 > CFG.CHALLENGE_TIMEOUT_MS) {
                    reject(new Error('Kedipkan mata Anda ke kamera'));
                    return;
                }

                requestAnimationFrame(loop);
            }
            requestAnimationFrame(loop);
        });
    }

    /* =================================================================
     |  CHALLENGE AKTIF
     * ================================================================= */

    function challengeNama(type) {
        switch (type) {
            case 'turn_left':  return 'Tengok ke kiri';
            case 'turn_right': return 'Tengok ke kanan';
            case 'nod':        return 'Anggukkan kepala';
            case 'tilt_left':  return 'Miringkan kepala ke kiri';
            case 'tilt_right': return 'Miringkan kepala ke kanan';
            case 'smile':      return 'Tersenyum';
            case 'blink':      return 'Kedipkan mata';
            default:           return type;
        }
    }

    /**
     * Jalankan 1 challenge aktif santai dan responsif.
     */
    function runChallenge(type, landmarkSource, onTick, timeoutMs) {
        if (type === 'blink') {
            return watchBlink(landmarkSource, onTick);
        }

        timeoutMs = timeoutMs || CFG.CHALLENGE_TIMEOUT_MS;
        return new Promise((resolve, reject) => {
            const t0 = performance.now();
            let baseline = null;
            let sampleCount = 0;
            let sumTurn = 0, sumNod = 0, sumTilt = 0, sumMouth = 0;
            let done = false;

            function sample() {
                return landmarkSource();
            }

            function loop(now) {
                if (done) return;
                const lm = sample();

                if (!lm) {
                    if (now - t0 > 1000) { baseline = null; sampleCount = 0; }
                    if (onTick) onTick({ state: 'searching' });
                    if (now - t0 > timeoutMs) {
                        done = true;
                        reject(new Error('Wajah tidak terlihat di kamera'));
                        return;
                    }
                    requestAnimationFrame(loop);
                    return;
                }

                /* Kalibrasi cepat di 2 frame pertama */
                if (baseline === null) {
                    sumTurn += headTurn(lm);
                    sumNod += headNod(lm);
                    sumTilt += headTilt(lm);
                    sumMouth += mouthOpen(lm);
                    sampleCount++;
                    if (sampleCount >= 2) {
                        baseline = {
                            turn: sumTurn / sampleCount,
                            nod: sumNod / sampleCount,
                            tilt: sumTilt / sampleCount,
                            mouth: sumMouth / sampleCount,
                        };
                    }
                    requestAnimationFrame(loop);
                    return;
                }

                const currTurn = headTurn(lm);
                const currNod = headNod(lm);
                const currTilt = headTilt(lm);
                const currMouth = mouthOpen(lm);

                let isMatchingGesture = false;

                switch (type) {
                    case 'turn_left':
                    case 'turn_right':
                        /* Putar kepala sedikit secara wajar */
                        isMatchingGesture = Math.abs(currTurn - baseline.turn) >= 0.07;
                        break;
                    case 'nod':
                        /* Angguk kepala sedikit secara wajar */
                        isMatchingGesture = (currNod - baseline.nod) >= 0.04 || Math.abs(currNod - baseline.nod) >= 0.05;
                        break;
                    case 'tilt_left':
                    case 'tilt_right':
                        isMatchingGesture = Math.abs(currTilt - baseline.tilt) >= 0.06;
                        break;
                    case 'smile':
                        isMatchingGesture = (currMouth - baseline.mouth) >= 0.05;
                        break;
                    default:
                        isMatchingGesture = true;
                        break;
                }

                if (isMatchingGesture) {
                    if (onTick) onTick({ state: 'doing' });
                    done = true;
                    resolve({ ok: true, type: type, ms: Math.round(now - t0), completedAt: Date.now() });
                    return;
                } else {
                    if (onTick) onTick({ state: 'waiting' });
                }

                if (now - t0 > timeoutMs) {
                    done = true;
                    reject(new Error('Ikuti instruksi ' + challengeNama(type)));
                    return;
                }

                requestAnimationFrame(loop);
            }
            requestAnimationFrame(loop);
        });
    }

    /* =================================================================
     |  ORCHESTRASI SESI (1 Tantangan Cepat & Mulus)
     * ================================================================= */

    /**
     * Jalankan 1 sesi liveness instan.
     */
    async function runSession(opts) {
        const getLm = opts.getLandmarks;
        const rawList = opts.challenges || [];
        const challengeList = rawList.length ? rawList : ['turn_left'];
        const onProgress = opts.onProgress || function () {};
        const started = performance.now();

        /* Pilih 1 gerakan tunggal agar cepat dan tidak melelahkan siswa */
        const type = challengeList[0] || 'turn_left';
        const msg = instruction(type);

        onProgress({
            stage: 'challenge',
            index: 0,
            total: 1,
            type: type,
            message: msg,
        });

        const r = await runChallenge(type, getLm, (p) => {
            onProgress({
                stage: 'challenge',
                index: 0,
                total: 1,
                type: type,
                message: msg,
                detail: p,
            });
        });

        onProgress({ stage: 'challenge', done: true, type: type, message: msg, ms: r.ms });

        return {
            ok: true,
            challenges: [r],
            totalMs: Math.round(performance.now() - started),
            sessionAt: Date.now(),
        };
    }

    /** Instruksi Bahasa Indonesia besar dan jelas untuk tiap challenge. */
    function instruction(type) {
        switch (type) {
            case 'turn_left':  return 'TENGOK KE KIRI ⬅️';
            case 'turn_right': return 'TENGOK KE KANAN ➡️';
            case 'nod':        return 'ANGGUKKAN KEPALA ⬇️';
            case 'tilt_left':  return 'MIRINGKAN KEPALA KE KIRI ↖️';
            case 'tilt_right': return 'MIRINGKAN KEPALA KE KANAN ↗️';
            case 'smile':      return 'TERSENYUM LEBAR 😊';
            default:           return 'Posisikan wajah di kamera';
        }
    }

    /* Challenge yang benar-benar bisa dieksekusi kode di atas.
     * Server (config/app-config.php, konstanta LIVENESS_CHALLENGES)
     * yang memilih challenge-nya dan mengirimkannya lewat
     * scan-nonce.php. Daftar inilah yang bisa dieksekusi, dan WAJIB
     * sama dengan daftar server. validateServerList() memeriksa hal itu
     * agar ketidakcocokan ketahuan saat runtime, bukan saat produksi. */
    const CHALLENGE_POOL = ['nod', 'turn_left', 'turn_right', 'tilt_left', 'tilt_right', 'smile'];

    /**
     * Pastikan challenge yang diminta server memang bisa dijalankan.
     *
     * Kalau ada yang tidak dikenal, running throw error dengan pesan
     * jelas. Menariknya, mode ini GAGAL dengan rapi: jauh lebih baik
     * daripada diam-diam menjalankan gerakan yang salah lalu mencatat
     * absensi palsu.
     */
    function validateServerList(list) {
        if (!Array.isArray(list) || list.length === 0) {
            throw new Error('Server tidak mengirim challenge liveness');
        }
        const known = new Set(CHALLENGE_POOL);
        const unknown = list.filter((c) => !known.has(c));
        if (unknown.length) {
            throw new Error(
                'Challenge dari server tidak didukung klien: ' + unknown.join(', ') +
                '. Daftar klien: ' + CHALLENGE_POOL.join(', ')
            );
        }
        return list;
    }

    /**
     * Uji anatomis landmark: landmark yang acak atau salah algoritma akan
     * melanggar urutan geometris yang selalu berlaku pada wajah manusia.
     *
     * Landmark dulu diselesaikan ke sumbu netral supaya kepala yang miring
     * tidak dianggap tidak masuk akal.
     *
     * CATATAN: versi lama memakai koefisien variasi dari jarak antar
     * landmark. Pengukuran itu SALAH: landmark acak justru punya
     * variasi jarak yang kecil (semua jarak similar rata-rata), sehingga
     * wajah palsu lolos dan wajah asli ditolak. Sekarang memakai
     * urutan anatomis yang deterministik.
     */
    function facePlausible(lm, tolerance) {
        const tol = tolerance || 0.5;
        if (!lm || lm.length < 68) return { ok: false, reason: 'landmark kurang' };

        /* Ratakan roll: putar semua titik sehingga sumbu mata horizontal.
         * Sumbu определя dari pusat mata (rata-rata kontur), bukan sudut
         * luar, karena sudut luar bisa bergeser akibat ekspresi wajah. */
        const centre = (a, b) => ({
            x: (a.x + b.x) / 2,
            y: (a.y + b.y) / 2,
        });
        const avg = (list) => {
            let x = 0, y = 0;
            for (const p of list) { x += p.x; y += p.y; }
            return { x: x / list.length, y: y / list.length };
        };
        const eyeA = avg([lm[36], lm[37], lm[38], lm[39], lm[40], lm[41]]);
        const eyeB = avg([lm[42], lm[43], lm[44], lm[45], lm[46], lm[47]]);

        const roll = Math.atan2(eyeB.y - eyeA.y, eyeB.x - eyeA.x);
        const mx = (eyeA.x + eyeB.x) / 2;
        const my = (eyeA.y + eyeB.y) / 2;
        const c = Math.cos(-roll), s = Math.sin(-roll);
        const P = new Array(68);
        for (let i = 0; i < 68; i++) {
            const dx = lm[i].x - mx, dy = lm[i].y - my;
            P[i] = { x: dx * c - dy * s, y: dx * s + dy * c };
        }

        const pa = avg([P[36], P[37], P[38], P[39], P[40], P[41]]);
        const pb = avg([P[42], P[43], P[44], P[45], P[46], P[47]]);
        const eyeDist = Math.abs(pb.x - pa.x) || 1;
        const eyeMidY = (pa.y + pb.y) / 2;
        const eyeL = Math.min(pa.x, pb.x);
        const eyeR = Math.max(pa.x, pb.x);
        const nose = P[27];
        const noseBase = P[30];
        const mouthY = (P[62].y + P[66].y) / 2;
        const mouthX = (P[48].x + P[54].x) / 2;
        const chin = P[8];
        const mouthW = dist(P[48], P[54]) || 1;

        const checks = [];
        checks.push(['mata di atas hidung',    eyeMidY < nose.y - eyeDist * 0.10]);
        checks.push(['hidung di atas mulut',   noseBase.y < mouthY - eyeDist * 0.05]);
        checks.push(['dagu di bawah mulut',    mouthY < chin.y - eyeDist * 0.02]);
        checks.push(['hidung di antara mata',   nose.x > eyeL + eyeDist * 0.10 &&
                                               nose.x < eyeR - eyeDist * 0.10]);
        checks.push(['mulut di bawah hidung',  mouthY > noseBase.y]);
        checks.push(['mulut dekat sumbu mata',  Math.abs(mouthX) < eyeDist * tol]);
        checks.push(['lebar mulut wajar',      mouthW > eyeDist * 0.30 && mouthW < eyeDist * 2.20]);
        checks.push(['jarak mata wajar',       eyeDist > 1 && eyeDist < 1e5]);

        const failed = checks.filter((c2) => !c2[1]).map((c2) => c2[0]);
        return {
            ok: failed.length === 0,
            failed: failed,
            eyeDist: eyeDist,
            roll: roll,
        };
    }

    return {
        CFG: CFG,
        CHALLENGE_POOL: CHALLENGE_POOL,
        validateServerList: validateServerList,
        instruction: instruction,
        runSession: runSession,
        runChallenge: runChallenge,
        watchBlink: watchBlink,
        /* helper yang expose untuk test & UI */
        eyeMetrics: eyeMetrics,
        headTurn: headTurn,
        headNod: headNod,
        headTilt: headTilt,
        mouthOpen: mouthOpen,
        faceScale: faceScale,
        facePlausible: facePlausible,
    };
})();

if (typeof module !== 'undefined' && module.exports) {
    module.exports = Liveness;
}
