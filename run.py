"""Entry point for the Open Agent Hackathon 2026 submission.

Runs a FastAPI server exposing:
  GET  /health
  POST /run   { "task": "..." } -> { answer, mode, provider, trace, ... }

Supports SAMPLE_MODE=true to run without live Zetaris/Nightshift credentials.
Writes traces to logs/ (JSONL) and stdout.
"""
from __future__ import annotations

import json
import os
import sys
import uuid
from datetime import datetime, timezone
from pathlib import Path
from typing import Optional

ROOT = Path(__file__).resolve().parent
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

from fastapi import FastAPI, HTTPException
from fastapi.middleware.cors import CORSMiddleware
from pydantic import BaseModel

from app.adapters import nightshift as ns_adapter
from app.adapters import zetaris as z_adapter
from app.engines.scout_intent import classify_intent, route

app = FastAPI(title="Open Agent Hackathon - Attendance Agent")

app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

LOG_DIR = ROOT / "logs"
LOG_DIR.mkdir(exist_ok=True)


class RunInput(BaseModel):
    task: str


def _sample_mode() -> bool:
    return os.getenv("SAMPLE_MODE", "false").lower() in ("1", "true", "yes")


def _log_trace(entry: dict) -> None:
    line = json.dumps(entry, ensure_ascii=False, default=str)
    print(line, flush=True)
    try:
        with open(LOG_DIR / "run.jsonl", "a", encoding="utf-8") as f:
            f.write(line + "\n")
    except Exception:
        pass


@app.get("/health")
def health():
    return {
        "status": "ok",
        "sample_mode": _sample_mode(),
        "zetaris_connected": z_adapter.health_check() if not _sample_mode() else True,
        "model_configured": bool(
            os.getenv("MODEL_BASE_URL") or os.getenv("METERLESS_BASE_URL")
        ),
    }


@app.post("/run")
def run_agent(inp: RunInput):
    if not inp.task or not inp.task.strip():
        raise HTTPException(status_code=400, detail="task is empty")

    run_id = str(uuid.uuid4())
    started = datetime.now(timezone.utc).isoformat()
    trace: list = []

    def step(name: str, action: str, detail: str, status: str = "success", ms: int = 0):
        entry = {
            "timestamp": datetime.now(timezone.utc).isoformat(),
            "run_id": run_id,
            "agent_name": name,
            "action": action,
            "detail": detail,
            "status": status,
            "ms": ms,
        }
        trace.append(entry)
        _log_trace(entry)

    step("Orchestrator", "start", f"Received task: {inp.task[:120]}")

    # Scout Intent Engine (Meterless)
    intent, conf = classify_intent(inp.task)
    handler = route(intent)
    step(
        "ScoutIntentEngine",
        "classify",
        f"intent={intent} confidence={conf:.2f} route={handler}",
    )

    sample_mode = _sample_mode()

    if sample_mode:
        step("Orchestrator", "sample_mode", "Running on cached sample data")
        try:
            with open(ROOT / "data" / "sample_cache.json", encoding="utf-8") as f:
                cached = json.load(f)
        except Exception as e:
            step("Orchestrator", "error", str(e), status="error")
            return {"run_id": run_id, "mode": "sample", "error": str(e), "trace": trace}

        # Real logic on cached data
        students = cached.get("sample_students", [])
        attendance = cached.get("sample_attendance", [])
        late = [a for a in attendance if a.get("is_late")]
        answer = (
            f"[SAMPLE MODE] Intent={intent}. "
            f"Ditemukan {len(students)} siswa sampel, {len(attendance)} record absensi, "
            f"{len(late)} terlambat. Handler yang dipilih: {handler}."
        )
        step("WriterAgent", "synthesize", answer)
        result = {
            "run_id": run_id,
            "mode": "sample",
            "intent": intent,
            "handler": handler,
            "answer": answer,
            "provider": "sample-mode",
            "trace": trace,
            "started_at": started,
        }
        _log_trace({"timestamp": datetime.now(timezone.utc).isoformat(), "run_id": run_id, "agent_name": "Orchestrator", "action": "finish", "status": "success"})
        return result

    # Live mode: try Nightshift + Zetaris
    try:
        client = ns_adapter.get_client()
        model = ns_adapter.get_model()
        step("NightshiftAdapter", "connect", f"model={model}")
    except Exception as e:
        step("NightshiftAdapter", "error", str(e), status="error")
        client = None
        model = None

    z_connected = z_adapter.health_check()
    step("ZetarisAdapter", "health", f"connected={z_connected}", status="success" if z_connected else "error")

    answer = None
    if client is not None:
        try:
            # Use Nightshift for a quick response, grounded in intent
            resp = client.chat.completions.create(
                model=model,
                messages=[
                    {"role": "system", "content": "You are an attendance assistant. Answer concisely in Indonesian."},
                    {"role": "user", "content": inp.task},
                ],
                max_tokens=256,
                temperature=0.4,
            )
            answer = resp.choices[0].message.content
            step("NightshiftAdapter", "chat", "received response", status="success")
        except Exception as e:
            step("NightshiftAdapter", "error", str(e), status="error")

    if answer is None:
        answer = f"[LIVE] Intent={intent}, Zetaris connected={z_connected}. Nightshift unavailable, fallback answer."

    step("WriterAgent", "synthesize", (answer or "")[:200])
    result = {
        "run_id": run_id,
        "mode": "live",
        "intent": intent,
        "handler": handler,
        "answer": answer,
        "provider": "Meterless Nightshift" if client is not None else "fallback",
        "zetaris_connected": z_connected,
        "trace": trace,
        "started_at": started,
    }
    _log_trace({"timestamp": datetime.now(timezone.utc).isoformat(), "run_id": run_id, "agent_name": "Orchestrator", "action": "finish", "status": "success"})
    return result


def main():
    import uvicorn

    host = os.getenv("HOST", "0.0.0.0")
    port = int(os.getenv("PORT", "8000"))
    uvicorn.run("run:app", host=host, port=port, reload=False)


if __name__ == "__main__":
    main()