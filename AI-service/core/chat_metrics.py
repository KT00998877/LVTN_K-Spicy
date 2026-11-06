"""Process-local, content-free chatbot counters for the demo service."""
from __future__ import annotations

from collections import Counter, deque
from math import ceil


class ChatMetrics:
    def __init__(self) -> None:
        self.counts: Counter[str] = Counter()
        self.latencies: deque[float] = deque(maxlen=1000)
        self.tool_errors: Counter[str] = Counter()

    def record(self, result: dict, elapsed_ms: float) -> None:
        self.counts["requests"] += 1
        self.latencies.append(elapsed_ms)
        if result.get("model") == "live-api":
            self.counts["live_api_responses"] += 1
        if result.get("fallback_used"):
            self.counts["gemini_fallbacks"] += 1
        if result.get("intent") == "error":
            self.counts["error_responses"] += 1
        for call in result.get("tool_calls") or []:
            self.counts["tool_calls"] += 1
            if call.get("error_code"):
                self.tool_errors[call["error_code"]] += 1

    def record_http_error(self, status_code: int, elapsed_ms: float | None = None) -> None:
        self.counts["http_errors"] += 1
        self.counts[f"http_{status_code}"] += 1
        if elapsed_ms is not None:
            self.latencies.append(elapsed_ms)

    def snapshot(self) -> dict:
        ordered = sorted(self.latencies)

        def percentile(p: float) -> float | None:
            if not ordered:
                return None
            return round(ordered[max(0, ceil(len(ordered) * p) - 1)], 2)

        return {
            "counts": dict(self.counts),
            "tool_errors": dict(self.tool_errors),
            "latency_sample_count": len(ordered),
            "latency_p50_ms": percentile(0.50),
            "latency_p95_ms": percentile(0.95),
            "scope": "single_process_since_start",
        }


chat_metrics = ChatMetrics()
