# tools_zetaris.py: Zetaris REST helper
import os
import uuid
import requests
from typing import Any, Dict, List, Optional


class ZetarisClient:
    def __init__(self):
        self.base = os.getenv('ZETARIS_REST_URL', '').rstrip('/')
        self.org_id = os.getenv('ZETARIS_ORG_ID', '1')
        self.api_key = os.getenv('ZETARIS_API_KEY', '')

    def run_sql(self, sql: str, limit: int = 100) -> List[Dict[str, Any]]:
        if not self.base or not self.api_key:
            raise RuntimeError('ZETARIS_REST_URL/ZETARIS_API_KEY missing')
        url = f'{self.base}/sql-editor/sqls/run'
        headers = {
            'Authorization': f'Bearer {self.api_key}',
            'X-Org-ID': self.org_id,
            'X-Request-ID': str(uuid.uuid4()),
        }
        payload = {
            'queryId': str(uuid.uuid4()),
            'sql': sql,
            'source': 'SqlEditor',
            'limit': limit,
        }
        resp = requests.post(url, headers=headers, json=payload, timeout=120)
        resp.raise_for_status()
        data = resp.json()
        rows = data.get('data') or []
        cols = data.get('columns') or []
        if cols:
            return [dict(zip(cols, r)) for r in rows]
        return rows


def run_sql(sql: str, limit: int = 100) -> List[Dict[str, Any]]:
    return ZetarisClient().run_sql(sql, limit)

