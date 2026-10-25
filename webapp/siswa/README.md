# siswa/ — Portal Siswa

> Halaman yang dipakai siswa setelah login: beranda, riwayat, statistik,
> pengajuan izin/sakit, dan profil. Admin/guru punya halaman mereka sendiri
> di `admin/`.

## Isi folder

| File | Tugas |
|---|---|
| `_bootstrap.php` | Dimuat paling atas setiap halaman. Sesi, identitas, dan seluruh helper tampilan. **Jangan dipanggil langsung dari peramban** (nama diawali `_`). |
| `index.php` | Beranda. Status absen hari ini, rekap bulan berjalan, aktivitas terbaru, dan tombol scan. |
| `riwayat.php` | Presensi harian per bulan, dengan penyaring bulan dan halaman. |
| `statistik.php` | Tingkat kehadiran, tren 6 bulan, sebaran per hari dalam seminggu. |
| `izin.php` | Formulir pengajuan izin/sakit, daftar pengajuan, dan pembatalan. |
| `profil.php` | Identitas akun, status Face ID, rekap seumur akun, perangkat terdaftar. |
| `scan-absen.php` | Halaman scan absen. **Satu-satunya tempat siswa bisa mendaftarkan wajah.** |

Pendukung: `templates/siswa-head.php` dan `templates/siswa-foot.php`
(kerangka bersama), `assets/css/siswa.css` (seluruh gaya portal).

## Dua tata letak, satu markup

Tampilan desktop dan HP sengaja dibuat berbeda, dipindah hanya lewat CSS:

| | Desktop (≥1024px) | HP (<1024px) |
|---|---|---|
| Navigasi | Sidebar tetap di kiri, 6 menu | Tab bar melayang di bawah + tombol scan tengah |
| Konten | Grid 12 kolom | Satu kolom |
| Riwayat | Tabel dengan kepala kolom | Kartu bertumpuk (`data-label` jadi label sel) |
| Akses profil | Sidebar + tombol keluar | Avatar di app bar → bottom sheet |
| Judul halaman | Toolbar di atas | Kartu `pagehead` di dalam konten |

Table `.dl` tetap satu elemen DOM di keduanya. Di HP, `.dl__head`
disembunyikan dan tiap `.dl__cell` memunculkan label-nya sendiri lewat
`::before { content: attr(data-label) }`, jadi tidak perlu ada markup
tambahan khusus HP.

## Aturan keras

- **`require_once __DIR__ . '/_bootstrap.php'`** di baris paling atas
  setiap halaman. File itu sudah memanggil `require_login('siswa')`, jadi
  halaman tidak perlu memanggilnya lagi.
- **Identitas dibaca dari database, bukan dari `$_SESSION`.** Sesi hanya
  menyimpan `id`, `username`, `nama_lengkap`, `role`, `kelas_id`. NIS/NISN
  tidak ada di sana — membacanya dari sesi akan selalu menghasilkan "-"
  tanpa error, karena kuncinya memang tidak pernah disimpan.
- **Nama sekolah dari `pengaturan.nama_sekolah`, bukan dari
  `lokasi_sekolah.nama`.** Isi tabel lokasi adalah nama gerbang/patokan
  ("Tanjung Juwiring Klaten"), bukan nama sekolah.
- **Semua nilai dari database dan form wajib lewat `esc()`.**
- **Angka kehadiran selalu lewat `rekap_bulan()`.** Dashboard, riwayat,
  dan statistik memanggil fungsi yang sama supaya tidak mungkin
  menampilkan angka berbeda untuk hal yang sama.
- **Rekap bulan berjalan dihitung sampai HARI INI**, bukan sampai akhir
  bulan. Kalau tidak, tanggal 3 akan terbaca "alpa 22 hari".
- **Jangan menulis baris ke `attendance` dari halaman siswa.** Kehadiran
  hanya lahir dari scan. Pengajuan izin ada di tabel terpisah.

## Pengajuan izin / sakit

Tabel `izin_requests` (dibuat otomatis oleh `izin_pastikan_tabel()`, dan
ikut tercantum di `install.php` serta `database.sql`).

- Siswa **mengajukan** → status `menunggu`. Tidak mengubah rekap kehadiran.
- Petugas/wali kelas yang **memutuskan** dari sisi admin. Belum ada panel
  admin untuk itu — sampai panelnya ada, statusnya tetap `menunggu`.
- Siswa boleh **membatalkan** pengajuan yang masih `menunggu`. Syarat
  "masih menunggu" ditulis di dalam `WHERE` `DELETE`, bukan dicek terpisah,
  supaya dua permintaan bersamaan tidak bisa sama-sama menghapusnya.
- Pengajuan yang sudah diputuskan tidak bisa diubah atau dihapus dari
  sisi siswa.

Validasi formulir sudah memakai `csrf_check()` dan menolak tanggal lampau,
rentang lebih dari 30 hari, serta pengajuan yang beririsan dengan
pengajuan menunggu lain.

## scan-absen.php — alur
1. `require_login('siswa', 'guru', 'admin')`.
2. Halaman memanggil `boot()` di `app.js`: buka kamera **dan** unduh model
   secara beriringan, tombol mulai disabled sampai model siap.
3. Minta izin kamera + lokasi. Butuh **HTTPS** (ngrok, atau
   `chrome://flags/#unsafely-treat-insecure-origin-as-secure` di Android).
4. Minta nonce: `POST /api/v1/attendance/scan-nonce.php` → dapat token +
   challenge acak.
5. Liveness: kedip mata (wajib) + penuhi challenge yang diminta server.
6. Kirim: `POST /api/v1/attendance/scan-check.php` (descriptor + GPS +
   liveness + nonce + fingerprint).
7. Tampilkan hasil **dari server** — bukan dari pencocokan lokal.

## Pendaftaran wajah (di halaman scan)
- `daftarWajah()` → kumpulkan sampai `FACE_SAMPLE_COUNT` sampel →
  `kirimSampel()` → `POST /api/v1/student/register-face.php`.
- Server menghitung **centroid** (rata-rata sampel) dan menyimpan **satu
  baris** `face_templates` dengan `is_centroid = 1`.
- Syarat server: sesi cukup tua (`FACE_ENROLL_MIN_SESSION_AGE`), jumlah
  sampel ≤ `FACE_ENROLL_MAX_SAMPLES`, sebaran antar-sampel ≤
  `FACE_ENROLL_MAX_SPREAD`, dan batas daftar ulang harian.
- Descriptor sintetis dari file uji **tidak akan** dikenali sebagai wajah
  asli — enrollment harus lewat kamera sungguhan.

## Yang sengaja tidak ada

- **Tidak ada tombol yang tidak bekerja.** Menu sidebar dan tab bar dibangun
  dari satu daftar yang sama di `templates/siswa-head.php`, dan item
  bertanda `mobile = false` (Profil) otomatis tidak masuk ke tab bar HP.
- **Tidak ada CDN Tailwind.** Gaya portal ada di `assets/css/siswa.css`.
  Tailwind CDN adalah kompilator JIT di sisi klien: menambah satu blok
  `class="..."` berarti seluruh stylesheet diunduh ulang, dan tampilannya
  bergeser setelah hydration.
- **Ikon Font Awesome dimuat dari CDN, dengan alasannya.** Ini satu-satunya
  sumber daya pihak ketiga yang tersisa, dan memang tidak bisa dihilangkan:
  simbol yang dipakai sudah ada di markup. Bedanya dengan Tailwind, berkas
  `all.min.css` itu statis — tidak ada kompilasi di browser, dan versinya
  dikunci di `templates/siswa-head.php` supaya tidak berubah diam-diam.
  Kalau portal ini harus jalan tanpa internet, unduh Font Awesome ke
  `assets/vendor/fontawesome/` dan ganti satu baris `href`; tidak ada
  markup lain yang perlu disentuh. Kotak ikon berukuran tetap, sehingga
  saat stylesheet gagal dimuat layout tetap rapi.
- **Tidak ada font dari Google Fonts.** `index.php` lama memuat Plus Jakarta
  Sans dari CDN; sekarang memakai system font stack, tanpa permintaan
  jaringan tambahan dan tanpa risiko teks tak terlihat saat font gagal
  dimuat.
- **Tidak ada `<img>` untuk avatar.** Inisial dirender sebagai teks, jadi
  tidak ada permintaan gambar yang bisa gagal.

## Sebelum mengubah

Jalankan ini setelah menyentuh halaman mana pun:

```
php -l siswa/index.php
php -l siswa/riwayat.php
php -l siswa/statistik.php
php -l siswa/izin.php
php -l siswa/profil.php
php -l siswa/_bootstrap.php
```
