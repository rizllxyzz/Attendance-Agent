# database/ — Penyimpanan data

> Berisi **satu file SQLite**. Tidak ada foto di sini, dan tidak pernah ada.

## Isi

| File | Keterangan |
|---|---|
| `presensi.sqlite` | Database aktif. Berisi akun, kelas, lokasi, descriptor wajah, absensi, audit. |
| `.htaccess` | `Require all denied` — menutup folder ini dari web. |

## Skema (SQLite, aktif)
Referensi MySQL ada di `../database.sql` (untuk hosting yang pakai MySQL).

| Tabel | Isi | Catatan penting |
|---|---|---|
| `users` | akun siswa/guru/admin | `role`, `password_hash`, `login_attempts`, `locked_until`, `last_login`, `kelas_id` |
| `kelas` | daftar kelas | `wali_kelas` → `users.id` |
| `lokasi_sekolah` | titik & radius GPS | baris `is_active = 1` yang dipakai runtime |
| `face_templates` | descriptor wajah | `descriptor` BLOB 512 byte (128 float), `is_centroid = 1` yang dicocokkan |
| `attendance` | catatan absensi | 20 kolom: `face_distance`, `face_confidence`, `gps_verdict`, `gps_score`, `is_late`, dll |
| `scan_nonces` | token sekali pakai | anti-replay; baris dihapus/diaborsi saat dipakai |
| `audit_log` | jejak audit | `event_type`, `severity` (info/warning/critical), `details` |
| `device_log` | sidik jari perangkat | untuk deteksi penyimpangan |
| `pengaturan` | key-value | konfigurasi runtime |

## Yang TIDAK ada di sini (dan tidak boleh ditambahkan)
- **Foto wajah / foto absensi.** Aplikasi sengaja hanya menyimpan descriptor
  128 float. Kalau kamu merasa perlu menyimpan foto, **berhenti dan
  tanyakan ulang** — descriptor sudah cukup untuk pencocokan dan foto
  adalah data pribadi paling sensitif.
- **Kolom JSON mentah berisi koordinat** di luar `attendance` (yang
  sudah punya kolom lat/lng/accuracy sendiri).

## Waktu
Semua timestamp disimpan sebagai teks `Y-m-d H:i:s` dalam zona
`Asia/Jakarta` (`TIMEZONE`). Kolom `created_at`/`used_at`/`seen_at`
menggunakan default CURRENT_TIMESTAMP database (UTC) — itu bawaan SQLite dan
hanya untuk jejak teknis, bukan untuk logika absensi.

## Akses
- **Jangan** pernah mengekspos folder ini lewat web — `.htaccess` sudah
  menutupnya. Jangan dihapus.
- Akses hanya lewat `config/database.php` (`db()`), yang memakai prepared
  statement. Jangan pernah membuat koneksi PDO baru di tempat lain.

## Kalau mengubah skema
1. Backup dulu: salin `presensi.sqlite`.
2. Ubah `../database.sql` (referensi MySQL) **dan** SQLite secara
   bersamaan.
3. Kalau mengubah `FACE_DESCRIPTOR_SIZE`, galeri `face_templates` harus
   **dibangun ulang dari nol** (descriptor lama tidak kompatibel).
