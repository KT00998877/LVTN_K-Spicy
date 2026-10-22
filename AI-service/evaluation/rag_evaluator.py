"""
RAG Evaluation Module.

Evaluates RAG quality using the consultation-only test cases.
"""
from __future__ import annotations

import json
import time
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any

from loguru import logger

from core.retrieval import HybridRetriever, create_retriever
from core.grounding import GroundingGuard, get_grounding_guard
from knowledge_base.loader import KnowledgeBaseLoader, Chunk


@dataclass
class EvaluationCase:
    """A single evaluation test case."""
    case_id: str
    domain: str
    capability: str
    user_query: str
    expected_capability: str
    requires_tool: bool
    expected_tool: str | None


@dataclass
class RetrievalResult:
    """Result of retrieval for a query."""
    case_id: str
    retrieved_chunks: list[Chunk]
    retrieval_scores: list[float]
    recall_at_5: float
    relevant_chunks_found: int
    total_relevant: int


@dataclass
class AnswerEvaluation:
    """Evaluation of a generated answer."""
    case_id: str
    has_response: bool
    is_grounded: bool
    has_citations: bool
    hallucination_detected: bool
    grounding_checks: list[dict]
    citation_valid: bool


@dataclass
class EvaluationMetrics:
    """Aggregated evaluation metrics."""
    retrieval_recall_at_5: float | None
    citation_validity_rate: float | None
    grounded_answer_rate: float | None
    hallucination_rate: float | None
    test_case_pass_rate: float | None
    total_cases: int
    cases_with_retrieval: int
    cases_with_grounded_answer: int
    cases_with_citation: int
    cases_with_hallucination: int
    not_measurable: list[str] = field(default_factory=list)


@dataclass
class EvaluationReport:
    """Full evaluation report."""
    metrics: EvaluationMetrics
    retrieval_results: list[RetrievalResult]
    answer_evaluations: list[AnswerEvaluation]
    bm25_latency_ms: float
    context_build_latency_ms: float
    total_latency_ms: float
    errors: list[str] = field(default_factory=list)


class RAGEvaluator:
    """
    Evaluates RAG system quality.
    
    Uses 25 consultation-only test cases.
    """
    
    def __init__(
        self,
        eval_data_path: str | None = None,
        kb_path: str | None = None,
    ):
        """
        Initialize evaluator.
        
        Args:
            eval_data_path: Path to evaluation cases
            kb_path: Path to knowledge base
        """
        self.eval_data_path = eval_data_path or self._get_default_eval_path()
        self.kb_path = kb_path
        
        self.loader = KnowledgeBaseLoader(kb_path)
        self.retriever: HybridRetriever | None = None
        self.grounding_guard = get_grounding_guard()
        
        self._cases: list[EvaluationCase] = []
        self._relevant_chunks: dict[str, set[str]] = {}  # capability -> chunk_ids
    
    def _get_default_eval_path(self) -> str:
        """Get default evaluation data path."""
        return str(Path(__file__).parent.parent.parent / "archive" / "AI" / "datasets" / "evaluation_v1" / "cases")
    
    def load_cases(self) -> list[EvaluationCase]:
        """Load evaluation cases from JSON files."""
        cases = []
        cases_path = Path(self.eval_data_path)
        
        if not cases_path.exists():
            logger.warning(f"Evaluation path not found: {cases_path}")
            return cases
        
        for json_file in cases_path.glob("*.json"):
            try:
                with open(json_file, "r", encoding="utf-8") as f:
                    data = json.load(f)
                    
                    meta = data.get("meta", {})
                    conv = data.get("conversation", {})
                    messages = conv.get("messages", [])
                    
                    # Get first user message
                    user_query = ""
                    for msg in messages:
                        if msg.get("role") == "user":
                            user_query = msg.get("content", "")
                            break
                    
                    exp = data.get("expectations", {})
                    tool = exp.get("tool", {})
                    
                    case = EvaluationCase(
                        case_id=meta.get("id", json_file.stem),
                        domain=meta.get("domain", "UNKNOWN"),
                        capability=meta.get("capability", "unknown"),
                        user_query=user_query,
                        expected_capability=exp.get("understanding", {}).get("capability", ""),
                        requires_tool=tool.get("required", False),
                        expected_tool=tool.get("name"),
                    )
                    cases.append(case)
                    
            except Exception as e:
                logger.warning(f"Error loading case {json_file}: {e}")
        
        self._cases = cases
        logger.info(f"Loaded {len(cases)} evaluation cases")
        return cases
    
    def _build_relevant_chunks_map(self) -> None:
        """Build map of relevant chunks for each capability."""
        # For consultation-only, we map capabilities to chunk types
        capability_chunk_map = {
            "search_menu": ["menu_item", "category"],
            "get_menu_item": ["menu_item"],
            "get_menu_item_by_name": ["menu_item"],
            "get_restaurant_info": ["restaurant_info", "faq"],
            "get_restaurant_stats": ["restaurant_info"],
            "get_categories": ["category"],
            "get_featured_items": ["menu_item", "review"],
            "get_best_sellers": ["menu_item", "review"],
            "get_dish_spicy_level": ["menu_item", "policy"],
            "list_dish_reviews": ["review"],
            "get_dish_review_summary": ["review"],
            "view_cart": ["faq"],  # General info
            "get_cart_count": ["faq"],
            "list_active_vouchers": ["voucher", "campaign"],
            "preview_voucher": ["voucher"],
            "list_active_campaigns": ["campaign"],
            "get_campaign_detail": ["campaign"],
            "list_loyalty_tiers": ["loyalty_tier"],
            "greet_user": ["faq", "restaurant_info"],
            "clarify_intent": [],  # No specific KB needed
        }
        
        chunks = self.loader.load_chunks()
        
        for capability, chunk_types in capability_chunk_map.items():
            relevant = set()
            for chunk in chunks:
                if chunk.type in chunk_types:
                    relevant.add(chunk.chunk_id)
            self._relevant_chunks[capability] = relevant
    
    def initialize(self) -> None:
        """Initialize evaluator resources."""
        self.loader.load_chunks()
        self.retriever = create_retriever(top_k=5)
        self._build_relevant_chunks_map()
        self.load_cases()
        logger.info("RAG Evaluator initialized")
    
    def evaluate_retrieval(self, case: EvaluationCase) -> RetrievalResult:
        """
        Evaluate retrieval for a single case.
        
        Args:
            case: Evaluation case
            
        Returns:
            RetrievalResult
        """
        import asyncio
        
        # Retrieve chunks
        start = time.time()
        results = asyncio.run(self.retriever.retrieve(case.user_query, top_k=5))
        retrieval_time = (time.time() - start) * 1000
        
        retrieved_ids = {r.chunk.chunk_id for r in results}
        relevant_ids = self._relevant_chunks.get(case.expected_capability, set())
        
        # Calculate recall@5
        if relevant_ids:
            found = len(retrieved_ids & relevant_ids)
            recall = found / len(relevant_ids)
        else:
            recall = 1.0  # No relevant chunks defined
        
        return RetrievalResult(
            case_id=case.case_id,
            retrieved_chunks=[r.chunk for r in results],
            retrieval_scores=[r.score for r in results],
            recall_at_5=recall,
            relevant_chunks_found=len(retrieved_ids & relevant_ids),
            total_relevant=len(relevant_ids),
        )
    
    def evaluate_answer(
        self,
        case: EvaluationCase,
        response: str,
        citations: list[dict],
        context: str,
    ) -> AnswerEvaluation:
        """
        Evaluate generated answer.
        
        Args:
            case: Evaluation case
            response: Generated response
            citations: List of citations
            context: Retrieved context
            
        Returns:
            AnswerEvaluation
        """
        # Check 1: Has response
        has_response = bool(response and len(response) > 0)
        
        # Check 2: Grounding
        grounding_checks = self.grounding_guard.check_response(
            response=response,
            context=context,
            citations=citations,
        )
        
        # Consider grounded if length is OK and context relevance passes
        is_grounded = all(
            c.passed or c.check_type == "hallucination_pattern" 
            for c in grounding_checks
            if c.severity in ("error", "warning")
        )
        
        # Check 3: Citations
        has_citations = bool(citations and len(citations) > 0)
        
        # Check 4: Citation validity
        citation_valid = True
        if citations:
            retrievals = self.retriever.retriever._bm25.chunks if self.retriever and self.retriever._bm25 else []
            cit_validations = self.grounding_guard.validate_citations(citations, retrievals)
            citation_valid = all(v.valid for v in cit_validations)
        
        # Check 5: Hallucination
        hallucination_check = next(
            (c for c in grounding_checks if c.check_type == "hallucination_pattern"),
            None
        )
        hallucination_detected = hallucination_check and hallucination_check.severity == "warning"
        
        return AnswerEvaluation(
            case_id=case.case_id,
            has_response=has_response,
            is_grounded=is_grounded,
            has_citations=has_citations,
            hallucination_detected=hallucination_detected,
            grounding_checks=[{"type": c.check_type, "passed": c.passed, "severity": c.severity} for c in grounding_checks],
            citation_valid=citation_valid,
        )
    
    def run_evaluation(
        self,
        chat_service=None,
        limit: int | None = None,
    ) -> EvaluationReport:
        """
        Run full evaluation.
        
        Args:
            chat_service: Optional chat service for E2E testing
            limit: Limit number of cases (for testing)
            
        Returns:
            EvaluationReport
        """
        self.initialize()
        
        # Limit cases if specified
        cases = self._cases[:limit] if limit else self._cases
        
        retrieval_results: list[RetrievalResult] = []
        answer_evaluations: list[AnswerEvaluation] = []
        
        total_retrieval_time = 0
        total_context_time = 0
        total_time = 0
        errors: list[str] = []
        
        for case in cases:
            case_start = time.time()
            
            try:
                # Retrieval evaluation
                ret_start = time.time()
                ret_result = self.evaluate_retrieval(case)
                ret_time = (time.time() - ret_start) * 1000
                total_retrieval_time += ret_time
                
                retrieval_results.append(ret_result)
                
                # If chat service provided, do E2E evaluation
                if chat_service:
                    ctx_start = time.time()
                    context = "\n\n".join([
                        f"[{i+1}] ({r.chunk.type}) {r.chunk.text}"
                        for i, r in enumerate([
                            type('obj', (object,), {'chunk': c, 'score': s})
                            for c, s in zip(ret_result.retrieved_chunks, ret_result.retrieval_scores)
                        ])
                    ])
                    ctx_time = (time.time() - ctx_start) * 1000
                    total_context_time += ctx_time
                    
                    # Generate answer
                    import asyncio
                    result = asyncio.run(chat_service.process_message(
                        session_id=f"eval_{case.case_id}",
                        message=case.user_query,
                        use_rag=True,
                    ))
                    
                    answer_eval = self.evaluate_answer(
                        case=case,
                        response=result.get("reply", ""),
                        citations=result.get("citations", []),
                        context=context,
                    )
                    answer_evaluations.append(answer_eval)
                
            except Exception as e:
                logger.warning(f"Error evaluating case {case.case_id}: {e}")
                errors.append(f"{case.case_id}: {str(e)}")
            
            total_time += (time.time() - case_start) * 1000
        
        # Calculate metrics
        metrics = self._calculate_metrics(
            cases=cases,
            retrieval_results=retrieval_results,
            answer_evaluations=answer_evaluations,
        )
        
        return EvaluationReport(
            metrics=metrics,
            retrieval_results=retrieval_results,
            answer_evaluations=answer_evaluations,
            bm25_latency_ms=total_retrieval_time / len(cases) if cases else 0,
            context_build_latency_ms=total_context_time / len(cases) if cases else 0,
            total_latency_ms=total_time / len(cases) if cases else 0,
            errors=errors,
        )
    
    def _calculate_metrics(
        self,
        cases: list[EvaluationCase],
        retrieval_results: list[RetrievalResult],
        answer_evaluations: list[AnswerEvaluation],
    ) -> EvaluationMetrics:
        """Calculate aggregated metrics."""
        
        not_measurable = []
        
        # Retrieval Recall@5
        if retrieval_results:
            recalls = [r.recall_at_5 for r in retrieval_results]
            retrieval_recall = sum(recalls) / len(recalls)
        else:
            retrieval_recall = None
            not_measurable.append("retrieval_recall")
        
        # Citation validity
        if answer_evaluations:
            valid_citations = sum(1 for a in answer_evaluations if a.citation_valid)
            citation_validity = valid_citations / len(answer_evaluations)
        else:
            citation_validity = None
            not_measurable.append("citation_validity")
        
        # Grounded answer rate
        if answer_evaluations:
            grounded = sum(1 for a in answer_evaluations if a.is_grounded)
            grounded_rate = grounded / len(answer_evaluations)
        else:
            grounded_rate = None
            not_measurable.append("grounded_rate")
        
        # Hallucination rate
        if answer_evaluations:
            hallucinated = sum(1 for a in answer_evaluations if a.hallucination_detected)
            hallucination_rate = hallucinated / len(answer_evaluations)
        else:
            hallucination_rate = None
            not_measurable.append("hallucination_rate")
        
        # Test case pass rate (based on retrieval + grounded answer)
        if retrieval_results and answer_evaluations:
            passed = 0
            for ret, ans in zip(retrieval_results, answer_evaluations):
                # Pass if: recall > 0 and answer is grounded
                if ret.recall_at_5 > 0 and ans.is_grounded:
                    passed += 1
            pass_rate = passed / len(retrieval_results)
        else:
            pass_rate = None
            not_measurable.append("pass_rate")
        
        # Counts
        cases_with_retrieval = len(retrieval_results)
        cases_with_grounded = sum(1 for a in answer_evaluations if a.is_grounded)
        cases_with_citation = sum(1 for a in answer_evaluations if a.has_citations)
        cases_with_hallucination = sum(1 for a in answer_evaluations if a.hallucination_detected)
        
        return EvaluationMetrics(
            retrieval_recall_at_5=retrieval_recall,
            citation_validity_rate=citation_validity,
            grounded_answer_rate=grounded_rate,
            hallucination_rate=hallucination_rate,
            test_case_pass_rate=pass_rate,
            total_cases=len(cases),
            cases_with_retrieval=cases_with_retrieval,
            cases_with_grounded_answer=cases_with_grounded,
            cases_with_citation=cases_with_citation,
            cases_with_hallucination=cases_with_hallucination,
            not_measurable=not_measurable,
        )


def run_evaluation(limit: int | None = None) -> EvaluationReport:
    """
    Run RAG evaluation.
    
    Args:
        limit: Limit number of test cases
        
    Returns:
        EvaluationReport
    """
    evaluator = RAGEvaluator()
    return evaluator.run_evaluation(limit=limit)
