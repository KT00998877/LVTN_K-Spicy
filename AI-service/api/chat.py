"""
Chat API endpoints.
"""
from __future__ import annotations

import asyncio
import uuid
from datetime import datetime

from fastapi import APIRouter, HTTPException, status

from api.dependencies import InternalToken
from core import config
from core.gemini_provider import request_deadline as gemini_request_deadline
from loguru import logger
from schemas import (
    ChatRequest,
    ChatResponse,
    ErrorResponse,
    StructuredChatRequest,
    StructuredChatResponse,
)
from services.chat_service import chat_service, business_tool_service

router = APIRouter(prefix="/chat", tags=["Chat"])


# System prompt for restaurant chatbot
SYSTEM_PROMPT = """Bạn là trợ lý ảo của nhà hàng Mì Cay Hàn Quốc K-Spicy.

Bạn giúp khách hàng:
- Tìm món, xem giá, mô tả món ăn
- Giới thiệu các món nổi bật, món bán chạy
- Trả lời về giờ mở cửa, địa chỉ, thông tin liên hệ
- Tư vấn về cấp độ cay phù hợp

Nguyên tắc:
1. Trả lời bằng tiếng Việt, giọng thân thiện, xưng 'mình' - 'bạn'
2. Không bịa thông tin về giá, địa chỉ, giờ mở cửa nếu không biết
3. Nếu cần thông tin chính xác từ nhà hàng (giá, món, khuyến mãi), nói rõ rằng bạn sẽ kiểm tra và phản hồi
4. Không thực hiện đặt món, đặt bàn, thanh toán - những thao tác này cần xác thực qua hệ thống nhà hàng
5. Không spam emoji, không dùng tiếng Anh trừ tên sản phẩm

Nếu khách hỏi thông tin cần xác minh (giá, địa chỉ chính xác, khuyến mãi hiện tại), 
hãy nói: "Mình chưa thể xác minh thông tin này. Bạn có thể liên hệ trực tiếp với nhà hàng qua hotline để được hỗ trợ chính xác nhất."."""


@router.post(
    "",
    response_model=ChatResponse,
    responses={
        401: {"model": ErrorResponse, "description": "Authentication error"},
        422: {"model": ErrorResponse, "description": "Validation error"},
        503: {"model": ErrorResponse, "description": "Service unavailable"},
    },
    summary="Chat",
    description="Send a chat message and get AI response.",
)
async def chat(
    request: ChatRequest,
    _token: InternalToken,
) -> ChatResponse:
    """
    Process a chat message and return AI response.
    
    Protected by internal token authentication.
    Does not execute business tools or RAG in Phase C1.
    """
    # Generate request_id if not provided
    request_id = request.request_id or str(uuid.uuid4())

    try:
        async with asyncio.timeout(config.settings.chat_timeout_seconds):
            # The provider runs in a worker thread, so the deadline is handed
            # over explicitly: the failover chain uses it to split the budget
            # between the models it tries.
            with gemini_request_deadline(config.settings.chat_timeout_seconds):
                result = await _process_chat(request)
        if result.get("intent") == "error" or not result.get("reply", "").strip():
            raise HTTPException(503, detail={"code": "AI_UNAVAILABLE", "message": "Dịch vụ trả lời đang bận. Vui lòng thử lại."})

        return ChatResponse(
            request_id=request_id,
            session_id=request.session_id,
            intent=result.get("intent", "unknown"),
            reply=result.get("reply", ""),
            provider="gemini",
            model=result.get("model", "gemini-2.5-flash"),
            latency_ms=result.get("latency_ms", 0),
            retrieval=result.get("retrieval"),
            citations=result.get("citations"),
            tool_calls=result.get("tool_calls"),
            confirmation_required=result.get("confirmation_required", False),
            quick_action=result.get("quick_action"),
            timestamp=datetime.utcnow(),
        )

    except TimeoutError:
        raise HTTPException(504, detail={"code": "AI_TIMEOUT", "message": "Trợ lý phản hồi quá lâu. Vui lòng thử lại."})
    except HTTPException:
        raise
    except Exception as e:
        logger.error("Chat failed request_id={} type={}", request_id, type(e).__name__)
        raise HTTPException(503, detail={"code": "AI_UNAVAILABLE", "message": "Dịch vụ trả lời tạm thời gián đoạn. Vui lòng thử lại."})


async def _process_chat(request: ChatRequest) -> dict:
    # Pass conversation history for multi-turn support
    history = [msg.model_dump() for msg in request.messages] if request.messages else None

    if request.use_tools:
        # Tool-calling path: the model can reach live Laravel data
        # (menu availability, cart, orders, reservations).
        result = await business_tool_service.process_with_tools(
            message=request.message,
            user_id=request.user_id,
            user_token=request.user_token,
            session_id=request.session_id,
            use_rag=False,
            messages=history,
        )
    else:
        # RAG-only path (default): cheaper and enough for FAQ / static info.
        result = await chat_service.process_message(
            session_id=request.session_id,
            message=request.message,
            system_prompt=None,  # Use RAG system prompt
            use_rag=True,
            messages=history,
        )
    return result



@router.post(
    "/structured",
    response_model=StructuredChatResponse,
    responses={
        401: {"model": ErrorResponse, "description": "Authentication error"},
        422: {"model": ErrorResponse, "description": "Validation error"},
        503: {"model": ErrorResponse, "description": "Service unavailable"},
    },
    summary="Chat Structured (Development Only)",
    description="Test structured JSON output from Gemini. Not for production.",
)
async def chat_structured(
    request: StructuredChatRequest,
    _token: InternalToken,
) -> StructuredChatResponse:
    """
    Process chat with structured JSON output.
    
    DEVELOPMENT ONLY - Not for production use.
    Tests Gemini's structured output capability.
    """
    request_id = request.request_id or str(uuid.uuid4())

    try:
        result = await asyncio.wait_for(chat_service.process_structured(
            session_id=request.session_id,
            message=request.message,
            system_prompt=SYSTEM_PROMPT,
        ), timeout=config.settings.chat_timeout_seconds)

        return StructuredChatResponse(
            request_id=request_id,
            session_id=request.session_id,
            intent=result.get("intent", "unknown"),
            reply=result.get("reply", ""),
            confidence=result.get("confidence", 0.0),
            model=result.get("model", "gemini-2.5-flash"),
            latency_ms=result.get("latency_ms", 0),
            timestamp=datetime.utcnow(),
        )

    except Exception as e:
        raise HTTPException(
            status_code=status.HTTP_503_SERVICE_UNAVAILABLE,
            detail={
                "code": "GEMINI_ERROR",
                "message": "Dịch vụ trả lời tạm thời gián đoạn. Vui lòng thử lại.",
            },
        )
