# admin/ - Panel admin & guru

> Halaman untuk admin dan guru. **Tidak ada halaman siswa di sini.**
> (role siswa harus selalu 403.)

## Isi

| File | Peran | Tugas |
|---|---|---|
| `index.php` | admin, guru | Dashboard: angka kehadiran, izin, wajah, aktivitas. |
| `kelas.php` | admin, guru | Daftar & filter kelas. Tambah/edit/hapus hanya admin. |
| `siswa.php` | admin, guru | Daftar & filter siswa, status akun, reset sandi, hapus. |
| `akun.php` | **admin saja** | Kelola akun staf: guru & administrator. |
| `impor.php` | **admin saja** | Impor massal siswa + kelas dari satu berkas CSV. |
| `izin.php` | admin, guru | Antrean pengajuan izin, approve/reject dengan catatan. |
| `absensi.php` | admin, guru | Rekap harian, riwayat scan, daftar belum absen, ekspor CSV. |
| `wajah.php` | admin, guru | Status Face ID siswa, minta pindaian ulang, hapus template. |
| `backup.php` | **admin saja** | Backup/pulihkan database & ekspor CSV. |
| `uji-akurasi.php` | **admin saja** | Diagnosa akurasi pencocokan wajah (tidak tampil di menu). |
| `pengaturan.php` | admin, guru | Nama sekolah + lokasi patokan GPS (titik, radius). |
| `_bootstrap.php` | - | Sesi, role gate, menu, flash, CSRF, helper tampilan. |
| `_data.php` | - | Semua query. Halaman tidak boleh menulis SQL sendiri. |

Siswa **tidak** punya halaman di sini. Portal siswa ada di `/siswa/`.

## Pola halaman

Semua halaman wajib punya urutan ini:

```php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_data.php';
// ... proses POST ...
$pageKey = 'siswa'; $pageTitle = 'Siswa'; $pageSub = '...';
require __DIR__ . '/../templates/admin-head.php';
// ... HTML ...
require __DIR__ . '/../templates/admin-foot.php';
```

- `$isAdmin` sudah disediakan `_bootstrap.php`; jangan menentukan ulang peran.
- Tutup file **tanpa** `?>` supaya tidak ada spasi yang mendahului header CSV.
- Tautan di sidebar otomatis muncul kalau file-nya benar-benar ada, jadi
  halaman baru tidak perlu diedit `_bootstrap.php` kecuali ingin masuk tab HP.

## Hak akses

- Semua mutasi melewati `admin_hanya_post()` (method + CSRF) lalu
  `admin_hanya_admin()` atau `$isAdmin` untuk pemeriksaan yang lebih spesifik.
- Guru: **read-only** di seluruh halaman. Tombol, form, dan JavaScript
  mutasi disembunyikan; penjaga ada dua lapis (UI + server).
- Tindakan yang mengubah identitas/kelulusan siswa (hapus siswa, hapus
  template wajah, koreksi status absen, nama sekolah, lokasi patokan)
  selalu `audit_log()`.

## Halaman per halaman

### kelas.php
Nilai yang tidak lengkap ditolak per field dan isian disimpan di
`$_SESSION['admin_form_kelas']`. Setelah gagal, halaman diarahkan dengan
`admin_kembali_query()` supaya drawer yang sama terbuka lagi, bukan daftar
kosong. Mengubah `wali_kelas` untuk kelas yang sudah ada tidak boleh
menghapus hak akses guru lain.

### siswa.php
`admin_hitung_siswa()` menghitung total dengan filter yang sama persis
dengan daftarnya. `admin_jumlah_siswa()` hanya bisa menyaring
aktif/semua, jadi memakainya di sini membuat paginator salah untuk filter
`nonaktif`. Konfirmasi sandi kosong dianggap "tidak diisi", bukan error.

### akun.php
Akun guru/admin sebelumnya hanya bisa dibuat lewat SQL manual; halaman ini
menutup celah itu. Penjaga penting: administrator terakhir tidak boleh
dihapus, dinonaktifkan, atau diturunkan jadi guru, dan akun yang masih
menjadi `wali_kelas` di kelas mana pun tidak boleh dihapus (ganti walinya
dulu di `kelas.php`). Akun sendiri juga tidak bisa dinonaktifkan/dihapus.

### absensi.php
Angka "izin" subtracting hadir: siswa yang izin disetujui **tetap datang**
dihitung sebagai hadir, tidak jadi alpa. KPI `belum` =
`total - hadir - izin`, dan tab "belum" memakai definisi yang sama supaya
angka di tab tidak melebihi angka besar di atasnya. Izin yang masih
menunggu tetap tampil sebagai belum absen.

### wajah.php
Admin **meminta**, siswa yang **mendaftarkan** lewat `/register-face.php`.
Minta pindaian ulang sekaligus menghapus template lama, supaya enrollment
baru tidak dibandingkan dengan descriptor yang sudah tidak berlaku.
Tabel `face_requests` dibuat on-demand lewat `admin_tabel_pakai()`; kalau
gagal dibuat, halaman tetap tampil dengan fiturnya dimatikan.

Antrean diurutkan **paling lama menunggu lebih dulu**
(`tanggal_mulai ASC, id ASC`). Permintaan yang lebih baru lebihALI belum
dilayani tidak boleh mendahului yang lebih lama.

Permintaan ditutup oleh **siswa**, bukan oleh admin: `register-face.php`
menandai `menunggu` menjadi `selesai` setelah enrollment benar-benar
berhasil, karena sampai saat itu siswa masih tidak bisa absen dengan wajah.
Notifikasi penantian tampil di beranda dan profil siswa
(`wajah_permintaan_untuk()` di `siswa/_bootstrap.php`).

### pengaturan.php
Bisa diubah: `pengaturan.nama_sekolah` dan **lokasi patokan GPS**
(latitude, longitude, radius meter) di tabel `lokasi_sekolah`.

Runtime memakai baris `lokasi_sekolah` pertama yang `is_active = 1`
(`admin_lokasi_aktif()` di `_data.php`); konstanta `SEKOLAH_LAT/LNG/RADIUS_M`
hanya cadangan kalau tabel kosong. Koordinat ditulis sebagai teks supaya
presisi desimal tidak hilang, dan `parse_koordinat()` menormalkan koma
menjadi titik. Radius dibatasi 50-20.000 meter, dan titik `0,0` ditolak
karena itu koordinat default, bukan lokasi sekolah.

Ambang Face ID, toleransi GPS, jam sekolah, dan kebijakan keamanan tetap
dibaca dari `config/app-config.php` dan ditampilkan baca saja: mengubahnya
berarti menulis berkas PHP di server.

Alat "cek jarak" di halaman ini hanya membantu admin memahami radiusnya;
itu bukan penentu. Verifikasi jarak tetap dihitung server.

## Aturan keras
- **Jangan menulis SQL di halaman.** Semua query lewat `_data.php` dengan
  prepared statement.
- **Jangan bocorkan descriptor siswa** ke halaman yang tidak perlu
  (hanya admin yang boleh, siswa hanya miliknya sendiri).
- **Jalur penolakan wajib diuji lewat HTTP sungguhan.** Endpoint yang membaca
  `php://input` (misalnya `api/v1/student/register-face.php`) selalu melihat
  body kosong kalau di-`include` dari CLI, jadi `include` saja hanya bisa
  menguji jalur penolakan. Pakai `php -S` + router yang mengarahkan database
  ke salinan uji.
- **Halaman diagnosa tidak boleh** menyentuh endpoint presensi
  (`scan-check.php` / `scan-nonce.php`), menyimpan foto, atau menulis
  catatan `attendance`. Fungsinya mengukur, bukan mencatat.
- `uji-akurasi.php` memanggil `require_login('admin')` di baris paling atas;
  role `guru` tidak boleh membuka halaman ini.
- Setiap edit wajib diuji ulang: lint PHP, render kedua role, dan regression
  POST. Font Awesome lokal, tanpa CDN.
- **Nama sekolah harus dibaca dari `pengaturan`, bukan `SCHOOL_NAME`.**
  Empat halaman berdiri sendiri dan melakukan query-nya masing-masing:
  `auth/login.php`, `siswa/_bootstrap.php`, `admin/_bootstrap.php`, dan
  `admin/uji-akurasi.php`. Kalau satu lupa, sekolah yang sudah mengganti
  nama melihat dua nama berbeda di dua halaman.
  Nama patokan di `lokasi_sekolah.nama` adalah data terpisah dan boleh
  berbeda, jadi jangan ikut dianggap bocor.

## Halaman yang TIDAK ada (jangan cari-cari)
`kelola-siswa.php` · `register-wajah-siswa.php` · `kelola-kelas.php` ·
`rekap-absensi.php` · `pengaturan-lokasi.php` · `log-audit.php`
— semua file ini tidak ada di build ini. Data siswa & lokasi dikelola lewat
database (`users`, `kelas`, `lokasi_sekolah`) atau lewat API yang ada.
