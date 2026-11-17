from __future__ import annotations

import pytest
from unittest.mock import AsyncMock

import api.chat as chat_api
from core.gemini_provider import StructuredResult
from schemas import ChatRequest
from services import reservation_prefill


class FakeProvider:
    is_configured = True

    def __init__(self, data: dict):
        self.data = data

    def generate_structured(self, **kwargs):
        return StructuredResult(data=self.data, model="test", latency_ms=1)


@pytest.mark.asyncio
async def test_extracts_only_valid_reservation_hints(monkeypatch):
    monkeypatch.setattr(reservation_prefill, "get_gemini_provider", lambda: FakeProvider({
        "date": "2099-12-20", "time": "19:00", "guest_count": 4, "location": "Tầng 2",
    }))
    assert await reservation_prefill.extract_reservation_hints("4 người lúc 7 tối") == {
        "date": "2099-12-20", "time": "19:00", "guest_count": 4, "location": "Tầng 2",
    }
    monkeypatch.setattr(reservation_prefill, "get_gemini_provider", lambda: FakeProvider({
        "date": "2099-02-30", "time": "27:00", "guest_count": 200, "location": "VIP",
    }))
    assert await reservation_prefill.extract_reservation_hints("đặt bàn") == {}


@pytest.mark.asyncio
async def test_booking_chat_attaches_hints_to_form_action(monkeypatch):
    monkeypatch.setattr(chat_api, "_process_chat", AsyncMock(return_value={
        "intent": "make_reservation", "reply": "Mời bạn kiểm tra lịch", "model": "test",
        "quick_action": {"type": "reservation_form", "label": "Đặt bàn"},
    }))
    monkeypatch.setattr(chat_api, "extract_reservation_hints", AsyncMock(return_value={"date": "2099-12-20", "guest_count": 4}))
    reply = await chat_api.chat(ChatRequest(session_id="test", message="Đặt bàn 4 người", use_tools=True), _token="test")
    assert reply.quick_action is not None
    assert reply.quick_action.prefill == {"date": "2099-12-20", "guest_count": 4}
