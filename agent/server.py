# server.py: FastAPI server for PHP frontend to call the agent
# Install: pip install fastapi uvicorn
import json
import os
import sys
from pathlib import Path
from typing import Optional

# Support running as script or module
try:
    from .llm import get_client, get_model
    from .tools_zetaris import run_sql
    from .zetaris_client import get_client as get_zetaris_client
except ImportError:
    root = Path(__file__).resolve().parent.parent
    sys.path.insert(0, str(root))
    from agent.llm import get_client, get_model
    from agent.tools_zetaris import run_sql
    from agent.zetaris_client import get_client as get_zetaris_client

from fastapi import FastAPI, HTTPException
from fastapi.middleware.cors import CORSMiddleware
from pydantic import BaseModel

app = FastAPI(title="Open Agent Hack - Attendance Agent")

# CORS for PHP frontend
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)


class AskRequest(BaseModel):
    question: str


class AskResponse(BaseModel):
    answer: str
    mode: str = "model"
    provider: Optional[str] = None
    strategy: str = ""
    provenance: list = []
    iterations: int = 1
    sufficient: bool = True
    trace: list = []


tools = [
    {
        "type": "function",
        "function": {
            "name": "run_sql",
            "description": "Run SQL against Zetaris and return results",
            "parameters": {
                "type": "object",
                "properties": {
                    "sql": {"type": "string"},
                    "limit": {"type": "integer"},
                },
                "required": ["sql"],
            },
        },
    }
]

SYSTEM_PROMPT = (
    "You are an AI agent for a school attendance (presensi) system. "
    "You have access to Zetaris via run_sql to query real attendance data. "
    "Always ground your answers in actual query results. "
    "When asked about attendance, first query the data, then answer based on results. "
    "Never make up data. If a query fails, report the error."
)


@app.get("/health")
def health():
    zc = get_zetaris_client()
    return {
        "status": "ok",
        "zetaris_connected": zc.health_check(),
        "model_configured": bool(os.getenv("MODEL_BASE_URL") and os.getenv("MODEL_API_KEY")),
    }


@app.post("/ask", response_model=AskResponse)
def ask(req: AskRequest):
    if not req.question or not req.question.strip():
        raise HTTPException(status_code=400, detail="Question is empty")

    try:
        client = get_client()
        model = get_model()
        messages = [
            {"role": "system", "content": SYSTEM_PROMPT},
            {"role": "user", "content": req.question},
        ]

        resp = client.chat.completions.create(
            model=model,
            messages=messages,
            tools=tools,
            tool_choice="auto",
        )

        choice = resp.choices[0]
        msg = choice.message

        if getattr(msg, "tool_calls", None):
            for tc in msg.tool_calls:
                if tc.function.name == "run_sql":
                    try:
                        args = json.loads(tc.function.arguments or "{}")
                    except json.JSONDecodeError:
                        args = {}

                    sql = args.get("sql") or "SELECT 1+1 AS result"
                    limit = args.get("limit", 100)

                    try:
                        res = run_sql(sql, limit)
                    except Exception as e:
                        res = {"error": str(e)}

                    try:
                        messages.append(msg.model_dump())
                    except Exception:
                        messages.append(
                            {"role": "assistant", "tool_calls": [tc.model_dump()]}
                        )

                    messages.append(
                        {
                            "role": "tool",
                            "tool_call_id": tc.id,
                            "content": json.dumps(res, ensure_ascii=False, default=str),
                        }
                    )

            resp_final = client.chat.completions.create(model=model, messages=messages)
            answer = resp_final.choices[0].message.content

            return AskResponse(
                answer=answer,
                mode="model",
                provider=model,
                strategy="zetaris+nightshift",
                sufficient=True,
            )
        else:
            return AskResponse(
                answer=msg.content or "",
                mode="retrieval-only",
                sufficient=True,
            )

    except Exception as e:
        raise HTTPException(status_code=500, detail=str(e))


if __name__ == "__main__":
    import uvicorn

    port = int(os.getenv("AGENT_PORT", "8000"))
    uvicorn.run(app, host="0.0.0.0", port=port)
