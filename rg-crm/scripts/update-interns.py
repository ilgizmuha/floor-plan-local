#!/usr/bin/env python3
"""Update intern names in metrics.json and ensure registry."""
from __future__ import annotations

import json
from datetime import datetime
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
METRICS_PATH = ROOT / "data" / "metrics.json"

INTERNS = {
    "стажёр луиза": "Жданова Луиза",
    "стажёр константин": "Максимов Константин",
    "стажёр земфира": "Гареева Земфира",
    "стажёр илья": "Кузнецов Илья",
    "стажёр айгуль": "Калимуллина Айгуль",
}

EMPTY_METRICS = {
    "hz": 0,
    "rastleyka": 0,
    "meetings": 0,
    "ad": 0,
    "showings": 0,
    "analogs": 0,
    "podbor": 0,
    "zadatok": 0,
    "lifts": 0,
    "rassylka": 0,
    "crm": 0,
    "incoming": 0,
    "consults": 0,
    "bron": 0,
    "deals": 0,
    "deals_secondary": 0,
    "prihod": 0,
    "touches": 0,
    "objects": 0,
}


def main() -> None:
    data = json.loads(METRICS_PATH.read_text(encoding="utf-8"))

    data["interns"] = {
        "goalDeals": 5,
        "tariffs": [
            {"deals": "1", "rate": 30, "label": "1-я сделка"},
            {"deals": "2–5", "rate": 40, "label": "2–5-я сделка"},
        ],
        "agents": {key: {"name": name} for key, name in INTERNS.items()},
    }

    for month in data["months"]:
        by_key = {a["key"]: a for a in month.get("agents", [])}
        for key, name in INTERNS.items():
            if key in by_key:
                by_key[key]["name"] = name
            elif month.get("id") == "Август 2026" and key == "стажёр айгуль":
                month.setdefault("agents", []).append(
                    {
                        "name": name,
                        "key": key,
                        "metrics": dict(EMPTY_METRICS),
                        "conversions": {},
                    }
                )
        month["agents"].sort(key=lambda a: a["name"].lower())

    data["generatedAt"] = datetime.now().strftime("%Y-%m-%dT%H:%M:%S")
    METRICS_PATH.write_text(json.dumps(data, ensure_ascii=False, indent=2), encoding="utf-8")
    print("Updated intern names:", ", ".join(INTERNS.values()))


if __name__ == "__main__":
    main()
