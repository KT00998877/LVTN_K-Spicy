"""Offline baseline for the demo service; does not call Gemini or Laravel."""
from __future__ import annotations

import argparse
import asyncio
import json
import sys
import time
from pathlib import Path

SERVICE_ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(SERVICE_ROOT))

from core.retrieval import HybridRetriever  # noqa: E402
from knowledge_base.loader import KnowledgeBaseLoader  # noqa: E402
from services.chat_service import detect_intent  # noqa: E402


def score_retrieval(rows: list[dict], k: int) -> dict:
    hit = sum(bool(row["hits_at_k"]) for row in rows)
    recall = sum(row["recall_at_k"] for row in rows)
    reciprocal_rank = sum(row["reciprocal_rank"] for row in rows)
    count = len(rows)
    return {
        f"hit_at_{k}": hit / count,
        f"recall_at_{k}": recall / count,
        "mrr": reciprocal_rank / count,
        "mean_latency_ms": sum(row["latency_ms"] for row in rows) / count,
        "first_query_latency_ms": rows[0]["latency_ms"],
        "later_queries_mean_latency_ms": sum(row["latency_ms"] for row in rows[1:]) / (count - 1) if count > 1 else None,
    }


async def evaluate(cases: dict, k: int) -> dict:
    chunks = KnowledgeBaseLoader(str(SERVICE_ROOT / "knowledge_base")).load_chunks()
    chunk_ids = {chunk.chunk_id for chunk in chunks}
    for case in cases["retrieval"]:
        if not case["relevant_chunk_ids"] or not set(case["relevant_chunk_ids"]) <= chunk_ids:
            raise ValueError(f"Invalid gold chunk IDs in {case['id']}")

    retriever = HybridRetriever(
        chunks=chunks,
        embeddings_path=str(SERVICE_ROOT / "knowledge_base" / "embeddings" / "embeddings.jsonl"),
    )
    if not retriever.use_vector or not retriever._vector or not retriever._vector._chunk_embeddings:
        raise RuntimeError("Vector index unavailable; refusing to label a fallback as hybrid")

    methods = {}
    for method in ("bm25", "vector", "hybrid"):
        rows = []
        for case in cases["retrieval"]:
            started = time.perf_counter()
            results = await retriever.retrieve(case["query"], top_k=k, method=method)
            elapsed = (time.perf_counter() - started) * 1000
            ids = [result.chunk.chunk_id for result in results]
            gold = set(case["relevant_chunk_ids"])
            hits = [chunk_id for chunk_id in ids if chunk_id in gold]
            rank = next((index for index, chunk_id in enumerate(ids, 1) if chunk_id in gold), None)
            rows.append({
                "id": case["id"], "retrieved_chunk_ids": ids,
                "hits_at_k": hits, "recall_at_k": len(hits) / len(gold),
                "reciprocal_rank": 1 / rank if rank else 0.0,
                "latency_ms": round(elapsed, 3),
            })
        methods[method] = {"metrics": score_retrieval(rows, k), "cases": rows}

    intent_rows = []
    for case in cases["intent"]:
        actual = detect_intent(case["message"])
        intent_rows.append({
            "id": case["id"], "expected": case["expected"],
            "actual": actual, "correct": actual == case["expected"],
            "tag": case.get("tag"),
        })
    return {
        "scope": "offline_static_retrieval_and_keyword_intent_only",
        "retrieval_case_count": len(cases["retrieval"]),
        "intent_case_count": len(intent_rows),
        "k": k, "methods": methods,
        "intent": {
            "accuracy": sum(row["correct"] for row in intent_rows) / len(intent_rows),
            "cases": intent_rows,
        },
        "not_measured": ["answer_correctness", "tool_execution", "auth", "gemini_errors", "end_to_end_latency"],
    }


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--cases", type=Path, default=SERVICE_ROOT / "evaluation" / "baseline_cases.json")
    parser.add_argument("--output", type=Path, default=SERVICE_ROOT / "evaluation" / "improved_report.json")
    parser.add_argument("--k", type=int, default=5)
    args = parser.parse_args()
    if args.k < 1:
        parser.error("--k must be positive")
    cases = json.loads(args.cases.read_text(encoding="utf-8"))
    report = asyncio.run(evaluate(cases, args.k))
    args.output.write_text(json.dumps(report, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(json.dumps({
        "retrieval_cases": report["retrieval_case_count"],
        "intent_cases": report["intent_case_count"],
        "metrics": {name: data["metrics"] for name, data in report["methods"].items()},
        "intent_accuracy": report["intent"]["accuracy"],
        "output": str(args.output),
    }, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
