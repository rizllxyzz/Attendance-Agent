from openai import OpenAI
import json
import os
from dotenv import load_dotenv

load_dotenv()

BASE_URL = os.getenv("MODEL_BASE_URL")
API_KEY = os.getenv("MODEL_API_KEY")
MODEL = os.getenv("NEMOTRON_MODEL")

ZETARIS_URL = os.getenv("ZETARIS_REST_URL")
ZETARIS_API_KEY = os.getenv("ZETARIS_API_KEY")
ZETARIS_ORG_ID = os.getenv("ZETARIS_ORG_ID")


def run_sql(sql: str, limit: int = 100):
    import uuid
    import requests

    url = f"{ZETARIS_URL.rstrip('/')}/sql-editor/sqls/run"
    headers = {
        "Authorization": f"Bearer {ZETARIS_API_KEY}",
        "X-Org-ID": ZETARIS_ORG_ID,
        "X-Request-ID": str(uuid.uuid4()),
    }
    payload = {
        "queryId": str(uuid.uuid4()),
        "sql": sql,
        "source": "SqlEditor",
        "limit": limit,
    }
    resp = requests.post(url, headers=headers, json=payload, timeout=120)
    resp.raise_for_status()
    return resp.json()


tools = [
    {
        "type": "function",
        "function": {
            "name": "run_sql",
            "description": "Send one SQL statement to Zetaris and return rows.",
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
    client = OpenAI(base_url=BASE_URL, api_key=API_KEY)
    messages = [
        {"role": "system", "content": "You are a minimal agent that uses run_sql to query Zetaris."},
        {"role": "user", "content": "Run: SELECT 1+1 AS result"},
    ]
    resp = client.chat.completions.create(
        model=MODEL, messages=messages, tools=tools, tool_choice="auto"
    )
    msg = resp.choices[0].message
    if msg.tool_calls:
        for tc in msg.tool_calls:
            if tc.function.name == "run_sql":
                args = json.loads(tc.function.arguments)
                res = run_sql(args.get("sql"), args.get("limit", 10))
                messages.append({"role": "assistant", "tool_calls": [tc.model_dump()]})
                messages.append({"role": "tool", "tool_call_id": tc.id, "content": json.dumps(res)})
        resp = client.chat.completions.create(model=MODEL, messages=messages)
        print(resp.choices[0].message.content)
    else:
        print(msg.content)


if __name__ == "__main__":
    main()
