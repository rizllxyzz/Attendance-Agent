# main.py: tool-calling agent loop (Zetaris + Meterless/Nightshift)
import json
import os
import sys
from pathlib import Path

# Support running as script or module
try:
    from .llm import get_client, get_model
    from .tools_zetaris import run_sql
except ImportError:
    root = Path(__file__).resolve().parent.parent
    sys.path.insert(0, str(root))
    from agent.llm import get_client, get_model
    from agent.tools_zetaris import run_sql

tools = [
    {
        "type": "function",
        "function": {
            "name": "run_sql",
            "description": (
                "Run one SQL statement against Zetaris and return rows. "
                "Use this to query attendance/presensi data. "
                "Always use Zetaris Lightning SQL syntax."
            ),
            "parameters": {
                "type": "object",
                "properties": {
                    "sql": {"type": "string", "description": "SQL statement to execute"},
                    "limit": {"type": "integer", "description": "Max rows to return", "default": 100},
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


def main(question: str = None):
    if question is None:
        question = "Show me 1+1 via SQL: SELECT 1+1 AS result"

    try:
        client = get_client()
        model = get_model()
        messages = [
            {"role": "system", "content": SYSTEM_PROMPT},
            {"role": "user", "content": question},
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

                    print(f"[Agent] Running SQL: {sql}")
                    try:
                        res = run_sql(sql, limit)
                        print(f"[Agent] Result: {len(res)} rows")
                    except Exception as e:
                        res = {"error": str(e)}
                        print(f"[Agent] Error: {e}")

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
            print("\n" + "=" * 60)
            print(answer)
            print("=" * 60)
            return answer
        else:
            print(msg.content)
            return msg.content

    except Exception as e:
        print(f"[ERROR] {e}")
        sys.exit(1)


if __name__ == "__main__":
    # Allow passing question as argument
    if len(sys.argv) > 1:
        q = " ".join(sys.argv[1:])
        main(q)
    else:
        main()
