# 📦 Setup Guide - Presensi School (Full Stack)

Panduan lengkap instalasi dari nol sampai siap pakai. Cocok untuk pemula.

---

## 📋 Yang Anda Butuhkan

| Kebutuhan | Minimum | Rekomendasi |
|---|---|---|
| OS | Windows 10 / macOS / Linux | Windows 11 |
| RAM | 4 GB | 8 GB |
| Disk | 5 GB free | 10 GB free |
| Internet | Ya (download images) | Stabil |

### Software
- **Docker Desktop** (wajib untuk Zetaris)
- **PHP 8.1+** (Laragon di Windows / XAMPP)
- **MySQL 5.7+** atau MariaDB 10.3+
- **Python 3.10+** (untuk agent)
- **Git** (opsional, untuk clone)

---

## 🚀 Langkah 1: Clone / Download

### Opsi A - Git
```bash
git clone https://github.com/rizllxyzz/Attendance-Agent.git
cd Attendance-Agent
```

### Opsi B - Download ZIP
1. Buka https://github.com/rizllxyzz/Attendance-Agent
2. Klik **Code** → **Download ZIP**
3. Extract ke folder, misal `C:\presensi`

---

## 🗄️ Langkah 2: Setup Database

### Windows (Laragon)
1. Start Laragon → klik **Start All**
2. Buka http://localhost/phpmyadmin
3. Buat database baru: `presensi`
4. Tab **Import** → pilih file `webapp/database.sql` → **Go**

### Manual (MySQL CLI)
```bash
mysql -u root -p -e "CREATE DATABASE presensi;"
mysql -u root -p presensi < webapp/database.sql
```

---

## ⚙️ Langkah 3: Konfigurasi `.env`

```bash
cp .env.example .env
```

Edit `.env` (isi sesuai environment):

```env
# Database
DB_DRIVER=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=presensi
DB_USER=root
DB_PASS=

# Zetaris (isi setelah setup Zetaris)
ZETARIS_REST_URL=http://localhost:8888/api/v1.0
ZETARIS_ORG_ID=1
ZETARIS_API_KEY=your-zetaris-api-key

# Meterless Nightshift (isi setelah setup Nightshift)
MODEL_BASE_URL=http://localhost:8088/v1
MODEL_API_KEY=your-nightshift-token
NEMOTRON_MODEL=meterless/auto

# Mode
SAMPLE_MODE=false
```

---

## 🐳 Langkah 4: Setup Zetaris (Data Layer)

Zetaris virtualisasi database presensi agar agent bisa query via REST.

### 4.1 Install Docker Desktop
- Download: https://www.docker.com/products/docker-desktop
- Install & buka (pastikan running)

### 4.2 Download Zetaris Platform
1. Login ke https://cloud.enterprise.zetaris.com
2. Menu **Run locally** → download `zetaris-platform.zip`
3. Extract ke home folder (misal `C:\Users\Nama\zetaris-platform`)

### 4.3 Login ke Registry
```bash
echo 'REGISTRY_PASSWORD' | docker login zetarishackathon.azurecr.io -u zethackuser3dbe7753d420477084dff345823c738f --password-stdin
```
> Password registry didapat dari halaman download Zetaris Cloud.

### 4.4 Konfigurasi RAM Minimal (untuk PC 8GB)
Edit `zetaris-platform/.env`:
```env
ZSPARK_DRIVER_MEM=1g
ZSPARK_DRIVER_CORES=1
ZSPARK_MASTER_URL=local[1]
ZETARIS_API_MAX_HEAP=512m
```

### 4.5 Jalankan
```bash
cd zetaris-platform
docker compose pull          # ~4 GB, 10-20 menit
docker compose up -d         # 2-5 menit
```

### 4.6 Verifikasi
```bash
docker compose ps --all      # semua harus "healthy" / "Exited (0)"
```
Buka http://localhost:3000 → login `admin@zetaris.com` / `Zetaris1@`

### 4.7 Buat API Key
1. Klik ikon profil (kanan atas) → **API Keys** / **Security**
2. **Create** → beri nama "presensi" → **Copy key** (hanya tampil sekali!)
3. Set di `.env` project: `ZETARIS_API_KEY=your_key`

### 4.8 Test Koneksi
Buka http://localhost/admin/zetaris.php?test=1 → harus "Terhubung".

---

## 🌙 Langkah 5: Setup Meterless Nightshift (AI Provider)

Nightshift = gateway LLM (Nemotron) untuk SmartJournal AI.

### 5.1 Install Nightshift
- Download: https://meterless.ai → pilih Windows
- Install & buka aplikasi desktop

### 5.2 Buat NVIDIA API Key
1. Buka https://build.nvidia.com/models
2. Login → pilih model Nemotron (misal `nvidia/nemotron-3.5-lightning-30b-a3b`)
3. **Generate API Key** → copy

### 5.3 Konfigurasi Nightshift
1. Nightshift → tab **Providers** → pilih **NVIDIA**
2. Paste API key → **Save** → confirm connected
3. Konfigurasi lane: assign Nemotron ke Lane 1
4. Klik **Console** (kanan atas) → copy **Runtime URL** & **API key**

### 5.4 Set di `.env`
```env
MODEL_BASE_URL=http://127.0.0.1:8088/v1
MODEL_API_KEY=your-nightshift-token
NEMOTRON_MODEL=meterless/auto
```

### 5.5 (Opsional) Admin Panel
Buka `/admin/ai-provider.php` → pilih provider → isi key → enable.

---

## 🐍 Langkah 6: Setup Agent (Python)

```bash
cd Attendance-Agent   # atau folder project
python -m venv .venv

# Windows
.venv\Scripts\activate
# macOS/Linux
source .venv/bin/activate

pip install -r requirements.txt
cp .env.example .env   # isi Zetaris + Nightshift
```

### Jalankan
```bash
# Live mode
python run.py

# Sample mode (tanpa kredensial, untuk juri)
SAMPLE_MODE=true python run.py
```

Server: http://localhost:8000

### Test
```bash
curl -X POST http://localhost:8000/run \
  -H "Content-Type: application/json" \
  -d @input_examples/example_1.json
```

---

## 🐳 Langkah 7: Docker (Full Stack)

Jalankan semuanya (MySQL + Web + Agent) dalam satu perintah:

```bash
docker compose up -d --build
```

- Web: http://localhost
- Agent: http://localhost:8000
- DB: localhost:3306

Zetaris & Nightshift tetap jalan di host (host.docker.internal).

### Agent saja (submission test)
```bash
docker build -t oah-submission .
docker run --rm -p 8000:8000 --env-file .env oah-submission

# Sample mode (tanpa kredensial)
docker run --rm -p 8000:8000 -e SAMPLE_MODE=true oah-submission
```

---

## 🧪 Langkah 8: Test Engine (Meterless)
```bash
pytest tests/ -q
# Expected: 5 passed
```

---

## 🔒 Langkah 9: Cek Secret (Keamanan)
```bash
# Pastikan .env tidak ter-commit
git ls-files | grep "\.env$"

# Scan key bocor
grep -RIn --exclude-dir=.git -E "(sk-|nvapi-|akaugpd)" . 2>/dev/null || echo "clean"
```

---

## 🎬 Langkah 10: Demo Video & Slide Deck

### Video (1-4 menit)
1. 0:00-0:20 — Intro + problem
2. 0:20-0:50 — Demo agent `/run` sample mode (curl)
3. 0:50-1:20 — Live mode: query Zetaris (real SQL) + Nightshift response
4. 1:20-1:45 — Scout Intent classification + trace log
5. 1:45-2:00 — Sponsor tech summary

⚠️ **Pastikan tidak ada API key terlihat** di video (cek frame by frame).

### Slide Deck
- Problem & target users
- Solution
- Agent workflow / architecture
- Tech stack
- How Zetaris & Meterless used
- Unique use of sponsor tech
- Product visuals
- Future enhancements

---

## 🆘 Troubleshooting

| Masalah | Solusi |
|---|---|
| Zetaris container unhealthy | Tunggu 3-5 menit, cek `docker compose logs` |
| Port 8000/3000 in use | Ganti port di `.env` atau docker-compose |
| `mysqladmin ping` gagal | Tunggu DB ready, cek password root |
| Agent import error | Pastikan `pip install -r requirements.txt` sukses |
| Nightshift connection refused | Pastikan Nightshift desktop app running |
| Face ID tidak jalan | Butuh HTTPS atau localhost |
| GPS ditolak | Pastikan di area sekolah, GPS menyala |

---

## 📁 Struktur Proyek
```
/
├── run.py                 # Entry point agent
├── app/                   # Core agent logic
│   ├── adapters/          # Zetaris, Nightshift
│   └── engines/           # Scout Intent (Meterless)
├── tests/                 # Engine tests
├── input_examples/        # 3 sample inputs
├── output_examples/       # 3 sample outputs
├── logs/                  # Traces (runtime)
├── data/                  # Cached sample data
├── webapp/                # PHP frontend (admin + siswa)
├── Dockerfile             # Agent container
├── docker-compose.yml     # Full stack
├── .env.example           # Placeholders only
└── README.md
```

---

## 📜 License
MIT - bebas digunakan, dimodifikasi, dan didistribusikan.