/**
 * gps-verifier.js
 * ---------------------------------------------------------------------------
 * Pengambil lokasi + deteksi GPS palsu (spoofing) berbasis heuristik.
 *
 * CATATAN PENTING SOAL BATASANNYA
 * -------------------------------
 * W3C Geolocation API TIDAK menyediakan flag mock/spoof. Field yang tersedia
 * hanya: accuracy, latitude, longitude, altitude, altitudeAccuracy, heading,
 * speed, timestamp. Tidak ada `isMocked`.
 *
 * Artinya: halaman web TIDAK BISA tahu apakah toggle
 * "Developer Options > Select mock location app" di Android sedang aktif.
 * Semua pemeriksaan di file ini adalah HEURISTIK (menaikkan biaya cheat),
 * bukan jaminan. Satu-satunya pencegahan mutlak butuh aplikasi native
 * (Play Integrity API) atau perangkat terminal kamera di gerbang sekolah.
 *
 * Yang TIDAK bisa dikalahkan skrip ini:
 *   - Chrome DevTools > Sensors > Override Geolocation (nilai indistinguishable
 *     dari native, sudah dibuktikan dalam riset geospoof)
 *   - Extension browser yang meng-override navigator.geolocation
 *
 * RUJUKAN:
 *   W3C REC-geolocation-20250923
 *   Incognia, "Detecting Location Spoofing" (pendekatan multi-sensor)
 * ---------------------------------------------------------------------------
 */

const GPS_GUARD = (function () {
    'use strict';

    const CFG = {
        /* Batas keras */
        MAX_ACCURACY_M: 200,        // akurasi > 200 m considered tidak layak
        MIN_ACCURACY_M: 1,          // akurasi < 1 m mustahil untuk GNSS konsumen
        NULL_ISLAND: 0.0001,        // 0,0 =Location k default aplikasi fake-GPS
        MAX_DECIMALS: 7,            // GNSS asli maksimal 7; 8+ = disalin dari peta

        /* Sampling */
        SAMPLE_COUNT: 6,
        SAMPLE_INTERVAL_MS: 1800,   // total ~11 detik
        REQUEST_TIMEOUT_MS: 15000,
        MAX_CACHED_AGE_MS: 0,       // paksa fix baru, jangan pakai cache

        /* Ambang heuristik */
        MIN_JITTER_M: 0.15,         // GNSS asli selalu bergetar > 15 cm
        MOTION_DELTA_G: 0.012,      // perubahan gravitasi terdeteksi = HP digerakkan
        MAX_PLAUSIBLE_SPEED_MPS: 75 // 75 m/s ~ 270 km/j, di atas itu mustahil
    };

    /* Bobot skor. Makin tinggi = makin yakin palsu. */
    const WEIGHT = {
        HARD: 100,     // langsung tolak
        STRONG: 35,    // sangat mencurigakan
        MEDIUM: 15,    // perludicurigai
        SOFT: 5        // info tambahan
    };

    // ---------------------------------------------------------------------
    // Utilitas
    // ---------------------------------------------------------------------

    const toRad = (deg) => (deg * Math.PI) / 180;
    const now = () => Date.now();

    /** Jumlah desimal pada representasi desimal dari sebuah angka. */
    function decimals(n) {
        const s = String(n);
        if (s.indexOf('e') !== -1 || s.indexOf('E') !== -1) return 0;
        const dot = s.indexOf('.');
        return dot === -1 ? 0 : s.length - dot - 1;
    }

    /** Jarak dua titik koordinat (meter) - formula Haversine. */
    function haversine(lat1, lng1, lat2, lng2) {
        const R = 6371000;
        const dLat = toRad(lat2 - lat1);
        const dLng = toRad(lng2 - lng1);
        const a =
            Math.sin(dLat / 2) * Math.sin(dLat / 2) +
            Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) *
            Math.sin(dLng / 2) * Math.sin(dLng / 2);
        return 2 * R * Math.asin(Math.sqrt(a));
    }

    function bearing(lat1, lng1, lat2, lng2) {
        const dLng = toRad(lng2 - lng1);
        const y = Math.sin(dLng) * Math.cos(toRad(lat2));
        const x =
            Math.cos(toRad(lat1)) * Math.sin(toRad(lat2)) -
            Math.sin(toRad(lat1)) * Math.cos(toRad(lat2)) * Math.cos(dLng);
        return ((Math.atan2(y, x) * 180) / Math.PI + 360) % 360;
    }

    // ---------------------------------------------------------------------
    // Pengambilan posisi
    // ---------------------------------------------------------------------

    function getPosition() {
        return new Promise((resolve, reject) => {
            if (!('geolocation' in navigator)) {
                reject(new Error('Geolocation tidak didukung browser ini.'));
                return;
            }
            navigator.geolocation.getCurrentPosition(
                resolve,
                (err) => reject(new Error('Gagal ambil lokasi: ' + err.message)),
                {
                    enableHighAccuracy: true,
                    timeout: CFG.REQUEST_TIMEOUT_MS,
                    maximumAge: CFG.MAX_CACHED_AGE_MS
                }
            );
        });
    }

    /** Ambil N sampel posisi secara berurutan dengan jeda. */
    async function collectSamples(count, intervalMs) {
        const out = [];
        for (let i = 0; i < count; i++) {
            const p = await getPosition();
            out.push({
                lat: p.coords.latitude,
                lng: p.coords.longitude,
                acc: p.coords.accuracy,
                alt: p.coords.altitude,
                speed: p.coords.speed,
                heading: p.coords.heading,
                ts: p.timestamp
            });
            if (i < count - 1) await sleep(intervalMs);
        }
        return out;
    }

    const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

    // ---------------------------------------------------------------------
    // Sensor gerak (akselerometer + gyroscope)
    // ---------------------------------------------------------------------

    /**
     * Pantauaccelerometer selama durasi tertentu.
     * Mengembalikanbesaran perubahan gravitasi yang terdeteksi.
     * Nilai 0 berarti perangkat dianggap diam.
     */
    function trackMotion(durationMs) {
        return new Promise((resolve) => {
            if (typeof window.DeviceMotionEvent === 'undefined') {
                resolve({ available: false, delta: 0 });
                return;
            }

            let started = false;
            let maxDelta = 0;
            let lastG = null;
            let samples = 0;

            const onMotion = (e) => {
                const a = e.accelerationIncludingGravity;
                if (a === null || a === undefined) return;

                const g = Math.sqrt(a.x * a.x + a.y * a.y + a.z * a.z) / 9.80665;
                samples++;
                if (lastG !== null) {
                    const d = Math.abs(g - lastG);
                    if (d > maxDelta) maxDelta = d;
                }
                lastG = g;

                if (!started) {
                    started = true;
                    setTimeout(() => {
                        window.removeEventListener('devicemotion', onMotion);
                        resolve({
                            available: samples > 3,
                            delta: maxDelta,
                            samples
                        });
                    }, durationMs);
                }
            };

            window.addEventListener('devicemotion', onMotion, { passive: true });

            // Fallback: sensor tidak pernah memberi data (desktop / sensor mati)
            setTimeout(() => {
                if (!started) {
                    window.removeEventListener('devicemotion', onMotion);
                    resolve({ available: false, delta: 0, samples: 0 });
                }
            }, durationMs + 300);
        });
    }

    // ---------------------------------------------------------------------
    // Setiap lapisan pemeriksaan
    // ---------------------------------------------------------------------

    function checkEnvironment(findings) {
        // Deteksi browser otomatis / headless / remote debugging
        if (navigator.webdriver === true) {
            findings.push({ code: 'AUTOMATION', weight: WEIGHT.HARD,
                detail: 'navigator.webdriver = true (browser dikendalikan otomatis)' });
        }

        if (typeof Geolocation === 'undefined') {
            findings.push({ code: 'GEOLOCATION_MISSING', weight: WEIGHT.HARD,
                detail: 'Objek Geolocation tidak ada di konteks ini' });
            return;
        }

        // Geolocation asli mendefinisikan method di prototype, bukan di instance.
        // Own property pada instance berarti ada yang menimpanya.
        const own = Object.getOwnPropertyDescriptor(navigator.geolocation, 'getCurrentPosition');
        if (own !== undefined) {
            findings.push({ code: 'GEOLOCATION_OVERRIDDEN', weight: WEIGHT.STRONG,
                detail: 'getCurrentPosition di-override pada instance navigator.geolocation' });
        }

        // Method native selalu memuat [native code] pada toString()
        const src = Function.prototype.toString.call(Geolocation.prototype.getCurrentPosition);
        if (src.indexOf('[native code]') === -1) {
            findings.push({ code: 'GEOLOCATION_PATCHED', weight: WEIGHT.STRONG,
                detail: 'Fungsi geolocation tidak lagi native' });
        }
    }

    function checkValuePlausibility(s, findings) {
        const { lat, lng, acc } = s;

        if (Math.abs(lat) > 90 || Math.abs(lng) > 180) {
            findings.push({ code: 'OUT_OF_RANGE', weight: WEIGHT.HARD,
                detail: 'Koordinat di luar rentang bumi valid' });
        }
        if (Math.abs(lat) < CFG.NULL_ISLAND && Math.abs(lng) < CFG.NULL_ISLAND) {
            findings.push({ code: 'NULL_ISLAND', weight: WEIGHT.HARD,
                detail: 'Koordinat 0,0 - nilai default aplikasi fake-GPS' });
        }
        if (acc === null || acc === undefined) {
            findings.push({ code: 'NO_ACCURACY', weight: WEIGHT.STRONG,
                detail: 'Browser tidak melaporkan akurasi' });
        } else if (acc < CFG.MIN_ACCURACY_M) {
            findings.push({ code: 'ACCURACY_TOO_GOOD', weight: WEIGHT.MEDIUM,
                detail: 'Akurasi ' + acc.toFixed(2) + ' m, mustahil untuk GNSS konsumen' });
        } else if (acc > CFG.MAX_ACCURACY_M) {
            findings.push({ code: 'ACCURACY_TOO_LOW', weight: WEIGHT.MEDIUM,
                detail: 'Akurasi ' + Math.round(acc) + ' m, di bawah ambang GPS' });
        }

        const dl = Math.max(decimals(lat), decimals(lng));
        if (dl > CFG.MAX_DECIMALS) {
            findings.push({ code: 'EXCESS_PRECISION', weight: WEIGHT.SOFT,
                detail: dl + ' angka desimal, GNSS tidak sepresisi itu' });
        }
    }

    function checkFreshness(s, findings) {
        if (typeof s.ts !== 'number' || !isFinite(s.ts)) {
            findings.push({ code: 'NO_TIMESTAMP', weight: WEIGHT.MEDIUM,
                detail: 'Sampel tidak memiliki timestamp yang valid' });
            return;
        }
        if (s.ts > now() + 60000) {
            findings.push({ code: 'FUTURE_TIMESTAMP', weight: WEIGHT.HARD,
                detail: 'Timestamp lokasi berada di masa depan' });
        } else if (now() - s.ts > 120000) {
            findings.push({ code: 'STALE_FIX', weight: WEIGHT.STRONG,
                detail: 'Fix GPS berumur ' + Math.round(age / 1000) + ' detik' });
        }
        return age;
    }

    /** Deteksi koordinat yang beku: aplikasi mock selalu mengulang nilai sama. */
    function checkJitter(samples, findings) {
        const uniq = new Set(samples.map((s) => s.lat + '|' + s.lng));
        const uniqAcc = new Set(samples.map((s) => s.acc));

        // Jarak dari sampel pertama ke semua sampel lain
        let spread = 0;
        for (const s of samples) {
            spread = Math.max(spread, haversine(samples[0].lat, samples[0].lng, s.lat, s.lng));
        }

        if (uniq.size === 1) {
            findings.push({ code: 'FROZEN_COORDS', weight: WEIGHT.STRONG,
                detail: samples.length + ' sampel menghasilkan koordinat identik (getaran 0 m)' });
        } else if (spread < CFG.MIN_JITTER_M) {
            findings.push({ code: 'LOW_JITTER', weight: WEIGHT.MEDIUM,
                detail: 'Getaran koordinat ' + spread.toFixed(3) + ' m, terlalu kecil untuk GNSS asli' });
        }
        if (uniqAcc.size === 1 && samples.length >= 3) {
            findings.push({ code: 'FROZEN_ACCURACY', weight: WEIGHT.MEDIUM,
                detail: 'Nilai akurasi tidak pernah berubah, indikasi nilai manual' });
        }
        return { spread, uniqueCoords: uniq.size };
    }

    /** Kecepatan implisit antar sampel - jangan sampai tidak masuk akal. */
    function checkTeleport(samples, findings) {
        for (let i = 1; i < samples.length; i++) {
            const a = samples[i - 1];
            const b = samples[i];
            const dist = haversine(a.lat, a.lng, b.lat, b.lng);
            const dt = (b.ts - a.ts) / 1000;
            if (dt > 0.5 && dist > 0) {
                const speed = dist / dt;
                if (speed > CFG.MAX_PLAUSIBLE_SPEED_MPS) {
                    findings.push({ code: 'IMPOSSIBLE_SPEED', weight: WEIGHT.HARD,
                        detail: 'Lompat ' + Math.round(dist) + ' m dalam ' + dt.toFixed(1) +
                                ' s (' + Math.round(speed) + ' m/s)' });
                }
            }
        }
    }

    /**
     * Pemeriksaan paling kuat yang tersedia di web:
     * cross-check antara sensor gerak fisik dan koordinat GPS.
     * Kalau perangkat terdeteksi bergerak tetapi koordinat tidak berubah sama
     * sekali, koordinat itu tidak berasal dari GNSS.
     */
    function checkMotionVsGps(motion, jitterSpread, findings) {
        if (!motion.available) {
            findings.push({ code: 'MOTION_UNAVAILABLE', weight: 0,
                detail: 'Sensor gerak tidak tersedia, cross-check dilewati' });
            return { verified: false };
        }
        if (motion.delta > CFG.MOTION_DELTA_G && jitterSpread < 0.5) {
            findings.push({ code: 'MOTION_WITHOUT_GPS_MOVEMENT', weight: WEIGHT.HARD,
                detail: 'Perangkat bergerak (Δ' + motion.delta.toFixed(4) +
                        ' g) tetapi koordinat GPS tetap beku' });
            return { verified: true, spoofed: true };
        }
        return { verified: true, spoofed: false };
    }

    // ---------------------------------------------------------------------
    // API utama
    // ---------------------------------------------------------------------

    /**
     * Jalankan seluruh rangkaian verifikasi lokasi.
     *
     * @param {Object} target  { lat, lng, maxRadiusM } lokasi sekolah
     * @param {Object} opts    { motion: Boolean, quick: Boolean }
     * @returns {Promise<Object>} laporan verifikasi
     */
    async function verify(target, opts) {
        opts = Object.assign({ motion: true, quick: false }, opts || {});
        const findings = [];
        const startedAt = now();

        checkEnvironment(findings);

        const sampleCount = opts.sampleCount || (opts.quick ? 1 : CFG.SAMPLE_COUNT);
        const interval = opts.intervalMs || (opts.quick ? 100 : CFG.SAMPLE_INTERVAL_MS);

        let samples;
        try {
            samples = await collectSamples(sampleCount, interval);
        } catch (err) {
            return {
                ok: false,
                blocked: true,
                score: WEIGHT.HARD,
                verdict: 'GAGAL_AMBIL',
                error: err.message,
                findings: [{ code: 'POSITION_UNAVAILABLE', weight: WEIGHT.HARD,
                    detail: err.message }],
                samples: []
            };
        }

        samples.forEach(function (s, i) {
            if (i === 0) checkValuePlausibility(s, findings);
            checkFreshness(s, findings);
        });

        checkTeleport(samples, findings);
        const jitter = checkJitter(samples, findings);

        let motion = { available: false, delta: 0 };
        if (opts.motion && !opts.quick) {
            motion = await trackMotion(1200);
        }
        const mv = checkMotionVsGps(motion, jitter.spread, findings);

        // --- Nilai akhir dengan rata-rata sampel terbaru sebagai lokasi fokus
        const last = samples[samples.length - 1];
        const distance = target ? haversine(target.lat, target.lng, last.lat, last.lng) : null;
        const radius = target ? target.maxRadiusM : null;
        const withinRadius = distance === null ? null : distance <= radius;

        if (withinRadius === false) {
            findings.push({ code: 'OUT_OF_RADIUS', weight: WEIGHT.HARD,
                detail: 'Jarak ' + Math.round(distance) + ' m, batas ' + radius + ' m' });
        }

        const score = findings.reduce((sum, f) => sum + f.weight, 0);
        const blocked = findings.some((f) => f.weight >= WEIGHT.HARD);
        const suspicious = score >= 60;

        return {
            ok: !blocked && withinRadius !== false,
            blocked: blocked,
            suspicious: suspicious,
            score: score,
            verdict: blocked ? 'DITOLAK' : (suspicious ? 'PERLU_DITINJAU' : 'LOKAL'),
            distanceM: distance === null ? null : Math.round(distance),
            withinRadius: withinRadius,
            bearingDeg: target ? Math.round(bearing(target.lat, target.lng, last.lat, last.lng)) : null,
            accuracyM: last.acc,
            motion: motion,
            motionCrossCheck: mv,
            elapsedMs: now() - startedAt,
            sampleCount: samples.length,
            jitterSpreadM: Number(jitter.spread.toFixed(3)),
            samples: samples,
            findings: findings
        };
    }

    /**
     * Ringkasan satu baris untuk dikirim ke server.
     * Server WAJIB menghitung ulang jarak Haversine dari lat/lng mentah.
     */
    function toPayload(report) {
        const last = report.samples.length
            ? report.samples[report.samples.length - 1]
            : { lat: 0, lng: 0, acc: null };
        return {
            lat: last.lat,
            lng: last.lng,
            accuracy: last.acc,
            altitude: last.alt,
            speed: last.speed,
            heading: last.heading,
            timestamp: last.ts,
            // Laporan heuristik, bukan bukti. Server tidak boleh mempercayainya
            // sebagai satu-satunya dasar keputusan.
            guard: {
                verdict: report.verdict,
                score: report.score,
                suspicious: !!report.suspicious,
                jitter: report.jitterSpreadM,
                motionDelta: report.motion ? report.motion.delta : null,
                motionAvailable: report.motion ? report.motion.available : false,
                codes: report.findings.map((f) => f.code),
                elapsedMs: report.elapsedMs
            }
        };
    }

    return {
        verify: verify,
        toPayload: toPayload,
        haversine: haversine,
        bearing: bearing,
        getPosition: getPosition,
        CFG: CFG,
        WEIGHT: WEIGHT
    };
})();

if (typeof module !== 'undefined' && module.exports) {
    module.exports = GPS_GUARD;
}
