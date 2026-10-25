"""Adapter for Meterless Nightshift (primary AI provider, OpenAI-compatible)."""
from __future__ import annotations

import os

from openai import OpenAI


def get_client() -> OpenAI:
    base = os.getenv("MODEL_BASE_URL") or os.getenv("METERLESS_BASE_URL")
    key = os.getenv("MODEL_API_KEY") or os.getenv("METERLESS_RUNTIME_TOKEN")
    if not base or not key:
        raise RuntimeError("MODEL_BASE_URL and MODEL_API_KEY (or METERLESS_*) must be set")
    return OpenAI(base_url=base, api_key=key)


def get_model() -> str:
    return os.getenv("NEMOTRON_MODEL") or os.getenv("METERLESS_MODEL") or "meterless/auto"