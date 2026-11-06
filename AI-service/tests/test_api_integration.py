from __future__ import annotations

import httpx
from fastapi.testclient import TestClient
from unittest.mock import AsyncMock

import api.chat as chat_api
from api.dependencies import verify_internal_token
from app import app
from core.laravel_client import LaravelClient, ToolExecutor, ToolRegistry
from schemas import ChatRequest


def test_chat_rejects_missing_internal_token():
    with TestClient(app) as client:
        response = client.post("/api/v1/chat", json={"session_id": "test", "message": "Xin chào"})
    assert response.status_code == 401


def test_chat_contract_and_metrics_with_mocked_processor(monkeypatch):
    async def fake_process(request):
        return {"intent": "get_info", "reply": "Quán mở cửa lúc 10 giờ.", "model": "mock", "latency_ms": 2}

    monkeypatch.setattr(chat_api, "_process_chat", fake_process)
    app.dependency_overrides[verify_internal_token] = lambda: "test"
    try:
        with TestClient(app) as client:
            response = client.post("/api/v1/chat", json={"session_id": "test", "message": "Giờ mở cửa?"})
            metrics = client.get("/api/v1/chat/metrics")
        assert response.status_code == 200
        assert response.json()["intent"] == "get_info"
        assert response.json()["reply"] == "Quán mở cửa lúc 10 giờ."
        assert metrics.status_code == 200
        assert metrics.json()["counts"]["requests"] >= 1
    finally:
        app.dependency_overrides.clear()


def test_chat_returns_controlled_503_for_empty_answer(monkeypatch):
    async def fake_process(request):
        return {"intent": "unknown", "reply": "", "model": "mock"}

    monkeypatch.setattr(chat_api, "_process_chat", fake_process)
    app.dependency_overrides[verify_internal_token] = lambda: "test"
    try:
        with TestClient(app) as client:
            response = client.post("/api/v1/chat", json={"session_id": "test", "message": "Xin chào"})
        assert response.status_code == 503
        assert response.json()["detail"]["code"] == "AI_UNAVAILABLE"
    finally:
        app.dependency_overrides.clear()


async def test_laravel_response_mapping_without_network():
    def handler(request: httpx.Request) -> httpx.Response:
        if request.url.path == "/api/cart":
            return httpx.Response(403, json={"message": "Forbidden"})
        return httpx.Response(200, json={"data": [{"name": "Mì cay"}]})

    laravel = LaravelClient(base_url="http://laravel.test")
    laravel._client = httpx.AsyncClient(transport=httpx.MockTransport(handler), base_url="http://laravel.test")
    executor = ToolExecutor(registry=ToolRegistry(), client=laravel)
    try:
        forbidden = await executor.execute("cart_view", {}, user_token="test")
        menu = await executor.execute("menu_lookup", {"per_page": 5})
        assert forbidden.error_code == "FORBIDDEN"
        assert menu.success and menu.data["data"][0]["name"] == "Mì cay"
    finally:
        await laravel.close()


async def test_static_info_uses_rag_even_when_frontend_enables_tools(monkeypatch):
    rag = AsyncMock(return_value={"intent": "get_info", "reply": "Thông tin từ FAQ", "model": "mock"})
    tools = AsyncMock()
    monkeypatch.setattr(chat_api.chat_service, "process_message", rag)
    monkeypatch.setattr(chat_api.business_tool_service, "process_with_tools", tools)
    for message in ("số điện thoại là số mấy", "địa chỉ", "dia chi", "sdt", "Quán mở cửa lúc mấy giờ?"):
        result = await chat_api._process_chat(ChatRequest(session_id="test", message=message, use_tools=True))
        assert result["intent"] == "get_info"
    assert rag.await_count == 5
    tools.assert_not_awaited()


async def test_dynamic_price_keeps_tool_path(monkeypatch):
    rag = AsyncMock()
    tools = AsyncMock(return_value={"intent": "get_price", "reply": "69.000đ", "model": "mock"})
    monkeypatch.setattr(chat_api.chat_service, "process_message", rag)
    monkeypatch.setattr(chat_api.business_tool_service, "process_with_tools", tools)
    result = await chat_api._process_chat(ChatRequest(session_id="test", message="Mì cay bò nhỏ giá bao nhiêu?", use_tools=True))
    assert result["intent"] == "get_price"
    tools.assert_awaited_once()
    rag.assert_not_awaited()
