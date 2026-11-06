"""Read-only live check for ambiguous and compound intent routing."""
from __future__ import annotations

import json
import os
import sys
from pathlib import Path

from fastapi.testclient import TestClient

os.environ.setdefault("HF_HUB_OFFLINE", "1")
sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from app import app  # noqa: E402
from core.config import settings  # noqa: E402


cases = (
    "Giá mì cay bò nhỏ và địa chỉ quán",
    "Tôi muốn mua gì nhỉ?",
    "Không thêm vào giỏ",
    "Thêm mì cay bò nhỏ vào giỏ",
    "Thêm 1 mì cay bò nhỏ vào giỏ",
)
results = []
with TestClient(app) as client:
    for question in cases:
        response = client.post(
            "/api/v1/chat",
            headers={"X-Internal-Token": settings.ai_service_internal_key},
            json={"session_id": "intent-demo-smoke", "message": question, "use_tools": True},
        )
        body = response.json()
        results.append({
            "question": question,
            "status": response.status_code,
            "intent": body.get("intent"),
            "reply": body.get("reply"),
            "tool_names": [item.get("tool") for item in body.get("tool_calls") or []],
            "confirmation_required": body.get("confirmation_required", False),
        })
print(json.dumps(results, ensure_ascii=True, indent=2))
if any(row["status"] != 200 for row in results):
    raise SystemExit(1)
if any(row["confirmation_required"] or "cart_add_item" in row["tool_names"] for row in results):
    raise SystemExit(1)
