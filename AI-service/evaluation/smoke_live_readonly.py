"""Safe read-only smoke test against the demo service and local Laravel API.

Loads the demo's configuration through the application; never prints secrets.
"""
from __future__ import annotations

import asyncio
import json
import sys
from pathlib import Path

import httpx

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from core.config import settings  # noqa: E402


async def main() -> None:
    base = "http://127.0.0.1:8003"
    report = {}
    async with httpx.AsyncClient(timeout=90) as client:
        for path in ("/api/v1/health", "/api/v1/ready"):
            response = await client.get(base + path)
            report[path] = {"status": response.status_code, "body": response.json()}
        unauth = await client.post(base + "/api/v1/chat", json={"session_id": "demo-smoke", "message": "Xin chào"})
        report["unauthenticated_chat"] = {"status": unauth.status_code}

        headers = {"X-Internal-Token": settings.ai_service_internal_key}
        for name, message, use_tools in (
            ("rag_hours", "Quán mở cửa lúc mấy giờ?", False),
            ("public_promotions", "Có voucher nào đang áp dụng?", True),
            ("menu_price", "Mì cay bò nhỏ giá bao nhiêu?", True),
            ("cart_without_login", "Cho tôi xem giỏ hàng", True),
        ):
            response = await client.post(
                base + "/api/v1/chat",
                headers=headers,
                json={"session_id": "demo-smoke-" + name, "message": message, "use_tools": use_tools},
            )
            body = response.json()
            report[name] = {
                "status": response.status_code,
                "intent": body.get("intent"),
                "model": body.get("model"),
                "latency_ms": body.get("latency_ms"),
                "reply": body.get("reply"),
                "tool_names": [call.get("tool") for call in body.get("tool_calls") or []],
                "confirmation_required": body.get("confirmation_required"),
                "error_code": (body.get("detail") or {}).get("code") if isinstance(body.get("detail"), dict) else None,
            }
        metrics = await client.get(base + "/api/v1/chat/metrics", headers=headers)
        report["metrics"] = {"status": metrics.status_code, "body": metrics.json()}
    print(json.dumps(report, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    asyncio.run(main())
