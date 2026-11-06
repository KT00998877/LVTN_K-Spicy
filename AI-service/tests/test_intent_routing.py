from __future__ import annotations

from unittest.mock import AsyncMock

import api.chat as chat_api
from core.gemini_provider import FunctionCallResult
from core.intent_classifier import IntentResult, IntentType
from core.laravel_client import ToolRegistry, ToolResult
from schemas import ChatRequest
from services.chat_service import BusinessToolService, cart_write_issue, detect_intent, detect_intents


def test_detects_distinct_intents_without_forcing_vague_writes():
    assert detect_intents("Giá mì cay bò và thêm vào giỏ") == ["get_price", "add_to_cart"]
    assert detect_intents("Xem giỏ, rồi xem thực đơn") == ["get_cart", "get_menu"]
    assert detect_intents("Giá món này và giờ mở cửa") == ["get_price", "get_info"]
    for message in ("Không thêm vào giỏ", "order"):
        assert detect_intent(message) == "unknown"
    assert detect_intent("Tôi muốn mua gì nhỉ?") == "get_menu"


def test_cart_write_requires_item_and_quantity():
    assert cart_write_issue("Thêm 1 mì cay bò nhỏ vào giỏ") is None
    assert cart_write_issue("Thêm mì cay bò nhỏ vào giỏ") == "missing_quantity"
    assert cart_write_issue("Thêm 1 món này vào giỏ") == "missing_item"
    assert cart_write_issue("Thêm 1 món này cấp 3 vào giỏ") == "missing_item"
    assert cart_write_issue("Thêm 1 mì cay vào giỏ") == "missing_item"
    assert cart_write_issue("Thêm 1 vào giỏ") == "missing_item"
    assert cart_write_issue("Không thêm 1 mì cay bò vào giỏ") == "not_requested"
    assert cart_write_issue("Thêm 1 mì cay bò nhỏ vào giỏ, nhưng đừng thực hiện") == "not_requested"


async def test_incomplete_cart_request_clarifies_before_gemini_or_laravel():
    class Gemini:
        is_configured = True

        def generate_with_functions(self, **kwargs):
            raise AssertionError("Incomplete write must not call Gemini")

    class Executor:
        async def execute(self, **kwargs):
            raise AssertionError("Incomplete write must not call Laravel")

    service = BusinessToolService(gemini=Gemini(), tool_registry=ToolRegistry(), tool_executor=Executor())
    for message in ("Thêm mì cay bò nhỏ vào giỏ", "Thêm 1 món này vào giỏ"):
        result = await service.process_with_tools(message, user_token="test")
        assert result["intent"] == "unknown"
        assert "món nào" in result["reply"]
        assert not result.get("confirmation_required")


async def test_explicit_cart_request_still_requires_login():
    service = BusinessToolService(gemini=object(), tool_registry=ToolRegistry(), tool_executor=object())
    result = await service.process_with_tools("Thêm 1 mì cay bò nhỏ vào giỏ")
    assert result["intent"] == "add_to_cart"
    assert "đăng nhập" in result["reply"]


async def test_no_write_tool_offered_for_incomplete_compound_request():
    class Gemini:
        is_configured = True
        model_name = "mock"

        def generate_with_functions(self, **kwargs):
            assert "cart_add_item" not in {tool["name"] for tool in kwargs["tools"]}
            return FunctionCallResult(model="mock", text="Giá đã được tra cứu; vui lòng nêu số lượng.")

    service = BusinessToolService(gemini=Gemini(), tool_registry=ToolRegistry(), tool_executor=object())
    result = await service.process_with_tools("Giá mì cay bò và thêm vào giỏ", user_token="test")
    assert result["confirmation_required"] is False


async def test_ambiguous_write_from_classifier_only_asks_clarification(monkeypatch):
    classifier = type("Classifier", (), {})()
    classifier.classify = AsyncMock(return_value=IntentResult(
        intent=IntentType.BUSINESS_WRITE, confidence=0.9, route="confirmation"
    ))
    monkeypatch.setattr(chat_api, "get_intent_classifier", lambda: classifier)
    monkeypatch.setattr(chat_api.business_tool_service, "process_with_tools", AsyncMock())
    result = await chat_api._process_chat(ChatRequest(session_id="test", message="order", use_tools=True))
    assert result["intent"] == "unknown"
    chat_api.business_tool_service.process_with_tools.assert_not_awaited()


async def test_negated_cart_request_is_acknowledged_without_model_or_tools(monkeypatch):
    classifier = AsyncMock()
    tools = AsyncMock()
    monkeypatch.setattr(chat_api, "get_intent_classifier", lambda: classifier)
    monkeypatch.setattr(chat_api.business_tool_service, "process_with_tools", tools)
    result = await chat_api._process_chat(ChatRequest(session_id="test", message="Không thêm vào giỏ", use_tools=True))
    assert "không thêm" in result["reply"]
    classifier.assert_not_awaited()
    tools.assert_not_awaited()


async def test_unknown_knowledge_question_can_use_gemini_routing_to_rag(monkeypatch):
    classifier = type("Classifier", (), {})()
    classifier.classify = AsyncMock(return_value=IntentResult(
        intent=IntentType.KNOWLEDGE_QUESTION, confidence=0.82, route="rag"
    ))
    rag = AsyncMock(return_value={"intent": "unknown", "reply": "Thông tin FAQ", "model": "mock"})
    tools = AsyncMock()
    monkeypatch.setattr(chat_api, "get_intent_classifier", lambda: classifier)
    monkeypatch.setattr(chat_api.chat_service, "process_message", rag)
    monkeypatch.setattr(chat_api.business_tool_service, "process_with_tools", tools)
    await chat_api._process_chat(ChatRequest(session_id="test", message="quán có bãi xe không", use_tools=True))
    rag.assert_awaited_once()
    tools.assert_not_awaited()


async def test_mixed_static_and_price_question_gets_rag_and_tools(monkeypatch):
    tool_path = AsyncMock(return_value={"intent": "get_price", "reply": "ok", "model": "mock"})
    monkeypatch.setattr(chat_api.business_tool_service, "process_with_tools", tool_path)
    await chat_api._process_chat(ChatRequest(session_id="test", message="Địa chỉ quán và giá mì cay bò nhỏ", use_tools=True))
    assert tool_path.await_args.kwargs["use_rag"] is True


async def test_valid_write_remains_pending_until_confirmation():
    class Gemini:
        is_configured = True
        model_name = "mock"

        def generate_with_functions(self, **kwargs):
            return FunctionCallResult(model="mock", function_calls=[{
                "name": "cart_add_item", "args": {"menu_item_id": 1, "quantity": 1}
            }])

    class Executor:
        async def execute(self, **kwargs):
            return ToolResult(tool="cart_add_item", success=False, error_code="CONFIRMATION_REQUIRED", error="TOOL_REQUIRES_CONFIRMATION")

    service = BusinessToolService(gemini=Gemini(), tool_registry=ToolRegistry(), tool_executor=Executor())
    result = await service.process_with_tools("Thêm 1 mì cay bò nhỏ vào giỏ", user_token="test")
    assert result["confirmation_required"] is True
    assert result["tool_calls"][0]["error_code"] == "CONFIRMATION_REQUIRED"
