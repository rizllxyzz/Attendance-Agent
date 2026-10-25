# api/v1/ — Endpoint JSON (authoritative)

> **Ini satu-satunya tempat yang berwenang memutuskan seorang siswa hadir.**
> Semua angka dari browser diperlakukan sebagai klaim yang harus dihitung
> ulang di sini. Kalau kamu menambahkan endpoint baru, ikuti urutan
> pemeriksaan di bawah — jangan skip validasi peran.

## Kenapa endpoint terpisah dari halaman

Token scan hanya berlaku `SCAN_NONCE_TTL` (120 detik). Memuat model
face-api.js butuh 5–15 detik, lebih lama di HP kelas lama. Kalau token
ditanam saat halaman dirender, token bisa kedaluwarsa sebelum siswa menekan
tombol. Karena itu token diminta **tepat sebelum** scan dikirim.

## Endpoint yang ada

| Endpoint | Metode | Peran | Fungsi |
|---|---|---|---|
| `attendance/scan-nonce.php` | POST | siswa | Terbitkan token sekali pakai + pilih challenge acak + kirim data sekolah & ambang. |
| `attendance/scan-check.php` | POST | siswa | **Verifikasi & catat absensi.** Otoritatif. |
| `student/get-descriptors.php` | GET | siswa/admin | Ambil galeri descriptor. `scope=self` untuk siswa, `scope=all` hanya admin. |
| `student/register-face.php` | POST | siswa | Daftarkan wajah: kirim sampel, server hitung centroid, simpan 1 baris. |

Folder `auth/`, `export/`, dan `.gitkeep` lain **kosong** - jangan mencari endpoint di sana. Kode auth/export belum pernah ada di build ini.
Folder `auth/`, `export/`, dan `.gitkeep` lain **kosong** - jangan mencari endpoint di sana. Kode auth/export belum pernah ada di build ini.

## Urutan pemeriksaan di scan-check.php

Berhenti di langkah pertama yang gagal. **Urutan ini disengaja** — jangan
mengacaknya, karena mengubah urutan mengubah perilaku keamanan.

```
1. Metode + Content-Type          (POST, application/json)
2. Sesi siswa aktif               (401 kalau belum masuk / bukan siswa)
3. Rate limit scan                (3 percobaan gagal → terkunci, 429)
4. Token anti-replay              (sekali pakai, 403 kalau dipakai ulang)
5. Validasi bentuk descriptor     (400 kalau bukan 128 float yang wajar)
6. Cocokkan wajah di SERVER       ← authoritative, 422 kalau gagal
7. Verifikasi lokasi di SERVER    ← authoritative, 422 kalau gagal
8. Jadwal absen                   (422 di luar window)
9. Anti duplikat                  (409 kalau sudah absen hari ini)
10. Liveness: jumlah + challenge dikenal (422 kalau kurang / palsu)
11. Tulis attendance + audit_log   (201 kalau semua lolos)
```

### Langkah 6 — pencocokan wajah (authoritative)
Server mengiterasi `face_templates` dengan `is_centroid = 1` dan menghitung
jarak **Euclid** dengan `face_distance()`. Yang dikirim browser **tidak**
dipakai sebagai identitas — hasilnya hanya dihitung di server.

Kalau jarak > `FACE_MATCH_THRESHOLD` → `FACE_NOT_FOUND` (422).
Kalau keyakinan < `FACE_MIN_CONFIDENCE` → `FACE_LOW_CONFIDENCE` (422).
Lantai keyakinan ini menutup celah kembar/wajah sangat mirip yang lolos
secara teknis — justru kasus yang paling memalukan (orang salah dicatat hadir).

### Langkah 7 — lokasi (authoritative)
`verify_location()` di `config/helper.php` adalah sumber kebenaran kode
lokasi. `geoMessage()` di `scan-check.php` memetakan kode ke pesan berbahasa
manusia. **Kalau menambah kode lokasi, perbarui dua tempat.**

### Langkah 10 — liveness
Server membandingkan `liveness.challenges` yang dikirim klien dengan
`LIVENESS_CHALLENGES` di config. Challenge tak dikenal → `LIVENESS_FAKE`.
Klien tidak boleh menentukan challenge sendiri; server yang memilih acak.

## Bentuk payloads

### Ke scan-nonce.php (tanpa body)
Respons `json_ok` berisi: `nonce`, `expiresIn`, `challenges[]`,
`schedule` (jendela + tipe saat ini + `issues`), `liveness` (required +
timeout), `thresholds`, `school` (lat/lng/radius),
`needsMotionPermission` (true di iOS 13+).

### Ke scan-check.php (`application/json`)
```json
{
  "nonce": "<token dari scan-nonce>",
  "descriptor": "<base64 128 float>",
  "gps": { "lat": -6.2, "lng": 106.81, "accuracy": 12, "timestamp": 1750000000000, "...": "..." },
  "liveness": { "ok": true, "challenges": [ {"type": "nod", "...": "..."} ] },
  "fingerprint": "<maks 64 char>"
}
```

Respons sukses `201`: `attendanceId`, `scanType`, `time`, `date`, `isLate`,
`lateMinutes`, `student`, `verification` (face+location+liveness), `message`.

Respons gagal: `json_fail(code, message, status, extra)` dengan
`attemptsLeft` saat relevan.

## Aturan keras
- **Server otoritatif.** Jangan pernah menerima `userId`, `faceDistance`,
  `locationVerdict`, atau `attendanceRecorded` dari klien.
- **Validasi peran sebelum logika bisnis**, bukan sesudahnya.
- **Nonce wajib consume** saat dipakai, walau langkah berikutnya gagal.
- **Prepared statement** untuk semua query. Tidak ada pengecualian.
- **`audit_log()`** untuk setiap penolakan dan setiap pencatatan.
- **Tidak menyimpan foto.** Endpoint ini hanya menerima/keluarkan angka.
- **Tidak mengembalikan descriptor milik orang lain** kepada siswa
  (`scope=self`).

## Catatan `.htaccess` (api/.htaccess)
- Hanya file `.php` boleh diakses; yang lain 403.
- Method selain GET/POST/OPTIONS ditolak.
- `GET /api` diarahkan ke `v1/auth/login.php` (berkas ini belum ada —
  aturan sisa dari versi lama, tidak merusak apa pun).

## Kalau menambah endpoint
1. `declare(strict_types=1);` di baris atas.
2. `require_once __DIR__ . '/../../../auth/session.php';`
3. Cek `$_SERVER['REQUEST_METHOD']` → `json_fail('METHOD_NOT_ALLOWED', ..., 405)`.
4. Cek `current_user()` dan peran → `401`/`403`.
5. Rate limit bila relevan (`throttle()` atau `$_SESSION['scan_attempts']`).
6. Validasi bentuk input.
7. Logika bisnis + `audit_log()`.
8. Balas dengan `json_ok()` / `json_fail()`.
