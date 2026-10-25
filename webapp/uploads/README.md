# uploads/ — Pagar (tidak dipakai)

> **Tidak ada foto yang disimpan di sini, dan tidak seharusnya ada.**
> Folder ini hanya mempertahankan `.htaccess` sebagai lapisan keamanan
> kalau suatu saat ada berkas media.

## Kenapa masih ada

Aplikasi memindai wajah dan mengubahnya menjadi descriptor 128 float
(angka). Foto tidak pernah ditulis ke disk atau server, jadi folder ini
tidak punya isi nyata. Subfolder lama (`ref-faces/`, `temp-captures/`)
dibiarkan kosong sebagai jejak desain lama.

**Alasan tidak menyimpan foto:** foto wajah adalah data pribadi paling
sensitif dan tidak dibutuhkan untuk pencocokan. Menyimpannya menambah
risiko tanpa manfaat.

## Isi

| Item | Keterangan |
|---|---|
| `.htaccess` | Melarang eksekusi skrip apa pun; hanya gambar yang boleh disajikan. |
| `ref-faces/` | Kosong. Tidak dipakai. |
| `temp-captures/` | Kosong. Tidak dipakai. |

## Aturan keras
- **Jangan hapus `uploads/.htaccess`.** Itu lapisan yang menolak eksekusi
  `.php`/`.sh` bila suatu saat ada berkas di sini.
- **Jangan tambahkan kode yang menulis gambar** hasil kamera ke sini.
  Descriptor sudah cukup; kalau butuh pratinjau, tampilkan dari kamera
  saat itu juga di browser, jangan disimpan.
- Jangan mengaktifkan `Options Indexes` — listing folder harus tetap mati.
