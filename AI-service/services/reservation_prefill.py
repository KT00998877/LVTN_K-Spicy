"""Extract optional reservation form hints; Laravel and the form remain authoritative."""
from __future__ import annotations

import asyncio
from datetime import datetime
from zoneinfo import ZoneInfo

from pydantic import BaseModel, Field

from core.gemini_provider import get_gemini_provider


class ReservationHints(BaseModel):
    date: str | None = Field(default=None, description="YYYY-MM-DD, only when the customer specified a date")
    time: str | None = Field(default=None, description="HH:mm 24-hour time, only when specified")
    guest_count: int | None = Field(default=None, description="Number of guests, only when specified")
    location: str | None = Field(default=None, description="Tầng 1 or Tầng 2, only when specified")


async def extract_reservation_hints(message: str) -> dict:
    provider = get_gemini_provider()
    if not provider.is_configured:
        return {}
    now = datetime.now(ZoneInfo("Asia/Ho_Chi_Minh"))
    result = await asyncio.to_thread(
        provider.generate_structured,
        prompt=f"Hôm nay là {now:%Y-%m-%d}, giờ Việt Nam. Trích xuất yêu cầu đặt bàn từ: {message}",
        response_schema=ReservationHints,
        system_instruction="Chỉ trích xuất điều khách nói rõ. Chuyển ngày tương đối sang YYYY-MM-DD và giờ sang HH:mm. Không đoán dữ liệu còn thiếu.",
        temperature=0,
    )
    if result.error:
        return {}
    try:
        hints = ReservationHints.model_validate(result.data)
    except Exception:
        return {}
    data = {}
    if hints.date:
        try:
            date = datetime.strptime(hints.date, "%Y-%m-%d").date()
            if date >= now.date():
                data["date"] = hints.date
        except ValueError:
            pass
    if hints.time:
        try:
            datetime.strptime(hints.time, "%H:%M")
            data["time"] = hints.time
        except ValueError:
            pass
    if hints.guest_count is not None and 1 <= hints.guest_count <= 20:
        data["guest_count"] = hints.guest_count
    if hints.location in {"Tầng 1", "Tầng 2"}:
        data["location"] = hints.location
    return data
