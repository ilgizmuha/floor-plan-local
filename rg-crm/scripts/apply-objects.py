#!/usr/bin/env python3
"""Set agent object counts for a month in metrics.json."""
from __future__ import annotations

import json
from datetime import datetime
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
METRICS_PATH = ROOT / "data" / "metrics.json"
MONTH_ID = "Август 2026"

# Количество объектов (абсолютные значения на текущий момент)
OBJECTS: dict[str, dict] = {
    "гафурзянова ляйсян": {"name": "Ляйсян Гафурзянова", "objects": 7},
    "михлюкова татьяна": {"name": "Татьяна Михлюкова", "objects": 2},
    "даян": {"name": "Даян", "objects": 1},
    "куватов радмир": {"name": "Радмир Куватов", "objects": 1},
    "редникова ляля": {"name": "Ляля Редникова", "objects": 2},
    "сафина альбина": {"name": "Альбина Сафина", "objects": 1},
    "алимбаева ильгина": {"name": "Ильгина Алимбаева", "objects": 2},
    "гаймалетдинова гульнара": {"name": "Гульнара Гаймелетдинова", "objects": 1},
    "клысова лилия": {"name": "Лилия Клысова", "objects": 1},
    "мустафина наталья": {"name": "Наталья Мустафина", "objects": 1},
    "стажёр земфира": {"name": "Земфира Стажёр", "objects": 1},
    "князева мария": {"name": "Мария Князева", "objects": 3},
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
    "objects",
]


def empty_metrics() -> dict:
    return {k: 0 for k in NUMERIC_KEYS}


def main() -> None:
    data = json.loads(METRICS_PATH.read_text(encoding="utf-8"))
    month = next(m for m in data["months"] if m["id"] == MONTH_ID)
    by_key = {ag["key"]: ag for ag in month["agents"]}

    updated = []
    for key, info in OBJECTS.items():
        if key not in by_key:
            by_key[key] = {
                "name": info["name"],
                "key": key,
                "metrics": empty_metrics(),
                "conversions": {},
            }
            month["agents"].append(by_key[key])
        by_key[key]["metrics"]["objects"] = info["objects"]
        updated.append(f"{info['name']}: {info['objects']}")

    month["agents"].sort(key=lambda a: a["name"].lower())
    month["totals"]["objects"] = sum(
        (ag.get("metrics") or {}).get("objects") or 0 for ag in month["agents"]
    )

    labels = data.setdefault("metricLabels", {})
    labels["objects"] = "Объекты"

    data["generatedAt"] = datetime.now().strftime("%Y-%m-%dT%H:%M:%S")
    METRICS_PATH.write_text(json.dumps(data, ensure_ascii=False, indent=2), encoding="utf-8")
    print(f"Objects set for {MONTH_ID} (total {month['totals']['objects']}):")
    for line in updated:
        print(f"  {line}")


if __name__ == "__main__":
    main()
