# config/ — Konfigurasi & aturan inti

> Wajib dibaca sebelum mengubah backend. Hampir semua angka yang bisa diubah
> ada di `app-config.php`, dan semua rumus keamanan ada di `helper.php`.

## Isi

| File | Tugas |
|---|---|
| `app-config.php` | Semua konstanta: ambang wajah, jadwal, GPS, liveness, keamanan. Dipanggil paling awal. |
| `database.php` | Koneksi PDO, helper query (`db_one`, `db_all`, `db_exec`, `db_insert`, `db_insert_unique`), dan waktu (`db_now`, `db_now_sql`). |
| `helper.php` | Rumus dan utilitas: jarak wajah, verifikasi lokasi, nonce, throttle, audit, validasi descriptor. |

## app-config.php

Satu-satunya tempat untuk mengubah angka. **Jangan** menulis angka yang sama
di tempat lain — duplikasi membuat dua sumber kebenaran yang bisa berbeda
pendapat.

### Urutan definisikonstanta penting
PHP mengevaluasi `define()` dari atas ke bawah. Konstanta yang memakai
konstanta lain (`FACE_ENROLL_MAX_SPREAD = FACE_MATCH_STRICT`) **harus ditulis
SETELAH** konstanta yang dipakainya, jika tidak nilainya jadi kosong.

### Kelompok konstanta

**Face recognition**
- `FACE_MATCH_THRESHOLD` `0.55` — jarak Euclid maksimum, keputusan utama
- `FACE_MATCH_STRICT` `0.45` — verifikasi 1:1 saat pendaftaran
- `FACE_MIN_CONFIDENCE` `0.20` — lantai keyakinan
- `FACE_ENROLL_MAX_SPREAD` — diikat ke `FACE_MATCH_STRICT`, sebaran antar-sampel
- `FACE_SAMPLE_COUNT` / `FACE_ENROLL_MAX_SAMPLES` `5`
- `FACE_DESCRIPTOR_SIZE` `128` — dimensi descriptor
- `FACE_CENTROID_ENABLED` `true` — rata-rata semua sampel

**Liveness** — `LIVENESS_CHALLENGES` (6 challenge), `LIVENESS_REQUIRED` `2`,
`LIVENESS_TIMEOUT_MS` `20000`. Daftar challenge di sini adalah **satu-satunya
sumber kebenaran** dan harus identik dengan `Liveness.runChallenge()` di
`assets/js/liveness-detection.js`.

**Jadwal** — `JAM_MASUK_*`, `JAM_PULANG_*`, `TOLERANSI_TELAT_MENIT` `15`.
Jendela masuk dan pulang **tidak boleh tumpang tindih**; ada pemeriksaan
`validate_schedule()` yang melaporkannya.

**Lokasi** — `SEKOLAH_LAT/LNG/RADIUS_M`. Nilai di sini adalah nilai awal;
yang dipakai runtime adalah baris `lokasi_sekolah` dengan `is_active = 1`.

**Keamanan** — `SESSION_LIFETIME` `7200`, `LOGIN_MAX_ATTEMPTS` `5`,
`LOGIN_LOCKOUT_SECONDS` `900`, `MAX_SCAN_ATTEMPTS` `3`, `SCAN_NONCE_TTL` `120`,
`NONCE_MAX_PER_WINDOW` `10` per `NONCE_WINDOW_SEC` `60`.

**GPS guard** (`GPS_GUARD`) — heuristik anti-palsu. **Heuristik, bukan
jaminan**: Geolocation API tidak menyediakan flag mock, jadi aplikasi web
tidak bisa tahu apakah "mock location" Android aktif. Nilai ini menaikkan biaya
manipulasi, tidak menutupnya.

**Sumber model** — `FACE_API_BASE` / `FACE_MODEL_BASE` menunjuk CDN jsDelivr.
Hanya library dan bobot model yang diambil dari sana; foto dan descriptor
wajah tidak pernah dikirim ke sana.

### Yang sengaja TIDAK ada di sini
- Tidak ada konstanta path foto wajah. Tidak ada kode yang menyimpan foto.
- Tidak ada `MODEL_PATH` (lalu dihapus) dan `JWT_SECRET` (pakai
  `API_FINGERPRINT_KEY`).

## database.php

- Driver: **SQLite** (default) atau MySQL. `db()` menyimpan koneksi sekali pakai.
- Semua query lewat **prepared statement** (`db_all`, `db_one`, `db_exec`,
  `db_insert`, `db_insert_unique`). Jangan pernah menyambung string SQL
  dengan input pengguna.
- `db_insert_unique()` dipakai untuk anti-replay:kolom unik ditolak database,
  bukan pengecekan manual yang rentan balapan (race condition).

### Fungsi waktu — keduanya wajib konsisten
- `db_now()` → nilai PHP (`date('Y-m-d H:i:s')`), untuk **bind parameter**.
- `db_now_sql()` → ekspresi SQL, untuk **disisipkan ke teks query**.

`db_now_sql()` **tidak boleh** dipakai sebagai bind parameter — potongan teksnya
akan tersimpan apa adanya di kolom, bukan dieksekusi. Untuk SQLite ia
menambahkan offset `date('P')` supaya jamnya sama dengan `db_now()`
(`datetime('now')` polos itu UTC, sedangkan aplikasi memakai Asia/Jakarta).

## helper.php

Fungsi yang **tidak boleh ditulis ulang tanpa mempertahankan kontrak yang sama**:

| Fungsi | Kontrak |
|---|---|
| `face_distance($a, $b)` | Jarak **Euclid**, panjang harus 128. Sama persis dengan JS. |
| `face_confidence($d, $thr)` | `1 - d/thr`, 0..1, bulat 4 desimal. |
| `descriptor_from_input($raw)` | Validasi **bentuk** descriptor: 128 float, tanpa NaN/Inf, nilai mutlak < 10, variasi wajar. Sumber aturan tunggal, dipakai `scan-check` dan `register-face`. |
| `verify_location($payload, $school)` | Verifikasi lokasi di server. Sumber kebenaran kode lokasi (`MISSING_COORD`, `OUT_OF_RADIUS`, dll). |
| `issue_scan_nonce()` / `verify_scan_nonce()` | Token sekali pakai, TTL 120 dtk, ditandatangani `API_FINGERPRINT_KEY`. |
| `throttle($bucket, $max, $window)` | Rate limit in-memory per bucket. |
| `audit_log($uid, $event, $sev, $details)` | Tulis jejak audit. Panggil untuk setiap kejadian penting. |
| `client_ip()` | Hormati `TRUST_PROXY_HEADERS` (default `false` untuk shared hosting). |
| `attendance_type($clock)` | `masuk` / `pulang` / `null` dari jam server. |
| `validate_schedule()` | Laporkan jendela absen yang tumpang tindih. |
| `json_ok()` / `json_fail()` | Bentuk respons API seragam. |

> **Kode lokasi** di `verify_location()` adalah sumber kebenaran.
> `geoMessage()` di `scan-check.php` memetakan kode itu ke pesan berbahasa
> manusia. Kalau kamu menambah kode lokasi baru, **dua tempat** itu harus
> diperbarui.

## Aturan keras
- **Server selalu otoritatif.** Fungsi-fungsi di sini menghitung ulang klaim
  dari klien; jangan pernah menerima nilai jadi dari peramban.
- **Rumus pencocokan identik dengan JS.** Euclid, bukan cosine.
- **Tidak menyimpan foto.** Tidak ada fungsi di sini yang menulis gambar.
- **Prepared statement selalu.** Tidak ada pengecualian.
- **Angka tidak diduplikasi.** Setiap ambang hanya ada di `app-config.php`.
