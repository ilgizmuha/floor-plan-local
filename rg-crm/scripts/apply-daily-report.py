#!/usr/bin/env python3
"""Apply a daily agent report to metrics.json (adds to current month totals)."""
from __future__ import annotations

import json
import re
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
METRICS_PATH = ROOT / "data" / "metrics.json"
DAILY_DIR = ROOT / "data" / "daily"

REPORT_DATE = "2026-08-17"
MONTH_ID = "Август 2026"

# Parsed from user report for 2026-08-17
DAILY_AGENTS: dict[str, dict] = {
    "клысова лилия": {
        "name": "Лилия Клысова",
        "hz": 0,
        "incoming": 1,
        "rastleyka": 0,
        "rassylka": 0,
        "crm": 3,
        "meetings": 0,
        "showings": 1,
        "podbor": 0,
        "consults": 0,
        "bron": 0,
        "zadatok": 0,
        "deals": 0,
        "deals_secondary": 0,
        "ad": 0,
    },
    "идрисова рузиля": {
        "name": "Рузиля Идрисова",
        "hz": 0,
        "incoming": 7,
        "rastleyka": 0,
        "rassylka": 0,
        "crm": 1,
        "meetings": 0,
        "showings": 0,
        "podbor": 0,
        "consults": 0,
        "bron": 0,
        "zadatok": 0,
        "deals": 0,
        "deals_secondary": 0,
        "ad": 0,
    },
    "стажёр константин": {
        "name": "Константин Стажёр",
        "hz": 0,
        "incoming": 0,
        "rastleyka": 50,
        "rassylka": 0,
        "crm": 0,
        "meetings": 2,
        "showings": 0,
        "podbor": 0,
        "consults": 0,
        "bron": 0,
        "zadatok": 0,
        "deals": 0,
        "deals_secondary": 0,
        "ad": 0,
    },
    "михлюкова татьяна": {
        "name": "Татьяна Михлюкова",
        "hz": 0,
        "incoming": 3,
        "rastleyka": 0,
        "rassylka": 0,
        "crm": 1,
        "meetings": 0,
        "showings": 0,
        "podbor": 1,
        "consults": 0,
        "bron": 0,
        "zadatok": 0,
        "deals": 0,
        "deals_secondary": 0,
        "ad": 0,
    },
    "стажёр земфира": {
        "name": "Земфира Стажёр",
        "hz": 0,
        "incoming": 2,
        "rastleyka": 20,
        "rassylka": 0,
        "crm": 1,
        "meetings": 3,
        "showings": 0,
        "podbor": 0,
        "consults": 1,
        "bron": 0,
        "zadatok": 0,
        "deals": 0,
        "deals_secondary": 0,
        "ad": 0,
    },
    "гафурзянова ляйсян": {
        "name": "Ляйсян Гафурзянова",
        "hz": 4,
        "incoming": 3,
        "rastleyka": 400,
        "rassylka": 0,
        "crm": 2,
        "meetings": 0,
        "showings": 0,
        "podbor": 0,
        "consults": 0,
        "bron": 0,
        "zadatok": 0,
        "deals": 0,
        "deals_secondary": 0,
        "ad": 0,
    },
    "мустафина наталья": {
        "name": "Наталья Мустафина",
        "hz": 1,
        "incoming": 1,
        "rastleyka": 0,
        "rassylka": 0,
        "crm": 0,
        "meetings": 0,
        "showings": 0,
        "podbor": 0,
        "consults": 2,
        "bron": 0,
        "zadatok": 0,
        "deals": 0,
        "deals_secondary": 0,
        "ad": 0,
    },
    "стажёр луиза": {
        "name": "Луиза Стажёр",
        "hz": 0,
        "incoming": 2,
        "rastleyka": 0,
        "rassylka": 0,
        "crm": 0,
        "meetings": 0,
        "showings": 0,
        "podbor": 0,
        "consults": 0,
        "bron": 0,
        "zadatok": 0,
        "deals": 0,
        "deals_secondary": 0,
        "ad": 0,
    },
    "стажёр илья": {
        "name": "Илья Стажёр",
        "hz": 13,
        "incoming": 0,
        "rastleyka": 0,
        "rassylka": 0,
        "crm": 0,
        "meetings": 1,
        "showings": 0,
        "podbor": 0,
        "consults": 0,
        "bron": 0,
        "zadatok": 0,
        "deals": 0,
        "deals_secondary": 0,
        "ad": 0,
    },
    "редникова ляля": {
        "name": "Ляля Редникова",
        "hz": 0,
        "incoming": 1,
        "rastleyka": 1200,
        "rassylka": 1500,
        "crm": 4,
        "meetings": 0,
        "showings": 0,
        "podbor": 0,
        "consults": 0,
        "bron": 0,
        "zadatok": 0,
        "deals": 0,
        "deals_secondary": 0,
        "ad": 0,
    },
    "сафина альбина": {
        "name": "Альбина Сафина",
        "hz": 0,
        "incoming": 5,
        "rastleyka": 0,
        "rassylka": 1000,
        "crm": 0,
        "meetings": 0,
        "showings": 0,
        "podbor": 0,
        "consults": 0,
        "bron": 0,
        "zadatok": 0,
        "deals": 0,
        "deals_secondary": 0,
        "ad": 0,
    },
}

NUMERIC_KEYS = [
    "hz",
    "rastleyka",
    "meetings",
    "ad",
    "showings",
    "analogs",
    "podbor",
    "zadatok",
    "lifts",
    "rassylka",
    "crm",
    "incoming",
    "consults",
    "bron",
    "deals",
    "deals_secondary",
    "prihod",
    "touches",
]


def pct(num: float, den: float):
    if not den:
        return None
    return round(1000 * num / den) / 10


def touches(m: dict) -> float:
    return (
        (m.get("meetings") or 0)
        + (m.get("showings") or 0)
        + (m.get("podbor") or 0)
        + (m.get("consults") or 0)
    )


def agent_conversions(m: dict) -> dict:
    deals_primary = m.get("deals") or 0
    deals_total = deals_primary + (m.get("deals_secondary") or 0)
    return {
        "meet_to_ad": pct(m.get("ad") or 0, m.get("meetings") or 0),
        "show_to_zad": pct(m.get("zadatok") or 0, m.get("showings") or 0),
        "consult_to_bron": pct(m.get("bron") or 0, m.get("consults") or 0),
        "bron_to_deal": pct(deals_primary, m.get("bron") or 0),
        "consult_to_deal": pct(deals_total, m.get("consults") or 0),
        "hz_to_meet": pct(m.get("meetings") or 0, m.get("hz") or 0),
        "meet_to_deal": pct(deals_total, m.get("meetings") or 0),
        "touch_to_deal": pct(deals_total, touches(m)),
    }


def month_conversions(totals: dict) -> dict:
    deals_total = (totals.get("deals") or 0) + (totals.get("deals_secondary") or 0)
    touch_total = touches(totals)
    return {
        "meet_to_ad": pct(totals.get("ad") or 0, totals.get("meetings") or 0),
        "show_to_zad": pct(totals.get("zadatok") or 0, totals.get("showings") or 0),
        "consult_to_bron": pct(totals.get("bron") or 0, totals.get("consults") or 0),
        "bron_to_deal": pct(totals.get("deals") or 0, totals.get("bron") or 0),
        "consult_to_deal": pct(deals_total, totals.get("consults") or 0),
        "hz_to_meet": pct(totals.get("meetings") or 0, totals.get("hz") or 0),
        "meet_to_deal": pct(deals_total, totals.get("meetings") or 0),
        "touch_to_deal": pct(deals_total, touch_total),
    }


def sum_month(agents: list[dict]) -> dict:
    totals = {k: 0 for k in NUMERIC_KEYS}
    for ag in agents:
        m = ag.get("metrics") or {}
        for k in NUMERIC_KEYS:
            totals[k] += m.get(k) or 0
    totals["touches"] = sum(touches(ag.get("metrics") or {}) for ag in agents)
    return totals


def add_metrics(base: dict, delta: dict) -> dict:
    out = dict(base)
    for k in NUMERIC_KEYS:
        if k in delta:
            out[k] = (out.get(k) or 0) + (delta.get(k) or 0)
    out["touches"] = touches(out)
    return out


def update_daily_index() -> None:
    dates = sorted(
        (p.stem for p in DAILY_DIR.glob("*.json") if p.name != "index.json"),
        reverse=True,
    )
    index = {"dates": dates, "latest": dates[0] if dates else None}
    (DAILY_DIR / "index.json").write_text(
        json.dumps(index, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
    )


def main() -> None:
    data = json.loads(METRICS_PATH.read_text(encoding="utf-8"))
    month = next(m for m in data["months"] if m["id"] == MONTH_ID)

    DAILY_DIR.mkdir(parents=True, exist_ok=True)
    daily_path = DAILY_DIR / f"{REPORT_DATE}.json"
    daily_path.write_text(
        json.dumps(
            {
                "date": REPORT_DATE,
                "monthId": MONTH_ID,
                "agents": DAILY_AGENTS,
                "note": "Статусы 3-х лиц → crm; топ100 → incoming",
            },
            ensure_ascii=False,
            indent=2,
        ),
        encoding="utf-8",
    )

    by_key = {ag["key"]: ag for ag in month["agents"]}
    updated = []
    for key, delta in DAILY_AGENTS.items():
        if key not in by_key:
            by_key[key] = {
                "name": delta["name"],
                "key": key,
                "metrics": {k: 0 for k in NUMERIC_KEYS},
                "conversions": {},
            }
            month["agents"].append(by_key[key])
        m = add_metrics(by_key[key].get("metrics") or {}, delta)
        by_key[key]["metrics"] = m
        by_key[key]["conversions"] = agent_conversions(m)
        updated.append(delta["name"])

    month["agents"].sort(key=lambda a: a["name"].lower())
    month["totals"] = sum_month(month["agents"])
    month["conversions"] = month_conversions(month["totals"])

    data["generatedAt"] = datetime.now().strftime("%Y-%m-%dT%H:%M:%S")
    data["lastDailyReport"] = {"date": REPORT_DATE, "agents": len(DAILY_AGENTS)}

    METRICS_PATH.write_text(json.dumps(data, ensure_ascii=False, indent=2), encoding="utf-8")
    update_daily_index()
    print(f"Applied daily report {REPORT_DATE} to {MONTH_ID}")
    print("Updated:", ", ".join(updated))


if __name__ == "__main__":
    main()
