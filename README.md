# 🎓 Presensi School - Smart Attendance System

[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)
[![Python](https://img.shields.io/badge/Python-3.10%2B-blue?logo=python)](https://python.org)
[![Docker](https://img.shields.io/badge/Docker-ready-2496ED?logo=docker)](Dockerfile)
[![Hackathon](https://img.shields.io/badge/Open%20Agent%20Hackathon-2026-orange)](https://hackathon.genai.works)


[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)
[![Python](https://img.shields.io/badge/Python-3.10%2B-blue.svg)](https://python.org)
[![Docker](https://img.shields.io/badge/Docker-ready-2496ED.svg)](Dockerfile)
[![Hackathon](https://img.shields.io/badge/Open%20Agent%20Hackathon-2026-orange.svg)](https://hackathon.genai.works)

> Smart attendance system with **Face ID**, **GPS verification**, and an **AI assistant** (SmartJournal AI).
> Built for the **Open Agent Hackathon 2026**.

**Sponsors:** Zetaris (data layer) · Meterless Nightshift (AI provider) · NVIDIA (Nemotron) · Cursor (optional)

---

## Screenshots

### Admin Dashboard
![Admin Dashboard](https://raw.githubusercontent.com/rizllxyzz/Attendance-Agent/main/assets/admin-dashboard.png)

### SmartJournal AI Assistant
![SmartJournal AI](https://raw.githubusercontent.com/rizllxyzz/Attendance-Agent/main/assets/smartjournal-ai.png)

### Face ID Verification
![Face ID](https://raw.githubusercontent.com/rizllxyzz/Attendance-Agent/main/assets/face-id.png)

### Admin Login Page
![Admin Login](https://raw.githubusercontent.com/rizllxyzz/Attendance-Agent/main/assets/login-admin.png)

### Mobile Responsive View
![Dashboard Mobile](https://raw.githubusercontent.com/rizllxyzz/Attendance-Agent/main/assets/dashboard-mobile.png)

## 📋 Table of Contents
1. [Features](#-features)
2. [Quick Start](#-quick-start-5-menit)
3. [Full Setup](#-full-setup-lengkap)
4. [Docker](#-docker-full-stack)
5. [Agent API](#-agent-api)
6. [Sample Mode](#-sample-mode)
7. [Sponsor Technology](#-sponsor-technology)
8. [Project Structure](#-project-structure)
9. [Troubleshooting](#-troubleshooting)
10. [License](#-license)

---

## ✨ Features
- 👤 **Face ID** enrollment & verification (face-api.js, descriptors only, no photos stored)
- 📍 **GPS radius** verification with anti-spoofing
- 🤖 **SmartJournal AI** assistant (chatbot-style) for attendance queries
- 📊 **Real-time recap** & history per student
- 📝 **Izin/Sakit** requests with approval flow
- 🔐 Admin panel: settings, users, classes, reports, AI provider config
- 🐳 **Docker** full stack (MySQL + PHP + Agent)
- 🤖 **Python agent** with Zetaris + Scout Intent Engine + Nightshift

---

## ⚡ Quick Start (5 Menit) - Sample Mode

Tanpa kredensial, langsung jalan:

```bash
git clone https://github.com/rizllxyzz/Attendance-Agent.git
cd Attendance-Agent
pip install -r requirements.txt
SAMPLE_MODE=true python run.py
```

Test:
```bash
curl -X POST http://localhost:8000/run -H "Content-Type: application/json" -d @input_examples/example_1.json
```

---

## 📦 Full Setup (Lengkap)

Panduan detail ada di **[SETUP.md](SETUP.md)**. Ringkasnya:

### 1. Database
```bash
mysql -u root -p -e "CREATE DATABASE presensi;"
mysql -u root -p presensi < webapp/database.sql
```

### 2. Konfigurasi `.env`
```bash
cp .env.example .env
# Edit: DB_*, ZETARIS_*, MODEL_*
```

### 3. Zetaris (Data Layer)
```bash
cd zetaris-platform
docker compose pull && docker compose up -d
# Buka http://localhost:3000 -> buat API key -> set ZETARIS_API_KEY
```

### 4. Meterless Nightshift (AI Provider)
- Install Nightshift desktop app
- Add NVIDIA API key di Providers
- Copy Runtime URL + token -> set `MODEL_BASE_URL`, `MODEL_API_KEY`

### 5. Run Web App
Start Laragon/XAMPP → buka http://localhost

### 6. Run Agent
```bash
python run.py          # live
SAMPLE_MODE=true python run.py  # sample
```

---

## 🐳 Docker (Full Stack)

```bash
docker compose up -d --build
```

| Service | URL |
|---|---|
| Web (PHP) | http://localhost |
| Agent | http://localhost:8000 |
| MySQL | localhost:3306 |

Zetaris & Nightshift tetap di host (`host.docker.internal`).

### Agent saja (submission test)
```bash
docker build -t oah-submission .
docker run --rm -p 8000:8000 --env-file .env oah-submission
# Sample mode
docker run --rm -p 8000:8000 -e SAMPLE_MODE=true oah-submission
```

---

## 🤖 Agent API

```bash
curl -X POST http://localhost:8000/run \
  -H "Content-Type: application/json" \
  -d '{"task": "Siapa yang terlambat hari ini?"}'
```

Response:
```json
{
  "run_id": "...",
  "mode": "sample",
  "intent": "late_report",
  "handler": "late_report",
  "answer": "...",
  "provider": "sample-mode",
  "trace": [...]
}
```

Endpoints:
- `GET /health` - status
- `POST /run` - run agent

---

## 🧪 Sample Mode

`SAMPLE_MODE=true` menjalankan agent pada data cache (`data/sample_cache.json`) tanpa kredensial live. Tetap menjalankan logika real (Scout Intent + Writer), bukan replay output.

Untuk juri tanpa setup Zetaris/Nightshift:
```bash
docker run --rm -p 8000:8000 -e SAMPLE_MODE=true oah-submission
curl -X POST http://localhost:8000/run -H "Content-Type: application/json" -d @input_examples/example_1.json
```

---

## 🏆 Sponsor Technology

| Sponsor | Penggunaan |
|---|---|
| **Zetaris** | Data layer. Agent query real attendance via REST `run_sql`. Lihat `config/zetaris.php` & `app/adapters/zetaris.py` |
| **Meterless Nightshift** | Primary AI provider (OpenAI-compatible). Lihat `app/adapters/nightshift.py` & `/admin/ai-provider.php` |
| **Meterless Engine** | Scout Intent - klasifikasi maksud user untuk routing (`app/engines/scout_intent.py`) |
| **NVIDIA** | Nemotron models via Nightshift (best use → DGX Spark) |
| **Cursor** | Opsional (boleh pakai tool AI coding lain) |

---

## 📁 Project Structure

```
/
├── run.py                  # Entry point agent
├── app/                    # Core agent logic
│   ├── adapters/           # Zetaris, Nightshift
│   └── engines/            # Scout Intent (Meterless)
├── tests/                  # Engine tests (pytest)
├── input_examples/         # 3 sample inputs
├── output_examples/        # 3 sample outputs
├── logs/                   # Traces (runtime, JSONL)
├── data/                   # Cached sample data
├── webapp/                 # PHP frontend (admin + siswa)
├── Dockerfile              # Agent container
├── docker-compose.yml      # Full stack
├── .env.example            # Placeholders only
├── SETUP.md                # Panduan lengkap
└── README.md
```

---

## 🧪 Tests

```bash
pytest tests/ -q
# Expected: 5 passed
```

---

## 🔒 Security

- `.env` (berisi key) **tidak** di-commit (ada di `.gitignore`)
- `.env.example` hanya placeholder
- Tidak ada API key di source code, README, atau logs
- Scan: `grep -RIn --exclude-dir=.git -E "(sk-|nvapi-|akaugpd)" .`

---

## 🆘 Troubleshooting

| Masalah | Solusi |
|---|---|
| Zetaris unhealthy | Tunggu 3-5 menit, cek `docker compose logs` |
| Port in use | Ganti port di `.env` |
| Agent import error | `pip install -r requirements.txt` |
| Nightshift refused | Pastikan Nightshift app running |
| Face ID gagal | Butuh HTTPS/localhost |
| GPS ditolak | Pastikan di area sekolah |

Detail lengkap: **[SETUP.md](SETUP.md)**

---

## 📥 Download

- **Repo**: https://github.com/rizllxyzz/Attendance-Agent
- **ZIP Release**: https://github.com/rizllxyzz/Attendance-Agent/releases/latest
- **Panduan**: [SETUP.md](SETUP.md)

---

## 🧾 Agent Trace & Logs

Every `/run` response includes a `trace` array showing how the agent reached its answer:
plan → retrieve → evaluate → revise → report (with timing in ms).

Runtime traces are also written to `logs/` as JSONL (gitignored). Three sample
input/output pairs are available in `input_examples/` and `output_examples/`.

## 📜 License

MIT - bebas digunakan, dimodifikasi, dan didistribusikan.

---

## 👤 Author

**rizllxyzz** - Open Agent Hackathon 2026 participant.