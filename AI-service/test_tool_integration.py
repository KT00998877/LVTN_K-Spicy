# -*- coding: utf-8 -*-
"""
End-to-end verification of the business tool integration.

Runs against a live Laravel backend + Gemini. Requires:
  - Laravel on http://localhost:8000
  - AI_SERVICE_INTERNAL_KEY set in ai-service/.env

Usage:  python test_tool_integration.py
"""
import asyncio
import io
import os
import sys

sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding="utf-8")
sys.path.insert(0, ".")

from loguru import logger

logger.remove()

from services.chat_service import business_tool_service
from core.gemini_provider import get_gemini_provider


async def main():
    provider = get_gemini_provider()
    if not provider.is_configured:
        print("SKIP: Gemini not configured")
        return

    test_cases = [
        {
            "label": "Menu availability (tool-only data RAG cannot see)",
            "message": "Mì cay bò còn món không? Bao nhiêu tiền?",
        },
        {
            "label": "Simple menu lookup",
            "message": "Quán có món gì?",
        },
    ]

    for case in test_cases:
        print("=" * 70)
        print(f"TEST: {case['label']}")
        print(f"Message: {case['message']}")
        print("-" * 70)

        result = await business_tool_service.process_with_tools(
            message=case["message"],
            user_id=None,
            user_token=None,
            session_id="sess_test",
            use_rag=True,
        )

        print(f"intent:   {result.get('intent')}")
        print(f"latency:  {result.get('latency_ms')}ms")
        print(f"confirm:  {result.get('confirmation_required')}")

        tool_calls = result.get("tool_calls") or []
        print(f"tool_calls ({len(tool_calls)}):")
        for tc in tool_calls:
            status = "OK" if tc.get("error") is None else f"ERR({tc.get('error_code')})"
            print(f"  - {tc.get('tool')}: {status}")
            if tc.get("args"):
                print(f"      args: {tc['args']}")

        print("-" * 70)
        print("REPLY:")
        print(result.get("reply", "(empty)"))
        print()


if __name__ == "__main__":
    asyncio.run(main())
