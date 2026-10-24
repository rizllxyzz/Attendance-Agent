# zetaris_client.py: Zetaris REST client (real, no mock)
import os
import uuid
import requests
from typing import Any, Dict, List, Optional


class ZetarisClient:
    """Real Zetaris REST client. No mock, no fake data."""

    def __init__(self):
        self.base = os.getenv("ZETARIS_REST_URL", "").rstrip("/")
        self.org_id = os.getenv("ZETARIS_ORG_ID", "1")
        self.api_key = os.getenv("ZETARIS_API_KEY", "")

    def _check_config(self):
        if not self.base:
            raise RuntimeError("ZETARIS_REST_URL is missing from .env")
        if not self.api_key:
            raise RuntimeError("ZETARIS_API_KEY is missing from .env")

    def run_sql(self, sql: str, limit: int = 100) -> List[Dict[str, Any]]:
        """Send one SQL statement to Zetaris and return rows. Real call, no mock."""
        self._check_config()

        url = f"{self.base}/sql-editor/sqls/run"
        headers = {
            "Authorization": f"Bearer {self.api_key}",
            "X-Org-ID": self.org_id,
            "X-Request-ID": str(uuid.uuid4()),
        }
        payload = {
            "queryId": str(uuid.uuid4()),
            "sql": sql,
            "source": "SqlEditor",
            "limit": limit,
        }

        try:
            resp = requests.post(url, headers=headers, json=payload, timeout=120)
            resp.raise_for_status()
        except requests.exceptions.ConnectionError as e:
            raise RuntimeError(f"Cannot connect to Zetaris at {self.base}: {e}")
        except requests.exceptions.Timeout:
            raise RuntimeError(f"Zetaris request timed out (120s): {sql[:80]}")
        except requests.exceptions.HTTPError as e:
            raise RuntimeError(f"Zetaris HTTP error {resp.status_code}: {resp.text[:200]}")

        data = resp.json()

        # Normalize response
        if isinstance(data, dict):
            if "data" in data and isinstance(data["data"], list):
                rows = data["data"]
                cols = data.get("columns") or data.get("columnNames") or []
                if cols and rows and isinstance(rows[0], (list, tuple)):
                    return [dict(zip(cols, r)) for r in rows]
                return rows
            if "rows" in data and isinstance(data["rows"], list):
                return data["rows"]
            if "result" in data:
                res = data["result"]
                if isinstance(res, list):
                    return res
                return [res]
            return [data]

        if isinstance(data, list):
            if data and isinstance(data[0], (list, tuple)):
                return [{"col{}".format(i + 1): v for i, v in enumerate(r)} for r in data]
            return data

        return [data]

    def health_check(self) -> bool:
        """Check if Zetaris is reachable."""
        try:
            self._check_config()
            # Simple query to verify connection
            result = self.run_sql("SELECT 1 AS health_check", limit=1)
            return bool(result)
        except Exception:
            return False


# Singleton
_client = None


def get_client() -> ZetarisClient:
    global _client
    if _client is None:
        _client = ZetarisClient()
    return _client


def run_sql(sql: str, limit: int = 100) -> List[Dict[str, Any]]:
    """Module-level function for convenience."""
    return get_client().run_sql(sql, limit)
