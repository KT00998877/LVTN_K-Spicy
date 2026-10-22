"""
Chat service - orchestrates AI chat processing with RAG and business tools.
"""
from __future__ import annotations

import asyncio

import re
from typing import Any, Optional

from loguru import logger

from core.gemini_provider import (
    GeminiProvider,
    FunctionCallResult,
    get_gemini_provider,
)
from core.retrieval import HybridRetriever, create_retriever
from core.laravel_client import (
    ToolRegistry,
    ToolExecutor,
    ToolResult,
    get_tool_registry,
    get_tool_executor,
)
from knowledge_base.loader import KnowledgeBaseLoader, get_kb_loader
from prompts.chat_prompts import RAG_SYSTEM_PROMPT, TOOL_CALL_SYSTEM_PROMPT
from prompts.base_rules import BASE_RULES
from prompts.conversation import format_conversation_history


# ============================================================================
# Intent detection helpers (module-level so both ChatService and
# BusinessToolService can use them)
# ============================================================================

def normalize_diacritics(text: str) -> str:
    """
    Normalize Vietnamese diacritics for matching.

    Converts accented characters to their base form.
    "chào" → "chao", "bạn" → "ban"
    """
    import unicodedata
    decomposed = unicodedata.normalize("NFD", text.lower())
    stripped = "".join(ch for ch in decomposed if not unicodedata.combining(ch))
    return stripped


def tokenize(msg: str) -> list[str]:
    """
    Split message into word tokens for accurate keyword matching.

    Vietnamese diacritics are normalized so "hi" matches "hì", "hỉ" etc.
    """
    normalized = normalize_diacritics(msg)
    cleaned = "".join(ch if (ch.isalnum() or ch.isspace()) else " " for ch in normalized)
    return cleaned.split()


def matches_whole_word(text: str, keyword: str, tokens: list[str]) -> bool:
    """
    Check if keyword matches as whole word in tokenized text.

    Args:
        text: Original lowercase text (diacritics normalized)
        keyword: Keyword to match (diacritics normalized)
        tokens: Pre-tokenized word list

    Returns:
        True if keyword found as whole word (not substring)
    """
    normalized_text = normalize_diacritics(text)
    normalized_keyword = normalize_diacritics(keyword)

    # Multi-word keyword: check in joined stream
    if " " in normalized_keyword:
        return normalized_keyword in normalized_text
    # Single word: check in tokens list (word boundary)
    return normalized_keyword in tokenize(text)


# ---------------------------------------------------------------------------
# Reservation intent helpers
#
# "đặt bàn" (2 words) and "datban" / "dat-ban" (1 token) must all be
# recognised, otherwise "/datban" falls through to "unknown" and the bot
# answers with generic text instead of offering the booking form.
# ---------------------------------------------------------------------------

# Words that mean the customer is ASKING ABOUT the process, not asking to book.
_RESERVATION_QUESTION_WORDS = frozenset({
    "nhu the nao", "cach", "huong dan", "thu tu", "quy trinh",
    "co the dat ban khong", "dat ban duoc khong", "co duoc khong",
})


def _reservation_tokens(tokens: list[str]) -> bool:
    """True when the message mentions booking at all."""
    stream = " ".join(tokens)
    return any(
        marker in stream
        for marker in ("dat ban", "datban", "dat-ban", "dat cho", "datcho",
                       "booking", "book ban", "reserve", "reservation")
    )


def is_reservation_question(message: str, tokens: list[str]) -> bool:
    """True when the customer only asks HOW to book (answer with words)."""
    if not _reservation_tokens(tokens):
        return False
    normalized = normalize_diacritics(message)
    for phrase in _RESERVATION_QUESTION_WORDS:
        if phrase in normalized:
            return True
    return False


def is_reservation_request(message: str, tokens: list[str]) -> bool:
    """True when the customer wants to book a table (open the form)."""
    if not _reservation_tokens(tokens):
        return False
    # "How do I book?" is a question, not a booking request.
    if is_reservation_question(message, tokens):
        return False
    # A booking intent with no concrete detail still opens the form — the
    # customer fills the details in the form itself.
    return True


def detect_intent(message: str) -> str:
    """
    Simple keyword-based intent detection with accurate word matching.

    Uses tokenization to prevent substring matches (e.g., "hi" in "nhiều").
    Priority: more specific intents are checked first, greetings last.
    """
    msg_lower = message.lower()
    tokens = tokenize(msg_lower)
    normalized = normalize_diacritics(msg_lower)

    # Specific actions take precedence over dish names and generic price words.
    if matches_whole_word(message, "giỏ", tokens):
        return "add_to_cart" if matches_whole_word(message, "thêm", tokens) else "get_cart"
    if any(matches_whole_word(message, word, tokens) for word in ("khuyến mãi", "voucher", "giảm giá")):
        return "get_promotion"
    # A question about the process ("đặt bàn như thế nào") is answered in
    # words; a booking request opens the form. Check the question first —
    # "đặt bàn" alone is a substring of both.
    if is_reservation_question(message, tokens):
        return "reservation_info"
    if is_reservation_request(message, tokens):
        return "make_reservation"

    # Menu queries (check first - more specific)
    menu_keywords = ["menu", "thực đơn", "danh mục", "món", "món gì", "có gì"]
    if any(matches_whole_word(msg_lower, k, tokens) for k in menu_keywords):
        return "get_menu"

    # Price queries
    price_keywords = ["giá", "bao nhiêu", "price", "tiền", "nhiều tiền"]
    if any(matches_whole_word(msg_lower, k, tokens) for k in price_keywords):
        return "get_price"

    # Cart queries
    cart_keywords = ["giỏ", "giỏ hàng", "cart", "xem giỏ"]
    if any(matches_whole_word(msg_lower, k, tokens) for k in cart_keywords):
        return "get_cart"

    # Add to cart
    add_cart_keywords = ["thêm vào giỏ", "cho vào giỏ", "mua", "order"]
    if any(matches_whole_word(msg_lower, k, tokens) for k in add_cart_keywords):
        return "add_to_cart"

    # Reservation
    reservation_keywords = ["đặt bàn", "booking", "reserve", "đặt chỗ"]
    if any(matches_whole_word(msg_lower, k, tokens) for k in reservation_keywords):
        return "make_reservation"

    # Order tracking
    order_keywords = ["đơn hàng", "theo dõi đơ"]
    if any(matches_whole_word(msg_lower, k, tokens) for k in order_keywords):
        return "track_order"

    # Info queries
    info_keywords = ["ở đâu", "địa chỉ", "số điện thoại", "hotline", "mở cửa", "giờ"]
    if any(k in msg_lower for k in info_keywords):
        return "get_info"

    # Promotion queries
    promo_keywords = ["khuyến mãi", "voucher", "giảm giá", "promotion", "deal"]
    if any(matches_whole_word(msg_lower, k, tokens) for k in promo_keywords):
        return "get_promotion"

    # Greetings - check LAST (only if no other keywords matched)
    # "hi" won't match "nhiều", "bôi", "thi" etc.
    greeting_keywords = ["hi", "hello", "xin chào", "hey", "alo"]
    if any(matches_whole_word(msg_lower, k, tokens) for k in greeting_keywords):
        return "greet_user"
    # "chào" needs special handling for Vietnamese diacritics
    if "chào" in message or "chao" in normalized:
        return "greet_user"

    # Default
    return "unknown"


def is_high_spice_preference(message: str) -> bool:
    """Recognize a request for spicy recommendations, not a menu default level."""
    words = set(tokenize(message))
    return (
        "cay" in words
        and bool(words & {"nhieu", "lam", "rat", "manh", "thich"})
        and not bool(words & {"khong", "hong", "it", "chua", "chang"})
        and not bool(words & {"them", "mua", "dat", "gio", "order"})
    )


# ============================================================================
# Chat Service (Phase C2 - RAG)
# ============================================================================

class ChatService:
    """
    Service for processing chat messages with RAG support.
    
    Phase C2 features:
    - RAG retrieval from Knowledge Base
    - Hybrid search (BM25)
    - Context-enhanced responses
    - Multi-turn conversation with history
    """

    # Intent detection configuration
    GREETING_WORDS = ["chào", "hello", "xin chào", "hey", "alo"]
    GREETING_PATTERNS = [r"\bchào\b", r"\bhello\b", r"\bhi\b", r"\bxin\s*chào\b", r"\bhey\b", r"\balo\b"]

    def __init__(
        self,
        gemini: Optional[GeminiProvider] = None,
        retriever: Optional[HybridRetriever] = None,
        kb_loader: Optional[KnowledgeBaseLoader] = None,
    ):
        """
        Initialize chat service.
        
        Args:
            gemini: Gemini provider instance
            retriever: Hybrid retriever instance
            kb_loader: Knowledge base loader instance
        """
        self._gemini = gemini or get_gemini_provider()
        self._retriever = retriever
        self._kb_loader = kb_loader or get_kb_loader()
        
        # Initialize retriever if not provided
        if self._retriever is None:
            try:
                self._retriever = create_retriever(top_k=5)
                logger.info("RAG retriever initialized")
            except Exception as e:
                logger.warning(f"Failed to initialize retriever: {e}")

    async def _build_rag_context(self, query: str) -> str:
        """
        Build RAG context from knowledge base (async).
        
        Args:
            query: User query string
            
        Returns:
            Formatted context string
        """
        if not self._retriever:
            return ""
        
        try:
            # Use await directly since this is async
            results = await self._retriever.retrieve(query, top_k=5)
            
            if not results:
                return ""
            
            # Format context
            context_parts = []
            for i, result in enumerate(results, 1):
                chunk = result.chunk
                context_parts.append(
                    f"[{i}] ({chunk.type}) {chunk.text}"
                )
            
            return "\n\n".join(context_parts)
            
        except Exception as e:
            logger.warning(f"Error building RAG context: {e}")
            return ""

    async def _get_retrieval_info(self, query: str) -> dict[str, Any]:
        """
        Get retrieval info for response metadata.
        
        Args:
            query: User query string
            
        Returns:
            Retrieval metadata dict
        """
        if not self._retriever:
            return {}
        
        try:
            results = await self._retriever.retrieve(query, top_k=5)
            
            return {
                "chunks_used": len(results),
                "sources": list(set(r.source for r in results)),
                "citations": [
                    {
                        "id": i + 1,
                        "text": r.chunk.text[:100] + "..." if len(r.chunk.text) > 100 else r.chunk.text,
                        "source": r.chunk.type,
                        "score": round(r.score, 3),
                    }
                    for i, r in enumerate(results)
                ],
            }
        except Exception as e:
            logger.warning(f"Error getting retrieval info: {e}")
            return {}

    def _build_system_prompt(
        self,
        query: str,
        context: str,
        messages: Optional[list[dict]] = None,
    ) -> str:
        """
        Build system prompt with RAG context and conversation history.

        Args:
            query: User query string
            context: Pre-fetched context string
            messages: Optional conversation history

        Returns:
            Complete system prompt
        """
        # Format conversation history if provided
        history_section = ""
        if messages:
            history_formatted = format_conversation_history(messages)
            if history_formatted:
                history_section = f"\n\n{history_formatted}\n\nCâu hỏi hiện tại: {query}"
        else:
            history_section = f"\n\nCâu hỏi của khách: {query}"

        return RAG_SYSTEM_PROMPT.format(
            BASE_RULES=BASE_RULES,
            context=context or "(Không có thông tin tham khảo)",
            query=query,
        ) + history_section

    async def process_message(
        self,
        session_id: str,
        message: str,
        system_prompt: Optional[str] = None,
        use_rag: bool = True,
        messages: Optional[list[dict]] = None,
    ) -> dict[str, Any]:
        """
        Process a chat message and return response.

        Args:
            session_id: Session identifier
            message: User message
            system_prompt: Optional system prompt override
            use_rag: Whether to use RAG (default True)
            messages: Conversation history for multi-turn context

        Returns:
            Dict with intent, reply, model, latency_ms, retrieval info
        """
        if not self._gemini.is_configured:
            logger.error("Gemini provider not configured")
            return {
                "intent": "error",
                "reply": "Dịch vụ AI chưa được cấu hình. Vui lòng liên hệ quản trị viên.",
                "model": self._gemini.model_name,
                "latency_ms": 0,
            }

        # Get RAG context if enabled
        rag_context = ""
        retrieval_info = {}

        if use_rag and system_prompt is None:
            rag_context = await self._build_rag_context(message)
            retrieval_info = await self._get_retrieval_info(message)
            prompt = self._build_system_prompt(message, rag_context, messages)
            system_instruction = None
        elif system_prompt:
            prompt = message
            system_instruction = system_prompt
        else:
            prompt = message
            system_instruction = None

        # Generate response (temperature 0.6 for consistent, factual responses)
        result = await asyncio.to_thread(self._gemini.generate,
            prompt=prompt,
            system_instruction=system_instruction,
            temperature=0.6,
        )

        if result.error:
            logger.warning(f"Gemini generation error: {result.error}")
            return {
                "intent": "error",
                "reply": f"Lỗi khi xử lý yêu cầu: {result.error}",
                "model": result.model,
                "latency_ms": result.latency_ms,
            }

        # Simple intent detection
        intent = self._simple_intent_detection(message)

        # Build response
        response = {
            "intent": intent,
            "reply": result.text,
            "model": result.model,
            "latency_ms": result.latency_ms,
            "input_tokens": result.input_tokens,
            "output_tokens": result.output_tokens,
        }

        # Add retrieval info if RAG was used
        if use_rag and retrieval_info:
            response["retrieval"] = {
                "chunks_used": retrieval_info.get("chunks_used", 0),
                "sources": retrieval_info.get("sources", []),
            }
            response["citations"] = retrieval_info.get("citations", [])

        return response

    async def process_structured(
        self,
        session_id: str,
        message: str,
        system_prompt: Optional[str] = None,
    ) -> dict[str, Any]:
        """
        Process chat with structured JSON output.
        
        Args:
            session_id: Session identifier
            message: User message
            system_prompt: Optional system prompt
            
        Returns:
            Dict with intent, reply, confidence, model, latency_ms
        """
        if not self._gemini.is_configured:
            logger.error("Gemini provider not configured")
            return {
                "intent": "error",
                "reply": "Dịch vụ AI chưa được cấu hình.",
                "confidence": 0.0,
                "model": self._gemini.model_name,
                "latency_ms": 0,
            }

        # Define response schema
        response_schema = {
            "type": "object",
            "properties": {
                "intent": {
                    "type": "string",
                    "description": "Detected intent (greet_user, get_info, get_menu, unknown)",
                },
                "reply": {
                    "type": "string",
                    "description": "Generated reply text",
                },
                "confidence": {
                    "type": "number",
                    "description": "Confidence score 0-1",
                },
            },
            "required": ["intent", "reply", "confidence"],
        }

        # Generate structured response
        result = await asyncio.to_thread(self._gemini.generate_structured,
            prompt=message,
            response_schema=response_schema,
            system_instruction=system_prompt,
            temperature=0.3,
        )

        if result.error:
            logger.warning(f"Gemini structured error: {result.error}")
            return {
                "intent": "error",
                "reply": f"Lỗi khi xử lý: {result.error}",
                "confidence": 0.0,
                "model": result.model,
                "latency_ms": result.latency_ms,
            }

        return {
            "intent": result.data.get("intent", "unknown"),
            "reply": result.data.get("reply", ""),
            "confidence": result.data.get("confidence", 0.0),
            "model": result.model,
            "latency_ms": result.latency_ms,
        }

    @staticmethod
    def _normalize_diacritics(text: str) -> str:
        """Normalize Vietnamese diacritics for matching."""
        return normalize_diacritics(text)

    @staticmethod
    def _tokenize(msg: str) -> list[str]:
        """Split message into word tokens for accurate keyword matching."""
        return tokenize(msg)

    @staticmethod
    def _matches_whole_word(text: str, keyword: str, tokens: list[str]) -> bool:
        """Check if keyword matches as whole word in tokenized text."""
        return matches_whole_word(text, keyword, tokens)

    def _simple_intent_detection(self, message: str) -> str:
        """
        Simple keyword-based intent detection with accurate word matching.
        """
        return detect_intent(message)


# =============================================================================
# Business Tool Service (Phase C3)
# =============================================================================

class BusinessToolService:
    """
    Service for executing business tools via Laravel API.
    
    Phase C3: Integrates AI service with Laravel backend business logic.
    """

    # Shown when a write tool is awaiting the customer's confirmation. Laravel
    # renders the confirmation card separately, so this text only needs to tell
    # the customer what the card below is for.
    _CONFIRMATION_PROMPT = (
        "Mình đã chuẩn bị xong thông tin. Bạn xem lại phần bên dưới và bấm "
        "Xác nhận để mình thực hiện nhé."
    )

    def __init__(
        self,
        gemini: Optional[GeminiProvider] = None,
        tool_registry: Optional[ToolRegistry] = None,
        tool_executor: Optional[ToolExecutor] = None,
    ):
        self._gemini = gemini or get_gemini_provider()
        self._tool_registry = tool_registry or get_tool_registry()
        self._tool_executor = tool_executor or get_tool_executor()

    def _build_tool_prompt(self, query: str, tools_only: bool = False) -> tuple[str, list[dict]]:
        """
        Build prompt with available tools for Gemini function calling.
        
        Args:
            query: User query
            tools_only: If True, only include tool instructions (no RAG)
            
        Returns:
            Tuple of (system_prompt, tool_declarations)
        """
        # Smaller tool sets reduce latency and unrelated calls; retain all tools
        # for ambiguous or compound questions.
        relevant = {
            'get_menu': {'menu_lookup', 'menu_item_detail'},
            'get_price': {'menu_lookup', 'menu_item_detail'},
            'add_to_cart': {'menu_lookup', 'menu_item_detail', 'cart_add_item'},
            'get_promotion': {'promotion_list', 'voucher_preview'},
            'make_reservation': {'reservation_options'},
            'reservation_info': {'reservation_options'},
            'track_order': {'order_list', 'order_detail'},
        }.get(detect_intent(query))
        if any(word in query.lower() for word in (' và ', ' rồi ', ';', 'danh mục')):
            relevant = None
        tool_declarations = [tool for tool in self._tool_registry.get_all_tools()
                             if relevant is None or tool['name'] in relevant]

        # Get tool list for system prompt
        tool_list = []
        for tool in self._tool_registry._tools.values():
            if relevant is None or tool.name in relevant:
                tool_list.append(f"- {tool.name}: {tool.description}")

        tool_list_str = "\n".join(tool_list) if tool_list else "Không có công cụ khả dụng."

        # Build tool declarations for Gemini
        # System prompt
        system_prompt = TOOL_CALL_SYSTEM_PROMPT.format(
            BASE_RULES=BASE_RULES,
            tool_list=tool_list_str,
        )

        return system_prompt, tool_declarations

    async def process_with_tools(
        self,
        message: str,
        user_id: Optional[int] = None,
        user_token: Optional[str] = None,
        session_id: Optional[str] = None,
        use_rag: bool = False,
        messages: Optional[list[dict]] = None,
        max_tool_rounds: int = 2,
    ) -> dict[str, Any]:
        """
        Process message with function calling capability.

        Runs a bounded function-calling loop: the model may call tools, the
        results are fed back, and it may call more tools. This lets a single
        customer turn answer compound questions ("mì cay bò còn không, giá
        bao nhiêu, thêm vào giỏ cho tôi") without extra round-trips.

        Args:
            message: User message
            user_id: User ID for auth
            user_token: Sanctum token for auth
            session_id: Session ID for logging
            use_rag: Whether to combine with RAG
            messages: Conversation history for multi-turn context
            max_tool_rounds: Max tool execution rounds (default 2)

        Returns:
            Dict with intent, reply, tool_calls, confirmation_required, etc.
        """
        intent = detect_intent(message)
        if not user_token and intent in {"get_cart", "add_to_cart", "track_order"}:
            return {"intent": intent, "reply": "Bạn vui lòng đăng nhập để sử dụng chức năng này nhé.", "model": "live-api"}
        if intent == "get_cart":
            answer = await self._read_only_fallback(message, user_id, user_token)
            return answer or {"intent": "error", "reply": "Chưa lấy được giỏ hàng. Vui lòng thử lại.", "model": "live-api"}

        if intent in {"unknown", "get_menu"} and is_high_spice_preference(message):
            result = await self._tool_executor.execute(
                tool_name="menu_lookup",
                arguments={"category": "mi-cay-han-quoc", "per_page": 20},
                user_id=user_id,
                user_token=user_token,
            )
            if not result.success:
                return {"intent": "error", "reply": "Chưa lấy được thực đơn mì cay. Vui lòng thử lại.", "model": "live-api"}
            items = self._unwrap_data(result.data)
            dishes = [item for item in items if item.get("is_available", True)] if isinstance(items, list) else []
            if not dishes:
                return {"intent": "get_menu", "reply": "Hiện chưa có món mì cay nào đang bán. Bạn có thể xem thực đơn để chọn món khác nhé.", "model": "live-api"}
            choices = []
            seen = set()
            for item in dishes:
                base_name = re.sub(r"\s+(nho|lon)$", "", normalize_diacritics(item["name"]))
                if base_name not in seen:
                    choices.append(item["name"])
                    seen.add(base_name)
                if len(choices) == 5:
                    break
            names = ", ".join(choices)
            return {
                "intent": "get_menu",
                "reply": f"Bạn thích ăn cay nhiều thì có thể thử {names}. Khi chọn mì cay, bạn có thể chọn cấp cay phù hợp, tối đa cấp 7.",
                "model": "live-api",
            }

        if not self._gemini.is_configured:
            logger.error("Gemini provider not configured")
            return {
                "intent": "error",
                "reply": "Dịch vụ AI chưa được cấu hình. Vui lòng liên hệ quản trị viên.",
                "model": self._gemini.model_name,
                "latency_ms": 0,
            }

        # Build prompt with tools
        system_prompt, tool_declarations = self._build_tool_prompt(message)
        system_prompt += "\nKhách hiện tại " + ("đã đăng nhập." if user_token else "chưa đăng nhập.")

        # Get RAG context if enabled
        rag_context = ""
        if use_rag:
            rag_context = await self._build_rag_context(message)

        # Format conversation history
        history_section = ""
        if messages:
            history_formatted = format_conversation_history(messages)
            if history_formatted:
                history_section = f"\n\n{history_formatted}"

        # If using RAG, combine with tool prompt
        if use_rag and rag_context:
            system_prompt = f"""{system_prompt}

THÔNG TIN THAM KHẢO TỪ CƠ SỞ DỮ LIỆU:
{rag_context}
{history_section}

Hãy sử dụng công cụ khi cần thông tin chính xác về giá, tồn kho, đơn hàng, giỏ hàng...
Với các thông tin chung (địa chỉ, giờ mở cửa), bạn có thể trả lời dựa trên THÔNG TIN THAM KHẢO.
"""
        elif history_section:
            system_prompt = f"{system_prompt}\n{history_section}"

        # Call Gemini with function calling
        import time
        start = time.perf_counter()

        # Separate history from the current message: Gemini gets the current
        # message as its own turn, the history as prior turns.
        prior_history = (messages or [])[:-1] if messages else []

        result = await asyncio.to_thread(self._gemini.generate_with_functions,
            prompt=message,
            system_instruction=system_prompt,
            tools=tool_declarations,
            temperature=0.6,
            history=prior_history,
            function_calling_mode="ANY" if intent == "add_to_cart" else "AUTO",
        )

        latency_ms = int((time.perf_counter() - start) * 1000)

        if result.error:
            logger.warning(f"Gemini function calling error: {result.error}")
            fallback = await self._read_only_fallback(message, user_id, user_token)
            if fallback:
                return fallback
            return {
                "intent": "error",
                "reply": f"Lỗi khi xử lý yêu cầu: {result.error}",
                "model": result.model,
                "latency_ms": result.latency_ms or latency_ms,
            }

        # Process function calls
        tool_results: list[dict[str, Any]] = []
        tool_history: list[dict[str, Any]] = []
        tool_calls = result.function_calls or []
        final_response = "" if tool_calls else (result.text or "")

        for round_idx in range(max_tool_rounds):
            if not tool_calls:
                break

            # Execute this round's tool calls
            function_responses: list[dict[str, Any]] = []
            for tool_call in tool_calls:
                tool_name = tool_call.get("name", "")
                tool_args = tool_call.get("args", {})

                # Execute tool
                exec_result = await self._tool_executor.execute(
                    tool_name=tool_name,
                    arguments=tool_args,
                    user_id=user_id,
                    user_token=user_token
                )

                tool_results.append({
                    "tool": tool_name,
                    "args": tool_args,
                    "result": exec_result.data if exec_result.success else None,
                    "error": exec_result.error if not exec_result.success else None,
                    "error_code": exec_result.error_code,
                    "latency_ms": exec_result.latency_ms,
                })

                function_responses.append({
                    "name": tool_name,
                    "response": exec_result.data if exec_result.success else {
                        "error": exec_result.error or "Tool execution failed"
                    },
                })

            # If every tool in this round was a write tool awaiting confirmation,
            # stop here — the confirmation card is shown to the customer and the
            # tool re-runs later via the Laravel pending-action flow.
            all_pending_confirmation = all(
                tr.get("error_code") == "CONFIRMATION_REQUIRED" for tr in tool_results[-len(tool_calls):]
            )
            if all_pending_confirmation:
                break

            # Feed results back so the model composes the final answer. The
            # history and the originating user turn must be replayed here too:
            # without them Gemini sees only a bare function_call/response pair
            # and loses the question it is meant to answer.
            round_start = time.perf_counter()
            tool_history.extend(GeminiProvider._build_function_contents(
                "", function_responses=function_responses, prior_function_calls=tool_calls,
            ))
            next_result = await asyncio.to_thread(self._gemini.generate_with_functions,
                prompt="",
                system_instruction=system_prompt,
                tools=tool_declarations,
                temperature=0.6,
                history=prior_history + [
                    {"role": "user", "content": message},
                ],
                tool_history=tool_history,
            )
            latency_ms += int((time.perf_counter() - round_start) * 1000)

            if next_result.error:
                logger.warning(f"Gemini follow-up tool round error: {next_result.error}")
                break

            if next_result.text:
                final_response = next_result.text
            tool_calls = next_result.function_calls or []

        # If there are function calls, include results in response
        if tool_results and all(tr.get("error_code") in {"TIMEOUT", "CONNECTION_ERROR", "INTERNAL_ERROR"} for tr in tool_results):
            return {"intent": "error", "reply": "Không thể lấy dữ liệu nhà hàng. Vui lòng thử lại.", "model": result.model}

        if tool_results and not final_response:
            # Build results summary
            results_summary = []
            for tr in tool_results:
                # A confirmation-pending tool is not an error — the customer is
                # about to be shown a confirmation card. Rendering it as
                # "Lỗi: TOOL_REQUIRES_CONFIRMATION" both leaks an internal token
                # to the chat and talks over the card itself.
                if tr.get("error_code") == "CONFIRMATION_REQUIRED":
                    results_summary.append(self._CONFIRMATION_PROMPT)
                elif tr["error"]:
                    results_summary.append(f"Lỗi: {tr['error']}")
                elif tr["result"]:
                    # Format result based on tool
                    result_str = self._format_tool_result(tr["tool"], tr["result"])
                    results_summary.append(result_str)

            if results_summary:
                final_response = "\n".join(results_summary)

        # Detect intent
        intent = detect_intent(message)

        return {
            "intent": intent,
            "reply": final_response,
            "model": result.model,
            "latency_ms": result.latency_ms or latency_ms,
            "input_tokens": result.input_tokens,
            "output_tokens": result.output_tokens,
            "tool_calls": tool_results,
            "confirmation_required": any(
                tr.get("error_code") == "CONFIRMATION_REQUIRED"
                for tr in tool_results
            ),
            "quick_action": self._build_quick_action(intent),
        }

    # ------------------------------------------------------------------
    # Quick actions
    # ------------------------------------------------------------------

    # Booking is served by a form the frontend opens, not by a model-written
    # tool call: the customer fills date/time/phone themselves, so the
    # restaurant schedule and the date format are always correct.
    _RESERVATION_QUICK_ACTION = {
        "type": "reservation_form",
        "label": "Đặt bàn",
        "title": "Đặt bàn tại K-Spicy",
    }

    def _build_quick_action(self, intent: str) -> Optional[dict[str, str]]:
        """Return the UI action the frontend should offer for this intent."""
        if intent == "make_reservation":
            return dict(self._RESERVATION_QUICK_ACTION)
        return None

    async def _read_only_fallback(self, message: str, user_id: Optional[int], user_token: Optional[str]) -> Optional[dict]:
        """Serve simple factual questions from live APIs when Gemini is unavailable.

        Never infer arguments for a write operation or claim it was performed.
        """
        intent = detect_intent(message)
        normalized = normalize_diacritics(message)
        if intent in {"get_cart", "add_to_cart", "track_order"} and not user_token:
            return {"intent": intent, "reply": "Bạn vui lòng đăng nhập để sử dụng chức năng này nhé.", "model": "live-api"}
        tool = {
            "get_menu": "menu_lookup", "get_price": "menu_lookup", "get_promotion": "promotion_list",
            "get_cart": "cart_view", "track_order": "order_list",
            "make_reservation": "reservation_options", "reservation_info": "reservation_options",
        }.get(intent)
        prefix = ""
        if intent == "make_reservation":
            prefix = "Mình đặt bàn giúp bạn nhé. Bấm nút Đặt bàn bên dưới để điền thông tin liên hệ, ngày giờ và số khách.\n"
        if not tool:
            return None
        arguments = {"per_page": 50} if tool == "menu_lookup" else {}
        result = await self._tool_executor.execute(tool_name=tool, arguments=arguments, user_id=user_id, user_token=user_token)
        if not result.success:
            return None
        data = result.data
        if tool == "menu_lookup":
            items = self._unwrap_data(data)
            if isinstance(items, list):
                exact = [item for item in items if normalize_diacritics(item.get("name", "")) in normalized]
                if exact:
                    data = exact
                elif "mi cay" in normalized:
                    data = [item for item in items if "mi cay" in normalize_diacritics(item.get("name", ""))]
        reply = self._format_tool_result(tool, data)
        if not reply:
            return None
        return {
            "intent": intent,
            "reply": prefix + reply,
            "model": "live-api",
            "quick_action": self._build_quick_action(intent),
        }

    @staticmethod
    def _unwrap_data(result: Any) -> Any:
        """
        Unwrap the Laravel envelope so formatters see the payload itself.

        Laravel controllers answer with {"success": bool, "message": str, "data": ...}
        but the shape of `data` differs per endpoint (list for menu, dict for
        a single item, nested dict for cart). Normalize to the `data` payload
        when present, otherwise return the result untouched.
        """
        if isinstance(result, dict) and "data" in result:
            return result["data"]
        return result

    def _format_tool_result(self, tool_name: str, result: Any) -> str:
        """Format tool result for display."""
        if not result:
            return ""

        payload = self._unwrap_data(result)

        # Menu lookup — Laravel returns a LIST of items for GET /api/menu
        if tool_name == "menu_lookup":
            items = payload if isinstance(payload, list) else payload.get("items", [])
            if items:
                lines = []
                for item in items[:5]:  # Limit to 5 items
                    name = item.get("name", "N/A")
                    price = item.get("price_formatted", f"{item.get('price', 0):,.0f} đ")
                    spicy = item.get("spicy_level", 0)
                    spicy_str = "🌶" * spicy if spicy else ""
                    avail = item.get("is_available")
                    avail_str = "" if avail is None else (" (còn)" if avail else " (hết)")
                    lines.append(f"- {name} {spicy_str}: {price}{avail_str}")
                return "\n".join(lines)
            return "Không có món nào phù hợp."

        # Menu item detail
        if tool_name == "menu_item_detail":
            data = payload if isinstance(payload, dict) else {}
            name = data.get("name", "N/A")
            price = data.get("price_formatted", f"{data.get('price', 0):,.0f} đ")
            desc = data.get("description", "")
            spicy = data.get("spicy_level", 0)
            spicy_str = "🌶" * spicy if spicy else "Không cay"
            available = data.get("is_available", True)
            avail_str = "Còn" if available else "Hết"
            return f"{name}\nGiá: {price}\nCấp cay: {spicy_str}\nTrạng thái: {avail_str}\n{desc}"

        # Cart view
        if tool_name == "cart_view":
            data = payload if isinstance(payload, dict) else {}
            items = data.get("items", [])
            subtotal = data.get("subtotal_formatted", f"{data.get('subtotal', 0):,.0f} đ")
            if items:
                lines = ["Giỏ hàng của bạn:"]
                for item in items:
                    menu_item = item.get("menu_item", {})
                    name = menu_item.get("name", item.get("name", "N/A"))
                    qty = item.get("quantity", 1)
                    price = item.get("subtotal_formatted", f"{item.get('subtotal', 0):,.0f} đ")
                    lines.append(f"- {name} x{qty}: {price}")
                lines.append(f"Tổng: {subtotal}")
                return "\n".join(lines)
            return "Giỏ hàng trống."

        # Order list
        if tool_name == "order_list":
            data = payload
            if isinstance(data, dict):
                data = data.get("orders", data.get("items", []))
            if data:
                lines = ["Đơn hàng của bạn:"]
                for order in data[:5]:  # Limit to 5
                    code = order.get("code", "N/A")
                    status = order.get("status", "N/A")
                    total = order.get("total_amount", 0)
                    date = order.get("placed_at", "")[:10]
                    lines.append(f"- {code} | {date} | {status} | {total:,.0f} đ")
                return "\n".join(lines)
            return "Bạn chưa có đơn hàng nào."

        # Order detail
        if tool_name == "order_detail":
            data = payload if isinstance(payload, dict) else {}
            code = data.get("code", "N/A")
            status = data.get("status", "N/A")
            total = data.get("total_amount", 0)
            items = data.get("items", [])
            lines = [f"Đơn {code} - Status: {status}"]
            lines.append(f"Tổng: {total:,.0f} đ")
            if items:
                lines.append("Món:")
                for item in items:
                    name = item.get("name", "N/A")
                    qty = item.get("quantity", 1)
                    lines.append(f"- {name} x{qty}")
            return "\n".join(lines)

        # Reservation options — GET /api/reservations/options returns the
        # reservations config (opens_at, closes_at, max_guests, min_advance,
        # timezone) plus server_now. It has no `time_slots`/`locations` keys;
        # reading those returned an empty string, so the bot told customers it
        # knew nothing about booking hours.
        if tool_name == "reservation_options":
            data = payload if isinstance(payload, dict) else {}

            def _fmt(value, fallback: str = "") -> str:
                if isinstance(value, str) and ":" in value:
                    return value[:5]
                return fallback

            opens = _fmt(data.get("opens_at"), "?")
            closes = _fmt(data.get("closes_at"), "?")
            lines = []
            if opens != "?" or closes != "?":
                lines.append(f"Giờ mở cửa: {opens} - {closes}")
            max_guests = data.get("max_guests")
            if max_guests:
                lines.append(f"Số khách tối đa mỗi lượt: {max_guests}")
            advance = data.get("min_advance_minutes")
            if advance:
                lines.append(f"Cần đặt trước tối thiểu: {advance} phút")
            lines.append("Khu vực đặt bàn: Tầng 1, Tầng 2")

            if len(lines) == 1:
                return "Chưa có thông tin khung giờ đặt bàn."
            return "\n".join(lines)

        # Category list
        if tool_name == "category_list":
            data = payload
            if isinstance(data, dict):
                data = data.get("categories", [])
            if data:
                cats = [c.get("name", "N/A") for c in data]
                return f"Danh mục: {', '.join(cats)}"
            return "Không có danh mục."

        # Promotion list
        if tool_name == "promotion_list":
            data = payload
            if isinstance(data, dict):
                data = data.get("vouchers", data.get("items", []))
            vouchers = data if isinstance(data, list) else []
            if vouchers:
                lines = ["Khuyến mãi hiện có:"]
                for v in vouchers[:3]:
                    code = v.get("code", "")
                    desc = v.get("description", "")
                    lines.append(f"- {code}: {desc}")
                return "\n".join(lines)
            return "Hiện không có khuyến mãi nào."

        # Generic fallback
        return str(payload)[:500]

    async def _build_rag_context(self, query: str) -> str:
        """Build RAG context from knowledge base."""
        if not self._tool_executor._retriever:
            try:
                self._tool_executor._retriever = create_retriever(top_k=3)
            except Exception:
                return ""

        try:
            results = await self._tool_executor._retriever.retrieve(query, top_k=3)
            if not results:
                return ""

            context_parts = []
            for i, result in enumerate(results, 1):
                chunk = result.chunk
                context_parts.append(f"[{i}] {chunk.text}")

            return "\n\n".join(context_parts)
        except Exception as e:
            logger.warning(f"Error building RAG context: {e}")
            return ""


# Singleton instances
_chat_service: Optional[ChatService] = None
_business_tool_service: Optional[BusinessToolService] = None


def get_chat_service() -> ChatService:
    """Get or create chat service singleton."""
    global _chat_service
    if _chat_service is None:
        _chat_service = ChatService()
    return _chat_service


def get_business_tool_service() -> BusinessToolService:
    """Get or create business tool service singleton."""
    global _business_tool_service
    if _business_tool_service is None:
        _business_tool_service = BusinessToolService()
    return _business_tool_service


# Convenience aliases
chat_service = get_chat_service()
business_tool_service = get_business_tool_service()
