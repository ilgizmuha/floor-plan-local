#!/usr/bin/env python3
"""Apply a daily agent report to metrics.json (adds to current month totals)."""
from __future__ import annotations

import argparse
import json
from datetime import datetime
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
METRICS_PATH = ROOT / "data" / "metrics.json"
DAILY_DIR = ROOT / "data" / "daily"

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
    "objects",
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


def load_daily(path: Path) -> tuple[str, str, dict[str, dict]]:
    payload = json.loads(path.read_text(encoding="utf-8"))
    report_date = payload["date"]
    month_id = payload["monthId"]
    agents = payload["agents"]
    return report_date, month_id, agents


def apply_daily(report_date: str, month_id: str, daily_agents: dict[str, dict]) -> None:
    data = json.loads(METRICS_PATH.read_text(encoding="utf-8"))
    month = next(m for m in data["months"] if m["id"] == month_id)

    DAILY_DIR.mkdir(parents=True, exist_ok=True)
    daily_path = DAILY_DIR / f"{report_date}.json"
    if not daily_path.exists():
        daily_path.write_text(
            json.dumps(
                {
                    "date": report_date,
                    "monthId": month_id,
                    "agents": daily_agents,
                    "note": "Статусы 3-х лиц → crm; топ100 → incoming",
                },
                ensure_ascii=False,
                indent=2,
            ),
            encoding="utf-8",
        )

    by_key = {ag["key"]: ag for ag in month["agents"]}
    updated = []
    for key, delta in daily_agents.items():
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
    data["lastDailyReport"] = {"date": report_date, "agents": len(daily_agents)}

    METRICS_PATH.write_text(json.dumps(data, ensure_ascii=False, indent=2), encoding="utf-8")
    update_daily_index()
    print(f"Applied daily report {report_date} to {month_id}")
    print("Updated:", ", ".join(updated))


def main() -> None:
    parser = argparse.ArgumentParser(description="Apply daily CRM report to metrics.json")
    parser.add_argument(
        "file",
        nargs="?",
        default=str(DAILY_DIR / "2026-08-18.json"),
        help="Path to daily JSON (default: latest prepared file)",
    )
    args = parser.parse_args()
    path = Path(args.file)
    if not path.is_absolute():
        path = ROOT / path
    report_date, month_id, daily_agents = load_daily(path)
    apply_daily(report_date, month_id, daily_agents)


if __name__ == "__main__":
    main()
