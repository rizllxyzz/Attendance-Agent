# 🎬 Naskah Video Demo - Presensi School AI Agent
## Open Agent Hackathon 2026

**Durasi target:** 3 menit 30 detik
**Tone:** Emosional, nyata, menyentuh hati, lalu menunjukkan solusi teknologi
**Audience:** Juri hackathon yang ingin melihat "real work" dan dampak nyata

---

## 🎵 Musik & Visual
- **Intro:** Musik piano lembut + suara ambient sekolah (bel, anak-anak)
- **Problem:** Musik sedikit tegang, slow motion, grayscale
- **Solution:** Musik uplifting, warna cerah, fast-paced
- **Outro:** Musik inspiratif, fade out

---

## 📹 Scene-by-Scene

### SCENE 1: HOOK - "Hari yang Berat" (0:00-0:20)
**Visual:** Montage cepat (fast cut) orang tua di pagi hari, anak-anak berangkat sekolah, guru memegang HP sambil cemas.

**Narasi (voice over, emosional):**
> "Setiap pagi, jutaan orang tua di Indonesia bertanya hal yang sama...
> 'Anak saya sudah sampai sekolah?'
> 'Apakah dia masuk kelas?'
> 'Kenapa dia tidak pulang-pulang?'"

**Teks di layar (besar, dramatis):**
> "15.000.000+ siswa Indonesia"
> "0 sistem yang menjawab orang tua"

**Sound:** detak jantung, lalu fade ke musik tegang

---

### SCENE 2: MASALAH NYATA #1 - "Anak Hilang" (0:20-0:40)
**Visual:** Ibu di rumah, jam 15:30, HP di tangan, wajah cemas. Cut ke guru BK di sekolah, mengecek buku absen manual.

**Narasi:**
> "Jam 3 sore. Ibu Siti menunggu anaknya di gerbang.
> Tapi gerbang sepi.
> Anaknya scan masuk pagi... tapi tidak pernah scan pulang.
> Guru BK buka buku absen manual... 200 halaman, nama anaknya ada di halaman 147.
> 'Dia izin pulang cepat katanya' - tapi tidak ada surat izin.
> Bolos? Kidnap? Atau cuma lupa scan?"

**Teks di layar:**
> "1 siswa hilang = 1 keluarga panik"
> "Manual check: 15-30 menit"

**Sound:** musik tegang, lalu silence 2 detik

---

### SCENE 3: MASALAH NYATA #2 - "Guru Overwhelmed" (0:40-0:55)
**Visual:** Guru di ruang guru, tumpukan kertas, Excel, wajah lelah. Jam dinding menunjukkan 17:00.

**Narasi:**
> "Pak Budi, wali kelas, harus rekap 35 siswa x 20 hari = 700 baris Excel.
> 'Siapa yang terlambat minggu ini?'
> 'Berapa persen kehadiran kelas?'
> Jawabannya: buka Excel, filter, hitung manual... 2 jam kerja.
> Setiap hari. Setiap minggu. Setiap bulan."

**Teks di layar:**
> "2 jam/hari x 365 hari = 730 jam/tahun terbuang"

---

### SCENE 4: MASALAH NYATA #3 - "Orang Tua Buta" (0:55-1:05)
**Visual:** Orang tua di HP, mengetik di grup chat kelas: "Anak saya masuk hari ini?" Tidak ada yang jawab. Guru sibuk.

**Narasi:**
> "Orang tua tidak punya akses. Mereka tanya di grup chat kelas.
> 'Anak saya masuk?'
> 'Pulang jam berapa?'
> Guru tidak bisa jawab semua. Data ada, tapi tidak bisa diakses."

**Teks di layar:**
> "Data ada. Akses tidak."

---

### SCENE 5: SOLUSI - "Presensi School + AI Agent" (1:05-1:20)
**Visual:** Transisi cepat ke tampilan modern, warna cerah, logo Presensi School. Musik uplifting.

**Narasi:**
> "Presensi School mengubah semuanya.
> Absen dengan Face ID + GPS. Data real-time.
> Dan yang paling penting: AI Agent yang bisa menjawab pertanyaan apapun... dalam detik."

**Teks di layar (besar, animasi):**
> "PRESENSI SCHOOL"
> "Absen. Analisis. Jawab. Instan."

---

### SCENE 6: DEMO 1 - "Ibu Siti Bertanya" (1:20-1:40)
**Visual:** Screen recording terminal + browser. Split screen: kiri terminal, kanan browser agent.

**Narasi:**
> "Ibu Siti buka aplikasi, tanya: 'Anak saya sudah pulang?'
> Agent langsung jawab: 'Andi masuk jam 07:12, belum scan pulang.'
> Waktu: 0.3 detik. Bukan 30 menit."

**Demo aksi:**
```
curl -X POST http://localhost:8000/run \
  -d '{"task": "Apakah Andi sudah pulang hari ini?"}'
```

**Response (highlight di layar):**
```json
{
  "answer": "Andi masuk jam 07:12 WIB, belum scan pulang.",
  "intent": "attendance_status_today",
  "trace": [...]
}
```

**Teks di layar:** "0.3 detik vs 30 menit manual"

---

### SCENE 7: DEMO 2 - "Pak Budi Rekap" (1:40-2:00)
**Visual:** Screen recording, guru ketik di agent.

**Narasi:**
> "Pak Budi, wali kelas, tanya: 'Ringkas kehadiran kelas X IPA 1 bulan ini.'
> Agent query Zetaris... 700 baris data diproses dalam 0.5 detik.
> Hasil: 35 siswa, 92% hadir, 3 terlambat, 2 izin, 1 alpa.
> Laporan PDF + Excel langsung bisa diunduh."

**Demo aksi:**
```
curl -X POST http://localhost:8000/run \
  -d '{"task": "tolong susunin laporan bulan ini seluruh kelas"}'
```

**Response (highlight):**
```json
{
  "answer": "Laporan berhasil dibuat...",
  "downloads": {
    "pdf": "http://.../laporan_presensi_2026-10_xxx.pdf",
    "excel": "http://.../laporan_presensi_2026-10_xxx.csv"
  }
}
```

**Teks di layar:** "2 jam → 0.5 detik"

---

### SCENE 8: DEMO 3 - "Scout Intent Engine" (2:00-2:15)
**Visual:** Terminal zoom in, highlight trace log.

**Narasi:**
> "Di balik layar, Scout Intent Engine - Meterless Engine - mengklasifikasi maksud user.
> 'Siapa terlambat?' → late_report.
> 'SELECT * FROM users' → sql_query.
> 'Hai' → greeting.
> Routing otomatis ke handler yang tepat. Tidak ada prompt panjang."

**Demo trace (highlight):**
```json
{"agent_name":"ScoutIntentEngine","action":"classify","detail":"intent=late_report confidence=0.85"}
{"agent_name":"ZetarisAdapter","action":"health","detail":"connected=true"}
{"agent_name":"NightshiftAdapter","action":"chat","detail":"received response"}
```

**Teks di layar:** "Meterless Engine: bekerja real, bukan mock"

---

### SCENE 9: DEMO 4 - "Live SQL" (2:15-2:25)
**Visual:** Terminal, guru ketik SQL langsung.

**Narasi:**
> "Guru juga bisa query SQL langsung ke Zetaris.
> 'SELECT * FROM presensi_db.users LIMIT 5' → data real, instan."

**Teks di layar:** "Zetaris: data layer real, bukan cache"

---

### SCENE 10: IMPACT - "Dampak Nyata" (2:25-2:45)
**Visual:** Montage: ibu tersenyum, guru lega, siswa scan, orang tua cek HP puas. Musik uplifting.

**Narasi:**
> "Dengan Presensi School:
> - Ibu Siti tahu anaknya aman dalam 0.3 detik.
> - Pak Budi rekap 700 baris dalam 0.5 detik.
> - Orang tua bisa cek kehadiran anak kapan saja.
> - Guru BK langsung tahu siapa yang perlu dicari.
> 
> Bukan lagi 'data ada tapi tidak bisa diakses'.
> Sekarang: data ada, agent jawab, masalah terselesaikan."

**Teks di layar (animasi counters):**
> "30 menit → 0.3 detik"
> "2 jam → 0.5 detik"
> "0 akses → real-time"

---

### SCENE 11: SPONSOR TECH (2:45-3:05)
**Visual:** Slide dengan logo sponsor + penjelasan singkat.

**Narasi:**
> "Dibangun dengan sponsor technology:
> - Zetaris sebagai data layer - real SQL queries, bukan mock.
> - Meterless Nightshift sebagai primary AI provider - Nemotron LLM.
> - Scout Intent - Meterless Engine yang klasifikasi maksud user.
> - NVIDIA Nemotron - best use menang DGX Spark.
> 
> Semua real. Semua terverifikasi di trace log."

---

### SCENE 12: OUTRO (3:05-3:20)
**Visual:** Logo Presensi School, link GitHub, QR code.

**Narasi:**
> "Presensi School. Absen. Analisis. Jawab. Instan.
> Coba sendiri di github.com/rizllxyzz/Attendance-Agent.
> Terima kasih."

**Teks di layar:**
> "github.com/rizllxyzz/Attendance-Agent"
> "SAMPLE_MODE=true untuk juri tanpa setup"
> "Open Agent Hackathon 2026"

**Sound:** musik fade out, logo fade

---

## 🎵 Musik & Audio
| Bagian | Musik | Volume |
|---|---|---|
| Intro (0:00-0:20) | Piano lembut + ambient sekolah | 60% |
| Problem (0:20-1:05) | Tegang, slow, minor key | 50% |
| Solution (1:05-1:20) | Uplifting, major key, cepat | 70% |
| Demo (1:20-2:25) | Electronic light, upbeat | 40% (voice dominant) |
| Impact (2:25-2:45) | Inspiratif, crescendo | 65% |
| Outro (2:45-3:20) | Fade out, lembut | 40% |

**Voice over:** Rekam terpisah, jelas, tidak terburu-buru. Gunakan mic yang baik.

---

## 🎥 Tips Recording
1. **Rekam terminal dengan font size 16-18** (zoom in)
2. **Tema terminal gelap** (lebih jelas di video)
3. **Tutup notifikasi** (Focus Assist on Windows)
4. **Gunakan OBS Studio** (gratis, 1080p 30fps)
5. **Rekam dalam 1 take** untuk demo, edit minimal
6. **Jangan tampilkan .env** atau terminal dengan key
7. **Gunakan SAMPLE_MODE=true** untuk demo yang stabil
8. **Cek frame by frame** - pastikan tidak ada API key terlihat

---

## 📋 Checklist Sebelum Upload
- [ ] Durasi 1-4 menit (target 3:20)
- [ ] Menunjukkan sistem benar-benar jalan
- [ ] Menunjukkan Zetaris query (real SQL)
- [ ] Menunjukkan Meterless Engine (Scout Intent)
- [ ] Menunjukkan Nightshift request (LLM call)
- [ ] Menunjukkan report generation (PDF + Excel download)
- [ ] Tidak ada API key terlihat
- [ ] Test link di private browser
- [ ] Upload ke YouTube (Unlisted) atau Google Drive

---

## 🎬 Tools Rekomendasi
| Kebutuhan | Tool | Harga |
|---|---|---|
| Screen record | OBS Studio | Gratis |
| Edit video | DaVinci Resolve / CapCut | Gratis |
| Voice over | Audacity + mic | Gratis |
| Musik | YouTube Audio Library | Gratis |
| Thumbnail | Canva | Gratis |

---

*"Presensi School. Absen. Analisis. Jawab. Instan."*
