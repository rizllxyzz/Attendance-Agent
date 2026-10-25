"""Adapter for Zetaris Data Virtualization REST API."""
from __future__ import annotations

import os
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

from agent.zetaris_client import get_client as _get_client, ZetarisClient


def get_client() -> ZetarisClient:
    return _get_client()


def run_sql(sql: str, limit: int = 100):
    return get_client().run_sql(sql, limit)


def health_check() -> bool:
    try:
        return get_client().health_check()
    except Exception:
        return False