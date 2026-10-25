/**
 * Pengendali halaman scan absensi.
 *
 * Alur satu tombol:
 *   1. muat model wajah dari CDN (sekali, lalu cache browser)
 *   2. buka kamera
 *   3. ambil nonce + jadwal + lokasi sekolah dari server
 *   4. tekan tombol: liveness -> descriptor -> kirim ke server
 *
 * Yang penting dipahami: tidak ada keputusan yang diambil di file ini.
 * Pencocokan wajah, jarak GPS, kelayakan liveness, jadwal, dan duplikasi
 * semuanya dihitung ulang di scan-check.php. Nilai lokal di sini hanya
 * memberi umpan balik supaya siswa tidak menunggu kosong. Nilai lokal
 * boleh lebih longgar daripada server, sebab server tetap menolak kalau
 * tidak cocok.
 */
(function () {
    'use strict';

    var P = window.PRESENSI || {};
    var VID = P.apiBase + '/attendance/';
    var STU = P.apiBase + '/student/';

    /* Berapa lama tombol ditahan setelah presensi tercatat, supaya
     * siswa melihat hasil dan tidak langsung menekan lagi. */
    var HITUNG_MUND_DETIK = 5;

    /* ----------------------------------------------------------------
     |  Elemen
     * ---------------------------------------------------------------- */

    function el(id) { return document.getElementById(id); }

    var video      = el('video');
    var titik      = el('titik');
    var statusKama = el('statusKamera');
    var petunjuk   = el('petunjuk');
    var progres    = el('progres');
    var progresTeks= el('progresTeks');
    var persen     = el('prosesPersen');
    var pengum     = el('pengumuman');
    var pengumJud  = el('pengumumanJudul');
    var pengumIsi  = el('pengumumanIsi');
    var livenessBox= el('liveness');
    var lvLangkah  = el('livenessLangkah');
    var lvInstruksi= el('livenessInstruksi');
    var lvRincian  = el('livenessRincian');
    var hasilBackdrop = el('hasilBackdrop');
    var hasilBox   = el('hasil');
    var hasilIkon  = el('hasilIkon');
    var hasilJudul = el('hasilJudul');
    var hasilIsi   = el('hasilRincian');
    var btnTutupHasil = el('tombolTutupHasil');
    var btnMulai   = el('tombolMulai');
    var btnDaftar  = el('tombolDaftar');
    var btnUlang   = el('tombolUlang');
    var panelSampel= el('panelSampel');
    var sampelBox  = el('sampel');
    var btnKirim   = el('tombolKirimSampel');
    var rijek      = el('rijek');
    var rijekIsi   = el('rijekIsi');
    var jam        = el('jam');
    var jadwalNow  = el('jadwalNow');
    var hitungMund = el('hitungMundur');
    var umat       = el('umat');

    /* ----------------------------------------------------------------
     |  State
     * ---------------------------------------------------------------- */

    var stream = null;
    var nonce = null;          /* { nonce, challenges, schedule, school, ... } */
    var gpsReport = null;
    var galatGps = null;
    var lastDetect = null;     /* hasil deteksi terakhir, dipakai liveness */
    var pumping = false;
    var sibuk = false;         /* kunci agar tidak ada dua scanParalel */
    var sampelEnroll = [];
    var gagalKamera = false;

    /* ----------------------------------------------------------------
     |  Tampilan
     * ---------------------------------------------------------------- */

    function ucapkan(pesan) { if (umat) umat.textContent = pesan; }

    function progresSet(p, teks) {
        var v = Math.max(0, Math.min(100, p));
        if (progres) progres.style.width = v + '%';
        if (persen) persen.textContent = Math.round(p) + '%';
        if (teks && progresTeks) progresTeks.textContent = teks;
    }

    function siang(state, teks) {
        if (titik) titik.setAttribute('data-siang', state);
        if (teks && statusKama) statusKama.textContent = teks;
    }

    function pengumumanTampil(judul, isi, jenis) {
        if (!pengum) return;
        pengum.hidden = false;
        pengum.setAttribute('data-jenis', jenis || 'kuning');
        if (pengumJud) pengumJud.textContent = judul;
        if (pengumIsi) pengumIsi.textContent = isi || '';
    }

    function pengumumanSembunyi() { if (pengum) pengum.hidden = true; }

    function hasilTampil(ok, judul, rincian) {
        if (hasilBackdrop) hasilBackdrop.hidden = false;
        if (hasilBox) {
            hasilBox.setAttribute('data-aktif', '1');
            hasilBox.setAttribute('data-hasil', ok ? 'ok' : 'gagal');
        }
        if (hasilIkon) hasilIkon.textContent = ok ? 'Berhasil' : 'Ditolak';
        if (hasilJudul) hasilJudul.textContent = judul;
        if (hasilIsi) hasilIsi.textContent = rincian || '';
        ucapkan(judul + '. ' + (rincian || ''));
    }

    function hasilSembunyi() {
        if (hasilBox) hasilBox.setAttribute('data-aktif', '0');
        if (hasilBackdrop) hasilBackdrop.hidden = true;
    }

    /* ----------------------------------------------------------------
     |  Jam
     * ---------------------------------------------------------------- */

    function jalankanJam() {
        function tick() {
            var d = new Date();
            var p = function (n) { return (n < 10 ? '0' : '') + n; };
            jam.textContent = p(d.getHours()) + ':' + p(d.getMinutes()) + ':' + p(d.getSeconds());
        }
        tick();
        setInterval(tick, 1000);
    }

    /* ----------------------------------------------------------------
     |  Kamera
     * ---------------------------------------------------------------- */

    async function bukaKamera() {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            gagalKamera = true;
            rijek.hidden = false;
            rijekIsi.textContent = 'Peramban ini tidak mendukung akses kamera. Gunakan HTTPS atau peramban modern.';
            siang('gagal', 'Tidak didukung');
            throw new Error('getUserMedia tidak tersedia');
        }

        try {
            stream = await navigator.mediaDevices.getUserMedia({
                video: {
                    facingMode: 'user',
                    width: { ideal: 640 },
                    height: { ideal: 480 },
                },
                audio: false,
            });
            video.srcObject = stream;
            await video.play();
            siang('siap', 'Kamera siap');
        } catch (err) {
            gagalKamera = true;
            rijek.hidden = false;
            var nama = err && err.name ? err.name : 'error';
            rijekIsi.textContent = nama === 'NotAllowedError'
                ? 'Izinkan akses kamera di pengaturan peramban, lalu muat ulang halaman.'
                : 'Kamera tidak dapat dibuka (' + nama + '). Pastikan tidak dipakai aplikasi lain.';
            siang('gagal', 'Kamera gagal');
            throw err;
        }
    }

    function tutupKamera() {
        if (stream) {
            stream.getTracks().forEach(function (t) { t.stop(); });
            stream = null;
        }
    }

    /* ----------------------------------------------------------------
     |  Pompa deteksi
     |
     |  Liveness dan pencocokan memakai hasil deteksi yang sama, jadi
     |  cukup satu detectSingleFace per frame. Kalau dua loop berjalan
     |  bersamaan, tiap frame dihitung dua kali dan HP kelas lama
     |  akan sangat berat.
     * ---------------------------------------------------------------- */

    async function pompa() {
        if (pumping) return;
        pumping = true;
        try {
            while (stream && !gagalKamera) {
                try {
                    lastDetect = await ScanEngine.detectFromVideo(video, false);
                } catch (e) {
                    lastDetect = null;
                }
                if (!sibuk && lastDetect) {
                    if (lastDetect.ok) {
                        petunjuk.textContent = 'Wajah terdeteksi. Tekan tombol untuk mulai.';
                        if (titik && titik.getAttribute('data-siang') !== 'jalan') siang('siap', 'Wajah terbaca');
                    } else {
                        petunjuk.textContent = lastDetect.reason || 'Posisikan wajah Anda di dalam lingkaran.';
                        siang('siap', 'Cari wajah');
                    }
                }
                /* Yield supaya halaman tetap bisa digambar. */
                await new Promise(function (r) { requestAnimationFrame(function () { r(); }); });
            }
        } finally {
            pumping = false;
        }
    }

    function landmarks() {
        if (lastDetect && lastDetect.ok && lastDetect.landmarks) {
            return lastDetect.landmarks.positions || lastDetect.landmarks;
        }
        return null;
    }

    /* ----------------------------------------------------------------
     |  Nonce + jadwal
     * ---------------------------------------------------------------- */

    async function muatNonce() {
        const res = await fetch(VID + 'scan-nonce.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' },
        });
        const data = await res.json();
        if (!res.ok || !data.success) {
            throw new Error(data.message || 'Gagal mengambil nonce (HTTP ' + res.status + ')');
        }
        nonce = data;

        jadwalNow.textContent = 'Presensi: ' + labelJenis(data.schedule.type);

        if (data.schedule.issues && data.schedule.issues.length) {
            pengumumanTampil('Jadwal Bermasalah',
                data.schedule.issues.join(' | ') + ' Hubungi admin.', 'gagal');
        }
        return data;
    }

    function labelJenis(t) {
        if (t === 'masuk') return 'Masuk';
        if (t === 'pulang') return 'Pulang';
        return 'Di luar jadwal';
    }

    /* ----------------------------------------------------------------
     |  GPS
     * ---------------------------------------------------------------- */

    function ambilGpsLangsung() {
        return new Promise(function (resolve) {
            if (!('geolocation' in navigator)) {
                galatGps = { code: 0, message: 'Geolocation tidak didukung browser ini.' };
                resolve(null);
                return;
            }
            navigator.geolocation.getCurrentPosition(
                function (pos) {
                    resolve({
                        lat: pos.coords.latitude,
                        lng: pos.coords.longitude,
                        accuracy: pos.coords.accuracy || 10,
                        altitude: pos.coords.altitude,
                        speed: pos.coords.speed,
                        heading: pos.coords.heading,
                        timestamp: pos.timestamp || Date.now(),
                    });
                },
                function (err) {
                    galatGps = {
                        code: err && typeof err.code === 'number' ? err.code : null,
                        message: (err && err.message) || 'Gagal mengambil lokasi.',
                    };
                    navigator.geolocation.getCurrentPosition(
                        function (pos2) {
                            resolve({
                                lat: pos2.coords.latitude,
                                lng: pos2.coords.longitude,
                                accuracy: pos2.coords.accuracy || 25,
                                altitude: pos2.coords.altitude,
                                speed: pos2.coords.speed,
                                heading: pos2.coords.heading,
                                timestamp: pos2.timestamp || Date.now(),
                            });
                        },
                        function (err2) {
                            galatGps = {
                                code: err2 && typeof err2.code === 'number' ? err2.code : null,
                                message: (err2 && err2.message) || 'Gagal mengambil lokasi.',
                            };
                            resolve(null);
                        },
                        { enableHighAccuracy: false, timeout: 10000, maximumAge: 60000 }
                    );
                },
                { enableHighAccuracy: true, timeout: 10000, maximumAge: 30000 }
            );
        });
    }

    async function ukurGps(forceFresh) {
        galatGps = null;

        /* Gunakan fix yang masih segar (< 60 detik) untuk respons instan */
        if (!forceFresh && gpsReport && gpsReport.lat && gpsReport.lng && (Date.now() - (gpsReport.timestamp || 0) < 60000)) {
            return gpsReport;
        }

        let payload = null;

        /* Jalur utama: GPS_GUARD dengan anti-spoofing heuristik cepat */
        if (nonce && nonce.school && nonce.school.lat) {
            try {
                const report = await GPS_GUARD.verify({
                    lat: nonce.school.lat,
                    lng: nonce.school.lng,
                    maxRadiusM: nonce.school.radiusM,
                }, {
                    sampleCount: 1,
                    intervalMs: 100,
                    motion: false,
                    quick: true,
                });
                if (report) {
                    var galatReport = null;
                    if (report.error) {
                        galatReport = { code: null, message: report.error };
                    } else if (report.findings && report.findings.length) {
                        for (var fi = 0; fi < report.findings.length; fi++) {
                            var temuan = report.findings[fi];
                            if (temuan && temuan.code) {
                                galatReport = { code: temuan.code, message: temuan.detail || temuan.code };
                                break;
                            }
                        }
                    }
                    if (galatReport) galatGps = galatReport;
                }
                if (report && report.samples && report.samples.length > 0) {
                    payload = GPS_GUARD.toPayload(report);
                }
            } catch (e) {
                payload = null;
                galatGps = { code: null, message: e.message || String(e) };
            }
        }

        /* Jalur cadangan: ambil langsung dari Geolocation API */
        if (!payload || !payload.lat || !payload.lng) {
            const direct = await ambilGpsLangsung();
            if (direct) {
                payload = {
                    lat: direct.lat,
                    lng: direct.lng,
                    accuracy: direct.accuracy,
                    altitude: direct.altitude,
                    speed: direct.speed,
                    heading: direct.heading,
                    timestamp: direct.timestamp,
                    guard: {
                        verdict: 'LOKAL',
                        score: 0,
                        suspicious: false,
                        jitter: null,
                        motionAvailable: false,
                        motionDelta: 0,
                        codes: [],
                    }
                };
            }
        }

        if (payload && payload.lat) {
            gpsReport = payload;
        }
        return gpsReport;
    }

    function ringkasGps(g) {
        if (!g) return 'Lokasi tidak terdeteksi';
        if (g.spoofed) return 'Lokasi mencurigakan';
        var d = g.distance != null ? Math.round(g.distance) + ' m dari sekolah' : 'dalam area sekolah';
        return d;
    }

    function pesanGpsGagal() {
        if (!window.isSecureContext) {
            return 'Halaman harus dibuka lewat HTTPS. Buka lewat alamat https://, bukan http:// IP lokal.';
        }
        if (!nonce || !nonce.school || !nonce.school.lat) {
            return 'Patokan lokasi sekolah belum diatur. Hubungi admin (Pengaturan -> Lokasi).';
        }
        if (galatGps && galatGps.code === 1) {
            return 'Izin lokasi ditolak oleh browser. Buka ikon gembok / Setelan situs -> Lokasi -> Izinkan, lalu MUAT ULANG halaman.';
        }
        if (galatGps && galatGps.code === 2) {
            return 'Sinyal lokasi tidak terdeteksi. Nyalakan GPS dan Wi-Fi, pindah ke area terbuka, lalu coba lagi.';
        }
        if (galatGps && galatGps.code === 3) {
            return 'Pengambilan lokasi habis waktu. Coba lagi di tempat terbuka sinyal.';
        }
        var pesan = 'GPS/Lokasi perangkat belum aktif atau izin lokasi ditolak. Aktifkan lokasi di HP/browser Anda lalu coba lagi.';
        if (galatGps && galatGps.message) {
            pesan += ' (' + galatGps.message + ')';
        }
        return pesan;
    }

    /* ----------------------------------------------------------------
     |  Scan
     * ---------------------------------------------------------------- */

    async function mulaiScan() {
        if (sibuk) return;

        /* KUNCI LANGSUNG & BERIKAN UMPAN BALIK VISUAL INSTAN TANPA DELAY */
        sibuk = true;
        if (btnMulai) {
            btnMulai.disabled = true;
            btnMulai.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Memverifikasi...';
        }
        if (btnUlang) btnUlang.hidden = true;
        hasilSembunyi();
        if (livenessBox) livenessBox.setAttribute('data-aktif', '1');
        siang('jalan', 'Memverifikasi');
        var stageEl = el('viewfinder') || document.querySelector('.ios-viewfinder');
        if (stageEl) stageEl.classList.add('is-active');

        try {
            /* Nonce punya masa berlaku. Kalau sudah habis karena tab
             * ditinggal lama, minta yang baru */
            if (!nonce) {
                try {
                    await muatNonce();
                } catch (e) {
                    throw new Error('Token scan tidak bisa diminta (' + (e.message || e) + '). Muat ulang halaman.');
                }
            }
            if (!nonce) {
                throw new Error('Token scan belum tersedia. Muat ulang halaman lalu coba lagi.');
            }

            if (nonce.schedule && nonce.schedule.type === 'lainnya') {
                throw new Error('Di luar jadwal presensi. Jadwal masuk '
                    + nonce.schedule.masuk + ', pulang ' + nonce.schedule.pulang + '.');
            }

            /* JALANKAN GPS SECARA PARALEL DENGAN LIVENESS (0 DELAY TUNGGU) */
            const gpsPromise = ukurGps();

            /* Liveness memakai challenge yang server pilih */
            const lv = await Liveness.runSession({
                getLandmarks: landmarks,
                challenges: nonce.challenges,
                onProgress: tampilkanLiveness,
            });

            /* Tunggu hasil GPS paralel */
            const gps = await gpsPromise;
            if (!gps || !gps.lat || !gps.lng) {
                throw new Error(pesanGpsGagal());
            }

            /* Descriptor diambil tepat setelah liveness */
            const d = await ScanEngine.detectFromVideo(video);
            if (!d.ok) {
                throw new Error(d.reason);
            }

            const kandidat = ScanEngine.match(d.descriptor, 3);

            /* Sidik perangkat: hash ringan */
            var fingerprint = '';
            try {
                var fpParts = [
                    navigator.userAgent || '',
                    screen.width + 'x' + screen.height,
                    screen.colorDepth || '',
                    navigator.language || '',
                    Intl.DateTimeFormat().resolvedOptions().timeZone || '',
                    navigator.hardwareConcurrency || '',
                ];
                var fpStr = fpParts.join('|');
                var hash = 2166136261;
                for (var ci = 0; ci < fpStr.length; ci++) {
                    hash ^= fpStr.charCodeAt(ci);
                    hash = (hash * 16777619) >>> 0;
                }
                fingerprint = hash.toString(16);
            } catch (e) { /* abaikan */ }

            const res = await ScanEngine.submitScan({
                nonce: nonce.nonce,
                descriptor: ScanEngine.toBase64(d.descriptor),
                gps: gps,
                liveness: lv,
                fingerprint: fingerprint,
            });

            tampilkanHasilServer(res, kandidat, lv);

        } catch (err) {
            tampilkanGagal(err.message || String(err));
        } finally {
            sibuk = false;
            if (livenessBox) livenessBox.setAttribute('data-aktif', '0');
            if (lvRincian) lvRincian.innerHTML = '';
            siang('siap', 'Siap');
            if (stageEl) stageEl.classList.remove('is-active');
            if (btnMulai) {
                btnMulai.disabled = false;
                btnMulai.innerHTML = '<i class="fa-solid fa-expand"></i> Mulai Scan Face ID';
            }
            if (petunjuk) petunjuk.textContent = 'Posisikan wajah Anda di dalam lingkaran.';
        }
    }

    function tampilkanLiveness(p) {
        var gIcon = el('livenessGestureIcon');
        if (lvLangkah) lvLangkah.textContent = 'Verifikasi Gerakan';
        if (p.message) {
            if (lvInstruksi) lvInstruksi.textContent = p.message;
            if (petunjuk) petunjuk.textContent = p.message;
        }
        if (gIcon) {
            var type = (p.type || p.stage || '').toLowerCase();
            var msg = (p.message || '').toLowerCase();
            if (type.indexOf('left') !== -1 || msg.indexOf('kiri') !== -1) {
                gIcon.innerHTML = '<i class="fa-solid fa-arrow-left"></i>';
            } else if (type.indexOf('right') !== -1 || msg.indexOf('kanan') !== -1) {
                gIcon.innerHTML = '<i class="fa-solid fa-arrow-right"></i>';
            } else if (type.indexOf('nod') !== -1 || msg.indexOf('angguk') !== -1) {
                gIcon.innerHTML = '<i class="fa-solid fa-arrow-down"></i>';
            } else if (type.indexOf('mouth') !== -1 || type.indexOf('smile') !== -1 || msg.indexOf('senyum') !== -1) {
                gIcon.innerHTML = '<i class="fa-regular fa-face-smile"></i>';
            } else if (type.indexOf('blink') !== -1 || msg.indexOf('kedip') !== -1) {
                gIcon.innerHTML = '<i class="fa-regular fa-eye"></i>';
            } else {
                gIcon.innerHTML = '<i class="fa-solid fa-arrows-spin"></i>';
            }
        }
        if (lvRincian) {
            lvRincian.textContent = p.done ? 'Terverifikasi! ✅' : '';
        }
    }

    var scanSukses = false;

    function tampilkanHasilServer(res, kandidat, lv) {
        if (res.success) {
            scanSukses = true;
            const nama = kandidat && kandidat[0] ? kandidat[0].name : P.userName;
            hasilTampil(true, res.message || 'Presensi Tercatat Berhasil! ✅',
                nama + ' - Absen ' + (res.attendance_type === 'pulang' ? 'Pulang' : 'Masuk')
                + ' (' + ringkasGps(gpsReport) + ')');
            if (btnTutupHasil) {
                btnTutupHasil.textContent = 'Selesai (Kembali ke Menu)';
            }
            if (btnMulai) {
                btnMulai.disabled = false;
                btnMulai.innerHTML = '<i class="fa-solid fa-expand"></i> Scan Lagi';
            }
            if (btnUlang) btnUlang.hidden = true;
            muatNonce().catch(function () {});

            /* Otomatis arahkan ke dashboard/menu utama setelah 1.5 detik */
            setTimeout(function () {
                if (scanSukses) {
                    window.location.href = '/siswa/index.php';
                }
            }, 1500);
        } else {
            scanSukses = false;
            if (btnTutupHasil) {
                btnTutupHasil.textContent = 'Tutup';
            }
            tampilkanGagal(res.message || 'Presensi ditolak.');
            muatNonce().catch(function () {});
        }
    }

    function tampilkanGagal(pesan) {
        scanSukses = false;
        hasilTampil(false, 'Presensi ditolak', pesan);
        if (btnTutupHasil) {
            btnTutupHasil.textContent = 'Coba Lagi';
        }
        if (btnMulai) {
            btnMulai.disabled = false;
            btnMulai.innerHTML = '<i class="fa-solid fa-rotate-right"></i> Coba Lagi';
        }
        if (btnUlang) btnUlang.hidden = true;
    }

    function selesaiScan() {
        hasilSembunyi();
        if (btnMulai) {
            btnMulai.disabled = false;
            btnMulai.innerHTML = '<i class="fa-solid fa-expand"></i> Mulai Scan Face ID';
        }
        if (btnUlang) btnUlang.hidden = true;
        muatNonce().catch(function () {});
    }

    function countdown(durasiDetik) {
        /* Argumennya wajib angka. Dulu pemanggilnya mengoper
         * selesaiScan (fungsi), sehingga sisa menjadi NaN, syarat
         * sisa <= 0 tidak pernah terpenuhi, tombol crip dan tidak
         * pernah pulih. Sekarang jumlah detik punya nama sendiri dan
         * pemeriksa menolak nilai yang bukan angka. */
        let sisa = Number(durasiDetik);
        if (!isFinite(sisa) || sisa <= 0) {
            console.error('[SCAN] hitung mundur tidak valid: ' + durasiDetik);
            selesaiScan();
            return;
        }
        hitungMund.hidden = false;
        hitungMund.textContent = 'Kembali dalam ' + sisa + ' dtk';
        const t = setInterval(function () {
            sisa -= 1;
            if (sisa <= 0) {
                clearInterval(t);
                hitungMund.hidden = true;
                selesaiScan();
                return;
            }
            hitungMund.textContent = 'Kembali dalam ' + sisa + ' dtk';
        }, 1000);
    }

    /* ----------------------------------------------------------------
     |  Pendaftaran wajah
     * ---------------------------------------------------------------- */

    function gambarKotakSampel(jumlah) {
        if (!sampelBox) return;
        sampelBox.innerHTML = '';
        for (let i = 0; i < jumlah; i++) {
            const k = document.createElement('span');
            k.className = 'sample-dot';
            k.setAttribute('data-terisi', '0');
            k.textContent = String(i + 1);
            sampelBox.appendChild(k);
        }
    }

    async function daftarWajah() {
        if (sibuk || !stream) return;
        sibuk = true;
        if (btnDaftar) {
            btnDaftar.disabled = true;
            btnDaftar.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Merekam Wajah...';
        }
        hasilSembunyi();
        if (panelSampel) panelSampel.hidden = false;
        if (btnKirim) btnKirim.disabled = true;
        sampelEnroll = [];
        gambarKotakSampel(P.enrollMaxSamples || 5);
        siang('jalan', 'Mengambil sampel');
        if (petunjuk) petunjuk.textContent = 'Posisikan wajah di kamera dan tahan posisi...';
        var stageEl = el('viewfinder') || document.querySelector('.ios-viewfinder');
        if (stageEl) stageEl.classList.add('is-active');

        try {
            sampelEnroll = await ScanEngine.captureEnrollSamples(
                video,
                P.enrollMaxSamples || 5,
                function (i, total, d, ok) {
                    if (petunjuk) {
                        petunjuk.textContent = ok
                            ? 'Merekam sampel ' + i + ' dari ' + total + '... Tahan posisi'
                            : 'Mencari posisi wajah... (' + (d.reason || 'posisikan di lingkaran') + ')';
                    }
                    if (ok && sampelBox && sampelBox.children[i - 1]) {
                        sampelBox.children[i - 1].setAttribute('data-terisi', '1');
                    }
                }
            );

            if (sampelEnroll.length < 2) {
                throw new Error('Hanya ' + sampelEnroll.length
                    + ' sampel yang terbaca. Pastikan wajah terlihat jelas di kamera.');
            }

            /* Otomatis kirim sampel ke server */
            await kirimSampel();

        } catch (e) {
            tampilkanGagal(e.message || 'Gagal merekam sampel wajah.');
        } finally {
            sibuk = false;
            siang('siap', 'Siap');
            if (stageEl) stageEl.classList.remove('is-active');
            if (btnDaftar) {
                btnDaftar.disabled = false;
                btnDaftar.innerHTML = '<i class="fa-solid fa-camera"></i> Mulai Daftar Wajah';
            }
        }
    }

    async function kirimSampel() {
        if (!sampelEnroll.length) return;
        siang('jalan', 'Mendaftarkan');
        if (petunjuk) petunjuk.textContent = 'Menyimpan data Face ID ke server...';

        try {
            const descriptors = sampelEnroll.map(function (s) {
                return ScanEngine.toBase64(Float32Array.from(s.descriptor));
            });
            const c = ScanEngine.centroid(sampelEnroll);

            const res = await fetch(STU + 'register-face.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({
                    descriptors: descriptors,
                    centroid: ScanEngine.toBase64(c),
                }),
            });

            const data = await res.json();
            if (res.ok && data.success) {
                scanSukses = true;
                /* Server menutup permintaan pindai ulang dari admin kalau ada,
                 * jadi hasilnya ikut disebut. Tanpa ini siswa melihat
                 * "berhasil" tapi tidak tahu permintaannya sudah selesai atau
                 * masih menggantung di panel admin. */
                const reqTutup = parseInt(data.requestClosed, 10) || 0;
                hasilTampil(
                    true,
                    'Wajah Berhasil Terdaftar!',
                    reqTutup > 0
                        ? 'Data biometrik Face ID Anda telah tersimpan dan permintaan pindai ulang dari admin sudah selesai.'
                        : 'Data biometrik Face ID Anda telah tersimpan. Sekarang Anda bisa melakukan presensi.'
                );
                if (btnTutupHasil) {
                    btnTutupHasil.textContent = 'Selesai (Kembali ke Profil)';
                }
                await ScanEngine.loadGallery(true, P.scope, P.userId);
                setTimeout(function () {
                    window.location.href = '/siswa/profil.php';
                }, 1800);
            } else {
                scanSukses = false;
                hasilTampil(false, 'Pendaftaran Ditolak', data.message || 'Tidak dapat mendaftarkan wajah.');
            }
        } catch (e) {
            scanSukses = false;
            hasilTampil(false, 'Pendaftaran Gagal', e.message || 'Koneksi terputus.');
        }
    }

    /* ----------------------------------------------------------------
     |  Start
     * ---------------------------------------------------------------- */

    async function boot() {
        jalankanJam();

        const isEnrollMode = window.location.search.indexOf('mode=enroll') !== -1;

        if (!window.isSecureContext) {
            var pesanHttps = 'Halaman harus dibuka lewat HTTPS. Buka lewat alamat https://, bukan http:// IP lokal.';
            if (petunjuk) petunjuk.textContent = pesanHttps;
            pengumumanTampil('Bukan HTTPS', pesanHttps, 'gagal');
        }

        if (isEnrollMode) {
            if (btnDaftar) {
                btnDaftar.disabled = true;
                btnDaftar.textContent = 'Menyiapkan Kamera...';
            }
            if (btnMulai) btnMulai.style.display = 'none';
        } else {
            if (btnMulai) {
                btnMulai.disabled = true;
                btnMulai.textContent = 'Menyiapkan Face ID...';
            }
            if (btnDaftar) btnDaftar.style.display = 'none';
        }

        if (typeof window.faceapi === 'undefined') {
            if (petunjuk) petunjuk.textContent = 'Library Face ID gagal dimuat. Periksa koneksi internet.';
            siang('gagal', 'Library gagal');
            return;
        }
        if (typeof ScanEngine === 'undefined') {
            if (petunjuk) petunjuk.textContent = 'Engine Face ID tidak termuat.';
            return;
        }

        ScanEngine.configure({
            apiBase: P.apiBase,
            modelPath: P.modelPath,
            threshold: P.threshold,
            thresholdStrict: P.thresholdStrict,
            scope: P.scope,
            userId: P.userId,
        });

        var kamera = bukaKamera();
        kamera.catch(function () { });

        try {
            await ScanEngine.loadModels(function (nama, p) {
                if (petunjuk) petunjuk.textContent = (isEnrollMode ? 'Menyiapkan Pendaftaran... (' : 'Menyiapkan Face ID... (') + Math.round(p) + '%)';
            });
        } catch (e) {
            console.error('[FaceID] Model load error:', e);
            if (petunjuk) petunjuk.textContent = 'Tidak dapat mengambil model Face ID. Periksa koneksi internet.';
            siang('gagal', 'Model gagal');
            var targetBtn = isEnrollMode ? btnDaftar : btnMulai;
            if (targetBtn) {
                targetBtn.disabled = false;
                targetBtn.innerHTML = '<i class="fa-solid fa-rotate-right"></i> Coba Muat Ulang';
                targetBtn.onclick = function () { window.location.reload(); };
            }
            return;
        }

        try {
            await kamera;
        } catch (e) {
            return; /* bukaKamera sudah menampilkan penjelasannya */
        }

        pompa();
        if (petunjuk) petunjuk.textContent = isEnrollMode
            ? (P.enrollReRegister ? 'Daftar ulang: tahan posisi dan ikuti pengambilan lima sampel.' : 'Pendaftaran awal: tahan posisi dan ikuti pengambilan lima sampel.')
            : 'Posisikan wajah Anda di dalam lingkaran.';

        try {
            await ScanEngine.loadGallery(false, P.scope, P.userId);
        } catch (e) {
            console.warn('[FaceID] Gallery load warning:', e);
        }

        try {
            await muatNonce();
        } catch (e) {
            if (petunjuk) petunjuk.textContent = 'Gagal meminta token scan. Muat ulang halaman.';
            siang('gagal', 'Nonce gagal');
            return;
        }

        ukurGps().then(function (g) {
            if (nonce && nonce.schedule && nonce.schedule.type === 'lainnya' && !isEnrollMode) {
                if (petunjuk) petunjuk.textContent = 'Di luar jam presensi (' + nonce.schedule.masuk + ' - ' + nonce.schedule.pulang + ')';
                return;
            }
            if ((!g || !g.lat || !g.lng) && petunjuk) {
                petunjuk.textContent = pesanGpsGagal();
            }
        }).catch(function (e) {
            if (petunjuk) petunjuk.textContent = 'Gagal mengambil lokasi awal' + (e && e.message ? ' (' + e.message + ')' : '') + '. Tekan tombol untuk mencoba lagi.';
        });

        if (isEnrollMode) {
            if (btnDaftar) {
                btnDaftar.disabled = false;
                btnDaftar.innerHTML = '<i class="fa-solid fa-camera"></i> Mulai Daftar Wajah';
            }
        } else {
            if (btnMulai) {
                btnMulai.disabled = false;
                btnMulai.innerHTML = '<i class="fa-solid fa-expand"></i> Mulai Scan Face ID';
            }
        }
    }

    /* ----------------------------------------------------------------
     |  Pemasangan
     * ---------------------------------------------------------------- */

    if (btnMulai) btnMulai.addEventListener('click', mulaiScan);
    if (btnUlang) {
        btnUlang.addEventListener('click', function () {
            hasilSembunyi();
            btnUlang.hidden = true;
            mulaiScan();
        });
    }
    if (btnTutupHasil) {
        btnTutupHasil.addEventListener('click', function () {
            if (scanSukses) {
                var isEnrollMode = window.location.search.indexOf('mode=enroll') !== -1;
                window.location.href = isEnrollMode ? '/siswa/profil.php' : '/siswa/index.php';
            } else {
                hasilSembunyi();
            }
        });
    }
    if (hasilBackdrop) {
        hasilBackdrop.addEventListener('click', function (e) {
            if (e.target === hasilBackdrop) {
                if (scanSukses) {
                    var isEnrollMode = window.location.search.indexOf('mode=enroll') !== -1;
                    window.location.href = isEnrollMode ? '/siswa/profil.php' : '/siswa/index.php';
                } else {
                    hasilSembunyi();
                }
            }
        });
    }
    if (btnDaftar) btnDaftar.addEventListener('click', daftarWajah);
    if (btnKirim) btnKirim.addEventListener('click', kirimSampel);

    window.addEventListener('beforeunload', tutupKamera);

    /* Tab disembunyikan = kamera tidak terlihat. Memotong stream
     * menghemat baterai, tapi sering memunculkan permintaan izin
     * kamera lagi di beberapa peramban, jadi stream dibiarkan terbuka
     * dan hanya pembacaan yang berhenti. */
    document.addEventListener('visibilitychange', function () {
        if (document.hidden) {
            lastDetect = null;
            petunjuk.textContent = 'Kamera dijeda, kembali ke tab untuk melanjutkan';
        } else {
            petunjuk.textContent = 'Posisikan wajah di dalam bingkai';
            /* Nonce hanya berlaku 2 menit, dan tab yang ditinggal
             * lama hampir pasti sudah Keystonehabis. Segarkan
             * sebelum siswa menekan tombol, kecuali sedang scan. */
            if (!sibuk) {
                muatNonce().catch(function () { });
            }
        }
    });

    boot().catch(function (e) {
        pengumumanTampil('Gagal Memulai', e.message || String(e), 'gagal');
        siang('gagal', 'Gagal');
    });
})();
