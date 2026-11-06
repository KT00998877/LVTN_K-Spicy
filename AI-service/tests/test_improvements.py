from __future__ import annotations

from core.chat_metrics import ChatMetrics
from core.gemini_provider import FunctionCallResult
from core.laravel_client import ToolExecutor, ToolRegistry, ToolResult
from core.vector_retriever import VectorRetriever
from evaluation.review_responses import summarize
from services.chat_service import BusinessToolService, detect_intent, retrieval_query


class NoNetworkClient:
    def __init__(self):
        self.calls = []

    async def call(self, **kwargs):
        self.calls.append(kwargs)
        raise AssertionError("A guarded tool must not call Laravel")


def test_vector_encodes_query_directly():
    class Model:
        def encode(self, text, normalize_embeddings):
            assert text == "query: quán có wifi không?"
            assert normalize_embeddings is True
            return [0.0] * 383 + [1.0]

    retriever = VectorRetriever([], embeddings_path="missing-embeddings.jsonl")
    retriever._query_model = Model()
    assert retriever._embed_query("quán có wifi không?")[-1] == 1.0


def test_negated_write_is_not_routed_as_cart_add():
    assert detect_intent("Tôi không muốn thêm vào giỏ") == "unknown"
    assert detect_intent("Thêm mì cay bò vào giỏ") == "add_to_cart"
    assert detect_intent("Toi muon xem gio hang") == "get_cart"
    assert detect_intent("Cho tôi xem voucher đang có") == "get_promotion"
    assert detect_intent("Quán mở cửa lúc mấy giờ?") == "get_info"
    assert detect_intent("dia chi") == "get_info"
    assert detect_intent("dia chi quan") == "get_info"
    assert detect_intent("sdt") == "get_info"
    assert retrieval_query("dia chi") == "địa chỉ nhà hàng K-Spicy"
    assert retrieval_query("sdt") == "số điện thoại hotline nhà hàng K-Spicy"


async def test_tool_guards_auth_arguments_and_confirmation():
    client = NoNetworkClient()
    executor = ToolExecutor(registry=ToolRegistry(), client=client)
    denied = await executor.execute("cart_view", {}, user_token=None)
    invalid = await executor.execute("cart_add_item", {"menu_item_id": 1, "quantity": 0}, user_token="test")
    pending = await executor.execute("cart_add_item", {"menu_item_id": 1, "quantity": 2}, user_token="test")
    unknown = await executor.execute("unregistered", {}, user_token="test")
    bad_slug = await executor.execute("menu_item_detail", {"slug": "../admin"})
    assert [denied.error_code, invalid.error_code, pending.error_code, unknown.error_code, bad_slug.error_code] == [
        "UNAUTHORIZED", "INVALID_ARGUMENTS", "CONFIRMATION_REQUIRED", "TOOL_NOT_ALLOWED", "INVALID_ARGUMENTS"
    ]
    assert client.calls == []


async def test_model_cannot_execute_tool_not_offered_this_turn():
    class Gemini:
        is_configured = True
        model_name = "mock"

        def generate_with_functions(self, **kwargs):
            return FunctionCallResult(model="mock", function_calls=[{"name": "cart_view", "args": {}}])

    class Executor:
        async def execute(self, **kwargs):
            raise AssertionError("Unlisted tool must not execute")

    service = BusinessToolService(gemini=Gemini(), tool_registry=ToolRegistry(), tool_executor=Executor())
    result = await service.process_with_tools("Mì cay bò giá bao nhiêu?", max_tool_rounds=1)
    assert result["tool_calls"][0]["error_code"] == "TOOL_NOT_OFFERED"


async def test_compound_cart_question_reaches_gemini():
    class Gemini:
        is_configured = True
        model_name = "mock"

        def generate_with_functions(self, **kwargs):
            return FunctionCallResult(model="mock", text="Đã xử lý câu hỏi nhiều ý.")

    class Executor:
        async def execute(self, **kwargs):
            raise AssertionError("No tool call was requested")

    service = BusinessToolService(gemini=Gemini(), tool_registry=ToolRegistry(), tool_executor=Executor())
    result = await service.process_with_tools("Xem giỏ và xem thực đơn", user_token="test")
    assert result["model"] == "mock"


async def test_authorized_read_preserves_laravel_result():
    class Client:
        async def call(self, **kwargs):
            assert kwargs["endpoint"] == "/api/cart"
            assert kwargs["user_token"] == "test"
            return ToolResult(tool="cart_view", success=True, data={"items": [1]})

    result = await ToolExecutor(registry=ToolRegistry(), client=Client()).execute("cart_view", {}, user_token="test")
    assert result.success and result.data == {"items": [1]}


def test_metrics_store_only_aggregate_values():
    metrics = ChatMetrics()
    metrics.record({"model": "live-api", "fallback_used": True, "tool_calls": [{"error_code": "UNAUTHORIZED"}]}, 10)
    metrics.record({"model": "gemini", "tool_calls": []}, 30)
    data = metrics.snapshot()
    assert data["counts"]["gemini_fallbacks"] == 1
    assert data["tool_errors"]["UNAUTHORIZED"] == 1
    assert data["latency_p95_ms"] == 30
    assert "message" not in str(data)


def test_review_summary_uses_only_labeled_cases():
    report = summarize([
        {"case_id": "a", "correct": True, "fallback_used": False, "gemini_error": False, "latency_ms": 10},
        {"case_id": "b", "correct": False, "fallback_used": True, "gemini_error": True, "latency_ms": 30},
    ])
    assert report["correct_rate"] == 0.5
    assert report["fallback_rate"] == 0.5
