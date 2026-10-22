"""
Multi-Method Retrieval Comparison.

Compare BM25, Vector, and Hybrid RRF on the same test cases.
"""
from __future__ import annotations

import asyncio
import json
import time
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any

from loguru import logger

from core.retrieval import create_retriever


@dataclass
class MethodResult:
    """Results for a single retrieval method."""
    method: str
    recall_at_5: float
    cases_with_results: int
    avg_latency_ms: float
    per_case_recall: dict[str, float] = field(default_factory=dict)
    per_case_retrieved: dict[str, list[str]] = field(default_factory=dict)


@dataclass
class ComparisonReport:
    """Comparison report across methods."""
    bm25: MethodResult
    vector: MethodResult
    hybrid: MethodResult
    cases: list[dict]
    winner: str
    improvement: dict[str, float]


# Capability to expected chunk-type mapping
CAPABILITY_CHUNK_MAP = {
    "search_menu": ["menu_item", "category"],
    "get_menu_item": ["menu_item"],
    "get_menu_item_by_name": ["menu_item"],
    "get_restaurant_info": ["restaurant_info", "faq"],
    "get_restaurant_stats": ["restaurant_info", "faq"],
    "get_categories": ["category"],
    "get_featured_items": ["menu_item", "review"],
    "get_best_sellers": ["menu_item", "review"],
    "get_dish_spicy_level": ["menu_item", "policy"],
    "list_dish_reviews": ["review"],
    "get_dish_review_summary": ["review"],
    "view_cart": ["faq"],
    "get_cart_count": ["faq"],
    "list_active_vouchers": ["voucher", "campaign"],
    "preview_voucher": ["voucher"],
    "list_active_campaigns": ["campaign"],
    "get_campaign_detail": ["campaign"],
    "list_loyalty_tiers": ["loyalty_tier"],
    "greet_user": ["faq", "restaurant_info"],
    "clarify_intent": [],
}


def load_evaluation_cases(eval_path: str | None = None) -> list[dict]:
    """Load evaluation cases."""
    if eval_path is None:
        eval_path = str(
            Path(__file__).parent.parent.parent
            / "archive"
            / "AI"
            / "datasets"
            / "evaluation_v1"
            / "cases"
        )
    
    cases = []
    cases_path = Path(eval_path)
    
    if not cases_path.exists():
        logger.warning(f"Path not found: {eval_path}")
        return cases
    
    for json_file in sorted(cases_path.glob("*.json")):
        try:
            with open(json_file, "r", encoding="utf-8") as f:
                data = json.load(f)
                
                messages = data.get("conversation", {}).get("messages", [])
                user_msg = next(
                    (m.get("content", "") for m in messages if m.get("role") == "user"),
                    "",
                )
                
                exp = data.get("expectations", {})
                case = {
                    "case_id": data.get("meta", {}).get("id", json_file.stem),
                    "domain": data.get("meta", {}).get("domain"),
                    "capability": data.get("meta", {}).get("capability"),
                    "user_query": user_msg,
                    "expected_capability": exp.get("understanding", {}).get("capability", ""),
                    "requires_tool": exp.get("tool", {}).get("required", False),
                }
                cases.append(case)
        except Exception as e:
            logger.warning(f"Error loading {json_file}: {e}")
    
    return cases


def get_relevant_chunk_ids(chunks: list, capability: str) -> set[str]:
    """Get relevant chunk IDs for a capability."""
    chunk_types = CAPABILITY_CHUNK_MAP.get(capability, [])
    if not chunk_types:
        return set()  # Empty means any chunk could be relevant
    
    return {c.chunk_id for c in chunks if c.type in chunk_types}


async def run_method(
    retriever,
    cases: list[dict],
    method: str,
    chunks: list,
) -> MethodResult:
    """Run a single retrieval method on all cases."""
    total_recall = 0
    cases_with_results = 0
    total_latency = 0
    per_case_recall = {}
    per_case_retrieved = {}
    
    for case in cases:
        relevant_ids = get_relevant_chunk_ids(chunks, case["expected_capability"])
        
        start = time.time()
        results = await retriever.retrieve(
            case["user_query"],
            top_k=5,
            method=method,
        )
        latency = (time.time() - start) * 1000
        total_latency += latency
        
        retrieved_ids = [r.chunk.chunk_id for r in results]
        per_case_retrieved[case["case_id"]] = retrieved_ids
        
        if relevant_ids:
            found = len(set(retrieved_ids) & relevant_ids)
            recall = found / len(relevant_ids)
        elif results:
            recall = 1.0  # No specific relevant chunks needed
        else:
            recall = 0.0
        
        per_case_recall[case["case_id"]] = recall
        total_recall += recall
        
        if results:
            cases_with_results += 1
    
    avg_recall = total_recall / len(cases) if cases else 0
    avg_latency = total_latency / len(cases) if cases else 0
    
    return MethodResult(
        method=method,
        recall_at_5=avg_recall,
        cases_with_results=cases_with_results,
        avg_latency_ms=avg_latency,
        per_case_recall=per_case_recall,
        per_case_retrieved=per_case_retrieved,
    )


async def compare_methods(eval_path: str | None = None) -> ComparisonReport:
    """Run comparison across BM25, Vector, and Hybrid RRF."""
    # Load cases
    cases = load_evaluation_cases(eval_path)
    logger.info(f"Loaded {len(cases)} test cases")
    
    # Create retrievers for each method
    logger.info("Creating BM25 retriever...")
    bm25_retriever = create_retriever(use_bm25=True, use_vector=False)
    
    logger.info("Creating Vector retriever...")
    vector_retriever = create_retriever(use_bm25=False, use_vector=True)
    
    logger.info("Creating Hybrid retriever...")
    hybrid_retriever = create_retriever(use_bm25=True, use_vector=True)
    
    chunks = bm25_retriever._bm25.chunks if bm25_retriever._bm25 else []
    
    # Run each method
    print("\n" + "=" * 70)
    print("RUNNING BM25...")
    print("=" * 70)
    bm25_result = await run_method(bm25_retriever, cases, "bm25", chunks)
    
    print("\n" + "=" * 70)
    print("RUNNING VECTOR...")
    print("=" * 70)
    vector_result = await run_method(vector_retriever, cases, "vector", chunks)
    
    print("\n" + "=" * 70)
    print("RUNNING HYBRID (RRF)...")
    print("=" * 70)
    hybrid_result = await run_method(hybrid_retriever, cases, "hybrid", chunks)
    
    # Determine winner
    results = {
        "bm25": bm25_result,
        "vector": vector_result,
        "hybrid": hybrid_result,
    }
    
    winner = max(results.keys(), key=lambda k: results[k].recall_at_5)
    
    improvement = {
        "vector_vs_bm25": vector_result.recall_at_5 - bm25_result.recall_at_5,
        "hybrid_vs_bm25": hybrid_result.recall_at_5 - bm25_result.recall_at_5,
        "hybrid_vs_vector": hybrid_result.recall_at_5 - vector_result.recall_at_5,
    }
    
    return ComparisonReport(
        bm25=bm25_result,
        vector=vector_result,
        hybrid=hybrid_result,
        cases=cases,
        winner=winner,
        improvement=improvement,
    )


def print_comparison_report(report: ComparisonReport) -> None:
    """Print comparison report."""
    print()
    print("=" * 70)
    print("  RAG RETRIEVAL COMPARISON REPORT")
    print("=" * 70)
    print()
    print(f"  Test Cases: {len(report.cases)}")
    print()
    
    # Summary table
    print(f"  {'Method':<12} {'Recall@5':<12} {'Latency (ms)':<15} {'Coverage'}")
    print(f"  {'-'*12} {'-'*12} {'-'*15} {'-'*15}")
    
    for method in ["bm25", "vector", "hybrid"]:
        r = getattr(report, method)
        coverage = f"{r.cases_with_results}/{len(report.cases)}"
        print(f"  {r.method:<12} {r.recall_at_5:.1%}        {r.avg_latency_ms:.2f}          {coverage}")
    
    print()
    
    # Winner
    print(f"  🏆 WINNER: {report.winner.upper()}")
    print()
    
    # Improvements
    print("  Improvements:")
    for comparison, value in report.improvement.items():
        sign = "+" if value > 0 else ""
        print(f"    {comparison}: {sign}{value:.1%}")
    
    print()
    print("=" * 70)
    print("  PER-CASE RECALL@5")
    print("=" * 70)
    print()
    
    # Per-case breakdown
    case_ids = sorted(report.bm25.per_case_recall.keys())
    print(f"  {'Case ID':<30} {'BM25':<10} {'Vector':<10} {'Hybrid':<10}")
    print(f"  {'-'*30} {'-'*10} {'-'*10} {'-'*10}")
    
    for case_id in case_ids:
        bm25_r = report.bm25.per_case_recall.get(case_id, 0)
        vec_r = report.vector.per_case_recall.get(case_id, 0)
        hyb_r = report.hybrid.per_case_recall.get(case_id, 0)
        print(f"  {case_id:<30} {bm25_r:.0%}        {vec_r:.0%}        {hyb_r:.0%}")
    
    # Cases where each method excels
    print()
    print("=" * 70)
    print("  ANALYSIS")
    print("=" * 70)
    print()
    
    hybrid_better_than_bm25 = sum(
        1 for cid in case_ids
        if report.hybrid.per_case_recall.get(cid, 0) > report.bm25.per_case_recall.get(cid, 0)
    )
    
    vector_better_than_bm25 = sum(
        1 for cid in case_ids
        if report.vector.per_case_recall.get(cid, 0) > report.bm25.per_case_recall.get(cid, 0)
    )
    
    print(f"  Cases where Hybrid > BM25: {hybrid_better_than_bm25}/{len(case_ids)}")
    print(f"  Cases where Vector > BM25: {vector_better_than_bm25}/{len(case_ids)}")
    print(f"  BM25 Recall: {report.bm25.recall_at_5:.1%}")
    print(f"  Vector Recall: {report.vector.recall_at_5:.1%}")
    print(f"  Hybrid Recall: {report.hybrid.recall_at_5:.1%}")


async def main():
    """Main entry point."""
    report = await compare_methods()
    print_comparison_report(report)
    
    # Save JSON report
    output = {
        "summary": {
            "test_cases": len(report.cases),
            "bm25": {
                "recall_at_5": report.bm25.recall_at_5,
                "avg_latency_ms": report.bm25.avg_latency_ms,
            },
            "vector": {
                "recall_at_5": report.vector.recall_at_5,
                "avg_latency_ms": report.vector.avg_latency_ms,
            },
            "hybrid": {
                "recall_at_5": report.hybrid.recall_at_5,
                "avg_latency_ms": report.hybrid.avg_latency_ms,
            },
            "winner": report.winner,
            "improvement": report.improvement,
        },
        "per_case": {
            cid: {
                "bm25": report.bm25.per_case_recall.get(cid, 0),
                "vector": report.vector.per_case_recall.get(cid, 0),
                "hybrid": report.hybrid.per_case_recall.get(cid, 0),
                "hybrid_retrieved": report.hybrid.per_case_retrieved.get(cid, []),
            }
            for cid in report.bm25.per_case_recall.keys()
        },
    }
    
    output_path = Path("docs/C2_5_RETRIEVAL_COMPARISON_DATA.json")
    output_path.parent.mkdir(exist_ok=True)
    with open(output_path, "w", encoding="utf-8") as f:
        json.dump(output, f, indent=2, ensure_ascii=False)
    
    print(f"\nDetailed data saved to: {output_path}")
    
    return report


if __name__ == "__main__":
    asyncio.run(main())
