# Open Agent Hackathon 2026 - Agent Scaffold

Agent project for Open Agent Hackathon 2026. Connects to **Zetaris** (Data layer) and **Meterless Nightshift** (Power layer).

## Structure

```
open-agent-hack/
├── .env / .env.example
├── requirements.txt
├── smoke_test.py
├── agent/
│   ├── __init__.py
│   ├── zetaris_client.py    # Zetaris REST client (real, no mock)
│   ├── tools_zetaris.py     # Re-exports from zetaris_client
│   ├── llm.py               # LLM client (Nightshift/Meterless)
│   ├── main.py              # CLI agent runner
│   ├── server.py            # FastAPI server for PHP frontend
│   └── app.py               # Alternative minimal runner
└── README.md
```

## Quick Start

### 1. Install Dependencies
```bash
pip install -r requirements.txt
# Optional (for server.py):
pip install fastapi uvicorn
```

### 2. Configure .env
```bash
copy .env.example .env
# Edit .env with your credentials
```

### 3. Test Connections
```bash
python smoke_test.py
```

### 4. Run Agent (CLI)
```bash
python -m agent.main
# or with a question:
python -m agent.main "Siapa yang belum absen pulang hari ini?"
```

### 5. Run Agent (Server for PHP frontend)
```bash
python -m agent.server
# Server runs on http://localhost:8000
# PHP frontend calls POST /ask with {"question": "..."}
```

## Environment Variables

| Variable | Description | Example |
|---|---|---|
| `ZETARIS_REST_URL` | Zetaris REST API endpoint | `http://localhost:8888/api/v1.0` |
| `ZETARIS_ORG_ID` | Organization ID (usually 1) | `1` |
| `ZETARIS_API_KEY` | Zetaris API key (from web UI) | `your-key` |
| `ZETARIS_BASE_URL` | Zetaris web UI URL (for smoke test) | `http://localhost:3000` |
| `MODEL_BASE_URL` | Nightshift/Meterless base URL | `http://127.0.0.1:8088/v1` |
| `MODEL_API_KEY` | Nightshift runtime token | `your-token` |
| `NEMOTRON_MODEL` | Model name | `meterless/auto` |
| `AGENT_PORT` | Server port (optional) | `8000` |

## Architecture

```
PHP Frontend (Laragon)
    ↓ POST /api/v1/agent/ask.php
Python Agent (server.py:8000)
    ↓ run_sql() → Zetaris REST API
    ↓ chat.completions → Nightshift (Meterless)
    ↓
Zetaris (Data layer) + Nightshift/Nemotron (Power layer)
```

## Hackathon Compliance

- **Data**: Zetaris REST API (`run_sql`) - real calls, no mock
- **Build**: Meterless H-MEM engine (in Node.js agent) + Cursor
- **Power**: Meterless Nightshift as primary AI provider + Nemotron

## Notes

- Never commit `.env` (listed in `.gitignore`)
- `smoke_test.py` verifies all connections before running agent
- `server.py` provides REST API for PHP frontend integration
- All Zetaris calls are real - no mock or fake data
