"""Check split integrity (template family).

Đảm bảo train/val families và test_phobert families TÁCH BIỆT.
"""
from __future__ import annotations

import json
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent.parent  # repo root
TEST_DATA_DIR = ROOT / "ai-service" / "tests" / "data"

TRAIN_FAMILIES = {"A", "B", "C"}
TEST_FAMILIES = {"D", "E"}


def main() -> int:
    train = TEST_DATA_DIR / "intent_train.jsonl"
    val = TEST_DATA_DIR / "intent_val.jsonl"
    test = TEST_DATA_DIR / "intent_test_phobert.jsonl"

    if not all(p.exists() for p in (train, val, test)):
        print("SKIP — chưa có train/val/test file.")
        return 0

    def load_families(path: Path) -> set[str]:
        fams = set()
        with path.open("r", encoding="utf-8") as f:
            for line in f:
                if not line.strip():
                    continue
                row = json.loads(line)
                fam = row.get("template_family")
                if fam:
                    fams.add(fam)
        return fams

    train_fams = load_families(train) | load_families(val)
    test_fams = load_families(test)

    overlap = train_fams & test_fams
    print(f"Train/Val families: {sorted(train_fams)}")
    print(f"Test families:      {sorted(test_fams)}")
    print(f"Overlap:            {sorted(overlap) or '(empty)'}")

    # Cũng check: test families phải ∈ TEST_FAMILIES (D,E)
    bad_test = test_fams - TEST_FAMILIES
    if bad_test:
        print(f"FAIL — test families không thuộc {{D,E}}: {bad_test}")
        return 1

    if overlap:
        print(f"FAIL — train và test dùng chung families: {overlap}")
        return 1

    print("PASS — split integrity OK.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
