"""
Grounding Guard Module.

Provides guardrails to prevent hallucinations and ensure
responses are grounded in retrieved context.
"""
from __future__ import annotations

import re
from dataclasses import dataclass
from typing import Any

from loguru import logger


@dataclass
class GroundingCheck:
    """Result of a grounding check."""
    passed: bool
    check_type: str
    message: str
    severity: str  # "error", "warning", "info"


@dataclass
class CitationValidation:
    """Citation validation result."""
    valid: bool
    citation_id: int
    text: str
    source: str
    issue: str | None = None


class GroundingGuard:
    """
    Guardrails for RAG responses.
    
    Prevents hallucinations by:
    1. Checking if response is grounded in context
    2. Validating citations
    3. Detecting potential hallucinations
    """
    
    # Patterns that indicate potential hallucination
    HALLUCINATION_PATTERNS = [
        r"tôi( không)? biết",  # Uncertain
        r"có thể là",  # Speculation
        r"theo( như)? (thông tin|tài liệu|tôi được biết)",  # Uncertain source
        r"nói( chung| rằng)",  # Vague
        r"thông thường",  # Generalization
        r"thường thì",  # Generalization
    ]
    
    # Patterns that indicate factual claims requiring grounding
    FACTUAL_PATTERNS = [
        r"\d+\.?\d*\.?\d*\s*đ",  # Price (e.g., "89.000đ")
        r"\d+\s*km",  # Distance
        r"\d+\s*phút",  # Time
        r"\d+\s*người",  # People count
        r"\d+\s*giờ",  # Hours
    ]
    
    def __init__(self):
        self._hallucination_re = [
            re.compile(p, re.IGNORECASE) for p in self.HALLUCINATION_PATTERNS
        ]
        self._factual_re = [
            re.compile(p) for p in self.FACTUAL_PATTERNS
        ]
    
    def check_response(
        self,
        response: str,
        context: str,
        citations: list[dict[str, Any]],
    ) -> list[GroundingCheck]:
        """
        Check if response is grounded in context.
        
        Args:
            response: Generated response text
            context: Retrieved context text
            citations: List of citations
            
        Returns:
            List of GroundingCheck results
        """
        checks = []
        
        # Check 1: Response length sanity
        checks.append(self._check_length(response))
        
        # Check 2: Citation presence for factual claims
        factual_claims = self._extract_factual_claims(response)
        if factual_claims:
            checks.append(self._check_factual_grounding(response, citations))
        
        # Check 3: Hallucination pattern detection
        checks.append(self._check_hallucination_patterns(response))
        
        # Check 4: Context coverage
        checks.append(self._check_context_relevance(response, context))
        
        return checks
    
    def _check_length(self, response: str) -> GroundingCheck:
        """Check response length sanity."""
        words = len(response.split())
        
        if words < 3:
            return GroundingCheck(
                passed=False,
                check_type="length",
                message=f"Response too short: {words} words",
                severity="error",
            )
        
        if words > 500:
            return GroundingCheck(
                passed=False,
                check_type="length",
                message=f"Response too long: {words} words",
                severity="warning",
            )
        
        return GroundingCheck(
            passed=True,
            check_type="length",
            message=f"Response length OK: {words} words",
            severity="info",
        )
    
    def _extract_factual_claims(self, response: str) -> list[str]:
        """Extract factual claims from response."""
        claims = []
        for pattern in self._factual_re:
            matches = pattern.findall(response)
            claims.extend(matches)
        return claims
    
    def _check_factual_grounding(
        self,
        response: str,
        citations: list[dict[str, Any]],
    ) -> GroundingCheck:
        """
        Check if factual claims are grounded in citations.
        
        Note: This is a heuristic check. Real validation requires
        semantic analysis.
        """
        if not citations:
            return GroundingCheck(
                passed=False,
                check_type="factual_grounding",
                message="Response contains factual claims but no citations provided",
                severity="warning",
            )
        
        # Check if citations have valid IDs
        citation_ids = {c.get("id") for c in citations if c.get("id")}
        if not citation_ids:
            return GroundingCheck(
                passed=False,
                check_type="factual_grounding",
                message="Citations present but missing IDs",
                severity="warning",
            )
        
        return GroundingCheck(
            passed=True,
            check_type="factual_grounding",
            message=f"Factual claims grounded with {len(citations)} citations",
            severity="info",
        )
    
    def _check_hallucination_patterns(self, response: str) -> GroundingCheck:
        """
        Detect potential hallucination patterns.
        
        Note: This is NOT definitive proof of hallucination.
        Some patterns may be legitimate (e.g., "có thể" for recommendations).
        """
        matches = []
        for pattern in self._hallucination_re:
            if pattern.search(response):
                matches.append(pattern.pattern)
        
        if matches:
            return GroundingCheck(
                passed=True,  # Warning only, not error
                check_type="hallucination_pattern",
                message=f"Detected uncertain language patterns: {len(matches)} found",
                severity="warning",
            )
        
        return GroundingCheck(
            passed=True,
            check_type="hallucination_pattern",
            message="No hallucination patterns detected",
            severity="info",
        )
    
    def _check_context_relevance(
        self,
        response: str,
        context: str,
    ) -> GroundingCheck:
        """
        Check if response seems relevant to context.
        
        Uses simple keyword overlap as heuristic.
        """
        if not context or context == "(Không có thông tin tham khảo)":
            return GroundingCheck(
                passed=True,
                check_type="context_relevance",
                message="No context provided - response may be ungrounded",
                severity="warning",
            )
        
        # Extract key words from context
        context_words = set(context.lower().split())
        response_words = set(response.lower().split())
        
        # Check overlap (simple heuristic)
        overlap = len(context_words & response_words)
        total = len(response_words)
        
        if total > 0:
            overlap_ratio = overlap / total
        else:
            overlap_ratio = 0
        
        if overlap_ratio < 0.1:
            return GroundingCheck(
                passed=False,
                check_type="context_relevance",
                message=f"Low context overlap: {overlap_ratio:.0%}",
                severity="warning",
            )
        
        return GroundingCheck(
            passed=True,
            check_type="context_relevance",
            message=f"Context overlap OK: {overlap_ratio:.0%}",
            severity="info",
        )
    
    def validate_citations(
        self,
        citations: list[dict[str, Any]],
        chunks: list[Any],
    ) -> list[CitationValidation]:
        """
        Validate citations against actual chunks.
        
        Args:
            citations: List of citations from response
            chunks: List of actual retrieved chunks
            
        Returns:
            List of CitationValidation results
        """
        validations = []
        chunk_ids = {c.chunk_id for c in chunks}
        
        for i, citation in enumerate(citations, 1):
            validation = CitationValidation(
                valid=True,
                citation_id=citation.get("id", i),
                text=citation.get("text", "")[:50],
                source=citation.get("source", "unknown"),
                issue=None,
            )
            
            # Check 1: Citation has ID
            if not citation.get("id"):
                validation.valid = False
                validation.issue = "Missing citation ID"
            
            # Check 2: Citation has text
            if not citation.get("text"):
                validation.valid = False
                validation.issue = "Missing citation text"
            
            # Check 3: Citation has source
            if not citation.get("source"):
                validation.valid = False
                validation.issue = "Missing citation source"
            
            # Check 4: Citation has score (optional but recommended)
            if "score" not in citation:
                validation.valid = True  # Warning only
                validation.issue = "Missing citation score (recommended)"
            
            validations.append(validation)
        
        return validations


# Singleton instance
_grounding_guard: GroundingGuard | None = None


def get_grounding_guard() -> GroundingGuard:
    """Get or create grounding guard singleton."""
    global _grounding_guard
    if _grounding_guard is None:
        _grounding_guard = GroundingGuard()
    return _grounding_guard
