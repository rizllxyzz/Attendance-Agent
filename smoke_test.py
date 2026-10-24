# smoke_test.py: checks your Data and Power layers
import os
import uuid
import sys
from pathlib import Path

# Add project root to path
root = Path(__file__).resolve().parent
sys.path.insert(0, str(root))

import requests
from dotenv import load_dotenv
from openai import OpenAI

load_dotenv()
results = []


def check(name, fn):
    try:
        fn()
        results.append(("PASS", name, ""))
    except Exception as error:
        results.append(("FAIL", name, str(error)[:160]))


def need(*names):
    missing = [n for n in names if not os.environ.get(n)]
    if missing:
        raise AssertionError(f"Missing in .env: {', '.join(missing)}")


def zetaris_web():
    url = os.environ.get("ZETARIS_BASE_URL", "http://localhost:3000")
    r = requests.get(url, timeout=10)
    r.raise_for_status()


def zetaris_sql():
    need("ZETARIS_REST_URL", "ZETARIS_ORG_ID", "ZETARIS_API_KEY")
    url = os.environ["ZETARIS_REST_URL"].rstrip("/") + "/sql-editor/sqls/run"
    headers = {
        "Authorization": "Bearer " + os.environ["ZETARIS_API_KEY"],
        "X-Org-ID": os.environ["ZETARIS_ORG_ID"],
        "X-Request-ID": str(uuid.uuid4()),
    }
    payload = {"queryId": str(uuid.uuid4()), "sql": "SELECT 1 AS test", "source": "SqlEditor", "limit": 1}
    resp = requests.post(url, headers=headers, json=payload, timeout=60)
    resp.raise_for_status()
    data = resp.json()

    # Try to extract value
    if isinstance(data, dict):
        rows = data.get("data") or data.get("rows") or data.get("result")
        if rows and isinstance(rows[0], (list, tuple)):
            assert str(rows[0][0]) == "1", "unexpected reply"
            return
        if rows and isinstance(rows[0], dict):
            vals = list(rows[0].values())
            assert str(vals[0]) == "1", "unexpected reply"
            return
    if isinstance(data, list) and data:
        first = data[0]
        if isinstance(first, (list, tuple)):
            assert str(first[0]) == "1", "unexpected reply"
            return
        if isinstance(first, dict):
            vals = list(first.values())
            assert str(vals[0]) == "1", "unexpected reply"
            return
        assert str(first) == "1", "unexpected reply"
        return
    raise AssertionError("unexpected reply: " + str(data)[:80])


def nemotron():
    need("MODEL_BASE_URL", "MODEL_API_KEY", "NEMOTRON_MODEL")
    client = OpenAI(base_url=os.environ["MODEL_BASE_URL"], api_key=os.environ["MODEL_API_KEY"])
    reply = client.chat.completions.create(
        model=os.environ["NEMOTRON_MODEL"],
        messages=[{"role": "user", "content": "Reply with the single word: ready"}],
        max_tokens=5,
    )
    if not reply.choices:
        raise AssertionError("no reply from the model")


def zetaris_health():
    """Check Zetaris health via client."""
    from agent.zetaris_client import get_client
    client = get_client()
    assert client.health_check(), "Zetaris health check failed"


check("Data: Zetaris web interface", zetaris_web)
check("Data: Zetaris runs SQL over its REST API", zetaris_sql)
check("Data: Zetaris health check (via client)", zetaris_health)
check("Power: Nemotron replies", nemotron)

print("\n=== SMOKE TEST RESULT ===")
for status, name, detail in results:
    print(f"{status} | {name} {detail}")
print("==========================")

# Summary
passed = sum(1 for s, _, _ in results if s == "PASS")
total = len(results)
print(f"\n{passed}/{total} checks passed")
if passed == total:
    print("All systems go! Ready to run agent.")
else:
    print("Some checks failed. Fix before running agent.")
