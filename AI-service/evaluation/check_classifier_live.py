"""Check Gemini classification of an unknown static question without writes."""
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


with TestClient(app) as client:
    response = client.post(
        "/api/v1/chat",
        headers={"X-Internal-Token": settings.ai_service_internal_key},
        json={"session_id": "classifier-demo-smoke", "message": "Quán có chỗ đậu xe không?", "use_tools": True},
    )
body = response.json()
result = {
    "status": response.status_code,
    "intent": body.get("intent"),
    "reply": body.get("reply"),
    "tool_names": [item.get("tool") for item in body.get("tool_calls") or []],
    "confirmation_required": body.get("confirmation_required", False),
}
print(json.dumps(result, ensure_ascii=True, indent=2))
if response.status_code != 200 or result["confirmation_required"] or result["tool_names"]:
    raise SystemExit(1)
