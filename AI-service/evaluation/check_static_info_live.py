"""Check the two static-info questions through the real chat API and Gemini."""
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


checks = (
    ("số điện thoại quán", "0901 234 567"),
    ("sdt", "0901 234 567"),
    ("địa chỉ quán", "Khu II"),
    ("dia chi", "Khu II"),
)
results = []
with TestClient(app) as client:
    for message, expected in checks:
        response = client.post(
            "/api/v1/chat",
            headers={"X-Internal-Token": settings.ai_service_internal_key},
            json={"session_id": "static-info-smoke", "message": message, "use_tools": True},
        )
        body = response.json()
        results.append({
            "question": message,
            "status": response.status_code,
            "intent": body.get("intent"),
            "expected_fact_present": expected in body.get("reply", ""),
            "reply": body.get("reply"),
        })
print(json.dumps(results, ensure_ascii=True, indent=2))
if not all(item["status"] == 200 and item["intent"] == "get_info" and item["expected_fact_present"] for item in results):
    raise SystemExit(1)
