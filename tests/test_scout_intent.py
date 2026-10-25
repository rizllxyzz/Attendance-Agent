"""Test for the Scout Intent Meterless Engine."""
from app.engines.scout_intent import classify_intent, route


def test_greeting():
    intent, conf = classify_intent("hai")
    assert intent == "greeting"
    assert conf > 0.5


def test_sql_query():
    intent, _ = classify_intent("SELECT * FROM presensi_db.users LIMIT 5")
    assert intent == "sql_query"


def test_late_report():
    intent, _ = classify_intent("siapa yang terlambat hari ini")
    assert intent == "late_report"


def test_route_known():
    assert route("greeting") == "greet"
    assert route("unknown") == "fallback"


def test_report():
    intent, _ = classify_intent("tolong susunin laporan bulan ini")
    assert intent == "report"


def test_empty():
    intent, conf = classify_intent("")
    assert intent == "unknown"
    assert conf == 0.0