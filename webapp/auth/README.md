# auth/ — Sesi, login, CSRF

> Pondasi yang memisahkan halaman publik dari halaman terlindungi.
> Halaman yang butuh tahu siapa penggunanya me-`require_once` `session.php`.

## Isi

| File | Tugas |
|---|---|
| `session.php` | Fungsi sesi, hak akses, dan CSRF token. Dimuat hampir semua halaman. |
| `login.php` | Form login siswa/guru/admin. POST + CSRF. |
| `logout.php` | Logout. **POST + CSRF saja**; GET ditolak 405. |

## session.php

Fungsi yang dipakai halaman lain:

- `start_session()` — mulai sesi dengan cookie dikeraskan (`httponly`,
  `samesite=Lax`, `secure` bila HTTPS). Idempoten.
- `current_user()` — array pengguna aktif, atau `null`.
- `has_role('admin', 'guru')` — cek peran.
- `require_login('admin')` — paksa masuk + cek peran; kalau tidak,
  redirect ke login atau 403. **Pakai ini di setiap halaman privat.**
- `login_user($user)` — pasang sidik jari akun ke sesi + regenerate id.
- `logout_user()` — hapus sesi penuh.
- `session_age()` / `session_is_old_enough($detik)` — cek usia sesi untuk
  tindakan sensitif (mis. daftar ulang wajah).
- `csrf_token()` / `csrf_check($token)` — token per formulir dengan masa
  berlaku (`CSRF_TOKEN_LIFETIME`).

## Yang terjadi saat login (baris demi baris)
1. Kalau sudah masuk → langsung ke beranda.
2. POST: `csrf_check()` dulu; token salah → pesan kedaluwarsa.
3. Ambil user berdasarkan username (`password_verify`).
4. Balasan **sama persis** untuk "user tidak ada" vs "sandi salah" supaya
   username valid tidak bisa ditebak. Untuk user tidak ada, tetap habiskan
   waktu dengan `password_verify` terhadap hash palsu.
5. Cek `is_active`, lalu `locked_until` (dari percobaan gagal berulang).
6. `password_verify` gagal → naikkan `login_attempts`; kalau sudah
   `LOGIN_MAX_ATTEMPTS`, kunci `LOGIN_LOCKOUT_SECONDS` dan audit.
7. Sukses → `password_needs_rehash` bila perlu, reset `login_attempts`,
   catat `login_success`, `login_user()`, redirect sesuai peran.

`last_login` ditulis memakai `db_now_sql()` — bukan `datetime('now')`
polos, karena itu UTC sedangkan aplikasi memakai Asia/Jakarta.

## Aturan keras
- **Semua halaman privat wajib `require_login(...)` di baris paling atas**,
  sebelum logika bisnis apa pun.
- **Semua form POST wajib `csrf_check()`.**
- **Login GET harus redirect**, tidak pernah menampilkan form lagi.
- **Pesan galat tidak boleh membocorkan apakah username ada.**
- **Tidak ada pendaftaran akun publik.** Akun dibuat admin.
- **Redirect tujuan harus internal** saja (diawali `/`, bukan `//`) supaya
  tidak jadi open redirect.
- Jangan pernah mencetak `$_SESSION` atau `password_hash` ke halaman/log.
