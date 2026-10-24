# main.py: minimal tool-calling loop
import json
import sys
from pathlib import Path

# Support running as script or module
try:
    from .llm import get_client, get_model
    from .tools_zetaris import run_sql
except ImportError:
    # Fallback for `python agent/main.py`
    sys.path.append(str(Path(__file__).resolve().parent.parent))
    from agent.llm import get_client, get_model
    from agent.tools_zetaris import run_sql


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


def main():
    try:
        client = get_client()
        model = get_model()
        messages = [
            {
                "role": "system",
                "content": "You are an agent that can query Zetaris via run_sql. Ground answers in data.",
            },
            {"role": "user", "content": "Show me 1+1 via SQL: SELECT 1+1 AS result"},
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
                        args = json.loads(tc.function.arguments)
                    except Exception:
                        args = {}
                    sql = args.get("sql") or "SELECT 1+1 AS result"
                    limit = args.get("limit", 10)
                    res = run_sql(sql, limit)
                    # Append assistant message with tool_calls
                    try:
                        messages.append(msg.model_dump())
                    except Exception:
                        messages.append(
                            {"role": "assistant", "tool_calls": [tc.model_dump()]}
                        )
                    messages.append(
                        {"role": "tool", "tool_call_id": tc.id, "content": json.dumps(res)}
                    )
            resp2 = client.chat.completions.create(model=model, messages=messages)
            print(resp2.choices[0].message.content)
            return
        else:
            print(msg.content)
            return
    except Exception as e:
        print(f"ERROR: {e}")
        raise


if __name__ == "__main__":
    main()


