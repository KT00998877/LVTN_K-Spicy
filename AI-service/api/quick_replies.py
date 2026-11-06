"""
Quick reply API — gợi ý câu trả lời nhanh cho nhân viên tư vấn trực tiếp.

Luồng: khách nhắn → nhân viên bấm "gợi ý" → AI đọc tin nhắn gần nhất và đề
xuất 3 câu trả lời ngắn để nhân viên bấm gửi.

Đây là GỢI Ý, không phải nội dung tự động gửi: nhân viên luôn phải đọc và
chịu trách nhiệm về câu trả lời đã gửi cho khách.
"""
from __future__ import annotations

import asyncio
import uuid
from datetime import datetime

from fastapi import APIRouter, HTTPException
from loguru import logger

from api.dependencies import InternalToken
from core import config
from core.gemini_provider import get_gemini_provider, request_deadline
from schemas.quick_replies import QuickReplyRequest, QuickReplyResponse

router = APIRouter(prefix="/chat", tags=["Chat"])

# Số gợi ý tối đa — nhân viên chỉ cần vài lựa chọn, không cần một danh sách dài.
_MAX_SUGGESTIONS = 3

_SYSTEM_PROMPT = """Bạn trợ lý cho nhân viên tư vấn của nhà hàng Mì Cay Hàn Quốc K-Spicy.

Nhiệm vụ: đọc tin nhắn khách hàng và gợi ý câu trả lời NGẮN cho nhân viên.

Quy tắc:
1. Trả lời bằng tiếng Việt, giọng thân thiện, xưng "mình" - "bạn" (nhân viên dùng để gửi cho khách).
2. Mỗi gợi ý là MỘT câu trả lời hoàn chỉnh, dưới 120 ký tự, có thể gửi thẳng cho khách.
3. Chỉ gợi ý nội dung liên quan tới nhà hàng: món ăn, giá, địa chỉ, giờ mở cửa, khuyến mãi, đặt bàn, đơn hàng.
4. Nếu thông tin khách hỏi cần tra cứu (giá chính xác, tình trạng món) mà bạn không biết, gợi ý câu xác nhận và nói sẽ kiểm tra giúp bạn.
5. Tuyệt đối không bịa giá, không bịa địa chỉ, không bịa tình trạng món.
6. Trả về đúng 3 gợi ý, mỗi gợi ý một chuỗi trong mảng JSON "suggestions".

Ví dụ đầu ra:
{"suggestions": ["Dạ mình kiểm tra giá món đó cho bạn nhé.", "Mì cay bò cấp 3 hiện có, bạn cần 2 phần ạ?", "Bạn có muốn mình gửi thêm gợi ý món khác không?"]}"""


@router.post(
    "/quick-replies",
    response_model=QuickReplyResponse,
    summary="Quick Replies",
    description="Suggest short replies a staff member can send to the customer.",
)
async def quick_replies(request: QuickReplyRequest, _token: InternalToken) -> QuickReplyResponse:
    """Suggest short replies for the latest customer message."""
    request_id = str(uuid.uuid4())
    provider = get_gemini_provider()

    if not provider.is_configured:
        raise HTTPException(
            503,
            detail={
                "code": "AI_UNAVAILABLE",
                "message": "Gợi ý nhanh đang tạm thời không khả dụng.",
            },
        )

    try:
        async with asyncio.timeout(config.settings.chat_timeout_seconds):
            with request_deadline(config.settings.chat_timeout_seconds):
                result = await _suggest(request, provider)
    except TimeoutError:
        raise HTTPException(
            504,
            detail={
                "code": "AI_TIMEOUT",
                "message": "Gợi ý nhanh phản hồi quá lâu. Vui lòng thử lại.",
            },
        )
    except HTTPException:
        raise
    except Exception as exc:
        logger.error("Quick replies failed request_id={} type={}", request_id, type(exc).__name__)
        raise HTTPException(
            503,
            detail={
                "code": "AI_UNAVAILABLE",
                "message": "Gợi ý nhanh tạm thời gián đoạn. Vui lòng thử lại.",
            },
        )

    return QuickReplyResponse(
        request_id=request_id,
        session_id=request.session_id,
        suggestions=result["suggestions"],
        provider="gemini",
        model=result["model"],
        latency_ms=result["latency_ms"],
        timestamp=datetime.utcnow(),
    )


async def _suggest(request: QuickReplyRequest, provider) -> dict:
    """Ask the model for suggestions and normalise the result."""
    history = _format_history(request.messages)

    generation = provider.generate(
        prompt=f"Tin nhắn khách vừa gửi:\n{request.message}",
        system_instruction=_SYSTEM_PROMPT,
        temperature=0.5,
        max_tokens=300,
        history=history,
    )

    if generation.error:
        logger.warning("Quick reply generation error: {}", generation.error)
        raise HTTPException(
            503,
            detail={
                "code": "AI_UNAVAILABLE",
                "message": "Gợi ý nhanh tạm thời gián đoạn. Vui lòng thử lại.",
            },
        )

    suggestions = _parse_suggestions(generation.text)

    return {
        "suggestions": suggestions,
        "model": generation.model,
        "latency_ms": generation.latency_ms,
    }


def _format_history(messages: list[dict[str, str]] | None) -> list[dict[str, str]] | None:
    """Chuyển lịch sử hội thoại sang định dạng Gemini chấp nhận.

    Chỉ `user` và `assistant` được giữ: `system` không nằm trong lịch sử hội
    thoại và nếu gửi lên sẽ bị API từ chối.
    """
    if not messages:
        return None

    history: list[dict[str, str]] = []
    for message in messages:
        role = message.get("role")
        content = (message.get("content") or "").strip()
        if not content or role not in ("user", "assistant"):
            continue
        history.append({"role": role, "content": content})

    return history or None


def _parse_suggestions(text: str) -> list[str]:
    """Đọc danh sách gợi ý từ câu trả lời của model.

    Model có thể trả về JSON thuần, JSON bọc trong ```json, hoặc chỉ một
    danh sách dạng gạch đầu dòng — cả ba đều được chấp nhận.
    """
    import json
    import re

    cleaned = text.strip()

    # Thử JSON thuần hoặc JSON bọc trong code fence.
    candidates = [cleaned]
    fence = re.search(r"```(?:json)?\s*(.+?)```", cleaned, re.DOTALL)
    if fence:
        candidates.append(fence.group(1).strip())

    for candidate in candidates:
        try:
            data = json.loads(candidate)
        except (json.JSONDecodeError, TypeError):
            continue

        if isinstance(data, dict):
            items = data.get("suggestions")
        elif isinstance(data, list):
            items = data
        else:
            continue

        if isinstance(items, list):
            suggestions = [str(item).strip() for item in items if str(item).strip()]
            if suggestions:
                return suggestions[:_MAX_SUGGESTIONS]

    # Fallback: các dòng bắt đầu bằng dấu gạch hoặc số.
    lines = [
        re.sub(r"^\s*[-*\d.)\s]+", "", line).strip().strip('"')
        for line in cleaned.splitlines()
    ]
    suggestions = [line for line in lines if len(line) > 3]

    return suggestions[:_MAX_SUGGESTIONS]
