"""Anti-leak check — P2 (v1.2, điều kiện #1, #2).

Đảm bảo:
  1. KHÔNG có câu nào từ 12 frozen cases xuất hiện trong PhoBERT train/val/test set.
  2. KHÔNG có câu nào có Jaccard token ≥ 0.7 với frozen case.
  3. KHÔNG có 4-gram liên tiếp trùng với frozen case.
  4. Train/Val và Test_Phobert có template family TÁCH BIỆT (A,B,C vs D,E).

Usage:
    python scripts/anti_leak_check.py

Exit code:
    0 = PASS (0 leak)
    1 = FAIL (có leak)
"""
from __future__ import annotations

import json
import re
import sys
from pathlib import Path

try:
    import yaml
except ImportError:
    print("ERROR: PyYAML chưa cài. pip install pyyaml", file=sys.stderr)
    sys.exit(2)

ROOT = Path(__file__).resolve().parent.parent.parent  # repo root (../.. từ ai-service/scripts)
FROZEN_PATH = ROOT / "AI" / "datasets" / "consultation_v1" / "test_set.yaml"
TEST_DATA_DIR = ROOT / "ai-service" / "tests" / "data"

# 4 lớp check.
EXACT_MATCH = "exact_match"
JACCARD = "jaccard"
NGRAM = "ngram"
TEMPLATE_FAMILY = "template_family_overlap"

# Files PhoBERT cần check.
PHOBERT_FILES = [
    "intent_train.jsonl",
    "intent_val.jsonl",
    "intent_test_phobert.jsonl",
    "intent_test_phobert_human.jsonl",
    "slot_test_phobert.jsonl",
    "ood_test_phobert.jsonl",
    "multiturn_test_phobert.jsonl",
]

# Template family cho train/val (A, B, C) và test (D, E).
TRAIN_FAMILIES = {"A", "B", "C"}
TEST_FAMILIES = {"D", "E"}


def tokenize(text: str) -> list[str]:
    """Tokenize đơn giản: lowercase, bỏ dấu câu, tách whitespace."""
    text = text.lower().strip()
    text = re.sub(r"[^\w\sà-ỹ]", " ", text, flags=re.UNICODE)
    return [t for t in text.split() if t]


def jaccard_similarity(a: list[str], b: list[str]) -> float:
    """Jaccard similarity giữa 2 list token."""
    sa, sb = set(a), set(b)
    if not sa or not sb:
        return 0.0
    return len(sa & sb) / len(sa | sb)


def has_ngram_overlap(text_tokens: list[str], frozen_tokens: list[str], n: int = 4) -> bool:
    """True nếu có n-gram liên tiếp trùng giữa text và frozen."""
    if len(text_tokens) < n or len(frozen_tokens) < n:
        return False
    frozen_ngrams = {tuple(frozen_tokens[i : i + n]) for i in range(len(frozen_tokens) - n + 1)}
    for i in range(len(text_tokens) - n + 1):
        if tuple(text_tokens[i : i + n]) in frozen_ngrams:
            return True
    return False


def load_frozen() -> list[dict]:
    """Load 12 frozen case từ consultation_v1/test_set.yaml."""
    if not FROZEN_PATH.exists():
        print(f"ERROR: frozen path không tồn tại: {FROZEN_PATH}", file=sys.stderr)
        sys.exit(2)

    with FROZEN_PATH.open("r", encoding="utf-8") as f:
        data = yaml.safe_load(f)

    cases = data.get("test_cases", [])
    if not cases:
        print(f"ERROR: frozen YAML không có test_cases.", file=sys.stderr)
        sys.exit(2)

    print(f"[1/4] Loaded {len(cases)} frozen cases từ {FROZEN_PATH.relative_to(ROOT)}")
    return cases


def load_phobert_file(path: Path) -> list[dict]:
    """Load 1 file JSONL PhoBERT. Trả về list dict."""
    if not path.exists():
        print(f"  WARN: {path.name} không tồn tại — bỏ qua (P2 chưa sinh xong).")
        return []
    with path.open("r", encoding="utf-8") as f:
        return [json.loads(line) for line in f if line.strip()]


def extract_text(row: dict) -> str:
    """Lấy text từ row. Ưu tiên 'text', fallback 'user' (multiturn)."""
    return row.get("text") or row.get("user") or row.get("turns", [{}])[0].get("user", "")


def extract_template_family(row: dict) -> str | None:
    """Lấy template_family nếu có."""
    return row.get("template_family")


def check_phobert_against_frozen(
    phobert_data: list[dict],
    frozen_data: list[dict],
    file_name: str,
) -> list[dict]:
    """Chạy 4 lớp check trên 1 file PhoBERT.

    Returns: list các leak phát hiện.
    """
    leaks = []
    frozen_users = [(c.get("user", ""), tokenize(c.get("user", ""))) for c in frozen_data]

    for idx, row in enumerate(phobert_data):
        text = extract_text(row)
        if not text:
            continue
        text_tokens = tokenize(text)
        if not text_tokens:
            continue

        for frozen_text, frozen_tokens in frozen_users:
            # 1. Exact match
            if text.strip() == frozen_text.strip():
                leaks.append({
                    "check": EXACT_MATCH,
                    "file": file_name,
                    "row_index": idx,
                    "phobert_text": text,
                    "frozen_text": frozen_text,
                })
                continue

            # 2. Jaccard ≥ 0.7
            j = jaccard_similarity(text_tokens, frozen_tokens)
            if j >= 0.7:
                leaks.append({
                    "check": JACCARD,
                    "file": file_name,
                    "row_index": idx,
                    "phobert_text": text,
                    "frozen_text": frozen_text,
                    "jaccard": round(j, 3),
                })
                continue

            # 3. 4-gram overlap
            if has_ngram_overlap(text_tokens, frozen_tokens, n=4):
                leaks.append({
                    "check": NGRAM,
                    "file": file_name,
                    "row_index": idx,
                    "phobert_text": text,
                    "frozen_text": frozen_text,
                })

    return leaks


def check_template_family_split(
    train_data: list[dict],
    val_data: list[dict],
    test_data: list[dict],
) -> list[dict]:
    """Lớp 4: train/val và test_phobert có template family TÁCH BIỆT."""
    leaks = []

    train_families = {
        extract_template_family(row)
        for row in train_data + val_data
        if extract_template_family(row)
    }
    test_families = {
        extract_template_family(row)
        for row in test_data
        if extract_template_family(row)
    }

    overlap = train_families & test_families
    if overlap:
        for fam in overlap:
            leaks.append({
                "check": TEMPLATE_FAMILY,
                "family": fam,
                "in_train": True,
                "in_test": True,
            })

    return leaks


def main() -> int:
    print("=" * 60)
    print("P2 Anti-Leak Check (v1.2)")
    print("=" * 60)

    # 1. Load frozen
    frozen = load_frozen()

    # 2. Load từng file PhoBERT
    all_leaks: list[dict] = []
    train_data: list[dict] = []
    val_data: list[dict] = []
    test_phobert_data: list[dict] = []

    print("\n[2/4] Check 12 frozen cases KHÔNG leak vào PhoBERT files:")
    for file_name in PHOBERT_FILES:
        path = TEST_DATA_DIR / file_name
        data = load_phobert_file(path)
        if not data:
            continue

        leaks = check_phobert_against_frozen(data, frozen, file_name)
        all_leaks.extend(leaks)

        if file_name == "intent_train.jsonl":
            train_data = data
        elif file_name == "intent_val.jsonl":
            val_data = data
        elif file_name == "intent_test_phobert.jsonl":
            test_phobert_data = data

        status = "OK" if not leaks else f"FAIL ({len(leaks)} leaks)"
        print(f"  {file_name:42s} {len(data):5d} rows  {status}")

    # 3. Template family split check
    print("\n[3/4] Check template family split (A,B,C vs D,E):")
    if train_data and test_phobert_data:
        family_leaks = check_template_family_split(train_data, val_data, test_phobert_data)
        all_leaks.extend(family_leaks)

        train_fams = {
            extract_template_family(r) for r in train_data + val_data
            if extract_template_family(r)
        }
        test_fams = {
            extract_template_family(r) for r in test_phobert_data
            if extract_template_family(r)
        }
        print(f"  Train/Val families: {sorted(train_fams)}")
        print(f"  Test families:      {sorted(test_fams)}")
        print(f"  Overlap:            {sorted(train_fams & test_fams) or '(empty)'}")
    else:
        print("  SKIP — chưa có train/val/test file (P2.4 chưa chạy).")

    # 4. Tổng kết
    print("\n[4/4] Tổng kết:")
    if not all_leaks:
        print("  ✅ 0 leak detected — PASS")
        return 0

    print(f"  ❌ {len(all_leaks)} leak(s) detected — FAIL")
    # In 5 leak đầu tiên để debug.
    for leak in all_leaks[:5]:
        print(f"    - {leak}")
    if len(all_leaks) > 5:
        print(f"    ... ({len(all_leaks) - 5} more)")
    return 1


if __name__ == "__main__":
    sys.exit(main())
