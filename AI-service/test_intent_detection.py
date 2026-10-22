# -*- coding: utf-8 -*-
"""
Test intent detection with word boundary matching.
Verifies fix for bug where "hi" matched "nhiều", "bao nhiêu" etc.
"""
import sys
import io

# Fix Windows console encoding
sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8')

import sys
sys.path.insert(0, ".")

from services.chat_service import ChatService

service = ChatService()

test_cases = [
    # (message, expected_intent, description)
    ("Chào bạn", "greet_user", "Pure greeting"),
    ("Hello", "greet_user", "English greeting"),
    ("Hi there", "greet_user", "Hi as whole word"),
    ("Hey, cho tôi xem menu", "get_menu", "Hey + question"),
    
    # Bug cases - these should NOT be greet_user
    ("Giá mì cay bò bao nhiêu?", "get_price", "Should be get_price, not greet"),
    ("Mì cay bò nhiều tiền?", "get_price", "Should be get_price, not greet"),
    ("Có món nhiều người order không?", "get_menu", "Should be get_menu, not greet"),
    ("Xem thông tin ở đâu?", "get_info", "Should be get_info, not greet"),
    
    # Normal cases
    ("Menu có gì?", "get_menu", "Menu query"),
    ("Địa chỉ quán ở đâu?", "get_info", "Address query"),
    ("Có khuyến mãi gì không?", "get_promotion", "Promotion query"),
]

print("=" * 60)
print("Intent Detection Test Results")
print("=" * 60)

all_passed = True
for message, expected, description in test_cases:
    result = service._simple_intent_detection(message)
    status = "PASS" if result == expected else "FAIL"
    if result != expected:
        all_passed = False
    print(f"{status}: {description}")
    print(f"  Input: '{message}'")
    print(f"  Expected: {expected}")
    print(f"  Got:      {result}")
    print()

print("=" * 60)
if all_passed:
    print("All tests PASSED!")
else:
    print("Some tests FAILED!")
print("=" * 60)
