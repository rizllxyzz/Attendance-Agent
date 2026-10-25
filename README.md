# Attendance Agent - Open Agent Hackathon 2026

AI agent for school attendance (presensi) data, built on **Zetaris** as the data
layer and **Meterless Nightshift** as the primary AI provider.

## Features
- Real `run_sql` calls to Zetaris Data Virtualization (no mocking).
- Scout Intent Engine (Meterless) classifies query intent for routing.
- FastAPI `/run` endpoint (programmatic access via curl).
- `SAMPLE_MODE=true` runs on cached data without live credentials.

## Setup
```bash
python -m venv .venv
# Windows
.venv\Scripts\activate
# macOS/Linux
source .venv/bin/activate

pip install -r requirements.txt
cp .env.example .env   # fill in your Zetaris and Nightshift settings
```

### Zetaris
1. Run Zetaris locally via Docker Desktop (see Participant Setup Guide).
2. Create an API key in the Zetaris web UI (http://localhost:3000).
3. Set `ZETARIS_REST_URL`, `ZETARIS_ORG_ID`, `ZETARIS_API_KEY` in `.env`.

### Meterless Nightshift
1. Install Nightshift desktop app.
2. Add NVIDIA API key in Nightshift Providers tab.
3. Copy Runtime URL + token from Nightshift Console.
4. Set `MODEL_BASE_URL`, `MODEL_API_KEY`, `NEMOTRON_MODEL` in `.env`.

## Run
```bash
# Live mode
python run.py

# Sample mode (no credentials needed)
SAMPLE_MODE=true python run.py
```

Server listens on http://0.0.0.0:8000.

## Drive it (programmatic)
```bash
curl -X POST http://localhost:8000/run \
  -H "Content-Type: application/json" \
  -d @input_examples/example_1.json
```

## Docker
```bash
docker build -t oah-submission .
docker run --rm -p 8000:8000 --env-file .env oah-submission
# Sample mode (no credentials):
docker run --rm -p 8000:8000 -e SAMPLE_MODE=true oah-submission
```

## Tests (Meterless Engine)
```bash
pytest tests/ -q
```

## Sponsor technology
- **Zetaris**: data layer via REST `run_sql` (see `app/adapters/zetaris.py`).
- **Meterless Nightshift**: primary AI provider (OpenAI-compatible), see `app/adapters/nightshift.py`.
- **Meterless Engine**: Scout Intent (`app/engines/scout_intent.py`) classifies query intent for routing.
- **NVIDIA**: Nemotron models routed through Nightshift.

## Traces
Each `/run` writes JSONL traces to `logs/run.jsonl` and stdout.

## Limitations
- Sample mode uses cached data (`data/sample_cache.json`), not live queries.
- Live mode requires Zetaris + Nightshift running locally.
- Scout Intent is rule-based (no LLM), kept lightweight for fast runs.
PY