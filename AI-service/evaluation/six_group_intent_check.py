"""Offline checks for the six chatbot groups used in the thesis table.

This exercises current routing and preference code only. It does not call Gemini
or the restaurant API, so these results must not be called end-to-end results.
"""

import json
import sys
from pathlib import Path
from time import perf_counter

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from services.chat_service import detect_intent, is_high_spice_preference


CASES = [
    ("Thực đơn", "Quán có món gì?", "get_menu", detect_intent),
    ("Thực đơn", "Cho tôi xem menu", "get_menu", detect_intent),
    ("Thực đơn", "Có món mì cay nào?", "get_menu", detect_intent),
    ("Giá và ưu đãi", "Giá mì cay bò bao nhiêu?", "get_price", detect_intent),
    ("Giá và ưu đãi", "Có khuyến mãi gì?", "get_promotion", detect_intent),
    ("Giá và ưu đãi", "Tôi có voucher nào?", "get_promotion", detect_intent),
    ("Tư vấn", "Tôi ăn cay nhiều lắm", True, is_high_spice_preference),
    ("Tư vấn", "Tôi không ăn cay nhiều", False, is_high_spice_preference),
    ("Tư vấn", "Tôi ăn cay ít", False, is_high_spice_preference),
    ("Giỏ hàng", "Xem giỏ hàng", "get_cart", detect_intent),
    ("Giỏ hàng", "Thêm món này vào giỏ", "add_to_cart", detect_intent),
    ("Giỏ hàng", "Giỏ hàng có gì?", "get_cart", detect_intent),
    ("Đặt bàn", "Tôi muốn đặt bàn cho 4 người", "make_reservation", detect_intent),
    ("Đặt bàn", "Đặt bàn như thế nào?", "reservation_info", detect_intent),
    ("Đặt bàn", "Đặt bàn", "make_reservation", detect_intent),
    ("Ngoài phạm vi", "Thời tiết ngày mai thế nào?", "unknown", detect_intent),
    ("Ngoài phạm vi", "Tôi muốn nghe nhạc", "unknown", detect_intent),
    ("Ngoài phạm vi", "Giải phương trình bậc hai", "unknown", detect_intent),
]


def main() -> int:
    results = []
    for group, question, expected, check in CASES:
        start = perf_counter()
        actual = check(question)
        elapsed_ms = (perf_counter() - start) * 1000
        results.append({
            "group": group,
            "question": question,
            "expected": expected,
            "actual": actual,
            "passed": actual == expected,
            "elapsed_ms": round(elapsed_ms, 4),
        })
    print(json.dumps(results, ensure_ascii=False, indent=2))
    return 0 if all(result["passed"] for result in results) else 1


if __name__ == "__main__":
    raise SystemExit(main())
