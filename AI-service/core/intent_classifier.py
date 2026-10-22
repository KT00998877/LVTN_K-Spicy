"""
Intent classification - Phase C3.
Uses Gemini Structured Output for intent detection.
"""
from __future__ import annotations

import unicodedata
from dataclasses import dataclass, field
from typing import Any, Optional

from loguru import logger

from core.gemini_provider import GeminiProvider, get_gemini_provider


# ============================================================================
# Intent taxonomy
# ============================================================================

class IntentType:
    """Intent categories."""
    GREETING = "greet_user"
    KNOWLEDGE_QUESTION = "knowledge_question"
    BUSINESS_READ = "business_read"
    BUSINESS_WRITE = "business_write"
    CONFIRM_ACTION = "confirm_action"
    CANCEL_ACTION = "cancel_action"
    UNSUPPORTED = "unsupported"
    UNKNOWN = "unknown"


# Confirm/cancel keyword phrases.
# The tokenizer strips Vietnamese diacritics (see IntentClassifier._tokenize),
# so only the unaccented form is needed here.
# Single-token entries only match whole words — see IntentClassifier._matches_any.
CONFIRM_TOKENS = frozenset({
    "xac nhan",
    "ok", "okay",
    "co",
    "dong y",
    "dung roi",
    "yes",
    "y",
    "lam di",
    "thuc hien",
    "go ahead",
})

CANCEL_TOKENS = frozenset({
    "huy",
    "khong",
    "no",
    "n",
    "thoi",
    "bo qua",
    "de sau",
    "tu choi",
})


@dataclass
class IntentResult:
    """Result from intent classification."""
    intent: str
    confidence: float
    route: str  # 'rag', 'business', 'confirmation', 'fallback'
    proposed_tool: Optional[str] = None
    slots: dict[str, Any] = field(default_factory=dict)
    needs_clarification: bool = False
    clarification_slots: list[str] = field(default_factory=list)
    reason: str = ""


# ============================================================================
# Intent classifier
# ============================================================================

class IntentClassifier:
    """
    Classifies user intent using Gemini Structured Output.
    Falls back to keyword-based classification if Gemini fails.
    """
    
    def __init__(self, gemini: Optional[GeminiProvider] = None):
        self._gemini = gemini or get_gemini_provider()
    
    async def classify(
        self,
        message: str,
        has_pending_action: bool = False,
    ) -> IntentResult:
        """
        Classify user intent.
        
        Args:
            message: User message
            has_pending_action: Whether there's a pending action awaiting confirmation
            
        Returns:
            IntentResult with intent, route, and slots
        """
        # Deterministic routing for confirm/cancel when pending action exists
        if has_pending_action:
            deterministic = self._deterministic_confirm_cancel(message)
            if deterministic:
                return deterministic
        
        # Gemini structured classification
        if self._gemini.is_configured:
            result = self._classify_with_gemini(message)
            if result:
                return result
        
        # Fallback to keyword-based
        return self._classify_with_keywords(message)
    
    def _deterministic_confirm_cancel(self, message: str) -> Optional[IntentResult]:
        """Rule-based confirm/cancel detection (no Gemini call needed)."""
        msg = message.lower().strip()
        tokens = self._tokenize(msg)
        
        # Cancel checked first: "hủy đi" contains "y"/"đi" which are confirm tokens,
        # so cancel must win before any confirm match is considered.
        if self._matches_any(tokens, CANCEL_TOKENS):
            return IntentResult(
                intent=IntentType.CANCEL_ACTION,
                confidence=0.95,
                route="confirmation",
                reason="User cancelled pending action (deterministic)",
            )
        
        if self._matches_any(tokens, CONFIRM_TOKENS):
            return IntentResult(
                intent=IntentType.CONFIRM_ACTION,
                confidence=0.95,
                route="confirmation",
                reason="User confirmed pending action (deterministic)",
            )
        
        return None
    
    @staticmethod
    def _tokenize(msg: str) -> list[str]:
        """
        Split into word tokens so short keywords cannot match mid-word.
        
        Vietnamese diacritics are stripped (NFD + remove combining marks) so that
        "hủy", "huy" and "huỷ" all normalize to the same token "huy".
        """
        decomposed = unicodedata.normalize("NFD", msg.lower())
        stripped = "".join(ch for ch in decomposed if not unicodedata.combining(ch))
        # Keep only word characters + whitespace so punctuation splits tokens.
        cleaned = "".join(ch if (ch.isalnum() or ch.isspace()) else " " for ch in stripped)
        return cleaned.split()
    
    @staticmethod
    def _matches_any(tokens: list[str], phrases: frozenset[str]) -> bool:
        """
        Match a token sequence against a set of phrases.
        
        Phrases are matched against the joined token stream so multi-word
        keywords ("xac nhan") work, while short tokens ("y", "n") only match
        as whole words — never as substrings inside another word.
        """
        stream = " ".join(tokens)
        for phrase in phrases:
            if " " in phrase:
                if phrase in stream:
                    return True
            elif phrase in tokens:
                return True
        return False
    
    def _classify_with_gemini(self, message: str) -> Optional[IntentResult]:
        """Classify using Gemini Structured Output."""
        import time
        start = time.perf_counter()
        
        schema = {
            "type": "object",
            "properties": {
                "intent": {
                    "type": "string",
                    "description": (
                        "One of: greet_user, knowledge_question, business_read, "
                        "business_write, unsupported, unknown"
                    ),
                },
                "confidence": {
                    "type": "number",
                    "description": "Confidence score 0-1",
                },
                "slots": {
                    "type": "object",
                    "description": "Extracted entities/slots from message",
                },
                "proposed_tool": {
                    "type": "string",
                    "description": "Suggested tool name (e.g. menu_lookup, cart_add_item)",
                },
                "needs_clarification": {
                    "type": "boolean",
                    "description": "Whether more info needed from user",
                },
                "clarification_slots": {
                    "type": "array",
                    "items": {"type": "string"},
                    "description": "Which slots are missing",
                },
            },
            "required": ["intent", "confidence", "needs_clarification"],
        }
        
        prompt = f"""Phân loại ý định của khách hàng nhà hàng Mì Cay.

INTENT TAXONOMY:
- greet_user: Chào hỏi, xin chào
- knowledge_question: Câu hỏi về thực đơn chung, địa chỉ, giờ mở cửa, hotline, chính sách
- business_read: Cần dữ liệu nghiệp vụ (giá cụ thể, tồn kho, đơn hàng, giỏ hàng, đặt bàn)
- business_write: Yêu cầu thao tác ghi (thêm vào giỏ, đặt bàn)
- unsupported: Yêu cầu không liên quan đến nhà hàng hoặc không thể hỗ trợ
- unknown: Không rõ

TOOLS CÓ THỂ ĐỀ XUẤT:
- menu_lookup, menu_item_detail, category_list (tra cứu menu)
- promotion_list (khuyến mãi)
- reservation_options (quy trình đặt bàn)
- cart_view, order_list, order_detail (dữ liệu nghiệp vụ cần đăng nhập)
- cart_add_item, reservation_create (thao tác ghi - cần xác nhận)

Câu hỏi của khách: "{message}"

Phân loại intent, trích xuất slots, đề xuất tool phù hợp."""
        
        result = self._gemini.generate_structured(
            prompt=prompt,
            response_schema=schema,
            system_instruction="Bạn là bộ phân loại intent cho chatbot nhà hàng. Trả lời chính xác, không giải thích.",
            temperature=0.1,
        )
        
        latency_ms = int((time.perf_counter() - start) * 1000)
        
        if result.error:
            logger.warning(f"Intent classification failed: {result.error}")
            return None
        
        data = result.data
        intent = data.get("intent", "unknown")
        confidence = data.get("confidence", 0.5)
        slots = data.get("slots", {})
        proposed_tool = data.get("proposed_tool")
        needs_clarification = data.get("needs_clarification", False)
        clarification_slots = data.get("clarification_slots", [])
        
        # Determine route
        route_map = {
            IntentType.GREETING: "rag",
            IntentType.KNOWLEDGE_QUESTION: "rag",
            IntentType.BUSINESS_READ: "business",
            IntentType.BUSINESS_WRITE: "confirmation",
            IntentType.UNSUPPORTED: "fallback",
            IntentType.UNKNOWN: "rag",
        }
        route = route_map.get(intent, "rag")
        
        return IntentResult(
            intent=intent,
            confidence=confidence,
            route=route,
            proposed_tool=proposed_tool,
            slots=slots,
            needs_clarification=needs_clarification,
            clarification_slots=clarification_slots,
            reason=f"Gemini structured classification ({latency_ms}ms)",
        )
    
    def _classify_with_keywords(self, message: str) -> IntentResult:
        """Keyword-based fallback classification."""
        msg = message.lower()
        
        # Greeting
        if any(k in msg for k in ["chào", "hello", "hi", "hey", "alo"]):
            return IntentResult(
                intent=IntentType.GREETING,
                confidence=0.9,
                route="rag",
                reason="Keyword match: greeting",
            )
        
        # Write operations
        if any(k in msg for k in ["thêm vào giỏ", "cho vào giỏ", "đặt bàn", "đặt chỗ", "book"]):
            tool = "reservation_create" if any(k in msg for k in ["đặt bàn", "đặt chỗ", "book"]) else "cart_add_item"
            return IntentResult(
                intent=IntentType.BUSINESS_WRITE,
                confidence=0.85,
                route="confirmation",
                proposed_tool=tool,
                reason="Keyword match: write operation",
            )
        
        # Business reads
        if any(k in msg for k in ["giỏ hàng", "đơn hàng", "đặt bàn", "mã giảm giá", "voucher"]):
            return IntentResult(
                intent=IntentType.BUSINESS_READ,
                confidence=0.8,
                route="business",
                reason="Keyword match: business read",
            )
        
        # Knowledge questions
        return IntentResult(
            intent=IntentType.KNOWLEDGE_QUESTION,
            confidence=0.7,
            route="rag",
            reason="Keyword fallback: knowledge question",
        )


# Singleton
_intent_classifier: Optional[IntentClassifier] = None


def get_intent_classifier() -> IntentClassifier:
    """Get or create intent classifier singleton."""
    global _intent_classifier
    if _intent_classifier is None:
        _intent_classifier = IntentClassifier()
    return _intent_classifier