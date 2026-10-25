# assets/js/ — Logika klien (deteksi & UI)

> Halaman scan hanya menampilkan; **keputusan tetap di server.**
> Semua yang dihitung di sini hanya untuk tampilan dan pengalaman pakai.
> Kalau ragu apakah nilai dari peramban boleh dipercaya, jawabannya: tidak.


## Kenapa face-api.js dari CDN, bukan lokal

Library (1,27 MB) + bobot model (5–6 MB) melebihi batas ukuran berkas per
hosting, dan `face_recognition_model` tidak bisa diperkecil — tanpa itu
aplikasi tidak bisa mengenali wajah sama sekali. Yang dikirim ke CDN hanya
berkas statis pihak ketiga. **Foto dan descriptor wajah tidak pernah ke
sana**; deteksi berjalan di peramban, dan satu-satunya yang dikirim ke
server sekolah adalah descriptor 512 byte milik siswa yang sedang login.

Konstanya: `FACE_API_BASE` / `FACE_MODEL_BASE` di `config/app-config.php`.

## Berkas

| File | Tugas |
|---|---|
| `scan-engine.js` | `ScanEngine` — muat model, deteksi wajah dari video, kumpulkan descriptor, bandingkan dengan galeri, kirim scan. |
| `liveness-detection.js` | `Liveness` — deteksi kedip (wajib) + jalankan challenge acak dari server. |
| `gps-verifier.js` | `GPS_GUARD` — ambil posisi, hitung jarak haversine, cek plausibilitas & anti-fake. |
| `app.js` | Alur UI halaman scan **siswa**. |
| `uji-akurasi.js` | Alur UI halaman uji **admin** (diagnostik, tidak menyentuh presensi). |
| `uji-akurasi-math.js` | Fungsi murni: mean, stdev, persentil, sebaran sampel, saran ambang. |

## Model yang dipakai (nilai ada di scan-engine.js)
- `tiny_face_detector` (189 KB) — untuk potret menghadap kamera hasilnya setara
  dengan `ssd_mobilenetv1` (5,36 MB) tapi 30x lebih kecil.
- `face_landmark_68_tiny` (75 KB) — cukup untuk sudut kepala & kelopak mata
  yang dipakai liveness.

Nama model **sengaja tidak** dideklarasikan di `app-config.php`: konstanta
yang tidak dibaca kode hanya memberi kesan ada tempat mengubah model,
padahal tidak berpengaruh apa pun.

## scan-engine.js — fungsi penting
- `loadModels(onProgress)` / `warmup()` — unduh & panaskan model.
- `openDB()`, `cacheSave()`, `cacheLoad()`, `cacheClear()` — cache descriptor
  di IndexedDB supaya tidak ambil ulang tiap kali.
- `loadGallery(force, scope, userId)` — ambil descriptor dari
  `api/v1/student/get-descriptors.php` (scope dibatasi peran server).
- `setGallery()` / `buildIndex()` — siapkan indeks pencocokan.
- `distanceToRow(query, rowOffset)` / `match(query, limit)` — **jarak EUCLID**,
  harus sama dengan `face_distance()` di PHP.
- `detectFromVideo(video)` — cari wajah pada frame kamera.
- `captureEnrollSamples(video, count, onEach)` — kumpulkan sampel saat daftar.
- `centroid(samples)` — rata-rata semua sampel.
- `toBase64()` / `fromBase64()` — descriptor Float32 ↔ base64.
- `submitScan(payload)` — POST ke `scan-check.php` (termasuk nonce & liveness).
- `configure(opts)` — set ambang & path dari PHP.

## Aturan keras
- **Deskripsi harus mirror rumus PHP.** `distanceToRow()` dan
  `face_distance()` = Euclid. Cosine di satu sisi saja menggeser ambang diam-diam.
- **Jangan pernah menentukan kehadiran di klien.** `match()` hanya untuk
  pratinjau; keputusan ada di `scan-check.php`.
- **Challenge liveness datang dari server.** `runChallenge()` hanya boleh
  menjalankan challenge yang ada di `Liveness.validateServerList()`, yang
  mencocokkan `LIVENESS_CHALLENGES` di config. Jangan menambah challenge
  di satu sisi saja — ketahuan saat runtime.
- **Jalankan kamera + model beriringan** di `boot()`/`bukaKamera()`
  (`Promise.all` atau urutan tanpa await), supaya tidak ada jeda 8 detik.
  Tombol mulai harus tetap disabled sampai model siap.
- **Jangan menulis foto ke disk atau mengirim ke server.**
- **Jangan bocorkan koordinat ke HTML** di halaman scan.

## uji-akurasi.js (admin)
Halaman diagnosa. **Wajib** dibatasi admin di sisi server. Aturan:
- **tidak boleh** menyentuh endpoint presensi;
- **tidak boleh** menyimpan foto;
- hanya menghitung descriptor di memori;
- navigasi harus menyertakan `window.UJI` dengan id, ambang, dan target
  yang dibaca JS.

## uji-akurasi-math.js
Fungsi murni tanpa DOM — mudah diuji. Dipakai untuk statistik sebaran
sampel dan saran rentang ambang. **Tidak boleh** menyentuh jaringan atau
menyimpan apa pun.
