# assets/css/ — Gaya tampilan

> Hanya CSS. Tidak ada logika dan tidak ada rahasia di sini.

## Isi

| File | Dipakai di |
|---|---|
| `app.css` | Halaman login (`auth/login.php`) dan dashboard admin. |
| `camera-scan.css` | Halaman scan siswa (`siswa/scan-absen.php`) — UI kamera, overlay wajah, progres. |
| `uji-akurasi.css` | Halaman diagnosa admin (`admin/uji-akurasi.php`). |

## Konvensi
- Halaman scan mengutamakan tampilan **HP**: satu kolom, tombol besar,
  aman untuk `viewport-fit=cover` (ponsel dengan notch).
- Kelas status (berhasil/gagal/progres) diubah dari JS; jangan
  menyembunyikan elemen dengan mengganti `style` langsung — pakai kelas.
- Jangan menaruh nilai konfigurasi (ambang, radius, jadwal) di CSS.

## Aturan keras
- **Halaman `uji-akurasi.css` tidak boleh menyembunyikan elemen uji** yang
  dibutuhkan skrip — kalau tombol tidak terlihat, skrip tetap harus bisa
  menemukannya di DOM (jangan lepas dari dokumen).
- **Jangan mengubah nama kelas** yang dipakai skrip uji tanpa memperbarui
  skripnya; suite uji memeriksa keberadaan elemen UI.
- Semua halaman harus tetap ringan; hindari menambah framework CSS berat
  menambah framework CSS berat hanya untuk beberapa gaya.
