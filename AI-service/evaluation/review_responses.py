"""Summarize manually reviewed, labeled chatbot responses from a JSONL file.

Each line: {"case_id": "...", "correct": true|false, "fallback_used": bool,
"gemini_error": bool, "latency_ms": number}. No message text or user token is needed.
"""
from __future__ import annotations

import argparse
import json
from pathlib import Path


def summarize(rows: list[dict]) -> dict:
    if not rows:
        raise ValueError("At least one reviewed response is required")
    ids = [row["case_id"] for row in rows]
    if len(ids) != len(set(ids)):
        raise ValueError("case_id values must be unique")
    for row in rows:
        if type(row["correct"]) is not bool or type(row["fallback_used"]) is not bool or type(row["gemini_error"]) is not bool:
            raise ValueError(f"Invalid labels for {row['case_id']}")
        if type(row["latency_ms"]) not in (int, float) or row["latency_ms"] < 0:
            raise ValueError(f"Invalid latency for {row['case_id']}")
    count = len(rows)
    return {
        "reviewed_count": count,
        "correct_rate": sum(row["correct"] for row in rows) / count,
        "fallback_rate": sum(row["fallback_used"] for row in rows) / count,
        "gemini_error_rate": sum(row["gemini_error"] for row in rows) / count,
    }


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("reviewed_jsonl", type=Path)
    args = parser.parse_args()
    rows = [json.loads(line) for line in args.reviewed_jsonl.read_text(encoding="utf-8").splitlines() if line.strip()]
    print(json.dumps(summarize(rows), indent=2, ensure_ascii=False))


if __name__ == "__main__":
    main()
