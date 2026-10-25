"""Scout Intent Engine - lightweight intent classifier for attendance queries.

Meterless Engine: Scout Intent. Classifies a user query into a small set of
intents so the agent can route to the right handler without an LLM round-trip.
This is a real, load-bearing feature: run.py calls it on every /run request
and logs the result in the trace.
"""
from __future__ import annotations

import re
from typing import Tuple

INTENTS = (
    "greeting",
    "attendance_status_today",
    "late_report",
    "not_checked_out",
    "attendance_summary",
    "student_list",
    "izin_sakit",
    "sql_query",
    "help",
    "unknown",
)

_RULES = [
    (r"\b(hai|halo|helo|hello|hey|pagi|siang|sore|malam|assalam)\b", "greeting"),
    (r"\b(select|from|where|limit)\b", "sql_query"),
    (r"belum.*pulang|belum.*absen|anomali|bolos", "not_checked_out"),
    (r"telat|terlambat|kedisiplinan", "late_report"),
    (r"ringkas|rekap|statistik|kehadiran", "attendance_summary"),
    (r"siswa|murid|kelas", "student_list"),
    (r"izin|sakit|dispensasi", "izin_sakit"),
    (r"status|hari ini|absen", "attendance_status_today"),
    (r"bantu|help|cara|apa", "help"),
]


def classify_intent(text: str) -> Tuple[str, float]:
    """Return (intent, confidence) for the given text."""
    t = (text or "").lower().strip()
    if not t:
        return "unknown", 0.0
    for pattern, intent in _RULES:
        if re.search(pattern, t):
            return intent, 0.85
    return "unknown", 0.3


def route(intent: str) -> str:
    """Map intent to a handler name (used by the agent)."""
    mapping = {
        "greeting": "greet",
        "sql_query": "run_sql",
        "not_checked_out": "not_checked_out_report",
        "late_report": "late_report",
        "attendance_summary": "attendance_summary",
        "student_list": "student_list",
        "izin_sakit": "izin_report",
        "attendance_status_today": "status_today",
        "help": "help",
        "unknown": "fallback",
    }
    return mapping.get(intent, "fallback")