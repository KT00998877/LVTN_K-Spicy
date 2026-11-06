"""
E2E RAG Evaluation with Real Gemini API.

Measures:
- Citation Validity
- Grounded Answer Rate
- Hallucination Rate
- Test Case Pass Rate
- Latency breakdown
"""
from __future__ import annotations

import asyncio
import json
import time
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any, Optional

from loguru import logger

import sys
sys.path.insert(0, str(Path(__file__).parent.parent))

from core.retrieval import create_retriever
from core.grounding import GroundingGuard
from evaluation.compare_methods import load_evaluation_cases


@dataclass
class E2ECaseResult:
    """Result of an E2E test case."""
    case_id: str
    user_query: str
    response: str
    intent: str
    retrieval: dict
    citations: list[dict]
    retrieval_latency_ms: float
    generation_latency_ms: float
    total_latency_ms: float
    citation_valid: bool
    is_grounded: bool
    hallucination_detected: bool
    grounding_checks: list[dict]


@dataclass
class E2EReport:
    """E2E evaluation report."""
    cases: list[E2ECaseResult]
    total_cases: int
    citation_validity_rate: float
    grounded_answer_rate: float
    hallucination_rate: float
    test_case_pass_rate: float
    avg_retrieval_ms: float
    avg_generation_ms: float
    avg_total_ms: float


# Deterministic intent mapping for E2E test
INTENT_MAP = {
    "search_menu": "search_menu",
    "get_menu_item": "get_menu_item",
    "get_menu_item_by_name": "get_menu_item_by_name",
    "get_restaurant_info": "get_restaurant_info",
    "get_restaurant_stats": "get_restaurant_stats",
    "get_categories": "get_categories",
    "get_featured_items": "get_featured_items",
    "get_best_sellers": "get_best_sellers",
    "get_dish_spicy_level": "get_dish_spicy_level",
    "list_dish_reviews": "list_dish_reviews",
    "get_dish_review_summary": "get_dish_review_summary",
    "view_cart": "view_cart",
    "get_cart_count": "get_cart_count",
    "list_active_vouchers": "list_active_vouchers",
    "preview_voucher": "preview_voucher",
    "list_active_campaigns": "list_active_campaigns",
    "get_campaign_detail": "get_campaign_detail",
    "list_loyalty_tiers": "list_loyalty_tiers",
    "greet_user": "greet_user",
    "clarify_intent": "clarify_intent",
}


async def run_e2e_case(
    case: dict,
    retriever,
    chat_service,
    guard: GroundingGuard,
) -> E2ECaseResult:
    """Run a single E2E test case."""
    case_id = case["case_id"]
    query = case["user_query"]
    
    # Retrieval
    start_total = time.time()
    start_retrieval = time.time()
    retrieval_results = await retriever.retrieve(query, top_k=5, method="hybrid")
    retrieval_latency = (time.time() - start_retrieval) * 1000
    
    # Build context
    context = "\n\n".join([
        f"[{i}] ({r.chunk.type}) {r.chunk.text}"
        for i, r in enumerate(retrieval_results, 1)
    ])
    
    citations = [
        {
            "id": i,
            "text": r.chunk.text[:80],
            "source": r.chunk.type,
            "score": round(r.score, 3),
        }
        for i, r in enumerate(retrieval_results, 1)
    ]
    
    retrieval_info = {
        "chunks_used": len(retrieval_results),
        "sources": list(set(r.chunk.type for r in retrieval_results)),
    }
    
    # Generation
    start_gen = time.time()
    result = await chat_service.process_message(
        session_id=f"e2e_{case_id}",
        message=query,
        use_rag=True,
    )
    generation_latency = (time.time() - start_gen) * 1000
    
    total_latency = (time.time() - start_total) * 1000
    
    response = result.get("reply", "")
    intent = result.get("intent", "unknown")
    
    # Grounding checks
    checks = guard.check_response(
        response=response,
        context=context,
        citations=citations,
    )
    
    is_grounded = all(
        c.passed or c.severity == "info"
        for c in checks
        if c.severity in ("error", "warning")
    )
    
    hallucination_check = next(
        (c for c in checks if c.check_type == "hallucination_pattern"),
        None,
    )
    hallucination_detected = (
        hallucination_check and hallucination_check.severity == "warning"
    )
    
    # Citation validation
    cit_validations = guard.validate_citations(citations, [r.chunk for r in retrieval_results])
    citation_valid = all(v.valid for v in cit_validations) if cit_validations else True
    
    return E2ECaseResult(
        case_id=case_id,
        user_query=query,
        response=response,
        intent=intent,
        retrieval=retrieval_info,
        citations=citations,
        retrieval_latency_ms=retrieval_latency,
        generation_latency_ms=generation_latency,
        total_latency_ms=total_latency,
        citation_valid=citation_valid,
        is_grounded=is_grounded,
        hallucination_detected=hallucination_detected,
        grounding_checks=[
            {"type": c.check_type, "passed": c.passed, "severity": c.severity}
            for c in checks
        ],
    )


async def run_e2e_evaluation(
    limit: Optional[int] = None,
    eval_path: Optional[str] = None,
    delay_ms: int = 500,
) -> E2EReport:
    """
    Run E2E evaluation with real Gemini API.
    
    Args:
        limit: Number of cases to run (None = all 25)
        eval_path: Path to evaluation cases
        delay_ms: Delay between requests to avoid rate limit
    """
    cases = load_evaluation_cases(eval_path)
    if limit:
        cases = cases[:limit]
    
    logger.info(f"Loaded {len(cases)} test cases")
    
    # Initialize components
    logger.info("Initializing retriever and chat service...")
    retriever = create_retriever(top_k=5)
    
    from services.chat_service import get_chat_service
    chat_service = get_chat_service()
    guard = GroundingGuard()
    
    # Run each case
    results: list[E2ECaseResult] = []
    errors: list[str] = []
    
    for i, case in enumerate(cases, 1):
        # Rate limit delay
        if i > 1 and delay_ms > 0:
            await asyncio.sleep(delay_ms / 1000)
        
        # Retry logic for rate limits
        max_retries = 3
        for attempt in range(max_retries):
            try:
                print(f"[{i}/{len(cases)}] {case['case_id']}", end=" ", flush=True)
                result = await run_e2e_case(case, retriever, chat_service, guard)
                results.append(result)
                
                status = "OK" if result.is_grounded else "FAIL"
                print(f"({status}, {result.total_latency_ms:.0f}ms)")
                break
                
            except Exception as e:
                error_msg = str(e)
                if "rate limit" in error_msg.lower() or "429" in error_msg:
                    if attempt < max_retries - 1:
                        wait = 5 * (attempt + 1)
                        print(f"Rate limit, waiting {wait}s...")
                        await asyncio.sleep(wait)
                        continue
                errors.append(f"{case['case_id']}: {error_msg}")
                print(f"ERROR: {error_msg[:50]}")
                break
    
    # Calculate metrics
    total = len(results)
    
    citation_valid_count = sum(1 for r in results if r.citation_valid)
    citation_validity_rate = citation_valid_count / total if total else 0
    
    grounded_count = sum(1 for r in results if r.is_grounded)
    grounded_rate = grounded_count / total if total else 0
    
    hallucination_count = sum(1 for r in results if r.hallucination_detected)
    hallucination_rate = hallucination_count / total if total else 0
    
    pass_count = sum(
        1 for r in results
        if r.is_grounded and r.citation_valid and not r.hallucination_detected
    )
    pass_rate = pass_count / total if total else 0
    
    avg_retrieval = sum(r.retrieval_latency_ms for r in results) / total if total else 0
    avg_generation = sum(r.generation_latency_ms for r in results) / total if total else 0
    avg_total = sum(r.total_latency_ms for r in results) / total if total else 0
    
    return E2EReport(
        cases=results,
        total_cases=total,
        citation_validity_rate=citation_validity_rate,
        grounded_answer_rate=grounded_rate,
        hallucination_rate=hallucination_rate,
        test_case_pass_rate=pass_rate,
        avg_retrieval_ms=avg_retrieval,
        avg_generation_ms=avg_generation,
        avg_total_ms=avg_total,
    )


def print_e2e_report(report: E2EReport) -> None:
    """Print E2E evaluation report."""
    print()
    print("=" * 70)
    print("  E2E RAG EVALUATION REPORT")
    print("=" * 70)
    print()
    print(f"  Total Cases: {report.total_cases}")
    print()
    print("  METRICS")
    print(f"  {'Metric':<30} {'Value':<15} {'Target':<15} {'Status'}")
    print(f"  {'-'*30} {'-'*15} {'-'*15} {'-'*10}")
    
    metrics = [
        ("Citation Validity Rate", report.citation_validity_rate, 1.0),
        ("Grounded Answer Rate", report.grounded_answer_rate, 0.85),
        ("Hallucination Rate", report.hallucination_rate, 0.05),
        ("Test Case Pass Rate", report.test_case_pass_rate, 0.75),
    ]
    
    for name, value, target in metrics:
        status = "✓" if value >= target else "✗"
        if name == "Hallucination Rate":
            status = "✓" if value <= target else "✗"
        
        if name == "Hallucination Rate":
            print(f"  {name:<30} {value:>6.1%}          ≤ {target:.0%}        {status}")
        else:
            print(f"  {name:<30} {value:>6.1%}          ≥ {target:.0%}        {status}")
    
    print()
    print("  LATENCY")
    print(f"  Avg Retrieval: {report.avg_retrieval_ms:.2f} ms")
    print(f"  Avg Generation: {report.avg_generation_ms:.2f} ms")
    print(f"  Avg Total: {report.avg_total_ms:.2f} ms")
    print()
    
    # Per-case breakdown
    print("  PER-CASE DETAILS")
    print()
    print(f"  {'Case ID':<28} {'Status':<10} {'Total ms':<10} {'Intent':<20}")
    print(f"  {'-'*28} {'-'*10} {'-'*10} {'-'*20}")
    
    for r in report.cases:
        status = "✓" if (r.is_grounded and r.citation_valid) else "✗"
        print(f"  {r.case_id:<28} {status:<10} {r.total_latency_ms:>7.0f}    {r.intent:<20}")


async def main():
    """Main entry point."""
    report = await run_e2e_evaluation()
    print_e2e_report(report)
    
    # Save JSON
    output = {
        "summary": {
            "total_cases": report.total_cases,
            "citation_validity_rate": report.citation_validity_rate,
            "grounded_answer_rate": report.grounded_answer_rate,
            "hallucination_rate": report.hallucination_rate,
            "test_case_pass_rate": report.test_case_pass_rate,
            "avg_retrieval_ms": report.avg_retrieval_ms,
            "avg_generation_ms": report.avg_generation_ms,
            "avg_total_ms": report.avg_total_ms,
        },
        "cases": [
            {
                "case_id": r.case_id,
                "user_query": r.user_query,
                "intent": r.intent,
                "response_length": len(r.response),
                "citation_valid": r.citation_valid,
                "is_grounded": r.is_grounded,
                "hallucination_detected": r.hallucination_detected,
                "retrieval_latency_ms": r.retrieval_latency_ms,
                "generation_latency_ms": r.generation_latency_ms,
                "total_latency_ms": r.total_latency_ms,
            }
            for r in report.cases
        ],
    }
    
    output_path = Path("docs/C2_5_E2E_RESULTS.json")
    output_path.parent.mkdir(exist_ok=True)
    with open(output_path, "w", encoding="utf-8") as f:
        json.dump(output, f, indent=2, ensure_ascii=False)
    
    print(f"\nResults saved to: {output_path}")
    
    return report


if __name__ == "__main__":
    asyncio.run(main())
